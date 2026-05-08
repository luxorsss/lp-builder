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
?>