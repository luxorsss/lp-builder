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

// Ambil daftar landing page milik user
$stmt = $pdo->prepare("SELECT * FROM landing_pages WHERE user_id = ? ORDER BY created_at DESC");
$stmt->execute([$user['id']]);
$landing_pages = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html>
<head>
    <title>Dashboard - Landing Page Builder</title>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css" rel="stylesheet">
    <style>
        :root {
            --primary-gradient: linear-gradient(135deg, #4361ee 0%, #3a0ca3 100%);
            --secondary-gradient: linear-gradient(135deg, #4cc9f0 0%, #4895ef 100%);
            --success-gradient: linear-gradient(135deg, #4ade80 0%, #10b981 100%);
            --card-shadow: 0 10px 30px rgba(0, 0, 0, 0.1);
            --hover-shadow: 0 15px 40px rgba(67, 97, 238, 0.2);
        }
        
        body {
            background: linear-gradient(135deg, #f5f7fa 0%, #e4edf5 100%);
            min-height: 100vh;
            font-family: 'Segoe UI', Roboto, 'Helvetica Neue', sans-serif;
        }
        
        .navbar {
            background: var(--primary-gradient) !important;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
        }
        
        .navbar-brand {
            font-weight: 700;
            font-size: 1.5rem;
        }
        
        .welcome-card {
            background: white;
            border-radius: 16px;
            box-shadow: var(--card-shadow);
            border: none;
            margin-bottom: 2rem;
            overflow: hidden;
        }
        
        .welcome-card .card-body {
            padding: 2rem;
        }
        
        .welcome-card h1 {
            font-weight: 800;
            background: var(--primary-gradient);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }
        
        .stats-card {
            border-radius: 16px;
            border: none;
            box-shadow: var(--card-shadow);
            transition: all 0.3s ease;
        }
        
        .stats-card:hover {
            transform: translateY(-5px);
            box-shadow: var(--hover-shadow);
        }
        
        .stats-card .card-body {
            padding: 1.5rem;
        }
        
        .stats-icon {
            width: 60px;
            height: 60px;
            border-radius: 16px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
            margin-bottom: 1rem;
        }
        
        .lp-card {
            border-radius: 16px;
            border: none;
            box-shadow: var(--card-shadow);
            transition: all 0.3s ease;
            height: 100%;
            position: relative;
        }
        
        .lp-card:hover {
            transform: translateY(-8px);
            box-shadow: var(--hover-shadow);
        }
        
        .lp-card .card-body {
            padding: 1.5rem;
        }
        
        .lp-card .card-footer {
            background: #f8f9fa;
            border-top: 1px solid #e9ecef;
            padding: 1rem 1.5rem;
        }
        
        .lp-card h5 {
            font-weight: 700;
            color: #1e293b;
        }
        
        .btn-primary {
            background: var(--primary-gradient);
            border: none;
            padding: 10px 20px;
            font-weight: 600;
            border-radius: 12px;
            box-shadow: 0 4px 15px rgba(67, 97, 238, 0.3);
        }
        
        .btn-primary:hover {
            background: linear-gradient(135deg, #3a56e4 0%, #320a8c 100%);
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(67, 97, 238, 0.4);
        }
        
        .btn-outline-primary {
            border-radius: 10px;
            font-weight: 500;
            transition: all 0.2s ease;
        }
        
        .btn-outline-primary:hover {
            background: var(--primary-gradient);
            border-color: #4361ee;
            color: white;
        }
        
        .btn-outline-success {
            border-radius: 10px;
            font-weight: 500;
            transition: all 0.2s ease;
        }
        
        .btn-outline-success:hover {
            background: var(--success-gradient);
            border-color: #10b981;
            color: white;
        }
        
        .btn-outline-info {
            border-radius: 10px;
            font-weight: 500;
            transition: all 0.2s ease;
        }
        
        .btn-outline-info:hover {
            background: var(--secondary-gradient);
            border-color: #4895ef;
            color: white;
        }
        
        .btn-outline-danger {
            border-radius: 10px;
            font-weight: 500;
            transition: all 0.2s ease;
        }
        
        .btn-outline-danger:hover {
            background: linear-gradient(135deg, #f43f5e 0%, #e11d48 100%);
            border-color: #f43f5e;
            color: white;
        }
        
        .badge-success {
            background: var(--success-gradient);
        }
        
        .badge-secondary {
            background: linear-gradient(135deg, #94a3b8 0%, #64748b 100%);
        }
        
        .empty-state {
            background: white;
            border-radius: 16px;
            box-shadow: var(--card-shadow);
            padding: 4rem 2rem;
            text-align: center;
            margin-top: 2rem;
        }
        
        .empty-state i {
            font-size: 4rem;
            color: #cbd5e1;
            margin-bottom: 2rem;
        }
        
        .empty-state h4 {
            font-weight: 700;
            color: #1e293b;
            margin-bottom: 1rem;
        }
        
        .btn-group .btn {
            border-radius: 0 !important;
        }
        
        .btn-group .btn:first-child {
            border-radius: 10px 0 0 10px !important;
        }
        
        .btn-group .btn:last-child {
            border-radius: 0 10px 10px 0 !important;
        }
        
        .stats-number {
            font-size: 2.5rem;
            font-weight: 800;
            margin-bottom: 0.5rem;
        }
        
        .stats-label {
            font-size: 1.1rem;
            color: #64748b;
            font-weight: 500;
        }
        
        .user-info {
            background: rgba(255, 255, 255, 0.2);
            padding: 8px 16px;
            border-radius: 50px;
            backdrop-filter: blur(10px);
        }

        .bulk-action-bar {
            background: white;
            padding: 1rem;
            border-radius: 12px;
            box-shadow: var(--card-shadow);
            margin-bottom: 1.5rem;
            display: none;
        }

        .bulk-action-bar.show {
            display: block;
        }

        .bulk-action-bar .form-check {
            margin-right: 1rem;
        }

        @media (max-width: 768px) {
            .stats-number {
                font-size: 2rem;
            }
            
            .lp-card {
                margin-bottom: 1.5rem;
            }

            .bulk-action-bar .row > div {
                margin-bottom: 1rem;
            }
        }
    </style>
</head>
<body>
    <!-- Navbar -->
    <nav class="navbar navbar-expand-lg navbar-dark">
        <div class="container py-2">
            <a class="navbar-brand" href="#">
                <i class="fas fa-cube me-2"></i>
                LandingPage Builder
            </a>
            <div class="d-flex align-items-center">
                <div class="user-info me-3">
                    <i class="fas fa-user me-2"></i>
                    <span><?= htmlspecialchars($user['username']) ?></span>
                </div>
                <a href="actions/logout.php" class="btn btn-outline-light">
                    <i class="fas fa-sign-out-alt me-1"></i>
                    <span class="d-none d-md-inline">Logout</span>
                </a>
            </div>
        </div>
    </nav>

    <div class="container mt-4">
        <!-- Welcome Card -->
        <div class="welcome-card">
            <div class="card-body">
                <h1>Selamat Datang, <?= htmlspecialchars($user['username']) ?>!</h1>
                <p class="text-muted mb-0">Kelola dan buat landing page Anda dengan mudah menggunakan builder kami.</p>
            </div>
        </div>
        
        <!-- Stats Cards -->
        <div class="row mb-4">
            <div class="col-md-4 mb-4">
                <div class="stats-card bg-white">
                    <div class="card-body text-center">
                        <div class="stats-icon bg-primary text-white mx-auto">
                            <i class="fas fa-file-alt"></i>
                        </div>
                        <div class="stats-number"><?= count($landing_pages) ?></div>
                        <div class="stats-label">Total Landing Page</div>
                    </div>
                </div>
            </div>
            <div class="col-md-4 mb-4">
                <div class="stats-card bg-white">
                    <div class="card-body text-center">
                        <div class="stats-icon bg-success text-white mx-auto">
                            <i class="fas fa-check-circle"></i>
                        </div>
                        <div class="stats-number">
                            <?= count(array_filter($landing_pages, function($page) { return $page['status'] == 'published'; })) ?>
                        </div>
                        <div class="stats-label">Published</div>
                    </div>
                </div>
            </div>
            <div class="col-md-4 mb-4">
                <div class="stats-card bg-white">
                    <div class="card-body text-center">
                        <div class="stats-icon bg-info text-white mx-auto">
                            <i class="fas fa-edit"></i>
                        </div>
                        <div class="stats-number">
                            <?= count(array_filter($landing_pages, function($page) { return $page['status'] == 'draft'; })) ?>
                        </div>
                        <div class="stats-label">Draft</div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Header -->
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h2><i class="fas fa-th-large me-2"></i>Landing Page Saya</h2>
            <a href="pages/builder.php" class="btn btn-primary">
                <i class="fas fa-plus me-1"></i> Buat Landing Page Baru
            </a>
        </div>

        <!-- Alert -->
        <?php if (isset($_SESSION['message'])): ?>
            <div class="alert alert-success alert-dismissible fade show rounded-3">
                <i class="fas fa-check-circle me-2"></i>
                <?= $_SESSION['message'] ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php unset($_SESSION['message']); ?>
        <?php endif; ?>

        <!-- Bulk Action Bar -->
        <div class="bulk-action-bar" id="bulkActionBar">
            <div class="row align-items-center">
                <div class="col-md-6">
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" id="selectAll">
                        <label class="form-check-label" for="selectAll">
                            Pilih Semua
                        </label>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="input-group">
                        <select class="form-select" id="bulkActionSelect">
                            <option value="">Pilih Aksi...</option>
                            <option value="published">Ubah ke Published</option>
                            <option value="draft">Ubah ke Draft</option>
                        </select>
                        <button class="btn btn-primary" id="applyBulkAction">Terapkan</button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Daftar Landing Page -->
        <?php if (empty($landing_pages)): ?>
            <div class="empty-state">
                <i class="fas fa-file-alt"></i>
                <h4>Belum ada landing page</h4>
                <p class="text-muted mb-4">Buat landing page pertamamu sekarang!</p>
                <a href="pages/builder.php" class="btn btn-primary btn-lg">
                    <i class="fas fa-plus me-2"></i>Buat Landing Page
                </a>
            </div>
        <?php else: ?>
            <div class="row">
                <?php foreach ($landing_pages as $page): ?>
                    <div class="col-md-6 col-lg-4 mb-4">
                        <div class="lp-card">
                            <div class="card-body">
                                <div class="form-check mb-3">
                                    <input class="form-check-input page-checkbox" type="checkbox" value="<?= $page['id'] ?>">
                                </div>
                                <h5 class="card-title"><?= htmlspecialchars($page['title']) ?></h5>
                                <p class="card-text">
                                    <small class="text-muted">
                                        <i class="far fa-calendar me-1"></i>
                                        Dibuat: <?= date('d M Y', strtotime($page['created_at'])) ?><br>
                                        <i class="fas fa-info-circle me-1"></i>
                                        Status: 
                                        <?php if ($page['status'] == 'published'): ?>
                                            <span class="badge badge-success">Published</span>
                                        <?php else: ?>
                                            <span class="badge badge-secondary">Draft</span>
                                        <?php endif; ?>
                                    </small>
                                </p>
                                <?php if ($page['next_schedule_time']): ?>
                                    <div class="mt-2">
                                        <small class="text-info">
                                            <i class="fas fa-clock me-1"></i>
                                            Akan jadi <strong><?= $page['next_scheduled_status'] ?></strong> pada: 
                                            <?= date('d M Y H:i', strtotime($page['next_schedule_time'])) ?>
                                        </small>
                                    </div>
                                <?php endif; ?>
                            </div>
                            <div class="card-footer">
                                <div class="btn-group w-100">
                                    <a href="pages/builder.php?id=<?= $page['id'] ?>" class="btn btn-sm btn-outline-primary" title="Edit">
                                        <i class="fas fa-edit me-1"></i> Edit
                                    </a>
                                    <a href="pages/preview.php?id=<?= $page['id'] ?>" class="btn btn-sm btn-outline-success" title="Preview">
                                        <i class="fas fa-eye me-1"></i> Preview
                                    </a>
                                    <a href="<?= $page['slug'] ?>" class="btn btn-sm btn-outline-info" target="_blank" title="Lihat">
                                        <i class="fas fa-external-link-alt me-1"></i> Lihat
                                    </a>
                                    <a href="#" class="btn btn-sm btn-outline-info" title="Atur Jadwal" 
                                       onclick="openScheduleModal(<?= $page['id'] ?>, '<?= htmlspecialchars($page['title']) ?>')">
                                        <i class="fas fa-clock me-1"></i> Jadwal
                                    </a>
									<a href="#" class="btn btn-sm btn-outline-warning" title="Duplikat" 
									   onclick="duplicatePage(<?= $page['id'] ?>, '<?= htmlspecialchars($page['title']) ?>')">
										<i class="fas fa-copy me-1"></i> Duplikat
									</a>
                                    <a href="actions/delete_page.php?id=<?= $page['id'] ?>" class="btn btn-sm btn-outline-danger" 
                                       onclick="return confirm('Yakin ingin menghapus landing page ini?')" title="Hapus">
                                        <i class="fas fa-trash"></i>
                                    </a>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <!-- Modal Jadwal Toggle -->
    <div class="modal fade" id="scheduleModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Atur Jadwal Toggle</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <form id="scheduleForm">
                        <input type="hidden" id="schedulePageId" name="page_id">
                        <div class="mb-3">
                            <label class="form-label">Status Baru</label>
                            <select class="form-select" name="new_status" required>
                                <option value="published">Published</option>
                                <option value="draft">Draft</option>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Tanggal & Waktu</label>
                            <input type="datetime-local" class="form-control" name="schedule_time" required>
                        </div>
                    </form>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                    <button type="button" class="btn btn-primary" id="saveScheduleBtn">Simpan Jadwal</button>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const checkboxes = document.querySelectorAll('.page-checkbox');
            const selectAll = document.getElementById('selectAll');
            const bulkActionBar = document.getElementById('bulkActionBar');
            const bulkActionSelect = document.getElementById('bulkActionSelect');
            const applyBulkAction = document.getElementById('applyBulkAction');
            let currentSchedulePageId = null;

            function updateBulkActionBar() {
                const anyChecked = Array.from(checkboxes).some(cb => cb.checked);
                bulkActionBar.classList.toggle('show', anyChecked);
            }

            checkboxes.forEach(cb => {
                cb.addEventListener('change', updateBulkActionBar);
            });

            selectAll.addEventListener('change', function() {
                checkboxes.forEach(cb => cb.checked = this.checked);
                updateBulkActionBar();
            });

            applyBulkAction.addEventListener('click', function() {
                const selectedStatus = bulkActionSelect.value;
                if (!selectedStatus) {
                    alert('Pilih aksi terlebih dahulu!');
                    return;
                }

                const selectedIds = Array.from(checkboxes)
                    .filter(cb => cb.checked)
                    .map(cb => cb.value);

                if (selectedIds.length === 0) {
                    alert('Pilih minimal satu landing page!');
                    return;
                }

                const formData = new URLSearchParams();
                formData.append('action', 'bulk_update_status');
                formData.append('status', selectedStatus);
                selectedIds.forEach(id => {
                    formData.append('page_ids[]', id);
                });

                fetch('', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: formData
                })
                .then(response => response.json())
                .then(data => {
                    if (data.status === 'success') {
                        const alertDiv = document.createElement('div');
                        alertDiv.className = 'alert alert-success alert-dismissible fade show rounded-3 fixed-top mt-5 mx-auto w-50';
                        alertDiv.style.zIndex = 1050;
                        alertDiv.innerHTML = `
                            <i class="fas fa-check-circle me-2"></i>
                            ${data.message}
                            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                        `;
                        document.body.appendChild(alertDiv);

                        setTimeout(() => {
                            const bsAlert = new bootstrap.Alert(alertDiv);
                            bsAlert.close();
                        }, 3000);

                        checkboxes.forEach(cb => cb.checked = false);
                        selectAll.checked = false;
                        bulkActionSelect.value = '';
                        updateBulkActionBar();

                        setTimeout(() => location.reload(), 1500);
                    } else {
                        alert('Gagal: ' + data.message);
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('Terjadi kesalahan saat memproses permintaan.');
                });
            });

            // Fungsi untuk membuka modal jadwal
            window.openScheduleModal = function(pageId, title) {
                currentSchedulePageId = pageId;
                document.getElementById('schedulePageId').value = pageId;
                document.getElementById('scheduleModal').querySelector('.modal-title').textContent = `Atur Jadwal: ${title}`;
                new bootstrap.Modal(document.getElementById('scheduleModal')).show();
            };

            // Simpan jadwal
            document.getElementById('saveScheduleBtn').addEventListener('click', function() {
                const formData = new FormData(document.getElementById('scheduleForm'));
                formData.append('action', 'schedule_toggle');

                fetch('actions/schedule_toggle.php', {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.json())
                .then(data => {
                    if (data.status === 'success') {
                        alert(data.message);
                        location.reload();
                    } else {
                        alert('Gagal: ' + data.message);
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('Terjadi kesalahan.');
                });
            });
        });
		function duplicatePage(pageId, title) {
			if (!confirm(`Duplikat "${title}"?`)) return;

			const formData = new FormData();
			formData.append('page_id', pageId);
			formData.append('action', 'duplicate');

			fetch('actions/duplicate_page.php', {
				method: 'POST',
				body: formData
			})
			.then(response => response.json())
			.then(data => {
				if (data.status === 'success') {
					alert(data.message);
					location.reload();
				} else {
					alert('Gagal: ' + data.message);
				}
			})
			.catch(error => {
				console.error('Error:', error);
				alert('Terjadi kesalahan.');
			});
		}
    </script>
</body>
</html>