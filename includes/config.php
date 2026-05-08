<?php
// Database configuration
define('DB_HOST', 'localhost');
define('DB_USER', 'root');        // ganti dengan username kamu
define('DB_PASS', '');            // ganti dengan password kamu
define('DB_NAME', 'lpb');

// Set default timezone ke WIB
date_default_timezone_set('Asia/Jakarta');

// Buat koneksi
try {
    $pdo = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME, DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch(PDOException $e) {
    die("Koneksi gagal: " . $e->getMessage());
}

// Start session
session_start();

// Base URL (sesuaikan dengan environment kamu)
define('BASE_URL', 'http://localhost/landingpage-builder');
?>
