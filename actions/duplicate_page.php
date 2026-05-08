<?php
require_once '../includes/config.php';
require_once '../includes/auth.php';
require_once '../includes/helpers.php';

// Aktifkan debug sementara
error_reporting(E_ALL);
ini_set('display_errors', 1);

requireLogin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Method not allowed']);
    exit;
}

$user = getCurrentUser($pdo);
$page_id = (int)($_POST['page_id'] ?? 0);

if (!$page_id) {
    echo json_encode(['status' => 'error', 'message' => 'ID halaman tidak valid']);
    exit;
}

try {
    $result = duplicateLandingPage($pdo, $page_id, $user['id']);
} catch (Exception $e) {
    // Tangkap semua error dan kirim sebagai JSON
    $result = [
        'status' => 'error',
        'message' => 'Server error: ' . $e->getMessage(),
        'debug' => [
            'file' => $e->getFile(),
            'line' => $e->getLine()
        ]
    ];
}

header('Content-Type: application/json');
echo json_encode($result);
?>