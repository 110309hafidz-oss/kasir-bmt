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

$filename = 'Riwayat_Transaksi_' . $dari . '_sd_' . $sampai . '.xls';

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
</style>
</head>
<body>

<table>
  <tr><td colspan="7" class="title">RIWAYAT TRANSAKSI PENJUALAN</td></tr>
  <tr><td colspan="7">Periode: <?= date('d/m/Y', strtotime($dari)) ?> s/d <?= date('d/m/Y', strtotime($sampai)) ?></td></tr>
  <tr><td colspan="7">Total Omzet: <?= $totalOmzet ?> | Jumlah Transaksi: <?= count($rows) ?></td></tr>
  <tr><td colspan="7"></td></tr>

  <tr>
    <th>Kode</th>
    <th>Tanggal</th>
    <th>Kasir</th>
    <th>Total</th>
    <th>Bayar</th>
    <th>Kembalian</th>
    <th>Keterangan</th>
  </tr>
  <?php foreach ($rows as $r): ?>
  <tr>
    <td><?= htmlspecialchars($r['kode']) ?></td>
    <td><?= date('d/m/Y H:i', strtotime($r['tanggal'])) ?></td>
    <td><?= htmlspecialchars($r['kasir'] ?? '-') ?></td>
    <td class="num"><?= $r['total'] ?></td>
    <td class="num"><?= $r['bayar'] ?></td>
    <td class="num"><?= $r['kembalian'] ?></td>
    <td><?= htmlspecialchars($r['keterangan'] ?: '-') ?></td>
  </tr>
  <?php endforeach; ?>
  <tr>
    <td colspan="3"><strong>TOTAL</strong></td>
    <td class="num"><strong><?= $totalOmzet ?></strong></td>
    <td colspan="3"></td>
  </tr>
</table>

</body>
</html>