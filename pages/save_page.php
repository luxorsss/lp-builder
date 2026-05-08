<?php
// save_page.php
require_once '../includes/config.php';
require_once '../includes/auth.php';

header('Content-Type: application/json');

// Pastikan user login (sesuaikan dengan fungsi auth kamu)
// if (!isLoggedIn()) { ... } 

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

    // 1. UPDATE DATA HALAMAN (Judul, Slug, Tracking, Status)
    if (!empty($settings)) {
        $stmtPage = $pdo->prepare("
            UPDATE landing_pages 
            SET title = ?, 
                slug = ?, 
                meta_pixel_id = ?, 
                meta_event_name = ?, 
                capi_access_token = ?, 
                capi_endpoint = ?,
                status = 'published'
            WHERE id = ?
        ");
        
        $stmtPage->execute([
            $settings['title'],
            $settings['slug'],
            $settings['pixel_id'],
            $settings['event_name'],
            $settings['capi_token'],
            $settings['capi_endpoint'],
            $page_id
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