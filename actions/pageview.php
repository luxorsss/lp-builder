<?php
require_once '../includes/config.php';

$input = json_decode(file_get_contents('php://input'), true);
$page_slug = trim($input['page_slug'] ?? '');
$event = trim($input['event'] ?? 'ViewContent');
$fbp = trim($input['fbp'] ?? '');
$fbc = trim($input['fbc'] ?? '');
$user_agent = trim($input['user_agent'] ?? '');
$ip = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'];
$ip = trim(explode(',', $ip)[0]);

if (empty($page_slug)) exit;

$stmt = $pdo->prepare("SELECT capi_endpoint, capi_access_token FROM landing_pages WHERE slug = ? AND status = 'published'");
$stmt->execute([$page_slug]);
$page = $stmt->fetch();

if (!$page || empty($page['capi_endpoint'])) exit;

$payload = [
    'data' => [[
        'event_name' => $event,
        'event_time' => time(),
        'action_source' => 'website',
        'event_source_url' => "https://$_SERVER[HTTP_HOST]/$page_slug",
        'client_ip_address' => $ip,
        'client_user_agent' => $user_agent,
        'fbp' => $fbp,
        'fbc' => $fbc
    ]]
];

$ch = curl_init($page['capi_endpoint']);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Content-Type: application/json',
    'Authorization: Bearer ' . $page['capi_access_token']
]);
curl_exec($ch);
curl_close($ch);
exit;