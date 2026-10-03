<?php
require_once 'config/database.php';
requireLogin();

/* ============================================================
   AKSI: BAYAR HUTANG KONSUMEN (LUNAS / SEBAGIAN)
   ============================================================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['act'] ?? '') === 'bayar') {
    $nama       = trim($_POST['nama'] ?? '');
    $nominal    = (float)($_POST['nominal'] ?? 0);
    $keterangan = trim($_POST['keterangan'] ?? '');

    if ($nama === '') {
        flash('error', 'Nama konsumen tidak valid.');
        redirect('hutang.php');
    }

    try {
        $pdo->beginTransaction();

        // Ambil hanya hutang yang BELUM LUNAS (sisa > 0)
        $stmt = $pdo->prepare("
            SELECT h.*, p.nama AS nama_produk
            FROM hutang h
            JOIN produk p ON p.id = h.produk_id
            WHERE h.nama = ? AND h.sisa > 0
            ORDER BY h.id ASC
            FOR UPDATE
        ");
        $stmt->execute([$nama]);
        $items = $stmt->fetchAll();

        if (!$items) throw new Exception('Tidak ada sisa hutang atas nama ' . $nama . '.');

        // Total sisa hutang
        $totalSisa = 0;
        foreach ($items as $it) {
            $totalSisa += (float)$it['sisa'];
        }
        $totalSisa = round($totalSisa, 2);

        if ($totalSisa <= 0) {
            throw new Exception('Hutang ' . $nama . ' sudah lunas.');
        }

        // Berapa yang dibayar
        if ($nominal <= 0 || $nominal >= $totalSisa) {
            $bayar = $totalSisa;
        } else {
            $bayar = $nominal;
        }

        $isLunas = ($bayar >= $totalSisa);

        // Buat transaksi penjualan
        $kode = generateKode('TRX', $pdo, 'transaksi', 'kode');
        $ket  = $isLunas
                ? 'Pelunasan hutang dari ' . $nama
                : 'Cicilan hutang dari ' . $nama;

        $pdo->prepare("
            INSERT INTO transaksi (kode, user_id, jenis, total, bayar, kembalian, keterangan, tanggal)
            VALUES (?, ?, 'penjualan', ?, ?, 0, ?, NOW())
        ")->execute([
            $kode,
            user()['id'],
            $bayar,
            $bayar,
            $ket . ($keterangan ? ' - ' . $keterangan : '')
        ]);
        $trxId = (int)$pdo->lastInsertId();

        $insDet = $pdo->prepare("
            INSERT INTO transaksi_detail (transaksi_id, produk_id, qty, harga, subtotal)
            VALUES (?, ?, ?, ?, ?)
        ");

        // Proses pembayaran per item (dari item terlama)
        $sisaBayar = $bayar;

        foreach ($items as $it) {
            if ($sisaBayar <= 0) break;

            $sisaItem = (float)$it['sisa'];
            if ($sisaItem <= 0) continue;

            if ($sisaBayar >= $sisaItem) {
                // Item ini lunas penuh
                $insDet->execute([$trxId, $it['produk_id'], $it['qty'], $it['harga'], $sisaItem]);
                $sisaBayar -= $sisaItem;

                $pdo->prepare("UPDATE hutang SET dibayar = harga * qty, sisa = 0 WHERE id = ?")
                    ->execute([$it['id']]);
            } else {
                // Item ini sebagian — potong dari sisa
                $insDet->execute([$trxId, $it['produk_id'], $it['qty'], $it['harga'], $sisaBayar]);

                $newSisa    = $sisaItem - $sisaBayar;
                $newDibayar = (float)$it['dibayar'] + $sisaBayar;

                $pdo->prepare("UPDATE hutang SET dibayar = ?, sisa = ? WHERE id = ?")
                    ->execute([$newDibayar, $newSisa, $it['id']]);

                $sisaBayar = 0;
            }
        }

        $pdo->commit();

        if ($isLunas) {
            flash('success', 'Hutang ' . $nama . ' sebesar ' . rupiah($totalSisa) . ' LUNAS. Transaksi ' . $kode . ' tercatat.');
        } else {
            $sisaAkhir = $totalSisa - $bayar;
            flash('success', 'Cicilan ' . rupiah($bayar) . ' dari ' . $nama . ' tercatat. Sisa hutang: ' . rupiah($sisaAkhir) . '.');
        }
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        flash('error', 'Gagal: ' . $e->getMessage());
    }

    redirect('hutang.php' . (!empty($_POST['redirect_nama']) ? '?nama=' . urlencode($_POST['redirect_nama']) : ''));
}

/* ============================================================
   AKSI: BAYAR 1 ITEM HUTANG
   ============================================================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['act'] ?? '') === 'bayar_item') {
    $id = (int)($_POST['id'] ?? 0);
    if ($id > 0) {
        try {
            $pdo->beginTransaction();

            $stmt = $pdo->prepare("
                SELECT h.*, p.nama AS nama_produk
                FROM hutang h
                JOIN produk p ON p.id = h.produk_id
                WHERE h.id = ?
                FOR UPDATE
            ");
            $stmt->execute([$id]);
            $h = $stmt->fetch();

            if (!$h) throw new Exception('Hutang tidak ditemukan.');

            $sisaItem = (float)$h['sisa'];

            if ($sisaItem <= 0) {
                throw new Exception('Item ini sudah lunas.');
            }

            $kode = generateKode('TRX', $pdo, 'transaksi', 'kode');

            $pdo->prepare("
                INSERT INTO transaksi (kode, user_id, jenis, total, bayar, kembalian, keterangan, tanggal)
                VALUES (?, ?, 'penjualan', ?, ?, 0, ?, NOW())
            ")->execute([
                $kode,
                user()['id'],
                $sisaItem,
                $sisaItem,
                'Pelunasan hutang item: ' . $h['nama_produk'] . ' (' . $h['nama'] . ')'
            ]);
            $trxId = (int)$pdo->lastInsertId();

            $pdo->prepare("
                INSERT INTO transaksi_detail (transaksi_id, produk_id, qty, harga, subtotal)
                VALUES (?, ?, ?, ?, ?)
            ")->execute([$trxId, $h['produk_id'], $h['qty'], $h['harga'], $sisaItem]);

            $pdo->prepare("UPDATE hutang SET dibayar = harga * qty, sisa = 0 WHERE id = ?")->execute([$id]);

            $pdo->commit();
            flash('success', 'Item "' . $h['nama_produk'] . '" atas nama ' . $h['nama'] . ' LUNAS. Transaksi ' . $kode . ' tercatat.');
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            flash('error', 'Gagal: ' . $e->getMessage());
        }
    }
    redirect('hutang.php' . (!empty($_POST['redirect_nama']) ? '?nama=' . urlencode($_POST['redirect_nama']) : ''));
}

/* ============================================================
   AKSI: HAPUS 1 ITEM HUTANG
   ============================================================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['act'] ?? '') === 'hapus_item') {
    $id = (int)($_POST['id'] ?? 0);
    if ($id > 0) {
        try {
            $pdo->prepare("DELETE FROM hutang WHERE id = ?")->execute([$id]);
            flash('success', 'Item hutang berhasil dihapus.');
        } catch (PDOException $e) {
            flash('error', 'Gagal menghapus: ' . $e->getMessage());
        }
    }
    redirect('hutang.php' . (!empty($_POST['redirect_nama']) ? '?nama=' . urlencode($_POST['redirect_nama']) : ''));
}

/* ============================================================
   AKSI: HAPUS SEMUA HUTANG KONSUMEN
   ============================================================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['act'] ?? '') === 'hapus_konsumen') {
    $nama = trim($_POST['nama'] ?? '');
    if ($nama !== '') {
        try {
            $pdo->prepare("DELETE FROM hutang WHERE nama = ?")->execute([$nama]);
            flash('success', 'Semua hutang atas nama ' . $nama . ' dihapus.');
        } catch (PDOException $e) {
            flash('error', 'Gagal menghapus: ' . $e->getMessage());
        }
    }
    redirect('hutang.php');
}

/* ============================================================
   DATA
   ============================================================ */
$namaFilter = trim($_GET['nama'] ?? '');
$statusFilter = $_GET['status'] ?? 'belum'; // 'belum', 'lunas', 'semua'

// Rekap per konsumen — semua yang masih ada di tabel hutang
$rekapKonsumen = $pdo->query("
    SELECT nama,
           COUNT(*)              AS jml_item,
           SUM(qty)              AS total_qty,
           SUM(harga * qty)      AS total_belanja,
           SUM(dibayar)          AS total_dibayar,
           SUM(sisa)             AS total_sisa,
           MIN(tanggal)          AS tgl_awal,
           MAX(tanggal)          AS tgl_akhir
    FROM hutang
    GROUP BY nama
    ORDER BY 
      CASE WHEN SUM(sisa) > 0 THEN 0 ELSE 1 END,
      MAX(tanggal) DESC,
      nama ASC
")->fetchAll();

$grandTotalSisa = 0;
$grandTotalDibayar = 0;
foreach ($rekapKonsumen as $r) {
    $grandTotalSisa    += (float)$r['total_sisa'];
    $grandTotalDibayar += (float)$r['total_dibayar'];
}

// Detail hutang
if ($namaFilter !== '') {
    $stmt = $pdo->prepare("
        SELECT h.*, p.nama AS nama_produk, p.kode AS kode_produk, p.satuan
        FROM hutang h
        JOIN produk p ON p.id = h.produk_id
        WHERE h.nama = ?
        ORDER BY h.sisa > 0 DESC, h.id DESC
    ");
    $stmt->execute([$namaFilter]);
    $detailHutang = $stmt->fetchAll();
} else {
    // Filter status
    if ($statusFilter === 'belum') {
        $stmt = $pdo->query("
            SELECT h.*, p.nama AS nama_produk, p.kode AS kode_produk, p.satuan
            FROM hutang h
            JOIN produk p ON p.id = h.produk_id
            WHERE h.sisa > 0
            ORDER BY h.nama ASC, h.id DESC
        ");
    } elseif ($statusFilter === 'lunas') {
        $stmt = $pdo->query("
            SELECT h.*, p.nama AS nama_produk, p.kode AS kode_produk, p.satuan
            FROM hutang h
            JOIN produk p ON p.id = h.produk_id
            WHERE h.sisa <= 0
            ORDER BY h.nama ASC, h.id DESC
        ");
    } else {
        $stmt = $pdo->query("
            SELECT h.*, p.nama AS nama_produk, p.kode AS kode_produk, p.satuan
            FROM hutang h
            JOIN produk p ON p.id = h.produk_id
            ORDER BY h.sisa > 0 DESC, h.nama ASC, h.id DESC
        ");
    }
    $detailHutang = $stmt->fetchAll();
}

$totalFilter = 0;
foreach ($detailHutang as $d) $totalFilter += (float)$d['sisa'];

$pageTitle    = 'Hutang';
$pageSubtitle = 'Daftar & pelunasan hutang konsumen';
require_once 'includes/header.php';
?>

<!-- ===== STATS ===== -->
<div class="stats">
  <div class="stat">
    <div class="stat-label">Konsumen Berhutang</div>
    <div class="stat-value">
      <?php
        $jmlBelum = 0;
        foreach ($rekapKonsumen as $r) if ((float)$r['total_sisa'] > 0) $jmlBelum++;
        echo $jmlBelum;
      ?>
    </div>
    <div class="stat-sub">dari <?= count($rekapKonsumen) ?> total konsumen</div>
  </div>
  <div class="stat">
    <div class="stat-label">Total Sisa Hutang</div>
    <div class="stat-value text-danger"><?= rupiah($grandTotalSisa) ?></div>
    <div class="stat-sub">belum lunas</div>
  </div>
  <div class="stat">
    <div class="stat-label">Total Sudah Dibayar</div>
    <div class="stat-value text-green"><?= rupiah($grandTotalDibayar) ?></div>
    <div class="stat-sub">akumulasi DP & cicilan</div>
  </div>
  <div class="stat">
    <div class="stat-label">Status</div>
    <div class="stat-value">
      <?php if ($grandTotalSisa > 0): ?>
        <span class="tag tag-red">BELUM LUNAS</span>
      <?php else: ?>
        <span class="tag tag-green">SEMUA LUNAS</span>
      <?php endif; ?>
    </div>
    <div class="stat-sub">kondisi saat ini</div>
  </div>
</div>

<?php if ($namaFilter && $detailHutang): ?>
<?php
  // Hitung total sisa & total belanja untuk filter nama
  $totalSisaFilter = 0;
  $totalBelanjaFilter = 0;
  foreach ($detailHutang as $d) {
    $totalSisaFilter    += (float)$d['sisa'];
    $totalBelanjaFilter += (float)$d['harga'] * (int)$d['qty'];
  }
  $adaSisa = ($totalSisaFilter > 0);
?>
<!-- ===== PANEL PELUNASAN ===== -->
<div class="card" style="border: 2px solid <?= $adaSisa ? 'var(--g500)' : '#94a3b8' ?>; background: linear-gradient(135deg, <?= $adaSisa ? '#ecfdf5' : '#f1f5f9' ?> 0%, #ffffff 100%);">
  <div class="card-head" style="background: transparent; border-bottom: 1px dashed <?= $adaSisa ? 'var(--g300)' : '#cbd5e1' ?>;">
    <h2>
      <?= $adaSisa ? '💳 Pelunasan Hutang: ' : '✅ Hutang LUNAS: ' ?>
      <span style="color:var(--g700)"><?= e($namaFilter) ?></span>
    </h2>
    <?php if ($adaSisa): ?>
      <span class="tag tag-red" style="font-size:13px">Sisa Hutang: <?= rupiah($totalSisaFilter) ?></span>
    <?php else: ?>
      <span class="tag tag-green" style="font-size:13px">✓ SUDAH LUNAS</span>
    <?php endif; ?>
  </div>
  <div class="card-body">

    <!-- Info Ringkasan -->
    <div style="background:#fff; border:1px solid var(--border); border-radius:10px; padding:14px 16px; margin-bottom:16px; display:flex; gap:24px; flex-wrap:wrap;">
      <div>
        <div style="font-size:11px; color:#64748b; text-transform:uppercase; letter-spacing:.5px;">Total Belanja</div>
        <div style="font-size:16px; font-weight:700; color:#1e293b;"><?= rupiah($totalBelanjaFilter) ?></div>
      </div>
      <div>
        <div style="font-size:11px; color:#64748b; text-transform:uppercase; letter-spacing:.5px;">Sudah Dibayar</div>
        <div style="font-size:16px; font-weight:700; color:#10b981;"><?= rupiah($totalBelanjaFilter - $totalSisaFilter) ?></div>
      </div>
      <div>
        <div style="font-size:11px; color:#64748b; text-transform:uppercase; letter-spacing:.5px;">Sisa Hutang</div>
        <div style="font-size:16px; font-weight:700; color:<?= $adaSisa ? '#dc2626' : '#10b981' ?>;">
          <?= $adaSisa ? rupiah($totalSisaFilter) : '✓ LUNAS' ?>
        </div>
      </div>
    </div>

    <?php if ($adaSisa): ?>
    <div style="display:grid; grid-template-columns: 1fr 1fr; gap:16px; align-items:start;">

      <div style="background:#fff; border:1px solid var(--border); border-radius:10px; padding:16px;">
        <h3 style="margin:0 0 8px; font-size:14px; color:var(--g700)">✓ Bayar LUNAS (Full)</h3>
        <p style="margin:0 0 12px; font-size:12px; color:#64748b">
          Lunasi <strong>semua</strong> sisa hutang <?= e($namaFilter) ?> sebesar <strong><?= rupiah($totalSisaFilter) ?></strong>.
        </p>
        <form method="post"
              onsubmit="return confirm('Lunasi SEMUA hutang <?= e($namaFilter) ?>?\n\nTotal: <?= rupiah($totalSisaFilter) ?>')">
          <input type="hidden" name="act" value="bayar">
          <input type="hidden" name="nama" value="<?= e($namaFilter) ?>">
          <input type="hidden" name="nominal" value="0">
          <input type="hidden" name="redirect_nama" value="<?= e($namaFilter) ?>">
          <button class="btn btn-primary btn-block" style="height:44px; font-size:15px">
            ✓ LUNAS — <?= rupiah($totalSisaFilter) ?>
          </button>
        </form>
      </div>

      <div style="background:#fff; border:1px solid var(--border); border-radius:10px; padding:16px;">
        <h3 style="margin:0 0 8px; font-size:14px; color:#d97706">💰 Bayar Sebagian (Cicilan)</h3>
        <p style="margin:0 0 12px; font-size:12px; color:#64748b">
          Masukkan nominal uang yang dibayar. Sisa akan tetap dicatat.
        </p>
        <form method="post"
              onsubmit="return confirm('Catat cicilan untuk <?= e($namaFilter) ?>?')">
          <input type="hidden" name="act" value="bayar">
          <input type="hidden" name="nama" value="<?= e($namaFilter) ?>">
          <input type="hidden" name="redirect_nama" value="<?= e($namaFilter) ?>">
          <div class="form-group" style="margin-bottom:10px">
            <label>Nominal Bayar <span class="req">*</span></label>
            <input type="number" name="nominal"
                   min="100" max="<?= (int)$totalSisaFilter ?>"
                   step="100" placeholder="0" required
                   style="font-size:15px; font-weight:600">
          </div>
          <div class="form-group" style="margin-bottom:10px">
            <label>Keterangan</label>
            <input type="text" name="keterangan" placeholder="Opsional...">
          </div>
          <button class="btn btn-outline btn-block" style="height:44px; border-color:#d97706; color:#d97706">
            💰 Bayar Cicilan
          </button>
        </form>
      </div>

    </div>
    <?php else: ?>
      <div style="text-align:center; padding:20px; color:#10b981; font-weight:600; font-size:15px;">
        🎉 Semua hutang <?= e($namaFilter) ?> sudah LUNAS!
      </div>
    <?php endif; ?>

    <div style="margin-top:16px; padding-top:16px; border-top:1px dashed var(--border); display:flex; gap:8px; justify-content:flex-end;">
      <a href="hutang.php" class="btn btn-outline btn-sm">← Kembali ke Semua</a>
      <form method="post" style="display:inline"
            onsubmit="return confirm('Hapus SEMUA riwayat hutang <?= e($namaFilter) ?>?\n\nData hilang permanen.')">
        <input type="hidden" name="act" value="hapus_konsumen">
        <input type="hidden" name="nama" value="<?= e($namaFilter) ?>">
        <button class="btn btn-danger btn-sm">🗑 Hapus Riwayat <?= e($namaFilter) ?></button>
      </form>
    </div>
  </div>
</div>
<?php endif; ?>

<!-- ===== REKAP PER KONSUMEN ===== -->
<div class="card">
  <div class="card-head">
    <h2>👥 Rekap Hutang per Konsumen</h2>
    <?php if ($grandTotalSisa > 0): ?>
      <span class="tag tag-red">Sisa Total: <?= rupiah($grandTotalSisa) ?></span>
    <?php else: ?>
      <span class="tag tag-green">Semua Lunas 🎉</span>
    <?php endif; ?>
  </div>
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
      <?php if (!$rekapKonsumen): ?>
        <tr><td colspan="7" class="empty">Tidak ada data hutang. 🎉</td></tr>
      <?php else: foreach ($rekapKonsumen as $r):
        $sisa   = (float)$r['total_sisa'];
        $lunas  = ($sisa <= 0);
      ?>
        <tr <?= $lunas ? 'style="background:#f0fdf4"' : '' ?>>
          <td class="strong"><?= e($r['nama']) ?></td>
          <td class="text-center"><?= (int)$r['jml_item'] ?> produk</td>
          <td class="text-right"><?= rupiah($r['total_belanja']) ?></td>
          <td class="text-right text-green"><?= rupiah($r['total_dibayar']) ?></td>
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
          <td class="text-center" style="white-space:nowrap">
            <a href="hutang.php?nama=<?= urlencode($r['nama']) ?>"
               class="btn btn-outline btn-sm">🔍 Detail</a>
            <?php if (!$lunas): ?>
              <form method="post" style="display:inline"
                    onsubmit="return confirm('Tandai hutang <?= e($r['nama']) ?> LUNAS?\n\nSisa: <?= rupiah($sisa) ?>')">
                <input type="hidden" name="act" value="bayar">
                <input type="hidden" name="nama" value="<?= e($r['nama']) ?>">
                <input type="hidden" name="nominal" value="0">
                <button class="btn btn-primary btn-sm">✓ Lunas</button>
              </form>
            <?php endif; ?>
            <form method="post" style="display:inline"
                  onsubmit="return confirm('Hapus SEMUA riwayat <?= e($r['nama']) ?>?')">
              <input type="hidden" name="act" value="hapus_konsumen">
              <input type="hidden" name="nama" value="<?= e($r['nama']) ?>">
              <button class="btn btn-danger btn-sm">🗑</button>
            </form>
          </td>
        </tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- ===== DETAIL HUTANG ===== -->
<div class="card">
  <div class="card-head">
    <h2>
      📋 Detail Hutang
      <?php if ($namaFilter): ?>
        — <span style="color:var(--g700)"><?= e($namaFilter) ?></span>
      <?php endif; ?>
    </h2>
    <div style="display:flex; gap:6px;">
      <?php if ($namaFilter): ?>
        <a href="hutang.php" class="btn btn-outline btn-sm">← Tampilkan Semua</a>
      <?php else: ?>
        <a href="hutang.php?status=belum" class="btn btn-sm <?= $statusFilter === 'belum' ? 'btn-primary' : 'btn-outline' ?>">Belum Lunas</a>
        <a href="hutang.php?status=lunas" class="btn btn-sm <?= $statusFilter === 'lunas' ? 'btn-primary' : 'btn-outline' ?>">Lunas</a>
        <a href="hutang.php?status=semua" class="btn btn-sm <?= $statusFilter === 'semua' ? 'btn-primary' : 'btn-outline' ?>">Semua</a>
      <?php endif; ?>
    </div>
  </div>
  <div class="table-wrap">
    <table>
      <thead>
        <tr>
          <th>Nama</th>
          <th>Kode</th>
          <th>Produk</th>
          <th class="text-center">Qty</th>
          <th class="text-right">Harga</th>
          <th class="text-right">Total</th>
          <th class="text-right">Dibayar</th>
          <th class="text-right">Sisa</th>
          <th class="text-center">Status</th>
          <th class="text-center">Aksi</th>
        </tr>
      </thead>
      <tbody>
      <?php if (!$detailHutang): ?>
        <tr><td colspan="10" class="empty">Tidak ada data hutang.</td></tr>
      <?php else: foreach ($detailHutang as $d):
        $subtotal  = (float)$d['harga'] * (int)$d['qty'];
        $dibayar   = (float)$d['dibayar'];
        $sisa      = (float)$d['sisa'];
        $isLunas   = ($sisa <= 0);
      ?>
        <tr <?= $isLunas ? 'style="background:#f0fdf4;opacity:.85"' : '' ?>>
          <td class="strong"><?= e($d['nama']) ?></td>
          <td><span class="tag tag-gray"><?= e($d['kode_produk']) ?></span></td>
          <td><?= e($d['nama_produk']) ?></td>
          <td class="text-center"><?= (int)$d['qty'] ?> <?= e($d['satuan']) ?></td>
          <td class="text-right"><?= rupiah($d['harga']) ?></td>
          <td class="text-right"><?= rupiah($subtotal) ?></td>
          <td class="text-right text-green"><?= rupiah($dibayar) ?></td>
          <td class="text-right strong <?= $isLunas ? 'text-green' : 'text-danger' ?>">
            <?= $isLunas ? 'Rp 0' : rupiah($sisa) ?>
          </td>
          <td class="text-center">
            <?php if ($isLunas): ?>
              <span class="tag tag-green">✓ LUNAS</span>
            <?php else: ?>
              <span class="tag tag-red">BELUM</span>
            <?php endif; ?>
          </td>
          <td class="text-center" style="white-space:nowrap">
            <?php if (!$isLunas): ?>
              <form method="post" style="display:inline"
                    onsubmit="return confirm('Lunasi item ini?\n\n<?= e($d['nama_produk']) ?> — Sisa: <?= rupiah($sisa) ?>')">
                <input type="hidden" name="act" value="bayar_item">
                <input type="hidden" name="id" value="<?= (int)$d['id'] ?>">
                <input type="hidden" name="redirect_nama" value="<?= e($namaFilter) ?>">
                <button class="btn btn-primary btn-sm">✓ Bayar</button>
              </form>
            <?php endif; ?>
            <form method="post" style="display:inline"
                  onsubmit="return confirm('Hapus item hutang ini?')">
              <input type="hidden" name="act" value="hapus_item">
              <input type="hidden" name="id" value="<?= (int)$d['id'] ?>">
              <input type="hidden" name="redirect_nama" value="<?= e($namaFilter) ?>">
              <button class="btn btn-danger btn-sm">🗑</button>
            </form>
          </td>
        </tr>
      <?php endforeach; endif; ?>
      </tbody>
      <?php if ($detailHutang): ?>
      <tfoot>
        <tr style="background:var(--g25);border-top:2px solid var(--border)">
          <td colspan="7" class="text-right"><strong>TOTAL SISA</strong></td>
          <td class="text-right strong text-danger"><?= rupiah($totalFilter) ?></td>
          <td colspan="2"></td>
        </tr>
      </tfoot>
      <?php endif; ?>
    </table>
  </div>
</div>

<?php require_once 'includes/footer.php'; ?>