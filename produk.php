<?php
require_once 'config/database.php';
requireLogin();

/* ============================================================
   HANDLE SIMPAN / UPDATE
   ============================================================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['act'] ?? '') === 'save') {
    $id         = (int)($_POST['id'] ?? 0);
    $nama       = trim($_POST['nama'] ?? '');
    $kategori   = trim($_POST['kategori'] ?? '');
    $hargaBeli  = (float)($_POST['harga_beli'] ?? 0);
    $hargaJual  = (float)($_POST['harga_jual'] ?? 0);
    $stok       = (int)($_POST['stok'] ?? 0);
    $satuan     = trim($_POST['satuan'] ?? 'pcs');
    $deskripsi  = trim($_POST['deskripsi'] ?? '');
    $gambarLama = trim($_POST['gambar_lama'] ?? '') ?: null;

    // Validasi
    if ($nama === '') {
        flash('error', 'Nama produk wajib diisi.');
        redirect('produk.php' . ($id ? '?edit=' . $id : ''));
    }
    if ($hargaJual < 0 || $hargaBeli < 0 || $stok < 0) {
        flash('error', 'Harga dan stok tidak boleh negatif.');
        redirect('produk.php' . ($id ? '?edit=' . $id : ''));
    }
    if ($hargaJual < $hargaBeli && $hargaBeli > 0) {
        flash('error', 'Harga jual tidak boleh lebih rendah dari harga beli.');
        redirect('produk.php' . ($id ? '?edit=' . $id : ''));
    }

    // Upload gambar (kalau ada file baru)
    $gambar = uploadGambar($_FILES['gambar'] ?? [], $gambarLama);

    try {
        if ($id > 0) {
            // === UPDATE ===
            $stmt = $pdo->prepare("UPDATE produk
                                   SET nama=?, kategori=?, harga_beli=?, harga_jual=?,
                                       stok=?, satuan=?, gambar=?, deskripsi=?
                                   WHERE id=?");
            $stmt->execute([$nama, $kategori, $hargaBeli, $hargaJual, $stok, $satuan, $gambar, $deskripsi, $id]);
            flash('success', 'Produk "' . $nama . '" berhasil diperbarui.');
        } else {
            // === INSERT ===
            $kode = 'PRD' . str_pad((string)random_int(1, 9999), 4, '0', STR_PAD_LEFT);
            while ((int)$pdo->query("SELECT COUNT(*) FROM produk WHERE kode='" . $kode . "'")->fetchColumn() > 0) {
                $kode = 'PRD' . str_pad((string)random_int(1, 9999), 4, '0', STR_PAD_LEFT);
            }
            $stmt = $pdo->prepare("INSERT INTO produk
                                   (kode, nama, kategori, harga_beli, harga_jual, stok, satuan, gambar, deskripsi)
                                   VALUES (?,?,?,?,?,?,?,?,?)");
            $stmt->execute([$kode, $nama, $kategori, $hargaBeli, $hargaJual, $stok, $satuan, $gambar, $deskripsi]);
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
        // Ambil nama file gambar sebelum dihapus
        $stmt = $pdo->prepare("SELECT nama, gambar FROM produk WHERE id = ?");
        $stmt->execute([$id]);
        $p = $stmt->fetch();

        $pdo->prepare("DELETE FROM produk WHERE id = ?")->execute([$id]);

        // Hapus file gambar
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
                                   (kode, nama, kategori, harga_beli, harga_jual, stok, satuan, gambar, deskripsi)
                                   VALUES (?,?,?,?,?,?,?,?,?)");
            $stmt->execute([
                $kode,
                $p['nama'] . ' (Copy)',
                $p['kategori'],
                $p['harga_beli'],
                $p['harga_jual'],
                0, // stok di-reset
                $p['satuan'],
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
$q = trim($_GET['q'] ?? '');
$kategori_filter = trim($_GET['kategori'] ?? '');

$sql = "SELECT * FROM produk WHERE 1=1";
$params = [];

if ($q !== '') {
    $sql .= " AND (nama LIKE ? OR kode LIKE ?)";
    $like = "%$q%";
    $params[] = $like;
    $params[] = $like;
}
if ($kategori_filter !== '') {
    $sql .= " AND kategori = ?";
    $params[] = $kategori_filter;
}
$sql .= " ORDER BY nama ASC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$list = $stmt->fetchAll();

// Ambil daftar kategori unik
$kategoris = $pdo->query("SELECT DISTINCT kategori FROM produk WHERE kategori IS NOT NULL AND kategori != '' ORDER BY kategori")->fetchAll(PDO::FETCH_COLUMN);

$pageTitle = 'Data Produk';
$pageSubtitle = 'Manajemen barang / produk BMT';
require_once 'includes/header.php';
?>

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

        <div class="form-group" style="margin-bottom:12px">
          <label>Nama Produk <span class="req">*</span></label>
          <input type="text" name="nama" value="<?= e($edit['nama'] ?? '') ?>"
                 placeholder="Contoh: Beras Premium 5kg" required autofocus>
        </div>

        <div class="form-group" style="margin-bottom:12px">
          <label>Kategori</label>
          <input type="text" name="kategori" list="listKategori"
                 value="<?= e($edit['kategori'] ?? '') ?>"
                 placeholder="Sembako / ATK / dll">
          <datalist id="listKategori">
            <?php foreach ($kategoris as $k): ?>
              <option value="<?= e($k) ?>">
            <?php endforeach; ?>
          </datalist>
        </div>

        <div class="form-grid" style="grid-template-columns:1fr 1fr;margin-bottom:12px">
          <div class="form-group">
            <label>Harga Beli</label>
            <input type="number" name="harga_beli" id="hargaBeli"
                   value="<?= (float)($edit['harga_beli'] ?? 0) ?>"
                   min="0" step="100">
          </div>
          <div class="form-group">
            <label>Harga Jual <span class="req">*</span></label>
            <input type="number" name="harga_jual" id="hargaJual"
                   value="<?= (float)($edit['harga_jual'] ?? 0) ?>"
                   min="0" step="100" required>
          </div>
        </div>

        <!-- Preview margin -->
        <div class="margin-info" id="marginInfo" style="display:none">
          <span>Margin: <strong id="marginText">Rp 0</strong></span>
        </div>

        <div class="form-grid" style="grid-template-columns:1fr 1fr;margin-bottom:12px">
          <div class="form-group">
            <label>Stok</label>
            <input type="number" name="stok" value="<?= (int)($edit['stok'] ?? 0) ?>" min="0">
          </div>
          <div class="form-group">
            <label>Satuan</label>
            <input type="text" name="satuan" value="<?= e($edit['satuan'] ?? 'pcs') ?>" placeholder="pcs / kg / dus">
          </div>
        </div>

        <!-- Upload gambar -->
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
      <form method="get" class="filter-inline">
        <input type="text" name="q" value="<?= e($q) ?>" placeholder="Cari produk...">
        <select name="kategori">
          <option value="">Semua Kategori</option>
          <?php foreach ($kategoris as $k): ?>
            <option value="<?= e($k) ?>" <?= $kategori_filter === $k ? 'selected' : '' ?>>
              <?= e($k) ?>
            </option>
          <?php endforeach; ?>
        </select>
        <button class="btn btn-outline btn-sm">Filter</button>
      </form>
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
        <?php else: foreach ($list as $p): ?>
          <tr>
            <td>
              <div class="thumb-wrap">
                <img src="<?= e(produkImage($p['gambar'])) ?>" alt="<?= e($p['nama']) ?>" class="thumb">
                <div>
                  <div class="strong"><?= e($p['nama']) ?></div>
                  <small class="text-muted"><?= e($p['kode']) ?> · <?= e($p['kategori'] ?: '-') ?></small>
                </div>
              </div>
            </td>
            <td class="text-right">
              <div class="strong"><?= rupiah($p['harga_jual']) ?></div>
              <small class="text-muted">Beli: <?= rupiah($p['harga_beli']) ?></small>
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

    // Validasi tipe
    if (!file.type.startsWith('image/')) {
      alert('File harus berupa gambar.');
      this.value = '';
      return;
    }
    // Validasi ukuran (max 2MB)
    if (file.size > 2 * 1024 * 1024) {
      alert('Ukuran gambar maksimal 2MB.');
      this.value = '';
      return;
    }

    const reader = new FileReader();
    reader.onload = e => {
      previewImg.src = e.target.result;
    };
    reader.readAsDataURL(file);

    // Reset flag hapus gambar
    const flag = document.getElementById('hapusGambarFlag');
    if (flag) flag.value = '0';
  });
}

/* ============================================================
   HAPUS GAMBAR (UI)
   ============================================================ */
function hapusGambarUI() {
  if (!confirm('Hapus gambar produk ini?')) return;

  // Ganti preview jadi placeholder
  previewImg.src = 'data:image/svg+xml;utf8,' + encodeURIComponent(
    '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100">' +
    '<rect width="100" height="100" fill="#ecfdf5"/>' +
    '<path d="M25 65l15-20 12 15 8-10 15 15H25z" fill="#10b981"/>' +
    '<circle cx="35" cy="35" r="7" fill="#d4af37"/></svg>'
  );

  const flag = document.getElementById('hapusGambarFlag');
  if (flag) flag.value = '1';

  // Reset file input
  if (inputGambar) inputGambar.value = '';
}

/* ============================================================
   HITUNG MARGIN OTOMATIS
   ============================================================ */
const hb = document.getElementById('hargaBeli');
const hj = document.getElementById('hargaJual');
const marginInfo = document.getElementById('marginInfo');
const marginText = document.getElementById('marginText');

function hitungMargin() {
  const beli = parseFloat(hb.value) || 0;
  const jual = parseFloat(hj.value) || 0;

  if (beli > 0 && jual > 0) {
    const margin = jual - beli;
    const persen = (margin / beli * 100).toFixed(1);

    marginText.textContent = 'Rp ' + margin.toLocaleString('id-ID') +
                             ' (' + persen + '%)';
    marginText.style.color = margin < 0 ? '#dc2626' : '#047857';
    marginInfo.style.display = 'block';
  } else {
    marginInfo.style.display = 'none';
  }
}

if (hb && hj) {
  hb.addEventListener('input', hitungMargin);
  hj.addEventListener('input', hitungMargin);
  hitungMargin(); // hitung saat load (kalau edit)
}
</script>

<?php require_once 'includes/footer.php'; ?>