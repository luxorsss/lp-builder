<?php
require_once '../includes/config.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';

requireLogin();
$user = getCurrentUser($pdo);

$page_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$error = ''; $success = '';

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
        if ($page_id > 0) {
            $stmt = $pdo->prepare("UPDATE landing_pages SET title=?, slug=?, meta_pixel_id=?, capi_endpoint=?, capi_access_token=?, meta_event_name=? WHERE id=? AND user_id=?");
            $stmt->execute([$title, $slug, $pixel_id, $capi_endpoint, $capi_token, $event_name, $page_id, $user['id']]);
        } else {
            $stmt = $pdo->prepare("INSERT INTO landing_pages (user_id, title, slug, meta_pixel_id, capi_endpoint, capi_access_token, meta_event_name, status) VALUES (?,?,?,?,?,?,?,'draft')");
            $stmt->execute([$user['id'], $title, $slug, $pixel_id, $capi_endpoint, $capi_token, $event_name]);
            $page_id = $pdo->lastInsertId();
        }

        $pdo->prepare("DELETE FROM page_elements WHERE page_id=?")->execute([$page_id]);
        if (isset($_POST['elements'])) {
            foreach ($_POST['elements'] as $idx => $el) {
                $stmt = $pdo->prepare("INSERT INTO page_elements (page_id, type, content, order_position, styles) VALUES (?,?,?,?,?)");
                $stmt->execute([$page_id, $el['type'], $el['content'], $idx, json_encode($el['styles'])]);
            }
        }
        $pdo->commit();
        header("Location: builder.php?id=$page_id&saved=1"); exit;
    } catch (Exception $e) { $pdo->rollBack(); $error = $e->getMessage(); }
}

$page = $page_id > 0 ? getUserLandingPage($pdo, $page_id, $user['id']) : null;
$elements = [];
if ($page) {
    $stmt = $pdo->prepare("SELECT * FROM page_elements WHERE page_id=? ORDER BY order_position ASC");
    $stmt->execute([$page_id]);
    $elements = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// --- FUNGSI RENDER UI ---
function renderElementUI($type, $idx, $content, $st) {
    $bg = $st['bg_color'] ?? '#ffffff';
    $tx = $st['text_color'] ?? '#000000';
    $link = $st['link'] ?? '#';

    $html = '<div class="wysiwyg-element" id="el-target-'.$idx.'" data-element-index="'.$idx.'" data-element-type="'.$type.'" style="background:'.$bg.'; color:'.$tx.'">';
    $html .= '<div class="element-badge"><span><i class="fas fa-tag me-1"></i> '.$type.'</span><i class="fas fa-chevron-down toggle-icon"></i></div>';
    $html .= '<div class="drag-handle"><i class="fas fa-grip-vertical"></i></div>';
    
    $html .= '<input type="hidden" name="elements['.$idx.'][type]" value="'.$type.'">';
    $html .= '<input type="hidden" name="elements['.$idx.'][styles][bg_color]" class="in-bg" value="'.$bg.'">';
    $html .= '<input type="hidden" name="elements['.$idx.'][styles][text_color]" class="in-tx" value="'.$tx.'">';
    $html .= '<input type="hidden" name="elements['.$idx.'][styles][link]" class="in-link" value="'.$link.'">';
    
    $html .= '<div class="element-content-wrapper">';
    
    // Default content untuk elemen baru
    $defaultContent = '';
    switch($type) {
        case 'header':
            $defaultContent = 'Header Baru';
            break;
        case 'paragraph':
            $defaultContent = 'Paragraf baru... Klik untuk mengedit konten.';
            break;
		case 'html':
            $defaultContent = '<div class="alert alert-info"><i class="fas fa-code"></i> Custom HTML Content</div>';
            break;
        case 'button':
            $defaultContent = 'Klik Disini';
            break;
    }
    
    // Gunakan konten yang ada atau default jika kosong
    $displayContent = !empty($content) ? $content : $defaultContent;
    
    if($type == 'header' || $type == 'paragraph') {
        // Untuk konten yang bisa diedit dengan Quill
        $html .= '<div class="editable-content" data-editor-index="'.$idx.'">';
        $html .= !empty($content) ? htmlspecialchars_decode($content) : $defaultContent;
        $html .= '</div>';
    } elseif($type == 'divider') {
        $html .= '<hr style="border-top:2px solid '.$tx.'">';
    } elseif($type == 'youtube') {
        $html .= '<div class="ratio ratio-16x9">';
        if(!empty($content)) {
            $html .= '<iframe src="https://www.youtube.com/embed/'.$content.'" allowfullscreen></iframe>';
        } else {
            $html .= '<div class="d-flex align-items-center justify-content-center" style="background: #f1f5f9; border-radius: 8px;">
                        <div class="text-center p-4">
                            <i class="fab fa-youtube fa-2x text-danger mb-2"></i>
                            <p class="mb-0 small text-muted">Masukkan ID video YouTube</p>
                        </div>
                      </div>';
        }
        $html .= '</div>';
    } elseif($type == 'image') {
        if(!empty($content)) {
            $html .= '<img src="'.$content.'" class="img-fluid rounded">';
        } else {
            $html .= '<div class="d-flex align-items-center justify-content-center" style="background: #f1f5f9; border-radius: 8px; min-height: 150px;">
                        <div class="text-center p-4">
                            <i class="fas fa-image fa-2x text-muted mb-2"></i>
                            <p class="mb-0 small text-muted">Masukkan URL gambar</p>
                        </div>
                      </div>';
        }
    } elseif($type == 'html') {
        $html .= '<div class="html-preview border rounded p-3">';
        if(!empty($content)) {
            $html .= '<div class="html-content">'.htmlspecialchars_decode($content).'</div>';
        } else {
            $html .= '<div class="text-center p-3">
                        <i class="fas fa-code fa-2x text-muted mb-2"></i>
                        <p class="mb-0 small text-muted">Custom HTML Code</p>
                        <p class="small text-muted">Edit untuk menambahkan kode HTML</p>
                      </div>';
        }
        $html .= '</div>';
    } elseif($type == 'button') {
        $html .= '<div class="text-center">';
        $html .= '<a href="'.$link.'" class="btn btn-lg shadow-sm" style="background:'.$bg.'; color:'.$tx.'; padding: 12px 30px; border-radius: 10px; font-weight: 600;">';
        $html .= $displayContent;
        $html .= '</a>';
        $html .= '</div>';
    } elseif($type == 'faq') {
        $faqs = !empty($content) ? json_decode($content, true) : [];
        $html .= '<div class="faq-preview">';
        if(!empty($faqs)) {
            foreach($faqs as $f) {
                $html .= '<div class="faq-item-preview"><b>Q:</b> '.htmlspecialchars($f['q']??'').'<br><small>A: '.htmlspecialchars($f['a']??'').'</small></div>';
            }
        } else {
            $html .= '<div class="text-center p-3">
                        <i class="fas fa-question-circle fa-lg text-muted mb-2"></i>
                        <p class="mb-0 small text-muted">Klik untuk menambahkan FAQ</p>
                      </div>';
        }
        $html .= '</div>';
    }
    $html .= '</div>';
    
    // Textarea untuk konten asli (selalu ada, meski kosong)
    $html .= '<textarea name="elements['.$idx.'][content]" class="d-none in-content">'.htmlspecialchars($content).'</textarea>';
    $html .= '</div>';
    
    return $html;
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Ultimate Builder Pro</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css" rel="stylesheet">
    <link href="https://cdn.quilljs.com/1.3.6/quill.snow.css" rel="stylesheet">
    <style>
        :root {
            --primary: #6d28d9;
            --primary-light: #8b5cf6;
            --secondary: #10b981;
            --accent: #f59e0b;
            --light: #f8fafc;
            --dark: #1e293b;
            --gray: #64748b;
            --gray-light: #e2e8f0;
            --header-h: 80px;
            --side-w: 320px;
            --shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06);
            --shadow-lg: 0 10px 25px -5px rgba(0, 0, 0, 0.1), 0 10px 10px -5px rgba(0, 0, 0, 0.04);
            --gradient-primary: linear-gradient(135deg, #6d28d9 0%, #8b5cf6 100%);
            --gradient-light: linear-gradient(135deg, #f8fafc 0%, #f1f5f9 100%);
        }

        * {
            font-family: 'Inter', 'Segoe UI', system-ui, sans-serif;
        }

        body {
            background: var(--gradient-light);
            overflow: hidden;
            height: 100vh;
            font-size: 0.95rem;
        }

        .builder-header {
            height: var(--header-h);
            background: white;
            border-bottom: 1px solid var(--gray-light);
            display: flex;
            align-items: center;
            padding: 0 30px;
            position: fixed;
            top: 0;
            width: 100%;
            z-index: 1000;
            box-shadow: var(--shadow);
        }

        .brand {
            font-weight: 800;
            background: var(--gradient-primary);
            -webkit-background-clip: text;
            background-clip: text;
            color: transparent;
            font-size: 1.4rem;
            letter-spacing: -0.5px;
        }

        .main-container {
            display: flex;
            margin-top: var(--header-h);
            height: calc(100vh - var(--header-h));
        }

        .sidebar-left, .sidebar-right {
            width: var(--side-w);
            background: white;
            border-right: 1px solid var(--gray-light);
            overflow-y: auto;
            padding: 25px;
            flex-shrink: 0;
            box-shadow: var(--shadow);
        }

        .sidebar-right {
            border-left: 1px solid var(--gray-light);
            border-right: none;
        }

        .canvas-area {
            flex-grow: 1;
            overflow-y: auto;
            padding: 40px 30px;
            background: linear-gradient(135deg, #f1f5f9 0%, #e2e8f0 100%);
            min-height: 100%;
        }

        #builderForm {
            width: 100%;
            max-width: 900px;
            margin: 0 auto;
        }

        .wysiwyg-element {
            position: relative;
            background: white;
            padding: 20px;
            margin-bottom: 20px;
            border-radius: 16px;
            border: 2px solid transparent;
            border-left: 8px solid var(--gray-light);
            overflow: hidden;
            transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
            cursor: pointer;
            box-shadow: var(--shadow);
            min-height: 80px;
        }

        .wysiwyg-element:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-lg);
            border-left-color: var(--primary-light);
        }

        .wysiwyg-element.selected {
            border-color: var(--primary);
            border-left-color: var(--primary);
            transform: translateY(-2px);
            box-shadow: var(--shadow-lg);
            background: linear-gradient(135deg, #ffffff 0%, #f8fafc 100%);
        }

        .element-badge {
            display: flex;
            justify-content: space-between;
            font-size: 0.75rem;
            font-weight: 700;
            color: white;
            text-transform: uppercase;
            margin-bottom: 15px;
            padding: 6px 12px;
            border-radius: 20px;
            width: fit-content;
            background: var(--primary);
        }

        .drag-handle {
            position: absolute;
            left: -15px;
            top: 50%;
            transform: translateY(-50%);
            width: 30px;
            height: 60px;
            background: white;
            border: 1px solid var(--gray-light);
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--primary);
            cursor: grab;
            opacity: 0;
            transition: all 0.3s;
            box-shadow: var(--shadow);
        }

        .wysiwyg-element:hover .drag-handle {
            opacity: 1;
            transform: translateY(-50%) scale(1.1);
        }

        .drag-handle:hover {
            background: var(--primary);
            color: white;
        }

        .section-title {
            color: var(--dark);
            font-weight: 700;
            font-size: 0.9rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 20px;
            padding-bottom: 10px;
            border-bottom: 2px solid var(--gray-light);
            position: relative;
        }

        .section-title::after {
            content: '';
            position: absolute;
            bottom: -2px;
            left: 0;
            width: 60px;
            height: 2px;
            background: var(--primary);
        }

        .component-group {
            background: #f8fafc;
            border-radius: 12px;
            padding: 15px;
            margin-bottom: 25px;
            border: 1px solid var(--gray-light);
        }

        .component-group-title {
            color: var(--gray);
            font-size: 0.8rem;
            font-weight: 600;
            margin-bottom: 15px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .element-card {
            background: white;
            border: 2px solid var(--gray-light);
            padding: 15px;
            border-radius: 12px;
            margin-bottom: 12px;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 15px;
            transition: all 0.3s;
            position: relative;
            overflow: hidden;
        }

        .element-card::before {
            content: '';
            position: absolute;
            left: 0;
            top: 0;
            height: 100%;
            width: 4px;
            background: var(--primary);
            transform: scaleY(0);
            transition: transform 0.3s;
        }

        .element-card:hover {
            transform: translateX(5px);
            border-color: var(--primary-light);
            box-shadow: var(--shadow);
        }

        .element-card:hover::before {
            transform: scaleY(1);
        }

        .element-card i {
            width: 40px;
            height: 40px;
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-light) 100%);
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 1.1rem;
        }

        .element-card-text {
            flex: 1;
        }

        .element-card-title {
            font-weight: 600;
            color: var(--dark);
            margin-bottom: 2px;
        }

        .element-card-desc {
            font-size: 0.8rem;
            color: var(--gray);
        }

        .settings-tabs {
            display: flex;
            gap: 5px;
            margin-bottom: 20px;
            border-bottom: 2px solid var(--gray-light);
            padding-bottom: 10px;
        }

        .settings-tab {
            padding: 8px 20px;
            border-radius: 8px;
            font-size: 0.85rem;
            font-weight: 600;
            color: var(--gray);
            cursor: pointer;
            transition: all 0.3s;
        }

        .settings-tab:hover {
            background: var(--gray-light);
            color: var(--dark);
        }

        .settings-tab.active {
            background: var(--primary);
            color: white;
        }

        .form-label {
            font-weight: 600;
            color: var(--dark);
            font-size: 0.9rem;
            margin-bottom: 8px;
        }

        .form-control, .form-select {
            border: 2px solid var(--gray-light);
            border-radius: 10px;
            padding: 10px 15px;
            font-size: 0.9rem;
            transition: all 0.3s;
        }

        .form-control:focus, .form-select:focus {
            border-color: var(--primary-light);
            box-shadow: 0 0 0 3px rgba(109, 40, 217, 0.1);
        }

        .mini-navigator {
            position: fixed;
            bottom: 30px;
            left: 30px;
            width: 250px;
            background: white;
            border-radius: 16px;
            box-shadow: var(--shadow-lg);
            z-index: 1001;
            border: 1px solid var(--gray-light);
            transition: all 0.3s;
            overflow: hidden;
        }

        .mini-navigator.collapsed {
            max-height: 50px;
            width: 180px;
        }

        .nav-header {
            padding: 15px;
            background: var(--gradient-primary);
            color: white;
            font-size: 0.9rem;
            font-weight: 700;
            cursor: pointer;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        #navItems {
            max-height: 300px;
            overflow-y: auto;
        }

        .nav-item {
            padding: 12px 15px;
            font-size: 0.8rem;
            border-bottom: 1px solid var(--gray-light);
            cursor: pointer;
            transition: background 0.2s;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .nav-item:hover {
            background: #f1f5f9;
            color: var(--primary);
            padding-left: 20px;
        }
		
		.btn-xs {
			background: transparent;
			border-radius: 4px;
		}
		.btn-xs:hover {
			background: #e2e8f0;
		}
		.btn-xs:disabled {
			cursor: not-allowed;
		}

        .nav-item::before {
            content: '●';
            color: var(--primary);
            font-size: 0.6rem;
        }

        .status-badge {
            background: linear-gradient(135deg, var(--secondary) 0%, #34d399 100%);
            color: white;
            padding: 6px 15px;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 600;
            animation: pulse 2s infinite;
        }

        @keyframes pulse {
            0% { opacity: 1; }
            50% { opacity: 0.7; }
            100% { opacity: 1; }
        }

        .save-btn {
            background: var(--gradient-primary);
            border: none;
            padding: 12px 30px;
            border-radius: 12px;
            font-weight: 700;
            font-size: 1rem;
            transition: all 0.3s;
            box-shadow: 0 4px 15px rgba(109, 40, 217, 0.3);
        }

        .save-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(109, 40, 217, 0.4);
        }

        .color-input-group {
            display: flex;
            gap: 10px;
            align-items: center;
        }

        .color-preview {
            width: 40px;
            height: 40px;
            border-radius: 10px;
            border: 2px solid var(--gray-light);
            cursor: pointer;
        }

        .faq-item {
            background: white;
            border: 1px solid var(--gray-light);
            border-radius: 12px;
            padding: 15px;
            margin-bottom: 10px;
            position: relative;
        }

        .faq-item:hover {
            border-color: var(--primary-light);
        }

        .faq-remove {
            position: absolute;
            right: 10px;
            top: 10px;
            color: #ef4444;
            cursor: pointer;
            opacity: 0.6;
            transition: opacity 0.3s;
        }

        .faq-remove:hover {
            opacity: 1;
        }

        .ql-toolbar {
            border-radius: 10px 10px 0 0;
            border-color: var(--gray-light) !important;
        }

        .ql-container {
            border-radius: 0 0 10px 10px;
            border-color: var(--gray-light) !important;
            min-height: 150px;
            font-size: 1rem;
        }

        .ql-editor {
            min-height: 150px;
            padding: 15px;
        }

        .action-buttons {
            display: flex;
            gap: 10px;
            margin-top: 20px;
        }

        .btn-action {
            flex: 1;
            padding: 12px;
            border-radius: 10px;
            font-weight: 600;
            font-size: 0.9rem;
            transition: all 0.3s;
        }

        .btn-delete {
            background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%);
            color: white;
            border: none;
        }

        .btn-duplicate {
            background: linear-gradient(135deg, var(--accent) 0%, #fbbf24 100%);
            color: white;
            border: none;
        }

        .canvas-header {
            background: white;
            border-radius: 16px;
            padding: 30px;
            margin-bottom: 30px;
            box-shadow: var(--shadow);
            border-left: 8px solid var(--primary);
        }

        .page-meta {
            background: white;
            border-radius: 16px;
            padding: 25px;
            margin-bottom: 30px;
            box-shadow: var(--shadow);
        }

        .meta-section {
            margin-bottom: 25px;
            padding-bottom: 20px;
            border-bottom: 1px solid var(--gray-light);
        }

        .meta-section:last-child {
            margin-bottom: 0;
            border-bottom: none;
            padding-bottom: 0;
        }

        .meta-section-title {
            color: var(--primary);
            font-weight: 700;
            font-size: 1rem;
            margin-bottom: 15px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .meta-section-title i {
            background: var(--primary);
            color: white;
            width: 30px;
            height: 30px;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.9rem;
        }

        .empty-state {
            text-align: center;
            padding: 60px 20px;
            color: var(--gray);
            background: white;
            border-radius: 16px;
            border: 2px dashed var(--gray-light);
            margin-bottom: 30px;
        }

        .empty-state i {
            font-size: 3rem;
            margin-bottom: 20px;
            color: var(--gray-light);
        }

        .empty-state h5 {
            color: var(--dark);
            margin-bottom: 10px;
            font-weight: 600;
        }

        .editable-content {
            min-height: 50px;
            padding: 10px;
            border-radius: 8px;
            outline: none;
        }

        .editable-content:empty:before {
            content: attr(data-placeholder);
            color: #94a3b8;
            font-style: italic;
        }

        .element-content-wrapper {
            min-height: 60px;
        }

        .btn-lg {
            padding: 12px 30px !important;
            font-size: 1.1rem !important;
            font-weight: 600 !important;
            border-radius: 10px !important;
        }
		
		/* Tambahkan di bagian CSS untuk styling elemen baru */
		.faq-item-preview {
			background: white;
			border: 1px solid #e2e8f0;
			border-radius: 8px;
			padding: 12px;
			margin-bottom: 8px;
			transition: all 0.2s;
		}

		.faq-item-preview:hover {
			border-color: #8b5cf6;
			box-shadow: 0 2px 8px rgba(139, 92, 246, 0.1);
		}

		.form-range::-webkit-slider-thumb {
			background: #6d28d9;
		}

		.form-range::-moz-range-thumb {
			background: #6d28d9;
		}
		
		.btn-export {
			background: linear-gradient(135deg, #10b981 0%, #34d399 100%);
			color: white;
			border: none;
		}
    </style>
</head>
<body>

<div class="builder-header">
    <div class="d-flex align-items-center gap-3">
        <div class="brand">
            <i class="fas fa-magic me-2"></i>LP Builder Pro
        </div>
        
        <!-- TAMBAHKAN TOMBOL BACK TO LIST -->
        <a href="../index.php" class="btn btn-outline-secondary btn-sm">
            <i class="fas fa-arrow-left me-1"></i> Back to Pages
        </a>
        
        <div class="text-muted small">
            <?= $page ? 'Editing: ' . htmlspecialchars($page['title']) : 'Creating New Page' ?>
        </div>
    </div>
    
    <div class="ms-auto d-flex align-items-center gap-3">
        <?php if(isset($_GET['saved'])): ?>
            <div class="status-badge">
                <i class="fas fa-check-circle me-2"></i>Saved Successfully!
            </div>
        <?php endif; ?>
        
        <?php if($page): ?>
            <a href="../preview.php?id=<?= $page_id ?>" target="_blank" class="btn btn-outline-primary px-3">
                <i class="fas fa-eye me-2"></i>Preview
            </a>
        <?php endif; ?>
		
		<!-- Tambahkan setelahnya: -->
		<?php if($page): ?>
			<a href="export_html.php?id=<?= $page_id ?>" class="btn btn-outline-success px-3">
				<i class="fas fa-file-export me-2"></i>Export HTML
			</a>
		<?php endif; ?>
        
        <button type="submit" form="builderForm" class="save-btn">
            <i class="fas fa-save me-2"></i>SAVE PAGE
        </button>
    </div>
</div>

<div class="main-container">
    <!-- Left Sidebar - Components -->
    <div class="sidebar-left">
        <h6 class="section-title">
            <i class="fas fa-cube me-2"></i>Page Elements
        </h6>
        
        <div class="component-group">
            <div class="component-group-title">
                <i class="fas fa-text-height"></i>Text Elements
            </div>
            <div class="element-card" data-type="header">
                <i class="fas fa-heading"></i>
                <div class="element-card-text">
                    <div class="element-card-title">Header</div>
                    <div class="element-card-desc">Add a heading section</div>
                </div>
            </div>
            <div class="element-card" data-type="paragraph">
                <i class="fas fa-paragraph"></i>
                <div class="element-card-text">
                    <div class="element-card-title">Paragraph</div>
                    <div class="element-card-desc">Add text content</div>
                </div>
            </div>
        </div>

        <div class="component-group">
            <div class="component-group-title">
                <i class="fas fa-photo-video"></i>Media Elements
            </div>
            <div class="element-card" data-type="image">
                <i class="fas fa-image"></i>
                <div class="element-card-text">
                    <div class="element-card-title">Image</div>
                    <div class="element-card-desc">Add images to your page</div>
                </div>
            </div>
			<div class="element-card" data-type="html">
            	<i class="fas fa-code"></i>
				<div class="element-card-text">
					<div class="element-card-title">Custom HTML</div>
					<div class="element-card-desc">Add custom HTML code</div>
				</div>
			</div>
            <div class="element-card" data-type="youtube">
                <i class="fab fa-youtube"></i>
                <div class="element-card-text">
                    <div class="element-card-title">YouTube Video</div>
                    <div class="element-card-desc">Embed YouTube videos</div>
                </div>
            </div>
            <div class="element-card" data-type="divider">
                <i class="fas fa-grip-lines"></i>
                <div class="element-card-text">
                    <div class="element-card-title">Divider</div>
                    <div class="element-card-desc">Separate content sections</div>
                </div>
            </div>
        </div>

        <div class="component-group">
            <div class="component-group-title">
                <i class="fas fa-mouse-pointer"></i>Interactive Elements
            </div>
            <div class="element-card" data-type="button">
                <i class="fas fa-mouse-pointer"></i>
                <div class="element-card-text">
                    <div class="element-card-title">Button</div>
                    <div class="element-card-desc">Add call-to-action buttons</div>
                </div>
            </div>
            <div class="element-card" data-type="faq">
                <i class="fas fa-question-circle"></i>
                <div class="element-card-text">
                    <div class="element-card-title">FAQ Section</div>
                    <div class="element-card-desc">Add frequently asked questions</div>
                </div>
            </div>
        </div>
    </div>

    <!-- Main Canvas Area -->
    <div class="canvas-area">
        <form id="builderForm" method="POST">
            <!-- Page Settings Card -->
            <div class="canvas-header">
                <h4 class="fw-bold mb-4" style="color: var(--primary);">
                    <i class="fas fa-cog me-2"></i>Page Settings
                </h4>
                <div class="row g-4">
                    <div class="col-md-6">
                        <label class="form-label">Page Title</label>
                        <input type="text" name="title" class="form-control" 
                               value="<?= htmlspecialchars($page['title'] ?? '') ?>" 
                               placeholder="Enter page title" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Slug URL</label>
                        <input type="text" name="slug" class="form-control" 
                               value="<?= htmlspecialchars($page['slug'] ?? '') ?>" 
                               placeholder="e.g., my-landing-page">
                    </div>
                </div>
            </div>

            <!-- Meta Integration Card -->
            <div class="page-meta">
                <div class="meta-section">
                    <div class="meta-section-title">
                        <i class="fab fa-facebook"></i>
                        Meta Pixel Settings
                    </div>
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">Pixel ID</label>
                            <input type="text" name="pixel_id" class="form-control" 
                                   value="<?= htmlspecialchars($page['meta_pixel_id'] ?? '') ?>" 
                                   placeholder="Enter your Meta Pixel ID">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Event Name</label>
                            <input type="text" name="meta_event_name" class="form-control" 
                                   value="<?= htmlspecialchars($page['meta_event_name'] ?? 'ViewContent') ?>" 
                                   placeholder="Default: ViewContent">
                        </div>
                    </div>
                </div>

                <div class="meta-section">
                    <div class="meta-section-title">
                        <i class="fas fa-code-branch"></i>
                        Conversions API Settings
                    </div>
                    <div class="row g-3">
                        <div class="col-12">
                            <label class="form-label">CAPI Access Token</label>
                            <input type="text" name="capi_access_token" class="form-control" 
                                   value="<?= htmlspecialchars($page['capi_access_token'] ?? '') ?>" 
                                   placeholder="Enter CAPI Access Token">
                        </div>
                        <div class="col-12">
                            <label class="form-label">CAPI Endpoint</label>
                            <input type="text" name="capi_endpoint" class="form-control" 
                                   value="<?= htmlspecialchars($page['capi_endpoint'] ?? '') ?>" 
                                   placeholder="Enter CAPI Endpoint URL">
                        </div>
                    </div>
                </div>
            </div>

            <!-- Page Elements Canvas -->
            <div class="mb-4">
                <h5 class="fw-bold mb-3" style="color: var(--dark);">
                    <i class="fas fa-layer-group me-2"></i>Page Elements
                    <span class="badge bg-primary ms-2" id="elementCount"><?= count($elements) ?> elements</span>
                </h5>
                
                <div id="canvasElements">
                    <?php 
                    if (empty($elements)): ?>
                        <div class="empty-state">
                            <i class="fas fa-magic"></i>
                            <h5>No elements yet</h5>
                            <p class="mb-4">Drag & drop elements from the left sidebar to start building your page</p>
                            <div class="text-muted small">
                                <i class="fas fa-lightbulb me-1"></i>
                                Tip: Click on any element to edit its properties
                            </div>
                        </div>
                    <?php else:
                        foreach ($elements as $idx => $el) {
                            $st = json_decode($el['styles'], true) ?: [];
                            echo renderElementUI($el['type'], $idx, $el['content'], $st);
                        }
                    endif; 
                    ?>
                </div>
            </div>
        </form>
    </div>

    <!-- Right Sidebar - Settings -->
    <div class="sidebar-right">
        <h6 class="section-title">
            <i class="fas fa-sliders-h me-2"></i>Element Settings
        </h6>
        <div id="settingsArea">
            <div class="empty-state" style="padding: 20px;">
                <i class="fas fa-cube" style="font-size: 2rem;"></i>
                <p class="mt-3 text-muted">Select an element to edit its properties</p>
            </div>
        </div>
    </div>
</div>

<!-- Mini Navigator -->
<div class="mini-navigator" id="miniNav">
    <div class="nav-header">
        <span><i class="fas fa-compass me-2"></i>NAVIGATOR</span>
        <i class="fas fa-chevron-up toggle-nav-icon"></i>
    </div>
    <div id="navItems"></div>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.0/Sortable.min.js"></script>
<script src="https://cdn.quilljs.com/1.3.6/quill.min.js"></script>

<script>
// Kami juga menambahkan 'false' agar user bisa mereset (menghapus) highlight.
const fullColorPalette = [
    // BARIS 1: Grayscale (Putih ke Hitam)
    "#ffffff", "#eeeeee", "#cccccc", "#999999", "#666666", "#444444", "#000000",
    
    // BARIS 2: Warna Standar (Cerah)
    "#ff0000", "#ff9900", "#ffff00", "#008a00", "#0066cc", "#9933ff", "#ffffff",
    
    // BARIS 3: Warna Soft/Pastel
    "#facccc", "#ffebcc", "#ffffcc", "#cce8cc", "#cce0f5", "#ebd6ff", "#eeeeee",
    
    // BARIS 4: Warna Gelap (Deep)
    "#a10000", "#b26b00", "#b2b200", "#006100", "#0047b2", "#6b24b2", "#333333",
    
    // BARIS 5: KHUSUS NEON (Highlight Utama)
    "#FFFF00", "#39FF14", "#00FFFF", "#FF00FF", "#FF5F1F", "#FE019A", false
];	
let quillEditors = {};
let selectedElement = null;

// Initialize Sortable
Sortable.create(document.getElementById('canvasElements'), { 
    animation: 200, 
    handle: '.drag-handle', 
    onEnd: function() {
        updateIndices();
        updateElementCount();
    }
});

$(document).on('click', '.nav-header', function() {
    $('#miniNav').toggleClass('collapsed');
    $(this).find('.toggle-nav-icon').toggleClass('fa-chevron-up fa-chevron-down');
});

$(document).on('click', '.wysiwyg-element', function(e) {
    if($(e.target).closest('.ql-editor').length > 0 || 
       $(e.target).hasClass('element-action-btn')) return;
    
    $('.wysiwyg-element').not($(this)).removeClass('selected');
    $(this).addClass('selected');
    selectedElement = $(this);
    const type = $(this).data('element-type');
    const idx = $(this).data('element-index');
    renderSettings(type, idx);
    if(type === 'header' || type === 'paragraph') initQuill(idx);
});

$('.element-card').click(function() {
    const type = $(this).data('type');
    const idx = $('#canvasElements .wysiwyg-element').length;
    
    // Hide empty state if it exists
    $('.empty-state').hide();
    
    // Default content berdasarkan tipe elemen
    let defaultContent = '';
    let defaultStyles = { bg_color: '#ffffff', text_color: '#000000', link: '#' };
    
    switch(type) {
        case 'header':
            defaultContent = '<h2>Header Baru</h2>';
            break;
        case 'paragraph':
            defaultContent = '<p>Paragraf baru... Klik untuk mengedit konten.</p>';
            break;
        case 'button':
            defaultContent = 'Klik Disini';
            break;
        case 'image':
            defaultContent = '';
            break;
        case 'youtube':
            defaultContent = '';
            break;
        case 'faq':
            defaultContent = '[]';
            break;
        case 'divider':
            defaultContent = '';
            break;
    }
    
    // Buat elemen baru dengan konten default
    const html = `
    <div class="wysiwyg-element" id="el-target-${idx}" data-element-index="${idx}" data-element-type="${type}" style="background:${defaultStyles.bg_color}; color:${defaultStyles.text_color}">
        <div class="element-badge"><span><i class="fas fa-tag me-1"></i> ${type}</span><i class="fas fa-chevron-down toggle-icon"></i></div>
        <div class="drag-handle"><i class="fas fa-grip-vertical"></i></div>
        
        <input type="hidden" name="elements[${idx}][type]" value="${type}">
        <input type="hidden" name="elements[${idx}][styles][bg_color]" class="in-bg" value="${defaultStyles.bg_color}">
        <input type="hidden" name="elements[${idx}][styles][text_color]" class="in-tx" value="${defaultStyles.text_color}">
        <input type="hidden" name="elements[${idx}][styles][link]" class="in-link" value="${defaultStyles.link}">
        
        <div class="element-content-wrapper">
            ${renderElementContent(type, defaultContent, defaultStyles)}
        </div>
        
        <textarea name="elements[${idx}][content]" class="d-none in-content">${defaultContent}</textarea>
    </div>`;
    
    $('#canvasElements').append(html); 
    updateIndices();
    updateElementCount();
    
    // Auto-select the new element
    $(`#el-target-${idx}`).click();
});

function renderElementContent(type, content, styles) {
    const bg = styles.bg_color || '#ffffff';
    const tx = styles.text_color || '#000000';
    const link = styles.link || '#';
    
    let html = '';
    
    switch(type) {
        case 'header':
        case 'paragraph':
            html = `<div class="editable-content" data-editor-index="${$('#canvasElements .wysiwyg-element').length}">`;
            if(content && content.trim() !== '') {
                html += content;
            } else {
                html += type === 'header' ? '<h2>Header Baru</h2>' : '<p>Klik untuk mengedit konten...</p>';
            }
            html += '</div>';
            break;
            
        case 'divider':
            const thickness = styles.thickness || '2px';
            const dividerStyle = styles.divider_style || 'solid';
            html = `<hr style="border-top: ${thickness} ${dividerStyle} ${tx}; margin: 20px 0;">`;
            break;
            
        case 'youtube':
            if(content && content.trim() !== '') {
                html = `<div class="ratio ratio-16x9">
                            <iframe src="https://www.youtube.com/embed/${content}" 
                                    title="YouTube video" 
                                    allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture" 
                                    allowfullscreen>
                            </iframe>
                        </div>`;
            } else {
                html = `<div class="d-flex align-items-center justify-content-center" style="background: #f1f5f9; border-radius: 8px; min-height: 200px;">
                            <div class="text-center p-4">
                                <i class="fab fa-youtube fa-3x text-danger mb-3"></i>
                                <p class="mb-0 small text-muted">Masukkan ID video YouTube</p>
                                <p class="small text-muted">Contoh: dQw4w9WgXcQ</p>
                            </div>
                        </div>`;
            }
            break;
            
        case 'image':
            if(content && content.trim() !== '') {
                const imgClass = styles.img_class || 'img-fluid rounded';
                const altText = styles.alt_text || 'Gambar';
                html = `<img src="${content}" class="${imgClass}" alt="${altText}" style="max-height: 400px; object-fit: cover;">`;
            } else {
                html = `<div class="d-flex align-items-center justify-content-center" style="background: #f1f5f9; border-radius: 8px; min-height: 200px;">
                            <div class="text-center p-4">
                                <i class="fas fa-image fa-3x text-muted mb-3"></i>
                                <p class="mb-0 small text-muted">Masukkan URL gambar</p>
                                <p class="small text-muted">Contoh: https://example.com/image.jpg</p>
                            </div>
                        </div>`;
            }
            break;
			
		case 'html':
			if(content && content.trim() !== '') {
				html = `<div class="html-preview border rounded p-3 bg-light">
							<div class="html-content">${content}</div>
							<div class="mt-2 small text-muted">
								<i class="fas fa-code me-1"></i>Custom HTML
							</div>
						</div>`;
			} else {
				html = `<div class="d-flex align-items-center justify-content-center" style="background: #f1f5f9; border-radius: 8px; min-height: 100px;">
							<div class="text-center p-4">
								<i class="fas fa-code fa-2x text-muted mb-2"></i>
								<p class="mb-0 small text-muted">Custom HTML Code</p>
							</div>
						</div>`;
			}
			break;
            
        case 'button':
            const btnSize = styles.btn_size || '';
            const btnText = content || 'Klik Disini';
            html = `<div class="text-center">
                        <a href="${link}" class="btn ${btnSize} shadow-sm" 
                           style="background:${bg}; color:${tx}; padding: 12px 30px; border-radius: 10px; font-weight: 600;">
                            ${btnText}
                        </a>
                    </div>`;
            break;
            
        case 'faq':
            try {
                const faqs = content ? JSON.parse(content) : [];
                if(faqs.length > 0) {
                    html = '<div class="faq-preview">';
                    faqs.forEach(f => {
                        html += `
                        <div class="faq-item-preview mb-2 p-2 border rounded">
                            <div class="d-flex align-items-start">
                                <span class="badge bg-primary me-2">Q</span>
                                <div class="flex-grow-1">
                                    <strong>${escapeHtml(f.q || '')}</strong>
                                    <div class="mt-1 small text-muted">
                                        <span class="badge bg-success me-1">A</span>
                                        ${escapeHtml(f.a || '')}
                                    </div>
                                </div>
                            </div>
                        </div>`;
                    });
                    html += '</div>';
                } else {
                    html = `<div class="text-center p-4 border rounded bg-light">
                                <i class="fas fa-question-circle fa-2x text-muted mb-3"></i>
                                <p class="mb-1 small text-muted">Belum ada FAQ</p>
                                <p class="mb-0 small text-muted">Tambahkan FAQ melalui panel settings</p>
                            </div>`;
                }
            } catch(e) {
                html = `<div class="text-center p-4 border rounded bg-light">
                            <i class="fas fa-exclamation-triangle fa-2x text-warning mb-3"></i>
                            <p class="mb-0 small text-muted">Format FAQ tidak valid</p>
                        </div>`;
            }
            break;
    }
    
    return html;
}

function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

function initQuill(i) {
    if(quillEditors[i]) return;
    
    const element = $(`.editable-content[data-editor-index="${i}"]`);
    if(element.length === 0) return;
    
    const q = new Quill(element[0], {
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

	// Replace blockquote button dengan icon FontAwesome
	setTimeout(() => {
		const blockquoteBtn = element.closest('.ql-toolbar').find('.ql-blockquote');
		if(blockquoteBtn.length) {
			blockquoteBtn.html('<i class="fas fa-quote-right"></i>');
		}
	}, 100);
    
    // Set initial content
    const initialContent = selectedElement.find('.in-content').val();
    if(initialContent) {
        q.root.innerHTML = initialContent;
    }
    
    q.on('text-change', () => {
        selectedElement.find('.in-content').val(q.root.innerHTML);
    });
    
    quillEditors[i] = q;
}

function renderSettings(type, idx) {
    if(!selectedElement) return;
    
    const bg = selectedElement.find('.in-bg').val();
    const tx = selectedElement.find('.in-tx').val();
    const content = selectedElement.find('.in-content').val();
    const link = selectedElement.find('.in-link').val();
    
    // Buat tab content dengan ID yang benar
    let html = `<div class="settings-tabs">
                    <div class="settings-tab active" onclick="showTab('style')">Style</div>
                    <div class="settings-tab" onclick="showTab('content')">Content</div>
                </div>`;
    
    // TAB STYLE (default: visible)
    html += `<div id="tab-style" class="settings-tab-content">
                <h6 class="fw-bold small mb-3 text-primary">
                    <i class="fas fa-palette me-2"></i>Style Settings
                </h6>
                <div class="row g-2 mb-3">
                    <div class="col-6">
                        <label class="form-label">Background Color</label>
                        <div class="color-input-group">
                            <input type="color" id="sBg" class="form-control p-1" value="${bg}" style="height: 40px;">
                            <div class="color-preview" style="background: ${bg};" onclick="$('#sBg').click()"></div>
                        </div>
                    </div>
                    <div class="col-6">
                        <label class="form-label">Text Color</label>
                        <div class="color-input-group">
                            <input type="color" id="sTx" class="form-control p-1" value="${tx}" style="height: 40px;">
                            <div class="color-preview" style="background: ${tx};" onclick="$('#sTx').click()"></div>
                        </div>
                    </div>
                </div>
            </div>`;
    
    // TAB CONTENT (default: hidden)
    html += `<div id="tab-content" class="settings-tab-content" style="display: none;">
                <h6 class="fw-bold small mb-3 text-primary">
                    <i class="fas fa-edit me-2"></i>Content Settings
                </h6>`;
    
    // KONTEN SPESIFIK BERDASARKAN TIPE ELEMEN
    if(type === 'button') {
        html += `<div class="mb-3">
                    <label class="form-label">Button Text</label>
                    <input type="text" id="sBtnText" class="form-control" value="${content || 'Klik Disini'}" placeholder="Masukkan teks tombol">
                </div>
                <div class="mb-3">
                    <label class="form-label">Button Link URL</label>
                    <input type="text" id="sBtnLink" class="form-control" value="${link || '#'}" placeholder="Masukkan URL (contoh: https://...)">
                </div>
                <div class="mb-3">
                    <label class="form-label">Button Size</label>
                    <select id="sBtnSize" class="form-select">
                        <option value="btn-sm">Small</option>
                        <option value="btn" selected>Medium</option>
                        <option value="btn-lg">Large</option>
                    </select>
                </div>`;
    } 
    else if(type === 'faq') {
        let faqs = [];
        try {
            faqs = content ? JSON.parse(content) : [];
        } catch(e) {
            faqs = [];
        }
        
        html += `<div class="alert alert-info small mb-3">
                    <i class="fas fa-info-circle me-2"></i>
                    Tambah pertanyaan dan jawaban FAQ di bawah ini
                </div>
                <div id="faqList">`;
        
        if(faqs.length > 0) {
            faqs.forEach((f, i) => {
                html += `<div class="faq-item mb-3 p-3 border rounded">
                            <div class="d-flex justify-content-between align-items-center mb-2">
                                <label class="form-label small mb-0">FAQ Item ${i+1}</label>
                                <button type="button" class="btn btn-sm btn-outline-danger" onclick="removeFaq(${i})">
                                    <i class="fas fa-times"></i> Hapus
                                </button>
                            </div>
                            <div class="mb-2">
                                <input type="text" class="form-control mb-2 f-q" value="${escapeHtml(f.q||'')}" placeholder="Masukkan pertanyaan">
                            </div>
                            <div>
                                <textarea class="form-control form-control-sm f-a" placeholder="Masukkan jawaban" rows="2">${escapeHtml(f.a||'')}</textarea>
                            </div>
                        </div>`;
            });
        } else {
            html += `<div class="text-center py-4 border rounded bg-light">
                        <i class="fas fa-question-circle fa-2x text-muted mb-3"></i>
                        <p class="mb-0 text-muted">Belum ada FAQ</p>
                        <p class="small text-muted">Klik tombol di bawah untuk menambahkan</p>
                    </div>`;
        }
        
        html += `</div>
                <button class="btn btn-primary w-100 mt-3" onclick="addFaq()">
                    <i class="fas fa-plus me-2"></i>Tambah FAQ Item
                </button>`;
    } 
    else if(type === 'youtube') {
        html += `<div class="mb-3">
                    <label class="form-label">YouTube Video ID</label>
                    <input type="text" id="sYoutubeId" class="form-control" value="${content || ''}" placeholder="Masukkan ID video (contoh: dQw4w9WgXcQ)">
                    <div class="form-text small mt-2">
                        <i class="fas fa-info-circle me-1"></i>
                        Video ID adalah bagian setelah "v=" di URL YouTube<br>
                        Contoh: https://www.youtube.com/watch?v=<strong class="text-primary">dQw4w9WgXcQ</strong>
                    </div>
                </div>
                <div class="mb-3">
                    <label class="form-label">Video Title (opsional)</label>
                    <input type="text" id="sVideoTitle" class="form-control" placeholder="Judul video">
                </div>
                <div class="mb-3">
                    <label class="form-label">Video Size</label>
                    <select id="sVideoSize" class="form-select">
                        <option value="ratio-16x9">16:9 (Standard)</option>
                        <option value="ratio-4x3">4:3 (Old TV)</option>
                        <option value="ratio-1x1">1:1 (Square)</option>
                    </select>
                </div>`;
    } 
    else if(type === 'image') {
        html += `<div class="mb-3">
                    <label class="form-label">Image URL</label>
                    <div class="input-group">
                        <input type="text" id="sImageUrl" class="form-control" value="${content || ''}" placeholder="Masukkan URL gambar">
                        <button class="btn btn-outline-secondary" type="button" onclick="browseImage()">
                            <i class="fas fa-link"></i>
                        </button>
                    </div>
                    <div class="form-text small mt-2">
                        <i class="fas fa-info-circle me-1"></i>
                        Gunakan URL lengkap (https://...) atau path relatif
                    </div>
                </div>
                <div class="mb-3">
                    <label class="form-label">Alternative Text</label>
                    <input type="text" id="sAltText" class="form-control" placeholder="Deskripsi gambar untuk SEO">
                </div>
                <div class="mb-3">
                    <label class="form-label">Image Style</label>
                    <select id="sImageStyle" class="form-select">
                        <option value="img-fluid rounded">Responsif dengan sudut membulat</option>
                        <option value="img-fluid">Responsif penuh</option>
                        <option value="img-thumbnail">Thumbnail dengan border</option>
                        <option value="img-fluid rounded-circle">Lingkaran</option>
                    </select>
                </div>
                <div class="mt-4">
                    <h6 class="small fw-bold mb-2">Contoh URL Gambar Gratis:</h6>
                    <div class="small text-muted">
                        <div class="mb-1">• https://picsum.photos/800/400</div>
                        <div class="mb-1">• https://via.placeholder.com/800x400</div>
                        <div>• https://images.unsplash.com/photo-...</div>
                    </div>
                </div>`;
    }
	else if(type === 'html') {
		html += `<div class="mb-3">
					<label class="form-label">HTML Code</label>
					<textarea id="sHtmlCode" class="form-control" rows="6" placeholder="Masukkan kode HTML di sini...">${content || ''}</textarea>
					<div class="form-text small mt-2">
						<i class="fas fa-info-circle me-1"></i>
						Masukkan kode HTML valid. Script dan style inline didukung.
					</div>
				</div>
				<div class="mb-3">
					<label class="form-label">Preview</label>
					<div id="htmlPreview" class="border rounded p-3 bg-light" style="min-height: 100px;">
						${content ? content : 'Preview akan muncul di sini...'}
					</div>
				</div>
				<div class="mt-4">
					<h6 class="small fw-bold mb-2">Contoh HTML:</h6>
					<div class="small text-muted">
						<div class="mb-1">• &lt;div class="alert alert-info"&gt;Peringatan&lt;/div&gt;</div>
						<div class="mb-1">• &lt;span class="badge bg-primary"&gt;Label&lt;/span&gt;</div>
						<div>• &lt;table&gt;...&lt;/table&gt; (tabel)</div>
					</div>
				</div>`;
	}
    else if(type === 'divider') {
        html += `<div class="mb-3">
                    <label class="form-label">Divider Style</label>
                    <select id="sDividerStyle" class="form-select">
                        <option value="solid">Garis Solid</option>
                        <option value="dashed">Garis Putus-putus</option>
                        <option value="dotted">Garis Titik-titik</option>
                        <option value="double">Garis Double</option>
                    </select>
                </div>
                <div class="mb-3">
                    <label class="form-label">Line Thickness: <span id="thicknessValue">2px</span></label>
                    <input type="range" id="sDividerThickness" class="form-range" min="1" max="10" value="2">
                </div>
                <div class="mb-3">
                    <label class="form-label">Margin Atas/Bawah</label>
                    <input type="range" id="sDividerMargin" class="form-range" min="0" max="50" value="20">
                    <div class="form-text small">Spasi: <span id="marginValue">20px</span></div>
                </div>`;
    }
    else if(['header','paragraph'].includes(type)) {
        html += `<div class="alert alert-info small">
                    <i class="fas fa-info-circle me-2"></i>
                    Edit konten langsung di elemen menggunakan editor teks
                </div>
                <div class="mb-3">
                    <label class="form-label">Text Alignment</label>
                    <select id="sTextAlign" class="form-select">
                        <option value="left">Rata Kiri</option>
                        <option value="center" selected>Rata Tengah</option>
                        <option value="right">Rata Kanan</option>
                        <option value="justify">Rata Kiri-Kanan</option>
                    </select>
                </div>`;
    }
    
    // Tutup tab content
    html += `</div>`;
    
    // Action buttons
    html += `<div class="action-buttons mt-4">
				<button type="button" class="btn-action btn-export" onclick="window.location.href='export_html.php?id=<?= $page_id ?>'">
					<i class="fas fa-file-export me-2"></i>Export
				</button>
                <button type="button" class="btn-action btn-duplicate" onclick="duplicateElement()">
                    <i class="fas fa-copy me-2"></i>Duplicate
                </button>
                <button type="button" class="btn-action btn-delete" onclick="deleteElement()">
                    <i class="fas fa-trash me-2"></i>Delete
                </button>
            </div>`;
    
    // Render ke settings area
    $('#settingsArea').html(html);
    
    // Setup event listeners berdasarkan tipe
    setupEventListeners(type);
}

// Fungsi untuk setup event listeners
function setupEventListeners(type) {
    // Background color
    $('#sBg').on('input', function() { 
        let v = $(this).val(); 
        selectedElement.find('.in-bg').val(v); 
        selectedElement.css('background', v); 
        if(type==='button') selectedElement.find('.btn').css('color', v); 
        $('.color-preview').first().css('background', v);
    });
    
    // Text color
    $('#sTx').on('input', function() { 
        let v = $(this).val(); 
        selectedElement.find('.in-tx').val(v); 
        if(type==='divider') selectedElement.find('hr').css('border-color', v); 
        else if(type==='button') selectedElement.find('.btn').css('background', v); 
        else selectedElement.css('color', v); 
        $('.color-preview').last().css('background', v);
    });
    
    // Konten spesifik berdasarkan tipe
    switch(type) {
        case 'button':
            // Button text
            $('#sBtnText').on('input', function() { 
                let v = $(this).val() || 'Klik Disini'; 
                selectedElement.find('.in-content').val(v);
                selectedElement.find('.btn').text(v);
            });
            
            // Button link
            $('#sBtnLink').on('input', function() { 
                let v = $(this).val() || '#'; 
                selectedElement.find('.in-link').val(v); 
                selectedElement.find('.btn').attr('href', v); 
            });
            
            // Button size
            $('#sBtnSize').on('change', function() {
                const btn = selectedElement.find('.btn');
                btn.removeClass('btn-sm btn-lg').addClass($(this).val());
            });
            break;
            
        case 'image':
            // Image URL
            $('#sImageUrl').on('input', function() { 
                let v = $(this).val(); 
                selectedElement.find('.in-content').val(v);
                
                if(v) {
                    selectedElement.find('img').attr('src', v).removeClass('d-none');
                    selectedElement.find('.d-flex').addClass('d-none');
                } else {
                    selectedElement.find('img').addClass('d-none');
                    selectedElement.find('.d-flex').removeClass('d-none');
                }
            });
            
            // Image style
            $('#sImageStyle').on('change', function() {
                const img = selectedElement.find('img');
                img.removeClass('img-fluid img-thumbnail rounded rounded-circle');
                img.addClass($(this).val());
            });
            break;
            
        case 'youtube':
            // YouTube ID
            $('#sYoutubeId').on('input', function() { 
                let v = $(this).val(); 
                selectedElement.find('.in-content').val(v);
                
                if(v) {
                    const iframe = selectedElement.find('iframe');
                    iframe.attr('src', 'https://www.youtube.com/embed/' + v).removeClass('d-none');
                    selectedElement.find('.d-flex').addClass('d-none');
                } else {
                    selectedElement.find('iframe').addClass('d-none');
                    selectedElement.find('.d-flex').removeClass('d-none');
                }
            });
            
            // Video size
            $('#sVideoSize').on('change', function() {
                const container = selectedElement.find('.ratio');
                container.removeClass('ratio-16x9 ratio-4x3 ratio-1x1');
                container.addClass($(this).val());
            });
            break;
            
        case 'divider':
            // Divider thickness
            $('#sDividerThickness').on('input', function() {
                const thickness = $(this).val();
                $('#thicknessValue').text(thickness + 'px');
                selectedElement.find('hr').css('border-width', thickness + 'px');
            });
            
            // Divider style
            $('#sDividerStyle').on('change', function() {
                selectedElement.find('hr').css('border-style', $(this).val());
            });
            
            // Divider margin
            $('#sDividerMargin').on('input', function() {
                const margin = $(this).val();
                $('#marginValue').text(margin + 'px');
                selectedElement.find('hr').css('margin', margin + 'px 0');
            });
            break;
            
        case 'faq':
            // FAQ inputs
            $('.f-q, .f-a').on('input', function() { 
                saveFaq(); 
            });
            break;
		
		case 'html':
			$('#sHtmlCode').on('input', function() { 
				let v = $(this).val(); 
				selectedElement.find('.in-content').val(v);

				// Update preview
				$('#htmlPreview').html(v || 'Preview akan muncul di sini...');

				// Update element preview
				const htmlPreview = selectedElement.find('.html-content');
				if(htmlPreview.length) {
					htmlPreview.html(v || '<div class="text-center p-3"><i class="fas fa-code"></i> Custom HTML</div>');
				}
			});
			break;
            
        case 'header':
        case 'paragraph':
            // Text alignment
            $('#sTextAlign').on('change', function() {
                selectedElement.find('.editable-content').css('text-align', $(this).val());
            });
            break;
    }
}
	
// Fungsi untuk browse image (placeholder)
function browseImage() {
    // Daftar gambar placeholder untuk testing
    const sampleImages = [
        'https://picsum.photos/800/400',
        'https://picsum.photos/600/400',
        'https://picsum.photos/400/400',
        'https://via.placeholder.com/800x400/4a90e2/ffffff',
        'https://via.placeholder.com/600x300/50c878/ffffff',
        'https://via.placeholder.com/400x200/f39c12/ffffff',
        'https://images.unsplash.com/photo-1546069901-ba9599a7e63c',
        'https://images.unsplash.com/photo-1565299624946-b28f40a0ae38',
        'https://images.unsplash.com/photo-1565958011703-44f9829ba187'
    ];
    
    // Buat modal sederhana untuk pilih gambar
    let modalHtml = `
    <div class="modal fade" id="imageModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Pilih Gambar</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-2">
                        <div class="col-12 mb-3">
                            <label>Atau masukkan URL manual:</label>
                            <div class="input-group">
                                <input type="text" id="customImageUrl" class="form-control" placeholder="https://example.com/image.jpg">
                                <button class="btn btn-primary" onclick="useCustomImage()">Gunakan</button>
                            </div>
                        </div>
                        ${sampleImages.map((url, index) => `
                            <div class="col-md-4">
                                <div class="card image-select-card" data-url="${url}" style="cursor: pointer;">
                                    <img src="${url}" class="card-img-top" style="height: 120px; object-fit: cover;">
                                    <div class="card-body p-2">
                                        <small class="text-muted">Gambar ${index + 1}</small>
                                    </div>
                                </div>
                            </div>
                        `).join('')}
                    </div>
                </div>
            </div>
        </div>
    </div>`;
    
    // Tambahkan modal ke body jika belum ada
    if($('#imageModal').length === 0) {
        $('body').append(modalHtml);
        
        // Event listener untuk pilih gambar
        $(document).on('click', '.image-select-card', function() {
            const url = $(this).data('url');
            $('#sImageUrl').val(url).trigger('input');
            $('#imageModal').modal('hide');
        });
    }
    
    // Tampilkan modal
    $('#imageModal').modal('show');
}

function useCustomImage() {
    const url = $('#customImageUrl').val();
    if(url) {
        $('#sImageUrl').val(url).trigger('input');
        $('#imageModal').modal('hide');
    }
}

// Fungsi untuk update FAQ preview
function updateFaqPreview() {
    if(!selectedElement) return;
    
    const content = selectedElement.find('.in-content').val();
    let faqs = [];
    try {
        faqs = content ? JSON.parse(content) : [];
    } catch(e) {
        faqs = [];
    }
    
    let previewHtml = '';
    if(faqs.length > 0) {
        faqs.forEach(f => {
            previewHtml += `
            <div class="faq-item-preview mb-2 p-2 border rounded">
                <div class="d-flex align-items-start">
                    <span class="badge bg-primary me-2">Q</span>
                    <div class="flex-grow-1">
                        <strong>${escapeHtml(f.q || '')}</strong>
                        <div class="mt-1 small text-muted">
                            <span class="badge bg-success me-1">A</span>
                            ${escapeHtml(f.a || '')}
                        </div>
                    </div>
                </div>
            </div>`;
        });
    } else {
        previewHtml = `
        <div class="text-center p-4 border rounded bg-light">
            <i class="fas fa-question-circle fa-2x text-muted mb-3"></i>
            <p class="mb-1 small text-muted">Belum ada FAQ</p>
            <p class="mb-0 small text-muted">Tambahkan FAQ melalui panel settings</p>
        </div>`;
    }
    
    selectedElement.find('.faq-preview').html(previewHtml);
}

// Perbaikan fungsi saveFaq
function saveFaq(newC = null) {
    if(!selectedElement) return;
    
    let c = newC;
    if(!c) {
        c = []; 
        $('#faqList .faq-item').each(function() {
            c.push({ 
                q: $(this).find('.f-q').val(), 
                a: $(this).find('.f-a').val() 
            });
        });
    }
    
    selectedElement.find('.in-content').val(JSON.stringify(c));
    updateFaqPreview();
    
    // Jika ada FAQ baru, refresh settings
    if(newC) {
        const idx = selectedElement.data('element-index');
        const type = selectedElement.data('element-type');
        renderSettings(type, idx);
    }
}

function showTab(tabName) {
    // Sembunyikan semua tab content
    $('.settings-tab-content').hide();
    
    // Tampilkan tab yang dipilih
    $(`#tab-${tabName}`).show();
    
    // Update active tab
    $('.settings-tab').removeClass('active');
    
    // Temukan tab berdasarkan teksnya
    $('.settings-tab').each(function() {
        if($(this).text().trim().toLowerCase() === tabName.toLowerCase()) {
            $(this).addClass('active');
        }
    });
}

function addFaq() { 
    let c = [];
    try {
        c = JSON.parse(selectedElement.find('.in-content').val() || '[]'); 
    } catch(e) {
        c = [];
    }
    c.push({q:'', a:''}); 
    saveFaq(c); 
}

function removeFaq(i) { 
    let c = [];
    try {
        c = JSON.parse(selectedElement.find('.in-content').val() || '[]'); 
    } catch(e) {
        c = [];
    }
    c.splice(i, 1); 
    saveFaq(c); 
}

function saveFaq(newC = null) {
    let c = newC;
    if(!c) {
        c = []; 
        $('#faqList .faq-item').each(function() {
            c.push({ 
                q: $(this).find('.f-q').val(), 
                a: $(this).find('.f-a').val() 
            });
        });
    }
    
    selectedElement.find('.in-content').val(JSON.stringify(c));
    
    // Update preview
    let previewHtml = '';
    if(c.length > 0) {
        c.forEach(f => {
            previewHtml += `<div class="faq-item-preview"><b>Q:</b> ${escapeHtml(f.q||'')}<br><small>A: ${escapeHtml(f.a||'')}</small></div>`;
        });
    } else {
        previewHtml = `<div class="text-center p-3">
                        <i class="fas fa-question-circle fa-lg text-muted mb-2"></i>
                        <p class="mb-0 small text-muted">Klik untuk menambahkan FAQ</p>
                      </div>`;
    }
    
    selectedElement.find('.faq-preview').html(previewHtml);
    if(newC) renderSettings('faq', selectedElement.data('element-index'));
}

function duplicateElement() {
    if(!selectedElement) return;
    const idx = $('#canvasElements .wysiwyg-element').length;
    const type = selectedElement.data('element-type');
    const content = selectedElement.find('.in-content').val();
    const bg = selectedElement.find('.in-bg').val();
    const tx = selectedElement.find('.in-tx').val();
    const link = selectedElement.find('.in-link').val();
    
    const st = { bg_color: bg, text_color: tx, link: link };
    const html = `
    <div class="wysiwyg-element" id="el-target-${idx}" data-element-index="${idx}" data-element-type="${type}" style="background:${bg}; color:${tx}">
        <div class="element-badge"><span><i class="fas fa-tag me-1"></i> ${type}</span><i class="fas fa-chevron-down toggle-icon"></i></div>
        <div class="drag-handle"><i class="fas fa-grip-vertical"></i></div>
        
        <input type="hidden" name="elements[${idx}][type]" value="${type}">
        <input type="hidden" name="elements[${idx}][styles][bg_color]" class="in-bg" value="${bg}">
        <input type="hidden" name="elements[${idx}][styles][text_color]" class="in-tx" value="${tx}">
        <input type="hidden" name="elements[${idx}][styles][link]" class="in-link" value="${link}">
        
        <div class="element-content-wrapper">
            ${renderElementContent(type, content, st)}
        </div>
        
        <textarea name="elements[${idx}][content]" class="d-none in-content">${content}</textarea>
    </div>`;
    
    $('#canvasElements').append(html);
    updateIndices();
    updateElementCount();
}

function deleteElement() { 
    if(confirm('Are you sure you want to delete this element?')) { 
        selectedElement.remove(); 
        $('#settingsArea').html('<div class="empty-state" style="padding: 20px;"><i class="fas fa-cube" style="font-size: 2rem;"></i><p class="mt-3 text-muted">Select an element to edit its properties</p></div>'); 
        updateIndices();
        updateElementCount();
        
        // Show empty state if no elements left
        if($('#canvasElements .wysiwyg-element').length === 0) {
            $('#canvasElements').html(`
                <div class="empty-state">
                    <i class="fas fa-magic"></i>
                    <h5>No elements yet</h5>
                    <p class="mb-4">Drag & drop elements from the left sidebar to start building your page</p>
                    <div class="text-muted small">
                        <i class="fas fa-lightbulb me-1"></i>
                        Tip: Click on any element to edit its properties
                    </div>
                </div>
            `);
        }
    } 
}

function updateIndices() {
    $('#canvasElements .wysiwyg-element').each(function(i) {
        $(this).attr({'id': 'el-target-'+i, 'data-element-index': i});
        $(this).find('[name]').each(function() { 
            $(this).attr('name', $(this).attr('name').replace(/elements\[\d+\]/, `elements[${i}]`)); 
        });
        $(this).find('.editable-content').attr('data-editor-index', i);
    });
    updateNav();
}

function updateNav() {
    const nav = $('#navItems'); 
    nav.empty();
    
    const elements = $('#canvasElements .wysiwyg-element');
    const total = elements.length;

    elements.each(function(i) {
        const type = $(this).data('element-type');
        const icons = {
            'header': 'fas fa-heading',
            'paragraph': 'fas fa-paragraph',
            'image': 'fas fa-image',
            'youtube': 'fab fa-youtube',
            'divider': 'fas fa-grip-lines',
            'button': 'fas fa-mouse-pointer',
            'faq': 'fas fa-question-circle',
            'html': 'fas fa-code'
        };
        
        nav.append(`
            <div class="nav-item d-flex align-items-center justify-content-between py-2 border-bottom">
                <div onclick="scrollToElement(${i})" class="flex-grow-1" style="cursor:pointer">
                    <i class="${icons[type]} me-2" style="font-size: 0.7rem;"></i>
                    <span class="small">${type.charAt(0).toUpperCase() + type.slice(1)} #${i+1}</span>
                </div>
                <div class="nav-controls d-flex gap-1">
                    <button type="button" class="btn btn-xs p-0 px-1 border-0" onclick="moveInNav(${i}, -1)" ${i === 0 ? 'disabled style="opacity:0.3"' : ''}>
                        <i class="fas fa-chevron-up text-primary" style="font-size: 0.7rem;"></i>
                    </button>
                    <button type="button" class="btn btn-xs p-0 px-1 border-0" onclick="moveInNav(${i}, 1)" ${i === total - 1 ? 'disabled style="opacity:0.3"' : ''}>
                        <i class="fas fa-chevron-down text-primary" style="font-size: 0.7rem;"></i>
                    </button>
                </div>
            </div>
        `);
    });
}

function moveInNav(index, direction) {
    const elements = $('#canvasElements .wysiwyg-element');
    const target = elements.eq(index);
    
    if (direction === -1 && index > 0) {
        // Pindah ke atas (sebelum elemen sebelumnya)
        target.insertBefore(elements.eq(index - 1));
    } else if (direction === 1 && index < elements.length - 1) {
        // Pindah ke bawah (setelah elemen sesudahnya)
        target.insertAfter(elements.eq(index + 1));
    }
    
    // Sinkronisasi ulang urutan
    updateIndices();
    updateNav();
    
    // Otomatis scroll ke elemen yang baru dipindah agar tetap terlihat
    scrollToElement(index + direction);
}

function updateElementCount() {
    const count = $('#canvasElements .wysiwyg-element').length;
    $('#elementCount').text(count + ' element' + (count !== 1 ? 's' : ''));
}

function scrollToElement(i) {
    const el = document.getElementById('el-target-'+i);
    if(el) {
        $(el).click();
        el.scrollIntoView({behavior: 'smooth', block: 'center'});
    }
}

$(document).ready(function() {
    updateNav();
    updateElementCount();
    
    // Auto-scroll to last edited element if saved
    const urlParams = new URLSearchParams(window.location.search);
    if(urlParams.has('saved')) {
        setTimeout(() => {
            const lastEl = $('#canvasElements .wysiwyg-element').last();
            if(lastEl.length) {
                lastEl.click();
                lastEl[0].scrollIntoView({behavior: 'smooth', block: 'center'});
            }
        }, 500);
    }
	
	// Event listener untuk tab settings
    $(document).on('click', '.settings-tab', function() {
        const tabName = $(this).text().trim().toLowerCase();
        showTab(tabName);
    });
});
</script>
</body>
</html>