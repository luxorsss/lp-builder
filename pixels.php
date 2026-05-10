<?php
require_once 'includes/config.php';
require_once 'includes/auth.php';
require_once 'includes/helpers.php';

requireLogin();
$user = getCurrentUser($pdo);

// Ambil semua profil pixel milik user
$stmt = $pdo->prepare("SELECT * FROM pixel_profiles WHERE user_id = ? ORDER BY created_at DESC");
$stmt->execute([$user['id']]);
$pixels = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html class="light" lang="id">
<head>
    <meta charset="utf-8"/>
    <meta content="width=device-width, initial-scale=1.0" name="viewport"/>
    <title>Pixel Management - LP Builder Pro</title>
    <script src="https://cdn.tailwindcss.com?plugins=forms,container-queries"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet"/>
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght@100..700" rel="stylesheet"/>
    <script id="tailwind-config">
        tailwind.config = {
            theme: {
                extend: {
                    colors: {
                        "primary": "#004ac6",
                        "primary-container": "#2563eb",
                        "surface": "#faf8ff",
                        "background": "#faf8ff",
                        "surface-container-low": "#ededf9",
                    }
                }
            }
        }
    </script>
</head>
<body class="bg-background text-slate-900 font-body-md text-[14px] h-screen flex overflow-hidden">

<nav class="w-[260px] h-screen fixed left-0 top-0 bg-white shadow-sm flex flex-col p-4 gap-2 z-20 border-r border-slate-200">
    <div class="flex items-center gap-3 mb-8 px-2">
        <div class="w-8 h-8 rounded bg-blue-600 text-white flex items-center justify-center font-bold text-xs">LP</div>
        <h1 class="text-[18px] font-bold text-blue-700">LP Builder Pro</h1>
    </div>
    
    <div class="flex flex-col gap-1 flex-grow">
        <a class="flex items-center gap-3 px-4 py-3 text-slate-600 rounded-xl hover:bg-slate-100 transition-all" href="index.php">
            <span class="material-symbols-outlined">description</span>
            <span>Semua Halaman</span>
        </a>
        <a class="flex items-center gap-3 px-4 py-3 bg-blue-50 text-blue-700 rounded-xl font-semibold" href="pixels.php">
            <span class="material-symbols-outlined" style="font-variation-settings: 'FILL' 1;">analytics</span>
            <span>Pengaturan Pixel</span>
        </a>
    </div>
</nav>

<main class="ml-[260px] flex-1 flex flex-col h-screen overflow-y-auto bg-slate-50">
    <div class="p-8 max-w-5xl mx-auto w-full">
        <div class="flex justify-between items-center mb-8">
            <div>
                <h2 class="text-[28px] font-bold text-slate-900">Pixel Management</h2>
                <p class="text-slate-500">Kelola ID Meta Pixel dan Token CAPI terpusat.</p>
            </div>
            <button onclick="openModal('pixelModal')" class="bg-blue-600 text-white px-5 py-2.5 rounded-lg font-semibold flex items-center gap-2 hover:bg-blue-700 transition-all shadow-sm">
                <span class="material-symbols-outlined text-[20px]">add</span> Tambah Pixel Baru
            </button>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
            <?php if (empty($pixels)): ?>
                <div class="col-span-full bg-white border-2 border-dashed border-slate-200 rounded-2xl p-12 text-center opacity-60">
                    <span class="material-symbols-outlined text-[48px] mb-2 text-slate-400">monitoring</span>
                    <p class="font-medium">Belum ada pixel yang didaftarkan.</p>
                </div>
            <?php endif; ?>

            <?php foreach ($pixels as $px): ?>
                <div class="bg-white rounded-2xl shadow-sm border border-slate-200 overflow-hidden hover:border-blue-300 transition-all group">
                    <div class="p-6">
                        <div class="flex justify-between items-start mb-4">
                            <div class="bg-blue-100 text-blue-700 p-2 rounded-lg">
                                <span class="material-symbols-outlined">analytics</span>
                            </div>
                            <div class="flex gap-1 opacity-0 group-hover:opacity-100 transition-opacity">
                                <button onclick='editPixel(<?= json_encode($px) ?>)' class="p-2 text-slate-400 hover:text-blue-600 hover:bg-blue-50 rounded-lg"><span class="material-symbols-outlined text-[20px]">edit</span></button>
                                <a href="actions/delete_pixel.php?id=<?= $px['id'] ?>" onclick="return confirm('Hapus pixel ini?')" class="p-2 text-slate-400 hover:text-red-600 hover:bg-red-50 rounded-lg"><span class="material-symbols-outlined text-[20px]">delete</span></a>
                            </div>
                        </div>
                        <h3 class="text-[18px] font-bold text-slate-900 mb-1"><?= htmlspecialchars($px['name']) ?></h3>
                        <p class="text-[13px] font-mono text-slate-500 mb-4 tracking-tighter">ID: <?= htmlspecialchars($px['pixel_id']) ?></p>
                        
                        <div class="space-y-2 border-t border-slate-100 pt-4">
                            <div class="flex items-center gap-2 text-[12px]">
                                <span class="w-16 text-slate-400">Endpoint:</span>
                                <span class="truncate font-medium text-slate-700"><?= $px['capi_endpoint'] ? 'Aktif' : '<span class="text-slate-300">Kosong</span>' ?></span>
                            </div>
                            <div class="flex items-center gap-2 text-[12px]">
                                <span class="w-16 text-slate-400">Token:</span>
                                <span class="truncate font-medium text-slate-700"><?= $px['capi_token'] ? 'Terpasang' : '<span class="text-slate-300">Kosong</span>' ?></span>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</main>

<div id="modalOverlay" class="fixed inset-0 bg-slate-900/40 backdrop-blur-sm z-[100] hidden items-center justify-center p-4">
    <div id="pixelModal" class="bg-white rounded-2xl shadow-xl w-full max-w-[520px] overflow-hidden border border-slate-200">
        <form action="actions/save_pixel.php" method="POST">
            <input type="hidden" name="pixel_id_internal" id="pixel_id_internal" value="0">
            <div class="flex justify-between items-center p-6 border-b border-slate-100">
                <h2 class="text-[20px] font-bold text-slate-900 flex items-center gap-2">
                    <span class="material-symbols-outlined text-blue-600">settings_heart</span> <span id="modalTitle">Tambah Profil Tracking</span>
                </h2>
                <button type="button" onclick="closeModal('pixelModal')" class="text-slate-400 hover:text-slate-900 p-1 rounded-full hover:bg-slate-100 transition-colors">
                    <span class="material-symbols-outlined text-[24px]">close</span>
                </button>
            </div>
            <div class="p-6 space-y-4 max-h-[70vh] overflow-y-auto">
                <div>
                    <label class="block text-[13px] font-bold text-slate-700 mb-1 uppercase tracking-wider">Nama Identitas Tracking</label>
                    <input type="text" name="name" id="px_name" placeholder="Contoh: Tracking Bisnis Kopi" required class="w-full px-4 py-2.5 border border-slate-200 rounded-xl focus:border-blue-500 focus:ring-4 focus:ring-blue-500/10 outline-none transition-all">
                </div>
                
                <div class="border-t border-slate-100 pt-4">
                    <div class="flex items-center gap-2 mb-3">
                        <span class="material-symbols-outlined text-[18px] text-blue-600">analytics</span>
                        <h3 class="text-[14px] font-bold text-slate-800">Pengaturan Meta Pixel</h3>
                    </div>
                    <div class="space-y-4">
                        <div>
                            <label class="block text-[13px] font-bold text-slate-700 mb-1 uppercase tracking-wider">Meta Pixel ID</label>
                            <input type="text" name="pixel_id" id="px_val" placeholder="1234567890..." required class="w-full px-4 py-2.5 border border-slate-200 rounded-xl focus:border-blue-500 focus:ring-4 focus:ring-blue-500/10 outline-none transition-all">
                        </div>
                        <div>
                            <label class="block text-[13px] font-bold text-slate-700 mb-1 uppercase tracking-wider">CAPI Access Token</label>
                            <textarea name="capi_token" id="px_token" rows="2" placeholder="EAAB..." class="w-full px-4 py-2.5 border border-slate-200 rounded-xl focus:border-blue-500 focus:ring-4 focus:ring-blue-500/10 outline-none transition-all font-mono text-xs"></textarea>
                        </div>
                        <div>
                            <label class="block text-[13px] font-bold text-slate-700 mb-1 uppercase tracking-wider">CAPI Endpoint (Opsional)</label>
                            <input type="text" name="capi_endpoint" id="px_endpoint" placeholder="https://graph.facebook.com/..." class="w-full px-4 py-2.5 border border-slate-200 rounded-xl focus:border-blue-500 focus:ring-4 focus:ring-blue-500/10 outline-none transition-all">
                        </div>
                    </div>
                </div>

                <div class="border-t border-slate-100 pt-4">
                    <div class="flex items-center gap-2 mb-3">
                        <span class="material-symbols-outlined text-[18px] text-orange-500">local_fire_department</span>
                        <h3 class="text-[14px] font-bold text-slate-800">Microsoft Clarity (Heatmap)</h3>
                    </div>
                    <div>
                        <label class="block text-[13px] font-bold text-slate-700 mb-1 uppercase tracking-wider">Clarity Project ID</label>
                        <input type="text" name="clarity_project_id" id="px_clarity" placeholder="Contoh: u9ebbwavns" class="w-full px-4 py-2.5 border border-slate-200 rounded-xl focus:border-blue-500 focus:ring-4 focus:ring-blue-500/10 outline-none transition-all font-mono">
                    </div>
                </div>
            </div>
            <div class="px-6 py-5 bg-slate-50 border-t border-slate-100 flex justify-end gap-3">
                <button type="button" onclick="closeModal('pixelModal')" class="px-5 py-2.5 text-[14px] font-semibold text-slate-600 hover:bg-slate-200 rounded-xl transition-colors">Batal</button>
                <button type="submit" class="px-6 py-2.5 text-[14px] font-bold text-white bg-blue-600 rounded-xl hover:bg-blue-700 transition-all shadow-md">Simpan Konfigurasi</button>
            </div>
        </form>
    </div>
</div>

<script>
    const modalOverlay = document.getElementById('modalOverlay');
    function openModal(id) {
        modalOverlay.classList.remove('hidden'); modalOverlay.classList.add('flex');
        document.getElementById(id).classList.remove('hidden');
    }
    function closeModal(id) {
        modalOverlay.classList.add('hidden'); modalOverlay.classList.remove('flex');
        document.getElementById(id).classList.add('hidden');
        // Reset form
        document.getElementById('pixel_id_internal').value = "0";
        document.getElementById('modalTitle').innerText = "Tambah Pixel Baru";
        document.querySelector('#pixelModal form').reset();
    }
    function editPixel(data) {
        openModal('pixelModal');
        document.getElementById('modalTitle').innerText = "Edit Profil Tracking";
        document.getElementById('pixel_id_internal').value = data.id;
        document.getElementById('px_name').value = data.name;
        document.getElementById('px_val').value = data.pixel_id;
        document.getElementById('px_token').value = data.capi_token;
        document.getElementById('px_endpoint').value = data.capi_endpoint;
        document.getElementById('px_clarity').value = data.clarity_project_id || ''; // Load ID Clarity
    }
</script>
</body>
</html>