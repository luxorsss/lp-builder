<?php
require_once 'includes/config.php';
require_once 'includes/auth.php';

requireLogin();
$user = getCurrentUser($pdo);

// Handle Delete Pixel
if (isset($_GET['delete'])) {
    $id = (int)$_GET['delete'];
    $stmt = $pdo->prepare("DELETE FROM pixel_profiles WHERE id=? AND user_id=?");
    $stmt->execute([$id, $user['id']]);
    header("Location: pixels.php");
    exit;
}

$stmt = $pdo->prepare("SELECT * FROM pixel_profiles WHERE user_id=? ORDER BY created_at DESC");
$stmt->execute([$user['id']]);
$pixels = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8"/>
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no"/>
    <title>Pengaturan Tracking - LP Builder Pro</title>
    <script src="https://cdn.tailwindcss.com?plugins=forms"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet"/>
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght@100..700" rel="stylesheet"/>
    <style>
        body { font-family: 'Inter', sans-serif; }
        .material-symbols-outlined { font-variation-settings: 'FILL' 0, 'wght' 400, 'GRAD' 0, 'opsz' 24; }
    </style>
</head>
<body class="bg-slate-50 min-h-screen pb-20">

    <nav class="bg-white border-b border-slate-200 sticky top-0 z-40">
        <div class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8 h-16 flex items-center gap-4">
            <a href="index.php" class="text-slate-400 hover:text-slate-900 transition-colors flex items-center justify-center w-8 h-8 rounded-full hover:bg-slate-100">
                <span class="material-symbols-outlined text-[20px]">arrow_back</span>
            </a>
            <h1 class="text-[16px] font-bold text-slate-900">Manajemen Tracking</h1>
        </div>
    </nav>

    <div class="max-w-4xl mx-auto px-4 sm:px-6 mt-6 sm:mt-8">
        
        <div class="flex flex-col sm:flex-row sm:items-end justify-between gap-4 mb-6">
            <div>
                <h2 class="text-[24px] font-bold text-slate-900">Profil Tracking Tersimpan</h2>
                <p class="text-[14px] text-slate-500 mt-1">Kelola Meta Pixel, CAPI, dan Microsoft Clarity secara terpusat.</p>
            </div>
            <button type="button" onclick="openModal('pixelModal'); resetForm();" class="w-full sm:w-auto bg-blue-600 text-white font-bold text-[14px] px-5 py-2.5 rounded-xl hover:bg-blue-700 transition-all shadow-md flex items-center justify-center gap-2">
                <span class="material-symbols-outlined text-[18px]">add</span> Tambah Profil
            </button>
        </div>

        <div class="bg-white border border-slate-200 rounded-2xl shadow-sm overflow-hidden">
            <?php if(empty($pixels)): ?>
                <div class="p-10 text-center">
                    <div class="w-16 h-16 bg-slate-50 rounded-full flex items-center justify-center mx-auto mb-4 text-slate-400">
                        <span class="material-symbols-outlined text-[32px]">analytics</span>
                    </div>
                    <p class="text-[14px] text-slate-500 font-medium">Belum ada profil tracking.</p>
                </div>
            <?php else: ?>
                <?php foreach($pixels as $px): ?>
                    <div class="flex flex-col sm:flex-row sm:items-center justify-between p-4 sm:p-5 border-b border-slate-100 hover:bg-slate-50 transition-colors gap-4">
                        
                        <div class="flex items-start gap-3 sm:gap-4 flex-1 min-w-0">
                            <div class="w-10 h-10 sm:w-12 sm:h-12 rounded-xl bg-blue-50 text-blue-600 flex items-center justify-center shrink-0">
                                <span class="material-symbols-outlined text-[20px] sm:text-[24px]">troubleshoot</span>
                            </div>
                            <div class="min-w-0 flex-1">
                                <h3 class="text-[15px] sm:text-[16px] font-bold text-slate-900 truncate"><?= htmlspecialchars($px['name']) ?></h3>
                                <div class="flex flex-wrap items-center gap-2 mt-1">
                                    <span class="inline-flex items-center gap-1 text-[11px] font-semibold px-2 py-0.5 rounded-md bg-slate-100 text-slate-600 border border-slate-200">
                                        <span class="material-symbols-outlined text-[12px]">tag</span> <?= htmlspecialchars($px['pixel_id']) ?>
                                    </span>
                                    <?php if(!empty($px['capi_token'])): ?>
                                        <span class="inline-flex items-center gap-1 text-[11px] font-semibold px-2 py-0.5 rounded-md bg-green-50 text-green-700 border border-green-200">
                                            <span class="material-symbols-outlined text-[12px]">api</span> CAPI Aktif
                                        </span>
                                    <?php endif; ?>
                                    <?php if(!empty($px['clarity_project_id'])): ?>
                                        <span class="inline-flex items-center gap-1 text-[11px] font-semibold px-2 py-0.5 rounded-md bg-orange-50 text-orange-700 border border-orange-200">
                                            <span class="material-symbols-outlined text-[12px]">local_fire_department</span> Clarity
                                        </span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>

                        <div class="flex items-center gap-2 border-t sm:border-0 border-slate-100 pt-3 sm:pt-0">
                            <button type="button" onclick='editPixel(<?= json_encode($px) ?>)' class="flex-1 sm:flex-none px-4 py-2 bg-slate-100 text-slate-700 text-[13px] font-bold rounded-xl hover:bg-blue-100 hover:text-blue-700 transition-colors flex items-center justify-center gap-1">
                                <span class="material-symbols-outlined text-[16px]">edit</span> <span class="sm:hidden">Edit</span>
                            </button>
                            <a href="pixels.php?delete=<?= $px['id'] ?>" onclick="return confirm('Hapus profil ini?')" class="flex-1 sm:flex-none px-4 py-2 bg-red-50 text-red-600 text-[13px] font-bold rounded-xl hover:bg-red-100 transition-colors flex items-center justify-center gap-1">
                                <span class="material-symbols-outlined text-[16px]">delete</span> <span class="sm:hidden">Hapus</span>
                            </a>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>

    <div id="modalOverlay" class="fixed inset-0 bg-slate-900/40 backdrop-blur-sm z-[100] hidden items-end sm:items-center justify-center sm:p-4">
        
        <div id="pixelModal" class="bg-white rounded-t-2xl sm:rounded-2xl shadow-xl w-full max-w-[520px] max-h-[90vh] sm:max-h-[85vh] flex flex-col overflow-hidden transition-transform transform translate-y-full sm:translate-y-0 duration-300">
            
            <form action="actions/save_pixel.php" method="POST" class="flex flex-col flex-1 min-h-0">
                <input type="hidden" name="pixel_id_internal" id="pixel_id_internal" value="0">
                
                <div class="flex justify-between items-center p-5 sm:p-6 border-b border-slate-100 shrink-0">
                    <h2 class="text-[18px] sm:text-[20px] font-bold text-slate-900 flex items-center gap-2">
                        <span class="material-symbols-outlined text-blue-600">settings_heart</span> <span id="modalTitle">Tambah Profil</span>
                    </h2>
                    <button type="button" onclick="closeModal('pixelModal')" class="text-slate-400 hover:text-slate-900 p-1 rounded-full hover:bg-slate-100">
                        <span class="material-symbols-outlined text-[24px]">close</span>
                    </button>
                </div>
                
                <div class="p-5 sm:p-6 space-y-5 overflow-y-auto flex-1 min-h-0">
                    <div>
                        <label class="block text-[12px] font-bold text-slate-700 mb-1 uppercase tracking-wider">Nama Identitas</label>
                        <input type="text" name="name" id="px_name" placeholder="Contoh: Tracking Buku Islami" required class="w-full px-4 py-2.5 border border-slate-200 rounded-xl text-[14px] focus:border-blue-500 focus:ring-4 focus:ring-blue-500/10 outline-none">
                    </div>
                    
                    <div class="border-t border-slate-100 pt-4">
                        <div class="flex items-center gap-2 mb-3">
                            <span class="material-symbols-outlined text-[18px] text-blue-600">analytics</span>
                            <h3 class="text-[14px] font-bold text-slate-800">Meta Pixel & CAPI</h3>
                        </div>
                        <div class="space-y-4">
                            <div>
                                <label class="block text-[12px] font-bold text-slate-700 mb-1 uppercase tracking-wider">Meta Pixel ID</label>
                                <input type="text" name="pixel_id" id="px_val" placeholder="1234567890..." required class="w-full px-4 py-2.5 border border-slate-200 rounded-xl text-[14px] focus:border-blue-500 focus:ring-4 focus:ring-blue-500/10 outline-none">
                            </div>
                            <div>
                                <label class="block text-[12px] font-bold text-slate-700 mb-1 uppercase tracking-wider">CAPI Access Token</label>
                                <textarea name="capi_token" id="px_token" rows="2" placeholder="EAAB..." class="w-full px-4 py-2.5 border border-slate-200 rounded-xl text-[12px] font-mono focus:border-blue-500 focus:ring-4 focus:ring-blue-500/10 outline-none"></textarea>
                            </div>
                            <div>
                                <label class="block text-[12px] font-bold text-slate-700 mb-1 uppercase tracking-wider">CAPI Endpoint (Opsional)</label>
                                <input type="text" name="capi_endpoint" id="px_endpoint" placeholder="https://graph.facebook.com/..." class="w-full px-4 py-2.5 border border-slate-200 rounded-xl text-[14px] focus:border-blue-500 focus:ring-4 focus:ring-blue-500/10 outline-none">
                            </div>
                        </div>
                    </div>

                    <div class="border-t border-slate-100 pt-4 pb-2">
                        <div class="flex items-center gap-2 mb-3">
                            <span class="material-symbols-outlined text-[18px] text-orange-500">local_fire_department</span>
                            <h3 class="text-[14px] font-bold text-slate-800">Microsoft Clarity</h3>
                        </div>
                        <div>
                            <label class="block text-[12px] font-bold text-slate-700 mb-1 uppercase tracking-wider">Clarity Project ID</label>
                            <input type="text" name="clarity_project_id" id="px_clarity" placeholder="Contoh: u9ebbwavns" class="w-full px-4 py-2.5 border border-slate-200 rounded-xl text-[14px] font-mono focus:border-blue-500 focus:ring-4 focus:ring-blue-500/10 outline-none">
                        </div>
                    </div>
                </div>

                <div class="px-5 sm:px-6 py-4 bg-slate-50 border-t border-slate-100 flex justify-end gap-3 shrink-0">
                    <button type="button" onclick="closeModal('pixelModal')" class="flex-1 sm:flex-none px-5 py-2.5 text-[14px] font-semibold text-slate-600 hover:bg-slate-200 rounded-xl transition-colors">Batal</button>
                    <button type="submit" class="flex-1 sm:flex-none px-6 py-2.5 text-[14px] font-bold text-white bg-blue-600 rounded-xl hover:bg-blue-700 transition-all shadow-md">Simpan</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        function openModal(id) {
            const overlay = document.getElementById('modalOverlay');
            const modal = document.getElementById(id);
            overlay.classList.remove('hidden');
            overlay.classList.add('flex');
            
            // Animasi slide up di mobile
            setTimeout(() => {
                modal.classList.remove('translate-y-full');
            }, 10);
        }

        function closeModal(id) {
            const overlay = document.getElementById('modalOverlay');
            const modal = document.getElementById(id);
            
            modal.classList.add('translate-y-full');
            setTimeout(() => {
                overlay.classList.add('hidden');
                overlay.classList.remove('flex');
            }, 300);
        }

        function resetForm() {
            document.getElementById('modalTitle').innerText = "Tambah Profil";
            document.getElementById('pixel_id_internal').value = "0";
            document.getElementById('px_name').value = "";
            document.getElementById('px_val').value = "";
            document.getElementById('px_token').value = "";
            document.getElementById('px_endpoint').value = "";
            document.getElementById('px_clarity').value = "";
        }

        function editPixel(data) {
            openModal('pixelModal');
            document.getElementById('modalTitle').innerText = "Edit Profil Tracking";
            document.getElementById('pixel_id_internal').value = data.id;
            document.getElementById('px_name').value = data.name;
            document.getElementById('px_val').value = data.pixel_id;
            document.getElementById('px_token').value = data.capi_token;
            document.getElementById('px_endpoint').value = data.capi_endpoint;
            document.getElementById('px_clarity').value = data.clarity_project_id || '';
        }
        
        // Tutup modal jika area luar diklik
        document.getElementById('modalOverlay').addEventListener('click', function(e) {
            if (e.target === this) closeModal('pixelModal');
        });
    </script>
</body>
</html>