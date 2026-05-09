<?php
require_once '../includes/config.php';
require_once '../includes/auth.php';

// Redirect jika sudah login
if (isLoggedIn()) {
    header('Location: ../index.php');
    exit;
}

$error = '';

if ($_POST) {
    $username = trim($_POST['username']);
    $password = $_POST['password'];
    
    if (empty($username) || empty($password)) {
        $error = "Semua field harus diisi";
    } else {
        $stmt = $pdo->prepare("SELECT id, password FROM users WHERE username = ? OR email = ?");
        $stmt->execute([$username, $username]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($user && password_verify($password, $user['password'])) {
            $_SESSION['user_id'] = $user['id'];
            header('Location: ../index.php');
            exit;
        } else {
            $error = "Username/email atau password salah";
        }
    }
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8"/>
    <meta content="width=device-width, initial-scale=1.0" name="viewport"/>
    <title>Login - LP Builder Pro</title>
    <script src="https://cdn.tailwindcss.com?plugins=forms"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet"/>
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght@100..700" rel="stylesheet"/>
    <style>
        body { font-family: 'Inter', sans-serif; background-color: #f8fafc; }
        .material-symbols-outlined { font-variation-settings: 'FILL' 0, 'wght' 400, 'GRAD' 0, 'opsz' 24; }
    </style>
</head>
<body class="min-h-screen flex items-center justify-center p-4">

    <div class="w-full max-w-[400px]">
        <div class="flex items-center justify-center gap-3 mb-8">
            <div class="w-10 h-10 rounded-xl bg-blue-600 text-white flex items-center justify-center font-bold text-sm shadow-lg shadow-blue-600/20">LP</div>
            <h1 class="text-[24px] font-bold text-slate-900 tracking-tight">Builder Pro</h1>
        </div>

        <div class="bg-white rounded-2xl shadow-xl shadow-slate-200/50 border border-slate-100 p-8">
            <div class="text-center mb-8">
                <h2 class="text-[20px] font-bold text-slate-900">Selamat Datang Kembali</h2>
                <p class="text-[14px] text-slate-500 mt-1">Masuk ke dashboard untuk mengelola halaman Anda.</p>
            </div>

            <?php if ($error): ?>
                <div class="bg-red-50 border border-red-200 text-red-600 px-4 py-3 rounded-xl mb-6 flex items-start gap-3 text-[13px] font-medium">
                    <span class="material-symbols-outlined text-[18px]">error</span>
                    <span><?= htmlspecialchars($error) ?></span>
                </div>
            <?php endif; ?>
            
            <form method="POST" class="space-y-5">
                <div>
                    <label class="block text-[13px] font-bold text-slate-700 mb-2 uppercase tracking-wider">Username / Email</label>
                    <input type="text" name="username" class="w-full px-4 py-3 border border-slate-200 rounded-xl text-[14px] focus:border-blue-500 focus:ring-4 focus:ring-blue-500/10 outline-none transition-all placeholder:text-slate-400" placeholder="Masukkan username atau email" required>
                </div>
                
                <div>
                    <div class="flex justify-between items-center mb-2">
                        <label class="block text-[13px] font-bold text-slate-700 uppercase tracking-wider">Password</label>
                        <a href="#" class="text-[12px] font-semibold text-blue-600 hover:text-blue-700">Lupa password?</a>
                    </div>
                    <div class="relative">
                        <input type="password" name="password" id="password" class="w-full pl-4 pr-12 py-3 border border-slate-200 rounded-xl text-[14px] focus:border-blue-500 focus:ring-4 focus:ring-blue-500/10 outline-none transition-all placeholder:text-slate-400" placeholder="••••••••" required>
                        <button type="button" id="togglePassword" class="absolute right-3 top-1/2 -translate-y-1/2 text-slate-400 hover:text-slate-600 p-1 rounded-lg focus:outline-none">
                            <span class="material-symbols-outlined text-[20px]" id="eyeIcon">visibility</span>
                        </button>
                    </div>
                </div>

                <div class="pt-2">
                    <button type="submit" class="w-full bg-blue-600 text-white font-bold text-[15px] py-3 rounded-xl hover:bg-blue-700 transition-all shadow-md shadow-blue-600/20">
                        Masuk ke Dashboard
                    </button>
                </div>
            </form>

            <div class="mt-8 text-center text-[14px] text-slate-500 font-medium">
                Belum punya akun? 
                <a href="daftardulubos.php" class="text-blue-600 font-bold hover:underline ml-1">Daftar Sekarang</a>
            </div>
        </div>
    </div>

    <script>
        const togglePassword = document.querySelector('#togglePassword');
        const password = document.querySelector('#password');
        const eyeIcon = document.querySelector('#eyeIcon');
        
        togglePassword.addEventListener('click', function () {
            const type = password.getAttribute('type') === 'password' ? 'text' : 'password';
            password.setAttribute('type', type);
            eyeIcon.textContent = type === 'password' ? 'visibility' : 'visibility_off';
        });
    </script>
</body>
</html>