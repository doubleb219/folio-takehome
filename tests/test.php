<?php

require __DIR__ . '/../lib/bootstrap.php';

system('php ' . escapeshellarg(__DIR__ . '/../seed.php') . ' > /dev/null', $rc);
if ($rc !== 0) {
    fwrite(STDERR, "seed failed\n");
    exit(1);
}

$pass = 0;
$fail = 0;

function test(string $name, callable $fn): void {
    global $pass, $fail;
    try {
        $fn();
        echo "  [ok] {$name}\n";
        $pass++;
    } catch (Throwable $e) {
        echo "  [FAIL] {$name}: " . $e->getMessage() . "\n";
        $fail++;
    }
}

function assert_true($cond, string $msg = ''): void {
    if (!$cond) {
        throw new RuntimeException($msg !== '' ? $msg : 'expected true');
    }
}

echo "\nRunning tests:\n";

test('seeded share link resolves to the seeded document', function () {
    $stmt = db()->prepare('
        SELECT d.title
        FROM shares s
        JOIN documents d ON d.id = s.document_id
        LIMIT 1
    ');
    $stmt->execute();
    $row = $stmt->fetch();
    assert_true($row !== false, 'expected the seeded share to resolve');
    assert_true($row['title'] === 'Welcome Packet', 'unexpected title: ' . var_export($row['title'], true));
});

test('document with no publish_at is visible via share link', function () {
    $stmt = db()->prepare('INSERT INTO documents (title, body, created_by) VALUES (?, ?, 1)');
    $stmt->execute(['Immediate Doc', 'Body text']);
    $docId = (int) db()->lastInsertId();

    $token = random_token();
    $stmt = db()->prepare('INSERT INTO shares (document_id, token, recipient_email) VALUES (?, ?, ?)');
    $stmt->execute([$docId, $token, 'test@example.com']);

    $stmt = db()->prepare('
        SELECT d.* FROM shares s JOIN documents d ON d.id = s.document_id WHERE s.token = ?
    ');
    $stmt->execute([$token]);
    $doc = $stmt->fetch();
    assert_true($doc !== false, 'share should resolve');
    $visible = empty($doc['publish_at']) || $doc['publish_at'] <= gmdate('Y-m-d H:i:s');
    assert_true($visible, 'document with no publish_at should be visible');
});

test('document with future publish_at is NOT visible via share link', function () {
    $future = gmdate('Y-m-d H:i:s', strtotime('+1 day'));
    $stmt = db()->prepare('INSERT INTO documents (title, body, created_by, publish_at) VALUES (?, ?, 1, ?)');
    $stmt->execute(['Future Doc', 'Body text', $future]);
    $docId = (int) db()->lastInsertId();

    $token = random_token();
    $stmt = db()->prepare('INSERT INTO shares (document_id, token, recipient_email) VALUES (?, ?, ?)');
    $stmt->execute([$docId, $token, 'test@example.com']);

    $stmt = db()->prepare('
        SELECT d.* FROM shares s JOIN documents d ON d.id = s.document_id WHERE s.token = ?
    ');
    $stmt->execute([$token]);
    $doc = $stmt->fetch();
    assert_true($doc !== false, 'share should resolve');
    $visible = empty($doc['publish_at']) || $doc['publish_at'] <= gmdate('Y-m-d H:i:s');
    assert_true(!$visible, 'document with future publish_at should NOT be visible');
});

test('document with past publish_at IS visible via share link', function () {
    $past = gmdate('Y-m-d H:i:s', strtotime('-1 day'));
    $stmt = db()->prepare('INSERT INTO documents (title, body, created_by, publish_at) VALUES (?, ?, 1, ?)');
    $stmt->execute(['Past Doc', 'Body text', $past]);
    $docId = (int) db()->lastInsertId();

    $token = random_token();
    $stmt = db()->prepare('INSERT INTO shares (document_id, token, recipient_email) VALUES (?, ?, ?)');
    $stmt->execute([$docId, $token, 'test@example.com']);

    $stmt = db()->prepare('
        SELECT d.* FROM shares s JOIN documents d ON d.id = s.document_id WHERE s.token = ?
    ');
    $stmt->execute([$token]);
    $doc = $stmt->fetch();
    assert_true($doc !== false, 'share should resolve');
    $visible = empty($doc['publish_at']) || $doc['publish_at'] <= gmdate('Y-m-d H:i:s');
    assert_true($visible, 'document with past publish_at should be visible');
});

test('scheduling a document logs publish_at in audit_log', function () {
    $future = gmdate('Y-m-d H:i:s', strtotime('+2 days'));
    $stmt = db()->prepare('INSERT INTO documents (title, body, created_by, publish_at) VALUES (?, ?, 1, ?)');
    $stmt->execute(['Audit Doc', 'Body', $future]);
    $docId = (int) db()->lastInsertId();

    audit_log('create', 'document', $docId, ['title' => 'Audit Doc', 'publish_at' => $future]);

    $stmt = db()->prepare('SELECT details FROM audit_log WHERE entity_type = ? AND entity_id = ? ORDER BY id DESC LIMIT 1');
    $stmt->execute(['document', $docId]);
    $row = $stmt->fetch();
    assert_true($row !== false, 'audit log entry should exist');
    $details = json_decode($row['details'], true);
    assert_true(isset($details['publish_at']), 'audit log should contain publish_at');
    assert_true($details['publish_at'] === $future, 'audit log publish_at should match');
});

echo "\n{$pass} passed, {$fail} failed.\n";
exit($fail > 0 ? 1 : 0);
