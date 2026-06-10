<?php
// Fungsi untuk membuat slug unik
function generateUniqueSlug($pdo, $title) {
    $slug = strtolower(trim(preg_replace('/[^A-Za-z0-9-]+/', '-', $title)));
    
    // Cek apakah slug sudah ada
    $stmt = $pdo->prepare("SELECT id FROM landing_pages WHERE slug = ?");
    $stmt->execute([$slug]);
    
    if ($stmt->fetch()) {
        // Jika sudah ada, tambahkan angka
        $counter = 1;
        $new_slug = $slug . '-' . $counter;
        
        do {
            $stmt = $pdo->prepare("SELECT id FROM landing_pages WHERE slug = ?");
            $stmt->execute([$new_slug]);
            if ($stmt->fetch()) {
                $counter++;
                $new_slug = $slug . '-' . $counter;
            } else {
                $slug = $new_slug;
                break;
            }
        } while (true);
    }
    
    return $slug;
}

// Fungsi untuk mendapatkan landing page milik user
function getUserLandingPage($pdo, $page_id, $user_id) {
    $stmt = $pdo->prepare("SELECT * FROM landing_pages WHERE id = ? AND user_id = ?");
    $stmt->execute([$page_id, $user_id]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

// Fungsi untuk memproses halaman terjadwal
function updateScheduledStatus($pdo, $user_id) {
    $current_time = date('Y-m-d H:i:s');
    
    // 1. Ambil halaman milik user yang jadwalnya sudah masuk/lewat waktu sekarang
    $stmt = $pdo->prepare("
        SELECT id, next_scheduled_status 
        FROM landing_pages 
        WHERE user_id = ? 
          AND next_schedule_time IS NOT NULL 
          AND next_schedule_time <= ?
    ");
    $stmt->execute([$user_id, $current_time]);
    $scheduled_pages = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // 2. Update status utama halaman tersebut dan kosongkan kolom jadwalnya
    foreach ($scheduled_pages as $page) {
        $updateStmt = $pdo->prepare("
            UPDATE landing_pages 
            SET status = ?, 
                next_scheduled_status = NULL, 
                next_schedule_time = NULL 
            WHERE id = ?
        ");
        $updateStmt->execute([$page['next_scheduled_status'], $page['id']]);
    }
}
?>