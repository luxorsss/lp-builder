<?php
require_once "../includes/config.php";
require_once "../includes/auth.php";
require_once "../includes/functions.php";

requireLogin();
$user = getCurrentUser($pdo);

$page_id = isset($_GET["id"]) ? (int) $_GET["id"] : 0;
$error = "";
$success = "";

// Ambil daftar profil pixel
$stmt = $pdo->prepare("SELECT id, name FROM pixel_profiles WHERE user_id = ? ORDER BY name ASC");
$stmt->execute([$user['id']]);
$pixels_list = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Ambil daftar project/folder untuk dropdown
$stmt = $pdo->prepare("SELECT id, name FROM projects WHERE user_id = ? ORDER BY name ASC");
$stmt->execute([$user['id']]);
$projects_list = $stmt->fetchAll(PDO::FETCH_ASSOC);

// --- LOGIKA SIMPAN (POST) ---
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $title = trim($_POST["title"]);
    $slug = trim($_POST["slug"]);
    $pixel_id = trim($_POST["pixel_id"]);
    $capi_endpoint = trim($_POST["capi_endpoint"]);
    $capi_token = trim($_POST["capi_access_token"]);
    $event_name = trim($_POST["meta_event_name"]);
    $project_id = !empty($_POST['project_id']) ? (int)$_POST['project_id'] : null;

    // Tangkap data Pure HTML
    $is_pure_html = isset($_POST["is_pure_html"]) ? 1 : 0;
    $pure_html_content = $_POST["pure_html_content"] ?? "";

    try {
        $pdo->beginTransaction();
        if ($page_id > 0) {
            $stmt = $pdo->prepare(
                "UPDATE landing_pages SET project_id=?, title=?, slug=?, meta_pixel_id=?, capi_endpoint=?, capi_access_token=?, meta_event_name=?, is_pure_html=?, pure_html_content=? WHERE id=? AND user_id=?",
            );
            $stmt->execute([
                $project_id, $title, $slug, $pixel_id, $capi_endpoint, $capi_token, $event_name, $is_pure_html, $pure_html_content, $page_id, $user["id"],
            ]);
        } else {
            $stmt = $pdo->prepare(
                "INSERT INTO landing_pages (user_id, project_id, title, slug, meta_pixel_id, capi_endpoint, capi_access_token, meta_event_name, status, is_pure_html, pure_html_content) VALUES (?,?,?,?,?,?,?,?,'draft',?,?)",
            );
            $stmt->execute([
                $user["id"], $project_id, $title, $slug, $pixel_id, $capi_endpoint, $capi_token, $event_name, $is_pure_html, $pure_html_content,
            ]);
            $page_id = $pdo->lastInsertId();
        }

        $pdo->prepare("DELETE FROM page_elements WHERE page_id=?")->execute([$page_id]);
        if (isset($_POST["elements"]) && empty($is_pure_html)) {
            foreach ($_POST["elements"] as $idx => $el) {
                $stmt = $pdo->prepare("INSERT INTO page_elements (page_id, type, content, order_position, styles) VALUES (?,?,?,?,?)");
                $stmt->execute([
                    $page_id, $el["type"], $el["content"], $idx, json_encode($el["styles"]),
                ]);
            }
        }
        $pdo->commit();
        header("Location: builder.php?id=$page_id&saved=1");
        exit();
    } catch (Exception $e) {
        $pdo->rollBack();
        $error = $e->getMessage();
    }
}

$page = $page_id > 0 ? getUserLandingPage($pdo, $page_id, $user["id"]) : null;
$elements = [];
if ($page) {
    $stmt = $pdo->prepare("SELECT * FROM page_elements WHERE page_id=? ORDER BY order_position ASC");
    $stmt->execute([$page_id]);
    $elements = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// --- FUNGSI RENDER UI UNTUK CANVAS ---
function renderElementUI($type, $idx, $content, $st)
{
    $bg = $st["bg_color"] ?? "#ffffff";
    $tx = $st["text_color"] ?? "#191b23";
    $link = $st["link"] ?? "#";

    $html = '<div class="wysiwyg-element relative group border border-transparent hover:border-outline-variant hover:border-dashed rounded-lg transition-all p-4 mb-4" id="el-target-' . $idx . '" data-element-index="' . $idx . '" data-element-type="' . $type . '" style="background:' . $bg . '; color:' . $tx . '">';
    
    // Drag Handle & Badge
    $html .= '<div class="absolute -left-4 top-1/2 -translate-y-1/2 flex flex-col gap-1 opacity-0 group-hover:opacity-100 transition-opacity z-10 drag-handle cursor-grab">';
    $html .= '<button type="button" class="w-6 h-6 bg-surface-container-lowest border border-outline-variant rounded shadow flex items-center justify-center text-on-surface hover:text-primary hover:border-primary"><span class="material-symbols-outlined text-[16px]">drag_indicator</span></button>';
    $html .= '</div>';
    
    $html .= '<div class="absolute -top-3 left-2 bg-primary text-on-primary text-[10px] font-bold px-2 py-0.5 rounded opacity-0 group-hover:opacity-100 transition-opacity pointer-events-none uppercase tracking-wider">' . $type . '</div>';

    // Hidden Inputs
    $html .= '<input type="hidden" name="elements[' . $idx . '][type]" value="' . $type . '">';
    $html .= '<input type="hidden" name="elements[' . $idx . '][styles][bg_color]" class="in-bg" value="' . $bg . '">';
    $html .= '<input type="hidden" name="elements[' . $idx . '][styles][text_color]" class="in-tx" value="' . $tx . '">';
    $html .= '<input type="hidden" name="elements[' . $idx . '][styles][link]" class="in-link" value="' . $link . '">';

    $html .= '<div class="element-content-wrapper w-full">';

    $defaultContent = "";
    switch ($type) {
        case "header": $defaultContent = '<h1 class="text-display-lg font-display-lg uppercase font-bold text-center">Header Baru</h1>'; break;
        case "paragraph": $defaultContent = '<p class="text-body-md text-center">Paragraf baru... Klik untuk mengedit konten.</p>'; break;
        case "html": $defaultContent = '<div class="bg-surface-container-low border border-outline-variant border-dashed rounded-lg p-6 flex flex-col items-center justify-center text-outline"><span class="material-symbols-outlined text-[32px] mb-2">code</span><span class="text-body-sm">Custom HTML Block</span></div>'; break;
        case "button": $defaultContent = "Klik Disini"; break;
    }

    $displayContent = !empty($content) ? $content : $defaultContent;

    if ($type == "header" || $type == "paragraph") {
        $html .= '<div class="editable-content focus:outline-none focus:ring-2 focus:ring-primary/20 rounded p-2" data-editor-index="' . $idx . '">';
        $html .= !empty($content) ? htmlspecialchars_decode($content) : $defaultContent;
        $html .= "</div>";
    } elseif ($type == "divider") {
        $html .= '<div class="py-4"><hr style="border-top:2px solid ' . $tx . '"></div>';
    } elseif ($type == "youtube") {
        if (!empty($content)) {
            $html .= '<div class="relative w-full overflow-hidden" style="padding-top: 56.25%;"><iframe class="absolute top-0 left-0 w-full h-full rounded-lg shadow-sm" src="https://www.youtube.com/embed/' . $content . '" allowfullscreen></iframe></div>';
        } else {
            $html .= '<div class="bg-surface-container-low border border-outline-variant border-dashed rounded-lg p-8 flex flex-col items-center justify-center text-outline"><span class="material-symbols-outlined text-[40px] text-error mb-2">smart_display</span><p class="text-body-sm font-medium text-on-surface-variant">Masukkan ID Video YouTube di Panel Kanan</p></div>';
        }
    } elseif ($type == "image") {
        if (!empty($content)) {
            $html .= '<img src="' . $content . '" class="w-full h-auto rounded-lg shadow-sm" style="max-height:500px; object-fit:contain;">';
        } else {
            $html .= '<div class="bg-surface-container-low border border-outline-variant border-dashed rounded-lg p-8 flex flex-col items-center justify-center text-outline"><span class="material-symbols-outlined text-[40px] mb-2">image</span><p class="text-body-sm font-medium text-on-surface-variant">Masukkan URL Gambar di Panel Kanan</p></div>';
        }
    } elseif ($type == "html") {
        $html .= '<div class="html-preview">';
        $html .= !empty($content) ? '<div class="html-content">' . htmlspecialchars_decode($content) . '</div>' : $defaultContent;
        $html .= "</div>";
    } elseif ($type == "button") {
        $html .= '<div class="text-center py-4">';
        $html .= '<a href="' . $link . '" class="inline-block shadow-sm transition-transform hover:-translate-y-0.5" style="background:' . $bg . '; color:' . $tx . '; padding: 12px 32px; border-radius: 9999px; font-weight: 700; text-decoration:none;">';
        $html .= $displayContent;
        $html .= '</a></div>';
    } elseif ($type == "faq") {
        $faqs = !empty($content) ? json_decode($content, true) : [];
        $html .= '<div class="faq-preview w-full max-w-3xl mx-auto space-y-3">';
        if (!empty($faqs)) {
            foreach ($faqs as $f) {
                $html .= '<div class="bg-surface-container-lowest border border-outline-variant rounded-lg p-4 shadow-sm">';
                $html .= '<h3 class="font-bold text-on-surface mb-2 flex gap-2"><span class="text-primary">Q:</span> ' . htmlspecialchars($f["q"] ?? "") . '</h3>';
                $html .= '<p class="text-on-surface-variant text-body-sm flex gap-2"><span class="text-success font-bold">A:</span> ' . htmlspecialchars($f["a"] ?? "") . '</p>';
                $html .= '</div>';
            }
        } else {
            $html .= '<div class="bg-surface-container-low border border-outline-variant border-dashed rounded-lg p-6 flex flex-col items-center justify-center text-outline"><span class="material-symbols-outlined text-[32px] mb-2">quiz</span><p class="text-body-sm font-medium text-on-surface-variant">Tambahkan FAQ di Panel Kanan</p></div>';
        }
        $html .= "</div>";
    }
    $html .= "</div>";

    $html .= '<textarea name="elements[' . $idx . '][content]" class="hidden in-content">' . htmlspecialchars($content) . '</textarea>';
    $html .= "</div>";

    return $html;
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8"/>
    <meta content="width=device-width, initial-scale=1.0" name="viewport"/>
    <title>Visual Editor - LP Builder Pro</title>
    <script src="https://cdn.tailwindcss.com?plugins=forms,container-queries"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet"/>
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&display=swap" rel="stylesheet"/>
    <link href="https://cdn.quilljs.com/1.3.6/quill.snow.css" rel="stylesheet">
    <script id="tailwind-config">
        tailwind.config = {
            darkMode: "class",
            theme: {
                extend: {
                    colors: {
                        "primary-fixed": "#dbe1ff", "on-error-container": "#93000a", "inverse-surface": "#2e3039",
                        "surface": "#faf8ff", "primary": "#004ac6", "surface-tint": "#0053db",
                        "surface-container-highest": "#e1e2ed", "error": "#ba1a1a",
                        "surface-container-high": "#e7e7f3", "primary-container": "#2563eb",
                        "secondary-container": "#dae2fd", "inverse-primary": "#b4c5ff",
                        "on-primary-fixed-variant": "#003ea8", "outline": "#737686",
                        "surface-container": "#ededf9", "surface-container-lowest": "#ffffff",
                        "surface-container-low": "#f3f3fe", "surface-variant": "#e1e2ed",
                        "secondary": "#565e74", "on-primary-container": "#eeefff",
                        "on-surface": "#191b23", "outline-variant": "#c3c6d7",
                        "on-surface-variant": "#434655", "on-primary": "#ffffff", "surface-dim": "#d9d9e5"
                    },
                    spacing: { "sidebar-width": "260px", "editor-inspector-width": "320px", "gutter": "24px" },
                    fontFamily: { "body-sm": ["Inter"], "label-uppercase": ["Inter"], "display-lg": ["Inter"], "body-md": ["Inter"], "headline-md": ["Inter"], "title-sm": ["Inter"] }
                }
            }
        }
    </script>
    <style>
        .material-symbols-outlined { font-variation-settings: 'FILL' 0, 'wght' 400, 'GRAD' 0, 'opsz' 24; }
        
        /* FIX: Elevasi Z-Index agar Dropdown Quill tidak tenggelam / tertutup elemen di bawahnya */
        .wysiwyg-element { z-index: 1; position: relative; }
        .wysiwyg-element.selected { border-color: #004ac6; background-color: #f3f3fe; z-index: 50 !important; }
        
        /* FIX: Proteksi dan style Dropdown Quill di lingkungan Tailwind */
        .ql-toolbar { border-radius: 0.5rem 0.5rem 0 0; border-color: #c3c6d7 !important; background: white; }
        .ql-container { border-radius: 0 0 0.5rem 0.5rem; border-color: #c3c6d7 !important; min-height: 100px; background: white; }
        .ql-picker-options { z-index: 9999 !important; border: 1px solid #c3c6d7 !important; box-shadow: 0 4px 6px rgba(0,0,0,0.1) !important; background: white !important; }
        
        .sortable-ghost { opacity: 0.4; background-color: #e1e2ed; }
        .canvas-scroll::-webkit-scrollbar { width: 8px; }
        .canvas-scroll::-webkit-scrollbar-track { background: transparent; }
        .canvas-scroll::-webkit-scrollbar-thumb { background-color: #c3c6d7; border-radius: 20px; }
    </style>
</head>
<body class="bg-background text-on-background font-body-md h-screen flex flex-col overflow-hidden">

<header class="bg-surface-container-lowest flex justify-between items-center w-full px-gutter h-16 shadow-sm z-50 shrink-0 border-b border-outline-variant">
    <div class="flex items-center gap-4">
        <a href="../index.php" class="text-on-surface-variant hover:bg-surface-container-low transition-colors p-2 rounded-full flex items-center justify-center" title="Kembali ke Dashboard">
            <span class="material-symbols-outlined">arrow_back</span>
        </a>
        <div class="flex items-center gap-2">
            <span class="material-symbols-outlined text-primary text-[24px]">view_quilt</span>
            <span class="text-[18px] font-bold text-primary">LP Builder Pro</span>
        </div>
        <div class="h-6 w-px bg-outline-variant mx-2"></div>
        <span class="text-[14px] text-on-surface-variant">Editing: <span class="font-bold text-on-surface"><?= $page ? htmlspecialchars($page["title"]) : "Halaman Baru" ?></span></span>
    </div>
    
    <div class="flex items-center gap-3">
        <?php if ($page): ?>
            <a href="export_html.php?id=<?= $page_id ?>" class="px-4 py-2 text-[14px] font-medium rounded-lg border border-outline-variant text-on-surface-variant hover:bg-surface-container-low transition-colors flex items-center gap-2">
                <span class="material-symbols-outlined text-[18px]">download</span> Export HTML
            </a>
            <a href="../preview.php?id=<?= $page_id ?>" target="_blank" class="px-4 py-2 text-[14px] font-medium rounded-lg border border-outline-variant text-on-surface-variant hover:bg-surface-container-low transition-colors flex items-center gap-2">
                <span class="material-symbols-outlined text-[18px]">visibility</span> Pratinjau
            </a>
        <?php endif; ?>
        <button type="button" onclick="document.getElementById('builderForm').submit();" class="px-5 py-2 text-[14px] font-medium rounded-lg bg-primary text-on-primary hover:bg-primary-container transition-colors flex items-center gap-2 shadow-sm">
            <span class="material-symbols-outlined text-[18px]">save</span> Simpan
        </button>
    </div>
</header>

<div class="flex flex-1 overflow-hidden">
    
    <aside class="w-sidebar-width bg-surface-container-lowest shadow-[1px_0_3px_rgba(0,0,0,0.05)] z-40 flex flex-col h-full shrink-0 border-r border-outline-variant">
        <div class="p-4 border-b border-outline-variant">
            <h2 class="text-[16px] font-semibold text-on-surface">Elemen Halaman</h2>
        </div>
        <div class="p-4 overflow-y-auto flex-1 space-y-6">
            
            <div>
                <h3 class="text-[12px] font-bold text-on-surface-variant mb-3 uppercase tracking-wider">Teks</h3>
                <div class="grid grid-cols-2 gap-2">
                    <div class="element-card bg-surface-container-low border border-outline-variant rounded-lg p-3 flex flex-col items-center justify-center gap-2 cursor-pointer hover:border-primary hover:text-primary transition-all group" data-type="header">
                        <span class="material-symbols-outlined text-secondary group-hover:text-primary transition-colors">title</span>
                        <span class="text-[12px] text-center font-medium">Header</span>
                    </div>
                    <div class="element-card bg-surface-container-low border border-outline-variant rounded-lg p-3 flex flex-col items-center justify-center gap-2 cursor-pointer hover:border-primary hover:text-primary transition-all group" data-type="paragraph">
                        <span class="material-symbols-outlined text-secondary group-hover:text-primary transition-colors">subject</span>
                        <span class="text-[12px] text-center font-medium">Paragraph</span>
                    </div>
                </div>
            </div>
            
            <div>
                <h3 class="text-[12px] font-bold text-on-surface-variant mb-3 uppercase tracking-wider">Media</h3>
                <div class="grid grid-cols-2 gap-2">
                    <div class="element-card bg-surface-container-low border border-outline-variant rounded-lg p-3 flex flex-col items-center justify-center gap-2 cursor-pointer hover:border-primary hover:text-primary transition-all group" data-type="image">
                        <span class="material-symbols-outlined text-secondary group-hover:text-primary transition-colors">image</span>
                        <span class="text-[12px] text-center font-medium">Image</span>
                    </div>
                    <div class="element-card bg-surface-container-low border border-outline-variant rounded-lg p-3 flex flex-col items-center justify-center gap-2 cursor-pointer hover:border-primary hover:text-primary transition-all group" data-type="youtube">
                        <span class="material-symbols-outlined text-secondary group-hover:text-primary transition-colors">smart_display</span>
                        <span class="text-[12px] text-center font-medium">YouTube</span>
                    </div>
                    <div class="element-card bg-surface-container-low border border-outline-variant rounded-lg p-3 flex flex-col items-center justify-center gap-2 cursor-pointer hover:border-primary hover:text-primary transition-all group col-span-2" data-type="html">
                        <span class="material-symbols-outlined text-secondary group-hover:text-primary transition-colors">code</span>
                        <span class="text-[12px] text-center font-medium">Custom HTML</span>
                    </div>
                </div>
            </div>
            
            <div>
                <h3 class="text-[12px] font-bold text-on-surface-variant mb-3 uppercase tracking-wider">Interaktif & Layout</h3>
                <div class="grid grid-cols-2 gap-2">
                    <div class="element-card bg-surface-container-low border border-outline-variant rounded-lg p-3 flex flex-col items-center justify-center gap-2 cursor-pointer hover:border-primary hover:text-primary transition-all group" data-type="button">
                        <span class="material-symbols-outlined text-secondary group-hover:text-primary transition-colors">smart_button</span>
                        <span class="text-[12px] text-center font-medium">Button</span>
                    </div>
                    <div class="element-card bg-surface-container-low border border-outline-variant rounded-lg p-3 flex flex-col items-center justify-center gap-2 cursor-pointer hover:border-primary hover:text-primary transition-all group" data-type="faq">
                        <span class="material-symbols-outlined text-secondary group-hover:text-primary transition-colors">quiz</span>
                        <span class="text-[12px] text-center font-medium">FAQ</span>
                    </div>
                    <div class="element-card bg-surface-container-low border border-outline-variant rounded-lg p-3 flex flex-col items-center justify-center gap-2 cursor-pointer hover:border-primary hover:text-primary transition-all group col-span-2" data-type="divider">
                        <span class="material-symbols-outlined text-secondary group-hover:text-primary transition-colors">horizontal_rule</span>
                        <span class="text-[12px] text-center font-medium">Divider</span>
                    </div>
                </div>
            </div>

        </div>
    </aside>

    <main class="flex-1 bg-surface-dim flex flex-col h-full overflow-hidden relative">
        <form id="builderForm" action="builder.php<?= $page_id ? '?id='.$page_id : '' ?>" method="POST" class="flex flex-col h-full m-0">
            
            <div class="bg-surface-container-lowest border-b border-outline-variant p-4 shrink-0 shadow-sm z-30">
                <div class="flex flex-wrap gap-4 items-end">
                    <div class="flex-1 min-w-[200px]">
                        <label class="block text-[12px] font-bold text-on-surface-variant mb-1 uppercase tracking-wider">Judul Halaman</label>
                        <input name="title" class="w-full bg-surface border border-outline-variant rounded-lg px-3 py-2 text-[14px] focus:border-primary focus:ring-1 focus:ring-primary outline-none" type="text" value="<?= htmlspecialchars($page['title'] ?? '') ?>" required/>
                    </div>
                    <div class="flex-1 min-w-[200px]">
                        <label class="block text-[12px] font-bold text-on-surface-variant mb-1 uppercase tracking-wider">Slug URL</label>
                        <input name="slug" class="w-full bg-surface border border-outline-variant rounded-lg px-3 py-2 text-[14px] focus:border-primary focus:ring-1 focus:ring-primary outline-none" type="text" value="<?= htmlspecialchars($page['slug'] ?? '') ?>" required/>
                    </div>
                    <div class="w-48">
                        <label class="block text-[12px] font-bold text-on-surface-variant mb-1 uppercase tracking-wider">Folder</label>
                        <div class="relative">
                            <select name="project_id" class="w-full bg-surface border border-outline-variant rounded-lg px-3 py-2 text-[14px] focus:border-primary focus:ring-1 focus:ring-primary outline-none appearance-none cursor-pointer">
                                <option value="">-- Tanpa Folder --</option>
                                <?php foreach ($projects_list as $proj): ?>
                                    <option value="<?= $proj['id'] ?>" <?= (($page['project_id'] ?? '') == $proj['id']) ? 'selected' : '' ?>><?= htmlspecialchars($proj['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <span class="material-symbols-outlined absolute right-2 top-1/2 -translate-y-1/2 text-on-surface-variant pointer-events-none">arrow_drop_down</span>
                        </div>
                    </div>
                    <div class="w-px bg-outline-variant h-10 self-center mx-2 hidden lg:block"></div>
                    <div class="flex flex-col justify-center h-10">
                        <label class="flex items-center gap-2 cursor-pointer group">
                            <input class="w-4 h-4 text-error border-outline-variant rounded focus:ring-error" type="checkbox" id="isPureHtmlToggle" name="is_pure_html" value="1" <?= (!empty($page['is_pure_html'])) ? 'checked' : '' ?>/>
                            <span class="text-[14px] font-semibold text-error group-hover:text-on-error-container transition-colors">Mode Pure HTML</span>
                        </label>
                    </div>
                </div>
            </div>

            <div class="flex-1 overflow-y-auto canvas-scroll p-4 md:p-8 flex justify-center w-full">
                <div class="w-full max-w-4xl relative">
                    
                    <?php if (isset($_GET["saved"])): ?>
                    <div class="bg-[#e8f5e9] border border-[#a5d6a7] text-[#2e7d32] px-4 py-3 rounded-lg mb-6 flex items-center gap-2 shadow-sm">
                        <span class="material-symbols-outlined">check_circle</span>
                        <span class="font-medium text-[14px]">Halaman berhasil disimpan!</span>
                    </div>
                    <?php endif; ?>

                    <div id="pureHtmlSection" class="mb-8" style="display:none;">
                        <div class="bg-[#1e1e1e] rounded-xl shadow-md overflow-hidden border border-outline-variant">
                            <div class="bg-[#2d2d2d] px-4 py-3 border-b border-white/10 flex justify-between items-center">
                                <div class="flex items-center gap-2">
                                    <span class="material-symbols-outlined text-[#d4d4d4] text-[18px]">code</span>
                                    <span class="text-[14px] font-mono text-[#d4d4d4]">index.html</span>
                                </div>
                                <span class="text-[12px] text-[#858585]">Script Meta & Tracking otomatis disisipkan</span>
                            </div>
                            <textarea name="pure_html_content" class="w-full bg-transparent p-4 outline-none resize-y font-mono text-[14px] leading-relaxed text-[#d4d4d4] min-h-[500px]" spellcheck="false"><?= htmlspecialchars($page['pure_html_content'] ?? '') ?></textarea>
                        </div>
                    </div>

                    <div id="visualBuilderSection" class="bg-surface-container-lowest shadow-sm rounded-xl min-h-[600px] border border-outline-variant p-4 md:p-8 flex flex-col gap-2 relative">
                        <div id="canvasElements" class="min-h-[500px]">
                            <?php if (empty($elements)): ?>
                                <div class="empty-state text-center py-20 flex flex-col items-center justify-center opacity-50">
                                    <span class="material-symbols-outlined text-[64px] mb-4">add_circle</span>
                                    <h5 class="text-[18px] font-bold text-on-surface mb-2">Canvas Kosong</h5>
                                    <p class="text-[14px] text-on-surface-variant">Klik elemen di panel kiri untuk mulai membangun halaman.</p>
                                </div>
                            <?php else: 
                                foreach ($elements as $idx => $el) {
                                    $st = json_decode($el["styles"], true) ?: [];
                                    echo renderElementUI($el["type"], $idx, $el["content"], $st);
                                }
                            endif; ?>
                        </div>
                        <div class="mt-8 text-center text-outline-variant flex flex-col items-center gap-2 opacity-50 pointer-events-none">
                            <span class="material-symbols-outlined text-[32px]">arrow_downward</span>
                            <span class="text-[12px] font-bold uppercase tracking-widest">Akhir Halaman</span>
                        </div>
                    </div>
                    
                </div>
            </div>
        </form>
    </main>

    <aside class="w-editor-inspector-width bg-surface-container-lowest shadow-[-1px_0_3px_rgba(0,0,0,0.05)] z-40 flex flex-col h-full shrink-0 border-l border-outline-variant relative">
        
        <div id="inspectorOverlay" class="absolute inset-0 bg-surface/80 z-50 hidden flex-col items-center justify-center p-6 text-center backdrop-blur-[1px]">
            <span class="material-symbols-outlined text-outline text-[48px] mb-4">lock</span>
            <h3 class="text-[16px] font-bold text-on-surface mb-2">Panel Dinonaktifkan</h3>
            <p class="text-[13px] text-on-surface-variant">Nonaktifkan Mode Pure HTML untuk menggunakan Visual Builder.</p>
        </div>

        <div class="shrink-0 border-b border-outline-variant">
            <div class="p-4 bg-surface-container-low flex items-center justify-between">
                <div class="flex items-center gap-2 text-on-surface font-semibold text-[14px]">
                    <span class="material-symbols-outlined text-primary text-[20px]">tune</span> Pengaturan
                </div>
            </div>
            <div class="flex w-full">
                <button class="settings-tab flex-1 py-3 text-[13px] font-bold text-primary border-b-2 border-primary text-center transition-colors" data-target="style">Gaya</button>
                <button class="settings-tab flex-1 py-3 text-[13px] font-medium text-on-surface-variant hover:bg-surface-container-low text-center transition-colors" data-target="content">Konten</button>
                <button class="settings-tab flex-1 py-3 text-[13px] font-medium text-on-surface-variant hover:bg-surface-container-low text-center transition-colors" data-target="tracking">Tracking</button>
            </div>
        </div>

        <div class="flex-1 overflow-y-auto p-5">
            <div id="inspectorEmpty" class="text-center py-10 opacity-50 flex flex-col items-center">
                <span class="material-symbols-outlined text-[48px] mb-3">touch_app</span>
                <p class="text-[13px]">Klik elemen di canvas untuk mengatur properti.</p>
            </div>

            <div id="settingsArea" class="space-y-5 hidden"></div>

            <div id="tab-tracking" class="settings-tab-content space-y-5 hidden">
                <div class="bg-blue-50 text-blue-700 text-[12px] p-4 rounded-xl border border-blue-100 flex gap-2">
                    <span class="material-symbols-outlined text-[18px]">info</span>
                    <span>Pilih profil pixel yang sudah Anda buat. Data Token & ID otomatis terhubung.</span>
                </div>

                <div class="space-y-4">
                    <div>
                        <label class="block text-[13px] font-bold text-slate-700 mb-1 uppercase tracking-wider">Pilih Meta Pixel</label>
                        <div class="relative">
                            <select name="pixel_profile_id" form="builderForm" class="w-full bg-white border border-slate-200 rounded-xl px-4 py-2.5 text-[14px] focus:border-blue-500 outline-none appearance-none cursor-pointer">
                                <option value="">-- Tanpa Pixel --</option>
                                <?php foreach ($pixels_list as $px): ?>
                                    <option value="<?= $px['id'] ?>" <?= (($page['pixel_profile_id'] ?? '') == $px['id']) ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($px['name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <span class="material-symbols-outlined absolute right-3 top-1/2 -translate-y-1/2 text-slate-400 pointer-events-none">expand_more</span>
                        </div>
                    </div>

                    <div>
                        <label class="block text-[13px] font-bold text-slate-700 mb-1 uppercase tracking-wider">Event Trigger</label>
                        <div class="relative">
                            <select name="meta_event_name" form="builderForm" class="w-full bg-white border border-slate-200 rounded-xl px-4 py-2.5 text-[14px] focus:border-blue-500 outline-none appearance-none cursor-pointer">
                                <?php 
                                $evs = ['ViewContent', 'Lead', 'Purchase', 'AddToCart', 'InitiateCheckout'];
                                foreach($evs as $e): ?>
                                    <option value="<?= $e ?>" <?= (($page['meta_event_name'] ?? 'ViewContent') == $e) ? 'selected' : '' ?>><?= $e ?></option>
                                <?php endforeach; ?>
                            </select>
                            <span class="material-symbols-outlined absolute right-3 top-1/2 -translate-y-1/2 text-slate-400 pointer-events-none">expand_more</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <div id="inspectorActions" class="shrink-0 p-4 border-t border-outline-variant bg-surface-container-lowest flex gap-2 hidden">
            <button type="button" onclick="duplicateElement()" class="flex-1 py-2 border border-outline-variant rounded-lg text-[13px] font-semibold text-on-surface-variant hover:bg-surface-container-low transition-colors flex items-center justify-center gap-1">
                <span class="material-symbols-outlined text-[16px]">content_copy</span> Duplikat
            </button>
            <button type="button" onclick="deleteElement()" class="flex-1 py-2 border border-error/30 rounded-lg text-[13px] font-semibold text-error hover:bg-error-container/20 transition-colors flex items-center justify-center gap-1">
                <span class="material-symbols-outlined text-[16px]">delete</span> Hapus
            </button>
        </div>
    </aside>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.0/Sortable.min.js"></script>
<script src="https://cdn.quilljs.com/1.3.6/quill.min.js"></script>

<script>
const fullColorPalette = [
    "#ffffff", "#eeeeee", "#cccccc", "#999999", "#666666", "#444444", "#000000",
    "#ff0000", "#ff9900", "#ffff00", "#008a00", "#0066cc", "#9933ff",
    "#facccc", "#ffebcc", "#ffffcc", "#cce8cc", "#cce0f5", "#ebd6ff",
    "#a10000", "#b26b00", "#b2b200", "#006100", "#0047b2", "#6b24b2",
    "#FFFF00", "#39FF14", "#00FFFF", "#FF00FF", "#FF5F1F", "#FE019A", false
];
let quillEditors = {};
let selectedElement = null;

// Init Sortable
Sortable.create(document.getElementById('canvasElements'), {
    animation: 200,
    handle: '.drag-handle',
    ghostClass: 'sortable-ghost',
    onEnd: function() { updateIndices(); }
});

// Click Element in Canvas (FIXED LOGIC)
$(document).on('click', '.wysiwyg-element', function(e) {
    // Jika elemen INI sudah berstatus selected, ABAIKAN logika click builder.
    // Ini agar fungsi native JS milik Quill (seperti membuka dropdown) bisa berjalan tanpa di-interupsi/dirender ulang.
    if ($(this).hasClass('selected')) {
        return; 
    }

    $('.wysiwyg-element').removeClass('selected z-50');
    $(this).addClass('selected z-50');
    selectedElement = $(this);
    
    $('#inspectorEmpty').hide();
    $('#settingsArea').show();
    $('#inspectorActions').removeClass('hidden').addClass('flex');
    
    // Switch to style tab by default when selecting element
    $('.settings-tab[data-target="style"]').click();

    const type = $(this).data('element-type');
    const idx = $(this).data('element-index');
    renderSettings(type, idx);
    if(type === 'header' || type === 'paragraph') initQuill(idx);
});

// Click Element in Left Sidebar (Add)
$('.element-card').click(function() {
    const type = $(this).data('type');
    const idx = $('#canvasElements .wysiwyg-element').length;
    $('.empty-state').hide();

    let defaultContent = '';
    let defaultStyles = { bg_color: '#ffffff', text_color: '#191b23', link: '#' };

    switch(type) {
        case 'header': defaultContent = '<h1 class="text-display-lg font-display-lg uppercase font-bold text-center">Header Baru</h1>'; break;
        case 'paragraph': defaultContent = '<p class="text-body-md text-center">Paragraf baru... Klik untuk mengedit konten.</p>'; break;
        case 'button': defaultContent = 'Klik Disini'; break;
        case 'faq': defaultContent = '[]'; break;
    }

    const html = `
    <div class="wysiwyg-element relative group border border-transparent hover:border-outline-variant hover:border-dashed rounded-lg transition-all p-4 mb-4" id="el-target-${idx}" data-element-index="${idx}" data-element-type="${type}" style="background:${defaultStyles.bg_color}; color:${defaultStyles.text_color}">
        <div class="absolute -left-4 top-1/2 -translate-y-1/2 flex flex-col gap-1 opacity-0 group-hover:opacity-100 transition-opacity z-10 drag-handle cursor-grab">
            <button type="button" class="w-6 h-6 bg-surface-container-lowest border border-outline-variant rounded shadow flex items-center justify-center text-on-surface hover:text-primary hover:border-primary"><span class="material-symbols-outlined text-[16px]">drag_indicator</span></button>
        </div>
        <div class="absolute -top-3 left-2 bg-primary text-on-primary text-[10px] font-bold px-2 py-0.5 rounded opacity-0 group-hover:opacity-100 transition-opacity pointer-events-none uppercase tracking-wider">${type}</div>

        <input type="hidden" name="elements[${idx}][type]" value="${type}">
        <input type="hidden" name="elements[${idx}][styles][bg_color]" class="in-bg" value="${defaultStyles.bg_color}">
        <input type="hidden" name="elements[${idx}][styles][text_color]" class="in-tx" value="${defaultStyles.text_color}">
        <input type="hidden" name="elements[${idx}][styles][link]" class="in-link" value="${defaultStyles.link}">

        <div class="element-content-wrapper w-full">
            ${renderElementContent(type, defaultContent, defaultStyles)}
        </div>
        <textarea name="elements[${idx}][content]" class="hidden in-content">${defaultContent}</textarea>
    </div>`;

    $('#canvasElements').append(html);
    updateIndices();
    $(`#el-target-${idx}`).click();
});

function renderElementContent(type, content, styles) {
    const bg = styles.bg_color || '#ffffff';
    const tx = styles.text_color || '#191b23';
    const link = styles.link || '#';
    let html = '';

    switch(type) {
        case 'header':
        case 'paragraph':
            html = `<div class="editable-content focus:outline-none focus:ring-2 focus:ring-primary/20 rounded p-2" data-editor-index="${$('#canvasElements .wysiwyg-element').length}">${content}</div>`;
            break;
        case 'divider':
            html = `<div class="py-4"><hr style="border-top: 2px solid ${tx};"></div>`;
            break;
        case 'youtube':
            html = `<div class="bg-surface-container-low border border-outline-variant border-dashed rounded-lg p-8 flex flex-col items-center justify-center text-outline"><span class="material-symbols-outlined text-[40px] text-error mb-2">smart_display</span><p class="text-body-sm font-medium text-on-surface-variant">Masukkan ID Video YouTube di Panel Kanan</p></div>`;
            break;
        case 'image':
            html = `<div class="bg-surface-container-low border border-outline-variant border-dashed rounded-lg p-8 flex flex-col items-center justify-center text-outline"><span class="material-symbols-outlined text-[40px] mb-2">image</span><p class="text-body-sm font-medium text-on-surface-variant">Masukkan URL Gambar di Panel Kanan</p></div>`;
            break;
        case 'html':
            html = `<div class="html-preview"><div class="bg-surface-container-low border border-outline-variant border-dashed rounded-lg p-6 flex flex-col items-center justify-center text-outline"><span class="material-symbols-outlined text-[32px] mb-2">code</span><span class="text-body-sm">Custom HTML Block</span></div></div>`;
            break;
        case 'button':
            html = `<div class="text-center py-4"><a href="${link}" class="inline-block shadow-sm transition-transform hover:-translate-y-0.5" style="background:${bg}; color:${tx}; padding: 12px 32px; border-radius: 9999px; font-weight: 700; text-decoration:none;">${content || 'Klik Disini'}</a></div>`;
            break;
        case 'faq':
            html = `<div class="faq-preview w-full max-w-3xl mx-auto space-y-3"><div class="bg-surface-container-low border border-outline-variant border-dashed rounded-lg p-6 flex flex-col items-center justify-center text-outline"><span class="material-symbols-outlined text-[32px] mb-2">quiz</span><p class="text-body-sm font-medium text-on-surface-variant">Tambahkan FAQ di Panel Kanan</p></div></div>`;
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
                [{ 'font': [] }], // FIX: Tambahkan dukungan opsi Font dropdown
                [{ 'header': [1, 2, 3, false] }],
                ['bold', 'italic', 'underline', 'strike'],
                [{ 'list': 'ordered'}, { 'list': 'bullet' }],
                [{ 'color': fullColorPalette }, { 'background': fullColorPalette }],
                [{ 'align': [] }],
                ['link', 'clean']
            ]
        }
    });

    const initialContent = selectedElement.find('.in-content').val();
    if(initialContent) q.root.innerHTML = initialContent;
    q.on('text-change', () => { selectedElement.find('.in-content').val(q.root.innerHTML); });
    quillEditors[i] = q;
}

// Right Panel Tabs
$('.settings-tab').click(function() {
    $('.settings-tab').removeClass('border-primary text-primary font-bold').addClass('text-on-surface-variant font-medium border-transparent');
    $(this).removeClass('text-on-surface-variant font-medium border-transparent').addClass('border-primary text-primary font-bold');
    
    const target = $(this).data('target');
    
    $('.settings-tab-content, #settingsArea > div').hide();
    
    if(target === 'tracking') {
        $('#inspectorEmpty').hide();
        $('#settingsArea').hide();
        $('#inspectorActions').removeClass('flex').addClass('hidden');
        $('#tab-tracking').show();
    } else {
        $('#tab-tracking').hide();
        if(selectedElement) {
            $('#inspectorEmpty').hide();
            $('#settingsArea').show();
            $('#inspectorActions').removeClass('hidden').addClass('flex');
            $(`#tab-${target}`).show();
        } else {
            $('#inspectorEmpty').show();
        }
    }
});

function renderSettings(type, idx) {
    if(!selectedElement) return;
    const bg = selectedElement.find('.in-bg').val();
    const tx = selectedElement.find('.in-tx').val();
    const content = selectedElement.find('.in-content').val();
    const link = selectedElement.find('.in-link').val();

    let html = '';

    // TAB STYLE
    html += `<div id="tab-style" class="space-y-4">
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-[12px] font-bold text-on-surface-variant mb-1 uppercase tracking-wider">Warna Latar</label>
                        <div class="flex items-center gap-2">
                            <input type="color" id="sBg" class="w-8 h-8 rounded cursor-pointer border border-outline-variant p-0" value="${bg}">
                            <input type="text" class="w-full bg-surface border border-outline-variant rounded px-2 py-1 text-[13px] uppercase font-mono" value="${bg}" readonly>
                        </div>
                    </div>
                    <div>
                        <label class="block text-[12px] font-bold text-on-surface-variant mb-1 uppercase tracking-wider">Warna Teks</label>
                        <div class="flex items-center gap-2">
                            <input type="color" id="sTx" class="w-8 h-8 rounded cursor-pointer border border-outline-variant p-0" value="${tx}">
                            <input type="text" class="w-full bg-surface border border-outline-variant rounded px-2 py-1 text-[13px] uppercase font-mono" value="${tx}" readonly>
                        </div>
                    </div>
                </div>
            </div>`;

    // TAB CONTENT
    html += `<div id="tab-content" class="space-y-4" style="display:none;">`;
    
    if(type === 'button') {
        html += `<div>
                    <label class="block text-[13px] font-medium text-on-surface mb-1">Teks Tombol</label>
                    <input type="text" id="sBtnText" class="w-full bg-surface border border-outline-variant rounded-lg px-3 py-2 text-[14px] focus:border-primary outline-none" value="${content || 'Klik Disini'}">
                </div>
                <div>
                    <label class="block text-[13px] font-medium text-on-surface mb-1">Link URL</label>
                    <input type="text" id="sBtnLink" class="w-full bg-surface border border-outline-variant rounded-lg px-3 py-2 text-[14px] focus:border-primary outline-none" value="${link || '#'}">
                </div>`;
    }
    else if(type === 'faq') {
        let faqs = [];
        try { faqs = content ? JSON.parse(content) : []; } catch(e) { faqs = []; }
        html += `<div class="bg-primary-fixed text-on-primary-fixed text-[12px] p-3 rounded-lg mb-2 flex gap-2"><span class="material-symbols-outlined text-[16px]">info</span><span>Tambah baris FAQ di bawah ini.</span></div>
                 <div id="faqList" class="space-y-3">`;
        if(faqs.length > 0) {
            faqs.forEach((f, i) => {
                html += `<div class="faq-item p-3 border border-outline-variant rounded-lg bg-surface relative">
                            <button type="button" class="absolute right-2 top-2 text-error hover:bg-error-container/50 rounded p-1" onclick="removeFaq(${i})"><span class="material-symbols-outlined text-[16px]">close</span></button>
                            <label class="block text-[12px] font-bold text-on-surface-variant mb-1">Pertanyaan</label>
                            <input type="text" class="w-full border border-outline-variant rounded px-2 py-1 text-[13px] mb-2 f-q" value="${escapeHtml(f.q||'')}">
                            <label class="block text-[12px] font-bold text-on-surface-variant mb-1">Jawaban</label>
                            <textarea class="w-full border border-outline-variant rounded px-2 py-1 text-[13px] f-a" rows="2">${escapeHtml(f.a||'')}</textarea>
                        </div>`;
            });
        }
        html += `</div><button type="button" class="w-full py-2 bg-surface-container-highest hover:bg-surface-dim rounded-lg text-[13px] font-semibold flex items-center justify-center gap-1 transition-colors mt-2" onclick="addFaq()"><span class="material-symbols-outlined text-[16px]">add</span> Tambah Item FAQ</button>`;
    }
    else if(type === 'youtube') {
        html += `<div>
                    <label class="block text-[13px] font-medium text-on-surface mb-1">YouTube Video ID</label>
                    <input type="text" id="sYoutubeId" class="w-full bg-surface border border-outline-variant rounded-lg px-3 py-2 text-[14px] focus:border-primary outline-none font-mono" value="${content || ''}" placeholder="dQw4w9WgXcQ">
                    <p class="text-[11px] text-on-surface-variant mt-1">ID video ada di belakang ?v= pada URL YouTube.</p>
                </div>`;
    }
    else if(type === 'image') {
        html += `<div>
                    <label class="block text-[13px] font-medium text-on-surface mb-1">Image URL</label>
                    <input type="text" id="sImageUrl" class="w-full bg-surface border border-outline-variant rounded-lg px-3 py-2 text-[14px] focus:border-primary outline-none" value="${content || ''}" placeholder="https://...">
                    <p class="text-[11px] text-on-surface-variant mt-1">Gunakan URL lengkap gambar.</p>
                </div>`;
    }
    else if(type === 'html') {
        html += `<div>
                    <label class="block text-[13px] font-medium text-on-surface mb-1">Custom HTML</label>
                    <textarea id="sHtmlCode" class="w-full bg-[#1e1e1e] text-[#d4d4d4] font-mono border border-outline-variant rounded-lg p-3 text-[13px] focus:border-primary outline-none min-h-[200px]">${content || ''}</textarea>
                </div>`;
    }
    else if(['header','paragraph'].includes(type)) {
        html += `<div class="bg-primary-fixed text-on-primary-fixed text-[12px] p-3 rounded-lg mb-2 flex gap-2"><span class="material-symbols-outlined text-[16px]">edit_note</span><span>Edit teks langsung di area Canvas sebelah kiri.</span></div>`;
    }
    else if(type === 'divider') {
        html += `<div class="bg-primary-fixed text-on-primary-fixed text-[12px] p-3 rounded-lg mb-2 flex gap-2"><span class="material-symbols-outlined text-[16px]">info</span><span>Pilih warna garis di tab Gaya (Style).</span></div>`;
    }
    
    html += `</div>`;

    $('#settingsArea').html(html);
    setupEventListeners(type);

    const activeTabTarget = $('.settings-tab.border-primary').data('target');
    if(activeTabTarget === 'style' || activeTabTarget === 'content') {
        $('.settings-tab-content, #settingsArea > div').hide();
        $('#settingsArea').show();
        $(`#tab-${activeTabTarget}`).show();
    }
}

function setupEventListeners(type) {
    $('#sBg').on('input', function() {
        let v = $(this).val();
        selectedElement.find('.in-bg').val(v);
        selectedElement.css('background', v);
        $(this).next().val(v);
        if(type==='button') selectedElement.find('a').css('background', v);
    });

    $('#sTx').on('input', function() {
        let v = $(this).val();
        selectedElement.find('.in-tx').val(v);
        $(this).next().val(v);
        if(type==='divider') selectedElement.find('hr').css('border-color', v);
        else if(type==='button') selectedElement.find('a').css('color', v);
        else selectedElement.css('color', v);
    });

    switch(type) {
        case 'button':
            $('#sBtnText').on('input', function() {
                let v = $(this).val() || 'Klik Disini';
                selectedElement.find('.in-content').val(v);
                selectedElement.find('a').text(v);
            });
            $('#sBtnLink').on('input', function() {
                let v = $(this).val() || '#';
                selectedElement.find('.in-link').val(v);
                selectedElement.find('a').attr('href', v);
            });
            break;
        case 'image':
            $('#sImageUrl').on('input', function() {
                let v = $(this).val();
                selectedElement.find('.in-content').val(v);
                if(v) {
                    selectedElement.find('.element-content-wrapper').html(`<img src="${v}" class="w-full h-auto rounded-lg shadow-sm" style="max-height:500px; object-fit:contain;">`);
                } else {
                    selectedElement.find('.element-content-wrapper').html(`<div class="bg-surface-container-low border border-outline-variant border-dashed rounded-lg p-8 flex flex-col items-center justify-center text-outline"><span class="material-symbols-outlined text-[40px] mb-2">image</span><p class="text-body-sm font-medium text-on-surface-variant">Masukkan URL Gambar di Panel Kanan</p></div>`);
                }
            });
            break;
        case 'youtube':
            $('#sYoutubeId').on('input', function() {
                let v = $(this).val();
                selectedElement.find('.in-content').val(v);
                if(v) {
                    selectedElement.find('.element-content-wrapper').html(`<div class="relative w-full overflow-hidden" style="padding-top: 56.25%;"><iframe class="absolute top-0 left-0 w-full h-full rounded-lg shadow-sm" src="https://www.youtube.com/embed/${v}" allowfullscreen></iframe></div>`);
                } else {
                    selectedElement.find('.element-content-wrapper').html(`<div class="bg-surface-container-low border border-outline-variant border-dashed rounded-lg p-8 flex flex-col items-center justify-center text-outline"><span class="material-symbols-outlined text-[40px] text-error mb-2">smart_display</span><p class="text-body-sm font-medium text-on-surface-variant">Masukkan ID Video YouTube di Panel Kanan</p></div>`);
                }
            });
            break;
        case 'html':
            $('#sHtmlCode').on('input', function() {
                let v = $(this).val();
                selectedElement.find('.in-content').val(v);
                selectedElement.find('.html-preview').html(v ? `<div class="html-content">${v}</div>` : `<div class="bg-surface-container-low border border-outline-variant border-dashed rounded-lg p-6 flex flex-col items-center justify-center text-outline"><span class="material-symbols-outlined text-[32px] mb-2">code</span><span class="text-body-sm">Custom HTML Block</span></div>`);
            });
            break;
        case 'faq':
            $('.f-q, .f-a').on('input', function() { saveFaq(); });
            break;
    }
}

function addFaq() {
    let c = [];
    try { c = JSON.parse(selectedElement.find('.in-content').val() || '[]'); } catch(e) { c = []; }
    c.push({q:'', a:''});
    saveFaq(c);
}

function removeFaq(i) {
    let c = [];
    try { c = JSON.parse(selectedElement.find('.in-content').val() || '[]'); } catch(e) { c = []; }
    c.splice(i, 1);
    saveFaq(c);
}

function saveFaq(newC = null) {
    let c = newC;
    if(!c) {
        c = [];
        $('#faqList .faq-item').each(function() {
            c.push({ q: $(this).find('.f-q').val(), a: $(this).find('.f-a').val() });
        });
    }
    selectedElement.find('.in-content').val(JSON.stringify(c));

    let previewHtml = '<div class="faq-preview w-full max-w-3xl mx-auto space-y-3">';
    if(c.length > 0) {
        c.forEach(f => {
            previewHtml += `<div class="bg-surface-container-lowest border border-outline-variant rounded-lg p-4 shadow-sm"><h3 class="font-bold text-on-surface mb-2 flex gap-2"><span class="text-primary">Q:</span> ${escapeHtml(f.q||'')}</h3><p class="text-on-surface-variant text-body-sm flex gap-2"><span class="text-success font-bold">A:</span> ${escapeHtml(f.a||'')}</p></div>`;
        });
    } else {
        previewHtml += `<div class="bg-surface-container-low border border-outline-variant border-dashed rounded-lg p-6 flex flex-col items-center justify-center text-outline"><span class="material-symbols-outlined text-[32px] mb-2">quiz</span><p class="text-body-sm font-medium text-on-surface-variant">Tambahkan FAQ di Panel Kanan</p></div>`;
    }
    previewHtml += '</div>';
    selectedElement.find('.element-content-wrapper').html(previewHtml);
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
    <div class="wysiwyg-element relative group border border-transparent hover:border-outline-variant hover:border-dashed rounded-lg transition-all p-4 mb-4" id="el-target-${idx}" data-element-index="${idx}" data-element-type="${type}" style="background:${bg}; color:${tx}">
        <div class="absolute -left-4 top-1/2 -translate-y-1/2 flex flex-col gap-1 opacity-0 group-hover:opacity-100 transition-opacity z-10 drag-handle cursor-grab">
            <button type="button" class="w-6 h-6 bg-surface-container-lowest border border-outline-variant rounded shadow flex items-center justify-center text-on-surface hover:text-primary hover:border-primary"><span class="material-symbols-outlined text-[16px]">drag_indicator</span></button>
        </div>
        <div class="absolute -top-3 left-2 bg-primary text-on-primary text-[10px] font-bold px-2 py-0.5 rounded opacity-0 group-hover:opacity-100 transition-opacity pointer-events-none uppercase tracking-wider">${type}</div>
        <input type="hidden" name="elements[${idx}][type]" value="${type}">
        <input type="hidden" name="elements[${idx}][styles][bg_color]" class="in-bg" value="${bg}">
        <input type="hidden" name="elements[${idx}][styles][text_color]" class="in-tx" value="${tx}">
        <input type="hidden" name="elements[${idx}][styles][link]" class="in-link" value="${link}">
        <div class="element-content-wrapper w-full">${renderElementContent(type, content, st)}</div>
        <textarea name="elements[${idx}][content]" class="hidden in-content">${content}</textarea>
    </div>`;

    $('#canvasElements').append(html);
    updateIndices();
}

function deleteElement() {
    if(confirm('Hapus elemen ini?')) {
        selectedElement.remove();
        $('#inspectorEmpty').show();
        $('#settingsArea').hide();
        $('#inspectorActions').removeClass('flex').addClass('hidden');
        selectedElement = null;
        updateIndices();
        if($('#canvasElements .wysiwyg-element').length === 0) {
            $('#canvasElements').html(`<div class="empty-state text-center py-20 flex flex-col items-center justify-center opacity-50"><span class="material-symbols-outlined text-[64px] mb-4">add_circle</span><h5 class="text-[18px] font-bold text-on-surface mb-2">Canvas Kosong</h5><p class="text-[14px] text-on-surface-variant">Klik elemen di panel kiri untuk mulai membangun halaman.</p></div>`);
        }
    }
}

function updateIndices() {
    $('#canvasElements .wysiwyg-element').each(function(i) {
        $(this).attr({'id': 'el-target-'+i, 'data-element-index': i});
        $(this).find('input[name^="elements["], textarea[name^="elements["]').each(function() {
            $(this).attr('name', $(this).attr('name').replace(/elements\[\d+\]/, `elements[${i}]`));
        });
        $(this).find('.editable-content').attr('data-editor-index', i);
    });
}

$(document).ready(function() {
    $('#isPureHtmlToggle').change(function() {
        if($(this).is(':checked')) {
            $('#visualBuilderSection').hide();
            $('#pureHtmlSection').fadeIn();
            $('#inspectorOverlay').css('display', 'flex'); 
            $('.element-card').addClass('opacity-50 pointer-events-none'); 
        } else {
            $('#visualBuilderSection').show();
            $('#pureHtmlSection').hide();
            $('#inspectorOverlay').hide();
            $('.element-card').removeClass('opacity-50 pointer-events-none');
        }
    });
    $('#isPureHtmlToggle').trigger('change');
});
</script>
</body>
</html>