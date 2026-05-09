<?php
require_once '../includes/config.php';

$error = '';
$success = '';

if ($_POST) {
    $username = trim($_POST['username']);
    $email = trim($_POST['email']);
    $password = $_POST['password'];
    $confirm_password = $_POST['confirm_password'];
    
    // Validasi
    if (empty($username) || empty($email) || empty($password)) {
        $error = "Semua field harus diisi";
    } elseif ($password !== $confirm_password) {
        $error = "Password tidak cocok";
    } elseif (strlen($password) < 6) {
        $error = "Password minimal 6 karakter";
    } else {
        // Cek apakah username atau email sudah ada
        $stmt = $pdo->prepare("SELECT id FROM users WHERE username = ? OR email = ?");
        $stmt->execute([$username, $email]);
        
        if ($stmt->fetch()) {
            $error = "Username atau email sudah digunakan";
        } else {
            // Simpan user baru
            $hashed_password = password_hash($password, PASSWORD_DEFAULT);
            $stmt = $pdo->prepare("INSERT INTO users (username, email, password) VALUES (?, ?, ?)");
            
            if ($stmt->execute([$username, $email, $hashed_password])) {
                $success = "Akun berhasil dibuat! Silakan login.";
            } else {
                $error = "Terjadi kesalahan saat membuat akun";
            }
        }
    }
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8"/>
    <meta content="width=device-width, initial-scale=1.0" name="viewport"/>
    <title>Daftar - LP Builder Pro</title>
    <script src="https://cdn.tailwindcss.com?plugins=forms"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet"/>
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght@100..700" rel="stylesheet"/>
    <style>
        body { font-family: 'Inter', sans-serif; background-color: #f8fafc; }
        .material-symbols-outlined { font-variation-settings: 'FILL' 0, 'wght' 400, 'GRAD' 0, 'opsz' 24; }
    </style>
</head>
<body class="min-h-screen flex items-center justify-center p-4 py-10">

    <div class="w-full max-w-[450px]">
        <div class="flex items-center justify-center gap-3 mb-8">
            <div class="w-10 h-10 rounded-xl bg-blue-600 text-white flex items-center justify-center font-bold text-sm shadow-lg shadow-blue-600/20">LP</div>
            <h1 class="text-[24px] font-bold text-slate-900 tracking-tight">Builder Pro</h1>
        </div>

        <div class="bg-white rounded-2xl shadow-xl shadow-slate-200/50 border border-slate-100 p-8">
            
            <?php if ($success): ?>
                <div class="text-center py-6">
                    <div class="w-16 h-16 bg-green-100 text-green-600 rounded-full flex items-center justify-center mx-auto mb-4">
                        <span class="material-symbols-outlined text-[32px]">check_circle</span>
                    </div>
                    <h2 class="text-[20px] font-bold text-slate-900 mb-2">Pendaftaran Berhasil!</h2>
                    <p class="text-[14px] text-slate-500 mb-8"><?= htmlspecialchars($success) ?></p>
                    <a href="login.php" class="block w-full bg-blue-600 text-white font-bold text-[15px] py-3 rounded-xl hover:bg-blue-700 transition-all shadow-md shadow-blue-600/20">
                        Menuju Halaman Login
                    </a>
                </div>
            <?php else: ?>
                <div class="text-center mb-8">
                    <h2 class="text-[20px] font-bold text-slate-900">Buat Akun Baru</h2>
                    <p class="text-[14px] text-slate-500 mt-1">Mulai rancang landing page Anda dalam hitungan menit.</p>
                </div>

                <?php if ($error): ?>
                    <div class="bg-red-50 border border-red-200 text-red-600 px-4 py-3 rounded-xl mb-6 flex items-start gap-3 text-[13px] font-medium">
                        <span class="material-symbols-outlined text-[18px]">error</span>
                        <span><?= htmlspecialchars($error) ?></span>
                    </div>
                <?php endif; ?>
                
                <form method="POST" class="space-y-4">
                    <div>
                        <label class="block text-[13px] font-bold text-slate-700 mb-1 uppercase tracking-wider">Username</label>
                        <input type="text" name="username" class="w-full px-4 py-2.5 border border-slate-200 rounded-xl text-[14px] focus:border-blue-500 focus:ring-4 focus:ring-blue-500/10 outline-none transition-all placeholder:text-slate-400" placeholder="Pilih username unik" value="<?= htmlspecialchars($_POST['username'] ?? '') ?>" required>
                    </div>
                    
                    <div>
                        <label class="block text-[13px] font-bold text-slate-700 mb-1 uppercase tracking-wider">Email</label>
                        <input type="email" name="email" class="w-full px-4 py-2.5 border border-slate-200 rounded-xl text-[14px] focus:border-blue-500 focus:ring-4 focus:ring-blue-500/10 outline-none transition-all placeholder:text-slate-400" placeholder="nama@email.com" value="<?= htmlspecialchars($_POST['email'] ?? '') ?>" required>
                    </div>
                    
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-[13px] font-bold text-slate-700 mb-1 uppercase tracking-wider">Password</label>
                            <div class="relative">
                                <input type="password" name="password" id="password" class="w-full pl-4 pr-10 py-2.5 border border-slate-200 rounded-xl text-[14px] focus:border-blue-500 focus:ring-4 focus:ring-blue-500/10 outline-none transition-all placeholder:text-slate-400" placeholder="Min. 6 kar" required>
                                <button type="button" id="togglePassword" class="absolute right-2 top-1/2 -translate-y-1/2 text-slate-400 hover:text-slate-600 p-1 rounded-lg focus:outline-none">
                                    <span class="material-symbols-outlined text-[18px]" id="eyeIcon1">visibility</span>
                                </button>
                            </div>
                        </div>
                        
                        <div>
                            <label class="block text-[13px] font-bold text-slate-700 mb-1 uppercase tracking-wider">Ulangi Password</label>
                            <div class="relative">
                                <input type="password" name="confirm_password" id="confirm_password" class="w-full pl-4 pr-10 py-2.5 border border-slate-200 rounded-xl text-[14px] focus:border-blue-500 focus:ring-4 focus:ring-blue-500/10 outline-none transition-all placeholder:text-slate-400" placeholder="Ketik ulang" required>
                                <button type="button" id="toggleConfirmPassword" class="absolute right-2 top-1/2 -translate-y-1/2 text-slate-400 hover:text-slate-600 p-1 rounded-lg focus:outline-none">
                                    <span class="material-symbols-outlined text-[18px]" id="eyeIcon2">visibility</span>
                                </button>
                            </div>
                        </div>
                    </div>

                    <div class="pt-4">
                        <button type="submit" class="w-full bg-blue-600 text-white font-bold text-[15px] py-3 rounded-xl hover:bg-blue-700 transition-all shadow-md shadow-blue-600/20">
                            Daftar Sekarang
                        </button>
                    </div>
                </form>

                <div class="mt-6 text-center text-[14px] text-slate-500 font-medium border-t border-slate-100 pt-6">
                    Sudah punya akun? 
                    <a href="login.php" class="text-blue-600 font-bold hover:underline ml-1">Masuk di sini</a>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <script>
        // Toggle Password 1
        const togglePassword = document.querySelector('#togglePassword');
        const password = document.querySelector('#password');
        const eyeIcon1 = document.querySelector('#eyeIcon1');
        
        if (togglePassword && password) {
            togglePassword.addEventListener('click', function () {
                const type = password.getAttribute('type') === 'password' ? 'text' : 'password';
                password.setAttribute('type', type);
                eyeIcon1.textContent = type === 'password' ? 'visibility' : 'visibility_off';
            });
        }
        
        // Toggle Password 2 (Confirm)
        const toggleConfirmPassword = document.querySelector('#toggleConfirmPassword');
        const confirmPassword = document.querySelector('#confirm_password');
        const eyeIcon2 = document.querySelector('#eyeIcon2');
        
        if (toggleConfirmPassword && confirmPassword) {
            toggleConfirmPassword.addEventListener('click', function () {
                const type = confirmPassword.getAttribute('type') === 'password' ? 'text' : 'password';
                confirmPassword.setAttribute('type', type);
                eyeIcon2.textContent = type === 'password' ? 'visibility' : 'visibility_off';
            });
        }
    </script>
</body>
</html>