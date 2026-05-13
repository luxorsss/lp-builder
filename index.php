<?php
require_once 'includes/config.php';
require_once 'includes/auth.php';

requireLogin();
$user = getCurrentUser($pdo);

// Handle Hapus Halaman
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_id'])) {
    $delete_id = (int)$_POST['delete_id'];
    $stmt = $pdo->prepare("DELETE FROM landing_pages WHERE id = ? AND user_id = ?");
    $stmt->execute([$delete_id, $user['id']]);
    
    // Hapus elemen terkait
    $stmtEl = $pdo->prepare("DELETE FROM page_elements WHERE page_id = ?");
    $stmtEl->execute([$delete_id]);
    
    header("Location: index.php?msg=deleted");
    exit;
}

// Handle Ubah Status
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['toggle_status'])) {
    $page_id = (int)$_POST['page_id'];
    $new_status = $_POST['new_status'];
    $stmt = $pdo->prepare("UPDATE landing_pages SET status = ? WHERE id = ? AND user_id = ?");
    $stmt->execute([$new_status, $page_id, $user['id']]);
    header("Location: index.php?msg=status_updated");
    exit;
}

// Ambil Daftar Folder untuk Filter
$stmt_folders = $pdo->prepare("SELECT id, name FROM projects WHERE user_id = ? ORDER BY name ASC");
$stmt_folders->execute([$user['id']]);
$folders = $stmt_folders->fetchAll(PDO::FETCH_ASSOC);

// Tangkap parameter filter folder
$active_folder = isset($_GET['folder']) ? (int)$_GET['folder'] : 0;

// Ambil Daftar Halaman (dengan logika filter)
$query = "SELECT lp.*, p.name as project_name FROM landing_pages lp LEFT JOIN projects p ON lp.project_id = p.id WHERE lp.user_id = ?";
$params = [$user['id']];

if ($active_folder > 0) {
    $query .= " AND lp.project_id = ?";
    $params[] = $active_folder;
}

$query .= " ORDER BY lp.created_at DESC";

$stmt = $pdo->prepare($query);
$stmt->execute($params);
$pages = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Statistik
$total_pages = count($pages);
$published_pages = count(array_filter($pages, fn($p) => $p['status'] === 'published'));
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8"/>
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no"/>
    <title>Dashboard - LP Builder Pro</title>
    <script src="https://cdn.tailwindcss.com?plugins=forms"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet"/>
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght@100..700" rel="stylesheet"/>
    <style>
        body { font-family: 'Inter', sans-serif; }
        .material-symbols-outlined { font-variation-settings: 'FILL' 0, 'wght' 400, 'GRAD' 0, 'opsz' 24; }
        /* Animasi Dropdown */
        .dropdown-menu { transform-origin: top right; transition: all 0.2s ease; opacity: 0; transform: scale(0.95) translateY(-10px); pointer-events: none; }
        .dropdown-menu.show { opacity: 1; transform: scale(1) translateY(0); pointer-events: auto; }
    </style>
</head>
<body class="bg-slate-50 min-h-screen pb-20">

    <nav class="bg-white border-b border-slate-200 sticky top-0 z-40">
        <div class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8 h-16 flex items-center justify-between">
            <div class="flex items-center gap-3">
                <div class="w-8 h-8 rounded-lg bg-blue-600 text-white flex items-center justify-center font-bold text-xs shadow-md">LP</div>
                <h1 class="text-[18px] font-bold text-slate-900 hidden sm:block">Builder Pro</h1>
            </div>
            
            <div class="flex items-center gap-4">
                <a href="pixels.php" class="text-slate-500 hover:text-blue-600 flex items-center gap-1 transition-colors">
                    <span class="material-symbols-outlined text-[20px]">analytics</span>
                    <span class="text-[13px] font-semibold hidden sm:block">Pixel & Tracking</span>
                </a>
                <div class="h-6 w-px bg-slate-200"></div>
                <div class="flex items-center gap-2">
                    <div class="w-8 h-8 rounded-full bg-slate-100 flex items-center justify-center text-slate-600">
                        <span class="material-symbols-outlined text-[18px]">person</span>
                    </div>
                    <span class="text-[13px] font-semibold text-slate-700 hidden sm:block"><?= htmlspecialchars($user['username']) ?></span>
                </div>
                <a href="logout.php" class="text-red-500 hover:text-red-700 ml-2">
                    <span class="material-symbols-outlined text-[20px]">logout</span>
                </a>
            </div>
        </div>
    </nav>

    <div class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8 mt-6 sm:mt-8">
        
        <div class="flex flex-col sm:flex-row sm:items-end justify-between gap-4 mb-6">
            <div>
                <h2 class="text-[24px] font-bold text-slate-900">Landing Pages</h2>
                <p class="text-[14px] text-slate-500 mt-1">Kelola semua halaman promosi Anda di sini.</p>
            </div>
            
            <div class="flex gap-3">
                <a href="pages/builder.php" class="flex-1 sm:flex-none bg-blue-600 text-white font-bold text-[14px] px-5 py-2.5 rounded-xl hover:bg-blue-700 transition-all shadow-md shadow-blue-600/20 flex items-center justify-center gap-2">
                    <span class="material-symbols-outlined text-[18px]">add</span> Buat Halaman
                </a>
            </div>
        </div>

        <div class="flex overflow-x-auto pb-4 sm:pb-0 sm:grid sm:grid-cols-3 gap-4 mb-8 hide-scrollbar">
            <div class="bg-white border border-slate-200 p-5 rounded-2xl min-w-[200px] flex-1">
                <div class="text-slate-500 text-[13px] font-semibold mb-1 flex items-center gap-1"><span class="material-symbols-outlined text-[16px]">description</span> Total Halaman</div>
                <div class="text-[28px] font-bold text-slate-900"><?= $total_pages ?></div>
            </div>
            <div class="bg-white border border-slate-200 p-5 rounded-2xl min-w-[200px] flex-1">
                <div class="text-green-600 text-[13px] font-semibold mb-1 flex items-center gap-1"><span class="material-symbols-outlined text-[16px]">public</span> Publik / Aktif</div>
                <div class="text-[28px] font-bold text-slate-900"><?= $published_pages ?></div>
            </div>
            <div class="bg-white border border-slate-200 p-5 rounded-2xl min-w-[200px] flex-1">
                <div class="text-slate-500 text-[13px] font-semibold mb-1 flex items-center gap-1"><span class="material-symbols-outlined text-[16px]">draft</span> Draft</div>
                <div class="text-[28px] font-bold text-slate-900"><?= $total_pages - $published_pages ?></div>
            </div>
        </div>

        <div class="flex items-center gap-2 overflow-x-auto pb-4 mb-2 hide-scrollbar">
            <a href="index.php" class="whitespace-nowrap px-4 py-2 rounded-xl text-[13px] font-semibold transition-colors <?= $active_folder === 0 ? 'bg-slate-800 text-white shadow-md' : 'bg-white border border-slate-200 text-slate-600 hover:bg-slate-50' ?>">
                Semua Halaman
            </a>
            <?php foreach ($folders as $f): ?>
                <a href="index.php?folder=<?= $f['id'] ?>" class="whitespace-nowrap flex items-center gap-1.5 px-4 py-2 rounded-xl text-[13px] font-semibold transition-colors <?= $active_folder === $f['id'] ? 'bg-blue-600 text-white shadow-md shadow-blue-600/20' : 'bg-white border border-slate-200 text-slate-600 hover:bg-slate-50' ?>">
                    <span class="material-symbols-outlined text-[16px] <?= $active_folder === $f['id'] ? 'text-blue-200' : 'text-slate-400' ?>">folder</span>
                    <?= htmlspecialchars($f['name']) ?>
                </a>
            <?php endforeach; ?>
        </div>

        <?php if (empty($pages)): ?>
            <div class="bg-white border border-slate-200 rounded-2xl p-12 text-center">
                <div class="w-16 h-16 bg-slate-50 rounded-full flex items-center justify-center mx-auto mb-4 text-slate-400">
                    <span class="material-symbols-outlined text-[32px]">post_add</span>
                </div>
                <h3 class="text-[16px] font-bold text-slate-900 mb-1">Belum ada halaman</h3>
                <p class="text-[14px] text-slate-500 mb-6">Mulai buat landing page pertama Anda sekarang.</p>
                <a href="pages/builder.php" class="inline-flex bg-blue-600 text-white font-bold text-[14px] px-6 py-2.5 rounded-xl hover:bg-blue-700 transition-all">Buat Halaman Baru</a>
            </div>
        <?php else: ?>
            <div class="bg-white border border-slate-200 rounded-2xl shadow-sm overflow-visible">
                <?php foreach ($pages as $p): ?>
                <div class="flex flex-col sm:flex-row sm:items-center justify-between p-4 sm:p-5 border-b border-slate-100 hover:bg-slate-50 transition-colors gap-4">
                    
                    <div class="flex items-start gap-3 sm:gap-4 flex-1 min-w-0">
                        <div class="w-10 h-10 sm:w-12 sm:h-12 rounded-xl bg-blue-50 text-blue-600 flex items-center justify-center shrink-0 mt-1 sm:mt-0">
                            <span class="material-symbols-outlined text-[20px] sm:text-[24px]">web</span>
                        </div>
                        <div class="min-w-0 flex-1">
                            <div class="flex items-center gap-2">
                                <h3 class="text-[15px] sm:text-[16px] font-bold text-slate-900 truncate"><?= htmlspecialchars($p['title']) ?></h3>
                                
                                <?php if (!empty($p['project_name'])): ?>
                                    <span class="px-2 py-0.5 rounded-md bg-slate-100 text-slate-500 text-[10px] font-bold border border-slate-200 uppercase flex items-center gap-1">
                                        <span class="material-symbols-outlined text-[12px]">folder</span> <?= htmlspecialchars($p['project_name']) ?>
                                    </span>
                                <?php endif; ?>
                            </div>
                            
                            <div class="flex flex-wrap items-center gap-1.5 sm:gap-2 mt-1 text-[12px] sm:text-[13px] text-slate-500">
                                <a href="<?= $p['slug'] ?>" target="_blank" class="text-blue-600 hover:underline flex items-center gap-1 truncate max-w-[150px] sm:max-w-xs">
                                    <span class="material-symbols-outlined text-[14px]">link</span> /<?= $p['slug'] ?>
                                </a>
                                <span class="hidden sm:inline w-1 h-1 rounded-full bg-slate-300"></span>
                                <span class="hidden sm:inline flex items-center gap-1"><span class="material-symbols-outlined text-[14px]">calendar_today</span> <?= date('d M Y', strtotime($p['created_at'])) ?></span>
                            </div>
                        </div>
                    </div>

                    <div class="flex items-center justify-between sm:justify-end gap-2 sm:gap-3 w-full sm:w-auto border-t sm:border-0 border-slate-100 pt-3 sm:pt-0">
                        
                        <span class="px-2.5 py-1 rounded-lg text-[11px] sm:text-[12px] font-bold uppercase tracking-wider <?= $p['status'] == 'published' ? 'bg-green-100 text-green-700' : 'bg-slate-100 text-slate-600' ?> mr-auto sm:mr-2">
                            <?= $p['status'] == 'published' ? 'Aktif' : 'Draft' ?>
                        </span>

                        <a href="pages/builder.php?id=<?= $p['id'] ?>" class="hidden sm:flex px-4 py-2 bg-slate-100 text-slate-700 text-[13px] font-bold rounded-xl hover:bg-blue-600 hover:text-white transition-colors items-center gap-1">
                            <span class="material-symbols-outlined text-[16px]">edit</span> Edit
                        </a>
                        <a href="pages/builderhp.php?id=<?= $p['id'] ?>" class="sm:hidden px-4 py-2 bg-blue-50 text-blue-700 text-[13px] font-bold rounded-xl hover:bg-blue-100 transition-colors flex items-center gap-1">
                            <span class="material-symbols-outlined text-[16px]">edit_document</span> Edit
                        </a>

                        <div class="relative">
                            <button type="button" onclick="toggleDropdown(<?= $p['id'] ?>)" class="p-2 text-slate-500 hover:text-slate-900 hover:bg-slate-100 rounded-xl transition-colors focus:outline-none">
                                <span class="material-symbols-outlined">more_vert</span>
                            </button>
                            
                            <div id="drop-<?= $p['id'] ?>" class="dropdown-menu absolute right-0 mt-2 w-48 bg-white border border-slate-200 rounded-xl shadow-lg shadow-slate-200/50 py-1 z-50">
                                <form method="POST">
                                    <input type="hidden" name="toggle_status" value="1">
                                    <input type="hidden" name="page_id" value="<?= $p['id'] ?>">
                                    <input type="hidden" name="new_status" value="<?= $p['status'] === 'published' ? 'draft' : 'published' ?>">
                                    <button type="submit" class="w-full flex items-center gap-2 px-4 py-2.5 text-[13px] font-medium text-slate-700 hover:bg-slate-50 hover:text-blue-600 text-left">
                                        <span class="material-symbols-outlined text-[18px]">
                                            <?= $p['status'] === 'published' ? 'visibility_off' : 'publish' ?>
                                        </span> 
                                        Jadikan <?= $p['status'] === 'published' ? 'Draft' : 'Publish' ?>
                                    </button>
                                </form>
                                <a href="preview.php?id=<?= $p['id'] ?>" target="_blank" class="flex items-center gap-2 px-4 py-2.5 text-[13px] font-medium text-slate-700 hover:bg-slate-50 hover:text-blue-600">
                                    <span class="material-symbols-outlined text-[18px]">visibility</span> Lihat Pratinjau
                                </a>
                                <a href="pages/export_html.php?id=<?= $p['id'] ?>" class="flex items-center gap-2 px-4 py-2.5 text-[13px] font-medium text-slate-700 hover:bg-slate-50 hover:text-blue-600">
                                    <span class="material-symbols-outlined text-[18px]">html</span> Export HTML
                                </a>
                                <div class="h-px bg-slate-100 my-1"></div>
                                <a href="https://clarity.microsoft.com/" target="_blank" class="flex items-center gap-2 px-4 py-2.5 text-[13px] font-medium text-slate-700 hover:bg-orange-50 hover:text-orange-600">
                                    <span class="material-symbols-outlined text-[18px]">local_fire_department</span> Lihat Heatmap
                                </a>
                                <div class="h-px bg-slate-100 my-1"></div>
                                <form method="POST" onsubmit="return confirm('Yakin ingin menghapus halaman ini secara permanen?');">
                                    <input type="hidden" name="delete_id" value="<?= $p['id'] ?>">
                                    <button type="submit" class="w-full flex items-center gap-2 px-4 py-2.5 text-[13px] font-medium text-red-600 hover:bg-red-50 text-left">
                                        <span class="material-symbols-outlined text-[18px]">delete</span> Hapus Halaman
                                    </button>
                                </form>
                            </div>
                        </div>

                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <script>
        function toggleDropdown(id) {
            const dropdown = document.getElementById('drop-' + id);
            const isShowing = dropdown.classList.contains('show');
            
            // Tutup semua dropdown yang sedang terbuka
            document.querySelectorAll('.dropdown-menu').forEach(el => {
                el.classList.remove('show');
            });
            
            // Jika sebelumnya tertutup, buka dropdown yang diklik
            if (!isShowing) {
                dropdown.classList.add('show');
            }
        }

        // Tutup dropdown saat klik di luar area
        document.addEventListener('click', function(event) {
            if (!event.target.closest('.relative')) {
                document.querySelectorAll('.dropdown-menu').forEach(el => {
                    el.classList.remove('show');
                });
            }
        });
    </script>
</body>
</html>