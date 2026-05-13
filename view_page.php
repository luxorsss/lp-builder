<?php
require_once 'includes/config.php';

$slug = trim($_GET['slug'] ?? '');
if (empty($slug)) {
    http_response_code(404);
    die("Landing page tidak ditemukan");
}

// Ambil data halaman DAN data pixel yang terhubung
$stmt = $pdo->prepare("
    SELECT 
        lp.*, 
        pp.pixel_id AS actual_pixel_id, 
        pp.capi_endpoint AS actual_capi_endpoint, 
        pp.capi_token AS actual_capi_token,
        pp.clarity_project_id AS actual_clarity_id 
    FROM landing_pages lp 
    LEFT JOIN pixel_profiles pp ON lp.pixel_profile_id = pp.id 
    WHERE lp.slug = ? LIMIT 1
");
$stmt->execute([$slug]);
$p = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$p) {
    http_response_code(404);
    die("Landing page tidak ditemukan");
}

$is_published = ($p['status'] ?? '') === 'published';
$meta_event_name = trim($p['meta_event_name'] ?? 'ViewContent');

// Parsing pixel & CAPI: Gunakan data profil (actual_*) jika ada, jika tidak fallback ke kolom lama
$pix = [
    'pixel_id' => trim($p['actual_pixel_id'] ?: ($p['meta_pixel_id'] ?? '')),
    'capi_endpoint' => trim($p['actual_capi_endpoint'] ?: ($p['capi_endpoint'] ?? '')),
    'capi_access_token' => trim($p['actual_capi_token'] ?: ($p['capi_access_token'] ?? '')),
    'clarity_id' => trim($p['actual_clarity_id'] ?? '') // Tangkap ID Clarity
];

// Fallback jika menyimpan JSON di kolom lama (Opsional, untuk backward compatibility)
$t = json_decode($p['meta_pixel_id'] ?? '', true);
if (is_array($t) && !empty($t['pixel_id'])) {
    $pix['pixel_id'] = $t['pixel_id'];
    $pix['capi_endpoint'] = $t['capi_endpoint'] ?? $pix['capi_endpoint'];
    $pix['capi_access_token'] = $t['capi_access_token'] ?? $pix['capi_access_token'];
}

if (empty($pix['capi_endpoint']) && !empty($pix['pixel_id'])) {
    $pix['capi_endpoint'] = "https://graph.facebook.com/v23.0/{$pix['pixel_id']}/events";
}

// Query elements
$stmt = $pdo->prepare("SELECT type,content,styles FROM page_elements WHERE page_id=? ORDER BY order_position");
$stmt->execute([$p['id']]);
$elements = $stmt->fetchAll(PDO::FETCH_ASSOC);

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
            $thickness = $styles['thickness'] ?? '2';
			$divider_style = $styles['divider_style'] ?? 'solid';
			$margin = $styles['margin'] ?? '20';
			echo "<div class='divider-element' style='margin: {$margin}px 0;'>";
			echo "<hr style='border:0;height:0;border-top:{$thickness}px {$divider_style} " . htmlspecialchars($text_color) . ";opacity:1;margin:0;'>";
			echo "</div>";
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
                if (!empty($_GET['fbclid'])) {
                    $separator = strpos($link, '?') !== false ? '&' : '?';
                    $link .= $separator . 'fbclid=' . urlencode($_GET['fbclid']);
                }
                $target = strpos($link, 'http') === 0 ? 'rel="noopener"' : '';
                echo "<div class='button-element'>
                    <a href='" . htmlspecialchars($link) . "'
                       class='custom-button " . htmlspecialchars($btn_size) . "'
                       style='background:" . htmlspecialchars($bg_color) . ";color:" . htmlspecialchars($text_color) . "'
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
            
        case 'form':
            if ($is_published) {
                echo "<div class='form-element' style='background-color:" . htmlspecialchars($bg_color) . "; color:" . htmlspecialchars($text_color) . ";'>";
                echo "<form id='landingForm'>";
                echo "<div class='mb-2'><label style='color:" . htmlspecialchars($text_color) . ";'>Nama Lengkap</label><input type='text' class='form-control' name='name' required style='color:#000;'></div>";
                echo "<div class='mb-2'><label style='color:" . htmlspecialchars($text_color) . ";'>Email</label><input type='email' class='form-control' name='email' required style='color:#000;'></div>";
                echo "<div class='mb-3'><label style='color:" . htmlspecialchars($text_color) . ";'>Pesan</label><textarea class='form-control' name='message' rows='3' style='color:#000;'></textarea></div>";
                echo "<button type='submit' class='btn btn-primary w-100' style='background:" . htmlspecialchars($text_color) . ";color:" . htmlspecialchars($bg_color) . ";'>Kirim Pesan</button>";
                echo "</form></div>";
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
            
        case 'video':
            if (!empty($content)) {
                $poster = $styles['poster'] ?? '';
                $controls = isset($styles['controls']) ? (bool)$styles['controls'] : true;
                $autoplay = isset($styles['autoplay']) ? (bool)$styles['autoplay'] : false;
                
                echo "<div class='video-element' style='text-align:center; margin:0 auto; max-width:100%;'>
                    <video style='max-width:100%; height:auto; border-radius:8px;'
                           " . ($controls ? 'controls' : '') . "
                           " . ($autoplay ? 'autoplay' : '') . "
                           " . (!empty($poster) ? "poster='" . htmlspecialchars($poster) . "'" : '') . ">
                        <source src='" . htmlspecialchars($content) . "' type='video/mp4'>
                        Browser Anda tidak mendukung tag video.
                    </video>
                </div>";
            }
            break;
            
        case 'audio':
            if (!empty($content)) {
                $controls = isset($styles['controls']) ? (bool)$styles['controls'] : true;
                $autoplay = isset($styles['autoplay']) ? (bool)$styles['autoplay'] : false;
                $loop = isset($styles['loop']) ? (bool)$styles['loop'] : false;
                
                echo "<div class='audio-element' style='background:#f8f9fa; padding:20px; border-radius:10px; margin:0 auto; max-width:100%;'>
                    <audio style='width:100%;'
                           " . ($controls ? 'controls' : '') . "
                           " . ($autoplay ? 'autoplay' : '') . "
                           " . ($loop ? 'loop' : '') . ">
                        <source src='" . htmlspecialchars($content) . "' type='audio/mpeg'>
                        Browser Anda tidak mendukung tag audio.
                    </audio>
                </div>";
            }
            break;
            
        case 'iframe':
            if (!empty($content)) {
                $height = $styles['height'] ?? '400px';
                $allow_fullscreen = isset($styles['allow_fullscreen']) ? (bool)$styles['allow_fullscreen'] : true;
                
                echo "<div class='iframe-element' style='margin:0 auto; max-width:100%;'>
                    <iframe src='" . htmlspecialchars($content) . "'
                            style='width:100%; height:" . htmlspecialchars($height) . "; border:1px solid #ddd; border-radius:8px;'
                            frameborder='0'
                            " . ($allow_fullscreen ? 'allowfullscreen' : '') . ">
                    </iframe>
                </div>";
            }
            break;
            
        case 'faq':
			try {
				$faqs = json_decode($content, true) ?: [];
				if (is_array($faqs) && !empty($faqs)) {
					echo "<div class='faq-element' style='background:" . htmlspecialchars($bg_color) . "; color:" . htmlspecialchars($text_color) . "; border-radius:12px; overflow:hidden; margin-top:20px;'>";
					foreach ($faqs as $i => $item) {
						$q = htmlspecialchars($item['q'] ?? $item['question'] ?? '');
						$a = htmlspecialchars($item['a'] ?? $item['answer'] ?? '');
						$is_first = ($i === 0);

						echo "<div class='faq-item' style='border-bottom:1px solid rgba(0,0,0,0.1);'>";
						echo "<div class='faq-question' style='padding:16px; cursor:pointer; font-weight:700; display:flex; justify-content:space-between; align-items:center;' data-index='{$i}'>";
						echo $q;
						echo "<span class='faq-toggle' data-open='−' data-close='+' style='font-size:1.2em; width:24px; height:24px; display:flex; align-items:center; justify-content:center;'>+</span>";
						echo "</div>";
						echo "<div class='faq-answer' style='padding:0 16px; max-height:" . ($is_first ? '500px' : '0') . "; overflow:hidden; transition:max-height 0.3s ease, padding 0.3s ease;'>";
						if ($is_first) echo "<p style='margin:16px 0; line-height:1.6;'>" . nl2br($a) . "</p>";
						else echo "<div style='margin:0 0 16px; line-height:1.6;'>" . nl2br($a) . "</div>";
						echo "</div>";
						echo "</div>";
					}
					echo "</div>";
				}
			} catch (Exception $e) {
				// Skip jika JSON invalid
			}
			break;
    }
    echo "<div class='element-spacing'></div>";
}
$content = ob_get_clean();

$has_faq = false;
foreach ($elements as $el) {
    if ($el['type'] === 'faq') {
        $has_faq = true;
        break;
    }
}

// ==========================================
// INTERCEPT: JIKA MENGGUNAKAN MODE PURE HTML
// ==========================================
if (!empty($p['is_pure_html'])) {
    if (!$is_published) {
        echo "<div style='font-family:sans-serif; text-align:center; padding:50px;'>⛔ Maaf, penjualan belum dibuka.</div>";
        exit;
    }

    $raw_html = $p['pure_html_content'] ?? '';
    $tracking_scripts = "";
    
    if (!empty($pix['pixel_id'])) {
        $tracking_scripts .= "
        <script>
        setTimeout(function() {
            !function(f,b,e,v,n,t,s)
            {if(f.fbq)return;n=f.fbq=function(){n.callMethod?
            n.callMethod.apply(n,arguments):n.queue.push(arguments)};
            if(!f._fbq)f._fbq=n;n.push=n;n.loaded=!0;n.version='2.0';
            n.queue=[];t=b.createElement(e);t.async=!0;
            t.src=v;s=b.getElementsByTagName(e)[0];
            s.parentNode.insertBefore(t,s)}(window, document,'script',
            'https://connect.facebook.net/en_US/fbevents.js');
            fbq('init', '" . htmlspecialchars($pix['pixel_id']) . "');
            fbq('track', '" . addslashes($meta_event_name) . "');
        }, 1500);
        </script>
        <noscript><img height='1' width='1' style='display:none' src='https://www.facebook.com/tr?id=" . htmlspecialchars($pix['pixel_id']) . "&ev=" . urlencode($meta_event_name) . "&noscript=1'/></noscript>
        
        <script>
        (function() {
            function getCookie(name) {
                const value = `; \${document.cookie}`;
                const parts = value.split(`; \${name}=`);
                if (parts.length === 2) return parts.pop().split(';').shift();
                return '';
            }
            const urlParams = new URLSearchParams(window.location.search);
            let fbc = urlParams.get('fbclid') || getCookie('_fbc') || '';
            const fbp = getCookie('_fbp') || '';
            const payload = {
                page_slug: '" . addslashes($p['slug']) . "',
                event: '" . addslashes($meta_event_name) . "',
                fbp: fbp, fbc: fbc, user_agent: navigator.userAgent
            };
            const blob = new Blob([JSON.stringify(payload)], { type: 'application/json' });
            if (navigator.sendBeacon) {
                navigator.sendBeacon('actions/pageview.php', blob);
            } else {
                fetch('actions/pageview.php', { method: 'POST', body: blob, keepalive: true }).catch(() => {});
            }
        })();
        </script>";
    }

    // --- ADD CLARITY INJECTION FOR PURE HTML HERE ---
    if (!empty($pix['clarity_id'])) {
        $tracking_scripts .= "
        <script>
            window.addEventListener('load', function() {
                setTimeout(function() {
                    (function(c,l,a,r,i,t,y){
                        c[a]=c[a]||function(){(c[a].q=c[a].q||[]).push(arguments)};
                        t=l.createElement(r);t.async=1;t.src=\"https://www.clarity.ms/tag/\"+i;
                        y=l.getElementsByTagName(r)[0];y.parentNode.insertBefore(t,y);
                    })(window, document, \"clarity\", \"script\", \"" . htmlspecialchars($pix['clarity_id']) . "\");
                    
                    if (typeof clarity === 'function') {
                        clarity(\"set\", \"PageName\", \"" . addslashes($p['title']) . "\");
                        clarity(\"set\", \"PageSlug\", \"" . addslashes($p['slug']) . "\");
                    }
                }, 2000); 
            });
        </script>";
    }
    // ------------------------------------------------

    if (stripos($raw_html, '</head>') !== false) {
        $raw_html = str_ireplace('</head>', $tracking_scripts . "\n</head>", $raw_html);
    } else {
        $raw_html = $tracking_scripts . "\n" . $raw_html;
    }

    echo $raw_html;
    exit; 
}
// ==========================================
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= htmlspecialchars($p['title']) ?></title>
<link rel="icon" href="/favicon.ico">

<?php if (!$is_published): ?>
<meta name="robots" content="noindex, nofollow">
<?php endif; ?>

<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Nunito:wght@300;400;600;700;800&display=swap" rel="stylesheet">

<?php if (!empty($pix['pixel_id'])): ?>
<script>
  !function(f,b,e,v,n,t,s)
  {if(f.fbq)return;n=f.fbq=function(){n.callMethod?
  n.callMethod.apply(n,arguments):n.queue.push(arguments)};
  if(!f._fbq)f._fbq=n;n.push=n;n.loaded=!0;n.version='2.0';
  n.queue=[];t=b.createElement(e);t.async=!0;
  t.src=v;s=b.getElementsByTagName(e)[0];
  s.parentNode.insertBefore(t,s)}(window, document,'script',
  'https://connect.facebook.net/en_US/fbevents.js');
  
  fbq('init', '<?= htmlspecialchars($pix['pixel_id']) ?>'); 
  fbq('track', '<?= htmlspecialchars($meta_event_name) ?>');
</script>
<noscript><img height='1' width='1' style='display:none' src='https://www.facebook.com/tr?id=<?= htmlspecialchars($pix['pixel_id']) ?>&ev=<?= urlencode($meta_event_name) ?>&noscript=1'/></noscript>
<?php endif; ?>
	
<style>
body{font-family:'Nunito',sans-serif;background:#F2F5FA;margin:0;padding:0;line-height:1.5}
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
.form-element{margin-bottom:15px;background:#f8f9fa;padding:20px;border-radius:10px;border:1px solid #e9ecef}
.custom-html-element{margin-bottom:8px}
.video-element, .audio-element, .iframe-element, .youtube-element { margin-bottom: 20px; }
.video-element video, .iframe-element iframe { max-width: 100%; border-radius: 8px; }
.audio-element audio { width: 100%; }
.divider-line{border:0;height:20px;border-top:1px solid #ccc;margin:6px auto}
.divider-dashed{border-top:2px dashed #ccc}
.divider-dotted{border-top:2px dotted #ccc}
.ql-align-center{text-align:center}
.ql-align-right{text-align:right}
.ql-align-justify{text-align:justify}
.form-control{width:100%;padding:10px;border:1px solid #ddd;border-radius:6px;font-family:'Nunito',sans-serif}
.btn{padding:12px 20px;background:#007bff;color:#fff;border:none;border-radius:6px;cursor:pointer;font-weight:600;transition:background .3s}
.w-100{width:100%}
.mb-2{margin-bottom:15px}
.mb-3{margin-bottom:20px}
.coming-soon-notice { text-align: center; padding: 30px 20px; background: #fef5f5; border-radius: 10px; border: 2px dashed #e74c3c; margin: 20px 0; }
.coming-soon-notice p { margin: 0; }
.testimonial-element, .faq-element { margin-top: 20px; }
.faq-question:hover { background-color: #f5f7fa; }
.faq-toggle { font-size: 1.2em; font-weight: bold; width: 24px; height: 24px; display: flex; align-items: center; justify-content: center; }

/* Ratio classes */
.ratio-16x9 { position: relative; padding-bottom: 56.25%; height: 0; overflow: hidden; }
.ratio-4x3 { position: relative; padding-bottom: 75%; height: 0; overflow: hidden; }
.ratio-1x1 { position: relative; padding-bottom: 100%; height: 0; overflow: hidden; }
.ratio-16x9 iframe, .ratio-4x3 iframe, .ratio-1x1 iframe { position: absolute; top: 0; left: 0; width: 100%; height: 100%; border: 0; }

/* Image classes */
.img-fluid { max-width: 100%; height: auto; }
.img-thumbnail { padding: 4px; background-color: #fff; border: 1px solid #dee2e6; border-radius: 4px; max-width: 100%; height: auto; }
.rounded { border-radius: 8px; }
.rounded-circle { border-radius: 50%; }

/* Quill & Blockquote */
.ql-size-small { font-size: 0.75em; }
.ql-size-large { font-size: 1.5em; }
.ql-size-huge { font-size: 2.5em; }
blockquote { border-left: 4px solid #4361ee; padding-left: 16px; margin: 10px 0; font-style: italic; color: #555; background: #f8f9fa; padding: 15px; border-radius: 0 4px 4px 0; }

/* FAQ */
.faq-element { margin-top: 20px; }
.faq-item { margin-bottom: 0; padding-bottom: 0; }
.faq-item:last-child { border-bottom: none; }
.faq-question { transition: background 0.3s ease; }
.faq-question:hover { background-color: #f5f7fa; }
.faq-toggle { font-size: 1.2em; font-weight: bold; width: 24px; height: 24px; display: flex; align-items: center; justify-content: center; }	
</style>
</head>
<body>
<div class="page-container">
    <div class="content-wrapper">
        <?= $content ?>
    </div>
</div>

<?php if (!empty($pix['pixel_id']) && $is_published): ?>
<script>
(function() {
    function getCookie(name) {
        const value = `; ${document.cookie}`;
        const parts = value.split(`; ${name}=`);
        if (parts.length === 2) return parts.pop().split(';').shift();
        return '';
    }

    const urlParams = new URLSearchParams(window.location.search);
    let fbc = urlParams.get('fbclid') || getCookie('_fbc') || '';
    const fbp = getCookie('_fbp') || '';
    const userAgent = navigator.userAgent;

    const payload = {
        page_slug: '<?= addslashes($p['slug']) ?>',
        event: '<?= addslashes($meta_event_name) ?>',
        fbp: fbp,
        fbc: fbc,
        user_agent: userAgent
    };

    const blob = new Blob([JSON.stringify(payload)], { type: 'application/json' });
    if (navigator.sendBeacon) {
        navigator.sendBeacon('actions/pageview.php', blob);
    } else {
        fetch('actions/pageview.php', { method: 'POST', body: blob, keepalive: true }).catch(() => {});
    }
})();
</script>

<script>
document.getElementById('landingForm')?.addEventListener('submit', function(e) {
    e.preventDefault();

    if (typeof fbq !== 'undefined') {
        fbq('track', 'Lead');
    }

    const formData = new FormData(this);
    const data = {
        event: 'Lead',
        page_title: '<?= addslashes($p['title']) ?>',
        page_slug: '<?= addslashes($p['slug']) ?>',
        user_data: {
            name: formData.get('name'),
            email: formData.get('email'),
            message: formData.get('message')
        }
    };

    fetch('actions/capi_event.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify(data)
    })
    .then(r => r.json())
    .then(r => {
        alert(r.status === 'success' ? 'Pesan berhasil dikirim!' : 'Terjadi kesalahan');
        if (r.status === 'success') this.reset();
    })
    .catch(() => {
        alert('Pesan berhasil dikirim!');
        this.reset();
    });
});
</script>
<?php endif; ?>
	
<?php if ($has_faq): ?>
<script>
document.addEventListener('DOMContentLoaded', function() {
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
});
</script>
<?php endif; ?>	

<?php if (!empty($pix['clarity_id']) && $is_published): ?>
<script>
    // Delay load script Clarity selama 2 detik agar tidak blokir proses render awal
    window.addEventListener('load', function() {
        setTimeout(function() {
            (function(c,l,a,r,i,t,y){
                c[a]=c[a]||function(){(c[a].q=c[a].q||[]).push(arguments)};
                t=l.createElement(r);t.async=1;t.src="https://www.clarity.ms/tag/"+i;
                y=l.getElementsByTagName(r)[0];y.parentNode.insertBefore(t,y);
            })(window, document, "clarity", "script", "<?= htmlspecialchars($pix['clarity_id']) ?>");
            
            // Injeksi Custom Tags (Memudahkan filter video heatmap di dashboard Clarity)
            if (typeof clarity === 'function') {
                clarity("set", "PageName", "<?= addslashes($p['title']) ?>");
                clarity("set", "PageSlug", "<?= addslashes($p['slug']) ?>");
            }
        }, 2000); 
    });
</script>
<?php endif; ?>
</body>
</html>