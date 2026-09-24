<?php
require_once 'config/database.php';
requireLogin();

$id = (int)($_GET['id'] ?? 0);

$stmt = $pdo->prepare("
    SELECT t.*, u.nama AS kasir
    FROM transaksi t
    LEFT JOIN users u ON u.id = t.user_id
    WHERE t.id = ? LIMIT 1
");
$stmt->execute([$id]);
$trx = $stmt->fetch();

if (!$trx) {
    flash('error', 'Transaksi tidak ditemukan.');
    redirect('transaksi.php');
}

$det = $pdo->prepare("
    SELECT d.*, p.nama
    FROM transaksi_detail d
    LEFT JOIN produk p ON p.id = d.produk_id
    WHERE d.transaksi_id = ?
");
$det->execute([$id]);
$items = $det->fetchAll();

$pageTitle    = 'Struk Transaksi';
$pageSubtitle = 'Bukti transaksi ' . $trx['kode'];
require_once 'includes/header.php';
?>

<!-- Tombol kembali di atas (no-print) -->
<div class="no-print" style="margin-bottom:12px">
  <button type="button" class="btn btn-outline" onclick="goBack()">⬅️ Kembali</button>
</div>

<div class="struk" id="struk">
  <div class="struk-head">
    <h3>KASIR BMT</h3>
    <small>Baitul Maal wat Tamwil</small><br>
    <small>Jl. Contoh No. 123 · Telp. 021-1234567</small>
  </div>

  <div class="struk-meta">
    <div><span>No. Transaksi</span><strong><?= e($trx['kode']) ?></strong></div>
    <div><span>Tanggal</span><strong><?= tanggalIndo($trx['tanggal']) ?></strong></div>
    <div><span>Kasir</span><strong><?= e($trx['kasir'] ?? '-') ?></strong></div>
  </div>

  <table class="struk-table">
    <thead>
      <tr><th>Item</th><th>Qty</th><th>Subtotal</th></tr>
    </thead>
    <tbody>
      <?php foreach ($items as $it): ?>
        <tr>
          <td><?= e($it['nama'] ?? '-') ?></td>
          <td><?= (int)$it['qty'] ?></td>
          <td><?= rupiah($it['subtotal']) ?></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>

  <div class="struk-total">
    <div class="grand"><span>TOTAL</span><span><?= rupiah($trx['total']) ?></span></div>
    <div><span>Bayar</span><span><?= rupiah($trx['bayar']) ?></span></div>
    <div><span>Kembalian</span><span><?= rupiah($trx['kembalian']) ?></span></div>
  </div>

  <div class="struk-foot">
    Terima kasih telah bertransaksi<br>
    <em>Barang yang sudah dibeli tidak dapat ditukar</em>
  </div>
</div>

<div class="text-center mt-16 no-print">
  <button class="btn btn-primary" onclick="window.print()">🖨️ Cetak Struk</button>
  <a href="kasir.php" class="btn btn-outline">➕ Transaksi Baru</a>
  <a href="transaksi.php" class="btn btn-outline">📋 Daftar Transaksi</a>
  <button type="button" class="btn btn-outline" onclick="goBack()">⬅️ Kembali</button>
</div>

<script>
function goBack() {
  if (document.referrer && document.referrer.includes(location.host)) {
    history.back();
  } else {
    location.href = 'index.php';
  }
}
</script>

<?php require_once 'includes/footer.php'; ?>