<?php
require_once '../includes/config.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';

requireLogin();
$user = getCurrentUser($pdo);

$page_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if (!$page_id) {
    header("Location: builder.php");
    exit;
}

$page = getUserLandingPage($pdo, $page_id, $user['id']);
if (!$page) {
    die("Page not found or access denied.");
}

// Ambil elemen-elemen halaman
$stmt = $pdo->prepare("SELECT * FROM page_elements WHERE page_id=? ORDER BY order_position ASC");
$stmt->execute([$page_id]);
$elements = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Fungsi untuk generate HTML lengkap seperti preview.php
function generateFullHTML($page, $elements) {
    $is_published = ($page['status'] ?? '') === 'published';
    $meta_event_name = trim($page['meta_event_name'] ?? 'ViewContent');
    
    // Parsing pixel & CAPI
    $pix = [
        'pixel_id' => trim($page['meta_pixel_id'] ?? ''),
        'capi_endpoint' => $page['capi_endpoint'] ?? '',
        'capi_access_token' => $page['capi_access_token'] ?? ''
    ];
    
    $t = json_decode($page['meta_pixel_id'] ?? '', true);
    if (is_array($t) && !empty($t['pixel_id'])) {
        $pix = array_merge($pix, $t);
    }
    
    if (empty($pix['capi_endpoint']) && !empty($pix['pixel_id'])) {
        $pix['capi_endpoint'] = "https://graph.facebook.com/v23.0/{$pix['pixel_id']}/events";
    }
    
    ob_start();
    foreach ($elements as $element) {
        $type = $element['type'];
        $content = $element['content'];
        $styles = json_decode($element['styles'] ?? '', true) ?: [];
        
        // Ambil style properties yang konsisten
        $bg_color = $styles['bg_color'] ?? '#ffffff';
        $text_color = $styles['text_color'] ?? '#000000';
        $link = $styles['link'] ?? '#';
        
        switch ($type) {
            case 'header':
                echo "<div class='header-element' style='background-color:" . htmlspecialchars($bg_color) . "; color:" . htmlspecialchars($text_color) . "; padding: 12px; border-radius: 6px;'>{$content}</div>";
                break;
                
            case 'paragraph':
                echo "<div class='paragraph-element' style='background-color:" . htmlspecialchars($bg_color) . "; color:" . htmlspecialchars($text_color) . "; padding: 10px; border-radius: 6px;'>{$content}</div>";
                break;
                
            case 'divider':
                $thickness = $styles['thickness'] ?? '2px';
                $divider_style = $styles['divider_style'] ?? 'solid';
                echo "<div class='divider-element'><hr style='border-top:" . htmlspecialchars($thickness) . " " . htmlspecialchars($divider_style) . " " . htmlspecialchars($text_color) . "; margin: 6px auto;'></div>";
                break;
                
            case 'image':
                if (!empty($content)) {
                    $img_class = $styles['img_class'] ?? 'img-fluid rounded';
                    $alt_text = $styles['alt_text'] ?? 'Gambar';
                    echo "<div class='image-element' style='text-align:center; margin:0 auto;'>
                        <img src='" . htmlspecialchars(trim($content)) . "'
                             class='" . htmlspecialchars($img_class) . "'
                             alt='" . htmlspecialchars($alt_text) . "'
                             loading='lazy'
                             style='max-width:100%; height:auto; max-height:400px; border-radius:8px;'
                             decoding='async'>
                    </div>";
                }
                break;
                
            case 'button':
                $btn_size = $styles['btn_size'] ?? '';
                $btn_text = $content ?: 'Klik Disini';
                if ($is_published) {
                    // Untuk HTML export, kita buat link asli tanpa fbclid
                    // fbclid akan ditambahkan via JavaScript nanti
                    $target = strpos($link, 'http') === 0 ? 'rel="noopener"' : '';
                    echo "<div class='button-element'>
                        <a href='" . htmlspecialchars($link) . "'
                           class='custom-button " . htmlspecialchars($btn_size) . "'
                           style='background:" . htmlspecialchars($bg_color) . ";color:" . htmlspecialchars($text_color) . "'
                           data-original-href='" . htmlspecialchars($link) . "'
                           {$target}>
                           " . htmlspecialchars($btn_text) . "
                        </a>
                    </div>";
                } else {
                    echo "<div class='coming-soon-notice'>
                        <p style='color:#e74c3c; font-size:1.5rem; font-weight:700; text-align:center; margin:20px 0;'>
                            ⛔ Maaf, penjualan belum dibuka.
                        </p>
                    </div>";
                }
                break;
                
            case 'youtube':
                if (!empty($content)) {
                    $videoId = preg_replace('/[^a-zA-Z0-9_-]/', '', $content);
                    if (!empty($videoId)) {
                        $video_size = $styles['video_size'] ?? 'ratio-16x9';
                        echo "<div class='youtube-element' style='margin:0 auto;max-width:100%;'>
                            <div class='" . htmlspecialchars($video_size) . "' style='position:relative;padding-bottom:56.25%;height:0;overflow:hidden;border-radius:8px;'>
                                <iframe 
                                    src='https://www.youtube.com/embed/" . htmlspecialchars($videoId) . "' 
                                    style='position:absolute;top:0;left:0;width:100%;height:100%;border:0;' 
                                    frameborder='0' 
                                    allow='accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share' 
                                    allowfullscreen 
                                    loading='lazy'>
                                </iframe>
                            </div>
                        </div>";
                    }
                }
                break;
                
            case 'html':
                echo "<div class='custom-html-element'>{$content}</div>";
                break;
                
            case 'faq':
                try {
                    $faqs = json_decode($content, true) ?: [];
                    if (is_array($faqs) && !empty($faqs)) {
                        echo "<div class='faq-element' style='background:" . htmlspecialchars($bg_color) . "; color:" . htmlspecialchars($text_color) . "; border-radius:8px; overflow:hidden; margin-top:20px;'>";
                        foreach ($faqs as $i => $item) {
                            $q = htmlspecialchars($item['q'] ?? $item['question'] ?? '');
                            $a = htmlspecialchars($item['a'] ?? $item['answer'] ?? '');
                            $border = $styles['border_color'] ?? '#e0e0e0';
                            $is_first = ($i === 0);
                            
                            echo "<div class='faq-item' style='border-bottom:1px solid {$border};'>";
                            echo "<div class='faq-question' style='padding:16px; cursor:pointer; font-weight:600; display:flex; justify-content:space-between; align-items:center;' data-index='{$i}'>";
                            echo $q;
                            echo "<span class='faq-toggle' data-open='−' data-close='+'>+</span>";
                            echo "</div>";
                            echo "<div class='faq-answer' style='padding:0 16px; max-height:" . ($is_first ? '500px' : '0') . "; overflow:hidden; transition:max-height 0.3s ease, padding 0.3s ease;'>";
                            if ($is_first) echo "<p style='margin:16px 0 16px; line-height:1.6;'>{$a}</p>";
                            else echo "<p style='margin:0 0 16px; line-height:1.6;'>{$a}</p>";
                            echo "</div>";
                            echo "</div>";
                        }
                        echo "</div>";
                        
                        // JS toggle untuk FAQ
                        echo "<script>
                        (function() {
                            document.querySelectorAll('.faq-question').forEach(btn => {
                                btn.addEventListener('click', function() {
                                    const idx = this.dataset.index;
                                    const answer = this.nextElementSibling;
                                    const toggle = this.querySelector('.faq-toggle');
                                    const isOpen = answer.style.maxHeight && answer.style.maxHeight !== '0px';
                    
                                    document.querySelectorAll('.faq-answer').forEach(el => {
                                        el.style.maxHeight = '0';
                                        el.style.padding = '0 16px';
                                    });
                                    document.querySelectorAll('.faq-toggle').forEach(t => t.textContent = t.dataset.close);
                    
                                    if (!isOpen) {
                                        answer.style.maxHeight = answer.scrollHeight + 32 + 'px';
                                        answer.style.padding = '0 16px 16px';
                                        toggle.textContent = toggle.dataset.open;
                                    }
                                });
                            });
                        })();
                        </script>";
                    }
                } catch (Exception $e) {
                    // Skip jika JSON invalid
                }
                break;
        }
        echo "<div class='element-spacing'></div>";
    }
    $content_output = ob_get_clean();
    
    // Generate full HTML document
    $html = '<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>' . htmlspecialchars($page['title']) . '</title>
<link rel="icon" href="/favicon.ico">';

    if (!$is_published) {
        $html .= '
<meta name="robots" content="noindex, nofollow">';
    }

    $html .= '
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="preload" as="style" href="https://fonts.googleapis.com/css2?family=Nunito:wght@300;400;600;700;800&display=swap">
<link href="https://fonts.googleapis.com/css2?family=Nunito:wght@300;400;600;700;800&display=swap" rel="stylesheet">';

    // Meta Pixel
    if (!empty($pix['pixel_id']) && $is_published) {
        $html .= '
<!-- Meta Pixel -->
<script>
setTimeout(function() {
    !function(f,b,e,v,n,t,s)
    {if(f.fbq)return;n=f.fbq=function(){n.callMethod?
    n.callMethod.apply(n,arguments):n.queue.push(arguments)};
    if(!f._fbq)f._fbq=n;n.push=n;n.loaded=!0;n.version=\'2.0\';
    n.queue=[];t=b.createElement(e);t.async=!0;
    t.src=v;s=b.getElementsByTagName(e)[0];
    s.parentNode.insertBefore(t,s)}(window, document,\'script\',
    \'https://connect.facebook.net/en_US/fbevents.js\');

    fbq(\'init\', \'' . htmlspecialchars($pix['pixel_id']) . '\');
    fbq(\'track\', \'' . addslashes($meta_event_name) . '\');
}, 1500);
</script>
<noscript><img height="1" width="1" style="display:none" src="https://www.facebook.com/tr?id=' . htmlspecialchars($pix['pixel_id']) . '&ev=' . urlencode($meta_event_name) . '&noscript=1"/></noscript>';
    }

    // Microsoft Clarity
    $html .= '
<script type="text/javascript">
    (function(c,l,a,r,i,t,y){
        c[a]=c[a]||function(){(c[a].q=c[a].q||[]).push(arguments)};
        t=l.createElement(r);t.async=1;t.src="https://www.clarity.ms/tag/"+i;
        y=l.getElementsByTagName(r)[0];y.parentNode.insertBefore(t,y);
    })(window, document, "clarity", "script", "u9ebbwavns");
</script>';

    // CSS Styles
    $html .= '
<style>
body{font-family:\'Nunito\',sans-serif;background:#F2F5FA;margin:0;padding:0;line-height:1.5}
h1,h2,h3{line-height:1.3;margin:0 0 0.25em}
p{margin:0 0 0.25em}
.page-container{max-width:700px;margin:15px auto;padding:20px 40px 40px 40px;background:#fff;border-radius:12px;box-shadow:0 4px 20px rgba(0,0,0,0.08)}
.content-wrapper{padding:30px 50px}
.element-spacing{margin-bottom:40px}
@media(max-width:768px){
    .page-container{margin:10px;padding:0px 10px 0px 10px;border-radius:8px}
    .content-wrapper{padding:20px}
    .element-spacing{margin-bottom:40px}
}
.header-element{margin-bottom:12px;line-height:1.3}
.paragraph-element{margin-bottom:10px}
.image-element{margin-bottom:12px;text-align:center}
.image-element img{max-width:100%;height:auto;border-radius:8px}
.button-element{margin-bottom:12px;text-align:center}
.custom-button{display:inline-block;padding:12px 24px;text-decoration:none;border-radius:8px;font-weight:700;font-size:24px;transition:all .3s ease;border:none;line-height:1.2; max-width:300px}
.custom-button:hover{transform:translateY(-2px);box-shadow:0 6px 16px rgba(0,0,0,0.15)}
.custom-button.btn-sm{padding:10px 20px;font-size:20px}
.custom-button.btn-lg{padding:14px 28px;font-size:28px}
.custom-html-element{margin-bottom:8px}
.youtube-element {
    margin-bottom: 20px;
}
.divider-line{border:0;height:20px;border-top:1px solid #ccc;margin:6px auto}
.divider-dashed{border-top:2px dashed #ccc}
.divider-dotted{border-top:2px dotted #ccc}
.ql-align-center{text-align:center}
.ql-align-right{text-align:right}
.ql-align-justify{text-align:justify}
.w-100{width:100%}
.mb-2{margin-bottom:15px}
.mb-3{margin-bottom:20px}
.coming-soon-notice {
    text-align: center;
    padding: 30px 20px;
    background: #fef5f5;
    border-radius: 10px;
    border: 2px dashed #e74c3c;
    margin: 20px 0;
}
.coming-soon-notice p {
    margin: 0;
}
.faq-element {
    margin-top: 20px;
}
.faq-question:hover {
    background-color: #f5f7fa;
}
.faq-toggle {
    font-size: 1.2em;
    font-weight: bold;
    width: 24px;
    height: 24px;
    display: flex;
    align-items: center;
    justify-content: center;
}

/* Ratio classes for YouTube/video */
.ratio-16x9 {
    position: relative;
    padding-bottom: 56.25%;
    height: 0;
    overflow: hidden;
}
.ratio-4x3 {
    position: relative;
    padding-bottom: 75%;
    height: 0;
    overflow: hidden;
}
.ratio-1x1 {
    position: relative;
    padding-bottom: 100%;
    height: 0;
    overflow: hidden;
}
.ratio-16x9 iframe,
.ratio-4x3 iframe,
.ratio-1x1 iframe {
    position: absolute;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    border: 0;
}

/* Image classes */
.img-fluid {
    max-width: 100%;
    height: auto;
}
.img-thumbnail {
    padding: 4px;
    background-color: #fff;
    border: 1px solid #dee2e6;
    border-radius: 4px;
    max-width: 100%;
    height: auto;
}
.rounded {
    border-radius: 8px;
}
.rounded-circle {
    border-radius: 50%;
}

/* Quill editor specific styles */
.ql-size-small { font-size: 0.75em; }
.ql-size-large { font-size: 1.5em; }
.ql-size-huge { font-size: 2.5em; }
blockquote {
    border-left: 4px solid #4361ee;
    padding-left: 16px;
    margin: 10px 0;
    font-style: italic;
    color: #555;
    background: #f8f9fa;
    padding: 15px;
    border-radius: 0 4px 4px 0;
}
</style>
</head>
<body>
<div class="page-container">
    <div class="content-wrapper">
        ' . $content_output . '
    </div>
</div>';

    // JavaScript untuk menangani fbclid dan CAPI
    $html .= '
<script>
// Function untuk menambahkan fbclid ke semua link
function addFbclidToLinks() {
    const urlParams = new URLSearchParams(window.location.search);
    const fbclid = urlParams.get(\'fbclid\');
    
    if (fbclid) {
        // Tambahkan fbclid ke semua custom button
        document.querySelectorAll(\'.custom-button\').forEach(button => {
            const originalHref = button.getAttribute(\'data-original-href\') || button.getAttribute(\'href\');
            if (originalHref && !originalHref.includes(\'fbclid=\')) {
                const separator = originalHref.includes(\'?\') ? \'&\' : \'?\';
                const newHref = originalHref + separator + \'fbclid=\' + encodeURIComponent(fbclid);
                button.setAttribute(\'href\', newHref);
            }
        });
        
        // Simpan fbclid di cookie untuk CAPI
        const expiryDate = new Date();
        expiryDate.setFullYear(expiryDate.getFullYear() + 1);
        document.cookie = `_fbc=${fbclid}; expires=${expiryDate.toUTCString()}; path=/; SameSite=Lax`;
    }
}

// Function untuk mendapatkan cookie
function getCookie(name) {
    const value = `; ${document.cookie}`;
    const parts = value.split(`; ${name}=`);
    if (parts.length === 2) return parts.pop().split(\';\').shift();
    return \'\';
}

// Event Handler & CAPI
' . (!empty($pix['pixel_id']) && $is_published ? '
(function() {
    // Panggil fungsi untuk menambahkan fbclid
    addFbclidToLinks();
    
    // Ambil fbp dari cookie
    const urlParams = new URLSearchParams(window.location.search);
    let fbc = urlParams.get(\'fbclid\') || getCookie(\'_fbc\') || \'\';
    const fbp = getCookie(\'_fbp\') || \'\';
    const userAgent = navigator.userAgent;

    // Kirim ke CAPI via Beacon (non-blocking)
    const payload = {
        page_slug: \'' . addslashes($page['slug']) . '\',
        event: \'' . addslashes($meta_event_name) . '\',
        fbp: fbp,
        fbc: fbc,
        user_agent: userAgent
        // IP diisi di sisi server
    };

    const blob = new Blob([JSON.stringify(payload)], { type: \'application/json\' });
    if (navigator.sendBeacon) {
        navigator.sendBeacon(\'actions/pageview.php\', blob);
    } else {
        fetch(\'actions/pageview.php\', {
            method: \'POST\',
            body: blob,
            keepalive: true
        }).catch(() => {});
    }
})();' : '
// Jika tidak ada pixel, tetap tambahkan fbclid untuk tracking
addFbclidToLinks();') . '

// Track button clicks untuk CAPI
document.querySelectorAll(\'.custom-button\').forEach(button => {
    button.addEventListener(\'click\', function(e) {
        ' . (!empty($pix['pixel_id']) && $is_published ? '
        if (typeof fbq !== \'undefined\') {
            fbq(\'track\', \'Lead\');
        }
        
        // Track ke CAPI
        const urlParams = new URLSearchParams(window.location.search);
        const fbclid = urlParams.get(\'fbclid\');
        const fbc = fbclid || getCookie(\'_fbc\') || \'\';
        const fbp = getCookie(\'_fbp\') || \'\';
        
        const capiData = {
            event: \'Lead\',
            page_slug: \'' . addslashes($page['slug']) . '\',
            button_text: this.textContent.trim(),
            button_url: this.getAttribute(\'href\'),
            fbp: fbp,
            fbc: fbc,
            user_agent: navigator.userAgent
        };
        
        // Kirim ke CAPI endpoint jika ada
        ' . (!empty($pix['capi_endpoint']) && !empty($pix['capi_access_token']) ? '
        if (\'' . addslashes($pix['capi_endpoint']) . '\' && \'' . addslashes($pix['capi_access_token']) . '\') {
            const capiPayload = {
                data: [{
                    event_name: \'Lead\',
                    event_time: Math.floor(Date.now() / 1000),
                    action_source: \'website\',
                    event_source_url: window.location.href,
                    user_data: {
                        client_ip_address: \'{{client_ip}}\',
                        client_user_agent: navigator.userAgent,
                        fbc: fbc,
                        fbp: fbp
                    },
                    custom_data: {
                        button_text: this.textContent.trim(),
                        page_slug: \'' . addslashes($page['slug']) . '\'
                    }
                }],
                access_token: \'' . addslashes($pix['capi_access_token']) . '\'
            };
            
            fetch(\'' . addslashes($pix['capi_endpoint']) . '\', {
                method: \'POST\',
                headers: {\'Content-Type\': \'application/json\'},
                body: JSON.stringify(capiPayload),
                keepalive: true
            }).catch(() => {});
        }' : '') . '
        ' : '') . '
        
        // Biarkan link berjalan normal setelah tracking
        // Tidak perlu mencegah default behavior
    });
});
</script>
</body>
</html>';
    
    return $html;
}

// Proses export
$full_html = generateFullHTML($page, $elements);

// Jika ada parameter download
if (isset($_GET['download'])) {
    header('Content-Type: text/html');
    header('Content-Disposition: attachment; filename="landing-page-' . $page['slug'] . '.html"');
    echo $full_html;
    exit;
}

// Jika hanya ingin lihat
if (isset($_GET['preview'])) {
    echo $full_html;
    exit;
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Export HTML - <?= htmlspecialchars($page['title']) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css" rel="stylesheet">
    <style>
        body {
            background: linear-gradient(135deg, #f8fafc 0%, #e2e8f0 100%);
            min-height: 100vh;
            padding: 20px;
        }
        
        .export-container {
            max-width: 1000px;
            margin: 0 auto;
            background: white;
            border-radius: 20px;
            box-shadow: 0 10px 25px rgba(0,0,0,0.1);
            padding: 40px;
        }
        
        .header-section {
            background: linear-gradient(135deg, #6d28d9 0%, #8b5cf6 100%);
            color: white;
            border-radius: 15px;
            padding: 30px;
            margin-bottom: 30px;
        }
        
        .option-card {
            border: 2px solid #e2e8f0;
            border-radius: 15px;
            padding: 30px;
            text-align: center;
            transition: all 0.3s;
            height: 100%;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
        }
        
        .option-card:hover {
            transform: translateY(-5px);
            border-color: #6d28d9;
            box-shadow: 0 10px 25px rgba(109, 40, 217, 0.1);
        }
        
        .option-icon {
            font-size: 3rem;
            color: #6d28d9;
            margin-bottom: 20px;
        }
        
        .btn-export {
            background: linear-gradient(135deg, #6d28d9 0%, #8b5cf6 100%);
            color: white;
            border: none;
            padding: 12px 30px;
            border-radius: 10px;
            font-weight: 600;
            transition: all 0.3s;
        }
        
        .btn-export:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(109, 40, 217, 0.3);
            color: white;
        }
        
        .btn-outline-export {
            border: 2px solid #6d28d9;
            color: #6d28d9;
            padding: 12px 30px;
            border-radius: 10px;
            font-weight: 600;
            transition: all 0.3s;
        }
        
        .btn-outline-export:hover {
            background: #6d28d9;
            color: white;
        }
        
        .code-preview {
            background: #1e293b;
            color: #e2e8f0;
            border-radius: 10px;
            padding: 20px;
            max-height: 300px;
            overflow-y: auto;
            font-family: 'Courier New', monospace;
            font-size: 0.9rem;
        }
        
        .step-item {
            display: flex;
            align-items: center;
            margin-bottom: 15px;
            padding: 15px;
            background: #f8fafc;
            border-radius: 10px;
        }
        
        .step-number {
            background: #6d28d9;
            color: white;
            width: 30px;
            height: 30px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-right: 15px;
            font-weight: bold;
        }
        
        .success-alert {
            position: fixed;
            top: 20px;
            right: 20px;
            z-index: 9999;
            min-width: 300px;
            animation: slideIn 0.3s ease;
        }
        
        @keyframes slideIn {
            from { transform: translateX(100%); opacity: 0; }
            to { transform: translateX(0); opacity: 1; }
        }
        
        .html-output {
            white-space: pre-wrap;
            word-wrap: break-word;
            background: #f8f9fa;
            border: 1px solid #dee2e6;
            border-radius: 5px;
            padding: 15px;
            font-family: 'Courier New', monospace;
            font-size: 12px;
            max-height: 400px;
            overflow-y: auto;
        }
        
        .fbclid-info {
            background: #e7f3ff;
            border-left: 4px solid #1877f2;
            padding: 15px;
            border-radius: 8px;
            margin: 20px 0;
        }
    </style>
</head>
<body>
    <div id="successAlert" class="success-alert alert alert-success alert-dismissible fade show d-none" role="alert">
        <span id="alertMessage"></span>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
    
    <div class="export-container">
        <div class="header-section">
            <h1 class="mb-3"><i class="fas fa-file-export me-2"></i>Export HTML</h1>
            <h3 class="mb-0"><?= htmlspecialchars($page['title']) ?></h3>
            <p class="mb-0 mt-2 opacity-75">Generate complete HTML file matching preview.php style</p>
        </div>
        
        <div class="fbclid-info">
            <h5><i class="fab fa-facebook me-2"></i>Facebook Click ID (fbclid) Support</h5>
            <p class="mb-0">HTML yang di-generate sudah termasuk fitur otomatis untuk:</p>
            <ul class="mb-0">
                <li>Menangkap parameter <code>?fbclid=...</code> dari URL</li>
                <li>Menambahkan fbclid ke semua link button secara otomatis</li>
                <li>Menyimpan fbclid di cookie untuk tracking CAPI</li>
                <li>Mengirim fbclid ke Meta Pixel dan Conversions API</li>
            </ul>
        </div>
        
        <div class="row mb-5">
            <div class="col-md-6 mb-4">
                <div class="option-card">
                    <div>
                        <div class="option-icon">
                            <i class="fas fa-download"></i>
                        </div>
                        <h4>Download HTML File</h4>
                        <p class="text-muted">Download complete HTML file ready to deploy</p>
                    </div>
                    <div class="mt-4">
                        <a href="export_html.php?id=<?= $page_id ?>&download=1" class="btn btn-export w-100">
                            <i class="fas fa-download me-2"></i>Download HTML File
                        </a>
                    </div>
                </div>
            </div>
            
            <div class="col-md-6 mb-4">
                <div class="option-card">
                    <div>
                        <div class="option-icon">
                            <i class="fas fa-code"></i>
                        </div>
                        <h4>Copy HTML Code</h4>
                        <p class="text-muted">Copy HTML code to clipboard (full page)</p>
                    </div>
                    <div class="mt-4">
                        <button onclick="copyFullHTML()" class="btn btn-outline-export w-100">
                            <i class="fas fa-copy me-2"></i>Copy to Clipboard
                        </button>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="row">
            <div class="col-md-6 mb-4">
                <div class="option-card">
                    <div>
                        <div class="option-icon">
                            <i class="fas fa-eye"></i>
                        </div>
                        <h4>Preview HTML</h4>
                        <p class="text-muted">Preview generated HTML in browser</p>
                    </div>
                    <div class="mt-4">
                        <a href="export_html.php?id=<?= $page_id ?>&preview=1" target="_blank" class="btn btn-outline-export w-100">
                            <i class="fas fa-external-link-alt me-2"></i>Open Preview
                        </a>
                    </div>
                </div>
            </div>
            
            <div class="col-md-6 mb-4">
                <div class="option-card">
                    <div>
                        <div class="option-icon">
                            <i class="fas fa-arrow-left"></i>
                        </div>
                        <h4>Back to Builder</h4>
                        <p class="text-muted">Return to page builder</p>
                    </div>
                    <div class="mt-4">
                        <a href="builder.php?id=<?= $page_id ?>" class="btn btn-outline-export w-100">
                            <i class="fas fa-edit me-2"></i>Edit Page
                        </a>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="mt-5">
            <h4 class="mb-3"><i class="fas fa-file-code me-2"></i>HTML Preview (First 1000 characters)</h4>
            <div class="html-output">
                <?= htmlspecialchars(substr($full_html, 0, 1000)) . "\n\n... [truncated - full file: " . strlen($full_html) . " characters]" ?>
            </div>
            <div class="text-end mt-2">
                <small class="text-muted">Total: <?= strlen($full_html) ?> characters</small>
            </div>
        </div>
        
        <div class="mt-5">
            <h4 class="mb-3"><i class="fas fa-list-ol me-2"></i>How to Use</h4>
            <div class="step-item">
                <div class="step-number">1</div>
                <div>
                    <strong>Download or copy the HTML code</strong>
                    <p class="mb-0 small text-muted">Choose your preferred method above</p>
                </div>
            </div>
            <div class="step-item">
                <div class="step-number">2</div>
                <div>
                    <strong>Upload to your server</strong>
                    <p class="mb-0 small text-muted">Upload the HTML file to your web hosting</p>
                </div>
            </div>
            <div class="step-item">
                <div class="step-number">3</div>
                <div>
                    <strong>Share link with fbclid</strong>
                    <p class="mb-0 small text-muted">Share your page with Facebook ads using fbclid parameter</p>
                </div>
            </div>
            <div class="step-item">
                <div class="step-number">4</div>
                <div>
                    <strong>Test tracking</strong>
                    <p class="mb-0 small text-muted">Visit your page with ?fbclid=test and check button links</p>
                </div>
            </div>
        </div>
        
        <div class="mt-5">
            <h4 class="mb-3"><i class="fas fa-info-circle me-2"></i>Features Included</h4>
            <div class="row">
                <div class="col-md-6">
                    <ul class="list-group">
                        <li class="list-group-item d-flex align-items-center">
                            <i class="fas fa-check text-success me-2"></i>
                            Meta Pixel Integration
                        </li>
                        <li class="list-group-item d-flex align-items-center">
                            <i class="fas fa-check text-success me-2"></i>
                            <strong>Facebook fbclid Support</strong>
                        </li>
                        <li class="list-group-item d-flex align-items-center">
                            <i class="fas fa-check text-success me-2"></i>
                            Microsoft Clarity Tracking
                        </li>
                        <li class="list-group-item d-flex align-items-center">
                            <i class="fas fa-check text-success me-2"></i>
                            Google Fonts (Nunito)
                        </li>
                    </ul>
                </div>
                <div class="col-md-6">
                    <ul class="list-group">
                        <li class="list-group-item d-flex align-items-center">
                            <i class="fas fa-check text-success me-2"></i>
                            Responsive Design
                        </li>
                        <li class="list-group-item d-flex align-items-center">
                            <i class="fas fa-check text-success me-2"></i>
                            Mobile Optimization
                        </li>
                        <li class="list-group-item d-flex align-items-center">
                            <i class="fas fa-check text-success me-2"></i>
                            SEO Meta Tags
                        </li>
                        <li class="list-group-item d-flex align-items-center">
                            <i class="fas fa-check text-success me-2"></i>
                            Interactive FAQ
                        </li>
                    </ul>
                </div>
            </div>
        </div>
    </div>
    
    <script>
    function showAlert(message, type = 'success') {
        const alert = document.getElementById('successAlert');
        const messageSpan = document.getElementById('alertMessage');
        
        alert.className = `success-alert alert alert-${type} alert-dismissible fade show`;
        messageSpan.textContent = message;
        alert.classList.remove('d-none');
        
        // Auto hide after 5 seconds
        setTimeout(() => {
            alert.classList.add('d-none');
        }, 5000);
    }
    
    function copyFullHTML() {
        // AJAX request untuk mendapatkan full HTML
        fetch('export_html.php?id=<?= $page_id ?>&preview=1')
            .then(response => response.text())
            .then(html => {
                // Copy ke clipboard
                navigator.clipboard.writeText(html)
                    .then(() => {
                        showAlert('✓ Complete HTML code copied to clipboard!', 'success');
                    })
                    .catch(err => {
                        console.error('Failed to copy: ', err);
                        showAlert('✗ Failed to copy. Please try again.', 'danger');
                    });
            })
            .catch(error => {
                console.error('Error fetching HTML:', error);
                showAlert('✗ Failed to fetch HTML code.', 'danger');
            });
    }
    
    // Event listener untuk close alert
    document.addEventListener('click', function(e) {
        if (e.target.classList.contains('btn-close')) {
            e.target.closest('.alert').classList.add('d-none');
        }
    });
    
    // Test fbclid functionality
    function testFbclid() {
        const testUrl = window.location.href.split('?')[0] + '?fbclid=test_123456789';
        showAlert(`Test URL with fbclid: ${testUrl}`, 'info');
    }
    </script>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>