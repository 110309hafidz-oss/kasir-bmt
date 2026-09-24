<?php
require_once 'config/database.php';

if (isLogin()) redirect('index.php');

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($username === '' || $password === '') {
        $error = 'Username dan password wajib diisi.';
    } else {
        $stmt = $pdo->prepare("SELECT * FROM users WHERE username = ? LIMIT 1");
        $stmt->execute([$username]);
        $u = $stmt->fetch();

        if ($u && password_verify($password, $u['password'])) {
            $_SESSION['user_id']   = $u['id'];
            $_SESSION['user_nama'] = $u['nama'];
            $_SESSION['user_role'] = $u['role'];
            redirect('index.php');
        } else {
            $error = 'Username atau password salah.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Login · Kasir BMT</title>
<link rel="stylesheet" href="assets/style.css">
</head>
<body>
<div class="login-page">
  <div class="login-card">
    <div class="login-logo">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7">
        <path d="M12 2l2.6 5.9 6.4.6-4.8 4.3 1.4 6.3L12 16l-5.6 3.1 1.4-6.3L3 8.5l6.4-.6L12 2z"/>
      </svg>
    </div>
    <h2>Kasir BMT</h2>
    <p class="sub">Baitul Maal wat Tamwil</p>

    <?php if ($error): ?>
      <div class="alert alert-error" style="margin:0 0 16px"><?= e($error) ?></div>
    <?php endif; ?>

    <form method="post">
      <div class="form-group">
        <label>Username</label>
        <input type="text" name="username" placeholder="Masukkan username" autofocus required>
      </div>
      <div class="form-group">
        <label>Password</label>
        <input type="password" name="password" placeholder="Masukkan password" required>
      </div>
      <button type="submit" class="btn btn-primary btn-block" style="margin-top:8px">Masuk Sistem</button>
    </form>

    <div class="login-hint">
      Default: <strong>admin</strong> / <strong>password</strong>
    </div>
  </div>
</div>
</body>
</html>