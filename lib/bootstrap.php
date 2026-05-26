<?php

date_default_timezone_set('America/Chicago');

function db(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $path = __DIR__ . '/../db.sqlite';
        $pdo = new PDO('sqlite:' . $path);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $pdo->exec('PRAGMA foreign_keys = ON');
    }
    return $pdo;
}

function current_staff(): array {
    $stmt = db()->prepare('SELECT * FROM staff WHERE id = 1');
    $stmt->execute();
    $row = $stmt->fetch();
    if (!$row) {
        throw new RuntimeException('No staff row #1 found. Did you run `php seed.php`?');
    }
    return $row;
}

function audit_log(string $action, string $entity_type, int $entity_id, array $details = []): void {
    $staff = current_staff();
    $stmt = db()->prepare('
        INSERT INTO audit_log (staff_id, action, entity_type, entity_id, details)
        VALUES (?, ?, ?, ?, ?)
    ');
    $stmt->execute([
        $staff['id'],
        $action,
        $entity_type,
        $entity_id,
        json_encode($details),
    ]);
}

function random_token(int $bytes = 16): string {
    return bin2hex(random_bytes($bytes));
}

/**
 * Generate a human-readable slug from a title with a 4-char random suffix.
 * Format: "title-words-a3k7" — readable, typeable, collision-resistant.
 * Retries with a new suffix if the slug already exists.
 */
function generate_slug(string $title, int $max_attempts = 10): string {
    $base = strtolower(trim($title));
    $base = preg_replace('/[^a-z0-9]+/', '-', $base);
    $base = trim($base, '-');
    if ($base === '') {
        $base = 'doc';
    }
    if (strlen($base) > 40) {
        $base = substr($base, 0, 40);
        $base = rtrim($base, '-');
    }

    $chars = 'abcdefghjkmnpqrstuvwxyz23456789';
    for ($i = 0; $i < $max_attempts; $i++) {
        $suffix = '';
        for ($j = 0; $j < 4; $j++) {
            $suffix .= $chars[random_int(0, strlen($chars) - 1)];
        }
        $slug = $base . '-' . $suffix;

        $stmt = db()->prepare('SELECT COUNT(*) FROM documents WHERE slug = ?');
        $stmt->execute([$slug]);
        if ((int) $stmt->fetchColumn() === 0) {
            return $slug;
        }
    }
    throw new RuntimeException('Could not generate a unique slug after ' . $max_attempts . ' attempts');
}

function h(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}
