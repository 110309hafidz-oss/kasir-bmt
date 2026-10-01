<?php
require_once 'config/database.php';
requireLogin();

/* ============================================================
   HANDLE SIMPAN / UPDATE
   ============================================================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['act'] ?? '') === 'save') {
    $id            = (int)($_POST['id'] ?? 0);
    $nama          = trim($_POST['nama'] ?? '');
    $kategori      = trim($_POST['kategori'] ?? '');
    $hargaBeli     = (float)($_POST['harga_beli'] ?? 0);      // harga per satuan beli (dus/box)
    $hargaJual     = (float)($_POST['harga_jual'] ?? 0);      // harga per pcs (eceran)
    $stok          = (int)($_POST['stok'] ?? 0);               // stok dalam pcs
    $satuan        = trim($_POST['satuan'] ?? 'pcs');          // satuan jual (pcs)
    $satuanBeli    = trim($_POST['satuan_beli'] ?? 'pcs');     // satuan beli (dus/box)
    $isiPerSatuan  = max(1, (int)($_POST['isi_per_satuan'] ?? 1)); // isi per dus
    $deskripsi     = trim($_POST['deskripsi'] ?? '');
    $gambarLama    = trim($_POST['gambar_lama'] ?? '') ?: null;

    // Harga beli per pcs (untuk validasi margin)
    $hargaBeliPcs = $hargaBeli / $isiPerSatuan;

    // Validasi
    if ($nama === '') {
        flash('error', 'Nama produk wajib diisi.');
        redirect('produk.php' . ($id ? '?edit=' . $id : ''));
    }
    if ($hargaJual < 0 || $hargaBeli < 0 || $stok < 0) {
        flash('error', 'Harga dan stok tidak boleh negatif.');
        redirect('produk.php' . ($id ? '?edit=' . $id : ''));
    }
    if ($hargaJual < $hargaBeliPcs && $hargaBeliPcs > 0) {
        flash('error', 'Harga jual per pcs (Rp ' . number_format($hargaJual, 0, ',', '.') . ') tidak boleh lebih rendah dari harga beli per pcs (Rp ' . number_format($hargaBeliPcs, 0, ',', '.') . ').');
        redirect('produk.php' . ($id ? '?edit=' . $id : ''));
    }

    // Upload gambar (kalau ada file baru)
    $gambar = uploadGambar($_FILES['gambar'] ?? [], $gambarLama);

    // Handle hapus gambar via checkbox
    if (($_POST['hapus_gambar'] ?? '0') === '1' && !empty($gambarLama)) {
        hapusGambar($gambarLama);
        $gambar = null;
    }

    try {
        if ($id > 0) {
            // === UPDATE ===
            $stmt = $pdo->prepare("UPDATE produk
                                   SET nama=?, kategori=?, harga_beli=?, harga_jual=?,
                                       stok=?, satuan=?, satuan_beli=?, isi_per_satuan=?,
                                       gambar=?, deskripsi=?
                                   WHERE id=?");
            $stmt->execute([
                $nama, $kategori, $hargaBeli, $hargaJual,
                $stok, $satuan, $satuanBeli, $isiPerSatuan,
                $gambar, $deskripsi, $id
            ]);
            flash('success', 'Produk "' . $nama . '" berhasil diperbarui.');
        } else {
            // === INSERT ===
            $kode = 'PRD' . str_pad((string)random_int(1, 9999), 4, '0', STR_PAD_LEFT);
            while ((int)$pdo->query("SELECT COUNT(*) FROM produk WHERE kode='" . $kode . "'")->fetchColumn() > 0) {
                $kode = 'PRD' . str_pad((string)random_int(1, 9999), 4, '0', STR_PAD_LEFT);
            }
            $stmt = $pdo->prepare("INSERT INTO produk
                                   (kode, nama, kategori, harga_beli, harga_jual, stok, satuan, satuan_beli, isi_per_satuan, gambar, deskripsi)
                                   VALUES (?,?,?,?,?,?,?,?,?,?,?)");
            $stmt->execute([
                $kode, $nama, $kategori, $hargaBeli, $hargaJual,
                $stok, $satuan, $satuanBeli, $isiPerSatuan,
                $gambar, $deskripsi
            ]);
            flash('success', 'Produk baru "' . $nama . '" berhasil ditambahkan.');
        }
    } catch (PDOException $e) {
        flash('error', 'Gagal menyimpan produk: ' . $e->getMessage());
    }
    redirect('produk.php');
}

/* ============================================================
   HANDLE HAPUS
   ============================================================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['act'] ?? '') === 'delete') {
    $id = (int)($_POST['id'] ?? 0);
    try {
        $stmt = $pdo->prepare("SELECT nama, gambar FROM produk WHERE id = ?");
        $stmt->execute([$id]);
        $p = $stmt->fetch();

        $pdo->prepare("DELETE FROM produk WHERE id = ?")->execute([$id]);

        if ($p && !empty($p['gambar'])) {
            hapusGambar($p['gambar']);
        }
        flash('success', 'Produk "' . ($p['nama'] ?? '') . '" berhasil dihapus.');
    } catch (PDOException $e) {
        flash('error', 'Produk tidak dapat dihapus karena sudah dipakai pada transaksi.');
    }
    redirect('produk.php');
}

/* ============================================================
   HANDLE DUPLIKAT
   ============================================================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['act'] ?? '') === 'duplicate') {
    $id = (int)($_POST['id'] ?? 0);
    try {
        $stmt = $pdo->prepare("SELECT * FROM produk WHERE id = ?");
        $stmt->execute([$id]);
        $p = $stmt->fetch();

        if ($p) {
            $kode = 'PRD' . str_pad((string)random_int(1, 9999), 4, '0', STR_PAD_LEFT);
            while ((int)$pdo->query("SELECT COUNT(*) FROM produk WHERE kode='$kode'")->fetchColumn() > 0) {
                $kode = 'PRD' . str_pad((string)random_int(1, 9999), 4, '0', STR_PAD_LEFT);
            }
            $stmt = $pdo->prepare("INSERT INTO produk
                                   (kode, nama, kategori, harga_beli, harga_jual, stok, satuan, satuan_beli, isi_per_satuan, gambar, deskripsi)
                                   VALUES (?,?,?,?,?,?,?,?,?,?,?)");
            $stmt->execute([
                $kode,
                $p['nama'] . ' (Copy)',
                $p['kategori'],
                $p['harga_beli'],
                $p['harga_jual'],
                0,
                $p['satuan'],
                $p['satuan_beli'] ?? 'pcs',
                $p['isi_per_satuan'] ?? 1,
                $p['gambar'],
                $p['deskripsi'],
            ]);
            flash('success', 'Produk berhasil diduplikasi. Silakan edit untuk penyesuaian.');
        }
    } catch (PDOException $e) {
        flash('error', 'Gagal menduplikasi produk: ' . $e->getMessage());
    }
    redirect('produk.php');
}

/* ============================================================
   DATA UNTUK EDIT
   ============================================================ */
$edit = null;
if (!empty($_GET['edit'])) {
    $stmt = $pdo->prepare("SELECT * FROM produk WHERE id = ?");
    $stmt->execute([(int)$_GET['edit']]);
    $edit = $stmt->fetch();

    if (!$edit) {
        flash('error', 'Produk tidak ditemukan.');
        redirect('produk.php');
    }
}

/* ============================================================
   DATA LIST PRODUK
   ============================================================ */
$kategori_filter = trim($_GET['kategori'] ?? '');

$sql = "SELECT * FROM produk WHERE 1=1";
$params = [];

if ($kategori_filter !== '') {
    $sql .= " AND kategori = ?";
    $params[] = $kategori_filter;
}
$sql .= " ORDER BY nama ASC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$list = $stmt->fetchAll();

// Ambil daftar kategori unik dari database
$kategorisDB = $pdo->query("SELECT DISTINCT kategori FROM produk WHERE kategori IS NOT NULL AND kategori != '' ORDER BY kategori")->fetchAll(PDO::FETCH_COLUMN);

// Kategori bawaan
$kategoriBawaan = ['Minuman', 'Makanan', 'ATK'];

// Gabungkan & hilangkan duplikat (case-insensitive)
$kategoris = array_values(array_unique(array_merge($kategoriBawaan, $kategorisDB)));
sort($kategoris);

$pageTitle = 'Data Produk';
$pageSubtitle = 'Manajemen barang / produk BMT';
require_once 'includes/header.php';
?>

<style>
  /* Style tambahan untuk form 3 kolom dan info konversi */
  .form-grid-3 {
    display: grid;
    grid-template-columns: 1fr 1fr 1fr;
    gap: 12px;
  }
  .margin-info {
    background: #ecfdf5;
    border: 1px solid #a7f3d0;
    border-radius: 8px;
    padding: 10px 14px;
    margin-bottom: 12px;
    font-size: 13px;
    color: #065f46;
  }
  .margin-info strong {
    color: #047857;
  }
  .info-badge {
    display: inline-block;
    background: #fef3c7;
    color: #92400e;
    padding: 2px 8px;
    border-radius: 6px;
    font-size: 11px;
    font-weight: 600;
    margin-left: 6px;
  }
  .konversi-info {
    background: #eff6ff;
    border-left: 3px solid #3b82f6;
    padding: 6px 10px;
    border-radius: 4px;
    font-size: 12px;
    color: #1e40af;
    margin-top: 6px;
  }
  @media (max-width: 768px) {
    .form-grid-3 { grid-template-columns: 1fr; }
  }
</style>

<div class="grid-2">

  <!-- =====================================================
       FORM TAMBAH / EDIT PRODUK
       ===================================================== -->
  <div class="card">
    <div class="card-head">
      <h2><?= $edit ? '✏️ Edit Produk' : '➕ Tambah Produk' ?></h2>
      <?php if ($edit): ?>
        <span class="tag tag-gray"><?= e($edit['kode']) ?></span>
      <?php endif; ?>
    </div>
    <div class="card-body">
      <form method="post" enctype="multipart/form-data" id="formProduk">
        <input type="hidden" name="act" value="save">
        <input type="hidden" name="id" value="<?= (int)($edit['id'] ?? 0) ?>">
        <input type="hidden" name="gambar_lama" value="<?= e($edit['gambar'] ?? '') ?>">

        <!-- NAMA PRODUK -->
        <div class="form-group" style="margin-bottom:12px">
          <label>Nama Produk <span class="req">*</span></label>
          <input type="text" name="nama" value="<?= e($edit['nama'] ?? '') ?>"
                 placeholder="Contoh: Oasis 600ml" required autofocus>
        </div>

        <!-- KATEGORI -->
        <div class="form-group" style="margin-bottom:12px">
          <label>Kategori</label>
          <input type="text" name="kategori" list="listKategori"
                 value="<?= e($edit['kategori'] ?? '') ?>"
                 placeholder="Minuman / Makanan / ATK">
          <datalist id="listKategori">
            <?php foreach ($kategoris as $k): ?>
              <option value="<?= e($k) ?>">
            <?php endforeach; ?>
          </datalist>
        </div>

        <!-- ====== HARGA BELI (PER SATUAN BELI) ====== -->
        <div class="form-group" style="margin-bottom:6px">
          <label>
            Harga Beli (per satuan beli)
            <span class="info-badge">Dus / Box / Pack</span>
          </label>
          <div class="form-grid-3">
            <div class="form-group">
              <label style="font-size:12px;color:#6b7280;font-weight:400">Harga Total</label>
              <input type="number" name="harga_beli" id="hargaBeli"
                     value="<?= (float)($edit['harga_beli'] ?? 0) ?>"
                     min="0" step="100" placeholder="35000">
            </div>
            <div class="form-group">
              <label style="font-size:12px;color:#6b7280;font-weight:400">Satuan Beli</label>
              <input type="text" name="satuan_beli" id="satuanBeli"
                     value="<?= e($edit['satuan_beli'] ?? 'dus') ?>"
                     placeholder="dus / box / pack">
            </div>
            <div class="form-group">
              <label style="font-size:12px;color:#6b7280;font-weight:400">Isi per Satuan</label>
              <input type="number" name="isi_per_satuan" id="isiPerSatuan"
                     value="<?= (int)($edit['isi_per_satuan'] ?? 1) ?>"
                     min="1" placeholder="24">
            </div>
          </div>
          <div class="konversi-info" id="konversiInfo" style="display:none">
            💡 1 <span id="labelSatuanBeli">dus</span> = <strong id="labelIsi">24</strong> pcs ·
            Harga beli per pcs: <strong id="labelHargaPcs">Rp 1.458</strong>
          </div>
        </div>

        <!-- ====== HARGA JUAL (PER PCS) ====== -->
        <div class="form-group" style="margin-bottom:12px">
          <label>
            Harga Jual (per pcs) <span class="req">*</span>
            <span class="info-badge" style="background:#dbeafe;color:#1e40af">Eceran</span>
          </label>
          <input type="number" name="harga_jual" id="hargaJual"
                 value="<?= (float)($edit['harga_jual'] ?? 0) ?>"
                 min="0" step="100" required placeholder="2000">
        </div>

        <!-- PREVIEW MARGIN -->
        <div class="margin-info" id="marginInfo" style="display:none">
          <span>
            💰 Margin per pcs: <strong id="marginText">Rp 0</strong>
            &nbsp;·&nbsp;
            Harga beli per pcs: <strong id="hargaBeliPcs">Rp 0</strong>
          </span>
        </div>

        <!-- STOK & SATUAN JUAL -->
        <div class="form-grid" style="grid-template-columns:1fr 1fr;margin-bottom:12px">
          <div class="form-group">
            <label>Stok (dalam pcs)</label>
            <input type="number" name="stok" value="<?= (int)($edit['stok'] ?? 0) ?>" min="0" placeholder="24">
          </div>
          <div class="form-group">
            <label>Satuan Jual</label>
            <input type="text" name="satuan" value="<?= e($edit['satuan'] ?? 'pcs') ?>" placeholder="pcs / botol / kg">
          </div>
        </div>

        <!-- UPLOAD GAMBAR -->
        <div class="form-group" style="margin-bottom:12px">
          <label>Gambar Produk</label>

          <div class="upload-area" id="uploadArea">
            <div class="upload-preview" id="previewBox">
              <img src="<?= e(produkImage($edit['gambar'] ?? null)) ?>"
                   alt="Preview" id="previewImg">
            </div>

            <div class="upload-info">
              <input type="file" name="gambar" id="inputGambar" accept="image/*">
              <small class="text-muted">Format: JPG, PNG, GIF, WEBP · Maks 2MB</small>
              <?php if (!empty($edit['gambar'])): ?>
                <button type="button" class="btn btn-outline btn-sm" style="margin-top:6px"
                        onclick="hapusGambarUI()">
                  Hapus Gambar
                </button>
                <input type="hidden" name="hapus_gambar" id="hapusGambarFlag" value="0">
              <?php endif; ?>
            </div>
          </div>
        </div>

        <!-- DESKRIPSI -->
        <div class="form-group">
          <label>Deskripsi</label>
          <textarea name="deskripsi" placeholder="Opsional..."><?= e($edit['deskripsi'] ?? '') ?></textarea>
        </div>

        <div class="form-actions">
          <button class="btn btn-primary" type="submit">
            <?= $edit ? 'Perbarui Produk' : 'Simpan Produk' ?>
          </button>
          <?php if ($edit): ?>
            <a href="produk.php" class="btn btn-outline">Batal</a>
          <?php endif; ?>
        </div>
      </form>
    </div>
  </div>

  <!-- =====================================================
       DAFTAR PRODUK
       ===================================================== -->
  <div class="card">
    <div class="card-head">
      <h2>Daftar Produk (<?= count($list) ?>)</h2>
    </div>

    <!-- QUICK FILTER KATEGORI -->
    <div style="padding: 10px 16px; display:flex; gap:8px; flex-wrap:wrap; border-bottom:1px solid #e5e7eb;">
      <a href="produk.php"
         class="btn btn-sm <?= $kategori_filter === '' ? 'btn-primary' : 'btn-outline' ?>">
        Semua
      </a>
      <?php foreach (['Minuman', 'Makanan', 'ATK'] as $kb): ?>
        <a href="produk.php?kategori=<?= urlencode($kb) ?>"
           class="btn btn-sm <?= $kategori_filter === $kb ? 'btn-primary' : 'btn-outline' ?>">
          <?= e($kb) ?>
        </a>
      <?php endforeach; ?>
    </div>

    <div class="table-wrap" style="max-height:640px;overflow-y:auto">
      <table>
        <thead>
          <tr>
            <th>Produk</th>
            <th class="text-right">Harga</th>
            <th class="text-center">Stok</th>
            <th class="text-center">Aksi</th>
          </tr>
        </thead>
        <tbody>
        <?php if (!$list): ?>
          <tr><td colspan="4" class="empty">Belum ada produk.</td></tr>
        <?php else: foreach ($list as $p):
          $isi  = max(1, (int)($p['isi_per_satuan'] ?? 1));
          $beli = (float)$p['harga_beli'];
          $beliPcs = $beli / $isi;
        ?>
          <tr>
            <td>
              <div class="thumb-wrap">
                <img src="<?= e(produkImage($p['gambar'])) ?>" alt="<?= e($p['nama']) ?>" class="thumb">
                <div>
                  <div class="strong"><?= e($p['nama']) ?></div>
                  <small class="text-muted">
                    <?= e($p['kode']) ?> · <?= e($p['kategori'] ?: '-') ?>
                    <?php if ($isi > 1): ?>
                      · 1 <?= e($p['satuan_beli'] ?? 'dus') ?> = <?= $isi ?> <?= e($p['satuan']) ?>
                    <?php endif; ?>
                  </small>
                </div>
              </div>
            </td>
            <td class="text-right">
              <div class="strong"><?= rupiah($p['harga_jual']) ?></div>
              <small class="text-muted">
                Beli: <?= rupiah($beliPcs) ?>/<?= e($p['satuan']) ?>
                <?php if ($isi > 1): ?>
                  <br><span style="color:#9ca3af">(<?= rupiah($beli) ?>/<?= e($p['satuan_beli'] ?? 'dus') ?>)</span>
                <?php endif; ?>
              </small>
            </td>
            <td class="text-center">
              <span class="tag <?= $p['stok'] <= 10 ? 'tag-red' : 'tag-green' ?>">
                <?= (int)$p['stok'] ?> <?= e($p['satuan']) ?>
              </span>
            </td>
            <td class="text-center" style="white-space:nowrap">
              <a href="produk.php?edit=<?= (int)$p['id'] ?>" class="btn btn-outline btn-sm" title="Edit">Edit</a>
              <form method="post" style="display:inline">
                <input type="hidden" name="act" value="duplicate">
                <input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
                <button class="btn btn-outline btn-sm" title="Duplikat">Copy</button>
              </form>
              <form method="post" style="display:inline"
                    onsubmit="return confirm('Hapus produk &quot;<?= e($p['nama']) ?>&quot;?')">
                <input type="hidden" name="act" value="delete">
                <input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
                <button class="btn btn-danger btn-sm" title="Hapus">Hapus</button>
              </form>
            </td>
          </tr>
        <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>

</div>

<script>
/* ============================================================
   PREVIEW GAMBAR SEBELUM UPLOAD
   ============================================================ */
const inputGambar = document.getElementById('inputGambar');
const previewImg  = document.getElementById('previewImg');

if (inputGambar) {
  inputGambar.addEventListener('change', function () {
    const file = this.files && this.files[0];
    if (!file) return;

    if (!file.type.startsWith('image/')) {
      alert('File harus berupa gambar.');
      this.value = '';
      return;
    }
    if (file.size > 2 * 1024 * 1024) {
      alert('Ukuran gambar maksimal 2MB.');
      this.value = '';
      return;
    }

    const reader = new FileReader();
    reader.onload = e => { previewImg.src = e.target.result; };
    reader.readAsDataURL(file);

    const flag = document.getElementById('hapusGambarFlag');
    if (flag) flag.value = '0';
  });
}

/* ============================================================
   HAPUS GAMBAR (UI)
   ============================================================ */
function hapusGambarUI() {
  if (!confirm('Hapus gambar produk ini?')) return;

  previewImg.src = 'data:image/svg+xml;utf8,' + encodeURIComponent(
    '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100">' +
    '<rect width="100" height="100" fill="#ecfdf5"/>' +
    '<path d="M25 65l15-20 12 15 8-10 15 15H25z" fill="#10b981"/>' +
    '<circle cx="35" cy="35" r="7" fill="#d4af37"/></svg>'
  );

  const flag = document.getElementById('hapusGambarFlag');
  if (flag) flag.value = '1';

  if (inputGambar) inputGambar.value = '';
}

/* ============================================================
   HITUNG MARGIN & KONVERSI OTOMATIS
   ============================================================ */
const hb            = document.getElementById('hargaBeli');
const hj            = document.getElementById('hargaJual');
const satuanBeli    = document.getElementById('satuanBeli');
const isiPerSatuan  = document.getElementById('isiPerSatuan');
const marginInfo    = document.getElementById('marginInfo');
const marginText    = document.getElementById('marginText');
const hargaBeliPcs  = document.getElementById('hargaBeliPcs');
const konversiInfo  = document.getElementById('konversiInfo');
const labelSatuanBeli = document.getElementById('labelSatuanBeli');
const labelIsi        = document.getElementById('labelIsi');
const labelHargaPcs   = document.getElementById('labelHargaPcs');

function formatRupiah(n) {
  return 'Rp ' + Math.round(n).toLocaleString('id-ID');
}

function hitungMargin() {
  if (!hb || !hj) return;

  const beliTotal = parseFloat(hb.value) || 0;
  const jual      = parseFloat(hj.value) || 0;
  const isi       = parseInt(isiPerSatuan?.value) || 1;
  const sBeli     = satuanBeli?.value || 'dus';

  const beliPerPcs = isi > 0 ? (beliTotal / isi) : beliTotal;

  // Info konversi
  if (beliTotal > 0 && isi > 1) {
    if (labelSatuanBeli) labelSatuanBeli.textContent = sBeli;
    if (labelIsi)        labelIsi.textContent = isi;
    if (labelHargaPcs)   labelHargaPcs.textContent = formatRupiah(beliPerPcs);
    if (konversiInfo)    konversiInfo.style.display = 'block';
  } else if (konversiInfo) {
    konversiInfo.style.display = 'none';
  }

  // Margin
  if (beliTotal > 0 && jual > 0) {
    const margin = jual - beliPerPcs;
    const persen = beliPerPcs > 0 ? (margin / beliPerPcs * 100).toFixed(1) : '0.0';

    if (marginText) marginText.textContent = formatRupiah(margin) + ' (' + persen + '%)';
    if (marginText) marginText.style.color = margin < 0 ? '#dc2626' : '#047857';
    if (hargaBeliPcs) hargaBeliPcs.textContent = formatRupiah(beliPerPcs);
    if (marginInfo) marginInfo.style.display = 'block';
  } else {
    if (marginInfo) marginInfo.style.display = 'none';
  }
}

if (hb && hj) {
  hb.addEventListener('input', hitungMargin);
  hj.addEventListener('input', hitungMargin);
  if (isiPerSatuan) isiPerSatuan.addEventListener('input', hitungMargin);
  if (satuanBeli)   satuanBeli.addEventListener('input', hitungMargin);
  hitungMargin();
}
</script>

<?php require_once 'includes/footer.php'; ?>