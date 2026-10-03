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

/* ====== QUERY: Total nilai stok produk ====== */
$totalNilaiStok = (float)$pdo->query("SELECT COALESCE(SUM(harga_jual * stok),0) FROM produk")->fetchColumn();

/* ====== QUERY: Pengeluaran 30 hari terakhir per kategori ====== */
$pengeluaranKategori = $pdo->query("
    SELECT kategori, SUM(nominal) AS total
    FROM pengeluaran
    WHERE tanggal >= DATE_SUB(CURDATE(), INTERVAL 29 DAY)
    GROUP BY kategori
    ORDER BY total DESC
")->fetchAll();

$outLabels = [];
$outData   = [];
foreach ($pengeluaranKategori as $p) {
    $outLabels[] = $p['kategori'];
    $outData[]   = (float)$p['total'];
}

/* ====== QUERY: Total pengeluaran hari ini ====== */
$outHariIni = (float)$pdo->query("
    SELECT COALESCE(SUM(nominal),0) FROM pengeluaran
    WHERE DATE(tanggal) = CURDATE()
")->fetchColumn();

$today = date('Y-m-d');

// Pendapatan HANYA dari penjualan
$pendapatan = (float)$pdo->query("
    SELECT COALESCE(SUM(total),0)
    FROM transaksi
    WHERE DATE(tanggal) = CURDATE()
      AND jenis = 'penjualan'
")->fetchColumn();

// Beban (kolom 'nominal')
$beban = (float)$pdo->query("
    SELECT COALESCE(SUM(nominal),0)
    FROM pengeluaran
    WHERE DATE(tanggal) = CURDATE()
")->fetchColumn();

// Laba / Rugi
$labaRugi = $pendapatan - $beban;
$isLaba   = $labaRugi >= 0;

// ============================================
// HUTANG (READ-ONLY di dashboard) — dikelompokkan per konsumen
// Pakai kolom 'sisa' untuk total hutang
// ============================================
$hutangGroup = $pdo->query("
    SELECT nama,
           COUNT(*)           AS jml_item,
           SUM(qty)           AS total_qty,
           SUM(harga * qty)   AS total_belanja,
           SUM(dibayar)       AS total_dibayar,
           SUM(sisa)          AS total_sisa,
           MIN(tanggal)       AS tgl_awal,
           MAX(tanggal)       AS tgl_akhir
    FROM hutang
    GROUP BY nama
    ORDER BY 
      CASE WHEN SUM(sisa) > 0 THEN 0 ELSE 1 END,
      MAX(tanggal) DESC,
      nama ASC
")->fetchAll();

$totalHutang       = 0;
$totalSudahDibayar = 0;
$jmlBelumLunas     = 0;
foreach ($hutangGroup as $h) {
    $totalHutang       += (float)$h['total_sisa'];
    $totalSudahDibayar += (float)$h['total_dibayar'];
    if ((float)$h['total_sisa'] > 0) $jmlBelumLunas++;
}

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
    <div class="card-head"><h2>Komposisi Pengeluaran (30 Hari Terakhir)</h2></div>
    <div class="card-body" style="height:340px">
      <canvas id="chartSimpanan"></canvas>
    </div>
  </div>
</div>
<!-- =============== END GRAFIK =============== -->

<!-- ===== CARD LABA RUGI ===== -->
<div class="card">
  <div class="card-head">
    <h2>💰 Laba / Rugi Hari Ini</h2>
    <span class="tag <?= $isLaba ? 'tag-green' : 'tag-red' ?>">
      <?= $isLaba ? 'LABA' : 'RUGI' ?>
    </span>
  </div>
  <div class="card-body">
    <table>
      <tbody>
        <tr>
          <td>Pendapatan Penjualan</td>
          <td class="text-right text-green strong"><?= rupiah($pendapatan) ?></td>
        </tr>
        <tr>
          <td>Beban Operasional</td>
          <td class="text-right text-danger strong">− <?= rupiah($beban) ?></td>
        </tr>
        <tr style="border-top:2px solid var(--border); background:var(--g25);">
          <td><strong>Laba / Rugi Bersih</strong></td>
          <td class="text-right strong"
              style="color: <?= $isLaba ? 'var(--g700)' : 'var(--danger)' ?>;">
            <?= rupiah($labaRugi) ?>
          </td>
        </tr>
      </tbody>
    </table>
  </div>
</div>

<!-- ===== CARD HUTANG (READ-ONLY, grouped per konsumen + status) ===== -->
<div class="card">
  <div class="card-head">
    <h2>📝 Daftar Hutang</h2>
    <span class="tag <?= $totalHutang > 0 ? 'tag-red' : 'tag-green' ?>">
      <?= $totalHutang > 0 ? 'Sisa: ' . rupiah($totalHutang) : 'Semua Lunas 🎉' ?>
    </span>
  </div>
  <div class="card-body">
    <p class="text-muted" style="margin-bottom:14px; font-size:13px;">
      ℹ️ Hutang baru dicatat melalui halaman <a href="kasir.php"><strong>Kasir</strong></a> saat uang bayar kurang.
      Pelunasan &amp; detail lengkap di halaman <a href="hutang.php"><strong>Hutang</strong></a>.
    </p>

    <?php if (!$hutangGroup): ?>
      <div class="empty" style="padding:24px 0">Tidak ada data hutang. 🎉</div>
    <?php else: ?>
      <div class="table-wrap">
        <table>
          <thead>
            <tr>
              <th>Nama Konsumen</th>
              <th class="text-center">Jml Item</th>
              <th class="text-right">Total Belanja</th>
              <th class="text-right">Sudah Dibayar</th>
              <th class="text-right">Sisa Hutang</th>
              <th class="text-center">Status</th>
              <th class="text-center">Aksi</th>
            </tr>
          </thead>
          <tbody>
          <?php foreach ($hutangGroup as $h):
            $sisa   = (float)$h['total_sisa'];
            $lunas  = ($sisa <= 0);
          ?>
            <tr <?= $lunas ? 'style="background:#f0fdf4"' : '' ?>>
              <td class="strong"><?= e($h['nama']) ?></td>
              <td class="text-center"><?= (int)$h['jml_item'] ?> produk</td>
              <td class="text-right"><?= rupiah($h['total_belanja']) ?></td>
              <td class="text-right text-green"><?= rupiah($h['total_dibayar']) ?></td>
              <td class="text-right strong <?= $lunas ? 'text-green' : 'text-danger' ?>">
                <?= $lunas ? '✓ LUNAS' : rupiah($sisa) ?>
              </td>
              <td class="text-center">
                <?php if ($lunas): ?>
                  <span class="tag tag-green">LUNAS</span>
                <?php else: ?>
                  <span class="tag tag-red">BELUM LUNAS</span>
                <?php endif; ?>
              </td>
              <td class="text-center">
                <a href="hutang.php?nama=<?= urlencode($h['nama']) ?>"
                   class="btn <?= $lunas ? 'btn-outline' : 'btn-primary' ?> btn-sm">
                  <?= $lunas ? '🔍 Lihat' : '💳 Bayar' ?>
                </a>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>
</div>

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

  // =====================================================
  // 1. LINE CHART — Tren Penjualan 7 Hari
  // =====================================================
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

  // =====================================================
  // 2. PLUGIN — Tampilkan Persen di Dalam Slice
  // =====================================================
  const persenPlugin = {
    id: 'persenPlugin',
    afterDatasetsDraw(chart) {
      const { ctx } = chart;
      const meta = chart.getDatasetMeta(0);
      if (!meta || !meta.data.length) return;

      const data  = chart.data.datasets[0].data;
      const total = data.reduce((a, b) => a + b, 0);
      if (total <= 0) return;

      meta.data.forEach((arc, i) => {
        const persen = (data[i] / total) * 100;
        if (persen < 5) return;

        const pos = arc.tooltipPosition();
        ctx.save();
        ctx.fillStyle = '#ffffff';
        ctx.font = 'bold 13px Segoe UI, sans-serif';
        ctx.textAlign = 'center';
        ctx.textBaseline = 'middle';
        ctx.shadowColor = 'rgba(0,0,0,.4)';
        ctx.shadowBlur = 3;
        ctx.fillText(persen.toFixed(0) + '%', pos.x, pos.y);
        ctx.restore();
      });
    }
  };

  // =====================================================
  // 3. PIE CHART — Komposisi Pengeluaran per Kategori
  // =====================================================
  const ctxPie = document.getElementById('chartSimpanan');
  if (ctxPie) {
    let pieLabels = <?= json_encode($outLabels ?? []) ?>;
    let pieData   = <?= json_encode($outData   ?? []) ?>;

    if (pieData.length === 0) {
      pieLabels = ['Belum ada pengeluaran'];
      pieData   = [1];
    }

    const pieColors = [
      '#10b981', '#f59e0b', '#3b82f6', '#8b5cf6',
      '#ec4899', '#ef4444', '#14b8a6', '#f97316'
    ];

    new Chart(ctxPie, {
      type: 'pie',
      plugins: [persenPlugin],
      data: {
        labels: pieLabels,
        datasets: [{
          data: pieData,
          backgroundColor: (pieData.length === 1 && pieLabels[0] === 'Belum ada pengeluaran')
                           ? ['#e2e8f0'] : pieColors,
          borderColor: '#ffffff',
          borderWidth: 3,
          hoverOffset: 8
        }]
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
          legend: {
            position: 'bottom',
            labels: {
              padding: 14,
              font: { size: 12, weight: '600' },
              color: '#334155',
              usePointStyle: true,
              pointStyle: 'circle'
            }
          },
          tooltip: {
            callbacks: {
              label: (ctx) => {
                if (ctx.label === 'Belum ada pengeluaran') {
                  return ' Belum ada pengeluaran tercatat';
                }
                const total  = ctx.dataset.data.reduce((a, b) => a + b, 0);
                const persen = ((ctx.parsed / total) * 100).toFixed(1);
                return ' ' + ctx.label + ': ' + rupiah(ctx.parsed) + ' (' + persen + '%)';
              }
            }
          }
        }
      }
    });
  }
</script>
<!-- ========== END SCRIPT CHART.JS ========== -->

<?php require_once 'includes/footer.php'; ?>