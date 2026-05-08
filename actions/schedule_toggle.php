<?php
require_once '../includes/config.php';
require_once '../includes/auth.php';

requireLogin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || $_POST['action'] !== 'schedule_toggle') {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Invalid request']);
    exit;
}

$user = getCurrentUser($pdo);
$page_id = (int)($_POST['page_id'] ?? 0);
$new_status = $_POST['new_status'] === 'published' ? 'published' : 'draft';
$schedule_time = $_POST['schedule_time'] ?? '';

if (empty($schedule_time) || !strtotime($schedule_time)) {
    echo json_encode(['status' => 'error', 'message' => 'Waktu tidak valid']);
    exit;
}

$stmt = $pdo->prepare("SELECT id FROM landing_pages WHERE id = ? AND user_id = ?");
$stmt->execute([$page_id, $user['id']]);
$page = $stmt->fetch();

if (!$page) {
    echo json_encode(['status' => 'error', 'message' => 'Landing page tidak ditemukan']);
    exit;
}

$stmt = $pdo->prepare("
    UPDATE landing_pages 
    SET next_scheduled_status = ?, next_schedule_time = ? 
    WHERE id = ?
");
$stmt->execute([$new_status, $schedule_time, $page_id]);

echo json_encode([
    'status' => 'success',
    'message' => "Jadwal berhasil disimpan: $new_status pada $schedule_time"
]);
?>