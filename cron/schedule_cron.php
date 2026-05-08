<?php
// cron/schedule_cron.php

require_once '../includes/config.php';
require_once '../includes/helpers.php';

// Cek apakah diakses dari command line
if (php_sapi_name() !== 'cli') {
    die('File ini hanya bisa diakses via command line');
}

// Ambil semua user
$stmt = $pdo->query("SELECT id FROM users");
$users = $stmt->fetchAll(PDO::FETCH_ASSOC);

foreach ($users as $user) {
    updateScheduledStatus($pdo, $user['id']);
}

echo "Cron job selesai: " . date('Y-m-d H:i:s') . "\n";