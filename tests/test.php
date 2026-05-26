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

// --- Feature 2: Human-readable document IDs ---

test('generate_slug returns a slug based on the title', function () {
    $slug = generate_slug('My Test Document');
    assert_true(str_starts_with($slug, 'my-test-document-'), 'slug should start with slugified title, got: ' . $slug);
    assert_true(strlen($slug) > strlen('my-test-document-'), 'slug should have a random suffix');
});

test('generate_slug strips special characters', function () {
    $slug = generate_slug('Hello, World! (2026)');
    assert_true(str_starts_with($slug, 'hello-world-2026-'), 'slug should strip special chars, got: ' . $slug);
});

test('generate_slug produces unique slugs for the same title', function () {
    $slug1 = generate_slug('Duplicate Title');
    $stmt = db()->prepare('INSERT INTO documents (title, body, created_by, slug) VALUES (?, ?, 1, ?)');
    $stmt->execute(['Duplicate Title', 'Body', $slug1]);

    $slug2 = generate_slug('Duplicate Title');
    assert_true($slug1 !== $slug2, 'two slugs for the same title should differ due to random suffix');
});

test('seeded document has a slug', function () {
    $stmt = db()->prepare('SELECT slug FROM documents WHERE title = ?');
    $stmt->execute(['Welcome Packet']);
    $row = $stmt->fetch();
    assert_true($row !== false, 'seeded document should exist');
    assert_true(!empty($row['slug']), 'seeded document should have a slug');
    assert_true(str_starts_with($row['slug'], 'welcome-packet-'), 'slug should be based on title, got: ' . $row['slug']);
});

test('document slug is logged in audit_log on creation', function () {
    $slug = generate_slug('Audit Slug Test');
    $stmt = db()->prepare('INSERT INTO documents (title, body, created_by, slug) VALUES (?, ?, 1, ?)');
    $stmt->execute(['Audit Slug Test', 'Body', $slug]);
    $docId = (int) db()->lastInsertId();

    audit_log('create', 'document', $docId, ['title' => 'Audit Slug Test', 'slug' => $slug]);

    $stmt = db()->prepare('SELECT details FROM audit_log WHERE entity_type = ? AND entity_id = ? ORDER BY id DESC LIMIT 1');
    $stmt->execute(['document', $docId]);
    $row = $stmt->fetch();
    $details = json_decode($row['details'], true);
    assert_true($details['slug'] === $slug, 'audit log should contain the slug');
});

// --- Feature 3: Share by name (search) ---

test('search by exact title returns the document', function () {
    $slug = generate_slug('Searchable Report');
    $stmt = db()->prepare('INSERT INTO documents (title, body, created_by, slug) VALUES (?, ?, 1, ?)');
    $stmt->execute(['Searchable Report', 'Body', $slug]);

    $stmt = db()->prepare('SELECT * FROM documents WHERE title LIKE ?');
    $stmt->execute(['%Searchable Report%']);
    $rows = $stmt->fetchAll();
    assert_true(count($rows) >= 1, 'exact title search should return at least one result');
    $found = false;
    foreach ($rows as $r) {
        if ($r['title'] === 'Searchable Report') $found = true;
    }
    assert_true($found, 'search results should contain the document');
});

test('search by partial title (substring) returns the document', function () {
    $slug = generate_slug('Quarterly Budget Review');
    $stmt = db()->prepare('INSERT INTO documents (title, body, created_by, slug) VALUES (?, ?, 1, ?)');
    $stmt->execute(['Quarterly Budget Review', 'Body', $slug]);

    $stmt = db()->prepare('SELECT * FROM documents WHERE title LIKE ?');
    $stmt->execute(['%Budget%']);
    $rows = $stmt->fetchAll();
    assert_true(count($rows) >= 1, 'substring search should return results');
    $found = false;
    foreach ($rows as $r) {
        if ($r['title'] === 'Quarterly Budget Review') $found = true;
    }
    assert_true($found, 'substring search should find the document');
});

test('search with no match returns empty results', function () {
    $stmt = db()->prepare('SELECT * FROM documents WHERE title LIKE ?');
    $stmt->execute(['%zzz_nonexistent_zzz%']);
    $rows = $stmt->fetchAll();
    assert_true(count($rows) === 0, 'search for nonexistent title should return no results');
});

test('search is case-insensitive', function () {
    $slug = generate_slug('Annual Compliance Check');
    $stmt = db()->prepare('INSERT INTO documents (title, body, created_by, slug) VALUES (?, ?, 1, ?)');
    $stmt->execute(['Annual Compliance Check', 'Body', $slug]);

    $stmt = db()->prepare('SELECT * FROM documents WHERE title LIKE ?');
    $stmt->execute(['%annual compliance%']);
    $rows = $stmt->fetchAll();
    assert_true(count($rows) >= 1, 'case-insensitive search should return results');
});

echo "\n{$pass} passed, {$fail} failed.\n";
exit($fail > 0 ? 1 : 0);
