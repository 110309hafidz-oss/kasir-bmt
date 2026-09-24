<?php

function e($str) {
    return htmlspecialchars((string)$str, ENT_QUOTES, 'UTF-8');
}

function rupiah($angka) {
    return 'Rp ' . number_format((float)$angka, 0, ',', '.');
}

function isLogin() {
    return isset($_SESSION['user_id']);
}

function requireLogin() {
    if (!isLogin()) {
        header('Location: login.php');
        exit;
    }
}

function redirect($url) {
    header("Location: $url");
    exit;
}

function flash($key, $msg = null) {
    if ($msg === null) {
        $v = $_SESSION['flash'][$key] ?? null;
        unset($_SESSION['flash'][$key]);
        return $v;
    }
    $_SESSION['flash'][$key] = $msg;
}

function user() {
    return [
        'id'   => $_SESSION['user_id'] ?? null,
        'nama' => $_SESSION['user_nama'] ?? 'Guest',
        'role' => $_SESSION['user_role'] ?? 'kasir',
    ];
}

function generateKode($prefix, $pdo, $table, $field) {
    $tgl = date('Ymd');
    do {
        $kode = $prefix . $tgl . str_pad((string)random_int(1, 9999), 4, '0', STR_PAD_LEFT);
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM {$table} WHERE {$field} = ?");
        $stmt->execute([$kode]);
    } while ((int)$stmt->fetchColumn() > 0);
    return $kode;
}

function tanggalIndo($datetime) {
    $bulan = [1=>'Jan','Feb','Mar','Apr','Mei','Jun','Jul','Agu','Sep','Okt','Nov','Des'];
    $ts = strtotime($datetime);
    return date('d', $ts) . ' ' . $bulan[(int)date('n', $ts)] . ' ' . date('Y H:i', $ts);
}

/* ============================================================
   HELPER GAMBAR PRODUK
   ============================================================ */

/**
 * Mengembalikan path gambar produk.
 * Jika file tidak ada, kembalikan placeholder SVG inline.
 */
function produkImage($gambar) {
    $gambar = trim((string)$gambar);

    if ($gambar !== '') {
        $path = 'uploads/produk/' . $gambar;
        if (file_exists(__DIR__ . '/../' . $path)) {
            return $path;
        }
    }

    // Placeholder SVG (data URI)
    $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100">'
         . '<rect width="100" height="100" fill="#ecfdf5"/>'
         . '<path d="M25 65l15-20 12 15 8-10 15 15H25z" fill="#10b981"/>'
         . '<circle cx="35" cy="35" r="7" fill="#d4af37"/>'
         . '</svg>';

    return 'data:image/svg+xml;utf8,' . rawurlencode($svg);
}

/**
 * Upload gambar produk.
 *
 * @param  array       $file     $_FILES['gambar']
 * @param  string|null $oldFile  Nama file lama (untuk dihapus jika ada file baru)
 * @return string|null           Nama file baru, atau file lama jika gagal
 */
function uploadGambar($file, $oldFile = null) {
    // Tidak ada file di-upload
    if (empty($file) || empty($file['name']) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        return $oldFile;
    }

    $allowed = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
    $ext     = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

    // Validasi ekstensi
    if (!in_array($ext, $allowed, true)) {
        return $oldFile;
    }

    // Validasi ukuran (maks 2MB)
    if (($file['size'] ?? 0) > 2 * 1024 * 1024) {
        return $oldFile;
    }

    // Pastikan folder tujuan ada
    $dir = __DIR__ . '/../uploads/produk/';
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }

    if (!is_writable($dir)) {
        return $oldFile;
    }

    // Nama file baru yang unik
    $namaBaru = 'prd_' . date('YmdHis') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;

    if (move_uploaded_file($file['tmp_name'], $dir . $namaBaru)) {
        // Hapus gambar lama
        if ($oldFile && file_exists($dir . $oldFile)) {
            @unlink($dir . $oldFile);
        }
        return $namaBaru;
    }

    return $oldFile;
}

/**
 * Hapus file gambar produk dari folder uploads.
 */
function hapusGambar($gambar) {
    if (empty($gambar)) return;
    $file = __DIR__ . '/../uploads/produk/' . $gambar;
    if (file_exists($file)) {
        @unlink($file);
    }
}