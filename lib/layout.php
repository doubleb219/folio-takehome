<?php

function render_header(string $title, ?array $staff = null): void {
    ?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= h($title) ?> · Folio</title>
    <link rel="stylesheet" href="/assets/style.css">
</head>
<body>
<nav class="nav">
    <div class="nav-inner">
        <a href="/admin.php" class="brand">
            <span class="brand-mark">F</span>
            Folio
        </a>
        <?php if ($staff): ?>
            <span class="nav-user"><strong><?= h($staff['name']) ?></strong> · <?= h($staff['email']) ?></span>
        <?php endif ?>
    </div>
</nav>
<main class="container">
    <?php
}

function render_footer(): void {
    ?>
</main>
</body>
</html>
    <?php
}
