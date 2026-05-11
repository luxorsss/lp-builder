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
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Export HTML - <?= htmlspecialchars($page['title']) ?></title>
    <script src="https://cdn.tailwindcss.com?plugins=forms"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet"/>
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght@100..700" rel="stylesheet"/>
    <style>
        body { font-family: 'Inter', sans-serif; }
        .material-symbols-outlined { font-variation-settings: 'FILL' 0, 'wght' 400, 'GRAD' 0, 'opsz' 24; }
        
        /* Animasi Custom Alert */
        @keyframes slideIn {
            from { transform: translateX(100%); opacity: 0; }
            to { transform: translateX(0); opacity: 1; }
        }
        .slide-in { animation: slideIn 0.3s ease forwards; }
    </style>
</head>
<body class="bg-slate-50 min-h-screen pb-20">

    <div class="bg-white border-b border-slate-200 sticky top-0 z-50">
        <div class="max-w-5xl mx-auto px-6 h-16 flex items-center justify-between">
            <div class="flex items-center gap-4">
                <a href="builder.php?id=<?= $page_id ?>" class="text-slate-500 hover:bg-slate-100 p-2 rounded-full transition-colors flex items-center" title="Kembali">
                    <span class="material-symbols-outlined">arrow_back</span>
                </a>
                <h1 class="text-[18px] font-bold text-slate-900">Export HTML</h1>
            </div>
        </div>
    </div>

    <div id="alertContainer" class="fixed top-20 right-6 z-50 flex flex-col gap-2"></div>

    <div class="max-w-5xl mx-auto px-6 mt-8">
        
        <div class="bg-gradient-to-r from-blue-700 to-blue-500 rounded-2xl p-8 text-white shadow-md shadow-blue-600/20 mb-8 relative overflow-hidden">
            <div class="relative z-10">
                <div class="flex items-center gap-3 mb-2">
                    <span class="material-symbols-outlined text-[32px] opacity-90">html</span>
                    <h2 class="text-[28px] font-bold"><?= htmlspecialchars($page['title']) ?></h2>
                </div>
                <p class="text-blue-100 max-w-xl">Generate *file* HTML murni yang siap di-*deploy* ke server mana pun, lengkap dengan sistem *tracking* yang utuh.</p>
            </div>
            <span class="material-symbols-outlined absolute -right-4 -bottom-10 text-[180px] text-white opacity-10 rotate-12 pointer-events-none">code_blocks</span>
        </div>

        <div class="bg-blue-50 border border-blue-200 rounded-2xl p-6 mb-8 flex gap-4">
            <span class="material-symbols-outlined text-blue-600 text-[28px] flex-shrink-0">data_alert</span>
            <div>
                <h3 class="font-bold text-blue-900 mb-1 text-[15px]">Facebook Click ID (fbclid) Terintegrasi</h3>
                <p class="text-blue-800 text-[13px] leading-relaxed mb-2">Kode HTML yang di-*generate* sudah dilengkapi fitur pintar otomatis untuk:</p>
                <ul class="text-blue-800 text-[13px] list-disc list-inside space-y-1 ml-1 opacity-90">
                    <li>Menangkap parameter <code class="font-bold bg-blue-100 px-1 rounded">?fbclid=</code> dari URL iklan Meta Anda.</li>
                    <li>Menyisipkan *fbclid* tersebut ke semua tautan (*link* tombol) secara otomatis.</li>
                    <li>Menyimpan rekam jejak di *cookie* browser untuk pengiriman *Conversions API (CAPI)*.</li>
                </ul>
            </div>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-10">
            <div class="bg-white rounded-2xl shadow-sm border border-slate-200 hover:border-blue-400 hover:shadow-md transition-all p-8 flex flex-col items-center text-center group">
                <div class="w-16 h-16 bg-blue-50 text-blue-600 rounded-2xl flex items-center justify-center mb-4 group-hover:scale-110 transition-transform">
                    <span class="material-symbols-outlined text-[32px]">download</span>
                </div>
                <h4 class="text-[18px] font-bold text-slate-900 mb-2">Download File HTML</h4>
                <p class="text-[13px] text-slate-500 mb-6 flex-grow">Unduh *file* HTML utuh yang langsung siap diunggah ke *hosting* atau *cPanel* Anda.</p>
                <a href="export_html.php?id=<?= $page_id ?>&download=1" class="w-full bg-blue-600 text-white font-bold py-3 rounded-xl hover:bg-blue-700 transition-colors flex items-center justify-center gap-2">
                    <span class="material-symbols-outlined text-[18px]">cloud_download</span> Download File
                </a>
            </div>

            <div class="bg-white rounded-2xl shadow-sm border border-slate-200 hover:border-blue-400 hover:shadow-md transition-all p-8 flex flex-col items-center text-center group">
                <div class="w-16 h-16 bg-blue-50 text-blue-600 rounded-2xl flex items-center justify-center mb-4 group-hover:scale-110 transition-transform">
                    <span class="material-symbols-outlined text-[32px]">content_copy</span>
                </div>
                <h4 class="text-[18px] font-bold text-slate-900 mb-2">Salin Kode HTML</h4>
                <p class="text-[13px] text-slate-500 mb-6 flex-grow">Salin seluruh kode HTML ke *clipboard* untuk di-*paste* ke *platform* lain.</p>
                <button onclick="copyFullHTML()" class="w-full bg-white text-blue-600 border-2 border-blue-600 font-bold py-2.5 rounded-xl hover:bg-blue-50 transition-colors flex items-center justify-center gap-2">
                    <span class="material-symbols-outlined text-[18px]">file_copy</span> Copy ke Clipboard
                </button>
            </div>
        </div>

        <div class="bg-slate-900 rounded-2xl shadow-lg overflow-hidden border border-slate-800">
            <div class="bg-slate-800 px-6 py-4 flex items-center justify-between border-b border-slate-700">
                <div class="flex items-center gap-2 text-slate-300">
                    <span class="material-symbols-outlined text-[18px]">terminal</span>
                    <h4 class="font-bold text-[14px]">Pratinjau Kode Sumber</h4>
                </div>
                <span class="text-xs font-mono text-slate-500 bg-slate-900 px-2 py-1 rounded"><?= number_format(strlen($full_html)) ?> characters</span>
            </div>
            <div class="p-6 overflow-y-auto max-h-[400px]">
                <pre class="text-slate-300 font-mono text-[12px] whitespace-pre-wrap word-break-all leading-relaxed"><?= htmlspecialchars(substr($full_html, 0, 1500)) . "\n\n... [Kode dipotong - Panjang total: " . strlen($full_html) . " karakter]" ?></pre>
            </div>
        </div>

    </div>
    
    <script>
    function showAlert(message, isSuccess = true) {
        const container = document.getElementById('alertContainer');
        const alertDiv = document.createElement('div');
        
        const bgColor = isSuccess ? 'bg-green-50 border-green-200 text-green-700' : 'bg-red-50 border-red-200 text-red-700';
        const icon = isSuccess ? 'check_circle' : 'error';
        
        alertDiv.className = `slide-in flex items-center gap-3 px-5 py-4 rounded-xl border shadow-lg ${bgColor}`;
        alertDiv.innerHTML = `
            <span class="material-symbols-outlined text-[20px]">${icon}</span>
            <span class="text-[14px] font-medium">${message}</span>
            <button class="ml-2 text-current opacity-70 hover:opacity-100" onclick="this.parentElement.remove()">
                <span class="material-symbols-outlined text-[18px]">close</span>
            </button>
        `;
        
        container.appendChild(alertDiv);
        
        setTimeout(() => {
            alertDiv.style.opacity = '0';
            alertDiv.style.transform = 'translateX(100%)';
            alertDiv.style.transition = 'all 0.3s ease';
            setTimeout(() => alertDiv.remove(), 300);
        }, 5000);
    }
    
    function copyFullHTML() {
        const copyBtn = event.currentTarget;
        const originalText = copyBtn.innerHTML;
        
        copyBtn.innerHTML = '<span class="material-symbols-outlined text-[18px] animate-spin">refresh</span> Menyalin...';
        copyBtn.disabled = true;

        fetch('export_html.php?id=<?= $page_id ?>&preview=1')
            .then(response => response.text())
            .then(html => {
                navigator.clipboard.writeText(html)
                    .then(() => {
                        showAlert('Berhasil! Seluruh kode HTML telah disalin ke clipboard.');
                    })
                    .catch(err => {
                        console.error('Failed to copy: ', err);
                        showAlert('Gagal menyalin kode. Silakan coba lagi.', false);
                    })
                    .finally(() => {
                        copyBtn.innerHTML = originalText;
                        copyBtn.disabled = false;
                    });
            })
            .catch(error => {
                console.error('Error fetching HTML:', error);
                showAlert('Gagal memuat kode HTML.', false);
                copyBtn.innerHTML = originalText;
                copyBtn.disabled = false;
            });
    }
    </script>
</body>
</html>