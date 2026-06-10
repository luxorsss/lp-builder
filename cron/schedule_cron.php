<?php
// cron/schedule_cron.php

// Gunakan jalur absolut berbasis lokasi file ini
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/functions.php'; // Pastikan file functions juga di-require jika fungsi ditaruh di sana

// Cek apakah diakses dari command line
// if (php_sapi_name() !== 'cli') {
//     die('File ini hanya bisa diakses via command line');
// }

// Ambil semua user
$stmt = $pdo->query("SELECT id FROM users");
$users = $stmt->fetchAll(PDO::FETCH_ASSOC);

foreach ($users as $user) {
    updateScheduledStatus($pdo, $user['id']);
}

echo "Cron job selesai: " . date('Y-m-d H:i:s') . "\n";