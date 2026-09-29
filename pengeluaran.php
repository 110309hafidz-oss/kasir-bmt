<?php
require_once 'config/database.php';
requireLogin();

/* ============================================================
   SIMPAN PENGELUARAN BARU
   ============================================================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['act'] ?? '') === 'save') {
    $tanggal    = trim($_POST['tanggal'] ?? date('Y-m-d'));
    $kategori   = trim($_POST['kategori'] ?? '');
    $keterangan = trim($_POST['keterangan'] ?? '');
    $nominal    = (float)($_POST['nominal'] ?? 0);

    if ($kategori === '') {
        flash('error', 'Kategori wajib diisi.');
        redirect('pengeluaran.php');
    }
    if ($nominal <= 0) {
        flash('error', 'Nominal harus lebih dari 0.');
        redirect('pengeluaran.php');
    }

    try {
        $kode = generateKode('OUT', $pdo, 'pengeluaran', 'kode');

        $stmt = $pdo->prepare("INSERT INTO pengeluaran
                               (kode, tanggal, kategori, keterangan, nominal, user_id)
                               VALUES (?,?,?,?,?,?)");
        $stmt->execute([
            $kode,
            $tanggal . ' ' . date('H:i:s'),
            $kategori,
            $keterangan,
            $nominal,
            user()['id']
        ]);

        flash('success', 'Pengeluaran ' . $kode . ' berhasil disimpan.');
    } catch (PDOException $e) {
        flash('error', 'Gagal menyimpan: ' . $e->getMessage());
    }
    redirect('pengeluaran.php');
}

/* ============================================================
   HAPUS PENGELUARAN
   ============================================================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['act'] ?? '') === 'delete') {
    $id = (int)($_POST['id'] ?? 0);
    try {
        $pdo->prepare("DELETE FROM pengeluaran WHERE id = ?")->execute([$id]);
        flash('success', 'Data pengeluaran berhasil dihapus.');
    } catch (PDOException $e) {
        flash('error', 'Gagal menghapus: ' . $e->getMessage());
    }
    redirect('pengeluaran.php?' . http_build_query([
        'dari'   => $_POST['dari']   ?? date('Y-m-01'),
        'sampai' => $_POST['sampai'] ?? date('Y-m-d'),
    ]));
}

/* ============================================================
   FILTER PERIODE
   ============================================================ */
$dari   = $_GET['dari']   ?? date('Y-m-01');
$sampai = $_GET['sampai'] ?? date('Y-m-d');

/* ============================================================
   REKAP
   ============================================================ */
$stmt = $pdo->prepare("
    SELECT COUNT(*) AS jml, COALESCE(SUM(nominal),0) AS total
    FROM pengeluaran
    WHERE DATE(tanggal) BETWEEN ? AND ?
");
$stmt->execute([$dari, $sampai]);
$rekap = $stmt->fetch();

/* ============================================================
   REKAP PER KATEGORI
   ============================================================ */
$stmt = $pdo->prepare("
    SELECT kategori, COUNT(*) AS jml, SUM(nominal) AS total
    FROM pengeluaran
    WHERE DATE(tanggal) BETWEEN ? AND ?
    GROUP BY kategori
    ORDER BY total DESC
");
$stmt->execute([$dari, $sampai]);
$perKategori = $stmt->fetchAll();

/* ============================================================
   DAFTAR PENGELUARAN
   ============================================================ */
$stmt = $pdo->prepare("
    SELECT p.*, u.nama AS input_by
    FROM pengeluaran p
    LEFT JOIN users u ON u.id = p.user_id
    WHERE DATE(p.tanggal) BETWEEN ? AND ?
    ORDER BY p.id DESC
");
$stmt->execute([$dari, $sampai]);
$list = $stmt->fetchAll();

$pageTitle    = 'Pengeluaran';
$pageSubtitle = 'Catat & pantau pengeluaran operasional';
require_once 'includes/header.php';
?>

<!-- ===== FILTER ===== -->
<div class="card">
  <div class="card-head"><h2>Filter Periode</h2></div>
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
      <button class="btn btn-primary">🔍 Tampilkan</button>
      <a href="pengeluaran.php" class="btn btn-outline">Reset</a>
    </form>
  </div>
</div>

<!-- ===== STATS ===== -->
<div class="stats">
  <div class="stat">
    <div class="stat-label">Total Pengeluaran</div>
    <div class="stat-value"><?= rupiah($rekap['total']) ?></div>
    <div class="stat-sub"><?= (int)$rekap['jml'] ?> catatan</div>
  </div>
  <div class="stat">
    <div class="stat-label">Jumlah Kategori</div>
    <div class="stat-value"><?= count($perKategori) ?></div>
    <div class="stat-sub">kategori berbeda</div>
  </div>
  <div class="stat">
    <div class="stat-label">Rata-rata</div>
    <div class="stat-value">
      <?= rupiah($rekap['jml'] > 0 ? $rekap['total'] / $rekap['jml'] : 0) ?>
    </div>
    <div class="stat-sub">per catatan</div>
  </div>
  <div class="stat">
    <div class="stat-label">Periode</div>
    <div class="stat-value" style="font-size:14px">
      <?= date('d/m/y', strtotime($dari)) ?> — <?= date('d/m/y', strtotime($sampai)) ?>
    </div>
    <div class="stat-sub">tanggal laporan</div>
  </div>
</div>

<div class="grid-2">

  <!-- ===== FORM INPUT ===== -->
  <div class="card">
    <div class="card-head"><h2>➕ Tambah Pengeluaran</h2></div>
    <div class="card-body">
      <form method="post">
        <input type="hidden" name="act" value="save">

        <div class="form-group" style="margin-bottom:12px">
          <label>Tanggal <span class="req">*</span></label>
          <input type="date" name="tanggal" value="<?= date('Y-m-d') ?>" required>
        </div>

        <div class="form-group" style="margin-bottom:12px">
          <label>Kategori <span class="req">*</span></label>
          <input type="text" name="kategori" list="listKategoriOut"
                 placeholder="Operasional / Listrik / Gaji / dll" required>
          <datalist id="listKategoriOut">
            <option value="Operasional">
            <option value="Listrik">
            <option value="Air">
            <option value="Internet">
            <option value="Gaji">
            <option value="Transport">
            <option value="Sewa">
            <option value="Perawatan">
            <option value="Lain-lain">
          </datalist>
        </div>

        <div class="form-group" style="margin-bottom:12px">
          <label>Nominal <span class="req">*</span></label>
          <input type="number" name="nominal" min="1" step="100"
                 placeholder="0" required>
        </div>

        <div class="form-group" style="margin-bottom:12px">
          <label>Keterangan</label>
          <textarea name="keterangan" placeholder="Opsional..."></textarea>
        </div>

        <div class="form-actions">
          <button class="btn btn-primary" type="submit">Simpan Pengeluaran</button>
        </div>
      </form>
    </div>
  </div>

  <!-- ===== REKAP PER KATEGORI ===== -->
  <div class="card">
    <div class="card-head"><h2>Rekap per Kategori</h2></div>
    <div class="table-wrap">
      <table>
        <thead>
          <tr>
            <th>Kategori</th>
            <th class="text-center">Jml</th>
            <th class="text-right">Total</th>
          </tr>
        </thead>
        <tbody>
        <?php if (!$perKategori): ?>
          <tr><td colspan="3" class="empty">Belum ada pengeluaran.</td></tr>
        <?php else: foreach ($perKategori as $k): ?>
          <tr>
            <td class="strong"><?= e($k['kategori']) ?></td>
            <td class="text-center"><span class="tag tag-blue"><?= (int)$k['jml'] ?></span></td>
            <td class="text-right strong"><?= rupiah($k['total']) ?></td>
          </tr>
        <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>

</div>

<!-- ===== DAFTAR PENGELUARAN ===== -->
<div class="card">
  <div class="card-head">
    <h2>Daftar Pengeluaran (<?= count($list) ?>)</h2>
  </div>
  <div class="table-wrap">
    <table>
      <thead>
        <tr>
          <th>Kode</th>
          <th>Tanggal</th>
          <th>Kategori</th>
          <th>Keterangan</th>
          <th>Input by</th>
          <th class="text-right">Nominal</th>
          <th class="text-center">Aksi</th>
        </tr>
      </thead>
      <tbody>
      <?php if (!$list): ?>
        <tr><td colspan="7" class="empty">Belum ada data pada periode ini.</td></tr>
      <?php else: foreach ($list as $p): ?>
        <tr>
          <td><span class="tag tag-gray"><?= e($p['kode']) ?></span></td>
          <td class="text-muted"><?= tanggalIndo($p['tanggal']) ?></td>
          <td><span class="tag tag-amber"><?= e($p['kategori']) ?></span></td>
          <td><?= e($p['keterangan'] ?: '-') ?></td>
          <td class="text-muted"><?= e($p['input_by'] ?? '-') ?></td>
          <td class="text-right strong"><?= rupiah($p['nominal']) ?></td>
          <td class="text-center">
            <form method="post" style="display:inline"
                  onsubmit="return confirm('Hapus pengeluaran <?= e($p['kode']) ?>?')">
              <input type="hidden" name="act" value="delete">
              <input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
              <input type="hidden" name="dari" value="<?= e($dari) ?>">
              <input type="hidden" name="sampai" value="<?= e($sampai) ?>">
              <button class="btn btn-danger btn-sm">Hapus</button>
            </form>
          </td>
        </tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php require_once 'includes/footer.php'; ?>