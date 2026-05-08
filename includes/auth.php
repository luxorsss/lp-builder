<?php
// Fungsi untuk mengecek apakah user sudah login
function isLoggedIn() {
    return isset($_SESSION['user_id']);
}

// Fungsi untuk redirect ke login jika belum login
function requireLogin() {
    if (!isLoggedIn()) {
        header('Location: /pages/login.php');
        exit;
    }
}

// Fungsi untuk mendapatkan data user yang sedang login
function getCurrentUser($pdo) {
    if (!isLoggedIn()) return null;
    
    $stmt = $pdo->prepare("SELECT id, username, email FROM users WHERE id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}
?>