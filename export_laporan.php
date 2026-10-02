<?php
require_once 'config/database.php';
requireLogin();

$dari   = $_GET['dari']   ?? date('Y-m-01');
$sampai = $_GET['sampai'] ?? date('Y-m-d');

/* ===== REKAP ===== */
$stmt = $pdo->prepare("
    SELECT COUNT(*) AS jml, COALESCE(SUM(total),0) AS omzet,
           COALESCE(AVG(total),0) AS rata
    FROM transaksi
    WHERE jenis='penjualan' AND DATE(tanggal) BETWEEN ? AND ?
");
$stmt->execute([$dari, $sampai]);
$rekapJual = $stmt->fetch();

$stmt = $pdo->prepare("
    SELECT COALESCE(SUM((d.harga - p.harga_beli) * d.qty),0) AS laba
    FROM transaksi_detail d
    JOIN produk p ON p.id = d.produk_id
    JOIN transaksi t ON t.id = d.transaksi_id
    WHERE t.jenis='penjualan' AND DATE(t.tanggal) BETWEEN ? AND ?
");
$stmt->execute([$dari, $sampai]);
$labaKotor = (float)$stmt->fetchColumn();

$stmt = $pdo->prepare("
    SELECT p.id, p.kode, p.nama, p.kategori,
           SUM(d.qty) AS qty, SUM(d.subtotal) AS omzet,
           SUM((d.harga - p.harga_beli) * d.qty) AS laba
    FROM transaksi_detail d
    JOIN produk p ON p.id = d.produk_id
    JOIN transaksi t ON t.id = d.transaksi_id
    WHERE t.jenis='penjualan' AND DATE(t.tanggal) BETWEEN ? AND ?
    GROUP BY p.id, p.kode, p.nama, p.kategori
    ORDER BY omzet DESC
");
$stmt->execute([$dari, $sampai]);
$detailProduk = $stmt->fetchAll();

$stmt = $pdo->prepare("
    SELECT DATE(tanggal) AS tgl, COUNT(*) AS jml, SUM(total) AS omzet
    FROM transaksi
    WHERE jenis='penjualan' AND DATE(tanggal) BETWEEN ? AND ?
    GROUP BY DATE(tanggal)
    ORDER BY tgl DESC
");
$stmt->execute([$dari, $sampai]);
$harian = $stmt->fetchAll();

$stmt = $pdo->prepare("
    SELECT u.nama AS kasir, COUNT(t.id) AS jml, COALESCE(SUM(t.total),0) AS omzet
    FROM transaksi t
    LEFT JOIN users u ON u.id = t.user_id
    WHERE t.jenis='penjualan' AND DATE(t.tanggal) BETWEEN ? AND ?
    GROUP BY t.user_id
    ORDER BY omzet DESC
");
$stmt->execute([$dari, $sampai]);
$perKasir = $stmt->fetchAll();

/* ===== OUTPUT EXCEL ===== */
$filename = 'Laporan_Penjualan_' . $dari . '_sd_' . $sampai . '.xls';

header("Content-Type: application/vnd.ms-excel");
header("Content-Disposition: attachment; filename=\"$filename\"");
header("Pragma: no-cache");
header("Expires: 0");
?>
<html xmlns:o="urn:schemas-microsoft-com:office:office"
      xmlns:x="urn:schemas-microsoft-com:office:excel">
<head>
<meta charset="UTF-8">
<style>
  table { border-collapse: collapse; }
  th, td { border: 1px solid #333; padding: 6px 10px; }
  th { background: #10b981; color: #fff; font-weight: bold; }
  .num { text-align: right; mso-number-format: "\\#\\,\\#\\#0"; }
  .center { text-align: center; }
  .title { font-size: 16px; font-weight: bold; }
  .section { font-size: 13px; font-weight: bold; background: #d1fae5; }
</style>
</head>
<body>

<table>
  <tr><td colspan="3" class="title">LAPORAN PENJUALAN BMT</td></tr>
  <tr><td colspan="3">Periode: <?= date('d/m/Y', strtotime($dari)) ?> s/d <?= date('d/m/Y', strtotime($sampai)) ?></td></tr>
  <tr><td colspan="3"></td></tr>

  <tr><td colspan="3" class="section">RINGKASAN</td></tr>
  <tr><th>Keterangan</th><th colspan="2">Nilai</th></tr>
  <tr><td>Total Omzet</td><td colspan="2" class="num"><?= $rekapJual['omzet'] ?></td></tr>
  <tr><td>Total Transaksi</td><td colspan="2" class="center"><?= $rekapJual['jml'] ?></td></tr>
  <tr><td>Rata-rata per Transaksi</td><td colspan="2" class="num"><?= round($rekapJual['rata']) ?></td></tr>
  <tr><td>Laba Kotor</td><td colspan="2" class="num"><?= round($labaKotor) ?></td></tr>
  <tr><td colspan="3"></td></tr>

  <tr><td colspan="3" class="section">REKAP HARIAN</td></tr>
  <tr><th>Tanggal</th><th>Jumlah Transaksi</th><th>Omzet</th></tr>
  <?php foreach ($harian as $h): ?>
  <tr>
    <td><?= date('d/m/Y', strtotime($h['tgl'])) ?></td>
    <td class="center"><?= $h['jml'] ?></td>
    <td class="num"><?= $h['omzet'] ?></td>
  </tr>
  <?php endforeach; ?>
  <tr><td colspan="3"></td></tr>

  <tr><td colspan="3" class="section">REKAP PER KASIR</td></tr>
  <tr><th>Kasir</th><th>Jumlah Transaksi</th><th>Omzet</th></tr>
  <?php foreach ($perKasir as $k): ?>
  <tr>
    <td><?= htmlspecialchars($k['kasir'] ?? '-') ?></td>
    <td class="center"><?= $k['jml'] ?></td>
    <td class="num"><?= $k['omzet'] ?></td>
  </tr>
  <?php endforeach; ?>
  <tr><td colspan="3"></td></tr>

  <tr><td colspan="3" class="section">DETAIL PER PRODUK</td></tr>
  <tr>
    <th>Kode</th><th>Produk</th><th>Kategori</th>
    <th>Qty Terjual</th><th>Omzet</th><th>Laba</th>
  </tr>
  <?php foreach ($detailProduk as $d): ?>
  <tr>
    <td><?= htmlspecialchars($d['kode']) ?></td>
    <td><?= htmlspecialchars($d['nama']) ?></td>
    <td><?= htmlspecialchars($d['kategori'] ?: '-') ?></td>
    <td class="center"><?= $d['qty'] ?></td>
    <td class="num"><?= $d['omzet'] ?></td>
    <td class="num"><?= $d['laba'] ?></td>
  </tr>
  <?php endforeach; ?>

</table>

</body>
</html>