<?php
require_once '../includes/config.php';

if ($_POST) {
    $username = trim($_POST['username']);
    $password = $_POST['password'];
    
    if (empty($username) || empty($password)) {
        $_SESSION['error'] = "Semua field harus diisi";
        header('Location: ../pages/login.php');
        exit;
    }
    
    $stmt = $pdo->prepare("SELECT id, password FROM users WHERE username = ? OR email = ?");
    $stmt->execute([$username, $username]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($user && password_verify($password, $user['password'])) {
        $_SESSION['user_id'] = $user['id'];
        header('Location: ../pages/dashboard.php');
        exit;
    } else {
        $_SESSION['error'] = "Username/email atau password salah";
        header('Location: ../pages/login.php');
        exit;
    }
}
?>