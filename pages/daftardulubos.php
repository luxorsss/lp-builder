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
<html>
<head>
    <title>Register - Landing Page Builder</title>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
</head>
<body class="bg-light">
    <div class="container">
        <div class="row justify-content-center mt-5">
            <div class="col-md-6">
                <div class="card">
                    <div class="card-header">
                        <h4>Buat Akun Baru</h4>
                    </div>
                    <div class="card-body">
                        <?php if ($error): ?>
                            <div class="alert alert-danger"><?= $error ?></div>
                        <?php endif; ?>
                        
                        <?php if ($success): ?>
                            <div class="alert alert-success"><?= $success ?></div>
                            <a href="login.php" class="btn btn-primary">Login Sekarang</a>
                        <?php else: ?>
                            <form method="POST">
                                <div class="mb-3">
                                    <label class="form-label">Username</label>
                                    <input type="text" name="username" class="form-control" value="<?= htmlspecialchars($_POST['username'] ?? '') ?>" required>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label">Email</label>
                                    <input type="email" name="email" class="form-control" value="<?= htmlspecialchars($_POST['email'] ?? '') ?>" required>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label">Password</label>
                                    <div class="input-group">
                                        <input type="password" name="password" id="password" class="form-control" required>
                                        <button class="btn btn-outline-secondary" type="button" id="togglePassword">
                                            <i class="fas fa-eye"></i>
                                        </button>
                                    </div>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label">Konfirmasi Password</label>
                                    <div class="input-group">
                                        <input type="password" name="confirm_password" id="confirm_password" class="form-control" required>
                                        <button class="btn btn-outline-secondary" type="button" id="toggleConfirmPassword">
                                            <i class="fas fa-eye"></i>
                                        </button>
                                    </div>
                                </div>
                                <button type="submit" class="btn btn-primary">Daftar</button>
                                <a href="login.php" class="btn btn-link">Sudah punya akun?</a>
                            </form>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Toggle untuk password
        const togglePassword = document.querySelector('#togglePassword');
        const password = document.querySelector('#password');
        
        if (togglePassword && password) {
            togglePassword.addEventListener('click', function () {
                const type = password.getAttribute('type') === 'password' ? 'text' : 'password';
                password.setAttribute('type', type);
                this.innerHTML = type === 'password' ? '<i class="fas fa-eye"></i>' : '<i class="fas fa-eye-slash"></i>';
            });
        }
        
        // Toggle untuk confirm password
        const toggleConfirmPassword = document.querySelector('#toggleConfirmPassword');
        const confirmPassword = document.querySelector('#confirm_password');
        
        if (toggleConfirmPassword && confirmPassword) {
            toggleConfirmPassword.addEventListener('click', function () {
                const type = confirmPassword.getAttribute('type') === 'password' ? 'text' : 'password';
                confirmPassword.setAttribute('type', type);
                this.innerHTML = type === 'password' ? '<i class="fas fa-eye"></i>' : '<i class="fas fa-eye-slash"></i>';
            });
        }
    </script>
</body>
</html>