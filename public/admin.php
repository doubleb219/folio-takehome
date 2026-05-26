<?php

require __DIR__ . '/../lib/bootstrap.php';
require __DIR__ . '/../lib/layout.php';

$staff = current_staff();
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $title = trim($_POST['title'] ?? '');
    $body = trim($_POST['body'] ?? '');

    $publish_at = trim($_POST['publish_at'] ?? '');

    if ($title === '' || $body === '') {
        $error = 'Title and body are required.';
    } else {
        $publish_at_utc = null;
        if ($publish_at !== '') {
            $dt = DateTimeImmutable::createFromFormat('Y-m-d\TH:i', $publish_at, new DateTimeZone(date_default_timezone_get()));
            if (!$dt) {
                $error = 'Invalid publish date.';
            } else {
                $publish_at_utc = $dt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
            }
        }
    }

    if (!$error) {
        $stmt = db()->prepare('
            INSERT INTO documents (title, body, created_by, publish_at)
            VALUES (?, ?, ?, ?)
        ');
        $stmt->execute([$title, $body, $staff['id'], $publish_at_utc]);
        $docId = (int) db()->lastInsertId();

        $details = ['title' => $title];
        if ($publish_at_utc) {
            $details['publish_at'] = $publish_at_utc;
        }
        audit_log('create', 'document', $docId, $details);

        header('Location: /admin.php?created=' . $docId);
        exit;
    }
}

$docs = db()->query('
    SELECT d.*, s.name AS creator_name
    FROM documents d
    JOIN staff s ON s.id = d.created_by
    ORDER BY d.created_at DESC
')->fetchAll();

render_header('Admin', $staff);
?>

<h1 class="page-title">Admin</h1>
<p class="page-subtitle">Create documents and generate share links for recipients.</p>

<?php if (!empty($_GET['created'])): ?>
    <div class="banner banner-success">Document #<?= (int) $_GET['created'] ?> created.</div>
<?php endif ?>

<?php if ($error): ?>
    <div class="banner banner-error"><?= h($error) ?></div>
<?php endif ?>

<section class="card">
    <h2 class="card-title">New document</h2>
    <form method="post">
        <div class="form-field">
            <label for="title">Title</label>
            <input type="text" id="title" name="title" required>
        </div>
        <div class="form-field">
            <label for="body">Body</label>
            <textarea id="body" name="body" required></textarea>
        </div>
        <div class="form-field">
            <label for="publish_at">Publish at (optional)</label>
            <input type="datetime-local" id="publish_at" name="publish_at">
            <small class="form-hint">Leave blank to publish immediately.</small>
        </div>
        <button type="submit" class="btn">Create document</button>
    </form>
</section>

<section class="card">
    <h2 class="card-title">Documents</h2>
    <?php if (empty($docs)): ?>
        <p class="empty">No documents yet.</p>
    <?php else: ?>
        <table class="data">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Title</th>
                    <th>Creator</th>
                    <th>Created</th>
                    <th>Status</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($docs as $d): ?>
                    <?php
                        $is_scheduled = !empty($d['publish_at']);
                        $is_published = !$is_scheduled || $d['publish_at'] <= gmdate('Y-m-d H:i:s');
                    ?>
                    <tr>
                        <td class="id">#<?= (int) $d['id'] ?></td>
                        <td><?= h($d['title']) ?></td>
                        <td><?= h($d['creator_name']) ?></td>
                        <td><?= h($d['created_at']) ?></td>
                        <td>
                            <?php if (!$is_scheduled): ?>
                                <span class="status-badge status-published">Published</span>
                            <?php elseif ($is_published): ?>
                                <span class="status-badge status-published">Published</span>
                            <?php else: ?>
                                <span class="status-badge status-scheduled">Scheduled <?= h($d['publish_at']) ?> UTC</span>
                            <?php endif ?>
                        </td>
                        <td><a href="/share.php?doc=<?= (int) $d['id'] ?>" class="btn-link">Create share →</a></td>
                    </tr>
                <?php endforeach ?>
            </tbody>
        </table>
    <?php endif ?>
</section>

<?php render_footer(); ?>
