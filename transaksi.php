<?php
require_once 'config/database.php';
requireLogin();

$dari   = $_GET['dari']   ?? date('Y-m-01');
$sampai = $_GET['sampai'] ?? date('Y-m-d');

$stmt = $pdo->prepare("SELECT t.*, u.nama AS kasir
                       FROM transaksi t
                       LEFT JOIN users u ON u.id = t.user_id
                       WHERE t.jenis = 'penjualan' AND DATE(t.tanggal) BETWEEN ? AND ?
                       ORDER BY t.id DESC");
$stmt->execute([$dari, $sampai]);
$rows = $stmt->fetchAll();

$totalOmzet = array_sum(array_column($rows, 'total'));

$pageTitle = 'Riwayat Transaksi';
$pageSubtitle = 'Daftar transaksi penjualan';

require_once 'includes/header.php';
?>

<div class="card">
  <div class="card-head">
    <h2>Filter Periode</h2>
    <span class="tag tag-green">Omzet: <?= rupiah($totalOmzet) ?></span>
  </div>
  <div class="card-body">
    <form method="get" class="filter-bar">
      <div class="form-group">
        <label>Dari Tanggal</label>
        <input type="date" name="dari" value="<?= e($dari) ?>">
      </div>
      <div class="form-group">
        <label>Sampai Tanggal</label>
        <input type="date" name="sampai" value="<?= e($sampai) ?>">
      </div>
      <button class="btn btn-primary">Tampilkan</button>
      <a href="transaksi.php" class="btn btn-outline">Reset</a>
    </form>
  </div>
</div>

<div class="card">
  <div class="card-head"><h2>Daftar Transaksi (<?= count($rows) ?>)</h2></div>
  <div class="table-wrap">
    <table>
      <thead>
        <tr>
          <th>Kode</th><th>Tanggal</th><th>Kasir</th>
          <th class="text-right">Total</th><th class="text-right">Bayar</th>
          <th class="text-right">Kembali</th><th class="text-center">Aksi</th>
        </tr>
      </thead>
      <tbody>
      <?php if (!$rows): ?>
        <tr><td colspan="7" class="empty">Tidak ada transaksi pada periode ini.</td></tr>
      <?php else: foreach ($rows as $r): ?>
        <tr>
          <td><span class="tag tag-green"><?= e($r['kode']) ?></span></td>
          <td class="text-muted"><?= tanggalIndo($r['tanggal']) ?></td>
          <td class="text-muted"><?= e($r['kasir'] ?? '-') ?></td>
          <td class="text-right strong"><?= rupiah($r['total']) ?></td>
          <td class="text-right"><?= rupiah($r['bayar']) ?></td>
          <td class="text-right"><?= rupiah($r['kembalian']) ?></td>
          <td class="text-center">
            <a href="struk.php?id=<?= (int)$r['id'] ?>" class="btn btn-outline btn-sm">Struk</a>
          </td>
        </tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php require_once 'includes/footer.php'; ?>