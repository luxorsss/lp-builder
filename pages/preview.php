<?php
require_once '../includes/config.php';
require_once '../includes/auth.php';

// Cek login
requireLogin();
$user = getCurrentUser($pdo);

// Ambil page ID
$page_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if (!$page_id) {
    die("Landing page tidak ditemukan");
}

// Ambil data landing page dan elemen-elemennya
$stmt = $pdo->prepare("SELECT * FROM landing_pages WHERE id = ? AND user_id = ?");
$stmt->execute([$page_id, $user['id']]);
$page = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$page) {
    die("Landing page tidak ditemukan atau bukan milik Anda");
}

// Parse tracking config
function parseTrackingConfig($config_string) {
    $config = json_decode($config_string, true);
    if (is_array($config)) {
        return $config;
    } else {
        // Format lama: hanya pixel ID
        return [
            'pixel_id' => $config_string ?? '',
            'capi_endpoint' => '',
            'capi_access_token' => ''
        ];
    }
}
$tracking_config = parseTrackingConfig($page['meta_pixel_id']);

// Ambil elemen-elemen
$stmt = $pdo->prepare("SELECT * FROM page_elements WHERE page_id = ? ORDER BY order_position ASC");
$stmt->execute([$page_id]);
$elements = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html>

<head>
    <title>Preview: <?= htmlspecialchars($page['title']) ?></title>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">

    <!-- Meta Pixel Code -->
    <?php if (!empty($tracking_config['pixel_id'])): ?>
    <script>
    !function(f,b,e,v,n,t,s)
    {if(f.fbq)return;n=f.fbq=function(){n.callMethod?
    n.callMethod.apply(n,arguments):n.queue.push(arguments)};
    if(!f._fbq)f._fbq=n;n.push=n;n.loaded=!0;n.version='2.0';
    n.queue=[];t=b.createElement(e);t.async=!0;
    t.src=v;s=b.getElementsByTagName(e)[0];
    s.parentNode.insertBefore(t,s)}(window, document,'script',
    'https://connect.facebook.net/en_US/fbevents.js');
    fbq('init', '<?= htmlspecialchars($tracking_config['pixel_id']) ?>');
    fbq('track', 'ViewContent');
    </script>
    <noscript>
        <img height="1" width="1" style="display:none"
            src="https://www.facebook.com/tr?id=<?= htmlspecialchars($tracking_config['pixel_id']) ?>&ev=ViewContent&noscript=1" />
    </noscript>
    <?php endif; ?>

    <style>
        @import url('https://fonts.googleapis.com/css2?family=Nunito:wght@300;400;600;700;800&display=swap');

        body {
            font-family: 'Nunito', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            line-height: 1.6;
            background: linear-gradient(135deg, #f0f4ff 0%, #e6e9ff 100%);
            margin: 0;
            padding: 0;
            color: #4b5563;
        }

        .page-container {
            max-width: 700px;
            margin: 40px auto;
            background: white;
            border-radius: 16px;
            box-shadow: 0 10px 40px rgba(0, 0, 0, 0.1);
        }

        @media (max-width: 768px) {
            .page-container {
                margin: 15px;
                border-radius: 12px;
            }
        }

        .content-wrapper {
            padding: 40px 30px 30px;
        }

        @media (max-width: 768px) {
            .content-wrapper {
                padding: 25px 20px;
            }
        }

        .element-spacing {
            margin-bottom: 30px;
        }

        .element-spacing:last-child {
            margin-bottom: 0;
        }

        /* Typography */
        h1, h2, h3, h4, h5, h6 {
            margin-top: 0;
            margin-bottom: 0.5em;
            line-height: 1.3;
            color: #1f2937;
        }

        h1 { font-size: 2.25rem; font-weight: 800; }
        h2 { font-size: 1.875rem; font-weight: 700; }
        h3 { font-size: 1.5rem; font-weight: 600; }
        h4 { font-size: 1.25rem; font-weight: 600; }

        p {
            margin-top: 0;
            margin-bottom: 1em;
            color: #4b5563;
        }

        /* Button */
        .button-element {
            margin-bottom: 20px;
            text-align: center;
        }

        .custom-button {
            display: inline-block;
            padding: 16px 40px;
            text-decoration: none;
            border-radius: 12px;
            font-weight: 700;
            font-size: 1.125rem;
            transition: all 0.3s ease;
            border: none;
            line-height: 1.2;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
        }

        .custom-button:hover {
            transform: translateY(-3px);
            box-shadow: 0 8px 20px rgba(0, 0, 0, 0.2);
        }

        /* Image */
        .image-element {
            margin-bottom: 20px;
            text-align: center;
        }

        .image-element img {
            max-width: 100%;
            height: auto;
            border-radius: 12px;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1);
        }

        /* Divider */
        .divider-element {
            text-align: center;
            margin: 25px 0;
        }

        .divider-line {
            border: 0;
            opacity: 1;
            margin: 0;
        }

        /* YouTube */
        .youtube-element {
            margin-bottom: 20px;
            border-radius: 12px;
            overflow: hidden;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1);
        }

        .youtube-element iframe {
            border-radius: 12px;
        }

        /* FAQ */
        .faq-element {
            margin-bottom: 20px;
        }

        .faq-item {
            margin-bottom: 20px;
            padding-bottom: 20px;
            border-bottom: 1px solid rgba(0,0,0,0.08);
        }

        .faq-item:last-child {
            border-bottom: none;
            padding-bottom: 0;
            margin-bottom: 0;
        }

        .faq-question {
            font-weight: 700;
            font-size: 1.125rem;
            margin-bottom: 8px;
            color: #1f2937;
        }

        .faq-answer {
            color: #6b7280;
            line-height: 1.7;
        }

        /* Form */
        .form-element {
            margin-bottom: 25px;
            background: #f9fafb;
            padding: 30px;
            border-radius: 12px;
            border: 1px solid #e5e7eb;
        }

        .form-label {
            font-weight: 600;
            color: #374151;
            margin-bottom: 0.5rem;
        }

        .form-control {
            border-radius: 8px;
            border: 1px solid #d1d5db;
            padding: 12px 16px;
        }

        .form-control:focus {
            border-color: #6366f1;
            box-shadow: 0 0 0 3px rgba(99, 102, 241, 0.1);
        }

        .btn-primary {
            background: linear-gradient(135deg, #6366f1 0%, #8b5cf6 100%);
            border: none;
            padding: 14px 28px;
            font-weight: 600;
            border-radius: 8px;
            transition: all 0.3s ease;
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 15px rgba(99, 102, 241, 0.4);
        }

        /* Back to Builder Button */
        .back-to-builder {
            position: fixed;
            top: 20px;
            right: 20px;
            z-index: 1000;
        }

        .back-to-builder .btn {
            background: linear-gradient(135deg, #6366f1 0%, #8b5cf6 100%);
            color: white;
            border: none;
            padding: 12px 24px;
            border-radius: 50px;
            font-weight: 600;
            box-shadow: 0 4px 15px rgba(99, 102, 241, 0.4);
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .back-to-builder .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(99, 102, 241, 0.5);
            color: white;
        }

        .back-to-builder .btn i {
            font-size: 1.1rem;
        }

        /* Responsive */
        @media (max-width: 768px) {
            .content-wrapper {
                padding: 25px 20px;
            }

            h1 { font-size: 1.875rem; }
            h2 { font-size: 1.5rem; }
            h3 { font-size: 1.25rem; }

            .custom-button {
                padding: 14px 28px;
                font-size: 1rem;
            }

            .form-element {
                padding: 20px;
            }
        }
    </style>
</head>

<body>
    <!-- Back to Builder Button -->
    <div class="back-to-builder">
        <a href="builder.php?id=<?= $page_id ?>" class="btn">
            <i class="fas fa-edit"></i> Edit Halaman
        </a>
    </div>

    <div class="page-container">
        <div class="content-wrapper">
            <?php foreach ($elements as $element): ?>
            <div class="element-spacing">
                <?php if ($element['type'] == 'header'): ?>
                <div class="header-element">
                    <?= $element['content'] ?>
                </div>
                <?php elseif ($element['type'] == 'paragraph'): ?>
                <div class="paragraph-element">
                    <?= $element['content'] ?>
                </div>
                <?php elseif ($element['type'] == 'divider'): ?>
                    <?php
                    $styles = $element['styles'] ? json_decode($element['styles'], true) : [];
                    $styles = $styles ?: [];
                    $thickness = $styles['thickness'] ?? '2';
                    $divider_style = $styles['divider_style'] ?? 'solid';
                    $color = $styles['text_color'] ?? '#cccccc';
                    $margin = $styles['margin'] ?? '20';
                    
                    $border_style = 'solid';
                    if($divider_style === 'dashed') $border_style = 'dashed';
                    if($divider_style === 'dotted') $border_style = 'dotted';
                    ?>
                    <div class="divider-element" style="margin: <?= $margin ?>px 0;">
                        <hr class="divider-line" 
                            style="
                                border-top: <?= $thickness ?>px <?= $border_style ?> <?= htmlspecialchars($color) ?>;
                                opacity: 1;
                                margin: 0;
                            ">
                    </div>
                <?php elseif ($element['type'] == 'image'): ?>
                <div class="image-element">
                    <?php if (!empty($element['content'])): ?>
                    <img src="<?= htmlspecialchars($element['content']) ?>" alt="Image">
                    <?php endif; ?>
                </div>
                <?php elseif ($element['type'] == 'button'): ?>
                <div class="button-element">
                    <?php
                    $styles = $element['styles'] ? json_decode($element['styles'], true) : [];
                    $styles = $styles ?: [];
                    $link = $styles['link'] ?? '#';
                    $bg_color = $styles['bg_color'] ?? '#6366f1';
                    $text_color = $styles['text_color'] ?? '#ffffff';
                    ?>
                    <a href="<?= htmlspecialchars($link) ?>" class="custom-button"
                        style="background-color: <?= htmlspecialchars($bg_color) ?>; color: <?= htmlspecialchars($text_color) ?>;">
                        <?= htmlspecialchars($element['content']) ?>
                    </a>
                </div>
                <?php elseif ($element['type'] == 'youtube'): ?>
                    <?php if (!empty($element['content'])): ?>
                    <div class="youtube-element">
                        <div style="position: relative; padding-bottom: 56.25%; height: 0; overflow: hidden;">
                            <iframe 
                                src="https://www.youtube.com/embed/<?= htmlspecialchars($element['content']) ?>?rel=0" 
                                style="position: absolute; top: 0; left: 0; width: 100%; height: 100%; border: 0;"
                                allow="autoplay; encrypted-media" 
                                allowfullscreen>
                            </iframe>
                        </div>
                    </div>
                    <?php endif; ?>
                <?php elseif ($element['type'] == 'faq'): ?>
                    <?php
                    $styles = $element['styles'] ? json_decode($element['styles'], true) : [];
                    $styles = $styles ?: [];
                    $bg_color = $styles['bg_color'] ?? '#ffffff';
                    $text_color = $styles['text_color'] ?? '#1f2937';
                    
                    $faq_data = [];
                    if (!empty($element['content'])) {
                        $faq_data = json_decode($element['content'], true) ?: [];
                    }
                    ?>
                    <div class="faq-element">
                        <div style="background: <?= htmlspecialchars($bg_color) ?>; border-radius: 12px; padding: 25px;">
                            <?php foreach ($faq_data as $item): ?>
                                <div class="faq-item">
                                    <div class="faq-question" style="color: <?= htmlspecialchars($text_color) ?>;">
                                        <?= htmlspecialchars($item['q'] ?? '') ?>
                                    </div>
                                    <div class="faq-answer">
                                        <?= htmlspecialchars($item['a'] ?? '') ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php elseif ($element['type'] == 'form'): ?>
                <div class="form-element">
                    <?php if (!empty($element['content'])): ?>
                        <?= $element['content'] ?>
                    <?php else: ?>
                        <form id="landingForm">
                            <div class="mb-3">
                                <label class="form-label">Nama Lengkap</label>
                                <input type="text" class="form-control form-control-lg" name="name" required>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Email</label>
                                <input type="email" class="form-control form-control-lg" name="email" required>
                            </div>
                            <div class="mb-4">
                                <label class="form-label">Pesan</label>
                                <textarea class="form-control" name="message" rows="4"></textarea>
                            </div>
                            <button type="submit" class="btn btn-primary btn-lg w-100">Kirim Pesan</button>
                        </form>
                    <?php endif; ?>
                </div>
                <?php elseif ($element['type'] == 'html'): ?>
                <div class="custom-html-element">
                    <?= $element['content'] ?>
                </div>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- CAPI Integration -->
    <?php if (!empty($tracking_config['pixel_id'])): ?>
    <script>
    window.addEventListener('load', function() {
        const capiData = {
            event: 'ViewContent',
            page_title: '<?= addslashes($page['title']) ?>',
            page_slug: '<?= addslashes($page['slug']) ?>'
        };

        <?php if (!empty($tracking_config['capi_endpoint'])): ?>
        fetch('<?= $tracking_config['capi_endpoint'] ?>', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    <?php if (!empty($tracking_config['capi_access_token'])): ?>
                    'Authorization': 'Bearer <?= $tracking_config['capi_access_token'] ?>'
                    <?php endif; ?>
                },
                body: JSON.stringify(capiData)
            })
            .catch(error => console.error('CAPI error:', error));
        <?php endif; ?>
    });

    document.getElementById('landingForm')?.addEventListener('submit', function(e) {
        e.preventDefault();

        if (typeof fbq !== 'undefined') {
            fbq('track', 'Lead', {
                content_name: '<?= addslashes($page['title']) ?>',
                content_category: 'landing_page'
            });
        }

        const formData = new FormData(this);
        const data = {
            event: 'Lead',
            page_title: '<?= addslashes($page['title']) ?>',
            page_slug: '<?= addslashes($page['slug']) ?>',
            user_data: {
                name: formData.get('name'),
                email: formData.get('email'),
                message: formData.get('message')
            }
        };

        fetch('../actions/capi_event.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify(data)
            })
            .then(response => response.json())
            .then(result => {
                alert(result.status === 'success' ? 'Pesan berhasil dikirim!' : 'Terjadi kesalahan');
                if (result.status === 'success') this.reset();
            })
            .catch(() => {
                alert('Pesan berhasil dikirim!');
                this.reset();
            });
    });
    </script>
    <?php endif; ?>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
</body>

</html>