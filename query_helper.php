<?php
/**
 * Helper functions untuk query pengembalian yang dioptimasi
 * File ini memecah query kompleks menjadi query yang lebih efisien
 */

/**
 * Get pengembalian products grouped by nomor_pengembalian
 * Query ini lebih efisien karena hanya mengambil data yang diperlukan
 */
function getPengembalianProductsBatch($db_dc, $nomorPengembalianList) {
    if (empty($nomorPengembalianList)) {
        return [];
    }
    
    $placeholders = str_repeat('?,', count($nomorPengembalianList) - 1) . '?';
    $query = "SELECT 
                m.nomor_pengembalian,
                m.produk,
                SUM(m.qty) as qty
              FROM pengembalian_stok m
              WHERE m.nomor_pengembalian IN ($placeholders)
              GROUP BY m.nomor_pengembalian, m.produk";
    
    $stmt = mysqli_prepare($db_dc, $query);
    if (!$stmt) {
        return [];
    }
    
    $types = str_repeat('s', count($nomorPengembalianList));
    mysqli_stmt_bind_param($stmt, $types, ...$nomorPengembalianList);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    
    $map = [];
    while ($row = mysqli_fetch_assoc($result)) {
        $nomor = $row['nomor_pengembalian'];
        $produk = $row['produk'];
        $qty = intval($row['qty']);
        
        if (!isset($map[$nomor])) {
            $map[$nomor] = [];
        }
        $map[$nomor][$produk] = $qty;
    }
    
    mysqli_stmt_close($stmt);
    return $map;
}

/**
 * Get gudang tujuan untuk setiap nomor_pengembalian
 */
function getGudangTujuanPengembalianBatch($db_dc, $nomorPengembalianList) {
    if (empty($nomorPengembalianList)) {
        return [];
    }
    
    $placeholders = str_repeat('?,', count($nomorPengembalianList) - 1) . '?';
    $query = "SELECT 
                nomor_pengembalian,
                MIN(gudang_tujuan) as gudang_tujuan
              FROM pengembalian_stok
              WHERE nomor_pengembalian IN ($placeholders)
              GROUP BY nomor_pengembalian";
    
    $stmt = mysqli_prepare($db_dc, $query);
    if (!$stmt) {
        return [];
    }
    
    $types = str_repeat('s', count($nomorPengembalianList));
    mysqli_stmt_bind_param($stmt, $types, ...$nomorPengembalianList);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    
    $map = [];
    while ($row = mysqli_fetch_assoc($result)) {
        $map[$row['nomor_pengembalian']] = $row['gudang_tujuan'];
    }
    
    mysqli_stmt_close($stmt);
    return $map;
}

/**
 * Get gudang asal untuk setiap nomor_pengembalian
 */
function getGudangAsalPengembalianBatch($db_dc, $nomorPengembalianList) {
    if (empty($nomorPengembalianList)) {
        return [];
    }
    
    $placeholders = str_repeat('?,', count($nomorPengembalianList) - 1) . '?';
    $query = "SELECT 
                nomor_pengembalian,
                MIN(gudang_asal) as gudang_asal
              FROM pengembalian_stok
              WHERE nomor_pengembalian IN ($placeholders)
              GROUP BY nomor_pengembalian";
    
    $stmt = mysqli_prepare($db_dc, $query);
    if (!$stmt) {
        return [];
    }
    
    $types = str_repeat('s', count($nomorPengembalianList));
    mysqli_stmt_bind_param($stmt, $types, ...$nomorPengembalianList);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    
    $map = [];
    while ($row = mysqli_fetch_assoc($result)) {
        $map[$row['nomor_pengembalian']] = $row['gudang_asal'];
    }
    
    mysqli_stmt_close($stmt);
    return $map;
}

/**
 * Get stok dipinjam dari tabel peminjaman_stok berdasarkan nomor_peminjaman_original
 * Mengambil total qty dari peminjaman asli untuk setiap nomor_pengembalian
 */
function getStokDipinjamBatch($db_dc, $nomorPeminjamanList) {
    if (empty($nomorPeminjamanList)) {
        return [];
    }
    
    $placeholders = str_repeat('?,', count($nomorPeminjamanList) - 1) . '?';
    $query = "SELECT 
                nomor_peminjaman,
                SUM(qty) as total_qty_dipinjam
              FROM peminjaman_stok
              WHERE nomor_peminjaman IN ($placeholders)
              GROUP BY nomor_peminjaman";
    
    $stmt = mysqli_prepare($db_dc, $query);
    if (!$stmt) {
        return [];
    }
    
    $types = str_repeat('s', count($nomorPeminjamanList));
    mysqli_stmt_bind_param($stmt, $types, ...$nomorPeminjamanList);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    
    $map = [];
    while ($row = mysqli_fetch_assoc($result)) {
        $map[$row['nomor_peminjaman']] = floatval($row['total_qty_dipinjam']);
    }
    
    mysqli_stmt_close($stmt);
    return $map;
}

/**
 * Build filter conditions untuk query pengembalian
 */
function buildFilterConditionsPengembalian($db_dc, $entitas_peminjam, $entitas_dipinjam, $gudang_asal, $gudang_tujuan, $search, $includeSearch = false) {
    $conditions = [];
    
    if (!empty($entitas_peminjam)) {
        $entitas_peminjam_escaped = mysqli_real_escape_string($db_dc, $entitas_peminjam);
        $conditions[] = "pg.entitas_peminjam = '$entitas_peminjam_escaped'";
    }
    
    if (!empty($entitas_dipinjam)) {
        $entitas_dipinjam_escaped = mysqli_real_escape_string($db_dc, $entitas_dipinjam);
        $conditions[] = "pg.entitas_dipinjam = '$entitas_dipinjam_escaped'";
    }
    
    if (!empty($gudang_asal)) {
        $gudang_asal_escaped = mysqli_real_escape_string($db_dc, $gudang_asal);
        $conditions[] = "pg.gudang_asal = '$gudang_asal_escaped'";
    }
    
    if (!empty($gudang_tujuan)) {
        $gudang_tujuan_escaped = mysqli_real_escape_string($db_dc, $gudang_tujuan);
        $conditions[] = "pg.gudang_tujuan LIKE '%$gudang_tujuan_escaped%'";
    }
    
    if ($includeSearch && !empty($search)) {
        $search_escaped = mysqli_real_escape_string($db_dc, $search);
        $conditions[] = "(
            pg.nomor_pengembalian LIKE '%$search_escaped%' OR
            pg.entitas_peminjam LIKE '%$search_escaped%' OR
            pg.entitas_dipinjam LIKE '%$search_escaped%' OR
            pg.gudang_asal LIKE '%$search_escaped%' OR
            pg.gudang_tujuan LIKE '%$search_escaped%'
        )";
    }
    
    return $conditions;
}

/**
 * Build filter conditions untuk count query (menggunakan alias m.)
 */
function buildFilterConditionsPengembalianForCount($db_dc, $entitas_peminjam, $entitas_dipinjam, $gudang_asal, $gudang_tujuan, $search, $includeSearch = false) {
    $conditions = [];
    
    if (!empty($entitas_peminjam)) {
        $entitas_peminjam_escaped = mysqli_real_escape_string($db_dc, $entitas_peminjam);
        $conditions[] = "m.entitas_peminjam = '$entitas_peminjam_escaped'";
    }
    
    if (!empty($entitas_dipinjam)) {
        $entitas_dipinjam_escaped = mysqli_real_escape_string($db_dc, $entitas_dipinjam);
        $conditions[] = "m.entitas_dipinjam = '$entitas_dipinjam_escaped'";
    }
    
    if (!empty($gudang_asal)) {
        $gudang_asal_escaped = mysqli_real_escape_string($db_dc, $gudang_asal);
        $conditions[] = "m.gudang_asal = '$gudang_asal_escaped'";
    }
    
    if (!empty($gudang_tujuan)) {
        $gudang_tujuan_escaped = mysqli_real_escape_string($db_dc, $gudang_tujuan);
        $conditions[] = "m.gudang_tujuan LIKE '%$gudang_tujuan_escaped%'";
    }
    
    if ($includeSearch && !empty($search)) {
        $search_escaped = mysqli_real_escape_string($db_dc, $search);
        $conditions[] = "(
            m.nomor_pengembalian LIKE '%$search_escaped%' OR
            m.entitas_peminjam LIKE '%$search_escaped%' OR
            m.entitas_dipinjam LIKE '%$search_escaped%' OR
            m.gudang_asal LIKE '%$search_escaped%' OR
            m.gudang_tujuan LIKE '%$search_escaped%'
        )";
    }
    
    return $conditions;
}

/**
 * Count query untuk DataTables pengembalian - mengikuti pola peminjaman
 */
function getCountQueryPengembalian($db_dc, $start_date, $end_date, $entitas_peminjam, $entitas_dipinjam, $gudang_asal, $gudang_tujuan, $search, $includeSearch = false) {
    $conditions = buildFilterConditionsPengembalianForCount($db_dc, $entitas_peminjam, $entitas_dipinjam, $gudang_asal, $gudang_tujuan, $search, $includeSearch);
    
    // Escape dates
    $start_date = mysqli_real_escape_string($db_dc, $start_date);
    $end_date = mysqli_real_escape_string($db_dc, $end_date);
    
    // Base WHERE clause - mengikuti pola peminjaman
    $whereClause = "((m.tanggal_pengembalian >= '$start_date 00:00:00' 
                    AND m.tanggal_pengembalian < DATE_ADD('$end_date', INTERVAL 1 DAY))
                    OR (m.tanggal_pengembalian IS NULL AND m.status_pengembalian = 'Draft'))";
    
    if (!empty($conditions)) {
        $whereClause = "(" . implode(" AND ", $conditions) . ") AND (" . $whereClause . ")";
    }
    
    $query = "SELECT COUNT(*) as total 
              FROM (
                  SELECT DISTINCT 
                      CASE WHEN m.nomor_pengembalian IS NULL OR m.nomor_pengembalian = '' OR TRIM(m.nomor_pengembalian) = '' 
                          THEN CONCAT(COALESCE(m.entitas_peminjam, ''), '|', m.gudang_asal, '|', m.gudang_tujuan, '|', COALESCE(DATE(m.tanggal_pengembalian), 'NULL'), '|', m.status_pengembalian)
                          ELSE m.nomor_pengembalian 
                      END as nomor_pengembalian
                  FROM pengembalian_stok m
                  WHERE $whereClause
              ) as distinct_pengembalian";
    
    return $query;
}

/**
 * Get log_stok data untuk pengembalian (match dan mismatch dalam 1 query)
 * Optimasi: gabungkan match dan mismatch dalam 1 query dengan CASE
 */
function getLogStokPengembalianBatch($db_dc, $nomorPengembalianList, $gudangTujuanMap) {
    if (empty($nomorPengembalianList) || empty($gudangTujuanMap)) {
        return [[], []];
    }
    
    // Build list nomor_pengembalian yang valid (ada gudang_tujuan-nya)
    $validNomorPengembalian = [];
    foreach ($nomorPengembalianList as $nomor) {
        if (isset($gudangTujuanMap[$nomor])) {
            $validNomorPengembalian[] = $nomor;
        }
    }
    
    if (empty($validNomorPengembalian)) {
        return [[], []];
    }
    
    // Escape nomor_pengembalian untuk digunakan dalam query
    $escapedNomorPengembalian = array_map(function($n) use ($db_dc) {
        return "'" . mysqli_real_escape_string($db_dc, $n) . "'";
    }, $validNomorPengembalian);
    $nomorPengembalianString = implode(',', $escapedNomorPengembalian);
    
    // Optimasi: Gunakan exact match terlebih dahulu, baru LIKE sebagai fallback
    $query = "
        SELECT 
            m.nomor_pengembalian,
            ls.varian as produk,
            SUM(CASE WHEN ls.nama_gudang = m.gudang_tujuan THEN ls.jumlah ELSE 0 END) as jumlah_match,
            SUM(CASE WHEN ls.nama_gudang != m.gudang_tujuan THEN ls.jumlah ELSE 0 END) as jumlah_mismatch
        FROM log_stok ls
        INNER JOIN (
            SELECT DISTINCT nomor_pengembalian, gudang_tujuan
            FROM pengembalian_stok
            WHERE nomor_pengembalian IN ($nomorPengembalianString)
        ) m ON (
            ls.nama_file = m.nomor_pengembalian
            OR ls.nama_file LIKE CONCAT('PB ', m.nomor_pengembalian, '%')
            OR ls.nama_file LIKE CONCAT('%', m.nomor_pengembalian, '%')
        )
        WHERE ls.kategori = 'Pengembalian'
        GROUP BY m.nomor_pengembalian, ls.varian
        HAVING jumlah_match > 0 OR jumlah_mismatch > 0
    ";
    
    $result = mysqli_query($db_dc, $query);
    
    $matchMap = [];
    $mismatchMap = [];
    
    if ($result) {
        while ($row = mysqli_fetch_assoc($result)) {
            $nomor = $row['nomor_pengembalian'];
            $produk = $row['produk'];
            $jumlahMatch = floatval($row['jumlah_match']);
            $jumlahMismatch = floatval($row['jumlah_mismatch']);
            
            if ($jumlahMatch > 0) {
                if (!isset($matchMap[$nomor])) {
                    $matchMap[$nomor] = [];
                }
                $matchMap[$nomor][$produk] = $jumlahMatch;
            }
            
            if ($jumlahMismatch > 0) {
                if (!isset($mismatchMap[$nomor])) {
                    $mismatchMap[$nomor] = [];
                }
                $mismatchMap[$nomor][$produk] = $jumlahMismatch;
            }
        }
    }
    
    return [$matchMap, $mismatchMap];
}

/**
 * Calculate status pengembalian secara batch
 * Mengikuti logika view detail: membandingkan jumlah dipinjam dengan jumlah dikembalikan
 */
function calculateStatusPengembalianBatch($rowsData, $pengembalianProductsMap, $logStokMatchMap, $logStokMismatchMap) {
    global $db_dc;
    $statusMap = [];
    
    foreach ($rowsData as $row) {
        $nomor = $row['nomor_peminjaman']; // Note: menggunakan alias yang sama dengan peminjaman (ini adalah nomor_pengembalian)
        $status_pengembalian = $row['status_peminjaman']; // Note: menggunakan alias yang sama
        
        // Jika Draft, set status = Draft dan skip
        if ($status_pengembalian == 'Draft') {
            if (!empty($nomor) && trim($nomor) != '') {
                if (!isset($statusMap[$nomor])) {
                    $statusMap[$nomor] = [
                        'status' => 'draft',
                        'is_selesai' => 0,
                        'is_belum_selesai' => 0,
                        'is_terproses' => 0
                    ];
                }
            }
            continue;
        }
        
        if (empty($nomor) || trim($nomor) == '') continue;
        
        if (!isset($statusMap[$nomor])) {
            $statusMap[$nomor] = [
                'status' => 'final',
                'is_selesai' => 0,
                'is_belum_selesai' => 0,
                'is_terproses' => 0
            ];
            
            // Ambil data pengembalian (jumlah dikembalikan)
            $pengembalianProducts = isset($pengembalianProductsMap[$nomor]) ? $pengembalianProductsMap[$nomor] : [];
            
            if (empty($pengembalianProducts)) {
                $statusMap[$nomor]['status'] = 'final';
            } else {
                // Ambil jumlah dipinjam dari peminjaman_stok berdasarkan nomor_peminjaman_original
                $nomorPeminjamanOriginal = isset($row['nomor_peminjaman_original']) ? $row['nomor_peminjaman_original'] : null;
                
                if (!empty($nomorPeminjamanOriginal)) {
                    $nomorPeminjamanEscaped = mysqli_real_escape_string($db_dc, $nomorPeminjamanOriginal);
                    $queryJumlahDipinjam = "SELECT 
                                    produk,
                                    SUM(qty) as jumlah_dipinjam
                                 FROM peminjaman_stok
                                 WHERE nomor_peminjaman = '{$nomorPeminjamanEscaped}'
                                 GROUP BY produk";
                    
                    $resultJumlahDipinjam = mysqli_query($db_dc, $queryJumlahDipinjam);
                    $jumlahDipinjamPerProduk = [];
                    
                    if ($resultJumlahDipinjam) {
                        while ($rowDipinjam = mysqli_fetch_assoc($resultJumlahDipinjam)) {
                            $produk = $rowDipinjam['produk'];
                            $jumlahDipinjam = floatval($rowDipinjam['jumlah_dipinjam']);
                            if ($jumlahDipinjam > 0) {
                                $jumlahDipinjamPerProduk[$produk] = $jumlahDipinjam;
                            }
                        }
                    }
                    
                    // Bandingkan jumlah dipinjam dengan jumlah dikembalikan
                    if (!empty($jumlahDipinjamPerProduk)) {
                        $allMatch = true;
                        $hasAnyData = false;
                        
                        foreach ($jumlahDipinjamPerProduk as $produk => $qtyDipinjam) {
                            $hasAnyData = true;
                            $jumlahDikembalikan = isset($pengembalianProducts[$produk]) ? floatval($pengembalianProducts[$produk]) : 0;
                            $qtyDipinjamFloat = floatval($qtyDipinjam);
                            
                            // Gunakan toleransi untuk perbandingan floating point
                            if (abs($jumlahDikembalikan - $qtyDipinjamFloat) >= 0.01) {
                                $allMatch = false;
                                break;
                            }
                        }
                        
                        if ($hasAnyData && $allMatch) {
                            $statusMap[$nomor]['status'] = 'selesai';
                            $statusMap[$nomor]['is_selesai'] = 1;
                        } else {
                            $statusMap[$nomor]['status'] = 'belum_selesai';
                            $statusMap[$nomor]['is_belum_selesai'] = 1;
                        }
                    } else {
                        $statusMap[$nomor]['status'] = 'final';
                    }
                } else {
                    $statusMap[$nomor]['status'] = 'final';
                }
            }
        }
    }
    
    return $statusMap;
}

/**
 * Get log_stok data untuk menentukan status transaksi pengembalian
 * Mengembalikan array dengan informasi:
 * - log_stok_asal: data log_stok dengan nama_gudang = gudang_asal (gudang pengembali)
 * - log_stok_tujuan: data log_stok dengan nama_gudang = gudang_tujuan (gudang penerima) dan qty sesuai
 */
function getLogStokForStatusTransaksiPengembalian($db_dc, $nomorPengembalianList, $gudangAsalMap, $gudangTujuanMap) {
    if (empty($nomorPengembalianList)) {
        return [];
    }
    
    // Escape nomor_pengembalian untuk digunakan dalam query
    $escapedNomorPengembalian = array_map(function($n) use ($db_dc) {
        return "'" . mysqli_real_escape_string($db_dc, $n) . "'";
    }, $nomorPengembalianList);
    $nomorPengembalianString = implode(',', $escapedNomorPengembalian);
    
    // Query untuk mendapatkan data log_stok dengan kondisi spesifik
    // Optimasi: prioritaskan exact match dengan normalisasi, lalu fallback ke exact match
    $query = "
        SELECT 
            m.nomor_pengembalian,
            m.gudang_asal,
            m.gudang_tujuan,
            ls.varian as produk,
            ls.nama_gudang,
            SUM(ls.jumlah) as qty_log_stok
        FROM log_stok ls
        INNER JOIN (
            SELECT DISTINCT nomor_pengembalian, gudang_asal, gudang_tujuan
            FROM pengembalian_stok
            WHERE nomor_pengembalian IN ($nomorPengembalianString)
        ) m ON (
            REPLACE(REPLACE(COALESCE(ls.nama_file, ''), ' ', ''), '-', '') = REPLACE(REPLACE(m.nomor_pengembalian, ' ', ''), '-', '')
            OR ls.nama_file = m.nomor_pengembalian
            OR ls.nama_file LIKE CONCAT('PB ', m.nomor_pengembalian, '%')
            OR ls.nama_file LIKE CONCAT('PB PB ', m.nomor_pengembalian, '%')
            OR ls.nama_file LIKE CONCAT('PB PB/', REPLACE(m.nomor_pengembalian, 'PB/', ''), '%')
            OR ls.nama_file LIKE CONCAT('%', m.nomor_pengembalian, '%')
            OR REPLACE(REPLACE(COALESCE(ls.nama_file, ''), ' ', ''), '-', '') LIKE CONCAT('%', REPLACE(REPLACE(m.nomor_pengembalian, ' ', ''), '-', ''), '%')
        )
        WHERE ls.kategori = 'Pengembalian'
            AND (ls.nama_gudang = m.gudang_tujuan OR ls.nama_gudang = m.gudang_asal)
        GROUP BY m.nomor_pengembalian, ls.varian, ls.nama_gudang
    ";
    
    $result = mysqli_query($db_dc, $query);
    
    if (!$result) {
        error_log("Error in getLogStokForStatusTransaksiPengembalian: " . mysqli_error($db_dc) . " | Query: " . $query);
        return [];
    }
    
    $logStokMap = [];
    
    if ($result) {
        while ($row = mysqli_fetch_assoc($result)) {
            $nomor = $row['nomor_pengembalian'];
            $produk = $row['produk'];
            $namaGudang = $row['nama_gudang'];
            $gudangTujuan = $row['gudang_tujuan'];
            $gudangAsal = $row['gudang_asal'];
            $qtyLogStok = floatval($row['qty_log_stok']);
            
            if (!isset($logStokMap[$nomor])) {
                $logStokMap[$nomor] = [
                    'asal' => [], // log_stok dengan nama_gudang = gudang_asal
                    'tujuan' => []  // log_stok dengan nama_gudang = gudang_tujuan
                ];
            }
            
            // Jika nama_gudang = gudang_asal (gudang pengembali)
            if ($namaGudang == $gudangAsal) {
                if (!isset($logStokMap[$nomor]['asal'][$produk])) {
                    $logStokMap[$nomor]['asal'][$produk] = 0;
                }
                $logStokMap[$nomor]['asal'][$produk] += $qtyLogStok;
            }
            
            // Jika nama_gudang = gudang_tujuan (gudang penerima)
            if ($namaGudang == $gudangTujuan) {
                if (!isset($logStokMap[$nomor]['tujuan'][$produk])) {
                    $logStokMap[$nomor]['tujuan'][$produk] = 0;
                }
                $logStokMap[$nomor]['tujuan'][$produk] += $qtyLogStok;
            }
        }
    }
    
    return $logStokMap;
}

/**
 * Calculate status transaksi pengembalian secara batch
 * Aturan Status Transaksi Pengembalian:
 * - Draft: ketika data disimpan/diupdate sebagai draft
 * - Final: ketika data disimpan/diupdate sebagai final
 * - Belum Selesai: ketika nama_gudang di table log_stok = gudang asal dan nama_file di log_stok seperti nomor pengembalian
 * - Selesai: ketika nama_gudang di table log_stok = gudang tujuan dan qty log_stok = qty pengembalian dan nama_file di log_stok seperti nomor pengembalian
 */
function calculateStatusTransaksiPengembalianBatch($rowsData, $pengembalianProductsMap, $logStokMatchMap, $logStokMismatchMap, $logStokForStatusTransaksiMap = null) {
    global $db_dc;
    $statusMap = [];
    
    foreach ($rowsData as $row) {
        $nomor = $row['nomor_peminjaman']; // Note: menggunakan alias yang sama dengan peminjaman (ini adalah nomor_pengembalian)
        $status_pengembalian = $row['status_peminjaman']; // Note: menggunakan alias yang sama
        
        if (empty($nomor) || trim($nomor) == '') continue;
        
        if (!isset($statusMap[$nomor])) {
            // Default status
            $statusMap[$nomor] = [
                'status' => 'final',
                'is_selesai' => 0,
                'is_belum_selesai' => 0,
                'is_terproses' => 0
            ];
            
            // 1. Draft: ketika data disimpan/diupdate sebagai draft
            if ($status_pengembalian == 'Draft') {
                $statusMap[$nomor]['status'] = 'draft';
                continue;
            }
            
            // 2. Final: ketika data disimpan/diupdate sebagai final
            if ($status_pengembalian == 'Final') {
                $statusMap[$nomor]['status'] = 'final';
                // Tetap cek log_stok untuk melihat apakah ada perubahan status
            }
            
            // Ambil data pengembalian (jumlah dikembalikan)
            $pengembalianProducts = isset($pengembalianProductsMap[$nomor]) ? $pengembalianProductsMap[$nomor] : [];
            
            if (empty($pengembalianProducts)) {
                $statusMap[$nomor]['status'] = 'final';
                continue;
            }
            
            // Ambil data log_stok untuk status transaksi
            // Struktur: ['nomor_pengembalian' => ['asal' => ['produk' => qty], 'tujuan' => ['produk' => qty]]]
            $logStokData = isset($logStokForStatusTransaksiMap[$nomor]) ? $logStokForStatusTransaksiMap[$nomor] : null;
            
            // 3. Selesai: ketika nama_gudang di table log_stok = gudang tujuan DAN qty log_stok = qty pengembalian DAN nama_file di log_stok seperti nomor pengembalian
            // PRIORITAS: Cek Selesai dulu sebelum Belum Selesai
            if ($logStokData && !empty($logStokData['tujuan'])) {
                $allMatch = true;
                $hasAnyData = false;
                
                foreach ($pengembalianProducts as $produk => $qtyPengembalian) {
                    $hasAnyData = true;
                    $qtyLogStokTujuan = isset($logStokData['tujuan'][$produk]) ? floatval($logStokData['tujuan'][$produk]) : 0;
                    $qtyPengembalianFloat = floatval($qtyPengembalian);
                    
                    // Bandingkan qty log_stok dengan qty pengembalian
                    // qty log_stok di gudang tujuan biasanya positif (menambah stok)
                    // Gunakan toleransi untuk perbandingan floating point
                    if (abs($qtyLogStokTujuan - $qtyPengembalianFloat) >= 0.01) {
                        $allMatch = false;
                        break;
                    }
                }
                
                if ($hasAnyData && $allMatch) {
                    $statusMap[$nomor]['status'] = 'selesai';
                    $statusMap[$nomor]['is_selesai'] = 1;
                    continue;
                }
            }
            
            // 4. Belum Selesai: ketika nama_gudang di table log_stok = gudang asal DAN nama_file di log_stok seperti nomor pengembalian
            if ($logStokData && !empty($logStokData['asal'])) {
                $statusMap[$nomor]['status'] = 'belum_selesai';
                $statusMap[$nomor]['is_belum_selesai'] = 1;
                continue;
            }
            
            // Jika tidak ada kondisi yang terpenuhi, tetap Final
        }
    }
    
    return $statusMap;
}

