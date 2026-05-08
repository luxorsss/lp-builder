<?php
require_once '../includes/config.php';
require_once '../includes/auth.php';

requireLogin();
$user = getCurrentUser($pdo);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Method not allowed']);
    exit;
}

if (!isset($_POST['page_ids']) || !is_array($_POST['page_ids']) || empty($_POST['page_ids'])) {
    echo json_encode(['status' => 'error', 'message' => 'Tidak ada halaman yang dipilih.']);
    exit;
}

$new_status = $_POST['status'] === 'published' ? 'published' : 'draft';
$page_ids = array_map('intval', $_POST['page_ids']);
$placeholders = str_repeat('?,', count($page_ids) - 1) . '?';

$stmt = $pdo->prepare("UPDATE landing_pages SET status = ? WHERE id IN ($placeholders) AND user_id = ?");
$params = array_merge([$new_status], $page_ids, [$user['id']]);
$stmt->execute($params);

$affected = $stmt->rowCount();
echo json_encode([
    'status' => 'success',
    'message' => "Berhasil memperbarui status $affected landing page menjadi '$new_status'."
]);