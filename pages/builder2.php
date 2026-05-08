<?php
// Pastikan path ini sesuai dengan struktur folder Anda
require_once '../includes/config.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';

// Cek Login
if (function_exists('requireLogin')) {
    requireLogin();
}
// Ambil User
$user = function_exists('getCurrentUser') ? getCurrentUser($pdo) : ['id' => 1]; // Fallback jika fungsi tidak ada

$page_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$error = ''; 
$success = '';

// --- LOGIKA SIMPAN (POST) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $title = trim($_POST['title']);
    $slug  = trim($_POST['slug']);
    $pixel_id = trim($_POST['pixel_id']);
    $capi_endpoint = trim($_POST['capi_endpoint']);
    $capi_token = trim($_POST['capi_access_token']);
    $event_name = trim($_POST['meta_event_name']);

    try {
        $pdo->beginTransaction();
        
        // 1. Simpan/Update Data Halaman Utama
        if ($page_id > 0) {
            $stmt = $pdo->prepare("UPDATE landing_pages SET title=?, slug=?, meta_pixel_id=?, capi_endpoint=?, capi_access_token=?, meta_event_name=? WHERE id=? AND user_id=?");
            $stmt->execute([$title, $slug, $pixel_id, $capi_endpoint, $capi_token, $event_name, $page_id, $user['id']]);
        } else {
            $stmt = $pdo->prepare("INSERT INTO landing_pages (user_id, title, slug, meta_pixel_id, capi_endpoint, capi_access_token, meta_event_name, status) VALUES (?,?,?,?,?,?,?,'draft')");
            $stmt->execute([$user['id'], $title, $slug, $pixel_id, $capi_endpoint, $capi_token, $event_name]);
            $page_id = $pdo->lastInsertId();
        }

        // 2. Simpan Elemen (Hapus lama, Insert baru)
        $pdo->prepare("DELETE FROM page_elements WHERE page_id=?")->execute([$page_id]);
        
        if (isset($_POST['elements']) && is_array($_POST['elements'])) {
            // Re-index array keys untuk memastikan urutan 0,1,2...
            $elements_data = array_values($_POST['elements']);
            
            foreach ($elements_data as $idx => $el) {
                // Pastikan styles valid JSON
                $styles = isset($el['styles']) ? $el['styles'] : [];
                $stmt = $pdo->prepare("INSERT INTO page_elements (page_id, type, content, order_position, styles) VALUES (?,?,?,?,?)");
                $stmt->execute([$page_id, $el['type'], $el['content'], $idx, json_encode($styles)]);
            }
        }
        
        $pdo->commit();
        if (isset($_SESSION['temp_new_element'])) unset($_SESSION['temp_new_element']);
        
        header("Location: builder2.php?id=$page_id&saved=1"); 
        exit;
    } catch (Exception $e) { 
        $pdo->rollBack(); 
        $error = $e->getMessage(); 
    }
}

// --- AMBIL DATA HALAMAN ---
$page = null;
$elements = [];

if ($page_id > 0) {
    // Gunakan fungsi getUserLandingPage jika ada, atau query manual
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

// --- FUNGSI RENDER UI (PHP Helper) ---
function renderElementUI($type, $idx, $content, $st) {
    $bg = $st['bg_color'] ?? '#ffffff';
    $tx = $st['text_color'] ?? '#000000';
    $link = $st['link'] ?? '#';
    
    $icons = [
        'paragraph' => 'fas fa-font',
        'button' => 'fas fa-square',
        'image' => 'fas fa-image',
        'youtube' => 'fab fa-youtube',
        'divider' => 'fas fa-minus',
        'faq' => 'fas fa-question-circle',
        'html' => 'fas fa-code'
    ];
    $icon = $icons[$type] ?? 'fas fa-cube';

    $html = '<div class="element-card" id="el-target-'.$idx.'" data-element-index="'.$idx.'" data-element-type="'.$type.'">';

    // --- CARD HEADER ---
    $html .= '<div class="element-card-header" onclick="toggleElementSettings('.$idx.', event)">';
        $html .= '<div class="element-card-icon"><i class="'.$icon.'"></i></div>';
        $html .= '<div class="element-card-info">';
            $html .= '<div class="element-card-title">'.ucwords($type).'</div>';
            $html .= '<div class="element-card-subtitle">#'.($idx+1).'</div>';
        $html .= '</div>';
        $html .= '<div class="element-card-actions">';
            $html .= '<div class="btn-group">';
                $html .= '<button type="button" class="btn btn-action move-up" onclick="event.stopPropagation(); moveElement('.$idx.', -1)"><i class="fas fa-arrow-up"></i></button>';
                $html .= '<button type="button" class="btn btn-action move-down" onclick="event.stopPropagation(); moveElement('.$idx.', 1)"><i class="fas fa-arrow-down"></i></button>';
            $html .= '</div>';
            $html .= '<div class="toggle-indicator"><i class="fas fa-chevron-down" id="chevron-'.$idx.'"></i></div>';
        $html .= '</div>';
    $html .= '</div>';

    // --- CARD BODY / SETTINGS ---
    $html .= '<div class="element-settings" id="settings-'.$idx.'">';
        $html .= '<input type="hidden" name="elements['.$idx.'][type]" value="'.$type.'">';
        $html .= '<input type="hidden" name="elements['.$idx.'][styles][bg_color]" class="in-bg" value="'.$bg.'">';
        $html .= '<input type="hidden" name="elements['.$idx.'][styles][text_color]" class="in-tx" value="'.$tx.'">';
        $html .= '<input type="hidden" name="elements['.$idx.'][styles][link]" class="in-link" value="'.$link.'">';
        
        if ($type === 'divider') {
            $thickness = $st['thickness'] ?? 2;
            $style = $st['divider_style'] ?? 'solid';
            $margin = $st['margin'] ?? 20;
            $html .= '<input type="hidden" name="elements['.$idx.'][styles][thickness]" class="in-thickness" value="'.$thickness.'">';
            $html .= '<input type="hidden" name="elements['.$idx.'][styles][divider_style]" class="in-divider-style" value="'.$style.'">';
            $html .= '<input type="hidden" name="elements['.$idx.'][styles][margin]" class="in-margin" value="'.$margin.'">';
        }

        $html .= '<textarea name="elements['.$idx.'][content]" class="d-none in-content">'.htmlspecialchars($content).'</textarea>';
        $html .= '<div class="settings-form" id="form-'.$idx.'"></div>';

        $html .= '<div class="element-actions">';
            $html .= '<button type="button" class="btn btn-duplicate" onclick="duplicateElement('.$idx.')"><i class="fas fa-copy"></i> Duplikat</button>';
            $html .= '<button type="button" class="btn btn-delete" onclick="deleteElement('.$idx.')"><i class="fas fa-trash"></i> Hapus</button>';
        $html .= '</div>';
    $html .= '</div>';

    // ✅ --- BUTTON TAMBAH DI BAWAH ---
    $html .= '<button type="button" class="element-add-btn" onclick="event.stopPropagation(); openAddElementModal('.$idx.')">';
        $html .= '<i class="fas fa-plus"></i>';
        $html .= '<span>Tambah Element di Bawah</span>';
        $html .= '<span class="tooltip-text">Tambah element baru di bawah ini</span>';
    $html .= '</button>';

    $html .= '</div>';

    return $html;
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0, user-scalable=yes">
    <title>LP Builder Pro - <?= $page_id ?></title>
    
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css" rel="stylesheet">
    <link href="https://cdn.quilljs.com/1.3.6/quill.snow.css" rel="stylesheet">
    
    <style>
        :root {
            --primary: #6366f1;
            --primary-dark: #4f46e5;
            --secondary: #8b5cf6;
            --success: #10b981;
            --warning: #f59e0b;
            --danger: #ef4444;
            --gray-100: #f9fafb;
            --gray-200: #e5e7eb;
            --gray-300: #d1d5db;
            --gray-400: #9ca3af;
            --gray-500: #6b7280;
            --gray-700: #374151;
            --header-height: 70px;
            --radius-sm: 8px;
            --radius-md: 12px;
            --radius-lg: 16px;
            --shadow-sm: 0 1px 2px rgba(0,0,0,0.05);
            --shadow-md: 0 4px 6px rgba(0,0,0,0.08);
            --shadow-lg: 0 10px 15px rgba(0,0,0,0.1);
            --transition: all 0.3s cubic-bezier(0.165, 0.84, 0.44, 1);
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            background: linear-gradient(135deg, #f0f4ff 0%, #e6e9ff 100%);
            color: var(--gray-700);
            font-family: 'Segoe UI', system-ui, -apple-system, sans-serif;
            padding-top: var(--header-height);
            padding-bottom: 100px;
            line-height: 1.6;
        }

        /* HEADER - Modern dengan gradient dan shadow */
        .builder-header {
            height: var(--header-height);
            background: linear-gradient(120deg, var(--primary) 0%, var(--secondary) 100%);
            box-shadow: var(--shadow-lg);
            display: flex;
            align-items: center;
            padding: 0 2.5rem; /* ✅ Tambah padding horizontal */
            position: fixed;
            top: 0; left: 0; right: 0;
            width: 100%;
            z-index: 1030;
            color: white;
        }
		
		.header-actions {
			margin-left: auto;
			display: flex;
			gap: 10px;
			margin-right: 1rem; /* ✅ Tambah sedikit margin lagi */
		}

        .brand {
            font-weight: 800;
            font-size: 1.5rem;
            display: flex;
            align-items: center;
            gap: 0.75rem;
            letter-spacing: -0.5px;
            text-shadow: 0 1px 3px rgba(0,0,0,0.2);
        }
        .brand i { font-size: 1.8rem; }

        .header-actions .btn {
            background: rgba(255,255,255,0.15);
            border: 1px solid rgba(255,255,255,0.3);
            color: white;
            padding: 0.4rem 1rem;
            border-radius: 50px;
            transition: var(--transition);
            font-weight: 500;
        }
        .header-actions .btn:hover {
            background: rgba(255,255,255,0.25);
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
        }

        /* TABS - Modern dengan indicator animasi */
        .builder-tabs-container {
            position: sticky;
            top: var(--header-height);
            background: white;
            z-index: 1020;
            padding: 0.75rem 2rem;
            box-shadow: var(--shadow-md);
            border-bottom: 1px solid var(--gray-200);
        }
        
        .nav-tabs {
            border: none;
            margin-bottom: 0;
        }
        
        .nav-tabs .nav-link {
            border: none;
            color: var(--gray-500);
            font-weight: 600;
            padding: 0.75rem 1.25rem;
            margin-right: 0.5rem;
            border-radius: var(--radius-sm);
            transition: var(--transition);
            display: flex;
            align-items: center;
            gap: 0.5rem;
            position: relative;
        }
        
        .nav-tabs .nav-link::after {
            content: '';
            position: absolute;
            bottom: -2px;
            left: 50%;
            width: 0;
            height: 3px;
            background: var(--primary);
            border-radius: 2px;
            transition: var(--transition);
            transform: translateX(-50%);
        }
        
        .nav-tabs .nav-link:hover {
            color: var(--primary-dark);
            background: var(--gray-100);
        }
        
        .nav-tabs .nav-link.active {
            color: var(--primary-dark);
            background: rgba(99, 102, 241, 0.08);
        }
        
        .nav-tabs .nav-link.active::after {
            width: 70%;
        }

        /* ELEMENT CARD - Modern card design */
        .element-card {
            background: white;
            border-radius: var(--radius-md);
            box-shadow: var(--shadow-md);
            margin-bottom: 1.25rem;
            border: 1px solid var(--gray-200);
            overflow: hidden;
            transition: var(--transition);
            position: relative;
        }
        
        .element-card:hover {
            box-shadow: var(--shadow-lg);
            transform: translateY(-2px);
        }
        
        .element-card-header {
            padding: 1.25rem 1.5rem;
            display: flex;
            align-items: center;
            cursor: pointer;
            background: white;
            transition: var(--transition);
            border-bottom: 1px solid var(--gray-200);
        }
        
        .element-card-header:hover {
            background: var(--gray-50);
        }
        
        .element-card-icon {
            width: 48px;
            height: 48px;
            border-radius: 12px;
            background: linear-gradient(135deg, var(--primary) 0%, var(--secondary) 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            margin-right: 1.25rem;
            flex-shrink: 0;
            box-shadow: 0 4px 10px rgba(99, 102, 241, 0.25);
        }
        
        .element-card-icon i {
            color: white;
            font-size: 1.4rem;
        }
        
        /* Icon colors untuk berbagai tipe elemen */
        [data-element-type="button"] .element-card-icon { background: linear-gradient(135deg, #ef4444, #f97316); }
        [data-element-type="image"] .element-card-icon { background: linear-gradient(135deg, #10b981, #0ea5e9); }
        [data-element-type="youtube"] .element-card-icon { background: linear-gradient(135deg, #ef4444, #b91c1c); }
        [data-element-type="divider"] .element-card-icon { background: linear-gradient(135deg, #8b5cf6, #a78bfa); }
        [data-element-type="faq"] .element-card-icon { background: linear-gradient(135deg, #f59e0b, #eab308); }
        [data-element-type="html"] .element-card-icon { background: linear-gradient(135deg, #6b7280, #4b5563); }
        
        .element-card-info { 
            flex: 1; 
            min-width: 0;
        }
        
        .element-card-title {
            font-weight: 700;
            font-size: 1.05rem;
            color: var(--gray-800);
            margin-bottom: 0.25rem;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        
        .element-card-subtitle {
            font-size: 0.85rem;
            color: var(--gray-500);
            font-weight: 500;
        }
        
        .element-card-actions {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            margin-left: 1rem;
        }
        
        .btn-group {
            display: flex;
            gap: 0.25rem;
        }
        
        .btn-action {
            width: 36px;
            height: 36px;
            border: 1px solid var(--gray-300);
            background: white;
            border-radius: 8px;
            color: var(--gray-600);
            padding: 0;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: var(--transition);
            font-size: 0.9rem;
        }
        
        .btn-action:hover {
            background: var(--primary);
            border-color: var(--primary);
            color: white;
            transform: scale(1.1);
        }
        
        .toggle-indicator {
            width: 28px;
            height: 28px;
            border-radius: 8px;
            background: var(--gray-100);
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--gray-500);
            transition: var(--transition);
        }
        
        .toggle-indicator.rotated {
            transform: rotate(180deg);
            background: var(--primary);
            color: white;
        }

        /* SETTINGS AREA - Smooth animations */
        .element-settings {
            display: none;
            background: var(--gray-50);
            padding: 1.75rem;
            border-top: 1px solid var(--gray-200);
            animation: slideDown 0.3s ease-out;
        }
        
        @keyframes slideDown {
            from { opacity: 0; transform: translateY(-10px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        .element-settings.show {
            display: block;
            animation: slideUp 0.3s ease-out;
        }
        
        @keyframes slideUp {
            from { opacity: 0; transform: translateY(10px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        .settings-form {
            margin-bottom: 1.5rem;
        }
        
        .element-actions {
            display: flex;
            gap: 0.75rem;
            margin-top: 1.5rem;
            padding-top: 1.25rem;
            border-top: 1px solid var(--gray-200);
        }
        
        .btn-duplicate {
            flex: 1;
            padding: 0.75rem;
            background: white;
            border: 1px solid var(--gray-300);
            border-radius: var(--radius-sm);
            color: var(--gray-700);
            font-weight: 500;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            transition: var(--transition);
        }
        
        .btn-duplicate:hover {
            background: var(--gray-100);
            border-color: var(--gray-400);
            transform: translateY(-1px);
        }
        
        .btn-delete {
            flex: 1;
            padding: 0.75rem;
            background: var(--danger);
            border: none;
            border-radius: var(--radius-sm);
            color: white;
            font-weight: 500;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            transition: var(--transition);
        }
        
        .btn-delete:hover {
            background: #dc2626;
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(239, 68, 68, 0.3);
        }

        /* BUTTON ADD ELEMENTS - Modern card style */
        .add-elements-container {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(150px, 1fr));
            gap: 1rem;
            margin: 1.5rem 0 2rem;
        }
        
        .btn-add-element {
            padding: 1.25rem 0.75rem;
            background: white;
            border: 2px dashed var(--gray-300);
            border-radius: var(--radius-md);
            text-align: center;
            text-decoration: none;
            color: var(--gray-700);
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            gap: 0.75rem;
            transition: var(--transition);
            font-weight: 500;
            min-height: 100px;
            box-shadow: var(--shadow-sm);
        }
        
        .btn-add-element:hover {
            border-color: var(--primary);
            background: rgba(99, 102, 241, 0.05);
            transform: translateY(-3px);
            box-shadow: var(--shadow-md);
            color: var(--primary-dark);
        }
        
        .btn-add-element i {
            font-size: 1.75rem;
            color: var(--primary);
        }
        
        .btn-add-element:hover i {
            transform: scale(1.15);
        }

        /* EMPTY STATE */
        .empty-state {
            text-align: center;
            padding: 3rem 2rem;
            border: 2px dashed var(--gray-300);
            border-radius: var(--radius-lg);
            background: white;
            margin: 2rem 0;
            box-shadow: var(--shadow-sm);
        }
        
        .empty-state i {
            font-size: 4rem;
            color: var(--gray-400);
            margin-bottom: 1.5rem;
            opacity: 0.7;
        }
        
        .empty-state p {
            font-size: 1.1rem;
            color: var(--gray-600);
            max-width: 400px;
            margin: 0 auto;
        }

        /* FORM CONTROLS - Enhanced styling */
        .form-label {
            font-weight: 600;
            margin-bottom: 0.5rem;
            color: var(--gray-800);
            display: block;
        }
        
        .form-control, 
        .form-select,
        .form-control-color {
            border: 1px solid var(--gray-300);
            padding: 0.75rem 1rem;
            border-radius: var(--radius-sm);
            transition: var(--transition);
            font-size: 1rem;
        }
        
        .form-control:focus, 
        .form-select:focus,
        .form-control-color:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(99, 102, 241, 0.15);
            outline: none;
        }
        
        .form-range {
            height: 1.5rem;
        }
        
        .input-group-text {
            background: var(--gray-50);
            border-color: var(--gray-300);
            font-weight: 500;
        }

        /* QUILL EDITOR - Custom styling */
        .ql-container.ql-snow {
            border: 1px solid var(--gray-300);
            border-radius: 0 0 var(--radius-sm) var(--radius-sm);
        }
        
        .ql-toolbar.ql-snow {
            border: 1px solid var(--gray-300);
            border-bottom: none;
            border-radius: var(--radius-sm) var(--radius-sm) 0 0;
            background: white;
        }
        
        .ql-editor {
            min-height: 200px;
            font-size: 1.05rem;
            line-height: 1.7;
        }
        
        .ql-editor.ql-blank::before {
            color: var(--gray-400);
            font-style: italic;
            font-weight: 400;
        }

        /* SAVE BUTTON - Floating action button style */
        .save-btn-container {
            position: fixed;
            bottom: 2rem;
            left: 50%;
            transform: translateX(-50%);
            z-index: 1040;
            width: 90%;
            max-width: 500px;
            box-shadow: var(--shadow-lg);
        }
        
        .save-btn {
            width: 100%;
            padding: 1.25rem 2rem;
            background: linear-gradient(120deg, var(--primary) 0%, var(--primary-dark) 100%);
            color: white;
            border: none;
            border-radius: 50px;
            font-weight: 700;
            font-size: 1.1rem;
            letter-spacing: 0.5px;
            box-shadow: 0 6px 20px rgba(99, 102, 241, 0.4);
            transition: var(--transition);
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.75rem;
        }
        
        .save-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(99, 102, 241, 0.55);
        }
        
        .save-btn:active {
            transform: translateY(0);
        }
        
        .save-btn:disabled {
            opacity: 0.85;
            transform: none;
            box-shadow: 0 4px 15px rgba(99, 102, 241, 0.3);
        }

        /* ALERTS - Enhanced styling */
        .alert {
            border-radius: var(--radius-md);
            border: none;
            padding: 1.25rem;
            margin-top: 1.5rem;
            box-shadow: var(--shadow-sm);
            font-weight: 500;
        }
        
        .alert-success {
            background: rgba(16, 185, 129, 0.1);
            color: #065f46;
        }
        
        .alert-danger {
            background: rgba(239, 68, 68, 0.1);
            color: #b91c1c;
        }
        
        .btn-close {
            width: 1.25rem;
            height: 1.25rem;
            opacity: 0.7;
        }

        /* FAQ ITEM STYLING */
        .faq-items-list .card {
            border: 1px solid var(--gray-200);
            border-radius: var(--radius-sm);
            margin-bottom: 0.75rem;
            transition: var(--transition);
        }
        
        .faq-items-list .card:hover {
            border-color: var(--primary);
            box-shadow: var(--shadow-sm);
        }
        
        .faq-q {
            font-weight: 600;
            border-bottom: 1px solid var(--gray-200);
            border-radius: var(--radius-sm) var(--radius-sm) 0 0 !important;
        }
        
        .faq-a {
            border-radius: 0 0 var(--radius-sm) var(--radius-sm) !important;
            min-height: 60px;
        }

        /* LOADING STATE */
        .loading-spinner {
            display: inline-block;
            width: 1.25rem;
            height: 1.25rem;
            border: 2px solid rgba(255,255,255,0.3);
            border-top-color: white;
            border-radius: 50%;
            animation: spin 0.8s linear infinite;
        }
        
		.btn-add-loading {
            opacity: 0.8;
            cursor: not-allowed;
        }
        .btn-add-loading i {
            animation: spin 1s linear infinite;
        }
		
        @keyframes spin {
            to { transform: rotate(360deg); }
        }

        /* RESPONSIVE ADJUSTMENTS */
        @media (max-width: 768px) {
            .builder-header {
                padding: 0 1.25rem;
                height: 65px;
            }
            
            .brand { font-size: 1.35rem; }
            .brand i { font-size: 1.6rem; }
            
            .builder-tabs-container {
                padding: 0.75rem 1.25rem;
                padding-bottom: 0;
            }
            
            .nav-tabs .nav-link {
                padding: 0.75rem 0.85rem;
                font-size: 0.9rem;
                margin-right: 0.25rem;
            }
            
            .add-elements-container {
                grid-template-columns: repeat(auto-fill, minmax(130px, 1fr));
                gap: 0.75rem;
            }
            
            .btn-add-element {
                padding: 1rem 0.5rem;
                min-height: 90px;
            }
            
            .btn-add-element i { font-size: 1.5rem; }
            
            .element-card-header {
                padding: 1rem;
            }
            
            .element-card-icon {
                width: 42px;
                height: 42px;
                margin-right: 1rem;
            }
            
            .element-card-title { font-size: 1rem; }
            .element-card-subtitle { font-size: 0.8rem; }
            
            .element-settings { padding: 1.5rem; }
            
            .save-btn-container {
                width: 94%;
                bottom: 1.5rem;
            }
            
            .save-btn {
                padding: 1.15rem 1.75rem;
                font-size: 1.05rem;
            }
        }
        
        @media (max-width: 480px) {
            .add-elements-container {
                grid-template-columns: repeat(auto-fill, minmax(110px, 1fr));
            }
            
            .btn-add-element {
                min-height: 80px;
                padding: 0.75rem 0.4rem;
                font-size: 0.9rem;
            }
            
            .btn-add-element i { font-size: 1.35rem; }
            
            .element-card-actions { margin-left: 0.5rem; }
            .btn-action { width: 32px; height: 32px; }
            .toggle-indicator { width: 26px; height: 26px; }
            
            .save-btn { padding: 1rem 1.5rem; font-size: 1rem; }
        }
		
		.element-add-btn {
            width: 100%;
            padding: 1rem;
            background: linear-gradient(135deg, rgba(99, 102, 241, 0.1) 0%, rgba(139, 92, 246, 0.1) 100%);
            border: none;
            border-top: 1px solid var(--gray-200);
            color: var(--primary);
            font-weight: 600;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.75rem;
            transition: var(--transition);
            border-radius: 0 0 var(--radius-md) var(--radius-md);
        }
        
        .element-add-btn:hover {
            background: linear-gradient(135deg, rgba(99, 102, 241, 0.15) 0%, rgba(139, 92, 246, 0.15) 100%);
            transform: translateY(-1px);
            box-shadow: var(--shadow-sm);
        }
        
        .element-add-btn i {
            font-size: 1.1rem;
        }
        
        /* ✅ MODAL TAMBAH ELEMENT */
        .add-element-modal {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0, 0, 0, 0.5);
            backdrop-filter: blur(4px);
            display: none;
            align-items: center;
            justify-content: center;
            z-index: 2000;
            opacity: 0;
            transition: opacity 0.3s ease;
        }
        
        .add-element-modal.show {
            display: flex;
            opacity: 1;
        }
        
        .add-element-modal-content {
            background: white;
            border-radius: var(--radius-lg);
            max-width: 500px;
            width: 90%;
            max-height: 85vh;
            overflow-y: auto;
            box-shadow: var(--shadow-lg);
            transform: translateY(20px);
            transition: transform 0.3s ease;
            animation: modalSlideUp 0.4s ease-out;
        }
        
        @keyframes modalSlideUp {
            from { transform: translateY(30px); opacity: 0; }
            to { transform: translateY(0); opacity: 1; }
        }
        
        .add-element-modal-header {
            padding: 1.5rem;
            border-bottom: 1px solid var(--gray-200);
            background: linear-gradient(120deg, var(--primary) 0%, var(--secondary) 100%);
            color: white;
            border-radius: var(--radius-lg) var(--radius-lg) 0 0;
        }
        
        .add-element-modal-header h3 {
            font-weight: 700;
            font-size: 1.35rem;
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }
        
        .add-element-modal-header h3 i { font-size: 1.5rem; }
        
        .add-element-modal-close {
            position: absolute;
            top: 1.25rem;
            right: 1.25rem;
            background: rgba(255, 255, 255, 0.2);
            border: none;
            color: white;
            width: 36px;
            height: 36px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: var(--transition);
            font-size: 1.1rem;
        }
        
        .add-element-modal-close:hover {
            background: rgba(255, 255, 255, 0.3);
            transform: rotate(90deg);
        }
        
        .add-element-modal-body {
            padding: 1.5rem;
        }
        
        .element-type-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(120px, 1fr));
            gap: 1rem;
            margin-top: 0.5rem;
        }
        
        .element-type-card {
            padding: 1.25rem 0.75rem;
            background: white;
            border: 2px solid var(--gray-200);
            border-radius: var(--radius-md);
            text-align: center;
            cursor: pointer;
            transition: var(--transition);
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            gap: 0.75rem;
            min-height: 110px;
            box-shadow: var(--shadow-sm);
        }
        
        .element-type-card:hover {
            border-color: var(--primary);
            background: rgba(99, 102, 241, 0.05);
            transform: translateY(-3px);
            box-shadow: var(--shadow-md);
        }
        
        .element-type-card i {
            font-size: 2rem;
            color: var(--primary);
        }
        
        .element-type-card.paragraph i { color: #6b7280; }
        .element-type-card.button i { color: #ef4444; }
        .element-type-card.image i { color: #10b981; }
        .element-type-card.youtube i { color: #ef4444; }
        .element-type-card.divider i { color: #8b5cf6; }
        .element-type-card.faq i { color: #f59e0b; }
        .element-type-card.html i { color: #6b7280; }
        
        .element-type-card span {
            font-weight: 600;
            font-size: 0.95rem;
            color: var(--gray-800);
        }
        
        .element-type-card:hover span {
            color: var(--primary-dark);
        }
        
        /* ✅ TOOLTIP */
        .tooltip-text {
            position: absolute;
            bottom: 100%;
            left: 50%;
            transform: translateX(-50%) translateY(10px);
            background: var(--gray-800);
            color: white;
            padding: 0.4rem 0.8rem;
            border-radius: var(--radius-sm);
            font-size: 0.85rem;
            white-space: nowrap;
            opacity: 0;
            visibility: hidden;
            transition: var(--transition);
            z-index: 10;
            pointer-events: none;
        }
        
        .element-add-btn:hover .tooltip-text {
            opacity: 1;
            visibility: visible;
            transform: translateX(-50%) translateY(0);
        }
        
        /* ✅ LOADING STATE */
        .element-add-btn.loading {
            opacity: 0.7;
            cursor: not-allowed;
        }
        
        .element-add-btn.loading i {
            animation: spin 1s linear infinite;
        }
        
        @keyframes spin {
            to { transform: rotate(360deg); }
        }
        
        /* ✅ RESPONSIVE MODAL */
        @media (max-width: 768px) {
            .element-type-grid {
                grid-template-columns: repeat(auto-fill, minmax(100px, 1fr));
                gap: 0.75rem;
            }
            
            .element-type-card {
                padding: 1rem 0.5rem;
                min-height: 100px;
            }
            
            .element-type-card i { font-size: 1.75rem; }
            .element-type-card span { font-size: 0.9rem; }
            
            .add-element-modal-header h3 { font-size: 1.25rem; }
            .add-element-modal-header h3 i { font-size: 1.35rem; }
            
            .add-element-modal-body { padding: 1.25rem; }
        }
        
        @media (max-width: 480px) {
            .element-type-grid {
                grid-template-columns: repeat(auto-fill, minmax(90px, 1fr));
                gap: 0.65rem;
            }
            
            .element-type-card {
                padding: 0.85rem 0.4rem;
                min-height: 90px;
                font-size: 0.85rem;
            }
            
            .element-type-card i { font-size: 1.5rem; }
        }
    </style>
</head>
<body>

<div class="builder-header">
<a href="../index.php" class="brand" style="text-decoration: none; color: white; cursor: pointer;">
    <i class="fas fa-gem"></i>
    <span>LP Builder Pro</span>
</a>
    <div class="header-actions">
        <?php if($page): ?>
            <a href="preview.php?id=<?= $page_id ?>" target="_blank" class="btn">
                <i class="fas fa-eye me-1"></i> Preview
            </a>
        <?php endif; ?>
    </div>
</div>

<div class="container-fluid" style="max-width: 850px; margin: 0 auto; padding: 0 1.5rem;">
    
    <?php if(isset($_GET['saved'])): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <i class="fas fa-check-circle me-2"></i>
            <strong>Berhasil!</strong> Perubahan halaman berhasil disimpan.
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>
    
    <?php if($error): ?>
        <div class="alert alert-danger">
            <i class="fas fa-exclamation-triangle me-2"></i>
            <strong>Error:</strong> <?= htmlspecialchars($error) ?>
        </div>
    <?php endif; ?>

    <form id="builderForm" method="POST">
        
        <div class="builder-tabs-container">
            <ul class="nav nav-tabs" id="builderTabs" role="tablist">
                <li class="nav-item" role="presentation">
                    <button class="nav-link active" id="content-tab" data-bs-toggle="tab" data-bs-target="#content-pane" type="button" role="tab" aria-controls="content-pane" aria-selected="true">
                        <i class="fas fa-layer-group"></i> Konten
                    </button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link" id="settings-tab" data-bs-toggle="tab" data-bs-target="#settings-pane" type="button" role="tab" aria-controls="settings-pane" aria-selected="false">
                        <i class="fas fa-sliders-h"></i> Setting
                    </button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link" id="tracking-tab" data-bs-toggle="tab" data-bs-target="#tracking-pane" type="button" role="tab" aria-controls="tracking-pane" aria-selected="false">
                        <i class="fab fa-facebook"></i> Tracking
                    </button>
                </li>
            </ul>
        </div>

        <div class="tab-content mt-4" id="builderTabContent">
            
            <div class="tab-pane fade show active" id="content-pane" role="tabpanel" aria-labelledby="content-tab">
                <div id="canvasElements">
                    <?php if (empty($elements)): ?>
                        <div class="empty-state">
                            <i class="fas fa-columns"></i>
                            <p class="text-muted">Belum ada elemen konten. Klik tombol di bawah untuk menambah elemen pertama.</p>
                            <button type="button" class="element-add-btn" style="width: auto; margin: 1.5rem auto; padding: 0.85rem 2rem;" onclick="openAddElementModal(-1)">
                                <i class="fas fa-plus-circle"></i>
                                <span>Tambah Elemen Pertama</span>
                            </button>
                        </div>
                    <?php else:
                        foreach ($elements as $idx => $el) {
                            $st = json_decode($el['styles'], true) ?: [];
                            echo renderElementUI($el['type'], $idx, $el['content'], $st);
                        }
                    endif; ?>
                </div>
            </div>

            <!-- ... (tab settings & tracking tetap sama) ... -->
            <div class="tab-pane fade" id="settings-pane" role="tabpanel" aria-labelledby="settings-tab">
                <div class="card border-0 shadow-sm mb-4">
                    <div class="card-body p-4">
                        <h5 class="card-title mb-4" style="font-weight: 700; color: var(--gray-800);">
                            <i class="fas fa-cog me-2 text-primary"></i>Pengaturan Halaman
                        </h5>
                        <div class="mb-4">
                            <label class="form-label">Judul Halaman</label>
                            <input type="text" name="title" class="form-control" value="<?= htmlspecialchars($page['title'] ?? '') ?>" required placeholder="Masukkan judul halaman">
                        </div>
                        <div class="mb-4">
                            <label class="form-label">Slug URL</label>
                            <div class="input-group">
                                <span class="input-group-text bg-white" style="border-right: none;">https://yourdomain.com/</span>
                                <input type="text" name="slug" class="form-control" value="<?= htmlspecialchars($page['slug'] ?? '') ?>" placeholder="nama-halaman-anda">
                            </div>
                            <small class="text-muted mt-1 d-block">Slug akan digunakan sebagai URL halaman Anda</small>
                        </div>
                    </div>
                </div>
            </div>

            <div class="tab-pane fade" id="tracking-pane" role="tabpanel" aria-labelledby="tracking-tab">
                <div class="card border-0 shadow-sm mb-4">
                    <div class="card-body p-4">
                        <h5 class="card-title mb-4" style="font-weight: 700; color: var(--gray-800);">
                            <i class="fab fa-facebook me-2" style="color: #1877f2;"></i>Pelacakan Meta
                        </h5>
                        <div class="mb-4">
                            <label class="form-label">Meta Pixel ID</label>
                            <input type="text" name="pixel_id" class="form-control" value="<?= htmlspecialchars($page['meta_pixel_id'] ?? '') ?>" placeholder="1234567890">
                            <small class="text-muted mt-1 d-block">ID Pixel untuk melacak aktivitas pengguna</small>
                        </div>
                        <div class="mb-4">
                            <label class="form-label">Nama Event</label>
                            <input type="text" name="meta_event_name" class="form-control" value="<?= htmlspecialchars($page['meta_event_name'] ?? 'ViewContent') ?>" placeholder="ViewContent">
                            <small class="text-muted mt-1 d-block">Nama event yang akan dikirim ke Meta</small>
                        </div>
                        <hr class="my-4">
                        <h6 class="mb-3" style="font-weight: 600; color: var(--gray-800);">Conversions API (CAPI)</h6>
                        <div class="mb-4">
                            <label class="form-label">CAPI Access Token</label>
                            <input type="text" name="capi_access_token" class="form-control" value="<?= htmlspecialchars($page['capi_access_token'] ?? '') ?>" placeholder="EAAB...">
                            <small class="text-muted mt-1 d-block">Token akses untuk Conversions API</small>
                        </div>
                        <div class="mb-4">
                            <label class="form-label">CAPI Endpoint</label>
                            <input type="text" name="capi_endpoint" class="form-control" value="<?= htmlspecialchars($page['capi_endpoint'] ?? '') ?>" placeholder="https://graph.facebook.com/v18.0/...">
                            <small class="text-muted mt-1 d-block">Endpoint API untuk mengirim event</small>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="save-btn-container">
            <button type="submit" class="save-btn">
                <i class="fas fa-save"></i>
                <span>SIMPAN PERUBAHAN</span>
            </button>
        </div>

    </form>
</div>

<div class="add-element-modal" id="addElementModal">
    <div class="add-element-modal-content">
        <div class="add-element-modal-header">
            <h3><i class="fas fa-plus-circle"></i> <span>Tambah Element Baru</span></h3>
            <button type="button" class="add-element-modal-close" onclick="closeAddElementModal()">
                <i class="fas fa-times"></i>
            </button>
        </div>
        <div class="add-element-modal-body">
            <p style="margin-bottom: 1.25rem; color: var(--gray-600);">Pilih jenis element yang ingin ditambahkan:</p>
            <div class="element-type-grid">
                <?php foreach(['paragraph','button','image','youtube','divider','faq','html'] as $type): ?>
                    <div class="element-type-card <?= $type ?>" onclick="selectElementType('<?= $type ?>')">
                        <i class="<?= 
                            $type === 'paragraph' ? 'fas fa-font' :
                            ($type === 'button' ? 'fas fa-square' :
                            ($type === 'image' ? 'fas fa-image' :
                            ($type === 'youtube' ? 'fab fa-youtube' :
                            ($type === 'divider' ? 'fas fa-minus' :
                            ($type === 'faq' ? 'fas fa-question-circle' : 'fas fa-code')))))
                        ?>"></i>
                        <span><?= ucfirst($type) ?></span>
                    </div>
                <?php endforeach; ?>
            </div>
            <div style="margin-top: 1.5rem; padding-top: 1.25rem; border-top: 1px solid var(--gray-200); text-align: center;">
                <button type="button" class="btn" onclick="closeAddElementModal()" style="background: var(--gray-200); color: var(--gray-700); padding: 0.65rem 1.5rem; border-radius: 50px; font-weight: 500;">
                    <i class="fas fa-times me-1"></i> Batal
                </button>
            </div>
        </div>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.quilljs.com/1.3.6/quill.min.js"></script>

<script>
// --- KONFIGURASI ---
const fullColorPalette = [
    "#000000", "#ffffff", "#e60000", "#ff9900", "#ffff00", "#008a00", "#0066cc", 
    "#9933ff", "#facccc", "#ffebcc", "#ffffcc", "#cce8cc", "#cce0f5", "#ebd6ff", 
    "#f06666", "#ffc266", "#ffff66", "#66b966", "#66a3e0", "#c285ff", 
    "#a10000", "#b26b00", "#b2b200", "#006100", "#0047b2", "#6b24b2", 
    "#5c0000", "#663d00", "#666600", "#003700", "#002966", "#3d1466"
];
let quillEditors = {};
let elementCounter = <?= count($elements) ?>; // ✅ HANYA 1x deklarasi
let currentInsertAfterIndex = -1;

// --- MODAL FUNCTIONS ---
function openAddElementModal(insertAfterIndex) {
    currentInsertAfterIndex = insertAfterIndex;
    document.getElementById('addElementModal').classList.add('show');
}

function closeAddElementModal() {
    document.getElementById('addElementModal').classList.remove('show');
    currentInsertAfterIndex = -1;
}

function selectElementType(type) {
    // 1. Simpan dulu nilainya ke variabel lokal sebelum di-reset
    const targetIndex = currentInsertAfterIndex;
    
    // 2. Baru tutup modal (yang akan mereset currentInsertAfterIndex jadi -1)
    closeAddElementModal();
    
    // 3. Gunakan variabel lokal tadi untuk pengecekan
    if (targetIndex >= 0) {
        insertElementAfter(targetIndex, type);
    } else {
        addNewElement(type);
    }
}

// --- ELEMENT CREATION ---
function getDefaultStyles(type) {
    return {
        bg_color: type === 'button' ? '#6366f1' : '#ffffff',
        text_color: type === 'button' ? '#ffffff' : '#000000',
        link: '#',
        thickness: '2',
        divider_style: 'solid',
        margin: '20'
    };
}

function getIconForType(type) {
    const icons = {
        'paragraph': 'fas fa-font',
        'button': 'fas fa-square',
        'image': 'fas fa-image',
        'youtube': 'fab fa-youtube',
        'divider': 'fas fa-minus',
        'faq': 'fas fa-question-circle',
        'html': 'fas fa-code'
    };
    return icons[type] || 'fas fa-cube';
}

function createElementHTML(idx, type, styles) {
    const icon = getIconForType(type);
    const typeName = type.charAt(0).toUpperCase() + type.slice(1);
    
    // Divider-specific fields
    const dividerFields = type === 'divider' ? `
        <input type="hidden" name="elements[${idx}][styles][thickness]" class="in-thickness" value="${styles.thickness}">
        <input type="hidden" name="elements[${idx}][styles][divider_style]" class="in-divider-style" value="${styles.divider_style}">
        <input type="hidden" name="elements[${idx}][styles][margin]" class="in-margin" value="${styles.margin}">
    ` : '';
    
    return `
        <div class="element-card" id="el-target-${idx}" data-element-index="${idx}" data-element-type="${type}">
            <div class="element-card-header" onclick="toggleElementSettings(${idx}, event)">
                <div class="element-card-icon"><i class="${icon}"></i></div>
                <div class="element-card-info">
                    <div class="element-card-title">${typeName}</div>
                    <div class="element-card-subtitle">#${idx + 1}</div>
                </div>
                <div class="element-card-actions">
                    <div class="btn-group">
                        <button type="button" class="btn btn-action move-up" onclick="event.stopPropagation(); moveElement(${idx}, -1)">
                            <i class="fas fa-arrow-up"></i>
                        </button>
                        <button type="button" class="btn btn-action move-down" onclick="event.stopPropagation(); moveElement(${idx}, 1)">
                            <i class="fas fa-arrow-down"></i>
                        </button>
                    </div>
                    <div class="toggle-indicator">
                        <i class="fas fa-chevron-down" id="chevron-${idx}"></i>
                    </div>
                </div>
            </div>
            <div class="element-settings" id="settings-${idx}">
                <input type="hidden" name="elements[${idx}][type]" value="${type}">
                <input type="hidden" name="elements[${idx}][styles][bg_color]" class="in-bg" value="${styles.bg_color}">
                <input type="hidden" name="elements[${idx}][styles][text_color]" class="in-tx" value="${styles.text_color}">
                <input type="hidden" name="elements[${idx}][styles][link]" class="in-link" value="${styles.link}">
                ${dividerFields}
                <textarea name="elements[${idx}][content]" class="d-none in-content"></textarea>
                <div class="settings-form" id="form-${idx}"></div>
                <div class="element-actions">
                    <button type="button" class="btn btn-duplicate" onclick="duplicateElement(${idx})">
                        <i class="fas fa-copy"></i> Duplikat
                    </button>
                    <button type="button" class="btn btn-delete" onclick="deleteElement(${idx})">
                        <i class="fas fa-trash"></i> Hapus
                    </button>
                </div>
            </div>
            <button type="button" class="element-add-btn" onclick="event.stopPropagation(); openAddElementModal(${idx})">
                <i class="fas fa-plus"></i>
                <span>Tambah Element di Bawah</span>
                <span class="tooltip-text">Tambah element baru di bawah ini</span>
            </button>
        </div>
    `;
}

function addNewElement(type) {
    const styles = getDefaultStyles(type);
    const idx = elementCounter;
    const html = createElementHTML(idx, type, styles);
    
    const canvas = document.getElementById('canvasElements');
    const emptyState = canvas.querySelector('.empty-state');
    if (emptyState) emptyState.remove();
    
    canvas.insertAdjacentHTML('beforeend', html);
    
    setTimeout(() => {
        loadSettingsForm(idx);
        updateAllIndices();
        
        // Auto scroll & open settings
        const newEl = document.getElementById(`el-target-${idx}`);
        if (newEl) {
            newEl.scrollIntoView({ behavior: 'smooth', block: 'center' });
            toggleElementSettings(idx);
        }
        
        elementCounter++;
    }, 50);
}

function insertElementAfter(afterIdx, type) {
    const target = document.getElementById(`el-target-${afterIdx}`);
    if (!target) return addNewElement(type);
    
    const idx = elementCounter;
    const html = createElementHTML(idx, type, getDefaultStyles(type));
    
    target.insertAdjacentHTML('afterend', html);
    
    setTimeout(() => {
        loadSettingsForm(idx);
        updateAllIndices();
        
        const newEl = document.getElementById(`el-target-${idx}`);
        if (newEl) {
            newEl.scrollIntoView({ behavior: 'smooth', block: 'center' });
            toggleElementSettings(idx);
        }
        
        elementCounter++;
    }, 50);
}

// --- DUPLICATE (insert setelah current) ---
function duplicateElement(idx) {
    const el = document.getElementById(`el-target-${idx}`);
    const type = el.dataset.elementType;
    
    // Clone styles
    const styles = {
        bg_color: el.querySelector('.in-bg').value,
        text_color: el.querySelector('.in-tx').value,
        link: el.querySelector('.in-link').value,
        thickness: el.querySelector('.in-thickness')?.value || '2',
        divider_style: el.querySelector('.in-divider-style')?.value || 'solid',
        margin: el.querySelector('.in-margin')?.value || '20'
    };
    
    // Clone content
    const content = el.querySelector('.in-content').value;
    
    // Insert after current
    const newIdx = elementCounter;
    const html = createElementHTML(newIdx, type, styles);
    
    el.insertAdjacentHTML('afterend', html);
    
    setTimeout(() => {
        // Set content
        document.getElementById(`el-target-${newIdx}`).querySelector('.in-content').value = content;
        
        loadSettingsForm(newIdx);
        updateAllIndices();
        
        const newEl = document.getElementById(`el-target-${newIdx}`);
        if (newEl) {
            newEl.scrollIntoView({ behavior: 'smooth', block: 'center' });
            toggleElementSettings(newIdx);
        }
        
        elementCounter++;
    }, 50);
}

// --- CORE FUNCTIONS (toggle, loadSettings, helpers) ---
function toggleElementSettings(idx, event) {
    if (event) event.stopPropagation();
    
    const settings = document.getElementById(`settings-${idx}`);
    const chevron = document.getElementById(`chevron-${idx}`);
    if (!settings) return;
    
    // Close others
    document.querySelectorAll('.element-settings.show').forEach(el => {
        if (el.id !== `settings-${idx}`) {
            el.classList.remove('show');
            const otherIdx = el.id.replace('settings-', '');
            const otherChev = document.getElementById(`chevron-${otherIdx}`);
            if (otherChev) otherChev.parentElement.classList.remove('rotated');
        }
    });
    
    // Toggle current
    const isShown = settings.classList.contains('show');
    settings.classList.toggle('show', !isShown);
    if (chevron) {
        chevron.parentElement.classList.toggle('rotated', !isShown);
    }
    
    // Load form if empty
    if (!isShown && document.getElementById(`form-${idx}`).innerHTML.trim() === '') {
        loadSettingsForm(idx);
    }
}

function loadSettingsForm(idx) {
    // ... (sama seperti file Anda - paragraph, button, image, dll) ...
    const el = document.getElementById(`el-target-${idx}`);
    if (!el) return;
    
    const type = el.dataset.elementType;
    const content = el.querySelector('.in-content').value || '';
    const bg = el.querySelector('.in-bg').value || '#ffffff';
    const tx = el.querySelector('.in-tx').value || '#000000';
    const link = el.querySelector('.in-link').value || '#';
    
    let html = '';
    
    if (type === 'paragraph') {
        html = `<div class="mb-3"><label class="form-label">Isi Teks</label><div class="editable-content" id="editor-${idx}"></div></div>`;
        setTimeout(() => initQuill(idx, content), 100);
    } 
    else if (type === 'button') {
        html = `
            <div class="mb-3">
                <label class="form-label">Teks Tombol</label>
                <input type="text" class="form-control" value="${escapeHtml(content)}" oninput="updateHiddenVal(${idx}, '.in-content', this.value)">
            </div>
            <div class="mb-3">
                <label class="form-label">Link Tujuan</label>
                <input type="text" class="form-control" value="${escapeHtml(link)}" oninput="updateHiddenVal(${idx}, '.in-link', this.value)">
            </div>
            <div class="row">
                <div class="col-6">
                    <label class="form-label">Background</label>
                    <input type="color" class="form-control form-control-color w-100" value="${bg}" oninput="updateHiddenVal(${idx}, '.in-bg', this.value)">
                </div>
                <div class="col-6">
                    <label class="form-label">Teks Color</label>
                    <input type="color" class="form-control form-control-color w-100" value="${tx}" oninput="updateHiddenVal(${idx}, '.in-tx', this.value)">
                </div>
            </div>`;
    }
    else if (type === 'image') {
        html = `<div class="mb-3"><label class="form-label">URL Gambar</label><input type="text" class="form-control" value="${escapeHtml(content)}" oninput="updateHiddenVal(${idx}, '.in-content', this.value)" placeholder="https://..."></div>`;
    }
    else if (type === 'youtube') {
        html = `<div class="mb-3"><label class="form-label">Youtube Video ID</label><input type="text" class="form-control" value="${escapeHtml(content)}" oninput="updateHiddenVal(${idx}, '.in-content', this.value)" placeholder="dQw4w9WgXcQ"></div>`;
    }
    else if (type === 'divider') {
        const thick = el.querySelector('.in-thickness').value || '2';
        html = `
            <div class="mb-3">
                <label class="form-label">Ketebalan</label>
                <input type="range" class="form-range" min="1" max="10" value="${thick}" oninput="updateHiddenVal(${idx}, '.in-thickness', this.value)">
            </div>
            <div class="mb-3">
                <label class="form-label">Warna Garis</label>
                <input type="color" class="form-control form-control-color w-100" value="${tx}" oninput="updateHiddenVal(${idx}, '.in-tx', this.value)">
            </div>`;
    }
    else if (type === 'faq') {
        let faqData = [];
        try { faqData = JSON.parse(content || '[]'); } catch(e) { faqData = []; }
        
        html = `
            <div id="faq-container-${idx}">
                <label class="form-label d-block">Daftar Tanya Jawab</label>
                <div class="faq-items-list" id="faq-list-${idx}">
                    ${faqData.map((item, fIdx) => `
                        <div class="card p-2 mb-2 bg-white border">
                            <input type="text" class="form-control form-control-sm mb-1 faq-q" placeholder="Pertanyaan" value="${escapeHtml(item.q)}" oninput="saveFaqData(${idx})">
                            <textarea class="form-control form-control-sm faq-a" placeholder="Jawaban" oninput="saveFaqData(${idx})">${escapeHtml(item.a)}</textarea>
                            <button type="button" class="btn btn-sm text-danger mt-1 p-0" onclick="removeFaqItem(${idx}, ${fIdx})">
                                <i class="fas fa-times"></i> Hapus Item
                            </button>
                        </div>
                    `).join('')}
                </div>
                <button type="button" class="btn btn-sm btn-outline-primary mt-2" onclick="addFaqItem(${idx})">
                    <i class="fas fa-plus"></i> Tambah FAQ
                </button>
            </div>
            <div class="mt-3 row">
                <div class="col-6">
                    <label class="form-label">Warna Pertanyaan</label>
                    <input type="color" class="form-control form-control-color w-100" value="${tx}" oninput="updateHiddenVal(${idx}, '.in-tx', this.value)">
                </div>
                <div class="col-6">
                    <label class="form-label">Warna Background</label>
                    <input type="color" class="form-control form-control-color w-100" value="${bg}" oninput="updateHiddenVal(${idx}, '.in-bg', this.value)">
                </div>
            </div>`;
    }
    else if (type === 'html') {
        html = `<div class="mb-3"><label class="form-label">Kode HTML</label><textarea class="form-control" rows="5" oninput="updateHiddenVal(${idx}, '.in-content', this.value)">${escapeHtml(content)}</textarea></div>`;
    }
    
    document.getElementById(`form-${idx}`).innerHTML = html;
}

// --- HELPER FUNCTIONS ---
function escapeHtml(text) {
    if (!text) return "";
    return text.replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;").replace(/"/g, "&quot;").replace(/'/g, "&#039;");
}

function updateHiddenVal(idx, selector, value) {
    const el = document.getElementById(`el-target-${idx}`);
    if (el) el.querySelector(selector).value = value;
}

function initQuill(idx, content) {
    if (quillEditors[idx]) {
        quillEditors[idx].root.innerHTML = content || '';
        return;
    }
    
    const editorEl = document.getElementById(`editor-${idx}`);
    if (!editorEl || editorEl.querySelector('.ql-editor')) return;
    
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
    
    if (content) quill.clipboard.dangerouslyPasteHTML(content);
    
    quill.on('text-change', function() {
        updateHiddenVal(idx, '.in-content', quill.root.innerHTML);
    });
    
    quillEditors[idx] = quill;
    updateHiddenVal(idx, '.in-content', quill.root.innerHTML);
}

// --- MOVE & DELETE ---
function moveElement(currentIdx, direction) {
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
    if (confirm('Yakin ingin menghapus elemen ini?')) {
        document.getElementById(`el-target-${idx}`).remove();
        updateAllIndices();
    }
}

// --- RE-INDEXING (KRITIS!) ---
function updateAllIndices() {
    const cards = document.querySelectorAll('.element-card');
    quillEditors = {}; // Reset Quill cache
    
    cards.forEach((card, newIndex) => {
        // Update IDs & attributes
        card.id = `el-target-${newIndex}`;
        card.dataset.elementIndex = newIndex;
        card.querySelector('.element-card-subtitle').textContent = `#${newIndex + 1}`;
        
        // Update event handlers
        card.querySelector('.element-card-header').setAttribute('onclick', `toggleElementSettings(${newIndex}, event)`);
        card.querySelector('.move-up').setAttribute('onclick', `event.stopPropagation(); moveElement(${newIndex}, -1)`);
        card.querySelector('.move-down').setAttribute('onclick', `event.stopPropagation(); moveElement(${newIndex}, 1)`);
        card.querySelector('.btn-duplicate').setAttribute('onclick', `duplicateElement(${newIndex})`);
        card.querySelector('.btn-delete').setAttribute('onclick', `deleteElement(${newIndex})`);
        card.querySelector('.element-add-btn').setAttribute('onclick', `event.stopPropagation(); openAddElementModal(${newIndex})`);
        
        // Update internal IDs
        card.querySelector('.element-settings').id = `settings-${newIndex}`;
        card.querySelector('.settings-form').id = `form-${newIndex}`;
        card.querySelector('.settings-form').innerHTML = '';
        
        const chevron = card.querySelector('.fa-chevron-down');
        if (chevron) chevron.id = `chevron-${newIndex}`;
        
        // Update name attributes untuk form submission
        card.querySelectorAll('[name]').forEach(input => {
            const oldName = input.getAttribute('name');
            if (oldName && oldName.includes('elements[')) {
                const newName = oldName.replace(/elements\[\d+\]/, `elements[${newIndex}]`);
                input.setAttribute('name', newName);
            }
        });
    });
}

// --- FAQ HELPERS ---
function addFaqItem(idx) {
    const list = document.getElementById(`faq-list-${idx}`);
    const newItem = document.createElement('div');
    newItem.className = 'card p-2 mb-2 bg-white border';
    newItem.innerHTML = `
        <input type="text" class="form-control form-control-sm mb-1 faq-q" placeholder="Pertanyaan" oninput="saveFaqData(${idx})">
        <textarea class="form-control form-control-sm faq-a" placeholder="Jawaban" oninput="saveFaqData(${idx})"></textarea>
        <button type="button" class="btn btn-sm text-danger mt-1 p-0" onclick="this.parentElement.remove(); saveFaqData(${idx})">
            <i class="fas fa-times"></i> Hapus Item
        </button>
    `;
    list.appendChild(newItem);
    saveFaqData(idx);
}

function removeFaqItem(idx, fIdx) {
    document.getElementById(`faq-list-${idx}`).children[fIdx].remove();
    saveFaqData(idx);
}

function saveFaqData(idx) {
    const list = document.getElementById(`faq-list-${idx}`);
    const items = [];
    
    list.querySelectorAll('.card').forEach(card => {
        const q = card.querySelector('.faq-q').value;
        const a = card.querySelector('.faq-a').value;
        if (q || a) items.push({ q: q, a: a });
    });
    
    updateHiddenVal(idx, '.in-content', JSON.stringify(items));
}

// --- INITIALIZATION ---
document.addEventListener('DOMContentLoaded', function() {
    // iOS zoom fix
    document.querySelectorAll('input, select, textarea').forEach(input => {
        input.addEventListener('focus', () => {
            document.querySelector('meta[name="viewport"]').setAttribute('content', 'width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=0');
        });
        input.addEventListener('blur', () => {
            document.querySelector('meta[name="viewport"]').setAttribute('content', 'width=device-width, initial-scale=1.0, maximum-scale=5.0, user-scalable=yes');
        });
    });
    
    // ESC to close modal
    document.addEventListener('keydown', e => {
        if (e.key === 'Escape') closeAddElementModal();
    });
    
    // Prevent modal close on inner click
    document.querySelector('.add-element-modal-content')?.addEventListener('click', e => e.stopPropagation());
    
    // Modal backdrop close
    document.getElementById('addElementModal').addEventListener('click', e => {
        if (e.target === e.currentTarget) closeAddElementModal();
    });
});
</script>
</body>
</html>