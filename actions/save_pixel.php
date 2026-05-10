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
    $clarity_project_id = trim($_POST['clarity_project_id']); // Tangkap input Clarity

    if ($id > 0) {
        $stmt = $pdo->prepare("UPDATE pixel_profiles SET name=?, pixel_id=?, capi_token=?, capi_endpoint=?, clarity_project_id=? WHERE id=? AND user_id=?");
        $stmt->execute([$name, $pixel_id, $capi_token, $capi_endpoint, $clarity_project_id, $id, $user['id']]);
    } else {
        $stmt = $pdo->prepare("INSERT INTO pixel_profiles (user_id, name, pixel_id, capi_token, capi_endpoint, clarity_project_id) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->execute([$user['id'], $name, $pixel_id, $capi_token, $capi_endpoint, $clarity_project_id]);
    }

    header("Location: ../pixels.php");
    exit;
}