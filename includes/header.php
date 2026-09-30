<?php
require_once __DIR__ . '/../config/database.php';
requireLogin();

$currentPage = basename($_SERVER['PHP_SELF']);
$u = user();

$menu = [
    ['index.php',      'Dashboard',  'M3 12l9-9 9 9M5 10v10h5v-6h4v6h5V10'],
    ['kasir.php',      'Kasir',      'M3 3h2l2 12h12l2-9H6M9 21a1 1 0 100-2 1 1 0 000 2zm8 0a1 1 0 100-2 1 1 0 000 2z'],
    ['transaksi.php',  'Transaksi',  'M4 4h16v4H4zM4 12h16v8H4z'],
    ['pengeluaran.php', 'Pengeluaran', 'M12 2v20M17 5H9.5a3.5 3.5 0 000 7h5a3.5 3.5 0 010 7H6'],
    ['produk.php',     'Produk',     'M21 16V8l-9-5-9 5v8l9 5 9-5zM3.3 7L12 12l8.7-5M12 22V12'],
    ['laporan.php',    'Laporan',    'M4 4h16v16H4zM8 12h8M8 8h8M8 16h5'],

];
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= isset($pageTitle) ? e($pageTitle) . ' · ' : '' ?>Kasir BMT</title>
<link rel="stylesheet" href="assets/style.css">
</head>
<body>
<div class="app">
  <aside class="sidebar" id="sidebar">

    <!-- ============================================ -->
    <!-- BRAND / LOGO                                 -->
    <!-- ============================================ -->
    <div class="brand">
      <div class="brand-logo-wrap">
        <img src="assets/loge.png" alt="Logo Asfie Mart" class="brand-logo">
      </div>
      <div class="brand-text">
        <strong>Asvie Mart</strong>
        <span class="brand-sub">Baitul Maal wat Tamwil</span>
      </div>
    </div>

    <nav class="nav">
      <?php foreach ($menu as $m): ?>
        <a href="<?= $m[0] ?>" class="nav-item <?= $currentPage === $m[0] ? 'active' : '' ?>">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
            <path d="<?= $m[2] ?>"/>
          </svg>
          <span><?= $m[1] ?></span>
        </a>
      <?php endforeach; ?>
    </nav>

    <div class="sidebar-foot">
      <div class="user-chip">
        <div class="avatar"><?= strtoupper(substr($u['nama'], 0, 1)) ?></div>
        <div>
          <strong><?= e($u['nama']) ?></strong>
          <small><?= e(ucfirst($u['role'])) ?></small>
        </div>
      </div>
      <a href="logout.php" class="btn-logout">Keluar</a>
    </div>
  </aside>

  <main class="main">
    <header class="topbar">
      <button class="sidebar-toggle" id="sidebarToggle" aria-label="Toggle Sidebar" type="button">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
          <path d="M3 6h18M3 12h18M3 18h18"/>
        </svg>
      </button>
      <div class="topbar-info">
        <h1><?= isset($pageTitle) ? e($pageTitle) : 'Dashboard' ?></h1>
        <p class="subtitle"><?= isset($pageSubtitle) ? e($pageSubtitle) : 'Sistem Informasi Kasir BMT' ?></p>
      </div>
      <div class="topbar-right">
        <span class="badge-date"><?= tanggalIndo(date('Y-m-d H:i:s')) ?></span>
      </div>
    </header>

    <?php if ($msg = flash('success')): ?>
      <div class="alert alert-success"><?= e($msg) ?></div>
    <?php endif; ?>
    <?php if ($msg = flash('error')): ?>
      <div class="alert alert-error"><?= e($msg) ?></div>
    <?php endif; ?>

    <div class="content">

    <!-- Overlay untuk menutup sidebar saat klik di luar (mobile) -->
<div class="sidebar-overlay" id="sidebarOverlay"></div>

<script>
(function () {
  const toggle  = document.getElementById('sidebarToggle');
  const sidebar = document.getElementById('sidebar');
  const overlay = document.getElementById('sidebarOverlay');
  if (!toggle || !sidebar || !overlay) return;

  function openSidebar() {
    sidebar.classList.add('open');
    overlay.classList.add('show');
    document.body.style.overflow = 'hidden';
  }
  function closeSidebar() {
    sidebar.classList.remove('open');
    overlay.classList.remove('show');
    document.body.style.overflow = '';
  }
  function toggleSidebar() {
    sidebar.classList.contains('open') ? closeSidebar() : openSidebar();
  }

  toggle.addEventListener('click', toggleSidebar);
  overlay.addEventListener('click', closeSidebar);

  window.addEventListener('resize', () => {
    if (window.innerWidth > 768) closeSidebar();
  });

  document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') closeSidebar();
  });
})();
</script>
    </body>