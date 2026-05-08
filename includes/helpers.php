<?php
function updateScheduledStatus($pdo, $user_id) {
    $stmt = $pdo->prepare("
        SELECT id, next_scheduled_status
        FROM landing_pages
        WHERE user_id = ? AND next_schedule_time <= NOW() AND next_scheduled_status IS NOT NULL
    ");
    $stmt->execute([$user_id]);
    $pages = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($pages as $page) {
        $stmt = $pdo->prepare("
            UPDATE landing_pages
            SET status = ?, next_scheduled_status = NULL, next_schedule_time = NULL
            WHERE id = ?
        ");
        $stmt->execute([$page['next_scheduled_status'], $page['id']]);
    }
}

function duplicateLandingPage($pdo, $page_id, $user_id) {
    // Ambil data dasar landing page
    $stmt = $pdo->prepare("
        SELECT title, slug, meta_pixel_id, status, capi_endpoint, capi_access_token
        FROM landing_pages 
        WHERE id = ? AND user_id = ?
    ");
    $stmt->execute([$page_id, $user_id]);
    $page = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$page) {
        return ['status' => 'error', 'message' => 'Landing page tidak ditemukan'];
    }

    // Buat slug baru
    $new_slug = generateUniqueSlug($pdo, $page['slug'], $user_id);
    $new_title = $page['title'] . ' (Copy)';

    // Insert landing page baru
    $stmt = $pdo->prepare("
        INSERT INTO landing_pages (user_id, title, slug, meta_pixel_id, status, capi_endpoint, capi_access_token, created_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
    ");
    $stmt->execute([
        $user_id,
        $new_title,
        $new_slug,
        $page['meta_pixel_id'],
        $page['status'],
        $page['capi_endpoint'],
        $page['capi_access_token']
    ]);
    $new_page_id = $pdo->lastInsertId();

    // Ambil semua elemen dari page asli
    $stmt = $pdo->prepare("SELECT type, content, order_position, styles FROM page_elements WHERE page_id = ? ORDER BY order_position");
    $stmt->execute([$page_id]);
    $elements = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Insert semua elemen ke page baru
    $stmt = $pdo->prepare("
        INSERT INTO page_elements (page_id, type, content, order_position, styles)
        VALUES (?, ?, ?, ?, ?)
    ");
    foreach ($elements as $element) {
        $stmt->execute([
            $new_page_id,
            $element['type'],
            $element['content'],
            $element['order_position'],
            $element['styles']
        ]);
    }

    return [
        'status' => 'success',
        'message' => 'Landing page berhasil diduplikat',
        'new_id' => $new_page_id
    ];
}

function generateUniqueSlug($pdo, $original_slug, $user_id) {
    $counter = 1;
    $new_slug = $original_slug;

    while (true) {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM landing_pages WHERE slug = ? AND user_id = ?");
        $stmt->execute([$new_slug, $user_id]);
        $count = $stmt->fetchColumn();

        if ($count == 0) {
            return $new_slug;
        }

        $new_slug = $original_slug . '_' . $counter;
        $counter++;
    }
}
?>