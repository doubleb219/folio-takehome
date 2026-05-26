<?php

require __DIR__ . '/lib/bootstrap.php';

$dbPath = __DIR__ . '/db.sqlite';
if (file_exists($dbPath)) {
    unlink($dbPath);
}

$pdo = db();
$pdo->exec(file_get_contents(__DIR__ . '/schema.sql'));

$migrations = glob(__DIR__ . '/migrations/*.sql');
sort($migrations);
foreach ($migrations as $file) {
    $pdo->exec(file_get_contents($file));
}

$pdo->exec("
    INSERT INTO staff (email, name) VALUES
        ('freddy@folio.example', 'Freddy Folio')
");

$slug = generate_slug('Welcome Packet');
$stmt = $pdo->prepare('
    INSERT INTO documents (title, body, created_by, slug)
    VALUES (?, ?, 1, ?)
');
$stmt->execute([
    'Welcome Packet',
    "Welcome to Folio!\n\nThis is the body of your welcome packet.",
    $slug,
]);
$docId = (int) $pdo->lastInsertId();

$token = random_token();
$stmt = $pdo->prepare('
    INSERT INTO shares (document_id, token, recipient_email)
    VALUES (?, ?, ?)
');
$stmt->execute([$docId, $token, 'recipient@example.com']);

echo "Seeded db.sqlite.\n";
echo "Admin:        http://localhost:8000/admin.php\n";
echo "Sample share: http://localhost:8000/view.php?token={$token}\n";
