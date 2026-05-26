<?php

require __DIR__ . '/../lib/bootstrap.php';
require __DIR__ . '/../lib/layout.php';

$staff = current_staff();
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $title = trim($_POST['title'] ?? '');
    $body = trim($_POST['body'] ?? '');

    if ($title === '' || $body === '') {
        $error = 'Title and body are required.';
    } else {
        $stmt = db()->prepare('
            INSERT INTO documents (title, body, created_by)
            VALUES (?, ?, ?)
        ');
        $stmt->execute([$title, $body, $staff['id']]);
        $docId = (int) db()->lastInsertId();

        audit_log('create', 'document', $docId, ['title' => $title]);

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
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($docs as $d): ?>
                    <tr>
                        <td class="id">#<?= (int) $d['id'] ?></td>
                        <td><?= h($d['title']) ?></td>
                        <td><?= h($d['creator_name']) ?></td>
                        <td><?= h($d['created_at']) ?></td>
                        <td><a href="/share.php?doc=<?= (int) $d['id'] ?>" class="btn-link">Create share →</a></td>
                    </tr>
                <?php endforeach ?>
            </tbody>
        </table>
    <?php endif ?>
</section>

<?php render_footer(); ?>
