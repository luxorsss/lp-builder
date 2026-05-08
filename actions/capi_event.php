<?php
require_once '../includes/config.php';

// Only accept POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Method not allowed']);
    exit;
}

// Get raw input
$input = file_get_contents('php://input');
$data = json_decode($input, true);

if (!$data || !is_array($data)) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Invalid JSON']);
    exit;
}

$event = trim($data['event'] ?? '');
$page_title = trim($data['page_title'] ?? '');
$page_slug = trim($data['page_slug'] ?? '');
$user_data = $data['user_data'] ?? [];

if (empty($event) || empty($page_slug)) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Missing event or page_slug']);
    exit;
}

// Ambil konfigurasi CAPI dari landing_pages berdasarkan page_slug
$stmt = $pdo->prepare("SELECT capi_endpoint, capi_access_token FROM landing_pages WHERE slug = ? AND status = 'published' LIMIT 1");
$stmt->execute([$page_slug]);
$page = $stmt->fetch(PDO::FETCH_ASSOC);

$capi_endpoint = $page['capi_endpoint'] ?? '';
$capi_access_token = $page['capi_access_token'] ?? '';

// Siapkan data untuk log
$log_data = [
    'event_name' => $event,
    'page_title' => $page_title,
    'page_slug' => $page_slug,
    'user_name' => $user_data['name'] ?? '',
    'user_email' => $user_data['email'] ?? '',
    'user_message' => $user_data['message'] ?? ''
];

// Simpan ke log database dulu (selalu)
try {
    $stmt = $pdo->prepare("
        INSERT INTO capi_events 
        (event_name, page_title, page_slug, user_name, user_email, user_message) 
        VALUES (?, ?, ?, ?, ?, ?)
    ");
    $stmt->execute(array_values($log_data));
} catch (Exception $e) {
    error_log("Failed to log CAPI event to DB: " . $e->getMessage());
}

// Kirim ke Meta CAPI jika konfigurasi tersedia
$sent_to_capi = false;
$response_body = '';

if (!empty($capi_endpoint)) {
    // Hash email (wajib menurut Meta)
    $email = strtolower(trim($user_data['email'] ?? ''));
    $hashed_email = $email ? hash('sha256', $email) : '';

    $payload = [
        'data' => [[
            'event_name' => $event,
            'event_time' => time(),
            'action_source' => 'website',
            'event_source_url' => "https://$_SERVER[HTTP_HOST]/$page_slug",
            'user_data' => array_filter([
                'em' => $hashed_email ? [$hashed_email] : null
            ])
        ]]
    ];

    $ch = curl_init($capi_endpoint);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $capi_access_token
    ]);

    $response_body = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($http_code >= 200 && $http_code < 300) {
        $sent_to_capi = true;
    } else {
        error_log("CAPI Send Failed [$page_slug]: HTTP $http_code, Response: $response_body");
    }
}

if ($sent_to_capi) {
    echo json_encode(['status' => 'success', 'message' => 'Event sent to Meta CAPI']);
} else {
    if (!empty($capi_endpoint)) {
        echo json_encode(['status' => 'error', 'message' => 'Failed to send to CAPI (logged locally)']);
    } else {
        echo json_encode(['status' => 'success', 'message' => 'Event logged (no CAPI config)']);
    }
}