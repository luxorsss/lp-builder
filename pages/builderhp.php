<?php
require_once '../includes/config.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';

if (function_exists('requireLogin')) {
    requireLogin();
}

$user = function_exists('getCurrentUser') ? getCurrentUser($pdo) : ['id' => 1];
$page_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$error = ''; 
$success = '';

// TAMBAH ELEMEN BARU VIA AJAX/POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_element'])) {
    $type = $_POST['element_type'];
    if (in_array($type, ['paragraph','button','image','youtube','divider','faq','html'])) {
        if (!isset($_SESSION)) session_start();
        $_SESSION['temp_new_element'] = $type;
        echo json_encode(['success' => true, 'type' => $type]);
        exit;
    }
}

// AMBIL DATA HALAMAN
$page = null;
$elements = [];

if ($page_id > 0) {
    if(function_exists('getUserLandingPage')) {
        $page = getUserLandingPage($pdo, $page_id, $user['id']);
    } else {
        $stmt = $pdo->prepare("SELECT * FROM landing_pages WHERE id=? AND user_id=?");
        $stmt->execute([$page_id, $user['id']]);
        $page = $stmt->fetch(PDO::FETCH_ASSOC);
    }

    if ($page) {
        $stmt = $pdo->prepare("SELECT * FROM page_elements WHERE page_id=? ORDER BY order_position ASC");
        $stmt->execute([$page_id]);
        $elements = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}

// Cek Session untuk elemen baru sementara
if (!isset($_SESSION)) session_start();
if (isset($_SESSION['temp_new_element'])) {
    $elements[] = [
        'type' => $_SESSION['temp_new_element'],
        'content' => '',
        'styles' => json_encode([])
    ];
    unset($_SESSION['temp_new_element']);
}

// FUNGSI RENDER UI
function renderElementUI($type, $idx, $content, $st) {
    $bg = $st['bg_color'] ?? '#ffffff';
    $tx = $st['text_color'] ?? '#000000';
    $link = $st['link'] ?? '#';
    
    $icons = [
        'paragraph' => ['icon' => 'fas fa-font', 'color' => '#3b82f6', 'name' => 'Teks'],
        'button' => ['icon' => 'fas fa-square', 'color' => '#10b981', 'name' => 'Tombol'],
        'image' => ['icon' => 'fas fa-image', 'color' => '#f59e0b', 'name' => 'Gambar'],
        'youtube' => ['icon' => 'fab fa-youtube', 'color' => '#ef4444', 'name' => 'Video'],
        'divider' => ['icon' => 'fas fa-minus', 'color' => '#6b7280', 'name' => 'Pembatas'],
        'faq' => ['icon' => 'fas fa-question-circle', 'color' => '#8b5cf6', 'name' => 'FAQ'],
        'html' => ['icon' => 'fas fa-code', 'color' => '#ec4899', 'name' => 'HTML']
    ];
    $icon = $icons[$type] ?? ['icon' => 'fas fa-cube', 'color' => '#6b7280', 'name' => ucfirst($type)];

    $html = '<div class="element-card" id="el-target-'.$idx.'" data-element-index="'.$idx.'" data-element-type="'.$type.'">';

    // HEADER - FULL WIDTH TAP AREA
    $html .= '<div class="element-header" onclick="toggleElementSettings('.$idx.', event)">';
        $html .= '<div class="element-icon" style="background: '.$icon['color'].'20;">';
            $html .= '<i class="'.$icon['icon'].'" style="color: '.$icon['color'].'"></i>';
        $html .= '</div>';
        
        $html .= '<div class="element-info" style="flex: 1;">';
            $html .= '<div class="element-title">'.$icon['name'].'</div>';
            $html .= '<div class="element-number">#'.($idx+1).'</div>';
        $html .= '</div>';
        
        $html .= '<div class="element-actions">';
            $html .= '<button type="button" class="btn-icon" onclick="event.stopPropagation(); moveElement('.$idx.', -1)"><i class="fas fa-arrow-up"></i></button>';
            $html .= '<button type="button" class="btn-icon" onclick="event.stopPropagation(); moveElement('.$idx.', 1)"><i class="fas fa-arrow-down"></i></button>';
            $html .= '<div class="toggle-arrow"><i class="fas fa-chevron-down" id="chevron-'.$idx.'"></i></div>';
        $html .= '</div>';
    $html .= '</div>';

    // SETTINGS
    $html .= '<div class="element-settings" id="settings-'.$idx.'">';
        $html .= '<input type="hidden" name="elements['.$idx.'][type]" value="'.$type.'">';
        $html .= '<input type="hidden" name="elements['.$idx.'][styles][bg_color]" class="in-bg" value="'.$bg.'">';
        $html .= '<input type="hidden" name="elements['.$idx.'][styles][text_color]" class="in-tx" value="'.$tx.'">';
        $html .= '<input type="hidden" name="elements['.$idx.'][styles][link]" class="in-link" value="'.$link.'">';
        
        if ($type === 'divider') {
            $thickness = $st['thickness'] ?? 2;
            $html .= '<input type="hidden" name="elements['.$idx.'][styles][thickness]" class="in-thickness" value="'.$thickness.'">';
        }

        $html .= '<textarea name="elements['.$idx.'][content]" class="d-none in-content">'.htmlspecialchars($content).'</textarea>';

        $html .= '<div class="settings-content" id="form-'.$idx.'"></div>';

        $html .= '<div class="element-footer">';
            $html .= '<button type="button" class="btn btn-action btn-duplicate" onclick="duplicateElement('.$idx.')"><i class="fas fa-copy"></i> <span>Duplikat</span></button>';
            $html .= '<button type="button" class="btn btn-action btn-delete" onclick="deleteElement('.$idx.')"><i class="fas fa-trash"></i> <span>Hapus</span></button>';
        $html .= '</div>';
    $html .= '</div>';
    $html .= '</div>';

    return $html;
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>LP Builder - <?= $page['title'] ?? 'Halaman Baru' ?></title>
    
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css" rel="stylesheet">
    <link href="https://cdn.quilljs.com/1.3.6/quill.snow.css" rel="stylesheet">
    
    <style>
        :root {
            --primary: #4361ee;
            --primary-light: #4895ef;
            --secondary: #7209b7;
            --light: #f8f9fa;
            --dark: #1a1a1a;
            --gray: #6c757d;
            --gray-light: #e9ecef;
            --success: #10b981;
            --danger: #f72585;
            --radius: 12px;
            --shadow: 0 4px 12px rgba(0,0,0,0.1);
            --shadow-lg: 0 8px 24px rgba(0,0,0,0.15);
        }

        * {
            box-sizing: border-box;
            -webkit-tap-highlight-color: transparent;
        }

        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: #f5f7fb;
            color: var(--dark);
            margin: 0;
            padding-bottom: 120px;
            -webkit-user-select: none;
            user-select: none;
        }

        /* HEADER */
        .app-header {
            background: white;
            padding: 12px 16px;
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            z-index: 1000;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
            display: flex;
            align-items: center;
            justify-content: space-between;
            height: 56px;
        }

        .page-title {
            font-weight: 600;
            color: var(--primary);
            font-size: 1.1rem;
            display: flex;
            align-items: center;
            gap: 8px;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .page-title i {
            font-size: 1.2rem;
        }

        .header-actions {
            display: flex;
            gap: 8px;
            align-items: center;
        }

        .status-badge {
            font-size: 0.75rem;
            padding: 4px 8px;
            border-radius: 20px;
            background: var(--gray-light);
            color: var(--gray);
            font-weight: 500;
        }

        .status-badge.published {
            background: #d1fae5;
            color: #065f46;
        }

        /* MAIN CONTENT */
        .main-content {
            margin-top: 56px;
            padding: 16px;
            max-width: 800px;
            margin-left: auto;
            margin-right: auto;
        }

        /* TABS - BIGGER TOUCH TARGET */
        .tabs-container {
            background: white;
            border-radius: var(--radius);
            padding: 8px;
            margin-bottom: 16px;
            display: flex;
            gap: 4px;
            box-shadow: var(--shadow);
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
            scrollbar-width: none;
        }

        .tabs-container::-webkit-scrollbar {
            display: none;
        }

        .tab {
            flex: 1;
            min-width: 100px;
            padding: 12px 16px;
            background: transparent;
            border: 2px solid transparent;
            border-radius: 8px;
            font-size: 0.9rem;
            font-weight: 500;
            color: var(--gray);
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            white-space: nowrap;
            transition: all 0.2s;
            min-height: 44px; /* Minimum touch target size */
        }

        .tab.active {
            background: var(--primary);
            color: white;
        }

        .tab i {
            font-size: 0.9rem;
        }

        /* TAB CONTENT */
        .tab-content {
            background: white;
            border-radius: var(--radius);
            margin-bottom: 16px;
            box-shadow: var(--shadow);
            overflow: hidden;
        }

        .tab-pane {
            display: none;
            animation: fadeIn 0.3s ease;
        }

        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }

        .tab-pane.active {
            display: block;
        }

        /* CONTENT AREA */
        .content-area {
            padding: 20px;
        }

        .empty-state {
            text-align: center;
            padding: 60px 20px;
            color: var(--gray);
        }

        .empty-state-icon {
            font-size: 3rem;
            color: var(--gray-light);
            margin-bottom: 16px;
        }

        .empty-state h4 {
            font-size: 1.2rem;
            font-weight: 600;
            margin-bottom: 8px;
            color: var(--dark);
        }

        .empty-state p {
            font-size: 0.95rem;
            color: var(--gray);
            max-width: 300px;
            margin: 0 auto;
            line-height: 1.5;
        }

        /* ELEMENT CARD - BIGGER FOR MOBILE */
        .element-card {
            background: white;
            border-radius: var(--radius);
            margin-bottom: 12px;
            box-shadow: var(--shadow);
            border: 1px solid var(--gray-light);
            overflow: hidden;
        }

        .element-header {
            padding: 16px;
            display: flex;
            align-items: center;
            gap: 12px;
            cursor: pointer;
            background: white;
            border-bottom: 1px solid transparent;
            transition: background 0.2s;
            min-height: 70px; /* Bigger touch target */
            -webkit-tap-highlight-color: rgba(0,0,0,0.1);
        }

        .element-header:active {
            background: var(--light);
        }

        .element-header.active {
            background: var(--light);
            border-bottom-color: var(--gray-light);
        }

        .element-icon {
            width: 44px;
            height: 44px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }

        .element-icon i {
            font-size: 1.3rem;
        }

        .element-info {
            flex: 1;
            min-width: 0;
            display: flex;
            flex-direction: column;
            justify-content: center;
        }

        .element-title {
            font-weight: 600;
            font-size: 1rem;
            color: var(--dark);
            margin-bottom: 4px;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .element-number {
            font-size: 0.85rem;
            color: var(--gray);
        }

        .element-actions {
            display: flex;
            align-items: center;
            gap: 8px;
            flex-shrink: 0;
        }

        .btn-icon {
            width: 40px;
            height: 40px;
            border: 1px solid var(--gray-light);
            background: white;
            border-radius: 8px;
            color: var(--gray);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1rem;
            transition: all 0.2s;
            flex-shrink: 0;
        }

        .btn-icon:active {
            background: var(--light);
            border-color: var(--gray);
            color: var(--dark);
            transform: scale(0.95);
        }

        .toggle-arrow {
            color: var(--gray);
            margin-left: 4px;
            transition: transform 0.3s;
            font-size: 1.1rem;
        }

        .toggle-arrow.rotated {
            transform: rotate(180deg);
            color: var(--primary);
        }

        /* ELEMENT SETTINGS */
        .element-settings {
            display: none;
            padding: 20px;
            background: var(--light);
            border-top: 1px solid var(--gray-light);
        }

        .element-settings.show {
            display: block;
            animation: slideDown 0.3s ease;
        }

        @keyframes slideDown {
            from {
                opacity: 0;
                transform: translateY(-10px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .settings-content {
            margin-bottom: 20px;
        }

        .form-group {
            margin-bottom: 20px;
        }

        .form-label {
            font-weight: 600;
            font-size: 0.95rem;
            color: var(--dark);
            margin-bottom: 8px;
            display: block;
        }

        .form-control {
            width: 100%;
            padding: 14px;
            border: 2px solid var(--gray-light);
            border-radius: 10px;
            font-size: 1rem;
            transition: all 0.2s;
            background: white;
            min-height: 48px; /* Bigger for touch */
        }

        .form-control:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(67, 97, 238, 0.1);
            outline: none;
        }

        .color-picker {
            display: flex;
            gap: 10px;
            align-items: center;
        }

        .color-picker input[type="color"] {
            width: 60px;
            height: 48px;
            padding: 2px;
            border: 2px solid var(--gray-light);
            border-radius: 10px;
            cursor: pointer;
            flex-shrink: 0;
        }

        .element-footer {
            display: flex;
            gap: 12px;
            justify-content: flex-end;
            padding-top: 20px;
            border-top: 1px solid var(--gray-light);
        }

        .btn-action {
            padding: 12px 20px;
            border-radius: 10px;
            font-weight: 500;
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 0.95rem;
            min-height: 44px; /* Minimum touch target */
        }

        .btn-duplicate {
            background: var(--light);
            border: 2px solid var(--gray-light);
            color: var(--dark);
        }

        .btn-delete {
            background: var(--danger);
            border: 2px solid var(--danger);
            color: white;
        }

        /* SETTINGS TAB */
        .settings-tab {
            padding: 20px;
        }

        .section-title {
            font-weight: 600;
            font-size: 1.1rem;
            color: var(--dark);
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        /* ADD ELEMENT FAB - BIGGER */
        .fab-container {
            position: fixed;
            bottom: 80px;
            right: 16px;
            z-index: 1000;
        }

        .fab-btn {
            width: 60px;
            height: 60px;
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            border: none;
            border-radius: 50%;
            color: white;
            font-size: 1.8rem;
            display: flex;
            align-items: center;
            justify-content: center;
            box-shadow: var(--shadow-lg);
            transition: all 0.3s;
            cursor: pointer;
            -webkit-tap-highlight-color: rgba(0,0,0,0.2);
        }

        .fab-btn:active {
            transform: scale(0.95);
        }

        /* ELEMENT MODAL */
        .modal-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0,0,0,0.5);
            z-index: 2000;
            animation: fadeIn 0.3s ease;
            touch-action: none;
        }

        .modal-overlay.show {
            display: block;
        }

        .element-modal {
            position: fixed;
            bottom: 0;
            left: 0;
            right: 0;
            background: white;
            border-radius: 24px 24px 0 0;
            padding: 24px 20px;
            z-index: 2001;
            animation: slideUp 0.3s ease;
            max-height: 80vh;
            overflow-y: auto;
            display: none;
            -webkit-overflow-scrolling: touch;
        }

        @keyframes slideUp {
            from {
                transform: translateY(100%);
            }
            to {
                transform: translateY(0);
            }
        }

        .modal-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 24px;
            padding-bottom: 16px;
            border-bottom: 1px solid var(--gray-light);
        }

        .modal-title {
            font-weight: 600;
            font-size: 1.3rem;
            color: var(--dark);
            margin: 0;
        }

        .close-btn {
            background: none;
            border: none;
            color: var(--gray);
            font-size: 1.5rem;
            width: 48px;
            height: 48px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 10px;
            cursor: pointer;
            -webkit-tap-highlight-color: rgba(0,0,0,0.1);
        }

        .close-btn:active {
            background: var(--light);
        }

        .element-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 16px;
        }

        @media (min-width: 768px) {
            .element-grid {
                grid-template-columns: repeat(3, 1fr);
            }
        }

        .element-option {
            background: var(--light);
            border: 2px solid var(--gray-light);
            border-radius: 12px;
            padding: 20px 16px;
            text-align: center;
            cursor: pointer;
            transition: all 0.2s;
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 12px;
            min-height: 120px; /* Bigger touch target */
            -webkit-tap-highlight-color: rgba(0,0,0,0.1);
        }

        .element-option:active {
            background: white;
            transform: scale(0.98);
        }

        .element-option i {
            font-size: 2rem;
            margin-bottom: 4px;
        }

        .element-name {
            font-weight: 600;
            font-size: 1rem;
            color: var(--dark);
        }

        .element-desc {
            font-size: 0.85rem;
            color: var(--gray);
            margin-top: 4px;
            line-height: 1.4;
        }

        /* SAVE BUTTON - BIGGER */
        .save-container {
            position: fixed;
            bottom: 0;
            left: 0;
            right: 0;
            background: white;
            padding: 16px;
            box-shadow: 0 -2px 10px rgba(0,0,0,0.1);
            z-index: 999;
        }

        .save-btn {
            width: 100%;
            padding: 18px;
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            color: white;
            border: none;
            border-radius: var(--radius);
            font-weight: 600;
            font-size: 1.1rem;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 12px;
            transition: all 0.3s;
            cursor: pointer;
            min-height: 56px; /* Bigger touch target */
            -webkit-tap-highlight-color: rgba(0,0,0,0.2);
        }

        .save-btn:active {
            transform: scale(0.98);
        }

        .save-btn.loading {
            opacity: 0.8;
            cursor: not-allowed;
        }

        /* NOTIFICATION */
        .notification {
            position: fixed;
            top: 70px;
            left: 50%;
            transform: translateX(-50%);
            background: white;
            border-radius: var(--radius);
            padding: 16px 24px;
            box-shadow: var(--shadow-lg);
            z-index: 3000;
            animation: slideIn 0.3s ease;
            display: flex;
            align-items: center;
            gap: 12px;
            max-width: 90%;
            min-width: 300px;
        }

        @keyframes slideIn {
            from {
                opacity: 0;
                transform: translate(-50%, -20px);
            }
            to {
                opacity: 1;
                transform: translate(-50%, 0);
            }
        }

        .notification.success {
            border-left: 4px solid var(--success);
        }

        .notification.error {
            border-left: 4px solid var(--danger);
        }

        .notification i {
            font-size: 1.3rem;
        }

        .notification.success i {
            color: var(--success);
        }

        .notification.error i {
            color: var(--danger);
        }

        /* QUILL EDITOR */
        .ql-toolbar {
            border: none !important;
            border-bottom: 1px solid var(--gray-light) !important;
            background: white !important;
            padding: 12px !important;
            border-radius: 10px 10px 0 0;
        }

        .ql-container {
            border: none !important;
            font-size: 16px !important;
            min-height: 200px !important;
            border-radius: 0 0 10px 10px;
        }

        .ql-editor {
            min-height: 150px !important;
            padding: 16px !important;
            font-size: 16px !important;
        }

        .ql-toolbar .ql-formats {
            margin-right: 8px !important;
        }

        /* FAQ ITEMS */
        .faq-items {
            max-height: 300px;
            overflow-y: auto;
            margin-bottom: 16px;
            -webkit-overflow-scrolling: touch;
        }

        .faq-item {
            background: white;
            border: 1px solid var(--gray-light);
            border-radius: 10px;
            padding: 16px;
            margin-bottom: 12px;
        }

        .add-faq-btn {
            width: 100%;
            padding: 16px;
            background: var(--light);
            border: 2px dashed var(--gray-light);
            border-radius: 10px;
            color: var(--gray);
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            cursor: pointer;
            transition: all 0.2s;
            font-size: 1rem;
            min-height: 52px;
        }

        .add-faq-btn:active {
            border-color: var(--primary);
            color: var(--primary);
            background: white;
        }

        /* UTILITY */
        .d-none {
            display: none !important;
        }

        .text-muted {
            color: var(--gray) !important;
            font-size: 0.9rem;
        }

        .mb-3 {
            margin-bottom: 1rem !important;
        }

        .mt-3 {
            margin-top: 1rem !important;
        }

        /* RESPONSIVE */
        @media (min-width: 768px) {
            .main-content {
                padding: 24px;
            }
            
            .fab-container {
                bottom: 24px;
                right: 24px;
            }
            
            .element-modal {
                left: 50%;
                right: auto;
                bottom: auto;
                top: 50%;
                transform: translate(-50%, -50%);
                border-radius: var(--radius);
                max-width: 500px;
                width: 90%;
                max-height: 70vh;
            }
        }

        @media (max-width: 576px) {
            .element-grid {
                grid-template-columns: 1fr;
            }
            
            .element-footer .btn-action span {
                display: inline;
            }
            
            .btn-action {
                padding: 12px 16px;
            }
            
            .tabs-container {
                padding: 6px;
            }
            
            .tab {
                min-width: 90px;
                padding: 10px 12px;
                font-size: 0.85rem;
            }
        }

        /* TOUCH FRIENDLY FIXES */
        input, textarea, select, button {
            font-size: 16px !important; /* Prevents iOS zoom */
            -webkit-appearance: none;
            appearance: none;
        }

        /* LONG PRESS PREVENTION */
        * {
            -webkit-touch-callout: none;
            -webkit-user-select: none;
            user-select: none;
        }

        input, textarea {
            -webkit-user-select: text;
            user-select: text;
        }

        /* BETTER SCROLLING */
        .element-settings, .faq-items, .element-modal {
            overscroll-behavior: contain;
        }
    </style>
</head>
<body>

<!-- Header -->
<div class="app-header">
    <div class="page-title">
        <i class="fas fa-edit"></i>
        <span id="pageTitle"><?= htmlspecialchars($page['title'] ?? 'Halaman Baru') ?></span>
    </div>
    
    <div class="header-actions">
        <?php if($page): ?>
            <div class="status-badge <?= ($page['status'] === 'published') ? 'published' : '' ?>">
                <?= ucfirst($page['status'] ?? 'draft') ?>
            </div>
            <a href="../preview.php?id=<?= $page_id ?>" target="_blank" class="btn btn-sm btn-outline-primary d-none d-sm-inline-flex align-items-center gap-1">
                <i class="fas fa-eye"></i>
                <span>Preview</span>
            </a>
            <a href="../preview.php?id=<?= $page_id ?>" target="_blank" class="btn btn-sm btn-outline-primary d-sm-none">
                <i class="fas fa-eye"></i>
            </a>
        <?php endif; ?>
    </div>
</div>

<!-- Main Content -->
<div class="main-content">
    
    <!-- Tabs -->
    <div class="tabs-container">
        <button class="tab active" data-tab="content">
            <i class="fas fa-th-large"></i>
            <span>Konten</span>
        </button>
        <button class="tab" data-tab="settings">
            <i class="fas fa-cog"></i>
            <span>Pengaturan</span>
        </button>
        <button class="tab" data-tab="tracking">
            <i class="fas fa-chart-line"></i>
            <span>Tracking</span>
        </button>
    </div>

    <!-- Tab Content -->
    <div class="tab-content">
        <!-- Content Tab -->
        <div class="tab-pane active" id="content-tab">
            <div class="content-area">
                <div id="canvasElements">
                    <?php if (empty($elements)): ?>
                        <div class="empty-state">
                            <div class="empty-state-icon">
                                <i class="fas fa-layer-group"></i>
                            </div>
                            <h4>Belum ada elemen</h4>
                            <p>Klik tombol + di bawah untuk menambahkan elemen pertama Anda</p>
                        </div>
                    <?php else:
                        foreach ($elements as $idx => $el) {
                            $st = json_decode($el['styles'], true) ?: [];
                            echo renderElementUI($el['type'], $idx, $el['content'], $st);
                        }
                    endif; ?>
                </div>
            </div>
        </div>
        
        <!-- Settings Tab -->
        <div class="tab-pane" id="settings-tab">
            <div class="settings-tab">
                <h5 class="section-title">
                    <i class="fas fa-sliders-h"></i> Pengaturan Halaman
                </h5>
                
                <div class="form-group">
                    <label class="form-label">Judul Halaman</label>
                    <input type="text" id="pageTitleInput" class="form-control" 
                           value="<?= htmlspecialchars($page['title'] ?? '') ?>" 
                           placeholder="Masukkan judul halaman" required>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Slug URL</label>
                    <div class="input-group">
                        <span class="input-group-text bg-light">/</span>
                        <input type="text" id="pageSlugInput" class="form-control" 
                               value="<?= htmlspecialchars($page['slug'] ?? '') ?>" 
                               placeholder="contoh-halaman">
                    </div>
                    <small class="text-muted mt-1 d-block">URL halaman: website.com/<strong>slug-url</strong></small>
                </div>
            </div>
        </div>
        
        <!-- Tracking Tab -->
        <div class="tab-pane" id="tracking-tab">
            <div class="settings-tab">
                <h5 class="section-title">
                    <i class="fab fa-facebook"></i> Meta Pixel
                </h5>
                
                <div class="form-group">
                    <label class="form-label">Pixel ID</label>
                    <input type="text" id="pixelIdInput" class="form-control" 
                           value="<?= htmlspecialchars($page['meta_pixel_id'] ?? '') ?>" 
                           placeholder="123456789012345">
                </div>
                
                <div class="form-group">
                    <label class="form-label">Nama Event</label>
                    <input type="text" id="eventNameInput" class="form-control" 
                           value="<?= htmlspecialchars($page['meta_event_name'] ?? 'ViewContent') ?>"
                           placeholder="ViewContent">
                </div>
                
                <hr class="my-4">
                
                <h5 class="section-title">
                    <i class="fas fa-code"></i> Conversions API
                </h5>
                
                <div class="form-group">
                    <label class="form-label">Access Token</label>
                    <input type="text" id="capiTokenInput" class="form-control" 
                           value="<?= htmlspecialchars($page['capi_access_token'] ?? '') ?>"
                           placeholder="EAA...">
                </div>
                
                <div class="form-group">
                    <label class="form-label">Endpoint URL</label>
                    <input type="text" id="capiEndpointInput" class="form-control" 
                           value="<?= htmlspecialchars($page['capi_endpoint'] ?? '') ?>"
                           placeholder="https://graph.facebook.com/...">
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Add Element FAB -->
<div class="fab-container">
    <button class="fab-btn" onclick="showElementModal()">
        <i class="fas fa-plus"></i>
    </button>
</div>

<!-- Element Modal - Hidden by default -->
<div class="modal-overlay" id="elementModalOverlay"></div>
<div class="element-modal" id="elementModal">
    <div class="modal-header">
        <h3 class="modal-title">Pilih Komponen</h3>
        <button class="close-btn" onclick="hideElementModal()">
            <i class="fas fa-times"></i>
        </button>
    </div>
    
    <div class="element-grid">
        <?php 
        $elementOptions = [
            'paragraph' => ['icon' => 'fas fa-font', 'name' => 'Teks', 'desc' => 'Teks dengan editor lengkap'],
            'button' => ['icon' => 'fas fa-square', 'name' => 'Tombol', 'desc' => 'Tombol dengan link'],
            'image' => ['icon' => 'fas fa-image', 'name' => 'Gambar', 'desc' => 'Gambar dari URL'],
            'youtube' => ['icon' => 'fab fa-youtube', 'name' => 'Video', 'desc' => 'Embed YouTube video'],
            'divider' => ['icon' => 'fas fa-minus', 'name' => 'Pembatas', 'desc' => 'Garis pemisah'],
            'faq' => ['icon' => 'fas fa-question-circle', 'name' => 'FAQ', 'desc' => 'Tanya jawab'],
            'html' => ['icon' => 'fas fa-code', 'name' => 'HTML', 'desc' => 'Kode HTML kustom']
        ];
        
        foreach($elementOptions as $type => $option): ?>
            <div class="element-option" onclick="addElement('<?= $type ?>')">
                <i class="<?= $option['icon'] ?>"></i>
                <div class="element-name"><?= $option['name'] ?></div>
                <div class="element-desc"><?= $option['desc'] ?></div>
            </div>
        <?php endforeach; ?>
    </div>
</div>

<!-- Save Button -->
<div class="save-container">
    <button type="button" class="save-btn" onclick="savePage()" id="saveBtn">
        <i class="fas fa-save"></i>
        <span>SIMPAN PERUBAHAN</span>
    </button>
</div>

<!-- Notification -->
<div id="notification" class="notification d-none"></div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.quilljs.com/1.3.6/quill.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sortablejs@1.14.0/Sortable.min.js"></script>

<script>
// Configuration
let quillEditors = {};
let isSaving = false;

// Color palette untuk Quill editor
const fullColorPalette = [
    "#000000", "#ffffff", "#e60000", "#ff9900", "#ffff00", "#008a00", "#0066cc", 
    "#9933ff", "#ffffff", "#facccc", "#ffebcc", "#ffffcc", "#cce8cc", "#cce0f5", 
    "#ebd6ff", "#bbbbbb", "#f06666", "#ffc266", "#ffff66", "#66b966", "#66a3e0", 
    "#c285ff", "#888888", "#a10000", "#b26b00", "#b2b200", "#006100", "#0047b2", 
    "#6b24b2", "#444444", "#5c0000", "#663d00", "#666600", "#003700", "#002966", 
    "#3d1466"
];

// Element Data
const elementData = {
    'paragraph': {icon: 'fas fa-font', color: '#3b82f6', name: 'Teks'},
    'button': {icon: 'fas fa-square', color: '#10b981', name: 'Tombol'},
    'image': {icon: 'fas fa-image', color: '#f59e0b', name: 'Gambar'},
    'youtube': {icon: 'fab fa-youtube', color: '#ef4444', name: 'Video'},
    'divider': {icon: 'fas fa-minus', color: '#6b7280', name: 'Pembatas'},
    'faq': {icon: 'fas fa-question-circle', color: '#8b5cf6', name: 'FAQ'},
    'html': {icon: 'fas fa-code', color: '#ec4899', name: 'HTML'}
};

// Tab Switching
document.querySelectorAll('.tab').forEach(btn => {
    btn.addEventListener('click', function(e) {
        e.stopPropagation();
        // Update active tab button
        document.querySelectorAll('.tab').forEach(b => b.classList.remove('active'));
        this.classList.add('active');
        
        // Show corresponding tab content
        const tabName = this.dataset.tab;
        document.querySelectorAll('.tab-pane').forEach(pane => pane.classList.remove('active'));
        document.getElementById(tabName + '-tab').classList.add('active');
    });
});

// Element Modal
function showElementModal() {
    document.getElementById('elementModalOverlay').classList.add('show');
    document.getElementById('elementModal').style.display = 'block';
}

function hideElementModal() {
    document.getElementById('elementModalOverlay').classList.remove('show');
    document.getElementById('elementModal').style.display = 'none';
}

// Add Element
async function addElement(type) {
    hideElementModal();
    
    try {
        const response = await fetch('', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: `add_element=true&element_type=${type}`
        });
        
        const result = await response.json();
        
        if (result.success) {
            // Reload page to show new element
            window.location.reload();
        } else {
            showNotification('Gagal menambah elemen', 'error');
        }
    } catch (error) {
        showNotification('Terjadi kesalahan', 'error');
        console.error('Error:', error);
    }
}

// Toggle Element Settings - IMPROVED FOR MOBILE
function toggleElementSettings(idx, event) {
    if (event) event.stopPropagation();
    
    const settings = document.getElementById(`settings-${idx}`);
    const header = document.querySelector(`#el-target-${idx} .element-header`);
    const chevron = document.getElementById(`chevron-${idx}`);
    
    if (!settings) return;

    // Close others
    document.querySelectorAll('.element-settings.show').forEach(el => {
        if (el.id !== `settings-${idx}`) {
            el.classList.remove('show');
            const otherIdx = el.id.replace('settings-', '');
            document.querySelector(`#el-target-${otherIdx} .element-header`).classList.remove('active');
            const otherChev = document.getElementById(`chevron-${otherIdx}`);
            if(otherChev) otherChev.classList.remove('rotated');
        }
    });

    // Toggle current
    if (settings.classList.contains('show')) {
        settings.classList.remove('show');
        header.classList.remove('active');
        if(chevron) chevron.classList.remove('rotated');
    } else {
        // Load form if empty
        const formContainer = document.getElementById(`form-${idx}`);
        if(formContainer && formContainer.innerHTML.trim() === '') {
            loadSettingsForm(idx);
        }
        
        settings.classList.add('show');
        header.classList.add('active');
        if(chevron) chevron.classList.add('rotated');
        
        // Scroll into view on mobile
        if (window.innerWidth < 768) {
            setTimeout(() => {
                settings.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
            }, 100);
        }
    }
}

// Load Settings Form
function loadSettingsForm(idx) {
    const el = document.getElementById(`el-target-${idx}`);
    if(!el) return;

    const type = el.dataset.elementType;
    const content = el.querySelector('.in-content').value || '';
    const bg = el.querySelector('.in-bg').value || '#ffffff';
    const tx = el.querySelector('.in-tx').value || '#000000';
    const link = el.querySelector('.in-link').value || '#';

    let html = '';

    switch(type) {
        case 'paragraph':
            html = `
                <div class="form-group">
                    <label class="form-label">Isi Teks</label>
                    <div id="editor-${idx}"></div>
                </div>`;
            setTimeout(() => initQuill(idx, content), 100);
            break;
            
        case 'button':
            html = `
                <div class="form-group">
                    <label class="form-label">Teks Tombol</label>
                    <input type="text" class="form-control" value="${escapeHtml(content)}" 
                           oninput="updateHiddenVal(${idx}, '.in-content', this.value)" 
                           placeholder="Klik di sini">
                </div>
                <div class="form-group">
                    <label class="form-label">Link Tujuan</label>
                    <input type="text" class="form-control" value="${escapeHtml(link)}" 
                           oninput="updateHiddenVal(${idx}, '.in-link', this.value)" 
                           placeholder="https://...">
                </div>
                <div class="row">
                    <div class="col-6">
                        <div class="form-group">
                            <label class="form-label">Background</label>
                            <div class="color-picker">
                                <input type="color" value="${bg}" oninput="updateHiddenVal(${idx}, '.in-bg', this.value)">
                                <input type="text" class="form-control" value="${bg}" 
                                       oninput="updateHiddenVal(${idx}, '.in-bg', this.value)" style="flex:1;">
                            </div>
                        </div>
                    </div>
                    <div class="col-6">
                        <div class="form-group">
                            <label class="form-label">Warna Teks</label>
                            <div class="color-picker">
                                <input type="color" value="${tx}" oninput="updateHiddenVal(${idx}, '.in-tx', this.value)">
                                <input type="text" class="form-control" value="${tx}" 
                                       oninput="updateHiddenVal(${idx}, '.in-tx', this.value)" style="flex:1;">
                            </div>
                        </div>
                    </div>
                </div>`;
            break;
            
        case 'image':
            html = `
                <div class="form-group">
                    <label class="form-label">URL Gambar</label>
                    <input type="text" class="form-control" value="${escapeHtml(content)}" 
                           oninput="updateHiddenVal(${idx}, '.in-content', this.value)" 
                           placeholder="https://example.com/image.jpg">
                    <small class="text-muted">Masukkan URL lengkap gambar</small>
                </div>`;
            break;
            
        case 'youtube':
            html = `
                <div class="form-group">
                    <label class="form-label">YouTube Video ID</label>
                    <input type="text" class="form-control" value="${escapeHtml(content)}" 
                           oninput="updateHiddenVal(${idx}, '.in-content', this.value)" 
                           placeholder="dQw4w9WgXcQ">
                    <small class="text-muted">ID video dari URL YouTube</small>
                </div>`;
            break;
            
        case 'divider':
            const thick = el.querySelector('.in-thickness')?.value || 2;
            html = `
                <div class="form-group">
                    <label class="form-label">Ketebalan Garis</label>
                    <input type="range" class="form-control" min="1" max="10" value="${thick}" 
                           oninput="updateHiddenVal(${idx}, '.in-thickness', this.value)"
                           style="width: 100%; padding: 10px 0;">
                </div>
                <div class="form-group">
                    <label class="form-label">Warna Garis</label>
                    <div class="color-picker">
                        <input type="color" value="${tx}" oninput="updateHiddenVal(${idx}, '.in-tx', this.value)">
                        <input type="text" class="form-control" value="${tx}" 
                               oninput="updateHiddenVal(${idx}, '.in-tx', this.value)" style="flex:1;">
                    </div>
                </div>`;
            break;
            
        case 'faq':
            let faqData = [];
            try {
                faqData = JSON.parse(content || '[]');
            } catch(e) {
                faqData = [];
            }

            html = `
                <div class="form-group">
                    <label class="form-label">Daftar FAQ</label>
                    <div class="faq-items" id="faq-list-${idx}">
                        ${faqData.map((item, fIdx) => `
                            <div class="faq-item">
                                <input type="text" class="form-control mb-2 faq-q" 
                                       placeholder="Pertanyaan" value="${escapeHtml(item.q)}" 
                                       oninput="saveFaqData(${idx})">
                                <textarea class="form-control faq-a" rows="2" 
                                          placeholder="Jawaban" oninput="saveFaqData(${idx})">${escapeHtml(item.a)}</textarea>
                                <button type="button" class="btn btn-sm btn-outline-danger mt-2 w-100" 
                                        onclick="removeFaqItem(${idx}, ${fIdx})">
                                    <i class="fas fa-trash me-1"></i> Hapus
                                </button>
                            </div>
                        `).join('')}
                    </div>
                    <button type="button" class="add-faq-btn" onclick="addFaqItem(${idx})">
                        <i class="fas fa-plus"></i> Tambah FAQ
                    </button>
                </div>
                <div class="row">
                    <div class="col-6">
                        <div class="form-group">
                            <label class="form-label">Warna Teks</label>
                            <div class="color-picker">
                                <input type="color" value="${tx}" oninput="updateHiddenVal(${idx}, '.in-tx', this.value)">
                                <input type="text" class="form-control" value="${tx}" 
                                       oninput="updateHiddenVal(${idx}, '.in-tx', this.value)" style="flex:1;">
                            </div>
                        </div>
                    </div>
                    <div class="col-6">
                        <div class="form-group">
                            <label class="form-label">Background</label>
                            <div class="color-picker">
                                <input type="color" value="${bg}" oninput="updateHiddenVal(${idx}, '.in-bg', this.value)">
                                <input type="text" class="form-control" value="${bg}" 
                                       oninput="updateHiddenVal(${idx}, '.in-bg', this.value)" style="flex:1;">
                            </div>
                        </div>
                    </div>
                </div>`;
            break;
            
        case 'html':
            html = `
                <div class="form-group">
                    <label class="form-label">Kode HTML</label>
                    <textarea class="form-control" rows="4" 
                              oninput="updateHiddenVal(${idx}, '.in-content', this.value)"
                              placeholder="<div>Kode HTML Anda</div>">${escapeHtml(content)}</textarea>
                    <small class="text-muted">Masukkan kode HTML yang valid</small>
                </div>`;
            break;
    }

    document.getElementById(`form-${idx}`).innerHTML = html;
}

// Helper Functions
function escapeHtml(text) {
    if (!text) return "";
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

function updateHiddenVal(idx, selector, value) {
    const el = document.getElementById(`el-target-${idx}`);
    if (el) {
        const target = el.querySelector(selector);
        if (target) target.value = value;
    }
}

function initQuill(idx, content) {
    if (quillEditors[idx]) return;

    const editorEl = document.getElementById(`editor-${idx}`);
    if (!editorEl) return;

    const quill = new Quill(editorEl, {
        theme: 'snow',
        modules: {
            toolbar: [
                [{ 'header': [1, 2, 3, false] }],
                ['bold', 'italic', 'underline', 'strike'],
                [{ 'list': 'ordered'}, { 'list': 'bullet' }],
                ['blockquote'],
                [{ 'color': fullColorPalette }, { 'background': fullColorPalette }],
                [{ 'align': [] }],
                ['link', 'clean']
            ]
        }
    });

    if (content) {
        quill.clipboard.dangerouslyPasteHTML(content);
    } else {
        quill.setText('Ketik teks Anda di sini...');
    }

    quill.on('text-change', function() {
        updateHiddenVal(idx, '.in-content', quill.root.innerHTML);
    });

    quillEditors[idx] = quill;
}

// Element Operations - BETTER TOUCH HANDLING
function moveElement(currentIdx, direction) {
    event.stopPropagation();
    const wrapper = document.getElementById('canvasElements');
    const cards = Array.from(document.querySelectorAll('.element-card'));
    const targetIdx = currentIdx + direction;

    if (targetIdx < 0 || targetIdx >= cards.length) return;

    const currentCard = document.getElementById(`el-target-${currentIdx}`);
    const targetCard = document.getElementById(`el-target-${targetIdx}`);

    if (direction === -1) {
        wrapper.insertBefore(currentCard, targetCard);
    } else {
        wrapper.insertBefore(currentCard, targetCard.nextSibling);
    }

    updateAllIndices();
}

function deleteElement(idx) {
    event.stopPropagation();
    if (confirm('Hapus elemen ini?')) {
        document.getElementById(`el-target-${idx}`).remove();
        updateAllIndices();
        showNotification('Elemen berhasil dihapus', 'success');
        
        // Show empty state if no elements left
        const wrapper = document.getElementById('canvasElements');
        if (document.querySelectorAll('.element-card').length === 0) {
            const emptyState = document.createElement('div');
            emptyState.className = 'empty-state';
            emptyState.innerHTML = `
                <div class="empty-state-icon">
                    <i class="fas fa-layer-group"></i>
                </div>
                <h4>Belum ada elemen</h4>
                <p>Klik tombol + di bawah untuk menambahkan elemen pertama Anda</p>
            `;
            wrapper.appendChild(emptyState);
        }
    }
}

function duplicateElement(idx) {
    event.stopPropagation();
    const el = document.getElementById(`el-target-${idx}`);
    const type = el.dataset.elementType;
    addElement(type);
}

// Re-indexing
function updateAllIndices() {
    const cards = document.querySelectorAll('.element-card');
    quillEditors = {};

    cards.forEach((card, newIndex) => {
        card.id = `el-target-${newIndex}`;
        card.dataset.elementIndex = newIndex;

        const number = card.querySelector('.element-number');
        if (number) number.textContent = `#${newIndex + 1}`;

        const header = card.querySelector('.element-header');
        if (header) {
            header.onclick = (e) => toggleElementSettings(newIndex, e);
        }

        const actions = card.querySelectorAll('.btn-icon');
        if (actions[0]) actions[0].onclick = (e) => {
            e.stopPropagation();
            moveElement(newIndex, -1);
        };
        if (actions[1]) actions[1].onclick = (e) => {
            e.stopPropagation();
            moveElement(newIndex, 1);
        };

        const footerBtns = card.querySelectorAll('.btn-action');
        if (footerBtns[0]) footerBtns[0].onclick = (e) => {
            e.stopPropagation();
            duplicateElement(newIndex);
        };
        if (footerBtns[1]) footerBtns[1].onclick = (e) => {
            e.stopPropagation();
            deleteElement(newIndex);
        };

        const settings = card.querySelector('.element-settings');
        if (settings) {
            settings.id = `settings-${newIndex}`;
            settings.classList.remove('show');
        }

        const formDiv = card.querySelector('.settings-content');
        if (formDiv) {
            formDiv.id = `form-${newIndex}`;
            formDiv.innerHTML = '';
        }

        const chevron = card.querySelector('.fa-chevron-down');
        if (chevron) {
            chevron.id = `chevron-${newIndex}`;
            chevron.classList.remove('rotated');
        }

        const inputs = card.querySelectorAll('input, textarea, select');
        inputs.forEach(input => {
            const oldName = input.getAttribute('name');
            if (oldName) {
                const newName = oldName.replace(/elements\[\d+\]/, `elements[${newIndex}]`);
                input.setAttribute('name', newName);
            }
        });
    });
}

// FAQ Functions
function addFaqItem(idx) {
    event.stopPropagation();
    const list = document.getElementById(`faq-list-${idx}`);
    const newItem = document.createElement('div');
    newItem.className = 'faq-item';
    newItem.innerHTML = `
        <input type="text" class="form-control mb-2 faq-q" placeholder="Pertanyaan" oninput="saveFaqData(${idx})">
        <textarea class="form-control faq-a" rows="2" placeholder="Jawaban" oninput="saveFaqData(${idx})"></textarea>
        <button type="button" class="btn btn-sm btn-outline-danger mt-2 w-100" onclick="event.stopPropagation(); this.parentElement.remove(); saveFaqData(${idx})">
            <i class="fas fa-trash me-1"></i> Hapus
        </button>
    `;
    list.appendChild(newItem);
    saveFaqData(idx);
    
    // Focus on new input
    setTimeout(() => {
        newItem.querySelector('.faq-q').focus();
    }, 100);
}

function removeFaqItem(idx, fIdx) {
    event.stopPropagation();
    const list = document.getElementById(`faq-list-${idx}`);
    if (list.children[fIdx]) {
        list.children[fIdx].remove();
        saveFaqData(idx);
    }
}

function saveFaqData(idx) {
    const list = document.getElementById(`faq-list-${idx}`);
    const items = [];
    
    list.querySelectorAll('.faq-item').forEach(card => {
        const q = card.querySelector('.faq-q').value;
        const a = card.querySelector('.faq-a').value;
        if (q.trim() || a.trim()) {
            items.push({ q: q, a: a });
        }
    });
    
    updateHiddenVal(idx, '.in-content', JSON.stringify(items));
}

// Save Page using save_page.php
async function savePage() {
    if (isSaving) return;
    
    const saveBtn = document.getElementById('saveBtn');
    const originalHTML = saveBtn.innerHTML;
    
    // Validation
    const titleInput = document.getElementById('pageTitleInput');
    if (!titleInput.value.trim()) {
        showNotification('Silakan isi judul halaman', 'error');
        titleInput.focus();
        return;
    }
    
    // Show loading
    saveBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> MENYIMPAN...';
    saveBtn.classList.add('loading');
    isSaving = true;
    
    try {
        // Collect settings data
        const settings = {
            title: titleInput.value,
            slug: document.getElementById('pageSlugInput').value,
            pixel_id: document.getElementById('pixelIdInput').value,
            event_name: document.getElementById('eventNameInput').value,
            capi_token: document.getElementById('capiTokenInput').value,
            capi_endpoint: document.getElementById('capiEndpointInput').value
        };
        
        // Collect elements data
        const elements = [];
        document.querySelectorAll('.element-card').forEach((card, index) => {
            const type = card.dataset.elementType;
            const content = card.querySelector('.in-content').value || '';
            
            const styles = {
                bg_color: card.querySelector('.in-bg')?.value || '#ffffff',
                text_color: card.querySelector('.in-tx')?.value || '#000000',
                link: card.querySelector('.in-link')?.value || '#'
            };
            
            if (type === 'divider') {
                styles.thickness = card.querySelector('.in-thickness')?.value || 2;
            }
            
            elements.push({
                type: type,
                content: content,
                styles: styles
            });
        });
        
        // Prepare data for save_page.php
        const saveData = {
            page_id: <?= json_encode($page_id) ?>,
            settings: settings,
            elements: elements
        };
        
        console.log('Saving data:', saveData);
        
        // Send to save_page.php
        const response = await fetch('save_page.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify(saveData)
        });
        
        const result = await response.json();
        
        if (result.success) {
            showNotification('Halaman berhasil disimpan!', 'success');
            // Update page title in header
            document.getElementById('pageTitle').textContent = titleInput.value;
            
            // Update status badge if exists
            const statusBadge = document.querySelector('.status-badge');
            if (statusBadge) {
                statusBadge.textContent = 'Published';
                statusBadge.classList.add('published');
            }
        } else {
            showNotification('Gagal menyimpan: ' + (result.message || 'Terjadi kesalahan'), 'error');
        }
    } catch (error) {
        console.error('Error:', error);
        showNotification('Terjadi kesalahan jaringan', 'error');
    } finally {
        // Reset button
        saveBtn.innerHTML = originalHTML;
        saveBtn.classList.remove('loading');
        isSaving = false;
    }
}

// Show Notification
function showNotification(message, type = 'success') {
    const notification = document.getElementById('notification');
    notification.className = `notification ${type}`;
    notification.innerHTML = `
        <i class="fas fa-${type === 'success' ? 'check-circle' : 'exclamation-triangle'}"></i>
        <span>${message}</span>
    `;
    notification.classList.remove('d-none');
    
    setTimeout(() => {
        notification.classList.add('d-none');
    }, 3000);
}

// Initialize
document.addEventListener('DOMContentLoaded', function() {
    // Initialize drag and drop with better touch handling
    const canvas = document.getElementById('canvasElements');
    if (canvas) {
        Sortable.create(canvas, {
            animation: 150,
            handle: '.element-header',
            filter: '.btn-icon, .btn-action',
            preventOnFilter: false,
            onEnd: function() {
                updateAllIndices();
            }
        });
    }
    
    // Auto-open first element
    const firstElement = document.querySelector('.element-card');
    if (firstElement) {
        const idx = firstElement.dataset.elementIndex;
        setTimeout(() => toggleElementSettings(idx), 500);
    }
    
    // Prevent zoom on input focus (iOS)
    const viewportMeta = document.querySelector('meta[name="viewport"]');
    const originalContent = viewportMeta.content;
    
    document.querySelectorAll('input, textarea').forEach(input => {
        input.addEventListener('focus', () => {
            viewportMeta.content = 'width=device-width, initial-scale=1.0, maximum-scale=1.0';
        });
        
        input.addEventListener('blur', () => {
            viewportMeta.content = originalContent;
        });
    });
    
    // Close modal on escape
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            hideElementModal();
        }
    });
    
    // Close modal when clicking outside on desktop
    document.getElementById('elementModalOverlay').addEventListener('click', function(e) {
        if (e.target === this) {
            hideElementModal();
        }
    });
    
    // Update page title in real-time
    const titleInput = document.getElementById('pageTitleInput');
    if (titleInput) {
        titleInput.addEventListener('input', function() {
            document.getElementById('pageTitle').textContent = this.value || 'Halaman Baru';
        });
    }
    
    // Auto-generate slug from title
    if (titleInput) {
        const slugInput = document.getElementById('pageSlugInput');
        if (slugInput && !slugInput.value) {
            titleInput.addEventListener('blur', function() {
                if (!slugInput.value) {
                    const slug = this.value
                        .toLowerCase()
                        .replace(/[^\w\s]/gi, '')
                        .replace(/\s+/g, '-')
                        .replace(/--+/g, '-');
                    slugInput.value = slug;
                }
            });
        }
    }
    
    // Better touch handling for mobile
    if ('ontouchstart' in window) {
        // Add touch feedback
        document.querySelectorAll('.element-header, .tab, .btn-action, .element-option').forEach(el => {
            el.addEventListener('touchstart', function() {
                this.style.opacity = '0.7';
            });
            el.addEventListener('touchend', function() {
                this.style.opacity = '1';
            });
        });
        
        // Prevent long press context menu
        document.addEventListener('contextmenu', function(e) {
            e.preventDefault();
            return false;
        });
    }
});
</script>
</body>
</html>