<?php
require __DIR__ . '/../_boot.php';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_valid($_POST['csrf'] ?? null)) {
    $_SESSION = [];
    session_regenerate_id(true);
    session_destroy();
}
redirect('/');
