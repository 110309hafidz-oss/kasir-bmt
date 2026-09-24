<?php
require_once __DIR__ . '/../config/database.php';
requireLogin();

$currentPage = basename($_SERVER['PHP_SELF']);
$u = user();

$menu = [
    ['index.php',      'Dashboard',  'M3 12l9-9 9 9M5 10v10h5v-6h4v6h5V10'],
    ['kasir.php',      'Kasir',      'M3 3h2l2 12h12l2-9H6M9 21a1 1 0 100-2 1 1 0 000 2zm8 0a1 1 0 100-2 1 1 0 000 2z'],
    ['transaksi.php',  'Transaksi',  'M4 4h16v4H4zM4 12h16v8H4z'],
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
  <aside class="sidebar">
    
    <!-- ============================================ -->
    <!-- BRAND / LOGO (SUDAH DIPERBAIKI)              -->
    <!-- ============================================ -->
    <div class="brand">
      <div class="brand-mark">
        <img src="assets/logo.png" alt="Logo" class="brand-logo">
      </div>
      <div class="brand-text">
        <strong>Asfie Mart</strong>
        <small>Baitul Maal wat Tamwil</small>
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
      <div>
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