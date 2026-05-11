<?php
// save_page.php
require_once '../includes/config.php';
require_once '../includes/auth.php';

header('Content-Type: application/json');

requireLogin();
$user = getCurrentUser($pdo);

// Ambil input JSON
$input = json_decode(file_get_contents('php://input'), true);
$page_id = $input['page_id'] ?? null;
$settings = $input['settings'] ?? []; // Tangkap data settings
$elements = $input['elements'] ?? [];

if (!$page_id) {
    echo json_encode(['success' => false, 'message' => 'Invalid Page ID']);
    exit;
}

try {
    $pdo->beginTransaction();

    // 1. UPDATE DATA HALAMAN (Sistem Pixel Baru)
    if (!empty($settings)) {
        $pixel_profile_id = !empty($settings['pixel_profile_id']) ? (int)$settings['pixel_profile_id'] : null;

        $stmtPage = $pdo->prepare("
            UPDATE landing_pages 
            SET title = ?, 
                slug = ?, 
                pixel_profile_id = ?, 
                meta_event_name = ?
            WHERE id = ? AND user_id = ?
        ");
        
        $stmtPage->execute([
            $settings['title'],
            $settings['slug'],
            $pixel_profile_id,
            $settings['event_name'],
            $page_id,
            $user['id']
        ]);
    }

    // 2. HAPUS ELEMEN LAMA
    $delete = $pdo->prepare("DELETE FROM page_elements WHERE page_id = ?");
    $delete->execute([$page_id]);

    // 3. INSERT ELEMEN BARU
    $insert = $pdo->prepare("
        INSERT INTO page_elements (page_id, type, content, order_position, styles) 
        VALUES (?, ?, ?, ?, ?)
    ");

    foreach ($elements as $index => $el) {
        $insert->execute([
            $page_id,
            $el['type'],
            $el['content'],
            $index, // Gunakan index loop 0,1,2...
            json_encode($el['styles'])
        ]);
    }

    $pdo->commit();
    echo json_encode(['success' => true]);

} catch (Exception $e) {
    $pdo->rollBack();
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>