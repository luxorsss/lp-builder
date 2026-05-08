<?php
require_once '../includes/config.php';
require_once '../includes/auth.php';
requireLogin();
$user_id = $_SESSION['user_id'];

try {
    $title = trim($_POST['title'] ?? '');
    $slug = trim($_POST['slug'] ?? '');
    $status = $_POST['status'] ?? 'draft';
    $pixel_id = trim($_POST['pixel_id'] ?? '');
    $capi_endpoint = trim($_POST['capi_endpoint'] ?? '');
    $capi_access_token = trim($_POST['capi_access_token'] ?? '');
    $page_id = (int)($_POST['page_id'] ?? 0);

    // Validasi dasar
    if (empty($title)) throw new Exception("Judul wajib diisi");
    if (empty($slug)) $slug = preg_replace('/[^a-z0-9]+/', '-', strtolower($title));

    // Pastikan slug unik
    $orig_slug = $slug;
    $counter = 1;
    while (true) {
        $stmt = $pdo->prepare("SELECT id FROM landing_pages WHERE slug = ? AND user_id = ? AND id != ?");
        $stmt->execute([$slug, $user_id, $page_id]);
        if (!$stmt->fetch()) break;
        $slug = $orig_slug . '-' . $counter++;
    }

    $pdo->beginTransaction();

    // Simpan landing page (meta_pixel_id = string pixel_id untuk kompatibilitas)
    if ($page_id) {
        $stmt = $pdo->prepare("UPDATE landing_pages SET title=?, slug=?, status=?, meta_pixel_id=?, capi_endpoint=?, capi_access_token=?, updated_at=NOW() WHERE id=? AND user_id=?");
        $stmt->execute([$title, $slug, $status, $pixel_id, $capi_endpoint, $capi_access_token, $page_id, $user_id]);
    } else {
        $stmt = $pdo->prepare("INSERT INTO landing_pages (user_id, title, slug, status, meta_pixel_id, capi_endpoint, capi_access_token, created_at, updated_at) VALUES (?,?,?,?,?,?,?,?,NOW())");
        $stmt->execute([$user_id, $title, $slug, $status, $pixel_id, $capi_endpoint, $capi_access_token, date('Y-m-d H:i:s')]);
        $page_id = $pdo->lastInsertId();
    }

    // Kosongkan elemen lama
    $stmt = $pdo->prepare("DELETE FROM page_elements WHERE page_id = ?");
    $stmt->execute([$page_id]);

    // Simpan elemen baru
    if (isset($_POST['elements']) && is_array($_POST['elements'])) {
        $order = 0;
        foreach ($_POST['elements'] as $key => $elem) {
            $type = $elem['type'] ?? '';
            $content = $elem['content'] ?? '';
            $styles = isset($elem['styles']) ? json_encode($elem['styles']) : null;

            if (!in_array($type, ['header','paragraph','divider','image','youtube','button','faq','testimonial'])) continue;

            $stmt = $pdo->prepare("INSERT INTO page_elements (page_id, type, content, order_position, styles) VALUES (?,?,?,?,?)");
            $stmt->execute([$page_id, $type, $content, $order++, $styles]);
        }
    }

    $pdo->commit();

    echo json_encode(['success' => true, 'id' => $page_id]);

} catch (Exception $e) {
    $pdo->rollBack();
    error_log("[SAVE ERROR] PageID={$page_id}, UserID={$user_id}: " . $e->getMessage());
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}