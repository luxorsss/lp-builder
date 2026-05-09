<?php
require_once '../includes/config.php';
require_once '../includes/auth.php';

requireLogin();
$user = getCurrentUser($pdo);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = (int)$_POST['pixel_id_internal'];
    $name = trim($_POST['name']);
    $pixel_id = trim($_POST['pixel_id']);
    $capi_token = trim($_POST['capi_token']);
    $capi_endpoint = trim($_POST['capi_endpoint']);

    if ($id > 0) {
        $stmt = $pdo->prepare("UPDATE pixel_profiles SET name=?, pixel_id=?, capi_token=?, capi_endpoint=? WHERE id=? AND user_id=?");
        $stmt->execute([$name, $pixel_id, $capi_token, $capi_endpoint, $id, $user['id']]);
    } else {
        $stmt = $pdo->prepare("INSERT INTO pixel_profiles (user_id, name, pixel_id, capi_token, capi_endpoint) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([$user['id'], $name, $pixel_id, $capi_token, $capi_endpoint]);
    }

    header("Location: ../pixels.php");
    exit;
}