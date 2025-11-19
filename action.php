<?php
    // Start output buffering untuk mencegah output sebelum JSON
    ob_start();

    // Disable error display untuk mencegah output error sebelum JSON
    ini_set('display_errors', 0);
    ini_set('log_errors', 1);
    error_reporting(E_ALL);
    
    // Set error handler untuk menangkap semua error
    set_error_handler(function($errno, $errstr, $errfile, $errline) {
        // Log error tapi jangan output
        error_log("PHP Error [$errno]: $errstr in $errfile on line $errline");
        return true; // Suppress default error handler
    });

    session_start();

    // Periksa apakah sesi login ada
    if (!isset($_SESSION["ssLogin"])) {
        ob_clean();
        header("location:../../auth/login.php");
        ob_end_flush();
        exit();
    }

    require_once "../../config/config.php";
    require_once "../../config/functions.php";
    
    // Fungsi untuk mengecek apakah semua produk dari peminjaman sudah dikembalikan
    function checkIfAllProductsReturned($nomor_peminjaman, $pdo) {
        try {
            // Ambil jumlah dipinjam per produk dari peminjaman_stok
            $queryPeminjaman = $pdo->prepare("SELECT produk, SUM(qty) as jumlah_dipinjam 
                                             FROM peminjaman_stok 
                                             WHERE nomor_peminjaman = :nomor_peminjaman 
                                             GROUP BY produk");
            $queryPeminjaman->execute([":nomor_peminjaman" => $nomor_peminjaman]);
            $jumlahDipinjamPerProduk = [];
            while ($row = $queryPeminjaman->fetch(PDO::FETCH_ASSOC)) {
                $jumlahDipinjamPerProduk[$row['produk']] = floatval($row['jumlah_dipinjam']);
            }
            
            if (empty($jumlahDipinjamPerProduk)) {
                return false; // Tidak ada data peminjaman
            }
            
            // Ambil jumlah dikembalikan per produk dari pengembalian_stok
            // Hitung semua pengembalian dengan status Final atau Selesai (karena Selesai juga sudah final)
            $queryPengembalian = $pdo->prepare("SELECT produk, SUM(qty) as jumlah_dikembalikan 
                                               FROM pengembalian_stok 
                                               WHERE nomor_peminjaman = :nomor_peminjaman 
                                               AND (status_pengembalian = 'Final' OR status_pengembalian = 'Selesai')
                                               GROUP BY produk");
            $queryPengembalian->execute([":nomor_peminjaman" => $nomor_peminjaman]);
            $jumlahDikembalikanPerProduk = [];
            while ($row = $queryPengembalian->fetch(PDO::FETCH_ASSOC)) {
                $jumlahDikembalikanPerProduk[$row['produk']] = floatval($row['jumlah_dikembalikan']);
            }
            
            // Bandingkan jumlah dipinjam dengan jumlah dikembalikan
            $allMatch = true;
            $hasAnyData = false;
            
            foreach ($jumlahDipinjamPerProduk as $produk => $qtyDipinjam) {
                $hasAnyData = true;
                $jumlahDikembalikan = isset($jumlahDikembalikanPerProduk[$produk]) ? floatval($jumlahDikembalikanPerProduk[$produk]) : 0;
                $qtyDipinjamFloat = floatval($qtyDipinjam);
                
                // Gunakan toleransi untuk perbandingan floating point
                if (abs($jumlahDikembalikan - $qtyDipinjamFloat) >= 0.01) {
                    $allMatch = false;
                    break;
                }
            }
            
            return $hasAnyData && $allMatch;
        } catch (Exception $e) {
            error_log("Error checking if all products returned: " . $e->getMessage());
            return false;
        }
    }
    
    // Fungsi untuk update status pengembalian menjadi Selesai
    function updateStatusToSelesai($nomor_peminjaman, $pdo) {
        try {
            // Update semua record pengembalian dengan nomor_peminjaman yang sama
            $updateQuery = $pdo->prepare("UPDATE pengembalian_stok 
                                         SET status_pengembalian = 'Selesai' 
                                         WHERE nomor_peminjaman = :nomor_peminjaman 
                                         AND status_pengembalian = 'Final'");
            $updateQuery->execute([":nomor_peminjaman" => $nomor_peminjaman]);
            return true;
        } catch (Exception $e) {
            error_log("Error updating status to Selesai: " . $e->getMessage());
            return false;
        }
    }

    // Fungsi untuk generate nomor pengembalian berdasarkan gudang pengembali dan tanggal
    function generateNomorPengembalian($gudang_asal, $entitas_peminjam, $tanggal_pengembalian, $pdo) {
        global $db_dc;
        
        // Query untuk mendapatkan kode_tim dan tim dari base_tim berdasarkan gudang
        $query = "SELECT bt.kode_tim, bt.tim as nama_tim
                  FROM gudang_omni go
                  INNER JOIN base_tim bt ON bt.tim COLLATE utf8mb4_unicode_ci = go.tim COLLATE utf8mb4_unicode_ci
                  WHERE go.nama_gudang COLLATE utf8mb4_unicode_ci = ? COLLATE utf8mb4_unicode_ci
                  LIMIT 1";
        
        $stmt = mysqli_prepare($db_dc, $query);
        $nama_tim = '';
        if ($stmt) {
            mysqli_stmt_bind_param($stmt, "s", $gudang_asal);
            mysqli_stmt_execute($stmt);
            $result = mysqli_stmt_get_result($stmt);
            
            $code_tim = '';
            if ($row = mysqli_fetch_assoc($result)) {
                $code_tim = isset($row['kode_tim']) ? $row['kode_tim'] : '';
                $nama_tim = isset($row['nama_tim']) ? $row['nama_tim'] : '';
            }
            mysqli_stmt_close($stmt);
            
            // Jika code_tim kosong, gunakan 3 karakter pertama dari gudang
            if (empty($code_tim)) {
                $code_tim = strtoupper(substr($gudang_asal, 0, 3));
            }
            
            // Format tanggal untuk nomor pengembalian
            if (empty($tanggal_pengembalian)) {
                throw new Exception("Tanggal pengembalian tidak boleh kosong");
            }
            $dateObj = new DateTime($tanggal_pengembalian);
            $month = $dateObj->format('m');
            $year = $dateObj->format('y');
            $formattedDate = $month . $year;
            
            // Cari nomor pengembalian terakhir berdasarkan code_tim (tim) yang sama dan bulan
            $expectedPrefix = "PB/" . $code_tim . "/" . $formattedDate . "/";
            $nextNo = 1;
            
            $code_tim_normalized = str_replace([' ', '-'], '', $code_tim);
            $queryCount = "SELECT m.nomor_pengembalian,
                                  CAST(SUBSTRING_INDEX(m.nomor_pengembalian, '/', -1) AS UNSIGNED) as nomor_urut,
                                  SUBSTRING_INDEX(SUBSTRING_INDEX(m.nomor_pengembalian, '/', 2), '/', -1) as code_tim_from_nomor,
                                  SUBSTRING_INDEX(SUBSTRING_INDEX(m.nomor_pengembalian, '/', 3), '/', -1) as date_from_nomor
                          FROM pengembalian_stok m
                          INNER JOIN gudang_omni go ON go.nama_gudang COLLATE utf8mb4_unicode_ci = m.gudang_asal COLLATE utf8mb4_unicode_ci
                          INNER JOIN base_tim bt ON bt.tim COLLATE utf8mb4_unicode_ci = go.tim COLLATE utf8mb4_unicode_ci
                          WHERE bt.kode_tim = ?
                          AND m.tanggal_pengembalian >= DATE_FORMAT(?, '%Y-%m-01')
                          AND m.tanggal_pengembalian < DATE_ADD(DATE_FORMAT(?, '%Y-%m-01'), INTERVAL 1 MONTH)
                          AND m.nomor_pengembalian IS NOT NULL
                          AND m.nomor_pengembalian != ''
                          AND TRIM(m.nomor_pengembalian) != ''
                          AND m.nomor_pengembalian LIKE 'PB/%'
                          ORDER BY CAST(SUBSTRING_INDEX(m.nomor_pengembalian, '/', -1) AS UNSIGNED) DESC
                          LIMIT 1";
            
            $stmtCount = mysqli_prepare($db_dc, $queryCount);
            if ($stmtCount) {
                mysqli_stmt_bind_param($stmtCount, "sss", $code_tim, $tanggal_pengembalian, $tanggal_pengembalian);
                mysqli_stmt_execute($stmtCount);
                $resultCount = mysqli_stmt_get_result($stmtCount);
                
                if ($rowCount = mysqli_fetch_assoc($resultCount)) {
                    $nomor_urut = intval($rowCount['nomor_urut']);
                    $nextNo = $nomor_urut + 1;
                }
                mysqli_stmt_close($stmtCount);
            }
            
            // Format nomor pengembalian: PB/CODE_TIM/MMYY/XXX
            $nomor_pengembalian = "PB/" . $code_tim . "/" . $formattedDate . "/" . str_pad($nextNo, 3, '0', STR_PAD_LEFT);
            
            return $nomor_pengembalian;
        } else {
            // Fallback jika query gagal
            $code_tim = strtoupper(substr($gudang_asal, 0, 3));
            $dateObj = new DateTime($tanggal_pengembalian);
            $month = $dateObj->format('m');
            $year = $dateObj->format('y');
            $formattedDate = $month . $year;
            return "PB/" . $code_tim . "/" . $formattedDate . "/001";
        }
    }

    // Pastikan tabel pengembalian_stok menggunakan CHARACTER SET utf8mb4
    $tablesToCheck = ['pengembalian_stok', 'gudang_omni', 'base_tim'];
    foreach ($tablesToCheck as $tableName) {
        $checkTableQuery = "SELECT TABLE_COLLATION 
                            FROM information_schema.TABLES 
                            WHERE TABLE_SCHEMA = DATABASE() 
                            AND TABLE_NAME = ?";
        $stmt = mysqli_prepare($db_dc, $checkTableQuery);
        if ($stmt) {
            mysqli_stmt_bind_param($stmt, "s", $tableName);
            mysqli_stmt_execute($stmt);
            $tableResult = mysqli_stmt_get_result($stmt);
            if ($tableResult && $tableRow = mysqli_fetch_assoc($tableResult)) {
                $currentCollation = $tableRow['TABLE_COLLATION'];
                if ($currentCollation && strpos($currentCollation, 'utf8mb4') === false) {
                    $alterTableQuery = "ALTER TABLE `$tableName` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci";
                    @mysqli_query($db_dc, $alterTableQuery);
                }
            }
            mysqli_stmt_close($stmt);
        }
    }
    
    // Pastikan kolom tanggal_pengembalian bisa NULL untuk status Draft
    $checkTanggalQuery = "SHOW COLUMNS FROM `pengembalian_stok` WHERE Field = 'tanggal_pengembalian'";
    $checkTanggalResult = mysqli_query($db_dc, $checkTanggalQuery);
    if ($checkTanggalResult && $row = mysqli_fetch_assoc($checkTanggalResult)) {
        if (strtoupper($row['Null']) === 'NO') {
            $alterTanggalQuery = "ALTER TABLE `pengembalian_stok` MODIFY COLUMN `tanggal_pengembalian` DATE NULL";
            mysqli_query($db_dc, $alterTanggalQuery);
        }
    }
    
    // Pastikan kolom nomor_pengembalian bisa NULL untuk status Draft
    $checkNomorQuery = "SHOW COLUMNS FROM `pengembalian_stok` WHERE Field = 'nomor_pengembalian'";
    $checkNomorResult = mysqli_query($db_dc, $checkNomorQuery);
    if ($checkNomorResult && $row = mysqli_fetch_assoc($checkNomorResult)) {
        if (strtoupper($row['Null']) === 'NO') {
            // Dapatkan tipe data kolom untuk mempertahankan definisi yang sama
            $columnType = $row['Type'];
            $alterNomorQuery = "ALTER TABLE `pengembalian_stok` MODIFY COLUMN `nomor_pengembalian` $columnType NULL";
            mysqli_query($db_dc, $alterNomorQuery);
        }
    }
    
    // Tidak perlu kolom stok di tabel pengembalian_stok (sama seperti peminjaman)
    // Stok hanya diambil dari omni_stok_akhir saat ditampilkan di form

    // Clean output buffer sebelum mengirim JSON
    while (ob_get_level()) { ob_end_clean(); }
    // Atur header JSON
    header('Content-Type: application/json');

    if ($_SERVER["REQUEST_METHOD"] === "POST") {
        if ($_GET['act'] === "add") {
            // Handler untuk tambah data pengembalian - SISTEM BERBEDA DARI PEMINJAMAN
            try {
                while (ob_get_level()) { ob_end_clean(); }
                header('Content-Type: application/json');
                
                // Validasi input
                if (!isset($_POST['in_nomor_peminjaman']) || empty($_POST['in_nomor_peminjaman'])) {
                    throw new Exception("Nomor Peminjaman harus dipilih");
                }
                
                $nomor_peminjaman = trim($_POST['in_nomor_peminjaman']);
                $tanggal_pengembalian = isset($_POST['in_tanggal']) ? $_POST['in_tanggal'] : '';
                
                // Ambil dari hidden field
                $entitas_peminjam = isset($_POST['in_entitas_peminjam']) ? trim($_POST['in_entitas_peminjam']) : '';
                $entitas_dipinjam = isset($_POST['in_entitas_dipinjam']) ? trim($_POST['in_entitas_dipinjam']) : '';
                $gudang_asal = isset($_POST['in_gudang_asal']) ? trim($_POST['in_gudang_asal']) : '';
                $gudang_tujuan = isset($_POST['in_gudang_tujuan']) ? trim($_POST['in_gudang_tujuan']) : '';
                $action_button = isset($_POST['in_action_button']) ? $_POST['in_action_button'] : 'Draft';
                $status_pengembalian = $action_button;
                
                // Set status berdasarkan tombol yang diklik (sama seperti peminjaman)
                if ($action_button == "Draft") {
                    $status_pengembalian = "Draft";
                    $nomor_pengembalian = '';
                    $tanggal_pengembalian = null;
                } else {
                    $status_pengembalian = "Final";
                    if (empty($tanggal_pengembalian)) {
                        throw new Exception("Tanggal Pengembalian harus diisi untuk status Final");
                    }
                    // Generate nomor pengembalian untuk Final
                    $nomor_pengembalian = generateNomorPengembalian($gudang_asal, $entitas_peminjam, $tanggal_pengembalian, $pdo);
                }
                
                // Ambil produk dan jumlah dari form
                $produk_array = isset($_POST['in_produk']) ? $_POST['in_produk'] : [];
                $jumlah_kembali_array = isset($_POST['in_jumlah_kembali']) ? $_POST['in_jumlah_kembali'] : [];
                
                // Validasi minimal harus ada produk
                if (empty($produk_array) || !is_array($produk_array) || count($produk_array) === 0) {
                    throw new Exception("Minimal harus ada 1 produk yang dikembalikan");
                }
                
                // Validasi jumlah array harus sama
                if (count($produk_array) !== count($jumlah_kembali_array)) {
                    throw new Exception("Data produk dan jumlah tidak sesuai");
                }
                
                // Validasi semua jumlah harus > 0
                $hasValidQty = false;
                foreach ($jumlah_kembali_array as $qty) {
                    if (intval($qty) > 0) {
                        $hasValidQty = true;
                        break;
                    }
                }
                
                if (!$hasValidQty) {
                    throw new Exception("Minimal harus ada 1 produk dengan jumlah dikembalikan > 0");
                }
                
                // Mulai transaksi
                $pdo->beginTransaction();
                
                try {
                    // Insert data pengembalian untuk setiap produk (sama seperti peminjaman, tanpa kolom stok)
                    $insertQuery = $pdo->prepare("INSERT INTO pengembalian_stok 
                        (nomor_pengembalian, nomor_peminjaman, tanggal_pengembalian, entitas_peminjam, entitas_dipinjam, gudang_asal, gudang_tujuan, produk, qty, status_pengembalian, catatan, created_at) 
                        VALUES 
                        (:nomor_pengembalian, :nomor_peminjaman, :tanggal_pengembalian, :entitas_peminjam, :entitas_dipinjam, :gudang_asal, :gudang_tujuan, :produk, :qty, :status_pengembalian, :catatan, NOW())");
                    
                    for ($i = 0; $i < count($produk_array); $i++) {
                        $produk = trim($produk_array[$i]);
                        $qty = intval($jumlah_kembali_array[$i]);
                        
                        // Skip jika produk kosong atau qty <= 0
                        if (empty($produk) || $qty <= 0) {
                            continue;
                        }
                        
                        // Untuk Draft, tanggal dan nomor_pengembalian bisa NULL
                        $tanggal_insert = ($tanggal_pengembalian === null || $tanggal_pengembalian === '') ? null : $tanggal_pengembalian;
                        $nomor_pengembalian_insert = ($status_pengembalian === 'Draft' || empty($nomor_pengembalian)) ? null : $nomor_pengembalian;
                        
                        $insertQuery->execute([
                            ":nomor_pengembalian" => $nomor_pengembalian_insert,
                            ":nomor_peminjaman" => $nomor_peminjaman,
                            ":tanggal_pengembalian" => $tanggal_insert,
                            ":entitas_peminjam" => $entitas_peminjam ?: '',
                            ":entitas_dipinjam" => $entitas_dipinjam ?: '',
                            ":gudang_asal" => $gudang_asal,
                            ":gudang_tujuan" => $gudang_tujuan,
                            ":produk" => $produk,
                            ":qty" => $qty,
                            ":status_pengembalian" => $status_pengembalian,
                            ":catatan" => '' // Default empty untuk catatan
                        ]);
                    }
                    
                    // Commit transaksi
                    $pdo->commit();
                    
                    // Cek apakah semua produk sudah dikembalikan dan update status menjadi Selesai jika perlu
                    if ($status_pengembalian === 'Final' && !empty($nomor_peminjaman)) {
                        if (checkIfAllProductsReturned($nomor_peminjaman, $pdo)) {
                            updateStatusToSelesai($nomor_peminjaman, $pdo);
                        }
                    }
                    
                    $message = $status_pengembalian === 'Final' 
                        ? "Data pengembalian berhasil disimpan sebagai Final. Dokumen tidak dapat diedit lagi." 
                        : "Data pengembalian berhasil disimpan sebagai Draft. Anda masih dapat mengedit dokumen ini.";
                    
                    echo json_encode([
                        'status' => 'success',
                        'success' => true,
                        'message' => $message,
                        'nomor_pengembalian' => $nomor_pengembalian
                    ], JSON_UNESCAPED_UNICODE);
                    
                } catch (Exception $e) {
                    $pdo->rollBack();
                    throw new Exception("Error saat menyimpan data: " . $e->getMessage());
                }
                
            } catch (Exception $e) {
                // Log error untuk debugging
                error_log("Error in add pengembalian: " . $e->getMessage() . " | Trace: " . $e->getTraceAsString());
                
                while (ob_get_level()) { ob_end_clean(); }
                header('Content-Type: application/json');
                $errorMessage = $e->getMessage();
                // Pastikan error message tidak kosong
                if (empty($errorMessage)) {
                    $errorMessage = "Terjadi kesalahan saat menyimpan data. Silakan cek kembali data yang diinput.";
                }
                echo json_encode([
                    'status' => 'error',
                    'success' => false,
                    'message' => $errorMessage,
                    'error' => $errorMessage
                ], JSON_UNESCAPED_UNICODE);
            }
            exit();
        } elseif ($_GET['act'] === "delete") {
            // Handler untuk delete data pengembalian
            try {
                while (ob_get_level()) { ob_end_clean(); }
                header('Content-Type: application/json');
                
                // Ambil identifier dari POST
                $identifier = isset($_POST['identifier']) ? trim($_POST['identifier']) : '';
                
                if (empty($identifier)) {
                    throw new Exception("Identifier tidak ditemukan.");
                }
                
                // Mulai transaksi
                mysqli_begin_transaction($db_dc);
                
                try {
                    $ids = [];
                    $isDraft = false;
                    
                    // Cek apakah identifier adalah DRAFT-ID
                    if (strpos($identifier, 'DRAFT-ID-') === 0) {
                        $isDraft = true;
                        $draftId = str_replace('DRAFT-ID-', '', $identifier);
                        
                        // Query untuk mendapatkan semua id yang terkait dengan draft ini
                        $querySelect = "SELECT m1.id 
                                      FROM pengembalian_stok m1 
                                      INNER JOIN pengembalian_stok m2 ON 
                                          m1.gudang_asal = m2.gudang_asal 
                                          AND m1.gudang_tujuan = m2.gudang_tujuan 
                                          AND (m1.tanggal_pengembalian IS NULL AND m2.tanggal_pengembalian IS NULL 
                                               OR (m1.tanggal_pengembalian IS NOT NULL AND m2.tanggal_pengembalian IS NOT NULL 
                                                   AND m1.tanggal_pengembalian = m2.tanggal_pengembalian))
                                          AND m1.status_pengembalian = m2.status_pengembalian
                                      WHERE m2.id = ? 
                                        AND (m1.nomor_pengembalian IS NULL OR m1.nomor_pengembalian = '' OR TRIM(m1.nomor_pengembalian) = '') 
                                        AND m1.status_pengembalian = 'Draft'";
                        $stmtSelect = mysqli_prepare($db_dc, $querySelect);
                        mysqli_stmt_bind_param($stmtSelect, "i", $draftId);
                        mysqli_stmt_execute($stmtSelect);
                        $resultSelect = mysqli_stmt_get_result($stmtSelect);
                        
                        while ($qp = mysqli_fetch_assoc($resultSelect)) {
                            $ids[] = $qp['id'];
                        }
                        mysqli_stmt_close($stmtSelect);
                        
                        if (!empty($ids)) {
                            $idsString = implode(',', array_map('intval', $ids));
                            $queryDelete = "DELETE FROM pengembalian_stok WHERE id IN ($idsString)";
                            $executeDelete = mysqli_query($db_dc, $queryDelete);
                        } else {
                            throw new Exception("Data tidak ditemukan untuk dihapus.");
                        }
                    } else {
                        // Delete berdasarkan nomor_pengembalian atau nomor_peminjaman
                        // Untuk pengembalian, identifier bisa berupa:
                        // 1. nomor_pengembalian (untuk Final)
                        // 2. nomor_peminjaman (jika tidak ada nomor_pengembalian atau untuk mencari berdasarkan nomor peminjaman)
                        
                        // Coba cari berdasarkan nomor_pengembalian dulu (untuk Final)
                        $querySelect = "SELECT id FROM pengembalian_stok WHERE nomor_pengembalian = ? AND nomor_pengembalian IS NOT NULL AND nomor_pengembalian != ''";
                        $stmtSelect = mysqli_prepare($db_dc, $querySelect);
                        mysqli_stmt_bind_param($stmtSelect, "s", $identifier);
                        mysqli_stmt_execute($stmtSelect);
                        $resultSelect = mysqli_stmt_get_result($stmtSelect);
                        
                        $found = false;
                        while ($qp = mysqli_fetch_assoc($resultSelect)) {
                            $ids[] = $qp['id'];
                            $found = true;
                        }
                        mysqli_stmt_close($stmtSelect);
                        
                        // Jika tidak ditemukan berdasarkan nomor_pengembalian, coba berdasarkan nomor_peminjaman
                        if (!$found) {
                            $querySelect = "SELECT id FROM pengembalian_stok WHERE nomor_peminjaman = ?";
                            $stmtSelect = mysqli_prepare($db_dc, $querySelect);
                            mysqli_stmt_bind_param($stmtSelect, "s", $identifier);
                            mysqli_stmt_execute($stmtSelect);
                            $resultSelect = mysqli_stmt_get_result($stmtSelect);
                            
                            while ($qp = mysqli_fetch_assoc($resultSelect)) {
                                $ids[] = $qp['id'];
                            }
                            mysqli_stmt_close($stmtSelect);
                        }
                        
                        if (empty($ids)) {
                            throw new Exception("Data pengembalian tidak ditemukan dengan identifier: " . htmlspecialchars($identifier));
                        }
                        
                        // Delete berdasarkan id yang ditemukan
                        $idsString = implode(',', array_map('intval', $ids));
                        $queryDelete = "DELETE FROM pengembalian_stok WHERE id IN ($idsString)";
                        $executeDelete = mysqli_query($db_dc, $queryDelete);
                    }
                    
                    if (!$executeDelete) {
                        throw new Exception("Error saat menghapus Pengembalian: " . mysqli_error($db_dc));
                    }
                    
                    // Insert log hapus
                    if (!empty($ids)) {
                        $queryInsertLog = "INSERT INTO log_hapus (akun, id) VALUES (?, ?)";
                        $stmtInsertLog = mysqli_prepare($db_dc, $queryInsertLog);
                        $akun = "PENGEMBALIAN";
                        foreach ($ids as $id) {
                            mysqli_stmt_bind_param($stmtInsertLog, "si", $akun, $id);
                            mysqli_stmt_execute($stmtInsertLog);
                        }
                        mysqli_stmt_close($stmtInsertLog);
                    }
                    
                    mysqli_commit($db_dc);
                    
                    $message = $isDraft ? "Data draft pengembalian berhasil dihapus" : "Pengembalian berhasil dihapus.";
                    
                    echo json_encode([
                        'status' => 'success',
                        'message' => $message,
                        'is_draft' => $isDraft
                    ], JSON_UNESCAPED_UNICODE);
                    
                } catch (Exception $e) {
                    mysqli_rollback($db_dc);
                    throw $e;
                }
                
            } catch (Exception $e) {
                while (ob_get_level()) { ob_end_clean(); }
                header('Content-Type: application/json');
                echo json_encode([
                    'status' => 'error',
                    'message' => $e->getMessage()
                ], JSON_UNESCAPED_UNICODE);
            }
            exit();
        } elseif ($_GET['act'] === "update") {
            // Handler untuk update data pengembalian draft
            try {
                while (ob_get_level()) { ob_end_clean(); }
                header('Content-Type: application/json');
                
                $edit_id = $_POST["edit_id"] ?? NULL;
                $existingIds = $_POST["in_id"] ?? [];
                $nomor_peminjaman = $_POST["in_nomor_peminjaman"] ?? '';
                $tanggal_pengembalian = $_POST["in_tanggal"] ?? '';
                $entitas_peminjam = $_POST["in_entitas_peminjam"] ?? '';
                $entitas_dipinjam = $_POST["in_entitas_dipinjam"] ?? '';
                $gudang_asal = $_POST["in_gudang_asal"] ?? '';
                $gudang_tujuan = $_POST["in_gudang_tujuan"] ?? '';
                $produk_array = $_POST["in_produk"] ?? [];
                $jumlah_kembali_array = $_POST["in_jumlah_kembali"] ?? [];
                $action_button = $_POST["in_action_button"] ?? "Draft";
                
                // Debug logging untuk troubleshooting
                error_log("Update pengembalian - produk_array: " . print_r($produk_array, true));
                error_log("Update pengembalian - jumlah_kembali_array: " . print_r($jumlah_kembali_array, true));
                
                // Cek status pengembalian saat ini
                $currentStatus = null;
                $currentNomorPengembalian = null;
                if (!empty($existingIds) && !empty($existingIds[0])) {
                    $checkStatus = $pdo->prepare("SELECT DISTINCT status_pengembalian, nomor_pengembalian, tanggal_pengembalian, entitas_peminjam, entitas_dipinjam, gudang_asal, gudang_tujuan, nomor_peminjaman FROM pengembalian_stok WHERE id = :id LIMIT 1");
                    $checkStatus->execute([":id" => $existingIds[0]]);
                    $statusRow = $checkStatus->fetch(PDO::FETCH_ASSOC);
                    if ($statusRow) {
                        $currentStatus = $statusRow['status_pengembalian'];
                        $currentNomorPengembalian = $statusRow['nomor_pengembalian'];
                        if (empty($nomor_peminjaman)) {
                            $nomor_peminjaman = $statusRow['nomor_peminjaman'];
                        }
                        if (empty($entitas_peminjam)) {
                            $entitas_peminjam = $statusRow['entitas_peminjam'];
                        }
                        if (empty($entitas_dipinjam)) {
                            $entitas_dipinjam = $statusRow['entitas_dipinjam'];
                        }
                        if (empty($gudang_asal)) {
                            $gudang_asal = $statusRow['gudang_asal'];
                        }
                        if (empty($gudang_tujuan)) {
                            $gudang_tujuan = $statusRow['gudang_tujuan'];
                        }
                    }
                }
                
                if ($currentStatus && $currentStatus != 'Draft') {
                    throw new Exception("Dokumen dengan status '" . $currentStatus . "' tidak dapat diedit. Hanya dokumen dengan status 'Draft' yang dapat diedit.");
                }
                
                // Set status berdasarkan tombol yang diklik
                if ($action_button == "Draft") {
                    $status_pengembalian = "Draft";
                    $nomor_pengembalian = '';
                    $tanggal_pengembalian = null;
                    if (empty($gudang_asal) || empty($gudang_tujuan)) {
                        throw new Exception("Data header tidak lengkap. Gudang Pengembali: " . ($gudang_asal ?: 'kosong') . ", Gudang Penerima: " . ($gudang_tujuan ?: 'kosong'));
                    }
                } else {
                    $status_pengembalian = "Final";
                    if (empty($tanggal_pengembalian) || empty($entitas_peminjam) || empty($gudang_asal) || empty($gudang_tujuan)) {
                        throw new Exception("Data header tidak lengkap. Tanggal: " . ($tanggal_pengembalian ?: 'kosong') . ", Entitas Pengembali: " . ($entitas_peminjam ?: 'kosong') . ", Gudang Pengembali: " . ($gudang_asal ?: 'kosong') . ", Gudang Penerima: " . ($gudang_tujuan ?: 'kosong'));
                    }
                    // Generate nomor pengembalian untuk Final
                    $nomor_pengembalian = generateNomorPengembalian($gudang_asal, $entitas_peminjam, $tanggal_pengembalian, $pdo);
                }
                
                if ($status_pengembalian == "Final" && (empty($nomor_pengembalian) || trim($nomor_pengembalian) == '')) {
                    throw new Exception("Gagal generate nomor pengembalian. Silakan coba lagi.");
                }
                
                // Filter produk dan jumlah yang valid terlebih dahulu
                $produkFiltered = [];
                $jumlahFiltered = [];
                
                // Pastikan produk_array dan jumlah_kembali_array adalah array
                if (!is_array($produk_array)) {
                    $produk_array = [];
                }
                if (!is_array($jumlah_kembali_array)) {
                    $jumlah_kembali_array = [];
                }
                
                $maxCount = max(count($produk_array), count($jumlah_kembali_array));
                
                for ($i = 0; $i < $maxCount; $i++) {
                    $p = isset($produk_array[$i]) ? trim($produk_array[$i]) : '';
                    $j = isset($jumlah_kembali_array[$i]) ? floatval($jumlah_kembali_array[$i]) : 0;
                    
                    // Hanya tambahkan jika produk tidak kosong dan jumlah > 0
                    if (!empty($p) && $j > 0) {
                        $produkFiltered[] = $p;
                        $jumlahFiltered[] = $j;
                    }
                }
                
                // Validasi setelah filtering - minimal harus ada 1 produk dengan jumlah > 0
                if (empty($produkFiltered) || empty($jumlahFiltered) || count($produkFiltered) === 0) {
                    throw new Exception("Minimal harus ada 1 produk dengan jumlah > 0 untuk pengembalian!");
                }
                
                // Mulai transaksi
                $pdo->beginTransaction();
                
                try {
                    // SOLUSI: LOGIKA YANG LEBIH SEDERHANA DAN ROBUST
                    
                    // 1. HAPUS SEMUA DATA LAMA YANG TERKAIT DRAFT INI
                    // Gunakan subquery yang lebih sederhana dan kompatibel dengan MySQL
                    if (!empty($existingIds) && !empty($existingIds[0])) {
                        // Ambil data draft yang terkait dulu
                        $getDraftIds = $pdo->prepare("SELECT m1.id 
                            FROM pengembalian_stok m1 
                            INNER JOIN pengembalian_stok m2 ON m1.gudang_asal = m2.gudang_asal 
                            AND m1.gudang_tujuan = m2.gudang_tujuan 
                            AND (m1.tanggal_pengembalian IS NULL AND m2.tanggal_pengembalian IS NULL 
                                 OR (m1.tanggal_pengembalian IS NOT NULL AND m2.tanggal_pengembalian IS NOT NULL 
                                     AND m1.tanggal_pengembalian = m2.tanggal_pengembalian))
                            AND m1.status_pengembalian = m2.status_pengembalian
                            AND m1.nomor_peminjaman = m2.nomor_peminjaman
                            WHERE m2.id = :id 
                            AND (m1.nomor_pengembalian IS NULL OR m1.nomor_pengembalian = '' OR TRIM(m1.nomor_pengembalian) = '') 
                            AND m1.status_pengembalian = 'Draft'");
                        $getDraftIds->execute([":id" => $existingIds[0]]);
                        $draftIds = $getDraftIds->fetchAll(PDO::FETCH_COLUMN);
                        
                        if (!empty($draftIds)) {
                            $placeholders = implode(',', array_fill(0, count($draftIds), '?'));
                            $deleteOldQuery = $pdo->prepare("DELETE FROM pengembalian_stok WHERE id IN ($placeholders)");
                            $deleteOldQuery->execute($draftIds);
                        }
                    }
                    
                    // 2. INSERT DATA BARU DENGAN STATUS YANG TELAH DIPERBARUI
                    $insertQuery = $pdo->prepare("INSERT INTO pengembalian_stok 
                        (nomor_pengembalian, nomor_peminjaman, tanggal_pengembalian, entitas_peminjam, entitas_dipinjam, gudang_asal, gudang_tujuan, produk, qty, status_pengembalian, catatan, created_at) 
                        VALUES 
                        (:nomor_pengembalian, :nomor_peminjaman, :tanggal_pengembalian, :entitas_peminjam, :entitas_dipinjam, :gudang_asal, :gudang_tujuan, :produk, :qty, :status_pengembalian, :catatan, NOW())");
                    
                    for ($i = 0; $i < count($produkFiltered); $i++) {
                        $insertQuery->execute([
                            ":nomor_pengembalian" => $nomor_pengembalian,
                            ":nomor_peminjaman" => $nomor_peminjaman,
                            ":tanggal_pengembalian" => ($tanggal_pengembalian === null || $tanggal_pengembalian === '') ? null : $tanggal_pengembalian,
                            ":entitas_peminjam" => $entitas_peminjam,
                            ":entitas_dipinjam" => $entitas_dipinjam,
                            ":gudang_asal" => $gudang_asal,
                            ":gudang_tujuan" => $gudang_tujuan,
                            ":produk" => $produkFiltered[$i],
                            ":qty" => $jumlahFiltered[$i],
                            ":status_pengembalian" => $status_pengembalian,
                            ":catatan" => ''
                        ]);
                    }
                    
                    $pdo->commit();
                    
                    // Cek apakah semua produk sudah dikembalikan dan update status menjadi Selesai jika perlu
                    if ($status_pengembalian === 'Final' && !empty($nomor_peminjaman)) {
                        if (checkIfAllProductsReturned($nomor_peminjaman, $pdo)) {
                            updateStatusToSelesai($nomor_peminjaman, $pdo);
                        }
                    }
                    
                    while (ob_get_level()) { ob_end_clean(); }
                    header('Content-Type: application/json; charset=utf-8');
                    $message = $status_pengembalian == "Draft" 
                        ? "Pengembalian berhasil diupdate sebagai Draft" 
                        : "Pengembalian berhasil diupdate sebagai Final";
                    echo json_encode([
                        "status" => "success",
                        "success" => true,
                        "message" => $message,
                        "nomor_pengembalian" => $nomor_pengembalian
                    ], JSON_UNESCAPED_UNICODE);
                    exit();
                    
                } catch (Exception $e) {
                    $pdo->rollBack();
                    throw $e;
                }
                
            } catch (Throwable $e) {
                // Tangkap semua error termasuk Exception dan fatal error
                error_log("Error in update pengembalian: " . $e->getMessage() . " | Trace: " . $e->getTraceAsString());
                
                while (ob_get_level()) { ob_end_clean(); }
                header('Content-Type: application/json; charset=utf-8');
                $errorMessage = $e->getMessage();
                if (empty($errorMessage)) {
                    $errorMessage = "Terjadi kesalahan saat mengupdate data. Silakan cek kembali data yang diinput.";
                }
                echo json_encode([
                    "status" => "error",
                    "success" => false,
                    "message" => $errorMessage
                ], JSON_UNESCAPED_UNICODE);
                exit();
            }
        }
    }
?>