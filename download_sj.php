<?php
    require_once "../../config/redirect_login.php";
    require_once "../../config/config.php";
    require_once "../../config/functions.php";
    require_once "../../vendor/autoload.php";
    require_once "../../asset/AdminLTE-3.2.0/plugins/dompdf/autoload.inc.php";
    
    use Dompdf\Dompdf;
    use Dompdf\Options;
    
    // Ambil nomor pengembalian dari GET
    $nomor_pengembalian = isset($_GET['nomor_pengembalian']) ? $_GET['nomor_pengembalian'] : '';
    
    if (empty($nomor_pengembalian)) {
        die("Nomor pengembalian tidak ditemukan.");
    }
    
    // Query untuk mengambil data pengembalian
    $query = "SELECT 
                m.nomor_pengembalian,
                m.tanggal_pengembalian,
                m.entitas_peminjam,
                m.entitas_dipinjam,
                m.gudang_asal,
                m.gudang_tujuan,
                m.produk,
                m.qty,
                m.status_pengembalian,
                go_asal.dc as dc_asal,
                go_asal.no_hp as telp_asal,
                go_asal.tim as tim_asal,
                go_asal.id_gudang as id_gudang_asal,
                go_tujuan.dc as dc_tujuan,
                go_tujuan.no_hp as telp_tujuan,
                go_tujuan.tim as tim_tujuan,
                go_tujuan.id_gudang as id_gudang_tujuan,
                bdc_asal.alamat as alamat_asal,
                bdc_asal.nama_pj as pj_asal,
                bdc_asal.no_telepon as no_telp_asal,
                bdc_tujuan.alamat as alamat_tujuan,
                bdc_tujuan.nama_pj as pj_tujuan,
                bdc_tujuan.no_telepon as no_telp_tujuan,
                bdc_tujuan.nama_dc as nama_dc_tujuan
              FROM pengembalian_stok m
              LEFT JOIN gudang_omni go_asal ON go_asal.nama_gudang COLLATE utf8mb4_unicode_ci = m.gudang_asal COLLATE utf8mb4_unicode_ci
              LEFT JOIN gudang_omni go_tujuan ON go_tujuan.nama_gudang COLLATE utf8mb4_unicode_ci = m.gudang_tujuan COLLATE utf8mb4_unicode_ci
              LEFT JOIN base_dc bdc_asal ON bdc_asal.id = go_asal.id_dc
              LEFT JOIN base_dc bdc_tujuan ON bdc_tujuan.id = go_tujuan.id_dc
              WHERE m.nomor_pengembalian = ?
              ORDER BY m.id ASC";
    
    // Query untuk mengambil data company identity dari base_entitas
    $companyData = null;
    
    $stmt = mysqli_prepare($db_dc, $query);
    if (!$stmt) {
        die("Error preparing query: " . mysqli_error($db_dc));
    }
    
    mysqli_stmt_bind_param($stmt, "s", $nomor_pengembalian);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    
    $pengembalianData = [];
    $headerData = null;
    $totalQty = 0;
    
    while ($row = mysqli_fetch_assoc($result)) {
        if ($headerData === null) {
            // Validasi tanggal sebelum digunakan
            $tanggal_pengembalian = $row['tanggal_pengembalian'];
            if ($tanggal_pengembalian && $tanggal_pengembalian !== '0000-00-00') {
                // Validasi format tanggal
                $date = DateTime::createFromFormat('Y-m-d', $tanggal_pengembalian);
                if (!$date) {
                    $tanggal_pengembalian = date('Y-m-d'); // Default ke hari ini jika invalid
                }
            } else {
                $tanggal_pengembalian = date('Y-m-d'); // Default ke hari ini jika NULL
            }
            
            $headerData = [
                'nomor_pengembalian' => $row['nomor_pengembalian'],
                'tanggal_pengembalian' => $tanggal_pengembalian,
                'entitas_peminjam' => $row['entitas_peminjam'],
                'entitas_dipinjam' => $row['entitas_dipinjam'],
                'gudang_asal' => $row['gudang_asal'],
                'gudang_tujuan' => $row['gudang_tujuan'],
                'status_pengembalian' => $row['status_pengembalian'],
                'dc_asal' => $row['dc_asal'] ?? '',
                'telp_asal' => $row['telp_asal'] ?? '',
                'tim_asal' => $row['tim_asal'] ?? '',
                'dc_tujuan' => $row['dc_tujuan'] ?? '',
                'telp_tujuan' => $row['telp_tujuan'] ?? '',
                'tim_tujuan' => $row['tim_tujuan'] ?? '',
                // Data dari base_dc untuk gudang pengembali
                'alamat_asal' => $row['alamat_asal'] ?? '',
                'pj_asal' => $row['pj_asal'] ?? '',
                'no_telp_asal' => $row['no_telp_asal'] ?? '',
                // Data dari base_dc untuk gudang penerima
                'alamat_tujuan' => $row['alamat_tujuan'] ?? '',
                'pj_tujuan' => $row['pj_tujuan'] ?? '',
                'no_telp_tujuan' => $row['no_telp_tujuan'] ?? '',
                'nama_dc_tujuan' => $row['nama_dc_tujuan'] ?? ''
            ];
        }
        
        // Hanya tambahkan ke pengembalianData jika produk dan qty terisi
        if (!empty($row['produk']) && !empty($row['qty']) && intval($row['qty']) > 0) {
            $pengembalianData[] = [
                'produk' => $row['produk'],
                'qty' => intval($row['qty'])
            ];
            
            $totalQty += intval($row['qty']);
        }
    }
    
    mysqli_stmt_close($stmt);
    
    if ($headerData === null) {
        die("Data pengembalian tidak ditemukan.");
    }
    
    // Ambil data company identity dari base_entitas berdasarkan entitas_peminjam (pengembali)
    if (!empty($headerData['entitas_peminjam'])) {
        $entitasPeminjam = mysqli_real_escape_string($db_dc, $headerData['entitas_peminjam']);
        $queryCompany = "SELECT 
                            be.nama as nama_company,
                            be.alamat as alamat_company,
                            be.no_hp as telp_company,
                            be.email as email_company
                         FROM base_entitas be
                         WHERE be.inisial = ? OR be.nama = ?
                         LIMIT 1";
        
        $stmtCompany = mysqli_prepare($db_dc, $queryCompany);
        if ($stmtCompany) {
            mysqli_stmt_bind_param($stmtCompany, "ss", $entitasPeminjam, $entitasPeminjam);
            mysqli_stmt_execute($stmtCompany);
            $resultCompany = mysqli_stmt_get_result($stmtCompany);
            if ($rowCompany = mysqli_fetch_assoc($resultCompany)) {
                $companyData = [
                    'nama' => $rowCompany['nama_company'] ?? '-',
                    'alamat' => $rowCompany['alamat_company'] ?? '-',
                    'telp' => $rowCompany['telp_company'] ?? '-',
                    'email' => $rowCompany['email_company'] ?? '-'
                ];
            }
            mysqli_stmt_close($stmtCompany);
        }
    }
    
    // Kelompokkan produk berdasarkan gudang (DC BEKASI, DC JOGJA, dll)
    $productsByGudang = [];
    $allProducts = [];
    
    // Query ulang untuk mendapatkan produk dengan gudang
    $queryProducts = "SELECT 
                        m.produk,
                        m.qty,
                        m.gudang_asal,
                        go_asal.dc as dc_asal
                     FROM pengembalian_stok m
                     LEFT JOIN gudang_omni go_asal ON go_asal.nama_gudang COLLATE utf8mb4_unicode_ci = m.gudang_asal COLLATE utf8mb4_unicode_ci
                     WHERE m.nomor_pengembalian = ?
                     AND m.produk IS NOT NULL 
                     AND m.produk != ''
                     AND m.qty > 0
                     ORDER BY m.id ASC";
    
    $stmtProducts = mysqli_prepare($db_dc, $queryProducts);
    if ($stmtProducts) {
        mysqli_stmt_bind_param($stmtProducts, "s", $nomor_pengembalian);
        mysqli_stmt_execute($stmtProducts);
        $resultProducts = mysqli_stmt_get_result($stmtProducts);
        
        while ($rowProduct = mysqli_fetch_assoc($resultProducts)) {
            $produk = $rowProduct['produk'];
            $qty = intval($rowProduct['qty']);
            $dc = $rowProduct['dc_asal'] ?? '';
            
            // Normalisasi nama DC (hilangkan "DC " jika ada)
            $dcNormalized = str_replace('DC ', '', $dc);
            $dcNormalized = strtoupper($dcNormalized);
            
            if (!isset($productsByGudang[$produk])) {
                $productsByGudang[$produk] = [
                    'produk' => $produk,
                    'gudang' => []
                ];
                $allProducts[] = $produk;
            }
            
            // Simpan qty per gudang
            if (!isset($productsByGudang[$produk]['gudang'][$dcNormalized])) {
                $productsByGudang[$produk]['gudang'][$dcNormalized] = 0;
            }
            $productsByGudang[$produk]['gudang'][$dcNormalized] += $qty;
        }
        mysqli_stmt_close($stmtProducts);
    }
    
    // Dapatkan semua gudang yang unik
    $allGudang = [];
    foreach ($productsByGudang as $product) {
        foreach ($product['gudang'] as $gudang => $qty) {
            if (!in_array($gudang, $allGudang)) {
                $allGudang[] = $gudang;
            }
        }
    }
    sort($allGudang); // Urutkan gudang
    
    // Format tanggal Indonesia dengan handle NULL/invalid dates
    function formatTanggalIndonesia($tanggal) {
        // Handle NULL atau tanggal invalid
        if (empty($tanggal) || $tanggal === '0000-00-00' || $tanggal === 'NULL') {
            return '-';
        }
        
        try {
            $bulan = [
                '01' => 'Januari', '02' => 'Februari', '03' => 'Maret', '04' => 'April',
                '05' => 'Mei', '06' => 'Juni', '07' => 'Juli', '08' => 'Agustus',
                '09' => 'September', '10' => 'Oktober', '11' => 'November', '12' => 'Desember'
            ];
            
            $date = new DateTime($tanggal);
            $day = $date->format('d');
            $month = $date->format('m');
            $year = $date->format('Y');
            
            return $day . ' ' . $bulan[$month] . ' ' . $year;
        } catch (Exception $e) {
            return '-';
        }
    }
    
    // Fungsi untuk mengganti "Jogja" menjadi "Yogyakarta"
    $replaceJogja = function($text) {
        if (empty($text)) return $text;
        return str_replace('Jogja', 'Yogyakarta', $text);
    };
    
    // Replace "Jogja" menjadi "Yogyakarta" untuk DC asal dan tujuan
    $dcAsalDisplay = !empty($headerData['dc_asal']) ? $replaceJogja($headerData['dc_asal']) : '';
    // Gunakan nama_dc dari base_dc untuk Ship to
    $dcTujuanDisplay = !empty($headerData['nama_dc_tujuan']) ? $headerData['nama_dc_tujuan'] : (!empty($headerData['dc_tujuan']) ? $replaceJogja($headerData['dc_tujuan']) : '');
    
    // Format tanggal untuk header
    $tanggalHeader = formatTanggalIndonesia($headerData['tanggal_pengembalian']);
    $bulanHeader = '';
    if (!empty($headerData['tanggal_pengembalian']) && $headerData['tanggal_pengembalian'] !== '0000-00-00') {
        try {
            $date = new DateTime($headerData['tanggal_pengembalian']);
            $bulan = [
                '01' => 'Januari', '02' => 'Februari', '03' => 'Maret', '04' => 'April',
                '05' => 'Mei', '06' => 'Juni', '07' => 'Juli', '08' => 'Agustus',
                '09' => 'September', '10' => 'Oktober', '11' => 'November', '12' => 'Desember'
            ];
            $bulanHeader = $bulan[$date->format('m')];
        } catch (Exception $e) {
            $bulanHeader = '';
        }
    }
    
    // Format nomor telepon untuk company identity
    $formatTelpCompany = function($telp) {
        if (empty($telp)) return '';
        $telp = preg_replace('/[^0-9]/', '', $telp);
        return $telp;
    };
    $telpCompany = !empty($companyData['telp']) ? $formatTelpCompany($companyData['telp']) : '';
    
    // Hitung total per gudang
    $totalPerGudang = [];
    if (!empty($allGudang)) {
        foreach ($allGudang as $gudang) {
            $totalPerGudang[$gudang] = 0;
        }
    }
    $grandTotal = 0;
    
    if (!empty($productsByGudang)) {
        foreach ($productsByGudang as $product) {
            foreach ($product['gudang'] as $gudang => $qty) {
                if (isset($totalPerGudang[$gudang])) {
                    $totalPerGudang[$gudang] += $qty;
                }
                $grandTotal += $qty;
            }
        }
    } else {
        // Jika tidak ada pengelompokan, gunakan totalQty dari $pengembalianData
        $grandTotal = $totalQty;
    }
    
    // Generate HTML untuk PDF sesuai format gambar
    $html = '
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Pengembalian - ' . htmlspecialchars($headerData['nomor_pengembalian']) . '</title>
    <style>
        @page {
            size: A4;
            margin: 8mm;
        }
        
        body {
            font-family: Arial, sans-serif;
            font-size: 8pt;
            margin: 0;
            padding: 0;
        }
        
        .header-right {
            text-align: right;
            margin-bottom: 12px;
        }
        
        .header-title {
            font-size: 18pt;
            font-weight: bold;
            margin-bottom: 3px;
        }
        
        .header-separator {
            border-bottom: 3px solid #000;
            margin: 10px 0 12px 0;
            width: 100%;
        }
        
        .header-date {
            font-size: 8pt;
            margin-bottom: 2px;
        }
        
        .header-number {
            font-size: 8pt;
            margin-bottom: 3px;
        }
        
        .ship-to {
            display: inline-block;
            background-color: #ffff00;
            padding: 2px 6px;
            font-weight: bold;
            margin-top: 3px;
            font-size: 8pt;
        }
        
        .left-section {
            width: 50%;
            float: left;
            padding-right: 15px;
        }
        
        .to-label {
            font-weight: bold;
            margin-bottom: 3px;
            font-size: 8pt;
        }
        
        .company-name {
            font-weight: bold;
            margin-bottom: 3px;
            font-size: 8pt;
        }
        
        .company-info {
            font-size: 7pt;
            margin-bottom: 1px;
        }
        
        .jumlah-koli {
            margin: 10px 0;
            font-weight: bold;
            color: #cc0000;
            border-bottom: 2px solid #cc0000;
            padding-bottom: 2px;
            background-color: #fffacd;
            display: inline-block;
            font-size: 8pt;
        }
        
        .product-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 20px;
            font-size: 7pt;
        }
        
        .product-table th {
            background-color: #b0d4f1;
            border: 1px solid #000;
            padding: 4px;
            text-align: center;
            font-weight: bold;
            font-size: 7pt;
        }
        
        .product-table td {
            border: 1px solid #000;
            padding: 3px;
            text-align: left;
            font-size: 7pt;
        }
        
        .product-table td.center {
            text-align: center;
        }
        
        .product-table td.right {
            text-align: right;
        }
        
        .total-row {
            font-weight: bold;
            background-color: #90ee90;
        }
        
        .approval-section {
            margin-top: 25px;
            display: table;
            width: 100%;
        }
        
        .approval-row {
            display: table-row;
        }
        
        .approval-cell {
            display: table-cell;
            width: 25%;
            padding: 5px;
            vertical-align: top;
            text-align: center;
        }
        
        .approval-box {
            border: 1px solid #000;
            height: 60px;
            margin-bottom: 3px;
        }
        
        .approval-label {
            font-size: 7pt;
            font-weight: bold;
        }
    </style>
</head>
<body>
    <div class="header-right">
        <div class="header-title">Pengembalian</div>
        <div class="header-number">' . htmlspecialchars($headerData['nomor_pengembalian']) . '</div>
    </div>
    
    <div class="header-separator"></div>
    
    <div style="display: table; width: 100%; margin-bottom: 20px;">
        <div class="left-section" style="display: table-cell; width: 50%; vertical-align: top;">
            <div class="to-label">To:</div>
            <div class="company-name">' . htmlspecialchars($headerData['entitas_dipinjam'] ?? '-') . '</div>
            
            <div style="margin-top: 15px;">
                <div class="company-name">Company Identity</div>';
    
    if ($companyData) {
        $html .= '
                <div class="company-info">' . htmlspecialchars($companyData['nama'] ?? '-') . '</div>
                <div class="company-info">' . htmlspecialchars($telpCompany) . '</div>
                <div class="company-info">' . htmlspecialchars($companyData['email'] ?? '-') . '</div>';
    } else {
        $html .= '
                <div class="company-info">-</div>
                <div class="company-info">-</div>
                <div class="company-info">-</div>';
    }
    
    $html .= '
            </div>
        </div>
        
        <div class="header-right" style="display: table-cell; width: 50%; vertical-align: top;">
            <div class="header-date">' . htmlspecialchars($tanggalHeader) . '</div>
            <div class="ship-to">Ship to: ' . htmlspecialchars($dcTujuanDisplay) . '</div>
        </div>
    </div>
    
    <div style="clear: both;"></div>
    
    <div class="left-section">
        <div class="jumlah-koli">JUMLAH KOLI:</div>
    </div>
    
    <div style="clear: both;"></div>
    
    <table class="product-table">
        <thead>
            <tr>
                <th style="width: 5%;">NO</th>
                <th style="width: 40%;">PRODUCT</th>';
    
    // Tambahkan kolom untuk setiap gudang
    if (empty($allGudang)) {
        // Jika tidak ada gudang, gunakan gudang tujuan sebagai default
        $dcTujuanNormalized = str_replace('DC ', '', $dcTujuanDisplay);
        $dcTujuanNormalized = strtoupper($dcTujuanNormalized);
        if (!empty($dcTujuanNormalized)) {
            $allGudang = [$dcTujuanNormalized];
        } else {
            $allGudang = ['BEKASI']; // Default
        }
    }
    
    $gudangWidth = count($allGudang) > 0 ? (45 / count($allGudang)) : 45;
    foreach ($allGudang as $gudang) {
        // Jika hanya ada 1 kolom gudang, gunakan "Qty" sebagai header
        if (count($allGudang) === 1) {
            $html .= '<th style="width: ' . $gudangWidth . '%;">Qty</th>';
        } else {
            $html .= '<th style="width: ' . $gudangWidth . '%;">DC ' . htmlspecialchars($gudang) . '</th>';
        }
    }
    
    $html .= '
                <th style="width: 10%;">TOTAL</th>
            </tr>
        </thead>
        <tbody>';
    
    $no = 1;
    if (!empty($allProducts)) {
        foreach ($allProducts as $produk) {
            if (!isset($productsByGudang[$produk])) continue;
            
            $productData = $productsByGudang[$produk];
            $rowTotal = 0;
            
            $html .= '
                <tr>
                    <td class="center">' . $no . '</td>
                    <td>' . htmlspecialchars($productData['produk']) . '</td>';
            
            // Tambahkan qty untuk setiap gudang
            foreach ($allGudang as $gudang) {
                $qty = isset($productData['gudang'][$gudang]) ? $productData['gudang'][$gudang] : 0;
                $rowTotal += $qty;
                $html .= '<td class="center">' . ($qty > 0 ? number_format($qty, 0, ',', '.') : '') . '</td>';
            }
            
            $html .= '
                    <td class="center">' . number_format($rowTotal, 0, ',', '.') . '</td>
                </tr>';
            
            $no++;
        }
    } else {
        // Fallback: gunakan data dari $pengembalianData jika tidak ada pengelompokan
        foreach ($pengembalianData as $item) {
            $rowTotal = $item['qty'];
            
            $html .= '
                <tr>
                    <td class="center">' . $no . '</td>
                    <td>' . htmlspecialchars($item['produk']) . '</td>';
            
            // Tambahkan qty untuk setiap gudang (kosongkan semua kecuali total)
            foreach ($allGudang as $gudang) {
                $html .= '<td class="center"></td>';
            }
            
            $html .= '
                    <td class="center">' . number_format($rowTotal, 0, ',', '.') . '</td>
                </tr>';
            
            $no++;
        }
    }
    
    // Total row
    $html .= '
            <tr class="total-row">
                <td></td>
                <td><strong>TOTAL</strong></td>';
    
    foreach ($allGudang as $gudang) {
        $total = isset($totalPerGudang[$gudang]) ? $totalPerGudang[$gudang] : 0;
        $html .= '<td class="center"><strong>' . number_format($total, 0, ',', '.') . '</strong></td>';
    }
    
    $html .= '
                <td class="center"><strong>' . number_format($grandTotal, 0, ',', '.') . '</strong></td>
            </tr>
        </tbody>
    </table>
    
    <div class="approval-section">
        <div class="approval-row">
            <div class="approval-cell">
                <div class="approval-box"></div>
                <div class="approval-label">APPLICANT FINANCE TEAM</div>
            </div>
            <div class="approval-cell">
                <div class="approval-box"></div>
                <div class="approval-label">APPROVAL FINANCE TEAM</div>
            </div>
            <div class="approval-cell">
                <div class="approval-box"></div>
                <div class="approval-label">APPROVAL PIC DC</div>
            </div>
            <div class="approval-cell">
                <div class="approval-box"></div>
                <div class="approval-label">PICKER DC</div>
            </div>
        </div>
    </div>
</body>
</html>';
    
    // Generate PDF
    $options = new Options();
    $options->set('isRemoteEnabled', true);
    $options->set('isHtml5ParserEnabled', true);
    $options->set('isPhpEnabled', true);
    
    $dompdf = new Dompdf($options);
    $dompdf->loadHtml($html);
    $dompdf->setPaper('A4', 'landscape');
    $dompdf->render();
    
    // Output PDF dalam mode download
    $dompdf->stream('Surat_Jalan_Pengembalian_' . $headerData['nomor_pengembalian'] . '.pdf', [
        'Attachment' => 1  // 1 = download, 0 = preview
    ]);
    
    exit(); // Keluar setelah generate PDF
?>







