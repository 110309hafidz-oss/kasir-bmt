<?php
require_once 'config/database.php';
requireLogin();

$pageTitle = 'Dashboard';
$pageSubtitle = 'Ringkasan aktivitas BMT hari ini';

$totalProduk = (int)$pdo->query("SELECT COUNT(*) FROM produk")->fetchColumn();
$stokKritis  = (int)$pdo->query("SELECT COUNT(*) FROM produk WHERE stok <= 10")->fetchColumn();

$trxHariIni = $pdo->query("SELECT COUNT(*) AS jml, COALESCE(SUM(total),0) AS nominal
                           FROM transaksi
                           WHERE jenis='penjualan' AND DATE(tanggal)=CURDATE()")->fetch();

$trxTerbaru = $pdo->query("SELECT t.*, u.nama AS kasir
                           FROM transaksi t
                           LEFT JOIN users u ON u.id = t.user_id
                           WHERE t.jenis='penjualan'
                           ORDER BY t.id DESC LIMIT 6")->fetchAll();

$produkLaris = $pdo->query("SELECT p.nama, SUM(d.qty) AS terjual, SUM(d.subtotal) AS omzet
                            FROM transaksi_detail d
                            JOIN produk p ON p.id = d.produk_id
                            JOIN transaksi t ON t.id = d.transaksi_id
                            WHERE t.jenis='penjualan'
                            GROUP BY p.id ORDER BY terjual DESC LIMIT 5")->fetchAll();

/* ====== QUERY: Penjualan 7 Hari Terakhir untuk Grafik ====== */
$penjualan7Hari = $pdo->query("
    SELECT DATE(tanggal) AS tgl, COALESCE(SUM(total),0) AS nominal
    FROM transaksi
    WHERE jenis='penjualan'
      AND tanggal >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)
    GROUP BY DATE(tanggal)
    ORDER BY tgl ASC
")->fetchAll(PDO::FETCH_KEY_PAIR);

$chartLabels = [];
$chartData   = [];
for ($i = 6; $i >= 0; $i--) {
    $tgl = date('Y-m-d', strtotime("-$i day"));
    $chartLabels[] = date('d M', strtotime($tgl));
    $chartData[]   = (float)($penjualan7Hari[$tgl] ?? 0);
}

/* ====== QUERY: Total nilai stok produk (pengganti simpanan) ====== */
$totalNilaiStok = (float)$pdo->query("SELECT COALESCE(SUM(harga_jual * stok),0) FROM produk")->fetchColumn();

require_once 'includes/header.php';
?>

<div class="stats">
  <div class="stat">
    <div class="stat-label">Penjualan Hari Ini</div>
    <div class="stat-value"><?= rupiah($trxHariIni['nominal']) ?></div>
    <div class="stat-sub"><?= (int)$trxHariIni['jml'] ?> transaksi</div>
  </div>
  <div class="stat">
    <div class="stat-label">Nilai Stok Produk</div>
    <div class="stat-value"><?= rupiah($totalNilaiStok) ?></div>
    <div class="stat-sub"><?= number_format($totalProduk) ?> jenis produk</div>
  </div>
  <div class="stat">
    <div class="stat-label">Produk</div>
    <div class="stat-value"><?= number_format($totalProduk) ?></div>
    <div class="stat-sub"><?= $stokKritis ?> produk stok menipis</div>
  </div>
  <div class="stat">
    <div class="stat-label">Transaksi Terbaru</div>
    <div class="stat-value"><?= count($trxTerbaru) ?></div>
    <div class="stat-sub">6 transaksi terakhir</div>
  </div>
</div>

<!-- ================= GRAFIK ================= -->
<div class="grid-2">
  <div class="card">
    <div class="card-head"><h2>Tren Penjualan 7 Hari Terakhir</h2></div>
    <div class="card-body">
      <canvas id="chartPenjualan" height="120"></canvas>
    </div>
  </div>

  <div class="card">
    <div class="card-head"><h2>Komposisi Penjualan per Hari</h2></div>
    <div class="card-body">
      <canvas id="chartSimpanan" height="120"></canvas>
    </div>
  </div>
</div>
<!-- =============== END GRAFIK =============== -->

<div class="card">
  <div class="card-head">
    <h2>Transaksi Terbaru</h2>
    <a href="transaksi.php" class="btn btn-outline btn-sm">Lihat Semua</a>
  </div>
  <div class="table-wrap">
    <table>
      <thead>
        <tr><th>Kode</th><th>Kasir</th><th>Waktu</th><th class="text-right">Total</th></tr>
      </thead>
      <tbody>
      <?php if (!$trxTerbaru): ?>
        <tr><td colspan="4" class="empty">Belum ada transaksi.</td></tr>
      <?php else: foreach ($trxTerbaru as $t): ?>
        <tr>
          <td><span class="tag tag-green"><?= e($t['kode']) ?></span></td>
          <td><?= e($t['kasir'] ?? '-') ?></td>
          <td class="text-muted"><?= tanggalIndo($t['tanggal']) ?></td>
          <td class="text-right strong"><?= rupiah($t['total']) ?></td>
        </tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>

<div class="card">
  <div class="card-head"><h2>Produk Terlaris</h2></div>
  <div class="table-wrap">
    <table>
      <thead>
        <tr><th>Produk</th><th class="text-center">Terjual</th><th class="text-right">Omzet</th></tr>
      </thead>
      <tbody>
      <?php if (!$produkLaris): ?>
        <tr><td colspan="3" class="empty">Belum ada penjualan.</td></tr>
      <?php else: foreach ($produkLaris as $p): ?>
        <tr>
          <td class="strong"><?= e($p['nama']) ?></td>
          <td class="text-center"><span class="tag tag-blue"><?= (int)$p['terjual'] ?></span></td>
          <td class="text-right strong"><?= rupiah($p['omzet']) ?></td>
        </tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- ============ SCRIPT CHART.JS ============ -->
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<script>
  const rupiah = (n) => 'Rp ' + Number(n).toLocaleString('id-ID');

  // Line Chart Penjualan
  const ctxPenjualan = document.getElementById('chartPenjualan');
  if (ctxPenjualan) {
    new Chart(ctxPenjualan, {
      type: 'line',
      data: {
        labels: <?= json_encode($chartLabels) ?>,
        datasets: [{
          label: 'Penjualan',
          data: <?= json_encode($chartData) ?>,
          borderColor: '#10b981',
          backgroundColor: 'rgba(16, 185, 129, 0.15)',
          borderWidth: 2,
          fill: true,
          tension: 0.35,
          pointBackgroundColor: '#10b981',
          pointRadius: 4
        }]
      },
      options: {
        responsive: true,
        plugins: {
          legend: { display: false },
          tooltip: { callbacks: { label: (ctx) => rupiah(ctx.parsed.y) } }
        },
        scales: {
          y: { beginAtZero: true, ticks: { callback: (v) => rupiah(v) } }
        }
      }
    });
  }

  // Bar Chart Penjualan per Hari (pengganti doughnut simpanan)
  const ctxBar = document.getElementById('chartSimpanan');
  if (ctxBar) {
    new Chart(ctxBar, {
      type: 'bar',
      data: {
        labels: <?= json_encode($chartLabels) ?>,
        datasets: [{
          label: 'Penjualan',
          data: <?= json_encode($chartData) ?>,
          backgroundColor: [
            'rgba(16,185,129,.85)',
            'rgba(5,150,105,.85)',
            'rgba(4,120,87,.85)',
            'rgba(52,211,153,.85)',
            'rgba(16,185,129,.85)',
            'rgba(5,150,105,.85)',
            'rgba(4,120,87,.85)'
          ],
          borderRadius: 6
        }]
      },
      options: {
        responsive: true,
        plugins: {
          legend: { display: false },
          tooltip: { callbacks: { label: (ctx) => rupiah(ctx.parsed.y) } }
        },
        scales: {
          y: { beginAtZero: true, ticks: { callback: (v) => rupiah(v) } }
        }
      }
    });
  }
</script>
<!-- ========== END SCRIPT CHART.JS ========== -->

<?php require_once 'includes/footer.php'; ?>