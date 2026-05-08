<?php
require_once '../includes/config.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';

requireLogin();
$user = getCurrentUser($pdo);

header('Content-Type: application/json; charset=utf-8');

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception('Metode tidak diizinkan', 405);
    }

    $page_id = (int)($_POST['id'] ?? $_GET['id'] ?? 0);
    if (!$page_id) {
        // Coba cari dari hidden input di form (fallback)
        $page_id = (int)($_POST['elements'] ? array_key_first($_POST['elements']) : 0);
        // Jika tetap tidak ketemu → error
        if (!$page_id && !isset($_POST['title'])) {
            throw new Exception('ID halaman tidak ditemukan', 400);
        }
    }

    // Ambil data halaman saat ini (bila ada)
    $page = null;
    if ($page_id) {
        $page = getUserLandingPage($pdo, $page_id, $user['id']);
        if (!$page) {
            throw new Exception('Landing page tidak ditemukan atau bukan milik Anda', 404);
        }
    }

    // Ambil data dari POST
    $title = trim($_POST['title'] ?? ($page['title'] ?? ''));
    $slug = trim($_POST['slug'] ?? ($page['slug'] ?? ''));
    $pixel_id = trim($_POST['pixel_id'] ?? ($page['pixel_id'] ?? ''));
    $meta_event_name = trim($_POST['meta_event_name'] ?? ($page['meta_event_name'] ?? 'ViewContent'));
    $capi_endpoint = trim($_POST['capi_endpoint'] ?? ($page['capi_endpoint'] ?? ''));
    $capi_access_token = trim($_POST['capi_access_token'] ?? ($page['capi_access_token'] ?? ''));
    $meta_pixel_id = $pixel_id; // karena buildTrackingConfig() saat ini hanya return $pixel_id

    // Validasi minimal: title & slug (tapi tidak gagal fatal → cukup abaikan jika null)
    if (empty($title)) $title = 'Untitled Draft';
    if (empty($slug)) $slug = generateUniqueSlug($pdo, $title, $user['id']);

    // Persiapkan data elemen
    $elements = $_POST['elements'] ?? [];
    if (!is_array($elements)) $elements = [];

    // Gunakan transaksi
    $pdo->beginTransaction();

    try {
        if ($page_id) {
            // Update halaman — **jangan ubah status** (biarkan tetap draft/published sesuai aslinya)
            $stmt = $pdo->prepare("
                UPDATE landing_pages 
                SET title = ?, slug = ?, meta_pixel_id = ?, meta_event_name = ?, 
                    capi_endpoint = ?, capi_access_token = ?, updated_at = NOW() 
                WHERE id = ? AND user_id = ?
            ");
            $stmt->execute([$title, $slug, $meta_pixel_id, $meta_event_name, $capi_endpoint, $capi_access_token, $page_id, $user['id']]);
        } else {
            // Insert baru — pastikan status = 'draft'
            $stmt = $pdo->prepare("
                INSERT INTO landing_pages 
                (user_id, title, slug, meta_pixel_id, meta_event_name, capi_endpoint, capi_access_token, status, created_at, updated_at) 
                VALUES (?, ?, ?, ?, ?, ?, ?, 'draft', NOW(), NOW())
            ");
            $stmt->execute([$user['id'], $title, $slug, $meta_pixel_id, $meta_event_name, $capi_endpoint, $capi_access_token]);
            $page_id = $pdo->lastInsertId();
        }

        // Hapus elemen lama
        $stmt = $pdo->prepare("DELETE FROM page_elements WHERE page_id = ?");
        $stmt->execute([$page_id]);

        // Insert ulang elemen — tetap lewati section_xxx
        $index = 0;
        $insertStmt = $pdo->prepare("
            INSERT INTO page_elements (page_id, type, content, order_position, styles) 
            VALUES (?, ?, ?, ?, ?)
        ");

        ksort($elements);
        foreach ($elements as $key => $el) {
            if (!is_array($el)) continue;
            $type = $el['type'] ?? '';
            if (in_array($type, ['section_1col', 'section_2col', 'section_3col'])) continue;

            $content = $el['content'] ?? '';
            $styles = isset($el['styles']) ? json_encode($el['styles'], JSON_UNESCAPED_UNICODE) : null;

            $insertStmt->execute([$page_id, $type, $content, $index, $styles]);
            $index++;
        }

        $pdo->commit();

        echo json_encode([
            'success' => true,
            'id' => $page_id,
            'title' => $title,
            'slug' => $slug,
            'timestamp' => date('c')
        ]);

    } catch (Exception $e) {
        $pdo->rollBack();
        throw $e;
    }

} catch (Exception $e) {
    http_response_code($e->getCode() ?: 500);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage(),
        'code' => $e->getCode()
    ]);
}