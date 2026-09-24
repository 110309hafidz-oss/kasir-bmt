<?php
require_once 'config/database.php';
requireLogin();

/* ============================================================
   HAPUS TRANSAKSI
   ============================================================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['act'] ?? '') === 'delete') {
    $id         = (int)($_POST['id'] ?? 0);
    $kembalikan = !empty($_POST['kembalikan_stok']);

    if ($id <= 0) {
        flash('error', 'ID transaksi tidak valid.');
        redirect('laporan.php');
    }

    try {
        $pdo->beginTransaction();

        $stmt = $pdo->prepare("SELECT * FROM transaksi WHERE id = ? LIMIT 1 FOR UPDATE");
        $stmt->execute([$id]);
        $trx = $stmt->fetch();

        if (!$trx) throw new Exception('Transaksi tidak ditemukan.');

        if ($kembalikan) {
            $stmt = $pdo->prepare("SELECT produk_id, qty FROM transaksi_detail WHERE transaksi_id = ?");
            $stmt->execute([$id]);
            $detail = $stmt->fetchAll();

            $updStok = $pdo->prepare("UPDATE produk SET stok = stok + ? WHERE id = ?");
            foreach ($detail as $d) {
                if (!empty($d['produk_id'])) {
                    $updStok->execute([(int)$d['qty'], (int)$d['produk_id']]);
                }
            }
        }

        $pdo->prepare("DELETE FROM transaksi_detail WHERE transaksi_id = ?")->execute([$id]);
        $pdo->prepare("DELETE FROM transaksi WHERE id = ?")->execute([$id]);

        $pdo->commit();
        flash('success', 'Transaksi ' . $trx['kode'] . ' berhasil dihapus' . ($kembalikan ? ' dan stok dikembalikan.' : '.'));

    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        flash('error', 'Gagal menghapus transaksi: ' . $e->getMessage());
    }

    redirect('laporan.php?' . http_build_query([
        'dari'   => $_POST['dari']   ?? date('Y-m-01'),
        'sampai' => $_POST['sampai'] ?? date('Y-m-d'),
    ]));
}

/* ============================================================
   HAPUS DETAIL PRODUK (per periode)
   ============================================================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['act'] ?? '') === 'delete_produk_detail') {
    $produkId = (int)($_POST['produk_id'] ?? 0);
    $dari     = $_POST['dari']   ?? date('Y-m-01');
    $sampai   = $_POST['sampai'] ?? date('Y-m-d');

    if ($produkId <= 0) {
        flash('error', 'Produk tidak valid.');
        redirect('laporan.php?' . http_build_query(['dari' => $dari, 'sampai' => $sampai]));
    }

    try {
        $pdo->beginTransaction();

        $stmt = $pdo->prepare("SELECT nama FROM produk WHERE id = ?");
        $stmt->execute([$produkId]);
        $namaProduk = $stmt->fetchColumn() ?: 'Produk';

        $stmt = $pdo->prepare("
            SELECT COUNT(*) FROM transaksi_detail d
            JOIN transaksi t ON t.id = d.transaksi_id
            WHERE d.produk_id = ? AND t.jenis='penjualan'
              AND DATE(t.tanggal) BETWEEN ? AND ?
        ");
        $stmt->execute([$produkId, $dari, $sampai]);
        $jmlBaris = (int)$stmt->fetchColumn();

        if ($jmlBaris === 0) {
            throw new Exception('Tidak ada data penjualan produk ini pada periode tersebut.');
        }

        $stmt = $pdo->prepare("
            DELETE d FROM transaksi_detail d
            JOIN transaksi t ON t.id = d.transaksi_id
            WHERE d.produk_id = ? AND t.jenis='penjualan'
              AND DATE(t.tanggal) BETWEEN ? AND ?
        ");
        $stmt->execute([$produkId, $dari, $sampai]);

        $pdo->commit();
        flash('success', 'Detail penjualan "' . $namaProduk . '" (' . $jmlBaris . ' baris) berhasil dihapus dari laporan.');

    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        flash('error', 'Gagal menghapus: ' . $e->getMessage());
    }

    redirect('laporan.php?' . http_build_query(['dari' => $dari, 'sampai' => $sampai]));
}

/* ============================================================
   FILTER PERIODE
   ============================================================ */
$dari   = $_GET['dari']   ?? date('Y-m-01');
$sampai = $_GET['sampai'] ?? date('Y-m-d');

/* ============================================================
   REKAP PENJUALAN
   ============================================================ */
$stmt = $pdo->prepare("
    SELECT COUNT(*) AS jml, COALESCE(SUM(total),0) AS omzet,
           COALESCE(AVG(total),0) AS rata
    FROM transaksi
    WHERE jenis='penjualan' AND DATE(tanggal) BETWEEN ? AND ?
");
$stmt->execute([$dari, $sampai]);
$rekapJual = $stmt->fetch();

/* ============================================================
   LABA KOTOR
   ============================================================ */
$stmt = $pdo->prepare("
    SELECT COALESCE(SUM((d.harga - p.harga_beli) * d.qty),0) AS laba
    FROM transaksi_detail d
    JOIN produk p ON p.id = d.produk_id
    JOIN transaksi t ON t.id = d.transaksi_id
    WHERE t.jenis='penjualan' AND DATE(t.tanggal) BETWEEN ? AND ?
");
$stmt->execute([$dari, $sampai]);
$labaKotor = (float)$stmt->fetchColumn();

/* ============================================================
   DETAIL PRODUK TERJUAL
   ============================================================ */
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

/* ============================================================
   REKAP HARIAN
   ============================================================ */
$stmt = $pdo->prepare("
    SELECT DATE(tanggal) AS tgl, COUNT(*) AS jml, SUM(total) AS omzet
    FROM transaksi
    WHERE jenis='penjualan' AND DATE(tanggal) BETWEEN ? AND ?
    GROUP BY DATE(tanggal)
    ORDER BY tgl DESC
");
$stmt->execute([$dari, $sampai]);
$harian = $stmt->fetchAll();

/* ============================================================
   REKAP PER KASIR
   ============================================================ */
$stmt = $pdo->prepare("
    SELECT u.nama AS kasir,
           COUNT(t.id) AS jml,
           COALESCE(SUM(t.total),0) AS omzet
    FROM transaksi t
    LEFT JOIN users u ON u.id = t.user_id
    WHERE t.jenis='penjualan' AND DATE(t.tanggal) BETWEEN ? AND ?
    GROUP BY t.user_id
    ORDER BY omzet DESC
");
$stmt->execute([$dari, $sampai]);
$perKasir = $stmt->fetchAll();

/* ============================================================
   DAFTAR TRANSAKSI (untuk hapus)
   ============================================================ */
$stmt = $pdo->prepare("
    SELECT t.*, u.nama AS kasir,
           (SELECT COUNT(*) FROM transaksi_detail WHERE transaksi_id = t.id) AS jml_item
    FROM transaksi t
    LEFT JOIN users u ON u.id = t.user_id
    WHERE t.jenis='penjualan' AND DATE(t.tanggal) BETWEEN ? AND ?
    ORDER BY t.id DESC
    LIMIT 50
");
$stmt->execute([$dari, $sampai]);
$daftarTransaksi = $stmt->fetchAll();

$pageTitle    = 'Laporan';
$pageSubtitle = 'Laporan penjualan';
require_once 'includes/header.php';
?>

<!-- ===== FILTER ===== -->
<div class="card no-print">
  <div class="card-head"><h2>Filter Periode Laporan</h2></div>
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
      <button type="button" class="btn btn-outline" onclick="window.print()">🖨️ Cetak</button>
    </form>
  </div>
</div>

<!-- ===== STATS ===== -->
<div class="stats">
  <div class="stat">
    <div class="stat-label">Total Omzet</div>
    <div class="stat-value"><?= rupiah($rekapJual['omzet']) ?></div>
    <div class="stat-sub"><?= (int)$rekapJual['jml'] ?> transaksi</div>
  </div>
  <div class="stat">
    <div class="stat-label">Laba Kotor</div>
    <div class="stat-value"><?= rupiah($labaKotor) ?></div>
    <div class="stat-sub">Selisih harga jual &amp; beli</div>
  </div>
  <div class="stat">
    <div class="stat-label">Rata-rata / Transaksi</div>
    <div class="stat-value"><?= rupiah($rekapJual['rata']) ?></div>
    <div class="stat-sub">Nilai belanja rata-rata</div>
  </div>
  <div class="stat">
    <div class="stat-label">Produk Terjual</div>
    <div class="stat-value"><?= count($detailProduk) ?></div>
    <div class="stat-sub">Jenis produk</div>
  </div>
</div>

<!-- ===== REKAP HARIAN ===== -->
<div class="card">
  <div class="card-head"><h2>Rekap Penjualan Harian</h2></div>
  <div class="table-wrap">
    <table>
      <thead>
        <tr><th>Tanggal</th><th class="text-center">Jumlah Transaksi</th><th class="text-right">Omzet</th></tr>
      </thead>
      <tbody>
      <?php if (!$harian): ?>
        <tr><td colspan="3" class="empty">Tidak ada data pada periode ini.</td></tr>
      <?php else: foreach ($harian as $h): ?>
        <tr>
          <td class="strong"><?= tanggalIndo($h['tgl'] . ' 00:00:00') ?></td>
          <td class="text-center"><span class="tag tag-blue"><?= (int)$h['jml'] ?></span></td>
          <td class="text-right strong"><?= rupiah($h['omzet']) ?></td>
        </tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- ===== PER KASIR ===== -->
<div class="card">
  <div class="card-head"><h2>Rekap Penjualan per Kasir</h2></div>
  <div class="table-wrap">
    <table>
      <thead>
        <tr><th>Kasir</th><th class="text-center">Jumlah Transaksi</th><th class="text-right">Omzet</th></tr>
      </thead>
      <tbody>
      <?php if (!$perKasir): ?>
        <tr><td colspan="3" class="empty">Tidak ada data pada periode ini.</td></tr>
      <?php else: foreach ($perKasir as $k): ?>
        <tr>
          <td class="strong"><?= e($k['kasir'] ?? '-') ?></td>
          <td class="text-center"><span class="tag tag-blue"><?= (int)$k['jml'] ?></span></td>
          <td class="text-right strong"><?= rupiah($k['omzet']) ?></td>
        </tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- ===== DETAIL PRODUK ===== -->
<div class="card">
  <div class="card-head">
    <h2>Detail Penjualan per Produk</h2>
    <span class="tag tag-amber no-print">Hapus untuk reset laporan</span>
  </div>
  <div class="table-wrap">
    <table>
      <thead>
        <tr>
          <th>Kode</th>
          <th>Produk</th>
          <th>Kategori</th>
          <th class="text-center">Qty Terjual</th>
          <th class="text-right">Omzet</th>
          <th class="text-right">Laba</th>
          <th class="text-center no-print">Aksi</th>
        </tr>
      </thead>
      <tbody>
      <?php if (!$detailProduk): ?>
        <tr><td colspan="7" class="empty">Tidak ada penjualan pada periode ini.</td></tr>
      <?php else: foreach ($detailProduk as $d): ?>
        <tr>
          <td><span class="tag tag-gray"><?= e($d['kode']) ?></span></td>
          <td class="strong"><?= e($d['nama']) ?></td>
          <td class="text-muted"><?= e($d['kategori'] ?: '-') ?></td>
          <td class="text-center"><?= (int)$d['qty'] ?></td>
          <td class="text-right strong"><?= rupiah($d['omzet']) ?></td>
          <td class="text-right <?= $d['laba'] < 0 ? 'text-danger' : 'text-green' ?> strong">
            <?= rupiah($d['laba']) ?>
          </td>
          <td class="text-center no-print">
            <button type="button" class="btn btn-danger btn-sm"
                    onclick="konfirmasiHapusDetail(
                      <?= (int)$d['id'] ?>,
                      '<?= e(addslashes($d['nama'])) ?>',
                      <?= (int)$d['qty'] ?>
                    )">
              Hapus
            </button>
          </td>
        </tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- ===== DAFTAR TRANSAKSI ===== -->
<div class="card">
  <div class="card-head">
    <h2>Daftar Transaksi (<?= count($daftarTransaksi) ?>)</h2>
    <span class="tag tag-amber no-print">Hapus dengan hati-hati</span>
  </div>
  <div class="table-wrap">
    <table>
      <thead>
        <tr>
          <th>Kode</th>
          <th>Tanggal</th>
          <th>Kasir</th>
          <th class="text-center">Item</th>
          <th class="text-right">Total</th>
          <th class="text-right">Bayar</th>
          <th class="text-center no-print">Aksi</th>
        </tr>
      </thead>
      <tbody>
      <?php if (!$daftarTransaksi): ?>
        <tr><td colspan="7" class="empty">Belum ada transaksi pada periode ini.</td></tr>
      <?php else: foreach ($daftarTransaksi as $t): ?>
        <tr>
          <td><span class="tag tag-green"><?= e($t['kode']) ?></span></td>
          <td class="text-muted"><?= tanggalIndo($t['tanggal']) ?></td>
          <td class="text-muted"><?= e($t['kasir'] ?? '-') ?></td>
          <td class="text-center"><?= (int)$t['jml_item'] ?></td>
          <td class="text-right strong"><?= rupiah($t['total']) ?></td>
          <td class="text-right"><?= rupiah($t['bayar']) ?></td>
          <td class="text-center no-print" style="white-space:nowrap">
<a href="struk.php?id=<?= (int)$t['id'] ?>" class="btn btn-outline btn-sm">Struk</a>            <button type="button" class="btn btn-danger btn-sm"
                    onclick="konfirmasiHapus(<?= (int)$t['id'] ?>, '<?= e($t['kode']) ?>')">
              Hapus
            </button>
          </td>
        </tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- ===== MODAL HAPUS TRANSAKSI ===== -->
<div class="modal-overlay no-print" id="modalHapus">
  <div class="modal-box">
    <div class="modal-head">
      <h3>Hapus Transaksi?</h3>
      <button type="button" class="modal-close" onclick="tutupModal()">×</button>
    </div>
    <div class="modal-body">
      <p>Anda akan menghapus transaksi:</p>
      <p class="modal-kode" id="modalKode">-</p>

      <label class="modal-check">
        <input type="checkbox" id="chkKembalikan" checked>
        <span>Kembalikan stok produk</span>
      </label>

      <p class="modal-warn">⚠️ Tindakan ini tidak dapat dibatalkan.</p>
    </div>
    <div class="modal-foot">
      <button type="button" class="btn btn-outline" onclick="tutupModal()">Batal</button>
      <form method="post" style="display:inline">
        <input type="hidden" name="act" value="delete">
        <input type="hidden" name="id" id="modalId">
        <input type="hidden" name="dari" value="<?= e($dari) ?>">
        <input type="hidden" name="sampai" value="<?= e($sampai) ?>">
        <input type="hidden" name="kembalikan_stok" id="modalKembalikan" value="1">
        <button type="submit" class="btn btn-danger">Ya, Hapus</button>
      </form>
    </div>
  </div>
</div>

<!-- ===== MODAL HAPUS DETAIL PRODUK ===== -->
<div class="modal-overlay no-print" id="modalHapusDetail">
  <div class="modal-box">
    <div class="modal-head">
      <h3>Hapus Detail Penjualan?</h3>
      <button type="button" class="modal-close" onclick="tutupModalDetail()">×</button>
    </div>
    <div class="modal-body">
      <p>Anda akan menghapus <strong>semua catatan penjualan</strong> produk:</p>
      <p class="modal-kode" id="modalDetailNama">-</p>

      <div class="modal-info">
        <div><span>Qty Terjual</span><strong id="modalDetailQty">0</strong></div>
        <div><span>Periode</span><strong><?= e($dari) ?> s/d <?= e($sampai) ?></strong></div>
      </div>

      <p class="modal-warn">
        ⚠️ Data penjualan produk ini akan hilang dari laporan.
        Stok produk <strong>tidak</strong> dikembalikan.
      </p>
    </div>
    <div class="modal-foot">
      <button type="button" class="btn btn-outline" onclick="tutupModalDetail()">Batal</button>
      <form method="post" style="display:inline">
        <input type="hidden" name="act" value="delete_produk_detail">
        <input type="hidden" name="produk_id" id="modalDetailId">
        <input type="hidden" name="dari"   value="<?= e($dari) ?>">
        <input type="hidden" name="sampai" value="<?= e($sampai) ?>">
        <button type="submit" class="btn btn-danger">Ya, Hapus</button>
      </form>
    </div>
  </div>
</div>

<script>
/* ============================================================
   MODAL HAPUS TRANSAKSI
   ============================================================ */
function konfirmasiHapus(id, kode) {
  document.getElementById('modalId').value = id;
  document.getElementById('modalKode').textContent = kode;
  document.getElementById('modalHapus').classList.add('show');
}

function tutupModal() {
  document.getElementById('modalHapus').classList.remove('show');
}

document.getElementById('chkKembalikan').addEventListener('change', function () {
  document.getElementById('modalKembalikan').value = this.checked ? '1' : '0';
});

document.getElementById('modalHapus').addEventListener('click', function (e) {
  if (e.target === this) tutupModal();
});

/* ============================================================
   MODAL HAPUS DETAIL PRODUK
   ============================================================ */
function konfirmasiHapusDetail(id, nama, qty) {
  document.getElementById('modalDetailId').value = id;
  document.getElementById('modalDetailNama').textContent = nama;
  document.getElementById('modalDetailQty').textContent = qty;
  document.getElementById('modalHapusDetail').classList.add('show');
}

function tutupModalDetail() {
  document.getElementById('modalHapusDetail').classList.remove('show');
}

document.getElementById('modalHapusDetail').addEventListener('click', function (e) {
  if (e.target === this) tutupModalDetail();
});

/* ============================================================
   ESC untuk menutup semua modal
   ============================================================ */
document.addEventListener('keydown', function (e) {
  if (e.key === 'Escape') {
    tutupModal();
    tutupModalDetail();
  }
});
</script>

<?php require_once 'includes/footer.php'; ?>