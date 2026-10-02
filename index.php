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
// HUTANG
// ============================================

// Handle TAMBAH hutang (stok langsung berkurang)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['act'] ?? '') === 'tambah_hutang') {
    $nama       = trim($_POST['nama'] ?? '');
    $produkId   = (int)($_POST['produk_id'] ?? 0);
    $qty        = max(1, (int)($_POST['qty'] ?? 1));
    $keterangan = trim($_POST['keterangan'] ?? '');

    if ($nama === '' || $produkId <= 0) {
        flash('error', 'Nama dan produk wajib diisi.');
        redirect('index.php');
    }

    try {
        $pdo->beginTransaction();

        // Cek stok
        $stmt = $pdo->prepare("SELECT nama, harga_jual, stok FROM produk WHERE id = ? FOR UPDATE");
        $stmt->execute([$produkId]);
        $p = $stmt->fetch();

        if (!$p) throw new Exception('Produk tidak ditemukan.');
        if ($p['stok'] < $qty) {
            throw new Exception('Stok "' . $p['nama'] . '" tidak mencukupi (sisa: ' . $p['stok'] . ').');
        }

        // Simpan hutang
        $pdo->prepare("
            INSERT INTO hutang (nama, produk_id, qty, harga, keterangan, tanggal)
            VALUES (?, ?, ?, ?, ?, CURDATE())
        ")->execute([$nama, $produkId, $qty, $p['harga_jual'], $keterangan]);

        // Kurangi stok langsung
        $pdo->prepare("UPDATE produk SET stok = stok - ? WHERE id = ?")
            ->execute([$qty, $produkId]);

        $pdo->commit();
        flash('success', 'Hutang "' . $nama . '" dicatat. Stok ' . $p['nama'] . ' berkurang ' . $qty . '.');
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        flash('error', $e->getMessage());
    }
    redirect('index.php');
}

// Handle BAYAR hutang (otomatis jadi transaksi penjualan)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['act'] ?? '') === 'bayar_hutang') {
    $id = (int)($_POST['id'] ?? 0);

    if ($id > 0) {
        try {
            $pdo->beginTransaction();

            $stmt = $pdo->prepare("
                SELECT h.*, p.nama AS nama_produk
                FROM hutang h
                JOIN produk p ON p.id = h.produk_id
                WHERE h.id = ?
            ");
            $stmt->execute([$id]);
            $h = $stmt->fetch();

            if (!$h) throw new Exception('Hutang tidak ditemukan.');

            $total = (float)$h['harga'] * (int)$h['qty'];
            $kode  = generateKode('TRX', $pdo, 'transaksi', 'kode');

            // Insert transaksi (jenis=penjualan)
            $pdo->prepare("
                INSERT INTO transaksi (kode, user_id, jenis, total, bayar, kembalian, keterangan, tanggal)
                VALUES (?, ?, 'penjualan', ?, ?, 0, ?, NOW())
            ")->execute([
                $kode,
                user()['id'],
                $total,
                $total,
                'Pelunasan hutang dari ' . $h['nama'] . ($h['keterangan'] ? ' (' . $h['keterangan'] . ')' : '')
            ]);

            $trxId = (int)$pdo->lastInsertId();

            // Insert detail transaksi
            $pdo->prepare("
                INSERT INTO transaksi_detail (transaksi_id, produk_id, qty, harga, subtotal)
                VALUES (?, ?, ?, ?, ?)
            ")->execute([$trxId, $h['produk_id'], $h['qty'], $h['harga'], $total]);

            // Hapus dari daftar hutang
            $pdo->prepare("DELETE FROM hutang WHERE id = ?")->execute([$id]);

            $pdo->commit();
            flash('success', 'Hutang ' . $h['nama'] . ' lunas. Transaksi ' . $kode . ' otomatis tercatat.');
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            flash('error', 'Gagal: ' . $e->getMessage());
        }
    }
    redirect('index.php');
}

// Ambil daftar hutang aktif + total
$hutangAktif = $pdo->query("
    SELECT h.*, p.nama AS nama_produk, p.satuan
    FROM hutang h
    JOIN produk p ON p.id = h.produk_id
    ORDER BY h.id DESC
")->fetchAll();

$totalHutang = 0;
foreach ($hutangAktif as $h) {
    $totalHutang += (float)$h['harga'] * (int)$h['qty'];
}

// Ambil daftar produk untuk dropdown (hanya yang stok > 0)
$produkHutang = $pdo->query("SELECT id, nama, harga_jual, stok, satuan FROM produk WHERE stok > 0 ORDER BY nama")->fetchAll();

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
          <td class="text-right text-green strong">
            <?= rupiah($pendapatan) ?>
          </td>
        </tr>
        <tr>
          <td>Beban Operasional</td>
          <td class="text-right text-danger strong">
            − <?= rupiah($beban) ?>
          </td>
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

<!-- ===== CARD HUTANG ===== -->
<div class="card">
  <div class="card-head">
    <h2>📝 Catatan Hutang</h2>
    <span class="tag tag-red">Total: <?= rupiah($totalHutang) ?></span>
  </div>
  <div class="card-body">

    <!-- Form tambah hutang -->
    <form method="post" style="margin-bottom:18px">
      <input type="hidden" name="act" value="tambah_hutang">
      <div class="form-grid" style="grid-template-columns:1.5fr 2fr 0.7fr 1.5fr auto;align-items:end;gap:10px">

        <div class="form-group">
          <label>Nama Orang <span class="req">*</span></label>
          <input type="text" name="nama" placeholder="Contoh: Budi" required>
        </div>

        <div class="form-group">
          <label>Produk <span class="req">*</span></label>
          <select name="produk_id" required>
            <option value="">-- Pilih Produk --</option>
            <?php foreach ($produkHutang as $p): ?>
              <option value="<?= (int)$p['id'] ?>">
                <?= e($p['nama']) ?> — <?= rupiah($p['harga_jual']) ?>
                (stok: <?= (int)$p['stok'] ?> <?= e($p['satuan']) ?>)
              </option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="form-group">
          <label>Qty <span class="req">*</span></label>
          <input type="number" name="qty" min="1" value="1" required>
        </div>

        <div class="form-group">
          <label>Keterangan</label>
          <input type="text" name="keterangan" placeholder="Opsional...">
        </div>

        <div class="form-group">
          <button class="btn btn-primary" type="submit" style="height:42px">+ Catat</button>
        </div>

      </div>
    </form>

    <!-- Daftar hutang aktif -->
    <?php if (!$hutangAktif): ?>
      <div class="empty" style="padding:24px 0">Tidak ada hutang aktif. 🎉</div>
    <?php else: ?>
      <table>
        <thead>
          <tr>
            <th>Nama</th>
            <th>Produk</th>
            <th class="text-center">Qty</th>
            <th class="text-right">Total</th>
            <th>Keterangan</th>
            <th>Tanggal</th>
            <th class="text-center">Aksi</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($hutangAktif as $h):
          $total = (float)$h['harga'] * (int)$h['qty'];
        ?>
          <tr>
            <td class="strong"><?= e($h['nama']) ?></td>
            <td><?= e($h['nama_produk']) ?></td>
            <td class="text-center"><?= (int)$h['qty'] ?> <?= e($h['satuan']) ?></td>
            <td class="text-right strong text-danger"><?= rupiah($total) ?></td>
            <td class="text-muted"><?= e($h['keterangan'] ?: '-') ?></td>
            <td class="text-muted"><?= date('d/m/Y', strtotime($h['tanggal'])) ?></td>
            <td class="text-center">
              <form method="post" style="display:inline"
                    onsubmit="return confirm('Tandai hutang <?= e($h['nama']) ?> sudah dibayar?\n\nOtomatis akan tercatat sebagai transaksi penjualan.')">
                <input type="hidden" name="act" value="bayar_hutang">
                <input type="hidden" name="id" value="<?= (int)$h['id'] ?>">
                <button class="btn btn-primary btn-sm">✓ Sudah Bayar</button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
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
  //    (HARUS di atas sebelum dipakai!)
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

    // Kalau tidak ada data
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
                           ? ['#e2e8f0']
                           : pieColors,
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