<?php
require_once '../includes/config.php';
require_once '../includes/auth.php';

// Cek login
requireLogin();

if (isset($_GET['id'])) {
    $page_id = $_GET['id'];
    $user = getCurrentUser($pdo);
    
    // Cek apakah page milik user yang login
    $stmt = $pdo->prepare("SELECT id FROM landing_pages WHERE id = ? AND user_id = ?");
    $stmt->execute([$page_id, $user['id']]);
    
    if ($stmt->fetch()) {
        // Hapus page (akan menghapus elemen-elemennya juga karena CASCADE)
        $stmt = $pdo->prepare("DELETE FROM landing_pages WHERE id = ?");
        $stmt->execute([$page_id]);
        
        $_SESSION['message'] = "Landing page berhasil dihapus";
    } else {
        $_SESSION['message'] = "Landing page tidak ditemukan atau bukan milik Anda";
    }
}

header('Location: ../index.php');
exit;
?>