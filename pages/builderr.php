<?php

require_once '../includes/config.php';

require_once '../includes/auth.php';

require_once '../includes/functions.php';



// Cek login

requireLogin();

 $user = getCurrentUser($pdo);



// Cek apakah edit mode

 $page_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

 $page = null;

 $elements = [];



// Helper function untuk parsing konfigurasi tracking

function parseTrackingConfig($config_string) {

    $config = json_decode($config_string, true);

    if (is_array($config)) {

        return [

            'pixel_id' => $config['pixel_id'] ?? '',

            'capi_endpoint' => $config['capi_endpoint'] ?? '',

            'capi_access_token' => $config['capi_access_token'] ?? ''

        ];

    } else {

        return [

            'pixel_id' => $config_string ?? '',

            'capi_endpoint' => '',

            'capi_access_token' => ''

        ];

    }

}



function buildTrackingConfig($pixel_id, $capi_endpoint, $capi_access_token) {

    return $pixel_id; // Menyimpan pixel_id lama

}



if ($page_id > 0) {

    $page = getUserLandingPage($pdo, $page_id, $user['id']);

    if (!$page) {

        die("Landing page tidak ditemukan");

    }

    $tracking_config = parseTrackingConfig($page['meta_pixel_id']);

    $page['pixel_id'] = $tracking_config['pixel_id'];

    $page['capi_endpoint'] = $page['capi_endpoint'] ?? $tracking_config['capi_endpoint'] ?? '';

    $page['capi_access_token'] = $page['capi_access_token'] ?? $tracking_config['capi_access_token'] ?? '';



    $stmt = $pdo->prepare("SELECT * FROM page_elements WHERE page_id = ? ORDER BY order_position ASC");

    $stmt->execute([$page_id]);

    $elements = $stmt->fetchAll(PDO::FETCH_ASSOC);

}



// Handle form submit

if ($_POST) {

    $title = trim($_POST['title']);

    $slug = trim($_POST['slug']);

    $pixel_id = trim($_POST['pixel_id'] ?? '');

    $meta_event_name = trim($_POST['meta_event_name'] ?? 'ViewContent');

    $capi_endpoint_input = trim($_POST['capi_endpoint'] ?? '');

    $capi_access_token = trim($_POST['capi_access_token'] ?? '');

    $default_api_version = 'v23.0';



    if (empty($capi_endpoint_input) && !empty($pixel_id)) {

        $capi_endpoint = "https://graph.facebook.com/{$default_api_version}/{$pixel_id}/events";

    } else {

        $capi_endpoint = $capi_endpoint_input;

    }



    $meta_pixel_id = buildTrackingConfig($pixel_id, $capi_endpoint, $capi_access_token);

    $status = $_POST['status'];



    if (empty($title)) {

        $error = "Judul landing page harus diisi";

    } elseif (empty($slug)) {

        $error = "Slug landing page harus diisi";

    } else {

        try {

            $pdo->beginTransaction();



            if ($page_id > 0) {

                $stmt = $pdo->prepare("UPDATE landing_pages SET title = ?, slug = ?, meta_pixel_id = ?, meta_event_name = ?, capi_endpoint = ?, capi_access_token = ?, status = ? WHERE id = ?");

                $stmt->execute([$title, $slug, $meta_pixel_id, $meta_event_name, $capi_endpoint, $capi_access_token, $status, $page_id]);

            } else {

                if (empty($slug)) {

                    $slug = generateUniqueSlug($pdo, $title);

                } else {

                    $stmt = $pdo->prepare("SELECT id FROM landing_pages WHERE slug = ? AND user_id = ?");

                    $stmt->execute([$slug, $user['id']]);

                    if ($stmt->fetch()) {

                        $original_slug = $slug;

                        $counter = 1;

                        do {

                            $slug = $original_slug . '-' . $counter;

                            $stmt->execute([$slug, $user['id']]);

                            $counter++;

                        } while ($stmt->fetch());

                    }

                }

                $stmt = $pdo->prepare("INSERT INTO landing_pages (user_id, title, slug, meta_pixel_id, meta_event_name, capi_endpoint, capi_access_token, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");

                $stmt->execute([$user['id'], $title, $slug, $meta_pixel_id, $meta_event_name, $capi_endpoint, $capi_access_token, $status]);

                $page_id = $pdo->lastInsertId();

            }



            $stmt = $pdo->prepare("DELETE FROM page_elements WHERE page_id = ?");

            $stmt->execute([$page_id]);



            if (isset($_POST['elements']) && is_array($_POST['elements'])) {

                ksort($_POST['elements']);

                $index = 0;

                foreach ($_POST['elements'] as $key => $element) {

                    $type = $element['type'];

                    $content = $element['content'] ?? '';

                    $styles = isset($element['styles']) ? json_encode($element['styles']) : null;

                    if (in_array($type, ['section_1col', 'section_2col', 'section_3col'])) {

                        continue; // Lewati elemen section

                    }

                    $stmt = $pdo->prepare("INSERT INTO page_elements (page_id, type, content, order_position, styles) VALUES (?, ?, ?, ?, ?)");

                    $stmt->execute([$page_id, $type, $content, $index, $styles]);

                    $index++;

                }

            }



            $pdo->commit();

            $_SESSION['message'] = "Landing page berhasil disimpan";

            header("Location: builder.php?id=$page_id");

            exit;

        } catch (Exception $e) {

            $pdo->rollBack();

            $error = "Terjadi kesalahan saat menyimpan: " . $e->getMessage();

        }

    }

}



// Fungsi untuk merender elemen dalam tampilan WYSIWYG

function renderElementForCanvas($element, $index) {

    $styles = $element['styles'] ? json_decode($element['styles'], true) : [];

    $styles = is_array($styles) ? $styles : [];



    $bgColor = $styles['bg_color'] ?? '';

    $textColor = $styles['text_color'] ?? '';

    $elementStyle = '';

    if ($bgColor) $elementStyle .= "background-color: $bgColor;";

    if ($textColor) $elementStyle .= "color: $textColor;";



    // Kelas CSS untuk elemen yang bisa dipilih

    $elementHtml = '<div class="wysiwyg-element" data-element-index="' . $index . '" data-element-type="' . $element['type'] . '" style="' . $elementStyle . ' border: 2px solid transparent; padding: 10px; margin: 10px 0; border-radius: 5px; transition: border-color 0.2s; cursor: pointer;" tabindex="0" role="button" aria-label="Elemen ' . $element['type'] . '">';

    // Input tersembunyi untuk type

    $elementHtml .= '<input type="hidden" name="elements[' . $index . '][type]" value="' . $element['type'] . '">';



    switch ($element['type']) {

        case 'header':

            $elementHtml .= '<h2 class="editable-content" data-editor-index="' . $index . '" contenteditable="true">' . htmlspecialchars_decode($element['content']) . '</h2>';

            $elementHtml .= '<textarea name="elements[' . $index . '][content]" class="d-none wysiwyg-content">' . htmlspecialchars($element['content']) . '</textarea>';

            // Input tersembunyi untuk styles, diperbarui oleh JS

            $elementHtml .= '<input type="hidden" name="elements[' . $index . '][styles][bg_color]" class="style-input-bg" value="' . $bgColor . '">';

            $elementHtml .= '<input type="hidden" name="elements[' . $index . '][styles][text_color]" class="style-input-text" value="' . $textColor . '">';

            break;

        case 'paragraph':

            $elementHtml .= '<p class="editable-content" data-editor-index="' . $index . '" contenteditable="true">' . htmlspecialchars_decode($element['content']) . '</p>';

            $elementHtml .= '<textarea name="elements[' . $index . '][content]" class="d-none wysiwyg-content">' . htmlspecialchars($element['content']) . '</textarea>';

            $elementHtml .= '<input type="hidden" name="elements[' . $index . '][styles][bg_color]" class="style-input-bg" value="' . $bgColor . '">';

            $elementHtml .= '<input type="hidden" name="elements[' . $index . '][styles][text_color]" class="style-input-text" value="' . $textColor . '">';

            break;

        case 'divider':

            $style = $styles['style'] ?? 'solid';

            $color = $styles['color'] ?? '#94a3b8';

            $elementHtml .= '<hr style="border: 0; border-top: 2px ' . $style . ' ' . $color . '; margin: 20px 0;">';

            $elementHtml .= '<input type="hidden" name="elements[' . $index . '][content]" value="">';

            $elementHtml .= '<input type="hidden" name="elements[' . $index . '][styles][style]" class="style-input-divider-style" value="' . $style . '">';

            $elementHtml .= '<input type="hidden" name="elements[' . $index . '][styles][color]" class="style-input-divider-color" value="' . $color . '">';

            break;

        case 'image':

            $elementHtml .= '<img src="' . htmlspecialchars($element['content']) . '" class="img-fluid wysiwyg-image" alt="Gambar" style="max-width: 100%;">';

            $elementHtml .= '<input type="hidden" name="elements[' . $index . '][content]" class="wysiwyg-image-url" value="' . htmlspecialchars($element['content']) . '">';

            break;

        case 'youtube':

            $videoId = $element['content'] ?? '';

            $embedUrl = $videoId ? "https://www.youtube.com/embed/{$videoId}" : "#";

            $elementHtml .= '<div class="ratio ratio-16x9 mb-3"><iframe src="' . htmlspecialchars($embedUrl) . '" 

                title="YouTube video player" frameborder="0" 

                allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share" 

                allowfullscreen></iframe></div>';

            $elementHtml .= '<input type="hidden" name="elements[' . $index . '][content]" class="wysiwyg-youtube-id" value="' . htmlspecialchars($videoId) . '">';

            break;

        case 'button':

            $link = $styles['link'] ?? '#';

            $bgColor = $styles['bg_color'] ?? '#3b82f6';

            $textColor = $styles['text_color'] ?? '#ffffff';

            $elementHtml .= '<a href="' . htmlspecialchars($link) . '" class="btn wysiwyg-button" style="background-color: ' . $bgColor . '; color: ' . $textColor . ';">' . htmlspecialchars($element['content']) . '</a>';

            $elementHtml .= '<input type="hidden" name="elements[' . $index . '][content]" class="wysiwyg-button-text" value="' . htmlspecialchars($element['content']) . '">';

            $elementHtml .= '<input type="hidden" name="elements[' . $index . '][styles][link]" class="wysiwyg-button-link" value="' . htmlspecialchars($link) . '">';

            $elementHtml .= '<input type="hidden" name="elements[' . $index . '][styles][bg_color]" class="wysiwyg-button-bg" value="' . $bgColor . '">';

            $elementHtml .= '<input type="hidden" name="elements[' . $index . '][styles][text_color]" class="wysiwyg-button-text-color" value="' . $textColor . '">';

            break;

        case 'form':

            $elementHtml .= '<div class="alert alert-info wysiwyg-form-content">' . htmlspecialchars($element['content']) . '</div>';

            $elementHtml .= '<textarea name="elements[' . $index . '][content]" class="d-none wysiwyg-form-content-input">' . htmlspecialchars($element['content']) . '</textarea>';

            break;

        case 'faq':

            $faqs = json_decode($element['content'], true);

            if (!is_array($faqs)) $faqs = [];

            $faqHtml = '<div class="faq-preview">';

            foreach ($faqs as $i => $faq) {

                $q = htmlspecialchars($faq['question'] ?? '');

                $a = htmlspecialchars($faq['answer'] ?? '');

                $faqHtml .= "<div class='mb-3 p-2 border rounded'>

                    <strong>Q:</strong> {$q}<br>

                    <strong>A:</strong> {$a}

                </div>";

            }

            if (empty($faqs)) $faqHtml .= '<em class="text-muted">Belum ada FAQ. Klik untuk atur.</em>';

            $faqHtml .= '</div>';

            $elementHtml .= $faqHtml;

            $elementHtml .= '<input type="hidden" name="elements[' . $index . '][content]" class="wysiwyg-faq-content" value="' . htmlspecialchars($element['content']) . '">';

            break;



        case 'testimonial':

            $testis = json_decode($element['content'], true);

            if (!is_array($testis)) $testis = [];

            $testiHtml = '<div class="testimonial-preview">';

            foreach ($testis as $i => $t) {

                $name = htmlspecialchars($t['name'] ?? '');

                $role = htmlspecialchars($t['role'] ?? '');

                $text = htmlspecialchars($t['text'] ?? '');

                $testiHtml .= "<div class='mb-3 p-3 border rounded bg-light'>

                    <p class='mb-2 fst-italic'>\"{$text}\"</p>

                    <h6 class='mb-0'>— {$name}</h6>

                    <small>{$role}</small>

                </div>";

            }

            if (empty($testis)) $testiHtml .= '<em class="text-muted">Belum ada testimoni. Klik untuk atur.</em>';

            $testiHtml .= '</div>';

            $elementHtml .= $testiHtml;

            $elementHtml .= '<input type="hidden" name="elements[' . $index . '][content]" class="wysiwyg-testimonial-content" value="' . htmlspecialchars($element['content']) . '">';

            break;			

        case 'html':

            $elementHtml .= '<div class="custom-html-content wysiwyg-html-content">' . $element['content'] . '</div>';

            $elementHtml .= '<textarea name="elements[' . $index . '][content]" class="d-none wysiwyg-html-content-input">' . htmlspecialchars($element['content']) . '</textarea>';

            break;

    }



    $elementHtml .= '</div>';

    return $elementHtml;

}

?>

<!DOCTYPE html>

<html>

<head>

    <title><?= $page ? 'Edit' : 'Buat' ?> Landing Page - Landing Page Builder</title>

    <meta charset="UTF-8">

    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">

    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css" rel="stylesheet">

    <link href="https://cdn.quilljs.com/1.3.6/quill.snow.css" rel="stylesheet">

    <style>

        :root {

            --primary-color: #4361ee;

            --secondary-color: #3f37c9;

            --accent-color: #4cc9f0;

            --light-bg: #f8f9fa;

            --dark-text: #212529;

            --sidebar-width: 280px;

            --header-height: 70px;

            --success-color: #4ade80;

            --warning-color: #facc15;

            --danger-color: #f87171;

        }

        body {

            background: linear-gradient(135deg, #f5f7fa 0%, #e4edf5 100%);

            font-family: 'Segoe UI', Roboto, 'Helvetica Neue', sans-serif;

            margin: 0;

            padding: 0;

            min-height: 100vh;

        }

        .builder-header {

            height: var(--header-height);

            background: white;

            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.08);

            position: fixed;

            top: 0;

            left: 0;

            right: 0;

            z-index: 1000;

            display: flex;

            align-items: center;

            padding: 0 25px;

            border-bottom: 1px solid rgba(0, 0, 0, 0.05);

        }

        .builder-header h4 {

            font-size: 1.5rem;

            font-weight: 700;

            color: var(--primary-color);

            margin: 0;

        }

        .builder-header .btn {

            font-size: 0.9rem;

            padding: 8px 16px;

            border-radius: 8px;

            font-weight: 500;

            transition: all 0.3s ease;

        }

        .builder-header .btn i {

            font-size: 1.1rem;

        }

        .btn-primary {

            background: linear-gradient(135deg, var(--primary-color) 0%, var(--secondary-color) 100%);

            border: none;

            box-shadow: 0 4px 6px rgba(67, 97, 238, 0.3);

        }

        .btn-primary:hover {

            background: linear-gradient(135deg, #3a56e4 0%, #362fc2 100%);

            transform: translateY(-2px);

            box-shadow: 0 6px 8px rgba(67, 97, 238, 0.4);

        }

        .btn-success {

            background: linear-gradient(135deg, #10b981 0%, #059669 100%);

            border: none;

            box-shadow: 0 4px 6px rgba(16, 185, 129, 0.3);

        }

        .btn-success:hover {

            background: linear-gradient(135deg, #0da271 0%, #047857 100%);

            transform: translateY(-2px);

            box-shadow: 0 6px 8px rgba(16, 185, 129, 0.4);

        }

        .btn-info {

            background: linear-gradient(135deg, #0ea5e9 0%, #0284c7 100%);

            border: none;

            box-shadow: 0 4px 6px rgba(14, 165, 233, 0.3);

        }

        .btn-info:hover {

            background: linear-gradient(135deg, #0c95d2 0%, #026b9d 100%);

            transform: translateY(-2px);

            box-shadow: 0 6px 8px rgba(14, 165, 233, 0.4);

        }

        .btn-outline-secondary {

            border-color: #d1d5db;

            color: #4b5563;

        }

        .btn-outline-secondary:hover {

            background-color: #f3f4f6;

            border-color: #9ca3af;

        }

        .builder-container {

            display: flex;

            margin-top: var(--header-height);

            height: calc(100vh - var(--header-height));

            gap: 0;

        }

        .elements-panel {

            width: var(--sidebar-width);

            background: white;

            border-right: 1px solid #e8e8e8;

            overflow-y: auto;

            padding: 25px 20px;

            flex-shrink: 0;

            box-shadow: 2px 0 10px rgba(0, 0, 0, 0.03);

        }

        .canvas-area {

            flex: 1;

            padding: 25px;

            background: #f5f7fa;

            overflow-y: auto;

        }

        .settings-panel {

            width: var(--sidebar-width);

            background: white;

            border-left: 1px solid #e8e8e8;

            overflow-y: auto;

            padding: 25px 20px;

            flex-shrink: 0;

            box-shadow: -2px 0 10px rgba(0, 0, 0, 0.03);

        }

        .panel-title {

            font-size: 1.25rem;

            font-weight: 600;

            color: #1e293b;

            margin-bottom: 20px;

            padding-bottom: 12px;

            border-bottom: 2px solid var(--primary-color);

        }

        .element-item {

            background: linear-gradient(135deg, #ffffff 0%, #f8fafc 100%);

            border: 2px dashed #e2e8f0;

            border-radius: 12px;

            padding: 18px;

            margin-bottom: 15px;

            cursor: pointer;

            transition: all 0.3s ease;

            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.03);

        }

        .element-item:hover {

            background: linear-gradient(135deg, #f1f5f9 0%, #e2e8f0 100%);

            border-color: var(--primary-color);

            transform: translateY(-3px);

            box-shadow: 0 6px 12px rgba(67, 97, 238, 0.15);

        }

        .element-item i {

            font-size: 24px;

            margin-right: 12px;

            color: var(--primary-color);

            vertical-align: middle;

        }

        .element-item strong {

            font-size: 1.1rem;

            color: #1e293b;

            display: block;

            margin-bottom: 5px;

        }

        .element-item p {

            font-size: 0.9rem;

            color: #64748b;

            margin: 0;

        }

        .wysiwyg-element {

            transition: border-color 0.2s ease;

        }

        .wysiwyg-element.selected {

            border-color: var(--primary-color) !important;

            box-shadow: 0 0 0 2px rgba(67, 97, 238, 0.2);

        }

        .ql-container {

            border: 1px solid #e2e8f0;

            border-radius: 8px;

            overflow: hidden;

        }

        .ql-editor {

            min-height: 150px;

            font-size: 1rem;

        }

        .setting-group {

            margin-bottom: 25px;

            padding: 20px;

            background: #f8fafc;

            border-radius: 10px;

            border: 1px solid #e2e8f0;

        }

        .setting-label {

            font-weight: 600;

            margin-bottom: 12px;

            color: #1e293b;

            font-size: 1.1rem;

            display: flex;

            align-items: center;

        }

        .setting-label i {

            margin-right: 10px;

            color: var(--primary-color);

        }

        .color-picker {

            display: flex;

            align-items: center;

            gap: 12px;

        }

        .color-picker input[type="color"] {

            width: 45px;

            height: 45px;

            border: none;

            border-radius: 8px;

            cursor: pointer;

            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);

        }

        #emptyCanvasMessage {

            text-align: center;

            padding: 60px 30px;

            color: #94a3b8;

            background: white;

            border-radius: 12px;

            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.05);

        }

        #emptyCanvasMessage i {

            font-size: 60px;

            margin-bottom: 25px;

            color: #cbd5e1;

        }

        #emptyCanvasMessage h5 {

            font-size: 1.5rem;

            color: #475569;

            margin-bottom: 15px;

        }

        #emptyCanvasMessage p {

            font-size: 1.1rem;

            max-width: 500px;

            margin: 0 auto;

        }

        .inline-color-controls {

            background: #f1f5f9;

            padding: 18px;

            border-radius: 8px;

            margin-bottom: 20px;

            border: 1px solid #e2e8f0;

        }

        .inline-color-controls small {

            font-size: 0.9rem;

            margin-bottom: 12px;

            display: block;

            color: #64748b;

        }

        .form-label {

            font-size: 1rem;

            font-weight: 500;

            color: #334155;

            margin-bottom: 8px;

        }

        .form-control, .form-select {

            font-size: 1rem;

            padding: 10px 14px;

            border-radius: 8px;

            border: 1px solid #cbd5e1;

            transition: all 0.3s ease;

        }

        .form-control:focus, .form-select:focus {

            border-color: var(--primary-color);

            box-shadow: 0 0 0 3px rgba(67, 97, 238, 0.2);

        }

        .form-text {

            font-size: 0.875rem;

            color: #64748b;

        }

        @media (max-width: 991.98px) {

            .builder-container {

                flex-direction: column;

                height: auto;

            }

            .elements-panel, .settings-panel {

                width: 100%;

                border-right: none;

                border-left: none;

                border-bottom: 1px solid #e2e8f0;

                border-top: 1px solid #e2e8f0;

                max-height: 300px;

            }

            .canvas-area {

                width: 100%;

            }

        }

        ::-webkit-scrollbar {

            width: 8px;

            height: 8px;

        }

        ::-webkit-scrollbar-track {

            background: #f1f5f9;

            border-radius: 4px;

        }

        ::-webkit-scrollbar-thumb {

            background: #c7d2fe;

            border-radius: 4px;

        }

        ::-webkit-scrollbar-thumb:hover {

            background: #a5b4fc;

        }

        .wysiwyg-element .ql-editor:focus {

            outline: 2px solid var(--primary-color);

        }

        .faq-preview,

        .testimonial-preview {

            min-height: 60px;

            padding: 10px;

            background: #f8f9fa;

            border-radius: 6px;

            border: 1px dashed #ced4da;

        }

        

        /* NEW UI/UX IMPROVEMENTS */

        

        /* Onboarding Overlay */

        .onboarding-overlay {

            position: fixed;

            top: 0;

            left: 0;

            right: 0;

            bottom: 0;

            background: rgba(0, 0, 0, 0.7);

            z-index: 9999;

            animation: fadeIn 0.3s ease;

        }

        .onboarding-tooltip {

            position: absolute;

            background: white;

            border-radius: 12px;

            padding: 20px;

            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.3);

            max-width: 350px;

            animation: slideIn 0.3s ease;

        }

        .tour-content h5 {

            color: var(--primary-color);

            margin-bottom: 10px;

        }

        .tour-progress {

            text-align: center;

            margin-top: 15px;

            font-size: 0.9rem;

            color: #64748b;

        }

        .tour-actions {

            display: flex;

            justify-content: space-between;

            margin-top: 20px;

        }

        .tour-arrow {

            position: absolute;

            width: 0;

            height: 0;

            border-style: solid;

        }

        

        /* Autosave Indicator */

        .autosave-indicator {

            position: fixed;

            bottom: 20px;

            right: 20px;

            background: var(--success-color);

            color: white;

            padding: 12px 20px;

            border-radius: 30px;

            box-shadow: 0 4px 12px rgba(74, 222, 128, 0.4);

            display: flex;

            align-items: center;

            opacity: 0;

            transform: translateY(20px);

            transition: all 0.3s ease;

            z-index: 1000;

        }

        .autosave-indicator.show {

            opacity: 1;

            transform: translateY(0);

        }

        

        /* Undo Notification */

        .undo-notification {

            position: fixed;

            bottom: 20px;

            left: 50%;

            transform: translateX(-50%);

            background: white;

            border-radius: 12px;

            padding: 15px 20px;

            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.15);

            display: none;

            z-index: 1000;

            min-width: 300px;

        }

        .undo-content {

            display: flex;

            align-items: center;

            justify-content: space-between;

            margin-bottom: 10px;

        }

        .undo-progress {

            height: 3px;

            background: #e5e7eb;

            border-radius: 3px;

            overflow: hidden;

        }

        .undo-progress-bar {

            height: 100%;

            background: var(--danger-color);

            width: 100%;

            animation: progressCountdown 10s linear;

        }

        @keyframes progressCountdown {

            from { width: 100%; }

            to { width: 0%; }

        }

        

        /* Template Cards */

        .template-card {

            border: 2px solid #e5e7eb;

            border-radius: 12px;

            overflow: hidden;

            cursor: pointer;

            transition: all 0.3s ease;

        }

        .template-card:hover {

            border-color: var(--primary-color);

            transform: translateY(-5px);

            box-shadow: 0 10px 25px rgba(67, 97, 238, 0.2);

        }

        .template-thumb {

            width: 100%;

            height: 200px;

            object-fit: cover;

        }

        .template-info {

            padding: 15px;

        }

        .template-info h6 {

            margin-bottom: 5px;

            color: var(--dark-text);

        }

        

        /* Preview Device Selector */

        .preview-device-selector {

            position: fixed;

            top: 50%;

            right: 20px;

            transform: translateY(-50%);

            background: white;

            border-radius: 30px;

            padding: 8px;

            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1);

            z-index: 999;

        }

        .device-btn {

            width: 45px;

            height: 45px;

            border-radius: 50%;

            display: flex;

            align-items: center;

            justify-content: center;

            cursor: pointer;

            margin: 5px 0;

            transition: all 0.3s ease;

            color: #64748b;

        }

        .device-btn:hover,

        .device-btn.active {

            background: var(--primary-color);

            color: white;

        }

        

        /* Accessibility Improvements */

        .wysiwyg-element:focus {

            outline: 3px solid var(--accent-color);

            outline-offset: 2px;

        }

        .element-item:focus {

            outline: 3px solid var(--accent-color);

            outline-offset: 2px;

        }

        

        /* High Contrast Mode Support */

        @media (prefers-contrast: high) {

            .wysiwyg-element.selected {

                border-width: 3px;

            }

        }

        

        /* Reduced Motion Support */

        @media (prefers-reduced-motion: reduce) {

            * {

                animation-duration: 0.01ms !important;

                animation-iteration-count: 1 !important;

                transition-duration: 0.01ms !important;

            }

        }

        

        /* Keyboard Navigation Focus */

        .keyboard-nav .wysiwyg-element:focus {

            background: #f0f9ff;

            border-color: var(--primary-color);

        }

        

        /* Loading States */

        .loading {

            position: relative;

            pointer-events: none;

            opacity: 0.6;

        }

        .loading::after {

            content: '';

            position: absolute;

            top: 50%;

            left: 50%;

            width: 20px;

            height: 20px;

            margin: -10px 0 0 -10px;

            border: 2px solid var(--primary-color);

            border-radius: 50%;

            border-top-color: transparent;

            animation: spin 1s linear infinite;

        }

        

        /* Animations */

        @keyframes fadeIn {

            from { opacity: 0; }

            to { opacity: 1; }

        }

        @keyframes slideIn {

            from { 

                opacity: 0;

                transform: translateY(-20px);

            }

            to { 

                opacity: 1;

                transform: translateY(0);

            }

        }

        @keyframes spin {

            to { transform: rotate(360deg); }

        }

        

        /* Notifications */

        .notification {

            position: fixed;

            top: 90px;

            right: 20px;

            background: white;

            padding: 15px 20px;

            border-radius: 8px;

            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);

            display: flex;

            align-items: center;

            z-index: 9999;

            transform: translateX(400px);

            transition: transform 0.3s ease;

        }

        .notification.show {

            transform: translateX(0);

        }

        .notification-success {

            border-left: 4px solid var(--success-color);

        }

        .notification-info {

            border-left: 4px solid var(--primary-color);

        }

        .sr-only {

            position: absolute;

            width: 1px;

            height: 1px;

            padding: 0;

            margin: -1px;

            overflow: hidden;

            clip: rect(0, 0, 0, 0);

            white-space: nowrap;

            border: 0;

        }

        .tour-highlight {

            position: relative;

            z-index: 9998;

            box-shadow: 0 0 0 4px var(--primary-color);

            border-radius: 8px;

        }

        .preview-desktop { max-width: 100%; }

        .preview-tablet { max-width: 768px; margin: 0 auto; }

        .preview-mobile { max-width: 375px; margin: 0 auto; }

        

        /* Responsive Updates */

        @media (max-width: 768px) {

            .onboarding-tooltip {

                max-width: 280px;

                left: 10px !important;

                right: 10px !important;

            }

            .preview-device-selector {

                bottom: 20px;

                top: auto;

                right: 50%;

                transform: translateX(50%);

                flex-direction: row;

                padding: 5px;

                border-radius: 20px;

            }

            .device-btn {

                margin: 0 5px;

            }

        }

    </style>

</head>

<body>

    <!-- Onboarding Overlay -->

    <div id="onboardingOverlay" class="onboarding-overlay" style="display: none;">

        <div class="onboarding-tooltip" id="tourTooltip">

            <div class="tour-content">

                <h5 id="tourTitle">Selamat Datang!</h5>

                <p id="tourDescription">Mari kita mulai membangun landing page pertama Anda</p>

                <div class="tour-progress">

                    <span id="tourStep">1</span> / <span id="tourTotal">5</span>

                </div>

            </div>

            <div class="tour-actions">

                <button class="btn btn-sm btn-outline-secondary" id="tourSkip">Lewati</button>

                <button class="btn btn-sm btn-primary" id="tourNext">Lanjut</button>

            </div>

            <div class="tour-arrow"></div>

        </div>

    </div>



    <!-- Autosave Indicator -->

    <div class="autosave-indicator" id="autosaveIndicator">

        <i class="fas fa-check-circle me-2"></i>

        <span>Tersimpan otomatis</span>

    </div>



    <!-- Delete Confirmation Modal -->

    <div class="modal fade" id="deleteConfirmModal" tabindex="-1">

        <div class="modal-dialog modal-sm">

            <div class="modal-content">

                <div class="modal-header">

                    <h5 class="modal-title">Konfirmasi Hapus</h5>

                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>

                </div>

                <div class="modal-body">

                    <p>Yakin ingin menghapus elemen ini?</p>

                    <div class="form-check">

                        <input class="form-check-input" type="checkbox" id="undoOption">

                        <label class="form-check-label" for="undoOption">

                            Tampilkan opsi undo (10 detik)

                        </label>

                    </div>

                </div>

                <div class="modal-footer">

                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>

                    <button type="button" class="btn btn-danger" id="confirmDeleteBtn">Hapus</button>

                </div>

            </div>

        </div>

    </div>



    <!-- Undo Notification -->

    <div class="undo-notification" id="undoNotification">

        <div class="undo-content">

            <i class="fas fa-trash me-2"></i>

            <span>Elemen dihapus</span>

            <button class="btn btn-sm btn-outline-primary" id="undoBtn">Undo</button>

        </div>

        <div class="undo-progress">

            <div class="undo-progress-bar"></div>

        </div>

    </div>



    <!-- Template Library Modal -->

    <div class="modal fade" id="templateModal" tabindex="-1">

        <div class="modal-dialog modal-xl">

            <div class="modal-content">

                <div class="modal-header">

                    <h5 class="modal-title">Template Library</h5>

                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>

                </div>

                <div class="modal-body">

                    <div class="row g-3">

                        <div class="col-md-4">

                            <div class="template-card" data-template="lead-magnet">

                                <img src="https://via.placeholder.com/300x200/4361ee/ffffff?text=Lead+Magnet" class="template-thumb">

                                <div class="template-info">

                                    <h6>Lead Magnet</h6>

                                    <p class="small text-muted">Template untuk mengumpulkan email leads</p>

                                </div>

                            </div>

                        </div>

                        <div class="col-md-4">

                            <div class="template-card" data-template="sales-page">

                                <img src="https://via.placeholder.com/300x200/10b981/ffffff?text=Sales+Page" class="template-thumb">

                                <div class="template-info">

                                    <h6>Sales Page</h6>

                                    <p class="small text-muted">Template halaman penjualan produk</p>

                                </div>

                            </div>

                        </div>

                        <div class="col-md-4">

                            <div class="template-card" data-template="webinar">

                                <img src="https://via.placeholder.com/300x200/f59e0b/ffffff?text=Webinar" class="template-thumb">

                                <div class="template-info">

                                    <h6>Webinar Registration</h6>

                                    <p class="small text-muted">Template pendaftaran webinar</p>

                                </div>

                            </div>

                        </div>

                    </div>

                </div>

            </div>

        </div>

    </div>



    <!-- Preview Device Selector -->

    <div class="preview-device-selector" id="previewDeviceSelector" style="display: none;">

        <div class="device-btn active" data-device="desktop" title="Desktop">

            <i class="fas fa-desktop"></i>

        </div>

        <div class="device-btn" data-device="tablet" title="Tablet">

            <i class="fas fa-tablet-alt"></i>

        </div>

        <div class="device-btn" data-device="mobile" title="Mobile">

            <i class="fas fa-mobile-alt"></i>

        </div>

    </div>



    <!-- Copy HTML Modal -->

    <div class="modal fade" id="copyHtmlModal" tabindex="-1" aria-labelledby="copyHtmlModalLabel" aria-hidden="true">

        <div class="modal-dialog modal-xl">

            <div class="modal-content">

                <div class="modal-header">

                    <h5 class="modal-title" id="copyHtmlModalLabel">Export HTML Landing Page</h5>

                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>

                </div>

                <div class="modal-body">

                    <div class="mb-3">

                        <label for="htmlOutput" class="form-label">HTML Code:</label>

                        <textarea id="htmlOutput" class="form-control" rows="15" readonly></textarea>

                    </div>

                </div>

                <div class="modal-footer">

                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Tutup</button>

                    <button type="button" class="btn btn-primary" id="copyToClipboardBtn">Copy to Clipboard</button>

                </div>

            </div>

        </div>

    </div>



    <div class="builder-header">

        <div class="d-flex align-items-center">

            <h4 class="mb-0">

                <i class="fas fa-cube me-2"></i>

                LP Builder

            </h4>

        </div>

        <div class="ms-auto d-flex gap-2">

            <a href="../index.php" class="btn btn-outline-secondary">

                <i class="fas fa-arrow-left me-1"></i>

                <span class="d-none d-md-inline">Dashboard</span>

            </a>

            <button type="button" class="btn btn-outline-primary" id="templateBtn">

                <i class="fas fa-layer-group me-1"></i>

                <span class="d-none d-md-inline">Template</span>

            </button>

            <button type="button" class="btn btn-success" id="previewBtn">

                <i class="fas fa-eye me-1"></i>

                <span class="d-none d-md-inline">Preview</span>

            </button>

            <?php if ($page): ?>

            <a href="../<?= $page['slug'] ?>" class="btn btn-info" target="_blank">

                <i class="fas fa-external-link-alt me-1"></i>

                <span class="d-none d-md-inline">Lihat</span>

            </a>

            <?php endif; ?>

            <button type="submit" class="btn btn-primary" form="builderForm">

                <i class="fas fa-save me-1"></i>

                <span class="d-none d-md-inline">Simpan</span>

            </button>

            <?php if ($page_id > 0): ?>

            <button type="button" class="btn btn-outline-secondary" id="copyHtmlBtn">

                <i class="fas fa-copy me-1"></i>

                <span class="d-none d-md-inline">Copy HTML</span>

            </button>

            <?php endif; ?>

        </div>

    </div>

    <div class="builder-container">

        <div class="elements-panel" role="region" aria-label="Panel Elemen">

            <h5 class="panel-title"><i class="fas fa-plus-circle me-2"></i>Tambah Elemen</h5>

            <p class="text-muted small mb-4">Klik elemen untuk menambahkan ke halaman</p>

            <div class="element-item" data-type="header" role="button" tabindex="0" aria-label="Tambah elemen header">

                <i class="fas fa-heading" aria-hidden="true"></i>

                <strong>Header</strong>

                <p class="mb-0">Teks judul besar</p>

            </div>

            <div class="element-item" data-type="paragraph" role="button" tabindex="0" aria-label="Tambah elemen paragraf">

                <i class="fas fa-paragraph" aria-hidden="true"></i>

                <strong>Paragraf</strong>

                <p class="mb-0">Teks deskripsi</p>

            </div>

            <div class="element-item" data-type="divider" role="button" tabindex="0" aria-label="Tambah elemen pembatas">

                <i class="fas fa-grip-lines" aria-hidden="true"></i>

                <strong>Divider</strong>

                <p class="mb-0">Garis pemisah</p>

            </div>

            <div class="element-item" data-type="image" role="button" tabindex="0" aria-label="Tambah elemen gambar">

                <i class="fas fa-image" aria-hidden="true"></i>

                <strong>Gambar</strong>

                <p class="mb-0">Tambahkan gambar</p>

            </div>

            <div class="element-item" data-type="youtube" role="button" tabindex="0" aria-label="Tambah video YouTube">

                <i class="fab fa-youtube" aria-hidden="true"></i>

                <strong>YouTube Video</strong>

                <p class="mb-0">Embed video YouTube</p>

            </div>

            <div class="element-item" data-type="button" role="button" tabindex="0" aria-label="Tambah tombol">

                <i class="fas fa-mouse" aria-hidden="true"></i>

                <strong>Tombol</strong>

                <p class="mb-0">Tombol aksi</p>

            </div>

            <div class="element-item" data-type="form" role="button" tabindex="0" aria-label="Tambah formulir">

                <i class="fas fa-envelope" aria-hidden="true"></i>

                <strong>Form Kontak</strong>

                <p class="mb-0">Formulir kontak</p>

            </div>

            <div class="element-item" data-type="html" role="button" tabindex="0" aria-label="Tambah HTML kustom">

                <i class="fas fa-code" aria-hidden="true"></i>

                <strong>HTML Kustom</strong>

                <p class="mb-0">Kode HTML bebas</p>

            </div>

            <div class="element-item" data-type="faq" role="button" tabindex="0" aria-label="Tambah FAQ">

                <i class="fas fa-question-circle" aria-hidden="true"></i>

                <strong>FAQ</strong>

                <p class="mb-0">Pertanyaan & jawaban</p>

            </div>

            <div class="element-item" data-type="testimonial" role="button" tabindex="0" aria-label="Tambah testimoni">

                <i class="fas fa-quote-right" aria-hidden="true"></i>

                <strong>Testimoni</strong>

                <p class="mb-0">Ulasan pelanggan</p>

            </div>			

        </div>

        <div class="canvas-area" role="main" aria-label="Area Kerja Landing Page">

            <form method="POST" id="builderForm" aria-label="Form Builder">

                <div class="card mb-4">

                    <div class="card-header">

                        <h5 class="mb-0"><i class="fas fa-cog me-2"></i> Pengaturan LP</h5>

                    </div>

                    <div class="card-body">

                        <div class="mb-4">

                            <label class="form-label">Judul Landing Page</label>

                            <input type="text" name="title" class="form-control" value="<?= htmlspecialchars($page['title'] ?? '') ?>" required placeholder="Masukkan judul landing page">

                        </div>

                        <div class="mb-4">

                            <label class="form-label">Slug</label>

                            <input type="text" name="slug" class="form-control" value="<?= htmlspecialchars($page['slug'] ?? '') ?>" required placeholder="slug-landing-page">

                            <div class="form-text">Slug akan digunakan sebagai URL landing page.</div>

                        </div>

                        <div class="row">

                            <div class="col-md-6 mb-4">

                                <label class="form-label">Meta Pixel ID</label>

                                <input type="text" name="pixel_id" class="form-control"

                                    value="<?= htmlspecialchars($page['pixel_id'] ?? '') ?>"

                                    placeholder="123456789012345">

                                <div class="form-text">ID Pixel Facebook Anda.</div>

                            </div>

                            <div class="col-md-6 mb-4">

                                <label class="form-label">Meta Event Name</label>

                                <select name="meta_event_name" class="form-select">

                                    <option value="ViewContent" <?= ($page['meta_event_name'] ?? 'ViewContent') == 'ViewContent' ? 'selected' : '' ?>>ViewContent</option>

                                    <option value="Lead" <?= ($page['meta_event_name'] ?? '') == 'Lead' ? 'selected' : '' ?>>Lead</option>

                                    <option value="Contact" <?= ($page['meta_event_name'] ?? '') == 'Contact' ? 'selected' : '' ?>>Contact</option>

                                    <option value="CompleteRegistration" <?= ($page['meta_event_name'] ?? '') == 'CompleteRegistration' ? 'selected' : '' ?>>CompleteRegistration</option>

                                    <option value="InitiateCheckout" <?= ($page['meta_event_name'] ?? '') == 'InitiateCheckout' ? 'selected' : '' ?>>InitiateCheckout</option>

                                    <option value="AddPaymentInfo" <?= ($page['meta_event_name'] ?? '') == 'AddPaymentInfo' ? 'selected' : '' ?>>AddPaymentInfo</option>

                                    <option value="Purchase" <?= ($page['meta_event_name'] ?? '') == 'Purchase' ? 'selected' : '' ?>>Purchase</option>

                                    <option value="Custom" <?= ($page['meta_event_name'] ?? '') == 'Custom' ? 'selected' : '' ?>>Custom (isi manual)</option>

                                </select>

                                <div class="form-text">Pilih event standar Meta atau pilih Custom untuk event khusus.</div>

                            </div>

                            <div class="col-md-6 mb-4">

                                <label class="form-label">Status</label>

                                <select name="status" class="form-select">

                                    <option value="draft" <?= ($page['status'] ?? '') == 'draft' ? 'selected' : '' ?>>Draft</option>

                                    <option value="published" <?= ($page['status'] ?? '') == 'published' ? 'selected' : '' ?>>Published</option>

                                </select>

                            </div>

                        </div>

                        <div class="row">

                            <div class="col-md-6 mb-4">

                                <label class="form-label">CAPI Endpoint (Opsional)</label>

                                <input type="url" name="capi_endpoint" class="form-control"

                                    value="<?= htmlspecialchars($page['capi_endpoint'] ?? '') ?>"

                                    placeholder="Biarkan kosong untuk dibuat otomatis">

                                <div class="form-text">Akan dibuat otomatis dari Pixel ID jika dikosongkan.</div>

                            </div>

                            <div class="col-md-6 mb-4">

                                <label class="form-label">CAPI Access Token (Opsional)</label>

                                <input type="text" name="capi_access_token" class="form-control"

                                    value="<?= htmlspecialchars($page['capi_access_token'] ?? '') ?>"

                                    placeholder="Token akses CAPI">

                                <div class="form-text">Access token untuk CAPI.</div>

                            </div>

                        </div>

                    </div>

                </div>

                <div id="canvasElements" role="region" aria-label="Elemen Landing Page">

                    <?php foreach ($elements as $index => $element): ?>

                        <?php if (in_array($element['type'], ['section_1col', 'section_2col', 'section_3col'])) continue; ?>

                        <?= renderElementForCanvas($element, $index) ?>

                    <?php endforeach; ?>

                </div>

                <div class="text-center text-muted py-5" id="emptyCanvasMessage"

                    style="<?= empty($elements) ? '' : 'display: none;' ?>"

                    role="alert" aria-live="polite">

                    <i class="fas fa-mouse-pointer fa-2x mb-4"></i>

                    <h5 class="mb-3">Klik elemen dari panel kiri</h5>

                    <p class="mb-0">Mulai bangun landing page Anda dengan menambahkan elemen-elemen di samping</p>

                </div>

            </form>

        </div>

        <div class="settings-panel">

            <h5 class="panel-title"><i class="fas fa-sliders-h me-2"></i>Pengaturan Elemen</h5>

            <div id="settingsContent">

                <div class="text-center text-muted py-5">

                    <i class="fas fa-info-circle fa-lg mb-3"></i>

                    <p class="mb-0">Pilih elemen di canvas untuk mengatur propertinya</p>

                </div>

            </div>

        </div>

    </div>



    <script src="https://cdn.quilljs.com/1.3.6/quill.min.js"></script>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>

    <script>

        // Global Variables

        let quillEditors = {};

        let selectedElement = null;

        let history = [];

        let historyIndex = -1;

        let autosaveTimer;

        let deletedElement = null;

        let undoTimer;

        let currentTourStep = 0;

        let isKeyboardNav = false;



        // Tour Configuration

        const tourSteps = [

            {

                element: '.elements-panel',

                title: 'Panel Elemen',

                description: 'Pilih elemen di sini untuk membangun landing page Anda',

                position: 'right'

            },

            {

                element: '#canvasElements',

                title: 'Area Kerja',

                description: 'Elemen akan muncul di sini. Klik untuk mengedit',

                position: 'center'

            },

            {

                element: '.settings-panel',

                title: 'Panel Pengaturan',

                description: 'Atur properti elemen yang dipilih di sini',

                position: 'left'

            },

            {

                element: '.builder-header .btn-primary',

                title: 'Simpan Progress',

                description: 'Klik tombol ini untuk menyimpan landing page Anda',

                position: 'bottom'

            },

            {

                element: '#previewBtn',

                title: 'Preview',

                description: 'Lihat preview landing page sebelum dipublikasikan',

                position: 'bottom'

            }

        ];



        // Initialize Onboarding

        function initializeOnboarding() {

            const hasSeenTour = localStorage.getItem('hasSeenTour');

            if (!hasSeenTour && $('.wysiwyg-element').length === 0) {

                setTimeout(() => startTour(), 1000);

            }

        }



        function startTour() {

            currentTourStep = 0;

            $('#onboardingOverlay').show();

            showTourStep();

        }



        function showTourStep() {

            const step = tourSteps[currentTourStep];

            const $element = $(step.element);

            

            if ($element.length === 0) {

                nextTourStep();

                return;

            }

            

            const $tooltip = $('#tourTooltip');

            const elementPos = $element.offset();

            const elementWidth = $element.outerWidth();

            const elementHeight = $element.outerHeight();

            

            // Update content

            $('#tourTitle').text(step.title);

            $('#tourDescription').text(step.description);

            $('#tourStep').text(currentTourStep + 1);

            $('#tourTotal').text(tourSteps.length);

            

            // Position tooltip

            $tooltip.removeClass('top bottom left right center');

            

            switch(step.position) {

                case 'right':

                    $tooltip.addClass('left').css({

                        left: elementPos.left + elementWidth + 20,

                        top: elementPos.top + (elementHeight / 2) - ($tooltip.outerHeight() / 2)

                    });

                    break;

                case 'left':

                    $tooltip.addClass('right').css({

                        left: elementPos.left - $tooltip.outerWidth() - 20,

                        top: elementPos.top + (elementHeight / 2) - ($tooltip.outerHeight() / 2)

                    });

                    break;

                case 'bottom':

                    $tooltip.addClass('top').css({

                        left: elementPos.left + (elementWidth / 2) - ($tooltip.outerWidth() / 2),

                        top: elementPos.top + elementHeight + 20

                    });

                    break;

                case 'center':

                    $tooltip.addClass('center').css({

                        left: '50%',

                        top: '50%',

                        transform: 'translate(-50%, -50%)'

                    });

                    break;

                default:

                    $tooltip.addClass('bottom').css({

                        left: elementPos.left + (elementWidth / 2) - ($tooltip.outerWidth() / 2),

                        top: elementPos.top - $tooltip.outerHeight() - 20

                    });

            }

            

            // Highlight element

            $('.tour-highlight').removeClass('tour-highlight');

            $element.addClass('tour-highlight');

        }



        function nextTourStep() {

            currentTourStep++;

            if (currentTourStep >= tourSteps.length) {

                endTour();

            } else {

                showTourStep();

            }

        }



        function endTour() {

            $('#onboardingOverlay').fadeOut();

            $('.tour-highlight').removeClass('tour-highlight');

            localStorage.setItem('hasSeenTour', 'true');

        }



        // Initialize Undo/Redo System

        function saveState() {

            const state = {

                elements: $('#canvasElements').html(),

                timestamp: Date.now()

            };

            

            // Remove any states after current index

            history = history.slice(0, historyIndex + 1);

            

            // Add new state

            history.push(state);

            historyIndex++;

            

            // Limit history to 50 states

            if (history.length > 50) {

                history.shift();

                historyIndex--;

            }

            

            updateUndoRedoButtons();

        }



        function undo() {

            if (historyIndex > 0) {

                historyIndex--;

                restoreState(history[historyIndex]);

                updateUndoRedoButtons();

            }

        }



        function redo() {

            if (historyIndex < history.length - 1) {

                historyIndex++;

                restoreState(history[historyIndex]);

                updateUndoRedoButtons();

            }

        }



        function restoreState(state) {

            $('#canvasElements').html(state.elements);

            updateElementIndices();

            initializeQuillEditors();

        }



        function updateUndoRedoButtons() {

            // Add undo/redo buttons to header if not exist

            if ($('#undoBtnHeader, #redoBtnHeader').length === 0) {

                $('.builder-header .ms-auto').prepend(`

                    <button type="button" class="btn btn-outline-secondary me-2" id="undoBtnHeader" disabled title="Undo (Ctrl+Z)">

                        <i class="fas fa-undo"></i>

                    </button>

                    <button type="button" class="btn btn-outline-secondary me-2" id="redoBtnHeader" disabled title="Redo (Ctrl+Y)">

                        <i class="fas fa-redo"></i>

                    </button>

                `);

            }

            

            $('#undoBtnHeader').prop('disabled', historyIndex <= 0);

            $('#redoBtnHeader').prop('disabled', historyIndex >= history.length - 1);

        }



        // Autosave Functionality

        function autosave() {

            // Save current state

            Object.keys(quillEditors).forEach(i => {

                if (quillEditors[i]) {

                    $(`.wysiwyg-content[name="elements[${i}][content]"]`).val(quillEditors[i].root.innerHTML);

                }

            });

            

            // Show autosave indicator

            const $indicator = $('#autosaveIndicator');

            $indicator.addClass('show');

            

            // Simulate save to server

            $.ajax({

                url: 'autosave.php',

                method: 'POST',

                data: $('#builderForm').serialize(),

                success: function() {

                    setTimeout(() => {

                        $indicator.removeClass('show');

                    }, 2000);

                }

            });

        }



        // Enhanced Delete with Undo

        function deleteElementWithUndo(element) {

            deletedElement = {

                element: element.clone(),

                index: element.index()

            };

            

            element.fadeOut(300, function() {

                $(this).remove();

                

                if ($('#canvasElements > .wysiwyg-element').length === 0) {

                    $('#emptyCanvasMessage').show();

                }

                

                updateElementIndices();

                saveState();

                

                // Show undo notification

                showUndoNotification();

            });

        }



        function showUndoNotification() {

            const $notification = $('#undoNotification');

            $notification.fadeIn();

            

            // Clear existing timer

            if (undoTimer) clearTimeout(undoTimer);

            

            // Set new timer

            undoTimer = setTimeout(() => {

                $notification.fadeOut();

                deletedElement = null;

            }, 10000);

        }



        function undoDelete() {

            if (deletedElement) {

                const $elements = $('#canvasElements');

                

                if (deletedElement.index === 0 || $elements.children().length === 0) {

                    $elements.prepend(deletedElement.element);

                } else {

                    $elements.children().eq(deletedElement.index - 1).after(deletedElement.element);

                }

                

                updateElementIndices();

                initializeQuillEditors();

                $('#emptyCanvasMessage').hide();

                

                $('#undoNotification').fadeOut();

                deletedElement = null;

                

                if (undoTimer) clearTimeout(undoTimer);

            }

        }



        // Template System

        function loadTemplate(templateName) {

            const templates = {

                'lead-magnet': {

                    elements: [

                        { type: 'header', content: 'Dapatkan Ebook Gratis!' },

                        { type: 'paragraph', content: 'Masukkan email Anda untuk mendapatkan panduan eksklusif.' },

                        { type: 'form', content: '<form><input type="email" placeholder="Email Anda" class="form-control mb-2"><button type="submit" class="btn btn-primary w-100">Download Gratis</button></form>' },

                        { type: 'testimonial', content: '[{"name":"John Doe","role":"CEO","text":"Ebook yang sangat berguna!"}]' }

                    ]

                },

                'sales-page': {

                    elements: [

                        { type: 'header', content: 'Produk Terbaik untuk Anda' },

                        { type: 'image', content: 'https://via.placeholder.com/600x400' },

                        { type: 'paragraph', content: 'Deskripsi produk yang menarik dan meyakinkan.' },

                        { type: 'button', content: 'Beli Sekarang', styles: { link: '#', bg_color: '#10b981', text_color: '#ffffff' } },

                        { type: 'faq', content: '[{"question":"Apakah ada garansi?","answer":"Ya, kami berikan garansi 30 hari."}]' }

                    ]

                },

                'webinar': {

                    elements: [

                        { type: 'header', content: 'Webinar Eksklusif' },

                        { type: 'youtube', content: 'dQw4w9WgXcQ' },

                        { type: 'paragraph', content: 'Daftar sekarang untuk slot terbatas!' },

                        { type: 'form', content: '<form><input type="text" placeholder="Nama" class="form-control mb-2"><input type="email" placeholder="Email" class="form-control mb-2"><button type="submit" class="btn btn-primary w-100">Daftar Gratis</button></form>' }

                    ]

                }

            };

            

            const template = templates[templateName];

            if (!template) return;

            

            // Clear existing elements

            $('#canvasElements').empty();

            

            // Add template elements

            template.elements.forEach((elem, index) => {

                const elementHtml = createElementHtml(elem.type, index);

                const $element = $(elementHtml);

                

                // Set content

                if (elem.content) {

                    $element.find(`[name="elements[${index}][content]"]`).val(elem.content);

                    if (elem.type === 'header' || elem.type === 'paragraph') {

                        $element.find('.editable-content').html(elem.content);

                    } else if (elem.type === 'image') {

                        $element.find('.wysiwyg-image').attr('src', elem.content);

                    } else if (elem.type === 'youtube') {

                        $element.find('iframe').attr('src', `https://www.youtube.com/embed/${elem.content}`);

                    }

                }

                

                // Set styles

                if (elem.styles) {

                    Object.keys(elem.styles).forEach(key => {

                        $element.find(`[name="elements[${index}][styles][${key}]"]`).val(elem.styles[key]);

                    });

                }

                

                $('#canvasElements').append($element);

            });

            

            $('#emptyCanvasMessage').hide();

            updateElementIndices();

            initializeQuillEditors();

            saveState();

            

            // Close modal

            bootstrap.Modal.getInstance(document.getElementById('templateModal')).hide();

            

            // Show success message

            showNotification('Template berhasil dimuat!', 'success');

        }



        // Preview Device System

        function setPreviewDevice(device) {

            const $canvas = $('.canvas-area');

            const $selector = $('#previewDeviceSelector');

            

            // Remove existing device classes

            $canvas.removeClass('preview-desktop preview-tablet preview-mobile');

            

            // Add new device class

            $canvas.addClass(`preview-${device}`);

            

            // Update active button

            $selector.find('.device-btn').removeClass('active');

            $selector.find(`[data-device="${device}"]`).addClass('active');

            

            // Save preference

            localStorage.setItem('previewDevice', device);

        }



        // Keyboard Navigation

        function initializeKeyboardNavigation() {

            $(document).on('keydown', function(e) {

                // Ctrl/Cmd + Z for undo

                if ((e.ctrlKey || e.metaKey) && e.key === 'z' && !e.shiftKey) {

                    e.preventDefault();

                    undo();

                }

                

                // Ctrl/Cmd + Shift + Z or Ctrl/Cmd + Y for redo

                if (((e.ctrlKey || e.metaKey) && e.shiftKey && e.key === 'z') || 

                    ((e.ctrlKey || e.metaKey) && e.key === 'y')) {

                    e.preventDefault();

                    redo();

                }

                

                // Escape to close modals

                if (e.key === 'Escape') {

                    $('.modal.show').modal('hide');

                    $('#onboardingOverlay').hide();

                }

                

                // Tab navigation for elements

                if (e.key === 'Tab') {

                    isKeyboardNav = true;

                    $('body').addClass('keyboard-nav');

                }

            });

            

            $(document).on('mousedown', function() {

                isKeyboardNav = false;

                $('body').removeClass('keyboard-nav');

            });

        }



        // Notification System

        function showNotification(message, type = 'info') {

            const $notification = $(`

                <div class="notification notification-${type} show">

                    <i class="fas fa-${type === 'success' ? 'check-circle' : 'info-circle'} me-2"></i>

                    ${message}

                </div>

            `);

            

            $('body').append($notification);

            

            setTimeout(() => {

                $notification.removeClass('show');

                setTimeout(() => $notification.remove(), 300);

            }, 3000);

        }



        // Fungsi untuk inisialisasi Quill

        function initializeQuillForElement(index) {

            if (quillEditors[index]) return;

            const element = $(`.editable-content[data-editor-index="${index}"]`);

            if (!element.length) return;



            const editorId = `#wysiwyg-quill-editor-${index}`;

            const editorHtml = `<div id="wysiwyg-quill-editor-${index}" class="ql-editor" style="border: 1px solid #ccc; padding: 10px; margin-top: 10px;"></div>`;

            element.after(editorHtml);



            quillEditors[index] = new Quill(`#wysiwyg-quill-editor-${index}`, {

                theme: 'snow',

                modules: {

                    toolbar: [

                        [{ 'header': [1, 2, 3, false] }],

                        ['bold', 'italic', 'underline', 'strike'],

                        [{ 'list': 'ordered' }, { 'list': 'bullet' }],

                        [{ 'align': [] }],

                        ['link'],

                        ['clean']

                    ]

                }

            });



            // Load content

            const initialContent = $(`.wysiwyg-content[name="elements[${index}][content]"]`).val();

            quillEditors[index].root.innerHTML = initialContent;



            // Sinkronkan perubahan ke textarea

            quillEditors[index].on('text-change', function() {

                const contentElement = $(`.wysiwyg-content[name="elements[${index}][content]"]`);

                if (contentElement.length) {

                    contentElement.val(quillEditors[index].root.innerHTML);

                }

            });

        }



        function initializeQuillEditors() {

            $('.editable-content').each(function() {

                const index = $(this).data('editor-index');

                if (index !== undefined) {

                    initializeQuillForElement(index);

                }

            });

        }



        // Event: Klik elemen di canvas (WYSIWYG)

        $(document).on('click', '.wysiwyg-element', function(e) {

            if ($(e.target).closest('.ql-editor').length > 0) return; // Jangan trigger jika klik di dalam Quill



            const $el = $(this);

            const type = $el.data('element-type');

            const index = $el.data('element-index');



            // Tutup editor Quill sebelumnya

            if (selectedElement && selectedElement !== $el) {

                const prevIndex = selectedElement.data('element-index');

                const prevType = selectedElement.data('element-type');

                if ((prevType === 'header' || prevType === 'paragraph') && quillEditors[prevIndex]) {

                    // Sembunyikan editor Quill dan tampilkan kembali contenteditable

                    $(`#wysiwyg-quill-editor-${prevIndex}`).hide();

                    selectedElement.find('.editable-content').show();

                }

            }



            // Hapus seleksi sebelumnya

            $('.wysiwyg-element').removeClass('selected');

            // Tambahkan seleksi baru

            $el.addClass('selected');

            selectedElement = $el;



            // Highlight di panel samping

            showElementSettings($el);



            // Inisialisasi Quill jika elemen adalah header/paragraph

            if (type === 'header' || type === 'paragraph') {

                // Sembunyikan contenteditable lama (jika ada)

                $el.find('.editable-content').hide();

                // Inisialisasi atau tampilkan editor Quill

                if (!quillEditors[index]) {

                    initializeQuillForElement(index);

                } else {

                    // Jika sudah ada, pastikan ditampilkan

                    $(`#wysiwyg-quill-editor-${index}`).show();

                }

            }

        });



        // Event: Tambah elemen dari panel kiri

        $('.element-item').click(function() {

            const type = $(this).data('type');

            if (['section_1col', 'section_2col', 'section_3col'].includes(type)) return;

            addElementToCanvas(type);

            $('#emptyCanvasMessage').hide();

        });



        // Fungsi untuk menambah elemen ke canvas

        function addElementToCanvas(type) {

            const newIndex = $('#canvasElements > .wysiwyg-element').length;

            const elementHtml = createElementHtml(type, newIndex);

            $('#canvasElements').append(elementHtml);

            updateElementIndices();

            saveState();

        }



        // Fungsi untuk membuat elemen HTML baru

        function createElementHtml(type, index) {

            if (['section_1col', 'section_2col', 'section_3col'].includes(type)) return '';



            let contentHtml = '';

            let elementStyle = '';

            if (['header', 'paragraph'].includes(type)) {

                contentHtml = `

                    <div class="editable-content" data-editor-index="${index}" contenteditable="true"></div>

                    <textarea name="elements[${index}][content]" class="d-none wysiwyg-content"></textarea>

                    <div id="wysiwyg-quill-editor-${index}" class="ql-editor" style="border: 1px solid #ccc; padding: 10px; margin-top: 10px; display: none;"></div>

                    <input type="hidden" name="elements[${index}][styles][bg_color]" class="style-input-bg" value="#ffffff">

                    <input type="hidden" name="elements[${index}][styles][text_color]" class="style-input-text" value="#000000">

                `;

                elementStyle = 'background-color: #ffffff; color: #000000;';

            } else if (type === 'divider') {

                contentHtml = `

                    <hr style="border: 0; border-top: 2px solid #94a3b8; margin: 20px 0;">

                    <input type="hidden" name="elements[${index}][content]" value="">

                    <input type="hidden" name="elements[${index}][styles][style]" class="style-input-divider-style" value="solid">

                    <input type="hidden" name="elements[${index}][styles][color]" class="style-input-divider-color" value="#94a3b8">

                `;

            } else if (type === 'image') {

                contentHtml = `

                    <img src="" class="img-fluid wysiwyg-image" alt="Gambar" style="max-width: 100%;">

                    <input type="hidden" name="elements[${index}][content]" class="wysiwyg-image-url" value="">

                `;

            } else if (type === 'button') {

                contentHtml = `

                    <a href="#" class="btn wysiwyg-button" style="background-color: #3b82f6; color: white;">Teks Tombol</a>

                    <input type="hidden" name="elements[${index}][content]" class="wysiwyg-button-text" value="Teks Tombol">

                    <input type="hidden" name="elements[${index}][styles][link]" class="wysiwyg-button-link" value="#">

                    <input type="hidden" name="elements[${index}][styles][bg_color]" class="wysiwyg-button-bg" value="#3b82f6">

                    <input type="hidden" name="elements[${index}][styles][text_color]" class="wysiwyg-button-text-color" value="#ffffff">

                `;

            } else if (type === 'youtube') {

                contentHtml = `

                    <div class="ratio ratio-16x9 mb-3">

                        <iframe src="about:blank" title="YouTube video player" frameborder="0" 

                            allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share" 

                            allowfullscreen></iframe>

                    </div>

                    <input type="hidden" name="elements[${index}][content]" class="wysiwyg-youtube-id" value="">

                `;

            } else if (type === 'form') {

                contentHtml = `

                    <div class="alert alert-info wysiwyg-form-content"></div>

                    <textarea name="elements[${index}][content]" class="d-none wysiwyg-form-content-input"></textarea>

                `;

            } else if (type === 'faq') {

                contentHtml = `

                    <div class="faq-preview">

                        <em class="text-muted">Klik untuk atur FAQ</em>

                    </div>

                    <input type="hidden" name="elements[${index}][content]" class="wysiwyg-faq-content" value="[]">

                `;

            } else if (type === 'testimonial') {

                contentHtml = `

                    <div class="testimonial-preview">

                        <em class="text-muted">Klik untuk atur testimoni</em>

                    </div>

                    <input type="hidden" name="elements[${index}][content]" class="wysiwyg-testimonial-content" value="[]">

                `;

            } else if (type === 'html') {

                contentHtml = `

                    <div class="custom-html-content wysiwyg-html-content"></div>

                    <textarea name="elements[${index}][content]" class="d-none wysiwyg-html-content-input"></textarea>

                `;

            }



            return `

                <div class="wysiwyg-element" data-element-index="${index}" data-element-type="${type}" style="${elementStyle} border: 2px solid transparent; padding: 10px; margin: 10px 0; border-radius: 5px; transition: border-color 0.2s; cursor: pointer;" tabindex="0" role="button" aria-label="Elemen ${type}">

                    <input type="hidden" name="elements[${index}][type]" value="${type}">

                    ${contentHtml}

                </div>

            `;

        }



        // Fungsi untuk menampilkan pengaturan elemen

        function showElementSettings(element) {

            const index = element.data('element-index');

            const type = element.data('element-type');

            const total = $('#canvasElements > .wysiwyg-element').length;

            const pos = index + 1;



            let html = `

                <div class="setting-group">

                    <div class="setting-label"><i class="fas fa-info-circle me-2"></i>Tipe Elemen</div>

                    <div class="alert alert-info py-3 px-4">

                        ${type.replace('_', ' ').replace(/\b\w/g, l => l.toUpperCase())}

                    </div>

                </div>

                <div class="setting-group">

                    <div class="setting-label"><i class="fas fa-sort-numeric-up me-2"></i>Ubah Urutan</div>

                    <div class="input-group mb-3">

                        <input type="number" class="form-control" value="${pos}" min="1" max="${total}" id="newPos">

                        <button class="btn btn-outline-primary" id="moveBtn">Pindahkan</button>

                    </div>

                </div>

                <div class="setting-group">

                    <div class="setting-label"><i class="fas fa-trash me-2"></i>Hapus Elemen</div>

                    <button class="btn btn-danger w-100" id="deleteBtn">Hapus Elemen Ini</button>

                </div>

            `;



            // Tambahkan pengaturan spesifik per tipe

            if (type === 'header' || type === 'paragraph') {

                const currentBg = element.find('.style-input-bg').val() || '#ffffff';

                const currentText = element.find('.style-input-text').val() || '#000000';

                html += `

                    <div class="setting-group">

                        <div class="setting-label"><i class="fas fa-palette me-2"></i>Warna Teks</div>

                        <input type="color" class="form-control form-control-color" id="textColorPicker-${index}" value="${currentText}">

                    </div>

                    <div class="setting-group">

                        <div class="setting-label"><i class="fas fa-fill-drip me-2"></i>Warna Background</div>

                        <input type="color" class="form-control form-control-color" id="bgColorPicker-${index}" value="${currentBg}">

                    </div>

                    <div class="setting-group">

                        <div class="setting-label"><i class="fas fa-font me-2"></i>Warna Teks Seleksi</div>

                        <div class="input-group">

                            <input type="color" class="form-control form-control-color" id="textColorPickerSel-${index}" value="#000000">

                            <button class="btn btn-outline-secondary" id="applyTextColorBtn-${index}">Terapkan</button>

                        </div>

                        <div class="form-text">Pilih teks di editor, lalu klik "Terapkan".</div>

                    </div>

                    <div class="setting-group">

                        <div class="setting-label"><i class="fas fa-fill-drip me-2"></i>Warna Background Teks Seleksi</div>

                        <div class="input-group">

                            <input type="color" class="form-control form-control-color" id="bgTextColorPickerSel-${index}" value="#ffffff">

                            <button class="btn btn-outline-secondary" id="applyBgTextColorBtn-${index}">Terapkan</button>

                        </div>

                        <div class="form-text">Pilih teks di editor, lalu klik "Terapkan".</div>

                    </div>

                `;

            } else if (type === 'divider') {

                const currentStyle = element.find('.style-input-divider-style').val() || 'solid';

                const currentColor = element.find('.style-input-divider-color').val() || '#94a3b8';

                html += `

                    <div class="setting-group">

                        <div class="setting-label"><i class="fas fa-grip-lines me-2"></i>Gaya Divider</div>

                        <select class="form-select" id="dividerStyle-${index}">

                            <option value="solid" ${currentStyle === 'solid' ? 'selected' : ''}>Solid</option>

                            <option value="dashed" ${currentStyle === 'dashed' ? 'selected' : ''}>Dashed</option>

                            <option value="dotted" ${currentStyle === 'dotted' ? 'selected' : ''}>Dotted</option>

                        </select>

                    </div>

                    <div class="setting-group">

                        <div class="setting-label"><i class="fas fa-palette me-2"></i>Warna Divider</div>

                        <input type="color" class="form-control form-control-color" id="dividerColor-${index}" value="${currentColor}">

                    </div>

                `;

            } else if (type === 'image') {

                const currentUrl = element.find('.wysiwyg-image-url').val() || '';

                html += `

                    <div class="setting-group">

                        <div class="setting-label"><i class="fas fa-link me-2"></i>URL Gambar</div>

                        <input type="url" class="form-control" id="imageUrl-${index}" value="${currentUrl}" placeholder="https://example.com/image.jpg">

                    </div>

                `;

            } else if (type === 'youtube') {

                const currentId = element.find('.wysiwyg-youtube-id').val() || '';

                html += `

                    <div class="setting-group">

                        <div class="setting-label"><i class="fab fa-youtube me-2"></i>YouTube URL atau ID</div>

                        <input type="text" class="form-control" id="youtubeUrl-${index}" 

                            value="${currentId}" 

                            placeholder="https://youtu.be/abc123 atau abc123">

                        <div class="form-text">

                            Masukkan URL YouTube atau cukup ID videonya (misal: <code>dQw4w9WgXcQ</code>).

                        </div>

                    </div>

                    <div class="setting-group">

                        <button class="btn btn-outline-primary w-100" id="applyYoutubeBtn-${index}">

                            <i class="fas fa-sync-alt me-1"></i> Terapkan Video

                        </button>

                    </div>

                `;

            } else if (type === 'button') {

                const currentText = element.find('.wysiwyg-button-text').val() || '';

                const currentLink = element.find('.wysiwyg-button-link').val() || '#';

                const currentBg = element.find('.wysiwyg-button-bg').val() || '#3b82f6';

                const currentTextCol = element.find('.wysiwyg-button-text-color').val() || '#ffffff';

                html += `

                    <div class="setting-group">

                        <div class="setting-label"><i class="fas fa-font me-2"></i>Teks Tombol</div>

                        <input type="text" class="form-control" id="buttonText-${index}" value="${currentText}" placeholder="Teks tombol">

                    </div>

                    <div class="setting-group">

                        <div class="setting-label"><i class="fas fa-link me-2"></i>Link Tujuan</div>

                        <input type="url" class="form-control" id="buttonLink-${index}" value="${currentLink}" placeholder="https://example.com">

                    </div>

                    <div class="setting-group">

                        <div class="setting-label"><i class="fas fa-palette me-2"></i>Warna Teks</div>

                        <input type="color" class="form-control form-control-color" id="buttonTextColor-${index}" value="${currentTextCol}">

                    </div>

                    <div class="setting-group">

                        <div class="setting-label"><i class="fas fa-fill-drip me-2"></i>Warna Background</div>

                        <input type="color" class="form-control form-control-color" id="buttonBgColor-${index}" value="${currentBg}">

                    </div>

                `;

            } else if (type === 'form') {

                const currentContent = element.find('.wysiwyg-form-content-input').val() || '';

                html += `

                    <div class="setting-group">

                        <div class="setting-label"><i class="fas fa-edit me-2"></i>Isi Form (HTML)</div>

                        <textarea class="form-control" id="formContent-${index}" rows="4" placeholder="HTML untuk form...">${currentContent}</textarea>

                    </div>

                `;

            } else if (type === 'faq') {

                const currentJson = element.find('.wysiwyg-faq-content').val() || '[]';

                let faqs = [];

                try { faqs = JSON.parse(currentJson); } catch(e) { faqs = []; }



                let faqHtml = '<div class="setting-group">';

                faqHtml += '<div class="setting-label"><i class="fas fa-question-circle me-2"></i>Daftar FAQ</div>';



                faqs.forEach((faq, i) => {

                    faqHtml += `

                        <div class="mb-3 p-2 border rounded bg-light">

                            <input type="text" class="form-control mb-1 faq-question" data-index="${i}" 

                                placeholder="Pertanyaan" value="${faq.question || ''}">

							<textarea class="form-control mb-2 faq-answer" data-index="${i}" 

                                rows="2" placeholder="Jawaban">${faq.answer || ''}</textarea>

                            <button type="button" class="btn btn-sm btn-danger remove-faq" data-index="${i}">

                                <i class="fas fa-trash"></i> Hapus

                            </button>

                        </div>

                    `;

                });



                faqHtml += `

                    <button type="button" class="btn btn-outline-primary w-100 mb-3 add-faq-btn">

                        <i class="fas fa-plus me-1"></i> Tambah FAQ

                    </button>

                    <button type="button" class="btn btn-success w-100 save-faq-btn">

                        <i class="fas fa-save me-1"></i> Simpan FAQ

                    </button>

                </div>

                `;



                html += faqHtml;



            } else if (type === 'testimonial') {

                const currentJson = element.find('.wysiwyg-testimonial-content').val() || '[]';

                let testis = [];

                try { testis = JSON.parse(currentJson); } catch(e) { testis = []; }



                let testiHtml = '<div class="setting-group">';

                testiHtml += '<div class="setting-label"><i class="fas fa-quote-right me-2"></i>Daftar Testimoni</div>';



                testis.forEach((t, i) => {

                    testiHtml += `

                        <div class="mb-3 p-2 border rounded bg-light">

                            <input type="text" class="form-control mb-1 testi-name" data-index="${i}" 

                                placeholder="Nama" value="${t.name || ''}">

                            <input type="text" class="form-control mb-1 testi-role" data-index="${i}" 

                                placeholder="Jabatan/Pekerjaan" value="${t.role || ''}">

                            <textarea class="form-control mb-2 testi-text" data-index="${i}" 

                                rows="2" placeholder="Ulasan">${t.text || ''}</textarea>

                            <button type="button" class="btn btn-sm btn-danger remove-testi" data-index="${i}">

                                <i class="fas fa-trash"></i> Hapus

                            </button>

                        </div>

                    `;

                });



                testiHtml += `

                    <button type="button" class="btn btn-outline-primary w-100 mb-3 add-testi-btn">

                        <i class="fas fa-plus me-1"></i> Tambah Testimoni

                    </button>

                    <button type="button" class="btn btn-success w-100 save-testi-btn">

                        <i class="fas fa-save me-1"></i> Simpan Testimoni

                    </button>

                </div>

                `;



                html += testiHtml;

            } else if (type === 'html') {

                const currentContent = element.find('.wysiwyg-html-content-input').val() || '';

                html += `

                    <div class="setting-group">

                        <div class="setting-label"><i class="fas fa-code me-2"></i>Kode HTML</div>

                        <textarea class="form-control" id="htmlContent-${index}" rows="6" placeholder="Kode HTML kustom...">${currentContent}</textarea>

                    </div>

                `;

            }



            $('#settingsContent').html(html);



            // Event listener untuk pengaturan spesifik

            if (type === 'header' || type === 'paragraph') {

                $(`#textColorPicker-${index}`).on('input', function() {

                    const color = $(this).val();

                    element.find('.style-input-text').val(color);

                    element.css('color', color);

                });

                $(`#bgColorPicker-${index}`).on('input', function() {

                    const color = $(this).val();

                    element.find('.style-input-bg').val(color);

                    element.css('background-color', color);

                });



                $(`#applyTextColorBtn-${index}`).click(function() {

                    const color = $(`#textColorPickerSel-${index}`).val();

                    if (quillEditors[index]) {

                        const range = quillEditors[index].getSelection();

                        if (range && range.length > 0) {

                            quillEditors[index].formatText(range.index, range.length, 'color', color);

                        } else {

                            alert('Pilih teks terlebih dahulu di editor.');

                        }

                    }

                });



                $(`#applyBgTextColorBtn-${index}`).click(function() {

                    const color = $(`#bgTextColorPickerSel-${index}`).val();

                    if (quillEditors[index]) {

                        const range = quillEditors[index].getSelection();

                        if (range && range.length > 0) {

                            quillEditors[index].formatText(range.index, range.length, 'background', color);

                        } else {

                            alert('Pilih teks terlebih dahulu di editor.');

                        }

                    }

                });

            } else if (type === 'divider') {

                $(`#dividerStyle-${index}`).on('change', function() {

                    const style = $(this).val();

                    element.find('.style-input-divider-style').val(style);

                    element.find('hr').css('border-top-style', style);

                });

                $(`#dividerColor-${index}`).on('input', function() {

                    const color = $(this).val();

                    element.find('.style-input-divider-color').val(color);

                    element.find('hr').css('border-top-color', color);

                });

            } else if (type === 'image') {

                $(`#imageUrl-${index}`).on('input', function() {

                    const url = $(this).val();

                    element.find('.wysiwyg-image-url').val(url);

                    element.find('.wysiwyg-image').attr('src', url);

                });

            } else if (type === 'youtube') {

                $(`#applyYoutubeBtn-${index}`).click(function() {

                    let input = $(`#youtubeUrl-${index}`).val().trim();

                    let videoId = '';



                    const patterns = [

                        /youtube\.com\/watch\?v=([a-zA-Z0-9_-]+)/,

                        /youtu\.be\/([a-zA-Z0-9_-]+)/,

                        /youtube\.com\/embed\/([a-zA-Z0-9_-]+)/,

                        /^([a-zA-Z0-9_-]+)$/

                    ];



                    for (let pattern of patterns) {

                        const match = input.match(pattern);

                        if (match) {

                            videoId = match[1];

                            break;

                        }

                    }



                    if (videoId) {

                        element.find('.wysiwyg-youtube-id').val(videoId);

                        const embedUrl = `https://www.youtube.com/embed/${videoId}`;

                        element.find('iframe').attr('src', embedUrl);

                    } else {

                        alert('ID video YouTube tidak valid. Contoh: dQw4w9WgXcQ');

                    }

                });

            } else if (type === 'button') {

                $(`#buttonText-${index}`).on('input', function() {

                    const text = $(this).val();

                    element.find('.wysiwyg-button-text').val(text);

                    element.find('.wysiwyg-button').text(text);

                });

                $(`#buttonLink-${index}`).on('input', function() {

                    const link = $(this).val();

                    element.find('.wysiwyg-button-link').val(link);

                    element.find('.wysiwyg-button').attr('href', link);

                });

                $(`#buttonTextColor-${index}`).on('input', function() {

                    const color = $(this).val();

                    element.find('.wysiwyg-button-text-color').val(color);

                    element.find('.wysiwyg-button').css('color', color);

                });

                $(`#buttonBgColor-${index}`).on('input', function() {

                    const color = $(this).val();

                    element.find('.wysiwyg-button-bg').val(color);

                    element.find('.wysiwyg-button').css('background-color', color);

                });

            } else if (type === 'form') {

                $(`#formContent-${index}`).on('input', function() {

                    const content = $(this).val();

                    element.find('.wysiwyg-form-content-input').val(content);

                    element.find('.wysiwyg-form-content').html(content);

                });

            } else if (type === 'html') {

                $(`#htmlContent-${index}`).on('input', function() {

                    const content = $(this).val();

                    element.find('.wysiwyg-html-content-input').val(content);

                    element.find('.wysiwyg-html-content').html(content);

                });

            }



            // Event handlers for FAQ

            $('#settingsContent').off('click', '.add-faq-btn').on('click', '.add-faq-btn', function() {

                const currentJson = element.find('.wysiwyg-faq-content').val() || '[]';

                let faqs = [];

                try { faqs = JSON.parse(currentJson); } catch(e) { faqs = []; }

                faqs.push({question: '', answer: ''});

                element.find('.wysiwyg-faq-content').val(JSON.stringify(faqs));

                showElementSettings(element); // Re-render

            });



            $('#settingsContent').off('click', '.remove-faq').on('click', '.remove-faq', function() {

                const idx = $(this).data('index');

                const currentJson = element.find('.wysiwyg-faq-content').val() || '[]';

                let faqs = [];

                try { faqs = JSON.parse(currentJson); } catch(e) { faqs = []; }

                faqs.splice(idx, 1);

                element.find('.wysiwyg-faq-content').val(JSON.stringify(faqs));

                showElementSettings(element);

            });



            $('#settingsContent').off('click', '.save-faq-btn').on('click', '.save-faq-btn', function() {

                const faqs = [];

                $('.faq-question').each(function() {

                    const i = $(this).data('index');

                    faqs[i] = faqs[i] || {};

                    faqs[i].question = $(this).val().trim();

                });

                $('.faq-answer').each(function() {

                    const i = $(this).data('index');

                    faqs[i] = faqs[i] || {};

                    faqs[i].answer = $(this).val().trim();

                });

                element.find('.wysiwyg-faq-content').val(JSON.stringify(faqs));

                let preview = '<div class="faq-preview">';

                faqs.forEach(f => {

                    if(f.question && f.answer) {

                        preview += `<div class='mb-2 p-2 border rounded bg-light'><strong>Q:</strong> ${f.question}<br><strong>A:</strong> ${f.answer}</div>`;

                    }

                });

                if (faqs.length === 0 || !faqs.some(f => f.question && f.answer)) {

                    preview += '<em class="text-muted">Belum ada FAQ.</em>';

                }

                preview += '</div>';

                element.find('.faq-preview').html(preview);

                showNotification('FAQ berhasil disimpan!', 'success');

            });



            // Event handlers for Testimonial

            $('#settingsContent').off('click', '.add-testi-btn').on('click', '.add-testi-btn', function() {

                const currentJson = element.find('.wysiwyg-testimonial-content').val() || '[]';

                let testis = [];

                try { testis = JSON.parse(currentJson); } catch(e) { testis = []; }

                testis.push({name: '', role: '', text: ''});

                element.find('.wysiwyg-testimonial-content').val(JSON.stringify(testis));

                showElementSettings(element);

            });



            $('#settingsContent').off('click', '.remove-testi').on('click', '.remove-testi', function() {

                const idx = $(this).data('index');

                const currentJson = element.find('.wysiwyg-testimonial-content').val() || '[]';

                let testis = [];

                try { testis = JSON.parse(currentJson); } catch(e) { testis = []; }

                testis.splice(idx, 1);

                element.find('.wysiwyg-testimonial-content').val(JSON.stringify(testis));

                showElementSettings(element);

            });



            $('#settingsContent').off('click', '.save-testi-btn').on('click', '.save-testi-btn', function() {

                const testis = [];

                $('.testi-name').each(function() {

                    const i = $(this).data('index');

                    testis[i] = testis[i] || {};

                    testis[i].name = $(this).val().trim();

                });

                $('.testi-role').each(function() {

                    const i = $(this).data('index');

                    testis[i] = testis[i] || {};

                    testis[i].role = $(this).val().trim();

                });

                $('.testi-text').each(function() {

                    const i = $(this).data('index');

                    testis[i] = testis[i] || {};

                    testis[i].text = $(this).val().trim();

                });

                element.find('.wysiwyg-testimonial-content').val(JSON.stringify(testis));

                let preview = '<div class="testimonial-preview">';

                testis.forEach(t => {

                    if(t.name && t.text) {

                        preview += `<div class='mb-2 p-3 border rounded bg-light'>

                            <p class='mb-2 fst-italic'>\"${t.text}\"</p>

                            <h6 class='mb-0'>— ${t.name}</h6>

                            <small>${t.role}</small>

                        </div>`;

                    }

                });

                if (testis.length === 0 || !testis.some(t => t.name && t.text)) {

                    preview += '<em class="text-muted">Belum ada testimoni.</em>';

                }

                preview += '</div>';

                element.find('.testimonial-preview').html(preview);

                showNotification('Testimoni berhasil disimpan!', 'success');

            });





            $('#moveBtn').click(function() {

                let newPos = parseInt($('#newPos').val()) - 1;

                if (isNaN(newPos) || newPos < 0 || newPos >= total || newPos === index) {

                    alert('Posisi tidak valid.');

                    return;

                }

                const $target = $('#canvasElements > .wysiwyg-element').eq(newPos);

                if (newPos < index) {

                    element.insertBefore($target);

                } else {

                    element.insertAfter($target);

                }

                updateElementIndices();

                saveState();

                showElementSettings(element); // Refresh settings

            });



            $('#deleteBtn').click(function() {

                $('#deleteConfirmModal').modal('show');

            });

        }



        // Fungsi untuk memperbarui indeks

        function updateElementIndices() {

            $('#canvasElements > .wysiwyg-element').each(function(i) {

                const $el = $(this);

                const oldIndex = $el.data('element-index');

                $el.attr('data-element-index', i);



                // Update Quill editors key

                if (quillEditors[oldIndex]) {

                    quillEditors[i] = quillEditors[oldIndex];

                    delete quillEditors[oldIndex];

                    // Update editor container ID if it exists

                    const oldEditorContainer = $(`#wysiwyg-quill-editor-${oldIndex}`);

                    if (oldEditorContainer.length) {

                        oldEditorContainer.attr('id', `wysiwyg-quill-editor-${i}`);

                        // Update editable-content data-editor-index

                        $el.find('.editable-content').attr('data-editor-index', i);

                    }

                }



                // Simpan nilai input sebelum mengganti name

                const preservedValues = {};

                $el.find('[name]').each(function() {

                    const $input = $(this);

                    const oldName = $input.attr('name');

                    preservedValues[oldName] = $input.val();

                });



                // Ganti name sesuai indeks baru

                $el.find('[name]').each(function() {

                    const $input = $(this);

                    const oldName = $input.attr('name');

                    if (oldName) {

                        const newName = oldName.replace(/\[(\d+)(?:_[^]]+)?\]/g, `[${i}]`);

                        $input.attr('name', newName);

                        // Pulihkan nilai asli

                        if (preservedValues.hasOwnProperty(oldName)) {

                            $input.val(preservedValues[oldName]);

                        }

                    }

                });

            });

        }



        // Submit form

        $('#builderForm').submit(function() {

            Object.keys(quillEditors).forEach(i => {

                if (quillEditors[i]) {

                    $(`.wysiwyg-content[name="elements[${i}][content]"]`).val(quillEditors[i].root.innerHTML);

                }

            });

        });



        // Preview

        $('#previewBtn').click(function() {

            Object.keys(quillEditors).forEach(i => {

                if (quillEditors[i]) {

                    $(`.wysiwyg-content[name="elements[${i}][content]"]`).val(quillEditors[i].root.innerHTML);

                }

            });

            <?php if ($page_id > 0): ?>

            window.open('preview.php?id=<?= $page_id ?>', '_blank');

            <?php else: ?>

            // For new pages, we need to save first to get an ID

            if(confirm('Anda harus menyimpan halaman terlebih dahulu untuk melihat preview. Simpan sekarang?')) {

                $('#builderForm').submit();

            }

            <?php endif; ?>

        });



        // Copy HTML

        $('#copyHtmlBtn').click(function() {

            <?php if ($page_id > 0): ?>

            $('#htmlOutput').val('Loading...');

            var modal = new bootstrap.Modal(document.getElementById('copyHtmlModal'));

            modal.show();

            $.get('export_html.php?id=<?= $page_id ?>', function(data) {

                $('#htmlOutput').val(data);

            });

            <?php endif; ?>

        });



        $('#copyToClipboardBtn').click(function() {

            const ta = document.getElementById('htmlOutput');

            ta.select();

            navigator.clipboard.writeText(ta.value).then(() => {

                const orig = $(this).html();

                $(this).html('<i class="fas fa-check me-1"></i>Copied!');

                setTimeout(() => $(this).html(orig), 2000);

            });

        });



        // Tour Event Handlers

        $('#tourNext').click(nextTourStep);

        $('#tourSkip').click(endTour);



        // Undo/Redo Event Handlers

        $(document).on('click', '#undoBtnHeader', undo);

        $(document).on('click', '#redoBtnHeader', redo);



        // Delete Confirmation Event Handlers

        $('#confirmDeleteBtn').click(function() {

            const showUndo = $('#undoOption').is(':checked');

            

            if (selectedElement) {

                if (showUndo) {

                    deleteElementWithUndo(selectedElement);

                } else {

                    const quillIndex = selectedElement.data('element-index');

                    if (quillEditors[quillIndex]) {

                        delete quillEditors[quillIndex];

                    }

                    selectedElement.remove();

                    if ($('#canvasElements > .wysiwyg-element').length === 0) {

                        $('#emptyCanvasMessage').show();

                    }

                    updateElementIndices();

                    saveState();

                }

            }

            

            $('#deleteConfirmModal').modal('hide');

            $('#settingsContent').html(`

                <div class="text-center text-muted py-5">

                    <i class="fas fa-info-circle fa-lg mb-3"></i>

                    <p class="mb-0">Pilih elemen di canvas untuk mengatur propertinya</p>

                </div>

            `);

        });



        // Undo button in notification

        $(document).on('click', '#undoNotification #undoBtn', undoDelete);



        // Template Modal Event Handlers

        $('#templateBtn').click(function() {

            $('#templateModal').modal('show');

        });



        $(document).on('click', '.template-card', function() {

            const template = $(this).data('template');

            loadTemplate(template);

        });



        // Preview Device Selector Event Handlers

        $('#previewBtn').click(function(e) {

            e.stopPropagation(); // Prevent triggering document click

            $('#previewDeviceSelector').toggle();

        });



        $(document).on('click', '.device-btn', function() {

            const device = $(this).data('device');

            setPreviewDevice(device);

        });



        // Hide device selector when clicking outside

        $(document).on('click', function(e) {

            if (!$(e.target).closest('#previewBtn, #previewDeviceSelector').length) {

                $('#previewDeviceSelector').hide();

            }

        });



        // Keyboard navigation for element items

        $('.element-item').on('keypress', function(e) {

            if (e.which === 13 || e.which === 32) { // Enter or Space

                e.preventDefault();

                $(this).click();

            }

        });



        // Accessibility announcements

        function announceToScreenReader(message) {

            const $announcement = $('<div class="sr-only" role="status" aria-live="polite"></div>');

            $announcement.text(message);

            $('body').append($announcement);

            setTimeout(() => $announcement.remove(), 1000);

        }

        

        // Announce element additions

        $(document).on('click', '.element-item', function() {

            const elementType = $(this).find('strong').text();

            announceToScreenReader(`${elementType} ditambahkan ke halaman`);

        });



        // Initialize Everything

        $(document).ready(function() {

            initializeOnboarding();

            initializeKeyboardNavigation();

            saveState(); // Save initial state

            

            // Autosave every 30 seconds

            setInterval(autosave, 30000);

            

            // Save state on element change

            $(document).on('input change', '.wysiwyg-element input, .wysiwyg-element textarea, .wysiwyg-element select', function() {

                clearTimeout(autosaveTimer);

                autosaveTimer = setTimeout(() => {

                    saveState();

                    autosave(); // Also trigger an autosave

                }, 1000);

            });



            // Quill editor change

            $(document).on('text-change', function() {

                clearTimeout(autosaveTimer);

                autosaveTimer = setTimeout(() => {

                    saveState();

                    autosave();

                }, 1000);

            });



            // Load saved preview device preference

            const savedDevice = localStorage.getItem('previewDevice') || 'desktop';

            setPreviewDevice(savedDevice);



            // Initial setup for existing elements

            updateElementIndices();

            initializeQuillEditors();

        });

    </script>

</body>

</html>