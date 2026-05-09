<?php
require_once 'includes/config.php';
require_once 'includes/auth.php';
require_once 'includes/helpers.php';

// Cek login
requireLogin();
$user = getCurrentUser($pdo);

// Update status otomatis berdasarkan jadwal
updateScheduledStatus($pdo, $user['id']);

// Handle bulk update jika ada POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'bulk_update_status') {
    $page_ids = $_POST['page_ids'] ?? [];
    if (!is_array($page_ids) || empty($page_ids)) {
        echo json_encode(['status' => 'error', 'message' => 'Tidak ada halaman yang dipilih.']);
        exit;
    }

    $new_status = $_POST['status'] === 'published' ? 'published' : 'draft';
    $page_ids = array_map('intval', $page_ids);
    $page_ids = array_filter($page_ids, fn($id) => $id > 0);

    if (empty($page_ids)) {
        echo json_encode(['status' => 'error', 'message' => 'ID halaman tidak valid.']);
        exit;
    }

    $placeholders = str_repeat('?,', count($page_ids) - 1) . '?';
    $stmt = $pdo->prepare("UPDATE landing_pages SET status = ? WHERE id IN ($placeholders) AND user_id = ?");
    $params = array_merge([$new_status], $page_ids, [$user['id']]);
    $stmt->execute($params);

    $affected = $stmt->rowCount();
    echo json_encode([
        'status' => 'success',
        'message' => "Berhasil memperbarui status $affected landing page menjadi '$new_status'."
    ]);
    exit;
}

// Handle bulk move ke folder
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'bulk_move_project') {
    $page_ids = $_POST['page_ids'] ?? [];
    $project_id = !empty($_POST['project_id']) ? (int)$_POST['project_id'] : null;

    if (empty($page_ids)) {
        echo json_encode(['status' => 'error', 'message' => 'Tidak ada halaman yang dipilih.']);
        exit;
    }

    $page_ids = array_map('intval', $page_ids);
    $placeholders = str_repeat('?,', count($page_ids) - 1) . '?';
    
    $stmt = $pdo->prepare("UPDATE landing_pages SET project_id = ? WHERE id IN ($placeholders) AND user_id = ?");
    $params = array_merge([$project_id], $page_ids, [$user['id']]);
    $stmt->execute($params);

    echo json_encode([
        'status' => 'success',
        'message' => "Berhasil memindahkan " . $stmt->rowCount() . " landing page ke folder."
    ]);
    exit;
}

// Proses tambah folder baru
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_folder') {
    $folder_name = trim($_POST['folder_name']);
    if (!empty($folder_name)) {
        $stmt = $pdo->prepare("INSERT INTO projects (user_id, name) VALUES (?, ?)");
        $stmt->execute([$user['id'], $folder_name]);
        $_SESSION['message'] = "Folder '$folder_name' berhasil dibuat.";
        header("Location: index.php");
        exit;
    }
}

// Ambil daftar folder
$stmt = $pdo->prepare("SELECT * FROM projects WHERE user_id = ? ORDER BY name ASC");
$stmt->execute([$user['id']]);
$projects = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Map folder untuk kemudahan akses nama
$folder_map = [];
foreach ($projects as $proj) {
    $folder_map[$proj['id']] = $proj['name'];
}

// Filter berdasarkan folder (opsional)
$active_project_id = isset($_GET['project_id']) ? (int)$_GET['project_id'] : null;

if ($active_project_id) {
    $stmt = $pdo->prepare("SELECT * FROM landing_pages WHERE user_id = ? AND project_id = ? ORDER BY created_at DESC");
    $stmt->execute([$user['id'], $active_project_id]);
} else {
    // Tampilkan semua LP (atau bisa diubah WHERE project_id IS NULL jika ingin menampilkan yang belum difolderkan saja)
    $stmt = $pdo->prepare("SELECT * FROM landing_pages WHERE user_id = ? ORDER BY created_at DESC");
    $stmt->execute([$user['id']]);
}
$landing_pages = $stmt->fetchAll(PDO::FETCH_ASSOC);

$total_lp = count($landing_pages);
$published_count = count(array_filter($landing_pages, fn($p) => $p['status'] == 'published'));
$draft_count = count(array_filter($landing_pages, fn($p) => $p['status'] == 'draft'));
?>

<!DOCTYPE html>
<html class="light" lang="id">
<head>
    <meta charset="utf-8"/>
    <meta content="width=device-width, initial-scale=1.0" name="viewport"/>
    <title>LP Builder Pro - Main Dashboard</title>
    <script src="https://cdn.tailwindcss.com?plugins=forms,container-queries"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet"/>
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&display=swap" rel="stylesheet"/>
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
                  "on-surface-variant": "#434655", "on-primary": "#ffffff",
                  "tertiary-fixed": "#ffdbcd", "on-tertiary-fixed": "#360f00"
              },
              spacing: { "sidebar-width": "260px", "container-max": "1280px" },
              fontFamily: { "body-md": ["Inter"], "title-sm": ["Inter"], "headline-md": ["Inter"] }
            }
          }
        }
    </script>
    <style>
        .material-symbols-outlined { font-variation-settings: 'FILL' 0, 'wght' 400, 'GRAD' 0, 'opsz' 24; }
        .material-symbols-outlined[data-weight="fill"] { font-variation-settings: 'FILL' 1; }
        /* Simple scrollbar */
        .no-scrollbar::-webkit-scrollbar { display: none; }
        .no-scrollbar { -ms-overflow-style: none; scrollbar-width: none; }
    </style>
</head>
<body class="bg-surface text-on-surface font-body-md text-[14px] h-screen flex overflow-hidden">

<nav class="w-sidebar-width h-screen fixed left-0 top-0 bg-surface-container-low shadow-sm flex flex-col p-4 gap-2 z-20">
    <div class="flex items-center gap-3 mb-8 px-2">
        <div class="w-8 h-8 rounded bg-primary-container text-on-primary-container flex items-center justify-center font-bold">LP</div>
        <div>
            <h1 class="text-[20px] font-bold text-primary">LP Builder Pro</h1>
            <p class="text-[12px] text-on-surface-variant">V.2.4.0</p>
        </div>
    </div>
    
    <a href="pages/builder.php<?= $active_project_id ? '?project_id='.$active_project_id : '' ?>" class="w-full bg-primary-container text-on-primary-container py-3 px-4 rounded-xl font-semibold mb-6 flex items-center justify-center gap-2 hover:bg-surface-tint transition-colors shadow-sm">
        <span class="material-symbols-outlined">add</span> Buat LP Baru
    </a>
    
    <div class="flex flex-col gap-1 flex-grow">
        <a class="flex items-center gap-3 px-4 py-3 bg-secondary-container text-on-secondary-container rounded-xl font-semibold transition-all" href="index.php">
            <span class="material-symbols-outlined" data-weight="fill">description</span>
            <span>Semua Halaman</span>
        </a>
        
        <a class="flex items-center gap-3 px-4 py-3 text-on-surface-variant hover:bg-surface-container-highest rounded-xl font-medium transition-all" href="pixels.php">
            <span class="material-symbols-outlined">analytics</span>
            <span>Pengaturan Pixel</span>
        </a>
    </div>
    
    <div class="flex flex-col gap-1 mt-auto pt-4 border-t border-surface-dim">
        <div class="px-4 py-3 text-on-surface-variant flex items-center gap-3">
            <span class="material-symbols-outlined">person</span>
            <span class="font-medium truncate"><?= htmlspecialchars($user['username']) ?></span>
        </div>
        <a class="flex items-center gap-3 px-4 py-3 text-error rounded-xl hover:bg-surface-container-highest transition-all" href="actions/logout.php">
            <span class="material-symbols-outlined">logout</span>
            <span class="font-medium">Keluar</span>
        </a>
    </div>
</nav>

<main class="ml-[260px] flex-1 flex flex-col h-screen overflow-y-auto bg-surface-container-lowest relative">

    <?php if (isset($_SESSION['message'])): ?>
        <div id="flashMessage" class="absolute top-4 left-1/2 transform -translate-x-1/2 bg-[#e8f5e9] text-[#2e7d32] border border-[#a5d6a7] px-4 py-3 rounded-lg shadow-md flex items-center gap-2 z-50 transition-opacity duration-500">
            <span class="material-symbols-outlined">check_circle</span>
            <span class="font-medium"><?= $_SESSION['message'] ?></span>
            <button onclick="document.getElementById('flashMessage').style.display='none'" class="ml-4 text-[#2e7d32] hover:text-[#1b5e20]"><span class="material-symbols-outlined text-[18px]">close</span></button>
        </div>
        <?php unset($_SESSION['message']); ?>
    <?php endif; ?>

    <div class="p-8 max-w-container-max mx-auto w-full flex-1">
        
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 mb-8">
            <div class="lg:col-span-2 bg-gradient-to-br from-primary-fixed to-surface-container-low p-8 rounded-xl shadow-sm relative overflow-hidden flex flex-col justify-center border border-outline-variant/20">
                <div class="relative z-10">
                    <h2 class="text-[32px] font-bold text-primary mb-2 uppercase">SELAMAT DATANG!</h2>
                    <p class="text-[15px] text-on-surface-variant max-w-md">Kelola dan buat landing page Anda dengan mudah menggunakan builder kami.</p>
                </div>
            </div>
            
            <div class="flex flex-col gap-4">
                <div class="bg-surface-container-lowest p-4 rounded-xl shadow-sm flex items-center justify-between border border-surface-container-highest">
                    <div class="flex items-center gap-3">
                        <div class="w-10 h-10 rounded-lg bg-surface-container flex items-center justify-center text-primary"><span class="material-symbols-outlined">description</span></div>
                        <span class="text-[15px] font-medium text-on-surface">Total LP</span>
                    </div>
                    <span class="text-[24px] font-bold text-on-background"><?= $total_lp ?></span>
                </div>
                <div class="bg-surface-container-lowest p-4 rounded-xl shadow-sm flex items-center justify-between border border-surface-container-highest">
                    <div class="flex items-center gap-3">
                        <div class="w-10 h-10 rounded-lg bg-secondary-container flex items-center justify-center text-on-secondary-container"><span class="material-symbols-outlined">check_circle</span></div>
                        <span class="text-[15px] font-medium text-on-surface">Published</span>
                    </div>
                    <span class="text-[24px] font-bold text-on-background"><?= $published_count ?></span>
                </div>
                <div class="bg-surface-container-lowest p-4 rounded-xl shadow-sm flex items-center justify-between border border-surface-container-highest">
                    <div class="flex items-center gap-3">
                        <div class="w-10 h-10 rounded-lg bg-[#fff8e1] flex items-center justify-center text-[#f57f17]"><span class="material-symbols-outlined">edit_document</span></div>
                        <span class="text-[15px] font-medium text-on-surface">Draft</span>
                    </div>
                    <span class="text-[24px] font-bold text-on-background"><?= $draft_count ?></span>
                </div>
            </div>
        </div>

        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 mb-6">
            <div class="flex items-center gap-2">
                <span class="material-symbols-outlined text-primary text-[28px]">folder_copy</span>
                <h2 class="text-[24px] font-bold text-on-background">Landing Page Saya</h2>
            </div>
            <div class="flex gap-3">
                <button onclick="openModal('addFolderModal')" class="bg-surface-container-lowest border border-outline-variant text-on-surface-variant px-4 py-2 rounded-lg font-medium flex items-center gap-2 hover:bg-surface-container-low transition-colors shadow-sm">
                    <span class="material-symbols-outlined text-[18px]">create_new_folder</span> Folder Baru
                </button>
                <a href="pages/builder.php<?= $active_project_id ? '?project_id='.$active_project_id : '' ?>" class="bg-primary-container text-on-primary-container px-4 py-2 rounded-lg font-medium flex items-center gap-2 hover:bg-surface-tint transition-colors shadow-sm">
                    <span class="material-symbols-outlined text-[18px]">add</span> Buat LP Baru
                </a>
            </div>
        </div>

        <div class="flex overflow-x-auto gap-2 pb-3 mb-4 no-scrollbar border-b border-surface-container-highest">
            <a href="index.php" class="px-4 py-2 rounded-lg <?= !$active_project_id ? 'bg-secondary-container text-on-secondary-container border-b-2 border-primary font-bold' : 'text-on-surface-variant hover:bg-surface-container font-medium transition-colors' ?> whitespace-nowrap">
                SEMUA LP
            </a>
            <?php foreach($projects as $proj): ?>
                <a href="index.php?project_id=<?= $proj['id'] ?>" class="px-4 py-2 rounded-lg <?= $active_project_id == $proj['id'] ? 'bg-secondary-container text-on-secondary-container border-b-2 border-primary font-bold' : 'text-on-surface-variant hover:bg-surface-container font-medium transition-colors' ?> whitespace-nowrap">
                    <?= htmlspecialchars($proj['name']) ?>
                </a>
            <?php endforeach; ?>
        </div>

        <div class="bg-surface-container-lowest rounded-xl shadow-sm border border-surface-container-highest overflow-visible pb-20">
            
            <div class="p-4 bg-surface-container-lowest border-b border-surface-container-highest flex items-center justify-between" id="bulkActionBar" style="display: none;">
                <div class="flex items-center gap-3">
                    <label class="flex items-center gap-3 cursor-pointer">
                        <input type="checkbox" id="selectAll" class="rounded border-outline-variant text-primary-container focus:ring-primary-container w-5 h-5 bg-surface-container-lowest"/>
                        <span class="font-medium text-on-surface-variant">Pilih Semua</span>
                    </label>
                    <span class="text-xs font-bold text-primary bg-primary-fixed px-2 py-1 rounded" id="selectedCount">0</span>
                </div>
                <div class="flex gap-2 relative">
                    <div class="relative inline-block text-left dropdown-container">
                        <button onclick="toggleDropdown('bulkStatusDropdown')" class="px-3 py-1.5 rounded bg-surface-container-lowest border border-outline-variant text-on-surface-variant font-medium flex items-center gap-2 hover:bg-surface-container-low transition-colors">
                            Ubah Status <span class="material-symbols-outlined text-[18px]">arrow_drop_down</span>
                        </button>
                        <div id="bulkStatusDropdown" class="hidden origin-top-right absolute right-0 mt-2 w-40 rounded-md shadow-lg bg-surface-container-lowest border border-outline-variant ring-1 ring-black ring-opacity-5 z-50">
                            <div class="py-1">
                                <button onclick="applyBulkAction('published')" class="block w-full text-left px-4 py-2 text-sm text-on-surface hover:bg-surface-container-low">Set Published</button>
                                <button onclick="applyBulkAction('draft')" class="block w-full text-left px-4 py-2 text-sm text-on-surface hover:bg-surface-container-low">Set Draft</button>
                            </div>
                        </div>
                    </div>
                    
                    <div class="relative inline-block text-left dropdown-container">
                        <button onclick="toggleDropdown('bulkMoveDropdown')" class="px-3 py-1.5 rounded bg-surface-container-lowest border border-outline-variant text-on-surface-variant font-medium flex items-center gap-2 hover:bg-surface-container-low transition-colors">
                            Pindah Folder <span class="material-symbols-outlined text-[18px]">arrow_drop_down</span>
                        </button>
                        <div id="bulkMoveDropdown" class="hidden origin-top-right absolute right-0 mt-2 w-48 rounded-md shadow-lg bg-surface-container-lowest border border-outline-variant ring-1 ring-black ring-opacity-5 z-50">
                            <div class="py-1 max-h-60 overflow-y-auto">
                                <button onclick="applyBulkMove(0)" class="block w-full text-left px-4 py-2 text-sm text-on-surface hover:bg-surface-container-low italic">-- Tanpa Folder --</button>
                                <?php foreach($projects as $proj): ?>
                                    <button onclick="applyBulkMove(<?= $proj['id'] ?>)" class="block w-full text-left px-4 py-2 text-sm text-on-surface hover:bg-surface-container-low"><?= htmlspecialchars($proj['name']) ?></button>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <?php if (empty($landing_pages)): ?>
                <div class="p-12 text-center flex flex-col items-center justify-center">
                    <span class="material-symbols-outlined text-[64px] text-outline mb-4">note_stack</span>
                    <h4 class="text-[18px] font-bold text-on-surface mb-2">Belum ada landing page</h4>
                    <p class="text-on-surface-variant mb-6">Buat landing page pertamamu sekarang!</p>
                    <a href="pages/builder.php<?= $active_project_id ? '?project_id='.$active_project_id : '' ?>" class="bg-primary text-on-primary px-6 py-2 rounded-lg font-medium hover:bg-primary-container transition-colors shadow-sm">Buat Landing Page</a>
                </div>
            <?php else: ?>
                <div class="w-full overflow-x-auto min-h-[300px]">
                    <table class="w-full text-left border-collapse">
                        <thead>
                            <tr class="bg-surface-container-low text-on-surface-variant text-[12px] font-bold uppercase tracking-wider border-b border-surface-container-highest">
                                <th class="p-4 w-12 text-center">
                                    <span class="material-symbols-outlined text-[18px] text-outline-variant">check_box_outline_blank</span>
                                </th>
                                <th class="p-4">NAMA HALAMAN & FOLDER</th>
                                <th class="p-4">STATUS</th>
                                <th class="p-4">TANGGAL</th>
                                <th class="p-4 text-right">AKSI</th>
                            </tr>
                        </thead>
                        <tbody class="text-on-surface">
                            <?php foreach ($landing_pages as $page): ?>
                                <tr class="border-b border-surface-container-highest hover:bg-surface-container-low transition-colors group">
                                    <td class="p-4 text-center">
                                        <input type="checkbox" class="page-checkbox rounded border-outline-variant text-primary-container focus:ring-primary-container w-5 h-5 bg-surface-container-lowest" value="<?= $page['id'] ?>">
                                    </td>
                                    <td class="p-4">
                                        <div class="flex items-center gap-4">
                                            <div class="w-12 h-12 bg-surface-dim rounded-lg overflow-hidden flex-shrink-0 border border-outline-variant flex items-center justify-center text-outline">
                                                <span class="material-symbols-outlined text-[24px]">web</span>
                                            </div>
                                            <div>
                                                <div class="text-[15px] font-bold text-on-background mb-1"><?= htmlspecialchars($page['title']) ?></div>
                                                <div class="flex items-center gap-1 text-on-surface-variant text-[13px]">
                                                    <?php if ($page['project_id'] && isset($folder_map[$page['project_id']])): ?>
                                                        <span class="material-symbols-outlined text-[14px]">folder</span> Folder: <?= htmlspecialchars($folder_map[$page['project_id']]) ?>
                                                    <?php else: ?>
                                                        <span class="italic opacity-70">-- Tanpa Folder --</span>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        </div>
                                    </td>
                                    <td class="p-4">
                                        <?php if ($page['status'] == 'published'): ?>
                                            <span class="inline-flex items-center px-2.5 py-1 rounded-full text-xs font-bold bg-[#e8f5e9] text-[#2e7d32]">PUBLISHED</span>
                                        <?php else: ?>
                                            <span class="inline-flex items-center px-2.5 py-1 rounded-full text-xs font-bold bg-[#fff8e1] text-[#f57f17]">DRAFT</span>
                                        <?php endif; ?>
                                        
                                        <?php if ($page['next_schedule_time']): ?>
                                            <div class="mt-1 text-[11px] text-primary flex items-center gap-1">
                                                <span class="material-symbols-outlined text-[12px]">schedule</span> 
                                                <span>=> <?= strtoupper($page['next_scheduled_status']) ?> (<?= date('d/m/y H:i', strtotime($page['next_schedule_time'])) ?>)</span>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                    <td class="p-4 text-on-surface-variant text-[13px] font-medium">
                                        <?= date('d M Y', strtotime($page['created_at'])) ?>
                                    </td>
                                    <td class="p-4 text-right">
                                        <div class="flex items-center justify-end gap-1 opacity-100 sm:opacity-0 group-hover:opacity-100 transition-opacity">
                                            <a href="pages/builder.php?id=<?= $page['id'] ?>" class="p-2 rounded-lg text-on-surface-variant hover:bg-surface-container-highest hover:text-primary transition-colors" title="Edit Builder">
                                                <span class="material-symbols-outlined text-[20px]">edit</span>
                                            </a>
                                            <a href="pages/preview.php?id=<?= $page['id'] ?>" target="_blank" class="p-2 rounded-lg text-on-surface-variant hover:bg-surface-container-highest transition-colors" title="Preview">
                                                <span class="material-symbols-outlined text-[20px]">visibility</span>
                                            </a>
                                            <div class="relative inline-block text-left dropdown-container">
                                                <button onclick="toggleDropdown('actionMenu-<?= $page['id'] ?>')" class="p-2 rounded-lg text-on-surface-variant hover:bg-surface-container-highest transition-colors" title="Aksi Lainnya">
                                                    <span class="material-symbols-outlined text-[20px]">more_vert</span>
                                                </button>
                                                <div id="actionMenu-<?= $page['id'] ?>" class="hidden origin-top-right absolute right-0 mt-2 w-48 rounded-md shadow-lg bg-surface-container-lowest border border-outline-variant ring-1 ring-black ring-opacity-5 z-50">
                                                    <div class="py-1">
                                                        <a href="<?= $page['slug'] ?>" target="_blank" class="flex items-center gap-2 px-4 py-2 text-sm text-on-surface hover:bg-surface-container-low"><span class="material-symbols-outlined text-[18px]">public</span> Buka Live URL</a>
                                                        <button onclick="openScheduleModal(<?= $page['id'] ?>, '<?= htmlspecialchars(addslashes($page['title'])) ?>')" class="w-full flex items-center gap-2 px-4 py-2 text-sm text-on-surface hover:bg-surface-container-low text-left"><span class="material-symbols-outlined text-[18px]">schedule</span> Atur Jadwal</button>
                                                        
                                                        <div class="border-t border-b border-surface-variant my-1 px-4 py-2 text-xs font-bold text-on-surface-variant">Pindah Folder:</div>
                                                        <button onclick="moveSinglePage(<?= $page['id'] ?>, null)" class="w-full flex items-center px-4 py-1.5 text-sm text-on-surface hover:bg-surface-container-low text-left italic">-- Tanpa Folder --</button>
                                                        <?php foreach($projects as $proj): ?>
                                                            <button onclick="moveSinglePage(<?= $page['id'] ?>, <?= $proj['id'] ?>)" class="w-full flex items-center px-4 py-1.5 text-sm text-on-surface hover:bg-surface-container-low text-left"><span class="material-symbols-outlined text-[16px] mr-2 text-outline-variant">folder</span> <?= htmlspecialchars($proj['name']) ?></button>
                                                        <?php endforeach; ?>

                                                        <div class="border-t border-surface-variant my-1"></div>
                                                        <button onclick="duplicatePage(<?= $page['id'] ?>, '<?= htmlspecialchars(addslashes($page['title'])) ?>')" class="w-full flex items-center gap-2 px-4 py-2 text-sm text-[#f57f17] hover:bg-surface-container-low text-left"><span class="material-symbols-outlined text-[18px]">content_copy</span> Duplikat</button>
                                                        <a href="actions/delete_page.php?id=<?= $page['id'] ?>" onclick="return confirm('Yakin ingin menghapus halaman ini secara permanen?')" class="flex items-center gap-2 px-4 py-2 text-sm text-error hover:bg-error-container/20"><span class="material-symbols-outlined text-[18px]">delete</span> Hapus</a>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
</main>

<div id="modalOverlay" class="fixed inset-0 bg-on-background/40 backdrop-blur-sm z-[100] hidden items-center justify-center p-4">
    
    <div id="addFolderModal" class="hidden bg-surface-container-lowest rounded-xl shadow-[0px_10px_15px_-3px_rgba(0,0,0,0.2)] w-full max-w-[480px] flex-col overflow-hidden border border-outline-variant/30 relative transform transition-all">
        <form method="POST">
            <input type="hidden" name="action" value="add_folder">
            <div class="flex justify-between items-center p-6 border-b border-surface-variant">
                <h2 class="text-[18px] font-bold text-on-surface flex items-center gap-2">
                    <span class="material-symbols-outlined text-[24px] text-primary">create_new_folder</span> Buat Folder Baru
                </h2>
                <button type="button" onclick="closeModal('addFolderModal')" class="text-on-surface-variant hover:text-on-surface p-1 rounded-full hover:bg-surface-container-low transition-colors">
                    <span class="material-symbols-outlined text-[20px]">close</span>
                </button>
            </div>
            <div class="p-6">
                <div class="flex flex-col gap-2">
                    <label class="text-[14px] font-semibold text-on-surface-variant">Nama Folder / Produk</label>
                    <input type="text" name="folder_name" placeholder="Contoh: Program Ramadhan" required class="w-full px-4 py-2 border border-outline-variant rounded-lg text-on-surface bg-surface-container-lowest focus:outline-none focus:border-primary focus:ring-2 focus:ring-primary/20 transition-all"/>
                </div>
            </div>
            <div class="px-6 py-4 bg-surface-container-lowest border-t border-surface-variant flex justify-end gap-3">
                <button type="button" onclick="closeModal('addFolderModal')" class="px-4 py-2 text-[14px] font-medium text-secondary hover:bg-surface-container-low rounded-lg transition-colors">Batal</button>
                <button type="submit" class="px-4 py-2 text-[14px] font-medium text-on-primary bg-primary rounded-lg hover:bg-primary-container transition-colors shadow-sm">Simpan</button>
            </div>
        </form>
    </div>

    <div id="scheduleModal" class="hidden bg-surface-container-lowest rounded-xl shadow-[0px_10px_15px_-3px_rgba(0,0,0,0.2)] w-full max-w-[480px] flex-col overflow-hidden border border-outline-variant/30 relative transform transition-all">
        <form id="scheduleForm">
            <input type="hidden" id="schedulePageId" name="page_id">
            <div class="flex justify-between items-center p-6 border-b border-surface-variant">
                <h2 class="text-[18px] font-bold text-on-surface flex items-center gap-2" id="scheduleModalTitle">
                    <span class="material-symbols-outlined text-[24px] text-primary">schedule</span> Atur Jadwal
                </h2>
                <button type="button" onclick="closeModal('scheduleModal')" class="text-on-surface-variant hover:text-on-surface p-1 rounded-full hover:bg-surface-container-low transition-colors">
                    <span class="material-symbols-outlined text-[20px]">close</span>
                </button>
            </div>
            <div class="p-6 flex flex-col gap-5">
                <div class="flex flex-col gap-2">
                    <label class="text-[14px] font-semibold text-on-surface-variant">Status Baru</label>
                    <div class="relative">
                        <select name="new_status" required class="w-full appearance-none px-4 py-2 border border-outline-variant rounded-lg text-on-surface bg-surface-container-lowest focus:outline-none focus:border-primary focus:ring-2 focus:ring-primary/20 transition-all cursor-pointer">
                            <option value="published">Published (Aktifkan Web)</option>
                            <option value="draft">Draft (Matikan Web)</option>
                        </select>
                        <span class="material-symbols-outlined absolute right-3 top-1/2 -translate-y-1/2 text-on-surface-variant pointer-events-none text-[20px]">expand_more</span>
                    </div>
                </div>
                <div class="flex flex-col gap-2">
                    <label class="text-[14px] font-semibold text-on-surface-variant">Tanggal & Waktu</label>
                    <input type="datetime-local" name="schedule_time" required class="w-full px-4 py-2 border border-outline-variant rounded-lg text-on-surface bg-surface-container-lowest focus:outline-none focus:border-primary focus:ring-2 focus:ring-primary/20 transition-all"/>
                </div>
            </div>
            <div class="px-6 py-4 bg-surface-container-lowest border-t border-surface-variant flex justify-end gap-3">
                <button type="button" onclick="closeModal('scheduleModal')" class="px-4 py-2 text-[14px] font-medium text-secondary hover:bg-surface-container-low rounded-lg transition-colors">Batal</button>
                <button type="button" id="saveScheduleBtn" class="px-4 py-2 text-[14px] font-medium text-on-primary bg-primary rounded-lg hover:bg-primary-container transition-colors shadow-sm">Simpan Jadwal</button>
            </div>
        </form>
    </div>

</div>

<script>
    // --- Modals Logic ---
    const modalOverlay = document.getElementById('modalOverlay');
    
    function openModal(modalId) {
        modalOverlay.classList.remove('hidden');
        modalOverlay.classList.add('flex');
        document.getElementById(modalId).classList.remove('hidden');
        document.getElementById(modalId).classList.add('flex');
    }

    function closeModal(modalId) {
        modalOverlay.classList.add('hidden');
        modalOverlay.classList.remove('flex');
        document.getElementById(modalId).classList.add('hidden');
        document.getElementById(modalId).classList.remove('flex');
    }

    // --- Dropdowns Logic ---
    function toggleDropdown(dropdownId) {
        const dropdown = document.getElementById(dropdownId);
        const isHidden = dropdown.classList.contains('hidden');
        
        // Hide all other dropdowns first
        document.querySelectorAll('.dropdown-container > div:not(.hidden)').forEach(el => {
            if(el.id !== dropdownId) el.classList.add('hidden');
        });

        if (isHidden) {
            dropdown.classList.remove('hidden');
        } else {
            dropdown.classList.add('hidden');
        }
    }

    // Close dropdowns if clicked outside
    document.addEventListener('click', function(event) {
        if (!event.target.closest('.dropdown-container')) {
            document.querySelectorAll('.dropdown-container > div:not(.hidden)').forEach(el => {
                el.classList.add('hidden');
            });
        }
    });

    // --- Core Logic ---
    document.addEventListener('DOMContentLoaded', function() {
        const checkboxes = document.querySelectorAll('.page-checkbox');
        const selectAll = document.getElementById('selectAll');
        const bulkActionBar = document.getElementById('bulkActionBar');
        const selectedCountEl = document.getElementById('selectedCount');

        // Checkbox Logic
        function updateBulkActionBar() {
            const checkedBoxes = Array.from(checkboxes).filter(cb => cb.checked);
            if (checkedBoxes.length > 0) {
                bulkActionBar.style.display = 'flex';
                selectedCountEl.textContent = checkedBoxes.length;
            } else {
                bulkActionBar.style.display = 'none';
                selectAll.checked = false;
            }
        }

        checkboxes.forEach(cb => {
            cb.addEventListener('change', updateBulkActionBar);
        });

        if(selectAll) {
            selectAll.addEventListener('change', function() {
                checkboxes.forEach(cb => cb.checked = this.checked);
                updateBulkActionBar();
            });
        }

        // Action: Bulk Update Status
        window.applyBulkAction = function(selectedStatus) {
            const selectedIds = Array.from(checkboxes).filter(cb => cb.checked).map(cb => cb.value);
            if (selectedIds.length === 0) return;

            const formData = new URLSearchParams();
            formData.append('action', 'bulk_update_status');
            formData.append('status', selectedStatus);
            selectedIds.forEach(id => formData.append('page_ids[]', id));

            fetch('', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: formData
            }).then(r => r.json()).then(data => {
                if (data.status === 'success') { location.reload(); } else { alert(data.message); }
            });
        };

        // Action: Bulk Move Folder
        window.applyBulkMove = function(selectedProjectId) {
            const selectedIds = Array.from(checkboxes).filter(cb => cb.checked).map(cb => cb.value);
            if (selectedIds.length === 0) return;

            const formData = new URLSearchParams();
            formData.append('action', 'bulk_move_project');
            formData.append('project_id', selectedProjectId === 0 ? '' : selectedProjectId);
            selectedIds.forEach(id => formData.append('page_ids[]', id));

            fetch('', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: formData
            }).then(r => r.json()).then(data => {
                if (data.status === 'success') { location.reload(); } else { alert(data.message); }
            });
        };

        // Action: Single Move
        window.moveSinglePage = function(pageId, projectId) {
            const formData = new URLSearchParams();
            formData.append('action', 'bulk_move_project');
            formData.append('project_id', projectId || '');
            formData.append('page_ids[]', pageId);

            fetch('', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: formData
            }).then(r => r.json()).then(data => {
                if (data.status === 'success') { location.reload(); }
            });
        };

        // Action: Schedule Setup
        window.openScheduleModal = function(pageId, title) {
            document.getElementById('schedulePageId').value = pageId;
            document.getElementById('scheduleModalTitle').innerHTML = `<span class="material-symbols-outlined text-[24px] text-primary">schedule</span> Atur Jadwal: ${title}`;
            openModal('scheduleModal');
        };

        // Action: Save Schedule
        document.getElementById('saveScheduleBtn').addEventListener('click', function() {
            const form = document.getElementById('scheduleForm');
            if(!form.checkValidity()) { form.reportValidity(); return; }

            const formData = new FormData(form);
            formData.append('action', 'schedule_toggle');

            fetch('actions/schedule_toggle.php', {
                method: 'POST',
                body: formData
            }).then(r => r.json()).then(data => {
                if (data.status === 'success') { location.reload(); } else { alert(data.message); }
            });
        });

        // Action: Duplicate
        window.duplicatePage = function(pageId, title) {
            if (!confirm(`Duplikat halaman "${title}"?`)) return;

            const formData = new FormData();
            formData.append('page_id', pageId);
            formData.append('action', 'duplicate');

            fetch('actions/duplicate_page.php', {
                method: 'POST',
                body: formData
            }).then(r => r.json()).then(data => {
                if (data.status === 'success') { location.reload(); } else { alert(data.message); }
            });
        };
    });
</script>
</body>
</html>