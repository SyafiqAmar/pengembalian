<?php
    require_once "../../config/redirect_login.php";
    require_once "../../config/config.php";
    require_once "../../config/functions.php";
    require_once "../../vendor/autoload.php";
    require_once "../../asset/AdminLTE-3.2.0/plugins/dompdf/autoload.inc.php";
    
    use Dompdf\Dompdf;
    use Dompdf\Options;
    
    // Cek jika ini request untuk view PDF
    if (isset($_GET['action']) && $_GET['action'] == 'view_pdf') {
        
        // Ambil nomor peminjaman dari GET
        $nomor_peminjaman = isset($_GET['nomor_peminjaman']) ? $_GET['nomor_peminjaman'] : '';
        
        if (empty($nomor_peminjaman)) {
            die("Nomor peminjaman tidak ditemukan.");
        }
        
        // PERBAIKAN: Query yang lebih aman untuk handle tanggal - tanpa DATE() function
        $query = "SELECT 
                    m.nomor_peminjaman,
                    m.tanggal_peminjaman,
                    m.entitas_peminjam,
                    m.entitas_dipinjam,
                    m.gudang_asal,
                    m.gudang_tujuan,
                    m.produk,
                    m.qty,
                    m.status_peminjaman,
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
                    bdc_tujuan.no_telepon as no_telp_tujuan
                  FROM peminjaman_stok m
                  LEFT JOIN gudang_omni go_asal ON go_asal.nama_gudang COLLATE utf8mb4_unicode_ci = m.gudang_asal COLLATE utf8mb4_unicode_ci
                  LEFT JOIN gudang_omni go_tujuan ON go_tujuan.nama_gudang COLLATE utf8mb4_unicode_ci = m.gudang_tujuan COLLATE utf8mb4_unicode_ci
                  LEFT JOIN base_dc bdc_asal ON bdc_asal.id = go_asal.id_dc
                  LEFT JOIN base_dc bdc_tujuan ON bdc_tujuan.id = go_tujuan.id_dc
                  WHERE m.nomor_peminjaman = ?
                  ORDER BY m.id ASC";
        
        $stmt = mysqli_prepare($db_dc, $query);
        if (!$stmt) {
            die("Error preparing query: " . mysqli_error($db_dc));
        }
        
        mysqli_stmt_bind_param($stmt, "s", $nomor_peminjaman);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        
        $pengembalianData = [];
        $headerData = null;
        $totalQty = 0;
        
        while ($row = mysqli_fetch_assoc($result)) {
            if ($headerData === null) {
                // PERBAIKAN: Validasi tanggal sebelum digunakan
                $tanggal_peminjaman = $row['tanggal_peminjaman'];
                if ($tanggal_peminjaman && $tanggal_peminjaman !== '0000-00-00') {
                    // Validasi format tanggal
                    $date = DateTime::createFromFormat('Y-m-d', $tanggal_peminjaman);
                    if (!$date) {
                        $tanggal_peminjaman = date('Y-m-d'); // Default ke hari ini jika invalid
                    }
                } else {
                    $tanggal_peminjaman = date('Y-m-d'); // Default ke hari ini jika NULL
                }
                
                $headerData = [
                    'nomor_peminjaman' => $row['nomor_peminjaman'],
                    'tanggal_peminjaman' => $tanggal_peminjaman, // ← Sudah divalidasi
                    'entitas_peminjam' => $row['entitas_peminjam'],
                    'entitas_dipinjam' => $row['entitas_dipinjam'],
                    'gudang_asal' => $row['gudang_asal'],
                    'gudang_tujuan' => $row['gudang_tujuan'],
                    'status_peminjaman' => $row['status_peminjaman'],
                    'dc_asal' => $row['dc_asal'] ?? '',
                    'telp_asal' => $row['telp_asal'] ?? '',
                    'tim_asal' => $row['tim_asal'] ?? '',
                    'dc_tujuan' => $row['dc_tujuan'] ?? '',
                    'telp_tujuan' => $row['telp_tujuan'] ?? '',
                    'tim_tujuan' => $row['tim_tujuan'] ?? '',
                    // Data dari base_dc untuk gudang peminjam
                    'alamat_asal' => $row['alamat_asal'] ?? '',
                    'pj_asal' => $row['pj_asal'] ?? '',
                    'no_telp_asal' => $row['no_telp_asal'] ?? '',
                    // Data dari base_dc untuk gudang dipinjam
                    'alamat_tujuan' => $row['alamat_tujuan'] ?? '',
                    'pj_tujuan' => $row['pj_tujuan'] ?? '',
                    'no_telp_tujuan' => $row['no_telp_tujuan'] ?? ''
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
        
        // PERBAIKAN: Format tanggal Indonesia dengan handle NULL/invalid dates
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
        
        // Format nomor telepon
        $formatTelp = function($telp) {
            if (empty($telp)) return '';
            $telp = preg_replace('/[^0-9]/', '', $telp);
            if (strlen($telp) >= 10) {
                return substr($telp, 0, 4) . '-' . substr($telp, 4, 4) . '-' . substr($telp, 8);
            }
            return $telp;
        };
        
        // Prioritas: gunakan no_telepon dari base_dc, jika kosong gunakan no_hp dari gudang_omni
        $telpAsal = !empty($headerData['no_telp_asal']) ? $formatTelp($headerData['no_telp_asal']) : $formatTelp($headerData['telp_asal']);
        $telpTujuan = !empty($headerData['no_telp_tujuan']) ? $formatTelp($headerData['no_telp_tujuan']) : $formatTelp($headerData['telp_tujuan']);
        
        // Fungsi untuk mengganti "Jogja" menjadi "Yogyakarta"
        $replaceJogja = function($text) {
            if (empty($text)) return $text;
            return str_replace('Jogja', 'Yogyakarta', $text);
        };
        
        // Replace "Jogja" menjadi "Yogyakarta" untuk DC asal dan tujuan
        $dcAsalDisplay = !empty($headerData['dc_asal']) ? $replaceJogja($headerData['dc_asal']) : '';
        $dcTujuanDisplay = !empty($headerData['dc_tujuan']) ? $replaceJogja($headerData['dc_tujuan']) : '';
        
        // Ekstrak nama kota dari dc_asal (setelah "DC ")
        $namaKota = '';
        if (!empty($headerData['dc_asal'])) {
            $dcAsalReplaced = $replaceJogja($headerData['dc_asal']);
            // Jika dimulai dengan "DC ", ambil text setelahnya
            if (stripos($dcAsalReplaced, 'DC ') === 0) {
                $namaKota = trim(substr($dcAsalReplaced, 3));
            } else {
                // Jika tidak ada prefix "DC ", gunakan langsung
                $namaKota = $dcAsalReplaced;
            }
        }
        
        // Generate keterangan pengembalian default
        $keteranganPengembalianDefault = "Pengembalian dari Gudang " . htmlspecialchars($headerData['gudang_asal']) . " (Gudang Pengembali) Ke Gudang " . htmlspecialchars($headerData['gudang_tujuan']) . " (Gudang Penerima)";
        
        // Generate HTML untuk PDF sesuai format gambar
        $html = '
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Surat Jalan Pengembalian - ' . htmlspecialchars($headerData['nomor_peminjaman']) . '</title>
    <style>
        @page {
            size: A4 landscape;
            margin: 10mm;
        }
        
        body {
            font-family: Arial, sans-serif;
            font-size: 10pt;
            margin: 0;
            padding: 0;
        }
        
        .header-container {
            margin-bottom: 15px;
        }
        
        .header-title {
            text-align: center;
            font-size: 20pt;
            font-weight: bold;
            margin-bottom: 5px;
        }
        
        .header-number {
            text-align: right;
            font-size: 13pt;
            font-weight: bold;
            margin-bottom: 5px;
        }
        
        .separator-line {
            border-top: 3px solid #000;
            margin: 10px 0;
        }
        
        .gudang-info {
            margin-bottom: 15px;
        }
        
        .gudang-title {
            font-weight: bold;
            font-size: 11pt;
            margin-bottom: 3px;
        }
        
        .gudang-title .tim-name {
            font-size: 9pt;
        }
        
        .gudang-name {
            margin-bottom: 2px;
        }
        
        .gudang-address {
            margin-bottom: 2px;
            font-size: 9pt;
        }
        
        .gudang-telp {
            margin-bottom: 2px;
            font-size: 9pt;
        }
        
        .date-info {
            text-align: right;
            margin-bottom: 15px;
        }
        
        .jumlah-koli {
            margin: 10px 0;
            font-weight: bold;
            color: #cc0000;
            border-bottom: 2px solid #cc0000;
            padding-bottom: 3px;
            background-color: #fffacd;
            display: inline-block;
        }
        
        .detail-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 20px;
        }
        
        .detail-table th {
            background-color: #b0d4f1;
            border: 1px solid #000;
            padding: 8px;
            text-align: center;
            font-weight: bold;
            font-size: 10pt;
        }
        
        .detail-table td {
            border: 1px solid #000;
            padding: 6px;
            text-align: left;
            font-size: 9pt;
        }
        
        .detail-table td.center {
            text-align: center;
        }
        
        .detail-table td.right {
            text-align: right;
        }
        
        .total-row {
            font-weight: bold;
            background-color: #90ee90;
        }
        
        .footer {
            margin-top: 30px;
            display: table;
            width: 100%;
        }
        
        .footer-row {
            display: table-row;
        }
        
        .footer-cell {
            display: table-cell;
            width: 33.33%;
            padding: 10px;
            vertical-align: top;
        }
        
        .signature-line {
            text-align: center;
            margin-top: 60px;
        }
        
        .signature-line div {
            margin-bottom: 5rem;
        }
        
        .signature-line::after {
            content: "";
            display: block;
            border-top: 1px solid #000;
            width: 50%;
            margin: 0 auto;
        }
    </style>
</head>
<body>
    <div class="header-container">
        <div class="header-title">SURAT JALAN PENGEMBALIAN</div>
        <div class="header-number">' . htmlspecialchars($headerData['nomor_peminjaman']) . '</div>
    </div>
    
    <div class="separator-line"></div>
    
    <div style="display: table; width: 100%;">
        <div style="display: table-row;">
            <div style="display: table-cell; width: 50%; vertical-align: top; padding-right: 20px;">
                <div class="gudang-info">
                    <div class="gudang-title">' . (!empty($headerData['dc_asal']) ? 'DC ' . htmlspecialchars($dcAsalDisplay) . (!empty($headerData['tim_asal']) ? ' (<span class="tim-name">' . htmlspecialchars($headerData['tim_asal']) . '</span>)' : '') : htmlspecialchars($headerData['gudang_asal'])) . '</div>
                    <div class="gudang-name">' . (!empty($headerData['pj_asal']) ? htmlspecialchars($headerData['pj_asal']) : '') . '</div>
                    <div class="gudang-address">' . (!empty($headerData['alamat_asal']) ? htmlspecialchars($headerData['alamat_asal']) : '') . '</div>
                    <div class="gudang-telp">' . (!empty($telpAsal) ? htmlspecialchars($telpAsal) : '') . '</div>
                </div>
            </div>
            <div style="display: table-cell; width: 50%; vertical-align: top; text-align: right;">
                <div class="date-info">
                    ' . (!empty($namaKota) ? htmlspecialchars($namaKota) . ', ' : '') . formatTanggalIndonesia($headerData['tanggal_peminjaman']) . '
                </div>
            </div>
        </div>
    </div>
    
    <div style="margin-top: 15px;">
        <div style="font-weight: bold; margin-bottom: 5px;">To:</div>
        <div class="gudang-info">
            <div class="gudang-title">' . (!empty($headerData['dc_tujuan']) ? 'DC ' . htmlspecialchars($dcTujuanDisplay) . (!empty($headerData['tim_tujuan']) ? ' (<span class="tim-name">' . htmlspecialchars($headerData['tim_tujuan']) . '</span>)' : '') : htmlspecialchars($headerData['gudang_tujuan'])) . '</div>
            <div class="gudang-name">' . (!empty($headerData['pj_tujuan']) ? htmlspecialchars($headerData['pj_tujuan']) : '') . '</div>
            <div class="gudang-address">' . (!empty($headerData['alamat_tujuan']) ? htmlspecialchars($headerData['alamat_tujuan']) : '') . '</div>
            <div class="gudang-telp">' . (!empty($telpTujuan) ? htmlspecialchars($telpTujuan) : '') . '</div>
        </div>
    </div>
    
    <div class="jumlah-koli">JUMLAH KOLI:</div>
    
    <table class="detail-table">
        <thead>
            <tr>
                <th style="width: 8%;">No.</th>
                <th style="width: 45%;">Produk</th>
                <th style="width: 15%;">Qty</th>
                <th style="width: 32%;">Keterangan</th>
            </tr>
        </thead>
        <tbody>';
        
        $no = 1;
        foreach ($pengembalianData as $item) {
            $keterangan = ''; // Keterangan kosong untuk pengembalian
            
            $html .= '
            <tr>
                <td class="center">' . $no . '</td>
                <td>' . htmlspecialchars($item['produk']) . '</td>
                <td class="center">' . number_format($item['qty'], 0, ',', '.') . '</td>
                <td>' . $keterangan . '</td>
            </tr>';
            $no++;
        }
        
        $html .= '
            <tr class="total-row">
                <td></td>
                <td><strong>TOTAL</strong></td>
                <td class="center"><strong>' . number_format($totalQty, 0, ',', '.') . '</strong></td>
                <td></td>
            </tr>
        </tbody>
    </table>
    
    <div class="footer">
        <div class="footer-row">
            <div class="footer-cell">
                <div class="signature-line">
                    <div>Penerima</div>
                </div>
            </div>
            <div class="footer-cell">
                <div class="signature-line">
                    <div>Ekspedisi</div>
                </div>
            </div>
            <div class="footer-cell">
                <div class="signature-line">
                    <div>Admin DC</div>
                </div>
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
        
        // Output PDF dalam mode preview (tanpa download)
        $dompdf->stream('Surat_Jalan_Pengembalian_' . $headerData['nomor_peminjaman'] . '.pdf', [
            'Attachment' => 0  // 0 = preview, 1 = download
        ]);
        
        exit(); // Keluar setelah generate PDF
    }

    // Data Web
    $namaHalaman = "Pengembalian Stok";

    $title  = $basetitle." - ".$namaHalaman;

    require_once "../../template/header.php";
    require_once "../../template/navbar.php";
    require_once "../../template/sidebar.php";

    // Cek apakah ada parameter start_date dan end_date pada URL
    if (isset($_GET['start_date']) && isset($_GET['end_date'])) {
        $start_date_default2    = $_GET['start_date'];
        $end_date_default1      = $_GET['end_date'];
    } else {
        $start_date_default2    = date('Y-m-d', strtotime('-29 days'));
        $end_date_default1      = date('Y-m-d');
    }

    if (isset($_GET['msg'])) {
        $msg = $_GET['msg'];
    }else{
        $msg = '';
    }

    if (isset($_GET['data'])) {
        $data = $_GET['data'];
    }else{
        $data = '';
    }

    $idEntitasPeminjam = isset($_GET['entitas_peminjam']) ? $_GET['entitas_peminjam'] : '';
    $idEntitasDipinjam = isset($_GET['entitas_dipinjam']) ? $_GET['entitas_dipinjam'] : '';
    $gudangAsal = isset($_GET['gudang_asal']) ? $_GET['gudang_asal'] : '';
    $gudangTujuan = isset($_GET['gudang_tujuan']) ? $_GET['gudang_tujuan'] : '';

    // Notifikasi
    $alert = '';
      if ($msg == 'added') {
        $alert = '<div class="alert alert-success alert-dismissible fade show" role="alert">
        <i class="fa-solid fa-circle-check"></i>'.$data.' Data '.$namaHalaman.' berhasil ditambah!
      </div>';
      }
      if ($msg == 'updated') {
        $alert = '<div class="alert alert-success alert-dismissible fade show" role="alert">
        <i class="fa-solid fa-circle-check"></i> Data '.$namaHalaman.' berhasil di-update!
      </div>';
      }
      if ($msg == 'deleted') {
        $alert = '<div class="alert alert-success alert-dismissible fade show" role="alert">
        <i class="fa-solid fa-circle-check"></i> Data '.$namaHalaman.' berhasil dihapus!
      </div>';
      }
      if ($msg == 'error') {
        $alert = '<div class="alert alert-danger alert-dismissible fade show" role="alert">
        <i class="fa-solid fa-circle-exclamation"></i> Proses error!
      </div>';
      }

    // Query untuk mendapatkan produk dari omni_stok_akhir
    $product = getDataDc("SELECT DISTINCT nama FROM omni_stok_akhir ORDER BY nama");

    // Generate opsi untuk dropdown produk
    $productOptions = "<option value=''>-- Pilih Produk --</option>";
    foreach ($product as $produk) {
        $productOptions .= "<option value='{$produk['nama']}'>{$produk['nama']}</option>";
    }
    
    // Query untuk mendapatkan gudang
    $gudang = getDataDc("SELECT DISTINCT nama_gudang FROM gudang_omni ORDER BY nama_gudang");
    
    // Generate opsi untuk dropdown gudang
    $gudangOptions = "<option value=''>-- Pilih Gudang --</option>";
    foreach ($gudang as $gud) {
        $gudangOptions .= "<option value='{$gud['nama_gudang']}'>{$gud['nama_gudang']}</option>";
    }

    // Query untuk mendapatkan data entitas
    $entitasList = getDataDc("SELECT DISTINCT be.inisial as entitas, be.id as id_entitas 
                             FROM base_entitas be 
                             INNER JOIN base_tim bt ON bt.id_entitas = be.id 
                             INNER JOIN gudang_omni go ON go.tim = bt.tim 
                             WHERE be.inisial IS NOT NULL AND TRIM(be.inisial) <> '' 
                             ORDER BY be.inisial ASC");

    // Generate opsi untuk dropdown entitas peminjam
    $entitasPeminjamOptions = "<option value=''>-- Pilih Entitas Pengembali --</option>";
    foreach ($entitasList as $ent) {
        $entitasPeminjamOptions .= "<option value='{$ent['entitas']}'>{$ent['entitas']}</option>";
    }
    
    // Generate opsi untuk dropdown entitas dipinjam
    $entitasDipinjamOptions = "<option value=''>-- Pilih Entitas Penerima --</option>";
    foreach ($entitasList as $ent) {
        $entitasDipinjamOptions .= "<option value='{$ent['entitas']}'>{$ent['entitas']}</option>";
    }
?>

<!-- Content Wrapper. Contains page content -->
<div class="content-wrapper">
    <!-- Content Header (Page header) -->
    <div class="content-header">
        <div class="container-fluid">
            <div class="row mb-2">
                <div class="col-sm-6">
                    <h1 class="ml-3 text-bold"><?= $namaHalaman ?></h1>
                </div><!-- /.col -->
                <div class="col-sm-6">
                    <ol class="breadcrumb float-sm-right">
                        <li class="breadcrumb-item"><a href="<?= $dashboard ?>"><i class="fas fa-home"></i> Home</a></li>
                        <li class="breadcrumb-item active"><?= $namaHalaman ?></li>
                    </ol>
                </div><!-- /.col -->

                    <div class="col-12 px-4 mt-2">
                        <div class="alert alert-info alert-dismissible fade show" role="alert">
                            <i class="fas fa-exclamation-circle"></i> Menampilkan data <?= $namaHalaman ?> Tanggal <strong><?= indoTgl($start_date_default2) ?></strong> s/d <strong><?= indoTgl($end_date_default1) ?></strong>
                            <button type="button" class="close" data-dismiss="alert" aria-label="Close">
                                <span aria-hidden="true">&times;</span>
                            </button>
                        </div>
                    </div>
            </div><!-- /.row -->
        </div><!-- /.container-fluid -->
    </div>
    <!-- /.content-header -->

    <?php // Notifikasi
        if ($msg !== '') {
            echo $alert;
        ?>
        <script>
            setTimeout(function() {
                document.querySelector('.alert').style.display = 'none';
            }, 3500);
        </script>
        <?php
        }
    ?>

    <!-- Main content -->
    <section class="content">
        <div class="container-fluid">
            <div class="row mr-3 mb-3"> <!-- Tombol Tambah -->
                <div class="col-12 d-flex justify-content-end">
                    <a class="btn btn-success float-right" data-toggle="modal" data-target="#tambahModal" id="btnTambahData">
                        <i class="fas fa-plus-square"></i> Tambah Data
                    </a>
                </div>
            </div>
        </div>

        <!-- Card Peminjaman dan Pengembalian -->
        <div class="row ml-3 mr-3 mb-3">
            <div class="col-md-6">
                <div class="card type-card" data-type="peminjaman" style="cursor: pointer; border-radius: 8px; transition: all 0.3s;">
                    <div class="card-body text-center p-4" style="background-color: #ffffff; color: #333;">
                        <h6 class="mb-2" style="font-weight: bold; text-transform: uppercase; font-size: 14px;">PEMINJAMAN</h6>
                        <h3 class="mb-0" style="font-weight: bold; font-size: 36px;" id="countPeminjaman">0</h3>
                    </div>
                </div>
            </div>
            <div class="col-md-6">
                <div class="card type-card" data-type="pengembalian" style="cursor: pointer; border-radius: 8px; transition: all 0.3s;">
                    <div class="card-body text-center p-4" style="background-color: #007bff; color: white;">
                        <h6 class="mb-2" style="font-weight: bold; text-transform: uppercase; font-size: 14px;">PENGEMBALIAN</h6>
                        <h3 class="mb-0" style="font-weight: bold; font-size: 36px;" id="countPengembalian">0</h3>
                    </div>
                </div>
            </div>
        </div>

        <!-- Formulir Filter -->
        <div class="row ml-3 mr-3">
            <div class="col-12">
                <div class="card mb-3">
                    <div class="card-header d-flex justify-content-between align-items-center" id="headingOne" data-toggle="collapse" data-target="#filterFormContainer" aria-expanded="true" aria-controls="filterFormContainer" style="cursor: pointer;">
                        <h5 class="card-title mb-0 text-bold"><i class="fas fa-filter"></i> Filter Data</h5>
                        <i class="fas fa-chevron-down ml-auto" id="toggleIcon"></i>
                    </div>
                    <div id="filterFormContainer" class="collapse">
                        <div class="card-body">
                            <form id="filterForm" class="form">
                                <div class="form-row">
                                    <div class="form-group col-md-3">
                                        <label for="entitas_peminjam">Pilih Entitas Pengembali:</label>
                                        <select name="entitas_peminjam[]" id="entitas_peminjam" class="form-control select2" multiple="multiple" style="width: 100%;">
                                            <?php
                                                foreach ($entitasList as $ent) {
                                                    $selected = (in_array($ent['entitas'], explode(',', $idEntitasPeminjam))) ? 'selected' : '';
                                                    echo '<option value="' . htmlspecialchars($ent['entitas']) . '" ' . $selected . '>' . htmlspecialchars($ent['entitas']) . '</option>';
                                                }
                                            ?>
                                        </select>
                                    </div>
                                    
                                    <div class="form-group col-md-3">
                                        <label for="entitas_dipinjam">Pilih Entitas Penerima:</label>
                                        <select name="entitas_dipinjam[]" id="entitas_dipinjam" class="form-control select2" multiple="multiple" style="width: 100%;">
                                            <?php
                                                foreach ($entitasList as $ent) {
                                                    $selected = (in_array($ent['entitas'], explode(',', $idEntitasDipinjam))) ? 'selected' : '';
                                                    echo '<option value="' . htmlspecialchars($ent['entitas']) . '" ' . $selected . '>' . htmlspecialchars($ent['entitas']) . '</option>';
                                                }
                                            ?>
                                        </select>
                                    </div>

                                    <div class="form-group col-md-4">
                                        <label for="daterange">Pilih Tanggal:</label>
                                        <div id="daterange" class="form-control" style="background: #fff; cursor: pointer; padding: 5px 10px; border: 1px solid #ccc;">
                                            <i class="fa fa-calendar"></i>&nbsp;
                                            <span></span> <i class="fa fa-caret-down"></i>
                                        </div>
                                        <input type="hidden" id="start_date" name="start_date" value="<?= htmlspecialchars($start_date_default2) ?>" />
                                        <input type="hidden" id="end_date" name="end_date" value="<?= htmlspecialchars($end_date_default1) ?>" />
                                    </div>
                                </div>
                                <div class="form-row align-items-end">
                                    <div class="form-group col-md-2">
                                        <button type="button" class="btn btn-primary btn-block" id="filterButton"><i class="fas fa-search"></i> Filter</button>
                                    </div>
                                    <div class="form-group col-md-2">
                                        <button type="button" class="btn btn-outline-secondary btn-block" id="resetButton"><i class="fas fa-trash"></i> Reset</button>
                                    </div>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Table Data -->
        <div class="row ml-3 mr-3">
            <div class="col-12">
                <div class="card">
                    <div class="card-body">
                        <div class="table-responsive">
                            <table id="table-pengembalian" class="table table-hover table-bordered" style="width: 100%;">
                                <thead>
                                    <tr class="text-center">
                                    <th scope="col">Tanggal</th>
                                    <th scope="col">Nomor Peminjaman</th>
                                    <th scope="col">Nomor Pengembalian</th>
                                    <th scope="col">Entitas Pengembali</th>
                                    <th scope="col">Entitas Penerima</th>
                                    <th scope="col">Jumlah Item</th>
                                    <th scope="col">Stok Dipinjam</th>
                                    <th scope="col">Total Qty</th>
                                    <th scope="col" width="10%">Status</th>
                                    <th scope="col" width="5%">Aksi</th>
                                    </tr>
                                </thead>
                                <tbody>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>
</div>

<?php
    require_once "../../template/footer.php";
?>

<!-- Modal Tambah Data Pengembalian -->
<div class="modal fade" id="tambahModal" data-backdrop="static" data-keyboard="false" tabindex="-1" aria-labelledby="tambahModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form id="tambahForm" method="post" enctype="multipart/form-data">
                <div class="modal-header">
                    <h5 class="modal-title font-weight-bold" id="tambahModalLabel">Tambah Pengembalian Stok</h5>
                    <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>
                <div class="modal-body">
                    
                    <!--hidden input for edit-->
                    <input type="hidden" name="edit_id" id="edit_id">

                    <div class="form-row align-items-end">
                        <!-- Nomor Peminjaman - DI ATAS TANGGAL -->
                        <div class="form-group col-md-6">
                            <label for="in_nomor_peminjaman">Nomor Peminjaman</label>
                            <select class="form-control select2" name="in_nomor_peminjaman" id="in_nomor_peminjaman" required>
                                <option value="">-- Pilih Nomor Peminjaman --</option>
                                <!-- Options akan diisi via AJAX -->
                            </select>
                        </div>

                        <div class="form-group col-md-6">
                            <label for="in_tanggal">Tanggal Pengembalian</label>
                            <input type="date" name="in_tanggal" class="form-control" id="in_tanggal" value="<?= date("Y-m-d") ?>" required>
                        </div>

                        <div class="form-group col-md-6">
                            <label for="in_entitas_peminjam">Entitas Pengembali</label>
                            <input type="text" class="form-control" id="in_entitas_peminjam" readonly>
                            <input type="hidden" name="in_entitas_peminjam" id="in_entitas_peminjam_hidden">
                        </div>
                        
                        <div class="form-group col-md-6">
                            <label for="in_entitas_dipinjam">Entitas Penerima</label>
                            <input type="text" class="form-control" id="in_entitas_dipinjam" readonly>
                            <input type="hidden" name="in_entitas_dipinjam" id="in_entitas_dipinjam_hidden">
                        </div>

                        <div class="form-group col-md-6">
                            <label for="in_gudang_asal">Gudang Pengembali</label>
                            <input type="text" class="form-control" id="in_gudang_asal" readonly>
                            <input type="hidden" name="in_gudang_asal" id="in_gudang_asal_hidden">
                        </div>

                        <div class="form-group col-md-6">
                            <label for="in_gudang_tujuan">Gudang Penerima</label>
                            <input type="text" class="form-control" id="in_gudang_tujuan" readonly>
                            <input type="hidden" name="in_gudang_tujuan" id="in_gudang_tujuan_hidden">
                        </div>

                        <div class="form-group col-md-6">
                            <label class="mb-1" for="in_nomor_pengembalian">Nomor Pengembalian</label>
                            <input type="text" id="in_nomor_pengembalian" name="in_nomor_pengembalian" class="form-control in_nomor_pengembalian" readonly>
                        </div>

                    </div>
                    
                    <!-- Tabel Detail Produk dari Peminjaman -->
                    <div class="mb-3">
                        <h6 class="font-weight-bold">Detail Produk yang Dikembalikan</h6>
                    </div>
                    
                    <table class="table table-striped table-bordered table-hover mb-0 mt-3" id="detailTable">
                        <thead>
                            <tr class="text-center">
                                <th width="5%">No</th>
                                <th width="25%">Produk</th>
                                <th width="12%">Jumlah Dipinjam</th>
                                <th width="12%">Stok</th>
                                <th width="15%">Jumlah Dikembalikan</th>
                                <th width="5%">Aksi</th>
                            </tr>
                        </thead>
                        <tbody id="tbodyListData">
                             <tr id="emptyRow">
                                <td colspan="6" class="text-center">Pilih Nomor Peminjaman untuk melihat detail produk</td>
                             </tr>
                        </tbody>
                    </table>
                </div>

                <div class="modal-footer actionAddData">
                    <button type="button" class="btn btn-sm btn-outline-secondary" data-dismiss="modal"><i class="fas fa-times-circle"></i> Tutup</button>
                    <button type="submit" class="btn btn-warning" name="in_action_button" value="Draft" id="draftButton"><i class="fas fa-file-alt"></i> Simpan sebagai Draft</button>
                    <button type="submit" class="btn btn-primary" name="in_action_button" value="Final" id="submitButton"><i class="fas fa-check-circle"></i> Simpan sebagai Final</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal Detail Pengembalian -->
<div class="modal fade" id="viewDetailModal" data-backdrop="static" data-keyboard="false" tabindex="-1" aria-labelledby="viewDetailModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-xl" style="max-width: 95%;">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title font-weight-bold" id="viewDetailModalLabel" style="font-size: 1.5rem;">Detail Data Pengembalian</h5>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <div class="modal-body" style="padding: 20px;">
                <div class="row mb-4">
                    <!-- Kolom Kiri: Detail Peminjaman -->
                    <div class="col-md-6">
                        <div class="card" style="border: none; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">
                            <div class="card-body">
                                <h6 class="font-weight-bold mb-3">Detail Pengembalian</h6>
                                <div class="mb-2"><strong>Tanggal Pengembalian:</strong> <span id="detailTanggalPeminjaman">-</span></div>
                                <div class="mb-2"><strong>Nomor Pengembalian:</strong> <span id="detailNomorPeminjaman">-</span></div>
                                <div class="mb-2"><strong>Entitas Pengembali:</strong> <span id="detailEntitasPeminjam">-</span></div>
                                <div class="mb-2"><strong>Entitas Penerima:</strong> <span id="detailEntitasDipinjam">-</span></div>
                                <div class="mb-2"><strong>Gudang Pengembali:</strong> <span id="detailGudangAsal">-</span></div>
                                <div class="mb-2"><strong>Gudang Penerima:</strong> <span id="detailGudangTujuan">-</span></div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Kolom Kanan: Warehouse Identity -->
                    <div class="col-md-6">
                        <div class="card" style="border: none; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">
                            <div class="card-body">
                                <h6 class="font-weight-bold mb-3">
                                    <i class="fas fa-warehouse mr-2"></i>Entitas Identity
                                </h6>
                                <div class="mb-2">
                                    <i class="fas fa-user mr-2 text-muted"></i>
                                    <strong>Name:</strong> <span id="detailPjAsal">-</span>
                                </div>
                                <div class="mb-2">
                                    <i class="fas fa-map-marker-alt mr-2 text-muted"></i>
                                    <strong>Address:</strong> <span id="detailAlamatAsal">-</span>
                                </div>
                                <div class="mb-2">
                                    <i class="fas fa-phone mr-2 text-muted"></i>
                                    <strong>Phone:</strong> <span id="detailNoTelpAsal">-</span>
                                </div>
                                <div class="mb-2">
                                    <i class="fas fa-envelope mr-2 text-muted"></i>
                                    <strong>Email:</strong> 
                                    <a href="#" id="detailEmailAsal" style="color: #007bff;">-</a>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Tabel Produk -->
                <div class="table-responsive">
                    <table class="table table-bordered table-hover" id="detailProductsTable">
                        <thead class="thead-light">
                            <tr>
                                <th>No</th>
                                <th>Gudang</th>
                                <th>Produk</th>
                                <th>Jumlah</th>
                                <th>Jml. Terkirim</th>
                                <th>Jml. Diterima</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody id="detailProductsBody">
                            <!-- Data akan diisi via JavaScript -->
                        </tbody>
                        <tfoot>
                            <tr class="font-weight-bold">
                                <td colspan="3" class="text-right">Total</td>
                                <td id="detailTotalJumlah">0</td>
                                <td id="detailTotalTerkirim">0</td>
                                <td id="detailTotalDiterima">0</td>
                                <td></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
                
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-dismiss="modal">
                    <i class="fas fa-times"></i> Tutup
                </button>
                <button type="button" class="btn btn-primary simpanPerubahanPengembalian" data-nomor-pengembalian="" style="display:none;">
                    <i class="fas fa-save"></i> Simpan Perubahan
                </button>
                <a id="downloadPdfLink" href="#" class="btn btn-success" target="_blank">
                    <i class="fas fa-file-pdf"></i> Download SJ
                </a>
            </div>
        </div>
    </div>
</div>

<style>
    .modal {
        overflow: visible !important;
    }
    .modal-body {
        max-height: 70vh;
        overflow-y: auto;
        overflow-x: auto;
        white-space: nowrap;
    }
    
    /* Styling untuk tabel peminjaman agar sesuai dengan card */
    #table-pengembalian_wrapper {
        width: 100%;
        overflow: hidden;
    }
    
    #table-pengembalian {
        width: 100% !important;
        table-layout: auto;
    }
    
    .table-responsive {
        width: 100%;
        overflow-x: auto;
        -webkit-overflow-scrolling: touch;
    }
    
    /* Pastikan card body tidak overflow */
    .card-body {
        padding: 1rem;
        overflow-x: hidden;
    }
    
    /* DataTables wrapper */
    .dataTables_wrapper {
        width: 100%;
        position: relative;
    }
    
    .dataTables_scroll {
        width: 100% !important;
    }
    
    .dataTables_scrollHead,
    .dataTables_scrollBody {
        width: 100% !important;
    }
    
    /* Pastikan Select2 memiliki lebar yang konsisten */
    #detailTable select.select2 {
        width: 100% !important;
    }
    
    .select2-container {
        width: 100% !important;
    }
    
    /* Pastikan kolom produk memiliki lebar yang sama */
    #detailTable td:nth-child(2) {
        width: 35%;
    }
    
    #detailTable td:nth-child(2) .select2-container {
        width: 100% !important;
    }
    
    /* Styling untuk input invalid */
    input.is-invalid {
        border-color: #dc3545;
        background-color: #fff5f5;
    }
    
    input.is-invalid:focus {
        border-color: #dc3545;
        box-shadow: 0 0 0 0.2rem rgba(220, 53, 69, 0.25);
    }
</style>

<script type="text/javascript">
    $(document).ready(function() {

        window.isEditMode = false;

        // Load gudang berdasarkan entitas yang dipilih (global function) - HARUS didefinisikan SEBELUM handler edit
        window.loadGudangByEntitas = function(entitas, targetSelect, callback) {
            if (!entitas) {
                targetSelect.prop('disabled', true).html('<option value="">-- Pilih Entitas dulu --</option>').trigger('change');
                if (callback) callback();
                return;
            }
            
            $.post("get_data.php?action=get_gudang_by_entitas", { entitas: entitas }, function (response) {
                try {
                    // Handle response yang sudah berupa object atau masih string
                    const data = typeof response === 'string' ? JSON.parse(response) : response;
                    let options = '<option value="">-- Pilih Gudang --</option>';
                    if (data.gudang && data.gudang.length > 0) {
                        data.gudang.forEach(function(g) {
                            options += '<option value="' + g.nama_gudang + '">' + g.nama_gudang + '</option>';
                        });
                    }
                    targetSelect.prop('disabled', false).html(options).trigger('change');
                    if (callback) callback();
                } catch (e) {
                    targetSelect.prop('disabled', true).html('<option value="">Error loading gudang</option>');
                    if (callback) callback();
                }
            }, 'json').fail(function (xhr, status, error) {
                targetSelect.prop('disabled', true).html('<option value="">Error loading gudang</option>');
                if (callback) callback();
            });
        };

        window.updateSubmitButtonState = function() {
            // Skip validasi jika tombol sudah disabled karena status bukan Draft
            if ($('#draftButton').prop('disabled') && $('#submitButton').prop('disabled')) {
                const isDraftDisabled = $('#draftButton').data('disabled-by-status');
                const isSubmitDisabled = $('#submitButton').data('disabled-by-status');
                if (isDraftDisabled || isSubmitDisabled) {
                    return; // Jangan update jika disabled karena status
                }
            }
            
            let hasInvalid = false;
            let hasData = false;
            let rowCount = 0;
            
            $('#tbodyListData tr').each(function() {
                const row = $(this);
                // Skip empty row
                if (row.attr('id') === 'emptyRow') {
                    return true; // continue
                }
                
                rowCount++;
                const jumlahInput = row.find('input[name="in_jumlah[]"]');
                const produkSelect = row.find('select[name="in_produk[]"]');
                const stokText = row.find('.stok-display').text();
                const stok = stokText !== '-' ? parseFloat(stokText) || 0 : 0;
                
                if (jumlahInput.length && produkSelect.length) {
                    const jumlah = parseFloat(jumlahInput.val()) || 0;
                    const produk = produkSelect.val();
                    
                    if (produk && jumlah > 0) {
                        hasData = true;
                    }
                    
                    // Validasi: jumlah harus > 0
                    if (produk && jumlah <= 0) {
                        hasInvalid = true;
                        return false; // break loop
                    }
                    
                    // Validasi: jumlah tidak boleh > stok
                    if (produk && stok > 0 && jumlah > stok) {
                        hasInvalid = true;
                        return false; // break loop
                    }
                }
            });
            
            // Disable tombol submit jika ada jumlah <= 0 atau tidak ada data sama sekali (dan ada row)
            if (rowCount > 0) {
                $('#draftButton, #submitButton').prop('disabled', hasInvalid || !hasData);
            }
        };
    
        // Fitur edit untuk pengembalian (sama seperti peminjaman)
        $(document).on("click", ".edit_btn", function () {
            $("#submitButton").text("Update sebagai Final");
            $("#draftButton").text("Update sebagai Draft");
            $("#tambahModalLabel").text("Update Pengembalian Stok");
            window.isEditMode = true;
        
            let nomor_pengembalian = $(this).data('nomor_pengembalian') || '';
            let nomor_peminjaman = $(this).data('nomor_peminjaman') || ''; // Untuk pengembalian, ambil nomor_peminjaman
            let statusPengembalian = $(this).data('status') || 'Draft';
            let minId = parseInt($(this).data('min-id')) || 0;
            let isDraftNoNomor = $(this).data('is-draft-no-nomor') == '1';
            
            // Simpan status untuk digunakan di dalam modal
            window.currentStatusPengembalian = statusPengembalian;
            
            // Simpan identifier di window untuk digunakan di event handler
            window.editNomorPengembalian = nomor_pengembalian;
            window.editNomorPeminjaman = nomor_peminjaman; // Simpan nomor_peminjaman untuk pengembalian
            window.editMinId = minId;
            window.editIsDraftNoNomor = isDraftNoNomor;
        
            // Tampilkan modal dulu
            $("#tambahModal").modal("show");
            
            // Ketika modal sudah selesai ditampilkan, baru jalankan AJAX
            $("#tambahModal").off("shown.bs.modal").on("shown.bs.modal", function() {
                let tbody = $("#tbodyListData");
                
                // Gunakan variabel dari window untuk memastikan data tersedia
                let currentNomorPengembalian = window.editNomorPengembalian || '';
                let currentNomorPeminjaman = window.editNomorPeminjaman || ''; // Untuk pengembalian
                let currentMinId = window.editMinId || 0;
                let currentIsDraftNoNomor = window.editIsDraftNoNomor || false;
                
                if (!window.isEditMode) {
                    tbody.empty().append(`
                        <tr id="emptyRow">
                            <td colspan="6" class="text-center">Tidak ada data detail</td>
                        </tr>
                    `);
                    return;
                }
                tbody.empty();
            
                // Untuk pengembalian, gunakan min_id untuk draft atau nomor_pengembalian untuk final
                let ajaxData = { type: 'pengembalian' };
                if (currentIsDraftNoNomor && currentMinId > 0) {
                    // Untuk draft tanpa nomor, gunakan min_id
                    ajaxData.min_id = currentMinId;
                } else if (currentNomorPengembalian) {
                    // Untuk final dengan nomor_pengembalian
                    ajaxData.nomor_pengembalian = currentNomorPengembalian;
                } else if (currentMinId > 0) {
                    // Fallback: gunakan min_id jika ada
                    ajaxData.min_id = currentMinId;
                } else {
                    tbody.empty().append(`
                        <tr id="emptyRow">
                            <td colspan="6" class="text-center text-danger">Error: Tidak ada identifier yang valid</td>
                        </tr>
                    `);
                    return;
                }
                
                $.ajax({
                    url: "get_data.php?action=get_peminjaman_detail",
                    type: "POST",
                    data: ajaxData,
                    dataType: "json",
                    success: function(response) {
                        tbody.empty();
                        
                        // Handle error response
                        if (response && response.error) {
                            tbody.append(`
                                <tr id="emptyRow">
                                    <td colspan="6" class="text-center text-danger">Error: ${response.error}</td>
                                </tr>
                            `);
                            alert("Error: " + response.error);
                            return;
                        }
                        
                        // Handle jika response bukan array
                        if (!Array.isArray(response)) {
                            tbody.append(`
                                <tr id="emptyRow">
                                    <td colspan="6" class="text-center text-danger">Error: Format response tidak valid</td>
                                </tr>
                            `);
                            return;
                        }
                        
                        if (response.length > 0) {
                            // Ambil nomor_peminjaman dari response pertama jika ada (untuk pengembalian)
                            let nomorPeminjamanFromResponse = '';
                            if (response[0] && response[0].nomor_peminjaman) {
                                nomorPeminjamanFromResponse = response[0].nomor_peminjaman;
                                // Set nomor peminjaman jika belum di-set atau kosong
                                const currentNomorPeminjaman = $("#in_nomor_peminjaman").val();
                                if (!currentNomorPeminjaman || currentNomorPeminjaman === '') {
                                    $("#in_nomor_peminjaman").val(nomorPeminjamanFromResponse).trigger('change');
                                }
                            }
                            
                            response.forEach((item, index) => {
                                const isDraft = window.currentStatusPengembalian === 'Draft';
                                // Untuk Draft: hanya jumlah dikembalikan yang bisa diedit, produk tidak bisa diubah
                                const produkDisabledAttr = 'disabled'; // Produk selalu disabled saat edit
                                const jumlahReadonlyAttr = isDraft ? '' : 'readonly'; // Jumlah bisa diubah hanya saat Draft
                                const deleteDisabledAttr = ''; // Tombol hapus selalu enabled
                                
                                // Ambil jumlah dipinjam dan stok dari response
                                const jumlahDipinjam = item.jumlah_dipinjam || 0;
                                const stok = item.stok || 0;
                                
                                let row = $(`
                                    <tr>
                                        <td class="text-center">${index + 1}</td>
                                        <td>
                                            <select name="in_produk[]" class="form-control select-produk" ${produkDisabledAttr}>
                                                <?php echo $productOptions; ?>
                                            </select>
                                        </td>
                                        <td class="text-center">
                                            <strong>${jumlahDipinjam}</strong>
                                            <input type="hidden" name="in_jumlah_dipinjam[]" value="${jumlahDipinjam}">
                                        </td>
                                        <td class="text-center">
                                            <span class="stok-display" style="display: inline-block; padding: 6px 12px; font-weight: bold; color: ${stok > 0 ? '#28a745' : '#dc3545'};">${stok}</span>
                                        </td>
                                        <td class="text-center">
                                            <input type="number" name="in_jumlah_kembali[]" value="${item.qty}" class="form-control text-center" min="0" max="${jumlahDipinjam}" required ${jumlahReadonlyAttr}>
                                            <input type="hidden" name="in_id[]" value="${item.id}" class="form-control text-center">
                                        </td>
                                        <td class="text-center">
                                            <button type="button" class="btn btn-danger btn-sm btnDeleteRow" data-id="${item.id}" ${deleteDisabledAttr}>
                                                <i class="fas fa-trash-alt"></i>
                                            </button>
                                        </td>
                                    </tr>
                                `);
                                tbody.append(row);
                
                                // Inisialisasi Select2 setelah elemen ditambahkan ke DOM
                                row.find(".select-produk").select2({
                                    dropdownParent: $('#tambahModal'),
                                    width: '100%'
                                });
                
                                // Set nilai dari database jika ada - pastikan produk muncul
                                if (item.produk) {
                                    // Set nilai produk dengan delay untuk memastikan Select2 sudah terinisialisasi
                                    setTimeout(function() {
                                        // Untuk edit mode, produk sudah disabled, tidak perlu trigger change
                                        if (window.isEditMode) {
                                            row.find(".select-produk").val(item.produk);
                                        } else {
                                            row.find(".select-produk").val(item.produk).trigger('change');
                                        }
                                        
                                        // Re-validate jumlah setelah produk dipilih
                                        const jumlahInput = row.find('input[name="in_jumlah_kembali[]"]');
                                        const jumlah = parseFloat(jumlahInput.val()) || 0;
                                        const jumlahDipinjam = parseFloat(row.find('input[name="in_jumlah_dipinjam[]"]').val()) || 0;
                                        
                                        // Hapus error sebelumnya
                                        jumlahInput.removeClass('is-invalid');
                                        row.find('.invalid-feedback').remove();
                                        
                                        // Validasi: jumlah tidak boleh > jumlah dipinjam
                                        if (jumlahDipinjam > 0 && jumlah > jumlahDipinjam) {
                                            jumlahInput.addClass('is-invalid');
                                            if (!row.find('.invalid-feedback').length) {
                                                jumlahInput.after('<div class="invalid-feedback d-block" style="font-size: 0.875rem;">Jumlah tidak boleh melebihi jumlah dipinjam!</div>');
                                            }
                                        }
                                        
                                        if (typeof window.updateSubmitButtonState === 'function') {
                                            window.updateSubmitButtonState();
                                        }
                                    }, 200);
                                }
                                
                                // Event handler untuk validasi real-time - cek jumlah tidak boleh > jumlah dipinjam
                                row.find('input[name="in_jumlah_kembali[]"]').on('input change', function() {
                                    const $input = $(this);
                                    const $row = $input.closest('tr');
                                    const jumlah = parseFloat($input.val()) || 0;
                                    const jumlahDipinjam = parseFloat($row.find('input[name="in_jumlah_dipinjam[]"]').val()) || 0;
                                    
                                    // Hapus class error sebelumnya
                                    $input.removeClass('is-invalid');
                                    $row.find('.invalid-feedback').remove();
                                    
                                    // Validasi: jumlah tidak boleh > jumlah dipinjam
                                    if (jumlahDipinjam > 0 && jumlah > jumlahDipinjam) {
                                        $input.addClass('is-invalid');
                                        // Tampilkan tooltip atau pesan
                                        if (!$row.find('.invalid-feedback').length) {
                                            $input.after('<div class="invalid-feedback d-block" style="font-size: 0.875rem;">Jumlah tidak boleh melebihi jumlah dipinjam!</div>');
                                        }
                                    }
                                    
                                    if (typeof window.updateSubmitButtonState === 'function') {
                                        window.updateSubmitButtonState();
                                    }
                                });
                            });
                            setTimeout(function() {
                                if (typeof window.updateSubmitButtonState === 'function') {
                                    window.updateSubmitButtonState();
                                }
                            }, 300);
                        } else {
                            tbody.append(`
                                <tr id="emptyRow">
                                    <td colspan="6" class="text-center">Tidak ada data detail</td>
                                </tr>
                            `);
                        }
                    },
                    error: function(xhr, status, error) {
                        tbody.empty().append(`
                            <tr id="emptyRow">
                                <td colspan="6" class="text-center text-danger">Error: Gagal mengambil data detail. Status: ${xhr.status}</td>
                            </tr>
                        `);
                        alert("Gagal mengambil data detail! Status: " + xhr.status);
                    }
                });

            });
            
            $("#edit_id").val($(this).data('id'));
            $("#in_tanggal").val($(this).data('tanggal'));
            const entitasPeminjamVal = $(this).data('entitas_peminjam');
            const entitasDipinjamVal = $(this).data('entitas_dipinjam');
            const gudangAsalVal = $(this).data('gudang_asal');
            const gudangTujuanVal = $(this).data('gudang_tujuan');
            const nomorPeminjamanVal = $(this).data('nomor_peminjaman') || '';
            
            // Set entitas pengembali dan penerima, lalu load gudang
            $("#in_entitas_peminjam").val(entitasPeminjamVal).trigger('change');
            $("#in_entitas_dipinjam").val(entitasDipinjamVal).trigger('change');
            
            // Load gudang setelah entitas dipilih, lalu set nilai
            setTimeout(function() {
                window.loadGudangByEntitas(entitasPeminjamVal, $("#in_gudang_asal"), function() {
                    $("#in_gudang_asal").val(gudangAsalVal).trigger('change');
                });
                window.loadGudangByEntitas(entitasDipinjamVal, $("#in_gudang_tujuan"), function() {
                    $("#in_gudang_tujuan").val(gudangTujuanVal).trigger('change');
                });
            }, 500);
            
            // Set nomor peminjaman - untuk pengembalian, ambil dari data attribute atau dari response AJAX
            // Jika nomor_peminjaman dari data attribute kosong, akan diambil dari response AJAX nanti
            if (nomorPeminjamanVal && nomorPeminjamanVal !== '') {
                $("#in_nomor_peminjaman").val(nomorPeminjamanVal);
            } else {
                // Untuk draft, nomor_peminjaman akan diambil dari response AJAX
                $("#in_nomor_peminjaman").val("");
            }
            
            // Disable form fields jika status bukan Draft
            if (statusPengembalian !== 'Draft') {
                $("#in_tanggal, #in_entitas_peminjam, #in_entitas_dipinjam, #in_gudang_asal, #in_gudang_tujuan, #in_nomor_peminjaman").prop('disabled', true);
                $("#draftButton, #submitButton").prop('disabled', true);
                // Disable semua field di tabel detail
                $("#tbodyListData select, #tbodyListData input[type='number']").prop('disabled', true);
                alert("Dokumen dengan status '" + statusPengembalian + "' tidak dapat diedit. Hanya dokumen dengan status 'Draft' yang dapat diedit.");
            } else {
                // Untuk Draft: hanya jumlah dikembalikan yang bisa diubah
                // Disable field header (tanggal, entitas, gudang, nomor peminjaman)
                $("#in_tanggal, #in_entitas_peminjam, #in_entitas_dipinjam, #in_gudang_asal, #in_gudang_tujuan, #in_nomor_peminjaman").prop('disabled', true);
                // Enable tombol dan field detail (hanya jumlah dikembalikan)
                $("#draftButton, #submitButton").prop('disabled', false);
                // Disable produk (select), hanya enable jumlah (input number)
                $("#tbodyListData select[name='in_produk[]']").prop('disabled', true);
                $("#tbodyListData input[name='in_jumlah_kembali[]']").prop('disabled', false);
                // Untuk Draft, tombol hapus enabled
            }
        });
        
        /* DISABLED - Old code
        $(document).on("click", ".edit_btn", function () {
            $("#submitButton").text("Update sebagai Final");
            $("#draftButton").text("Update sebagai Draft");
            $("#tambahModalLabel").text("Update Pengembalian Stok");
            window.isEditMode = true;
        
            let nomor_peminjaman = $(this).data('nomor_peminjaman') || '';
            let statusPeminjaman = $(this).data('status') || 'Draft';
            let minId = parseInt($(this).data('min-id')) || 0;
            let isDraftNoNomor = $(this).data('is-draft-no-nomor') == '1';
            
            // Simpan status untuk digunakan di dalam modal
            window.currentStatusPeminjaman = statusPeminjaman;
            
            // Simpan identifier di window untuk digunakan di event handler
            window.editNomorPengembalian = nomor_peminjaman;
            window.editMinId = minId;
            window.editIsDraftNoNomor = isDraftNoNomor;
        
            // Tampilkan modal dulu
            $("#tambahModal").modal("show");
            
            // Ketika modal sudah selesai ditampilkan, baru jalankan AJAX
            $("#tambahModal").off("shown.bs.modal").on("shown.bs.modal", function() {
                let tbody = $("#tbodyListData");
                
                // Gunakan variabel dari window untuk memastikan data tersedia
                let currentNomorPengembalian = window.editNomorPengembalian || '';
                let currentMinId = window.editMinId || 0;
                let currentIsDraftNoNomor = window.editIsDraftNoNomor || false;
                
                if (!window.isEditMode) {
                    tbody.empty().append(`
                        <tr id="emptyRow">
                            <td colspan="5" class="text-center">Tidak ada data detail</td>
                        </tr>
                    `);
                    return;
                }
                tbody.empty();
            
                // Untuk Draft tanpa nomor, gunakan min_id
                let ajaxData = {};
                if (currentIsDraftNoNomor && currentMinId > 0) {
                    ajaxData = { min_id: currentMinId };
                } else if (currentNomorPengembalian) {
                    ajaxData = { nomor_peminjaman: currentNomorPengembalian };
                } else {
                    tbody.empty().append(`
                        <tr id="emptyRow">
                            <td colspan="5" class="text-center text-danger">Error: Tidak ada identifier yang valid</td>
                        </tr>
                    `);
                    return;
                }
            
                $.ajax({
                    url: "get_data.php?action=get_peminjaman_detail",
                    type: "POST",
                    data: ajaxData,
                    dataType: "json",
                    success: function(response) {
                        tbody.empty();
                        if (response.length > 0) {
                            
                            response.forEach((item, index) => {
                                const isDraft = window.currentStatusPeminjaman === 'Draft';
                                // Untuk Draft: hanya produk dan jumlah yang bisa diedit
                                const produkDisabledAttr = isDraft ? '' : 'disabled';
                                const jumlahReadonlyAttr = isDraft ? '' : 'readonly';
                                const deleteDisabledAttr = ''; // Tombol hapus selalu enabled
                                
                                let row = $(`
                                    <tr>
                                        <td class="text-center">${index + 1}</td>
                                        <td>
                                            <select name="in_produk[]" class="form-control select-produk" ${produkDisabledAttr}>
                                                <?php echo $productOptions; ?>
                                            </select>
                                        </td>
                                        <td class="text-center">
                                            <span class="stok-display" style="display: inline-block; padding: 6px 12px; font-weight: bold; color: #495057;">-</span>
                                        </td>
                                        <td class="text-center">
                                            <input type="number" name="in_jumlah[]" value="${item.qty}" class="form-control text-center" min="0" required ${jumlahReadonlyAttr}>
                                            <input type="hidden" name="in_id[]" value="${item.id}" class="form-control text-center">
                                        </td>
                                        <td class="text-center">
                                            <button type="button" class="btn btn-danger btn-sm btnDeleteRow" data-id="${item.id}" ${deleteDisabledAttr}>
                                                <i class="fas fa-trash-alt"></i>
                                            </button>
                                        </td>
                                    </tr>
                                `);
                                tbody.append(row);
                
                                // Inisialisasi Select2 setelah elemen ditambahkan ke DOM
                                row.find(".select-produk").select2({
                                    dropdownParent: $('#tambahModal'),
                                    width: '100%'
                                });
                
                                // Set nilai dari database jika ada - pastikan produk muncul
                                if (item.produk) {
                                    // Set nilai produk dengan delay untuk memastikan Select2 sudah terinisialisasi
                                    setTimeout(function() {
                                        row.find(".select-produk").val(item.produk).trigger('change');
                                        // Ambil stok setelah produk dipilih
                                        // PENTING: Stok diambil dari Gudang Dipinjam (in_gudang_tujuan), bukan Gudang Peminjam
                                        const gudangDipinjam = $('#in_gudang_tujuan').val(); // Stok dari gudang dipinjam
                                        if (gudangDipinjam) {
                                            $.post("get_data.php?action=get_stok_by_produk", {
                                                produk: item.produk,
                                                gudang: gudangDipinjam
                                            }, function(response) {
                                                try {
                                                    const data = typeof response === 'string' ? JSON.parse(response) : response;
                                                    if (data.status === 'success') {
                                                        const stok = data.stok || 0;
                                                        const $stokDisplay = row.find('.stok-display');
                                                        $stokDisplay.text(stok).css('color', stok > 0 ? '#28a745' : '#dc3545');
                                                        
                                                        // Re-validate jumlah setelah stok berubah
                                                        const jumlahInput = row.find('input[name="in_jumlah[]"]');
                                                        const jumlah = parseFloat(jumlahInput.val()) || 0;
                                                        
                                                        // Hapus error sebelumnya
                                                        jumlahInput.removeClass('is-invalid');
                                                        $stokDisplay.removeClass('text-danger');
                                                        row.find('.invalid-feedback').remove();
                                                        
                                                        // Validasi ulang: jumlah tidak boleh > stok
                                                        if (stok > 0 && jumlah > stok) {
                                                            jumlahInput.addClass('is-invalid');
                                                            $stokDisplay.addClass('text-danger');
                                                            if (!row.find('.invalid-feedback').length) {
                                                                $stokDisplay.after('<div class="invalid-feedback d-block" style="font-size: 0.875rem;">Jumlah tidak boleh melebihi stok!</div>');
                                                            }
                                                        }
                                                        
                                                        updateSubmitButtonState();
                                                    }
                                                } catch (e) {
                                                    // Ignore error
                                                }
                                            }, 'json');
                                        }
                                    }, 200);
                                }
                                
                                // Event handler untuk mengambil stok ketika produk dipilih
                                // PENTING: Stok diambil dari Gudang Dipinjam (in_gudang_tujuan), bukan Gudang Peminjam
                                row.find('select[name="in_produk[]"]').on('change', function() {
                                    const produk = $(this).val();
                                    const gudangDipinjam = $('#in_gudang_tujuan').val(); // Stok dari gudang dipinjam
                                    const $stokDisplay = $(this).closest('tr').find('.stok-display');
                                    
                                    if (produk && gudangDipinjam) {
                                        // Ambil stok dari server - menggunakan gudang dipinjam
                                        $.post("get_data.php?action=get_stok_by_produk", {
                                            produk: produk,
                                            gudang: gudangDipinjam
                                        }, function(response) {
                                            try {
                                                const data = typeof response === 'string' ? JSON.parse(response) : response;
                                                if (data.status === 'success') {
                                                    const stok = data.stok || 0;
                                                    $stokDisplay.text(stok).css('color', stok > 0 ? '#28a745' : '#dc3545');
                                                    
                                                    // Re-validate jumlah setelah stok berubah
                                                    const jumlahInput = $row.find('input[name="in_jumlah[]"]');
                                                    const jumlah = parseFloat(jumlahInput.val()) || 0;
                                                    
                                                    // Hapus error sebelumnya
                                                    jumlahInput.removeClass('is-invalid');
                                                    $stokDisplay.removeClass('text-danger');
                                                    $row.find('.invalid-feedback').remove();
                                                    
                                                    // Validasi ulang: jumlah tidak boleh > stok
                                                    if (stok > 0 && jumlah > stok) {
                                                        jumlahInput.addClass('is-invalid');
                                                        $stokDisplay.addClass('text-danger');
                                                        if (!$row.find('.invalid-feedback').length) {
                                                            $stokDisplay.after('<div class="invalid-feedback d-block" style="font-size: 0.875rem;">Jumlah tidak boleh melebihi stok!</div>');
                                                        }
                                                    }
                                                } else {
                                                    $stokDisplay.text('-').css('color', '#495057');
                                                }
                                            } catch (e) {
                                                $stokDisplay.text('-').css('color', '#495057');
                                            }
                                        }, 'json').fail(function() {
                                            $stokDisplay.text('-').css('color', '#495057');
                                        });
                                    } else {
                                        $stokDisplay.text('-').css('color', '#495057');
                                    }
                                    
                                    updateSubmitButtonState();
                                });
                                
                                // Event handler untuk validasi real-time - cek jumlah tidak boleh > stok
                                row.find('input[name="in_jumlah[]"]').on('input change', function() {
                                    const $input = $(this);
                                    const $row = $input.closest('tr');
                                    const jumlah = parseFloat($input.val()) || 0;
                                    const stokText = $row.find('.stok-display').text();
                                    const stok = stokText !== '-' ? parseFloat(stokText) || 0 : 0;
                                    
                                    // Hapus class error sebelumnya
                                    $input.removeClass('is-invalid');
                                    $row.find('.stok-display').removeClass('text-danger');
                                    
                                    // Validasi: jumlah tidak boleh > stok
                                    if (stok > 0 && jumlah > stok) {
                                        $input.addClass('is-invalid');
                                        $row.find('.stok-display').addClass('text-danger');
                                        // Tampilkan tooltip atau pesan
                                        if (!$row.find('.invalid-feedback').length) {
                                            $row.find('.stok-display').after('<div class="invalid-feedback d-block" style="font-size: 0.875rem;">Jumlah tidak boleh melebihi stok!</div>');
                                        }
                                    } else {
                                        $row.find('.invalid-feedback').remove();
                                    }
                                    
                                    updateSubmitButtonState();
                                });
                            });
                            setTimeout(updateSubmitButtonState, 300);
                        } else {
                            tbody.append(`
                                <tr id="emptyRow">
                                    <td colspan="5" class="text-center">Tidak ada data detail</td>
                                </tr>
                            `);
                        }
                    },
                    error: function(xhr, status, error) {
                        tbody.empty().append(`
                            <tr id="emptyRow">
                                <td colspan="5" class="text-center text-danger">Error: Gagal mengambil data detail. Status: ${xhr.status}</td>
                            </tr>
                        `);
                        alert("Gagal mengambil data detail! Status: " + xhr.status + ". Cek console untuk detail error.");
                    }
                });

            });
            
            $("#edit_id").val($(this).data('id'));
            $("#in_tanggal").val($(this).data('tanggal'));
            const entitasPeminjamVal = $(this).data('entitas_peminjam');
            const entitasDipinjamVal = $(this).data('entitas_dipinjam');
            const gudangAsalVal = $(this).data('gudang_asal');
            const gudangTujuanVal = $(this).data('gudang_tujuan');
            
            // Set entitas peminjam dan dipinjam, lalu load gudang
            $("#in_entitas_peminjam").val(entitasPeminjamVal).trigger('change');
            $("#in_entitas_dipinjam").val(entitasDipinjamVal).trigger('change');
            
            // Load gudang setelah entitas dipilih, lalu set nilai
            setTimeout(function() {
                loadGudangByEntitas(entitasPeminjamVal, $("#in_gudang_asal"), function() {
                    $("#in_gudang_asal").val(gudangAsalVal).trigger('change');
                });
                loadGudangByEntitas(entitasDipinjamVal, $("#in_gudang_tujuan"), function() {
                    $("#in_gudang_tujuan").val(gudangTujuanVal).trigger('change');
                    // Update stok setelah gudang dipinjam dipilih
                    setTimeout(function() {
                        updateAllStok();
                    }, 300);
                });
            }, 500);
            
            // Set nomor peminjaman (bisa kosong untuk Draft)
            if (nomor_peminjaman && nomor_peminjaman !== '') {
                $("#in_nomor_peminjaman").val(nomor_peminjaman);
            } else {
                $("#in_nomor_peminjaman").val("");
            }
            
            // Disable form fields jika status bukan Draft
            if (statusPeminjaman !== 'Draft') {
                $("#in_tanggal, #in_entitas_peminjam, #in_entitas_dipinjam, #in_gudang_asal, #in_gudang_tujuan, #in_nomor_peminjaman").prop('disabled', true);
                // Tidak ada tombol addRowButton untuk form pengembalian
                $("#draftButton, #submitButton").prop('disabled', true);
                // Disable semua field di tabel detail
                $("#tbodyListData select, #tbodyListData input[type='number']").prop('disabled', true);
                alert("Dokumen dengan status '" + statusPeminjaman + "' tidak dapat diedit. Hanya dokumen dengan status 'Draft' yang dapat diedit.");
            } else {
                // Untuk Draft: hanya produk dan jumlah yang bisa diedit
                // Disable field header (tanggal, entitas, gudang peminjam, gudang dipinjam, nomor peminjaman)
                $("#in_tanggal, #in_entitas_peminjam, #in_entitas_dipinjam, #in_gudang_asal, #in_gudang_tujuan, #in_nomor_peminjaman").prop('disabled', true);
                // Enable tombol dan field detail (hanya produk dan jumlah)
                // Tidak ada tombol addRowButton untuk form pengembalian
                $("#draftButton, #submitButton").prop('disabled', false);
                // Enable hanya produk (select) dan jumlah (input number)
                $("#tbodyListData select[name='in_produk[]'], #tbodyListData input[name='in_jumlah[]']").prop('disabled', false);
                // Untuk Draft, tombol hapus enabled (tidak perlu disable karena sudah di dalam blok Draft)
            }
        });

    // reset
    $("#tambahModal").on("hidden.bs.modal", function() {
        window.isEditMode = false;
        window.editNomorPengembalian = '';
        window.editMinId = 0;
        window.editIsDraftNoNomor = false;
        let tbody = $("#tbodyListData");
        tbody.empty();
        $("#tambahModalLabel").text("Tambah Pengembalian Stok");
        $("#tambahForm")[0].reset();
        $("#in_entitas_peminjam, #in_entitas_dipinjam, #in_gudang_asal, #in_gudang_tujuan").val(null).trigger("change");
        $("#in_gudang_asal").prop('disabled', true).html('<option value="">-- Pilih Entitas Pengembali dulu --</option>');
        $("#in_gudang_tujuan").prop('disabled', true).html('<option value="">-- Pilih Entitas Penerima dulu --</option>');
        $("#edit_id").val("");
        $("#in_nomor_peminjaman").val("");
        $("#submitButton").text("Simpan sebagai Final").removeClass("btn-warning").addClass("btn-primary");
        $("#draftButton").text("Simpan sebagai Draft");
        // Enable semua field saat reset
        $("#in_tanggal, #in_entitas_peminjam, #in_entitas_dipinjam, #in_gudang_asal, #in_gudang_tujuan, #in_nomor_peminjaman").prop('disabled', false);
        $("#draftButton, #submitButton").prop('disabled', false);
    });
    
    // Caching selector
    // NOTE: Untuk form pengembalian, produk dimuat otomatis dari nomor peminjaman
    // Tidak perlu fungsi addRow() atau tombol "Tambah Data Produk"
    const $tbody         = $("#tbodyListData"),
          $detailTable   = $("#detailTable");

    // NOTE: Handler untuk menghapus row di form pengembalian menggunakan .btnDeleteRowPengembalian
    // Handler .btnDeleteRow dihapus karena tidak diperlukan untuk form pengembalian
    // Produk dimuat otomatis dari nomor peminjaman, bukan ditambahkan manual

    // loadGudangByEntitas sudah dipindahkan ke awal document.ready untuk menghindari error

    // Load gudang berdasarkan multiple entitas (untuk filter) - global function
    window.loadGudangByMultipleEntitas = function(entitasArray, targetSelect, callback) {
        if (!entitasArray || entitasArray.length === 0) {
            targetSelect.prop('disabled', true).html('<option value="">-- Pilih Entitas dulu --</option>').trigger('change');
            if (callback) callback();
            return;
        }
        
        // Jika hanya satu entitas, gunakan fungsi yang sudah ada
        if (entitasArray.length === 1) {
            window.loadGudangByEntitas(entitasArray[0], targetSelect, callback);
            return;
        }
        
        // Untuk multiple entitas, kirim sebagai array
        $.post("get_data.php?action=get_gudang_by_entitas", { entitas: entitasArray }, function (response) {
            try {
                const data = typeof response === 'string' ? JSON.parse(response) : response;
                let options = '<option value="">-- Pilih Gudang --</option>';
                if (data.gudang && data.gudang.length > 0) {
                    // Hapus duplikat berdasarkan nama_gudang
                    const uniqueGudang = [];
                    const seen = {};
                    data.gudang.forEach(function(g) {
                        if (!seen[g.nama_gudang]) {
                            seen[g.nama_gudang] = true;
                            uniqueGudang.push(g);
                        }
                    });
                    uniqueGudang.forEach(function(g) {
                        options += '<option value="' + g.nama_gudang + '">' + g.nama_gudang + '</option>';
                    });
                }
                targetSelect.prop('disabled', false).html(options).trigger('change');
                if (callback) callback();
            } catch (e) {
                targetSelect.prop('disabled', true).html('<option value="">Error loading gudang</option>');
                if (callback) callback();
            }
        }, 'json').fail(function (xhr, status, error) {
            targetSelect.prop('disabled', true).html('<option value="">Error loading gudang</option>');
            if (callback) callback();
        });
    }

    // Handler ketika entitas peminjam dipilih di form tambah/edit
    $("#in_entitas_peminjam").change(function() {
        const entitasPeminjam = $(this).val();
        const entitasDipinjam = $("#in_entitas_dipinjam").val();
        
        // Validasi: entitas peminjam dan entitas dipinjam tidak boleh sama
        if (entitasPeminjam && entitasDipinjam && entitasPeminjam === entitasDipinjam) {
            alert("Entitas Pengembali dan Entitas Penerima tidak boleh sama!");
            $(this).val('').trigger('change');
            $("#in_gudang_asal").prop('disabled', true).html('<option value="">-- Pilih Entitas Pengembali dulu --</option>').trigger('change');
            return;
        }
        
        if (entitasPeminjam) {
            loadGudangByEntitas(entitasPeminjam, $("#in_gudang_asal"));
        } else {
            $("#in_gudang_asal").prop('disabled', true).html('<option value="">-- Pilih Entitas Pengembali dulu --</option>').trigger('change');
        }
        
        // Clear nomor peminjaman jika entitas berubah (karena gudang akan di-reset)
        $("#in_nomor_peminjaman").val("");
        // Nomor peminjaman akan di-generate otomatis ketika gudang peminjam dipilih
    });
    
    // Handler ketika entitas dipinjam dipilih di form tambah/edit
    $("#in_entitas_dipinjam").change(function() {
        const entitasDipinjam = $(this).val();
        const entitasPeminjam = $("#in_entitas_peminjam").val();
        
        // Validasi: entitas peminjam dan entitas dipinjam tidak boleh sama
        if (entitasPeminjam && entitasDipinjam && entitasPeminjam === entitasDipinjam) {
            alert("Entitas Pengembali dan Entitas Penerima tidak boleh sama!");
            $(this).val('').trigger('change');
            $("#in_gudang_tujuan").prop('disabled', true).html('<option value="">-- Pilih Entitas Penerima dulu --</option>').trigger('change');
            return;
        }
        
        if (entitasDipinjam) {
            loadGudangByEntitas(entitasDipinjam, $("#in_gudang_tujuan"));
        } else {
            $("#in_gudang_tujuan").prop('disabled', true).html('<option value="">-- Pilih Entitas Penerima dulu --</option>').trigger('change');
        }
    });


    // Fungsi untuk update stok semua produk yang sudah dipilih
    // PENTING: Stok diambil dari Gudang Dipinjam (in_gudang_tujuan), bukan Gudang Peminjam
    function updateAllStok() {
        const gudangDipinjam = $('#in_gudang_tujuan').val(); // Stok dari gudang dipinjam
        if (!gudangDipinjam) {
            $('#tbodyListData tr').each(function() {
                $(this).find('.stok-display').text('-').css('color', '#495057');
            });
            return;
        }
        
        $('#tbodyListData tr').each(function() {
            const $row = $(this);
            const produk = $row.find('select[name="in_produk[]"]').val();
            const $stokDisplay = $row.find('.stok-display');
            
            if (produk) {
                $.post("get_data.php?action=get_stok_by_produk", {
                    produk: produk,
                    gudang: gudangDipinjam
                }, function(response) {
                    try {
                        const data = typeof response === 'string' ? JSON.parse(response) : response;
                        if (data.status === 'success') {
                            const stok = data.stok || 0;
                            $stokDisplay.text(stok).css('color', stok > 0 ? '#28a745' : '#dc3545');
                            
                            // Re-validate jumlah setelah stok berubah
                            const $row = $stokDisplay.closest('tr');
                            const jumlahInput = $row.find('input[name="in_jumlah[]"]');
                            const jumlah = parseFloat(jumlahInput.val()) || 0;
                            
                            // Hapus error sebelumnya
                            jumlahInput.removeClass('is-invalid');
                            $stokDisplay.removeClass('text-danger');
                            $row.find('.invalid-feedback').remove();
                            
                            // Validasi ulang: jumlah tidak boleh > stok
                            if (stok > 0 && jumlah > stok) {
                                jumlahInput.addClass('is-invalid');
                                $stokDisplay.addClass('text-danger');
                                if (!$row.find('.invalid-feedback').length) {
                                    $stokDisplay.after('<div class="invalid-feedback d-block" style="font-size: 0.875rem;">Jumlah tidak boleh melebihi stok!</div>');
                                }
                            }
                            
                            updateSubmitButtonState();
                        } else {
                            $stokDisplay.text('-').css('color', '#495057');
                        }
                    } catch (e) {
                        $stokDisplay.text('-').css('color', '#495057');
                    }
                }, 'json').fail(function() {
                    $stokDisplay.text('-').css('color', '#495057');
                });
            } else {
                $stokDisplay.text('-').css('color', '#495057');
            }
        });
    }
    
    $("#in_gudang_asal").change(function() {
        const gudangAsal = $(this).val();
        const gudangTujuan = $("#in_gudang_tujuan").val();
        if (gudangAsal && gudangTujuan && gudangAsal === gudangTujuan) {
            alert("Gudang Pengembali dan Gudang Penerima tidak boleh sama!");
            $(this).val('').trigger('change');
        }
        // Jika gudang peminjam dikosongkan, clear nomor peminjaman
        if (!gudangAsal) {
            $("#in_nomor_peminjaman").val("");
        }
        generateNomorPeminjaman();
    });
    
    // Handler ketika gudang dipinjam berubah - update stok semua produk
    $("#in_gudang_tujuan").change(function() {
        const gudangAsal = $("#in_gudang_asal").val();
        const gudangTujuan = $(this).val();
        if (gudangAsal && gudangTujuan && gudangAsal === gudangTujuan) {
            alert("Gudang Pengembali dan Gudang Penerima tidak boleh sama!");
            $(this).val('').trigger('change');
            return;
        }
        generateNomorPeminjaman();
        // Update stok semua produk yang sudah dipilih
        updateAllStok();
    });
    
    function generateNomorPeminjaman() {
        if (window.isEditMode) { 
            return; 
        }
        const in_entitas_peminjam = $('#in_entitas_peminjam').val();
        const in_gudang_asal = $('#in_gudang_asal').val();
        const in_gudang_tujuan = $('#in_gudang_tujuan').val();
        
        // Nomor peminjaman hanya muncul jika gudang peminjam diisi
        if (!in_gudang_asal) {
            $("#in_nomor_peminjaman").val("");
            return;
        }
        
        // Untuk Draft, nomor peminjaman tidak di-generate (kosong)
        // Nomor peminjaman hanya di-generate saat Final
        // Jadi kita tidak generate otomatis di sini, biarkan kosong
        // Nomor peminjaman akan di-generate di server side saat Final
        
        // Clear nomor peminjaman untuk Draft
        $("#in_nomor_peminjaman").val("");
        return;
    }
    */ // END DISABLED

});

</script>

<script>
    document.addEventListener('DOMContentLoaded', function () {
        // Fungsi untuk inisialisasi Select2
        function initializeSelect2() {
            $('.select2').select2({
                placeholder: "Pilih",
                allowClear: true,
                dropdownParent: $('#tambahModal'),
                width: '100%'
            });
        }

        initializeSelect2();

        // Pastikan semua field di-enable saat tombol Tambah Data diklik
        $('#btnTambahData').on('click', function() {
            // Set title modal ke Pengembalian Stok
            $("#tambahModalLabel").text("Tambah Pengembalian Stok");
            
            // Reset form dan enable semua field
            $("#edit_id").val("");
            $("#in_tanggal, #in_entitas_peminjam, #in_entitas_dipinjam, #in_gudang_asal, #in_gudang_tujuan, #in_nomor_peminjaman").prop('disabled', false);
            $("#draftButton, #submitButton").prop('disabled', false);
            // Enable semua field di tabel detail yang sudah ada
            $("#tbodyListData select, #tbodyListData input[type='number']").prop('disabled', false);
        });
        
        // Handler show.bs.modal sudah dipindahkan ke bawah untuk menghindari duplikasi
        
        $('#tambahModal').on('shown.bs.modal', function () {
            initializeSelect2();
        });
    });
    
    document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll('.actionAddData button[type="submit"]').forEach(button => {
            button.addEventListener('click', function (e) {
                e.preventDefault();

                const actionType = this.value;

                // Validasi entitas peminjam dan entitas dipinjam tidak boleh sama
                const entitasPeminjam = $('#in_entitas_peminjam').val();
                const entitasDipinjam = $('#in_entitas_dipinjam').val();
                
                if (entitasPeminjam && entitasDipinjam && entitasPeminjam === entitasDipinjam) {
                    alert('Validasi Gagal:\n\nEntitas Pengembali dan Entitas Penerima tidak boleh sama!');
                    $('#in_entitas_peminjam, #in_entitas_dipinjam').addClass('is-invalid');
                    return false;
                } else {
                    $('#in_entitas_peminjam, #in_entitas_dipinjam').removeClass('is-invalid');
                }

                // Validasi form
                let isValid = true;
                let errorMessage = '';
                $('#tbodyListData tr').each(function() {
                    const row = $(this);
                    // Untuk pengembalian, gunakan in_jumlah_kembali[] bukan in_jumlah[]
                    const jumlahInput = row.find('input[name="in_jumlah_kembali[]"]');
                    const produkSelect = row.find('select[name="in_produk[]"]');
                    const jumlahDipinjamInput = row.find('input[name="in_jumlah_dipinjam[]"]');
                    const jumlahDipinjam = jumlahDipinjamInput.length ? parseFloat(jumlahDipinjamInput.val()) || 0 : 0;
                    
                    if (jumlahInput.length && produkSelect.length) {
                        const jumlah = parseFloat(jumlahInput.val()) || 0;
                        const produk = produkSelect.val();
                        const produkNama = produkSelect.find('option:selected').text();
                        
                        // Validasi: jumlah harus > 0
                        if (produk && jumlah <= 0) {
                            isValid = false;
                            errorMessage += `Jumlah untuk produk "${produkNama}" harus lebih dari 0.\n`;
                            jumlahInput.addClass('is-invalid');
                        }
                        // Validasi: jumlah tidak boleh > jumlah dipinjam
                        else if (produk && jumlahDipinjam > 0 && jumlah > jumlahDipinjam) {
                            isValid = false;
                            errorMessage += `Jumlah untuk produk "${produkNama}" (${jumlah}) tidak boleh melebihi jumlah dipinjam (${jumlahDipinjam}).\n`;
                            jumlahInput.addClass('is-invalid');
                        } else {
                            jumlahInput.removeClass('is-invalid');
                        }
                    }
                });

                if (!isValid) {
                    alert('Validasi Gagal:\n\n' + errorMessage);
                    return false;
                }

                // Pastikan semua field tidak disabled sebelum submit
                // Field yang disabled tidak akan dikirim ke server saat form disubmit
                const fieldsToEnable = ['in_tanggal', 'in_entitas_peminjam', 'in_entitas_dipinjam', 'in_gudang_asal', 'in_gudang_tujuan'];
                fieldsToEnable.forEach(function(fieldId) {
                    const $field = $('#' + fieldId);
                    if ($field.length && $field.prop('disabled')) {
                        $field.prop('disabled', false);
                        // Jika field menggunakan Select2, trigger update
                        if ($field.hasClass('select2-hidden-accessible')) {
                            $field.trigger('change.select2');
                        }
                    }
                });
                
                // Jika update sebagai Final dan tanggal kosong, set tanggal hari ini
                // Lakukan ini SEBELUM membuat FormData agar nilai tanggal ikut terkirim
                if (actionType === 'Final' && window.isEditMode) {
                    const $tanggalField = $('#in_tanggal');
                    if (!$tanggalField.val() || $tanggalField.val().trim() === '') {
                        const today = new Date();
                        const year = today.getFullYear();
                        const month = String(today.getMonth() + 1).padStart(2, '0');
                        const day = String(today.getDate()).padStart(2, '0');
                        const todayStr = `${year}-${month}-${day}`;
                        $tanggalField.val(todayStr);
                        // Trigger change event untuk memastikan nilai ter-update
                        $tanggalField.trigger('change');
                    }
                }
                
                const formData = new FormData(document.getElementById('tambahForm'));
                formData.append('in_action_button', actionType);

                const editIdEl = document.getElementById('edit_id');
                const editIdVal = editIdEl ? editIdEl.value : '';
                const endpointUrl = (window.isEditMode === true) ? 'action.php?act=update' : 'action.php?act=add';

                $.ajax({
                    type: 'POST',
                    url: endpointUrl,
                    data: formData,
                    processData: false,
                    contentType: false,
                    success: function (response) {
                        try {
                            // Clean response jika ada whitespace atau karakter tidak valid
                            let cleanResponse = typeof response === 'string' ? response.trim() : response;
                            
                            // Parse JSON
                            const result = typeof cleanResponse === 'object' ? cleanResponse : JSON.parse(cleanResponse);
                            
                            // Support both 'status' and 'success' field for backward compatibility
                            const isSuccess = (result.status === 'success') || (result.success === true);
                            
                            if (isSuccess) {
                                // Hapus focus dari elemen yang terfokus sebelum menutup modal
                                if (document.activeElement) {
                                    document.activeElement.blur();
                                }
                                
                                // Tutup modal dan tunggu sampai benar-benar ditutup
                                $('#tambahModal').one('hidden.bs.modal', function() {
                                    const statusMessage = actionType === 'Draft' ? 'Data berhasil disimpan sebagai Draft. Anda masih dapat mengedit dokumen ini.' : 'Data berhasil disimpan sebagai Final. Dokumen tidak dapat diedit lagi.';
                                    alert(statusMessage);
                                    
                                    // Jika Final, langsung reload halaman untuk memastikan data terbaru
                                    if (actionType === 'Final') {
                                        location.reload();
                                    } else {
                                        // Untuk Draft, refresh DataTables tanpa reload halaman
                                        if (typeof tablePengembalian !== 'undefined' && tablePengembalian) {
                                            try {
                                                tablePengembalian.ajax.reload(null, false); // false = keep current page
                                            } catch (reloadError) {
                                                console.error('Error reloading DataTables:', reloadError);
                                                // Fallback: reload halaman jika DataTables error
                                                location.reload();
                                            }
                                        } else {
                                            location.reload();
                                        }
                                    }
                                });
                                
                                $('#tambahModal').modal('hide');
                            } else {
                                alert(result.message || 'Terjadi kesalahan saat menyimpan data.');
                            }
                        } catch (e) {
                            console.error('Error parsing response:', e, response);
                            alert('Gagal memproses respons dari server. Silakan refresh halaman dan coba lagi.');
                            // Fallback: reload halaman jika parsing error
                            setTimeout(function() {
                                location.reload();
                            }, 2000);
                        }
                    },
                    error: function(xhr, status, error) {
                        console.error('AJAX Error:', {
                            status: xhr.status,
                            statusText: xhr.statusText,
                            responseText: xhr.responseText,
                            error: error
                        });
                        
                        let errorMessage = 'Gagal mengirim data! Data Peminjaman belum lengkap, mohon cek kembali & lengkapi data dengan benar!';
                        
                        // Coba parse error response jika ada
                        if (xhr.responseText) {
                            try {
                                const errorResponse = JSON.parse(xhr.responseText);
                                if (errorResponse.message) {
                                    errorMessage = errorResponse.message;
                                }
                            } catch (e) {
                                // Jika bukan JSON, gunakan responseText langsung (potong jika terlalu panjang)
                                if (xhr.responseText.length < 200) {
                                    errorMessage = xhr.responseText;
                                }
                            }
                        }
                        
                        alert(errorMessage);
                    },
                    dataType: 'json' // Explicitly set dataType to JSON
                });
            });
        });

    });

    $(function() {
        $('.select2').select2();
        
        // Inisialisasi validasi tombol submit saat halaman dimuat
        setTimeout(function() {
            updateSubmitButtonState();
        }, 500);

        // Handler untuk card Peminjaman dan Pengembalian
        // Default: pengembalian karena ini adalah halaman pengembalian
        let selectedType = 'pengembalian';
        
        // Inisialisasi card berdasarkan URL parameter atau default
        const urlParams = new URLSearchParams(window.location.search);
        const typeParam = urlParams.get('type');
        if (typeParam === 'peminjaman') {
            selectedType = 'peminjaman';
        } else {
            // Default ke pengembalian jika tidak ada parameter atau parameter = pengembalian
            selectedType = 'pengembalian';
            // Update URL untuk memastikan parameter type ada
            if (!typeParam || typeParam !== 'pengembalian') {
                const url = new URL(window.location);
                url.searchParams.set('type', 'pengembalian');
                window.history.replaceState({}, '', url);
            }
        }
        
        // Update tampilan card berdasarkan selectedType
        function updateCardDisplay() {
            $('.type-card').each(function() {
                const card = $(this);
                const type = card.data('type');
                const cardBody = card.find('.card-body');
                
                if (type === selectedType) {
                    // Card terpilih: biru
                    cardBody.css({
                        'background-color': '#007bff',
                        'color': 'white'
                    });
                } else {
                    // Card tidak terpilih: putih
                    cardBody.css({
                        'background-color': '#ffffff',
                        'color': '#333'
                    });
                }
            });
        }
        
        // Fungsi untuk load count untuk kedua card
        function loadCardCounts() {
            // Load count peminjaman
            $.get('../peminjaman/get_data.php', {
                action: 'get_data',
                type: 'peminjaman',
                start_date: '<?= $start_date_default2 ?>',
                end_date: '<?= $end_date_default1 ?>',
                draw: 1,
                start: 0,
                length: 1
            }, function(response) {
                if (response && response.recordsTotal !== undefined) {
                    $('#countPeminjaman').text(response.recordsTotal);
                }
            }, 'json');
            
            // Load count pengembalian
            $.get('../peminjaman/get_data.php', {
                action: 'get_data',
                type: 'pengembalian',
                start_date: '<?= $start_date_default2 ?>',
                end_date: '<?= $end_date_default1 ?>',
                draw: 1,
                start: 0,
                length: 1
            }, function(response) {
                if (response && response.recordsTotal !== undefined) {
                    $('#countPengembalian').text(response.recordsTotal);
                }
            }, 'json');
        }
        
        // Handler click pada card
        $('.type-card').on('click', function() {
            const clickedType = $(this).data('type');
            
            // Jika klik card peminjaman, redirect ke halaman peminjaman
            if (clickedType === 'peminjaman') {
                window.location.href = '../peminjaman/index.php?type=peminjaman';
                return;
            }
            
            // Jika klik card pengembalian, tetap di halaman ini dan tampilkan data pengembalian
            if (clickedType === 'pengembalian') {
                selectedType = 'pengembalian';
                updateCardDisplay();
                
                // Update URL tanpa reload
                const url = new URL(window.location);
                url.searchParams.set('type', 'pengembalian');
                window.history.pushState({}, '', url);
                
                // Reload DataTables dengan filter type pengembalian untuk menampilkan dashboard pengembalian
                if (typeof tablePengembalian !== 'undefined' && tablePengembalian) {
                    // Destroy dan reinitialize untuk memastikan type benar
                    tablePengembalian.destroy();
                    initDataTable();
                } else {
                    initDataTable();
                }
            }
        });
        
        // Inisialisasi tampilan card saat halaman dimuat
        updateCardDisplay();
        
        // Load count untuk kedua card saat halaman dimuat
        loadCardCounts();

        // Handler ketika entitas peminjam dipilih di filter (setelah Select2 diinisialisasi)
        // Gunakan event Select2 untuk multiple select
        var start       = moment().subtract(29, 'days');
        var end         = moment();
        var startDate   = "<?= $start_date_default2?>";
        var endDate     = "<?= $end_date_default1 ?>";

        if (startDate && endDate) {
            start       = moment(startDate);
            end         = moment(endDate);
        }

        function cb(start, end) {
            $('#daterange span').html(start.format('D MMMM YYYY') + ' - ' + end.format('D MMMM YYYY'));
            var startInGMT7 = start.utcOffset(420);
            var endInGMT7 = end.utcOffset(420);

            $('#start_date').val(startInGMT7.startOf('day').format('YYYY-MM-DD'));
            $('#end_date').val(endInGMT7.endOf('day').format('YYYY-MM-DD'));
        }

        $('#daterange').daterangepicker({
            startDate: start,
            endDate: end,
            ranges: {
                'Today': [moment(), moment()],
                'Yesterday': [moment().subtract(1, 'days'), moment().subtract(1, 'days')],
                'Last 7 Days': [moment().subtract(6, 'days'), moment()],
                'Last 30 Days': [moment().subtract(29, 'days'), moment()],
                'This Month': [moment().startOf('month'), moment().endOf('month')],
                'Last Month': [moment().subtract(1, 'month').startOf('month'), moment().subtract(1, 'month').endOf('month')]
            }
        }, cb);

        cb(start, end);
    });

    function formatDate(date) {
        var d       = new Date(date),
            month   = '' + (d.getMonth() + 1),
            day     = '' + d.getDate(),
            year    = d.getFullYear();

        if (month.length < 2) 
            month   = '0' + month;
        if (day.length < 2) 
            day     = '0' + day;

        return [year, month, day].join('-');
    }

    // Function to filter data
    $('#filterButton').on('click', function() {
        var selectedEntitasPeminjam = $('#entitas_peminjam').val();
        var selectedEntitasDipinjam = $('#entitas_dipinjam').val();
        var dateRange            = $('#daterange span').html().split(' - ');
        var startDate            = formatDate(dateRange[0]);
        var endDate              = formatDate(dateRange[1]);
        
        var newUrl = 'index.php?start_date=' + startDate + '&end_date=' + endDate;
        if (selectedEntitasPeminjam && selectedEntitasPeminjam.length > 0) {
            newUrl += '&entitas_peminjam=' + selectedEntitasPeminjam.join(',');
        }
        if (selectedEntitasDipinjam && selectedEntitasDipinjam.length > 0) {
            newUrl += '&entitas_dipinjam=' + selectedEntitasDipinjam.join(',');
        }
        
        window.history.pushState({}, '', newUrl);
        initDataTable();
    });

    $('#filterFormContainer').on('show.bs.collapse', function () {
        $('#toggleIcon').removeClass('fa-chevron-down').addClass('fa-chevron-up');
    });
    $('#filterFormContainer').on('hide.bs.collapse', function () {
        $('#toggleIcon').removeClass('fa-chevron-up').addClass('fa-chevron-down');
    });

    $('#resetButton').on('click', function() {
        window.history.pushState({}, '', 'index.php');
        
        $('#filterForm')[0].reset();
        $('#entitas_peminjam, #entitas_dipinjam').val(null).trigger('change');
        
        var defaultStartDate = '<?= $start_date_default2 ?>';
        var defaultEndDate = '<?= $end_date_default1 ?>';
        $('#daterange span').html(moment(defaultStartDate).format('DD/MM/YYYY') + ' - ' + moment(defaultEndDate).format('DD/MM/YYYY'));
        $('#start_date').val(defaultStartDate);
        $('#end_date').val(defaultEndDate);
        
        initDataTable();
    });

    function deletepengembalian(identifier) {
        if (confirm("Apakah Anda yakin ingin menghapus Pengembalian " + identifier + "?")) {
            $.ajax({
                type: 'POST',
                url: 'action.php?act=delete',
                data: { identifier: identifier },
                success: function(response) {
                    try {
                        var result = typeof response === 'object' ? response : JSON.parse(response);
                        if (result.status === 'success') {
                            // Jika Draft, reload DataTables tanpa refresh halaman
                            if (result.is_draft) {
                                // Tampilkan notifikasi
                                alert(result.message);
                                // Reload DataTables untuk update data
                                if (typeof tablePengembalian !== 'undefined' && tablePengembalian) {
                                    tablePengembalian.ajax.reload(null, false); // false = keep current page
                                } else {
                                    location.reload();
                                }
                            } else {
                                // Untuk Final, reload DataTables tanpa refresh halaman
                                alert(result.message);
                                if (typeof tablePengembalian !== 'undefined' && tablePengembalian) {
                                    tablePengembalian.ajax.reload(null, false); // false = keep current page
                                } else {
                                    location.reload();
                                }
                            }
                        } else {
                            alert("Gagal menghapus Pengembalian: " + result.message);
                        }
                    } catch (e) {
                        alert("Terjadi kesalahan saat memproses data.");
                    }
                },
                error: function(xhr, status, error) {
                    alert("Gagal mengirim data.");
                }
            });
        }
    }

    // Variable untuk menyimpan instance DataTable
    let tablePengembalian;

    // Function untuk inisialisasi DataTable
    function initDataTable() {
        // Destroy existing table if exists
        if (tablePengembalian) {
            tablePengembalian.destroy();
        }

        // Ambil parameter dari URL
        const urlParams = new URLSearchParams(window.location.search);
        const startDate = urlParams.get('start_date') || '<?= $start_date_default2 ?>';
        const endDate = urlParams.get('end_date') || '<?= $end_date_default1 ?>';
        const entitasPeminjam = urlParams.get('entitas_peminjam') || '';
        const entitasDipinjam = urlParams.get('entitas_dipinjam') || '';
        const type = 'pengembalian'; // Selalu pengembalian untuk halaman ini

        tablePengembalian = $('#table-pengembalian').DataTable({
            processing: true,
            serverSide: true,
            "scrollY": "600px",
            "scrollCollapse": true,
            "autoWidth": false,
            "responsive": false,
            "fixedColumns": false,
            ajax: {
                url: '../peminjaman/get_data.php',
                type: 'GET',
                data: function(d) {
                    d.start_date = startDate;
                    d.end_date = endDate;
                    d.entitas_peminjam = entitasPeminjam;
                    d.entitas_dipinjam = entitasDipinjam;
                    d.type = 'pengembalian'; // Pastikan selalu pengembalian
                    d.action = 'get_data'; // Pastikan action terkirim
                    
                    // Debug: log parameter yang dikirim
                    console.log('Pengembalian DataTables - Sending type:', d.type);
                    return d;
                }
            },
            columns: [
                { data: 0, width: "10%" }, // Tanggal
                { data: 1, width: "15%" }, // Nomor Peminjaman
                { data: 2, width: "15%" }, // Nomor Pengembalian
                { data: 3, width: "12%" }, // Entitas Pengembali
                { data: 4, width: "12%" }, // Entitas Penerima
                { data: 5, width: "8%" }, // Jumlah Item
                { data: 6, width: "10%" }, // Stok Dipinjam
                { data: 7, width: "10%" }, // Total Qty
                { data: 8, width: "10%" }, // Status
                { data: 9, width: "11%" }  // Aksi
            ],
            pageLength: 10,
            lengthMenu: [[10, 25, 50, 100, -1], [10, 25, 50, 100, 'All']],
            order: [[0, 'desc']],
            language: {
                processing: '<div class="spinner-border" role="status"><span class="sr-only">Loading...</span></div>',
                search: "Cari:",
                lengthMenu: "Tampilkan _MENU_ data per halaman",
                zeroRecords: "Tidak ada data yang ditemukan",
                info: "Menampilkan _START_ sampai _END_ dari _TOTAL_ data",
                infoEmpty: "Menampilkan 0 sampai 0 dari 0 data",
                infoFiltered: "(difilter dari _MAX_ total data)",
                paginate: {
                    first: "Pertama",
                    last: "Terakhir",
                    next: "Selanjutnya",
                    previous: "Sebelumnya"
                }
            },
            drawCallback: function(settings) {
                $('[data-toggle="tooltip"]').tooltip();
                
                // Update count di card pengembalian
                const recordsTotal = settings.json ? settings.json.recordsTotal : 0;
                $('#countPengembalian').text(recordsTotal);
            }
        });
    }

$(document).ready(function() {
    initDataTable();
    
    // ============================================
    // Handler untuk Form Tambah Data Pengembalian
    // ============================================
    
    // Handler show.bs.modal untuk reset form dan enable field (hanya jika bukan edit mode)
    $('#tambahModal').on('show.bs.modal', function() {
        // Jangan reset form jika sedang edit mode
        if (!window.isEditMode && (!$("#edit_id").val() || $("#edit_id").val() === '')) {
            // Set title modal ke Pengembalian Stok
            $('#tambahModalLabel').text('Tambah Pengembalian Stok');
            
            // Reset form
            $('#tambahForm')[0].reset();
            $('#tbodyListData').empty().append('<tr id="emptyRow"><td colspan="5" class="text-center">Pilih Nomor Peminjaman untuk melihat detail produk</td></tr>');
            $('#in_entitas_dipinjam, #in_entitas_peminjam, #in_gudang_asal, #in_gudang_tujuan').val('');
            $('input[name="in_entitas_dipinjam"], input[name="in_entitas_peminjam"], input[name="in_gudang_asal"], input[name="in_gudang_tujuan"]').val('');
            
            // Enable semua field
            $("#in_tanggal, #in_entitas_peminjam, #in_entitas_dipinjam, #in_gudang_asal, #in_gudang_tujuan, #in_nomor_peminjaman").prop('disabled', false);
            $("#draftButton, #submitButton").prop('disabled', false);
            $("#tbodyListData select, #tbodyListData input[type='number']").prop('disabled', false);
            
            // Load list nomor peminjaman setelah modal ditampilkan
            setTimeout(function() {
                loadListNomorPeminjaman();
            }, 300);
        }
    });
    
    // Fungsi untuk load list nomor peminjaman
    function loadListNomorPeminjaman() {
        // Destroy Select2 jika sudah ada
        if ($('#in_nomor_peminjaman').hasClass('select2-hidden-accessible')) {
            $('#in_nomor_peminjaman').select2('destroy');
        }
        
        $.ajax({
            url: '../peminjaman/get_data.php?action=get_list_nomor_peminjaman',
            type: 'GET',
            dataType: 'json',
            beforeSend: function() {
                // Tampilkan loading
                $('#in_nomor_peminjaman').html('<option value="">Memuat data...</option>');
            },
            success: function(response) {
                if (response && response.success && response.data && response.data.length > 0) {
                    let options = '<option value="">-- Pilih Nomor Peminjaman --</option>';
                    response.data.forEach(function(item) {
                        const nomor = item.nomor_peminjaman || '';
                        const tanggal = item.tanggal_peminjaman || '';
                        const entitasPeminjam = (item.entitas_peminjam || '').replace(/"/g, '&quot;');
                        const entitasDipinjam = (item.entitas_dipinjam || '').replace(/"/g, '&quot;');
                        const gudangAsal = (item.gudang_asal || '').replace(/"/g, '&quot;');
                        const gudangTujuan = (item.gudang_tujuan || '').replace(/"/g, '&quot;');
                        
                        options += '<option value="' + nomor + '" ';
                        options += 'data-tanggal="' + tanggal + '" ';
                        options += 'data-entitas-peminjam="' + entitasPeminjam + '" ';
                        options += 'data-entitas-dipinjam="' + entitasDipinjam + '" ';
                        options += 'data-gudang-asal="' + gudangAsal + '" ';
                        options += 'data-gudang-tujuan="' + gudangTujuan + '">';
                        options += nomor + '</option>';
                    });
                    $('#in_nomor_peminjaman').html(options);
                    
                    // Initialize Select2 setelah data dimuat
                    setTimeout(function() {
                        $('#in_nomor_peminjaman').select2({
                            placeholder: "Pilih Nomor Peminjaman",
                            allowClear: true,
                            dropdownParent: $('#tambahModal'),
                            width: '100%',
                            language: {
                                noResults: function() {
                                    return "Tidak ada nomor peminjaman ditemukan";
                                }
                            }
                        });
                    }, 100);
                } else {
                    $('#in_nomor_peminjaman').html('<option value="">-- Tidak ada data peminjaman --</option>');
                    if (response && !response.success) {
                        alert('Gagal memuat list nomor peminjaman: ' + (response.error || 'Unknown error'));
                    }
                }
            },
            error: function(xhr, status, error) {
                $('#in_nomor_peminjaman').html('<option value="">-- Error memuat data --</option>');
                alert('Gagal memuat list nomor peminjaman. Silakan refresh halaman dan coba lagi.');
            }
        });
    }
    
    // Handler saat nomor peminjaman dipilih (gunakan event delegation untuk Select2)
    $(document).on('change', '#in_nomor_peminjaman', function() {
        const nomorPeminjaman = $(this).val();
        const selectedOption = $(this).find('option:selected');
        
        if (!nomorPeminjaman) {
            // Reset form jika nomor peminjaman dikosongkan
            $('#in_entitas_dipinjam, #in_entitas_peminjam, #in_gudang_asal, #in_gudang_tujuan').val('');
            $('#in_entitas_dipinjam_hidden, #in_entitas_peminjam_hidden, #in_gudang_asal_hidden, #in_gudang_tujuan_hidden').val('');
            $('#tbodyListData').empty().append('<tr id="emptyRow"><td colspan="6" class="text-center">Pilih Nomor Peminjaman untuk melihat detail produk</td></tr>');
            return;
        }
        
        // Populate form dengan data dari atribut option
        const entitasPeminjam = selectedOption.data('entitas-peminjam') || '';
        const entitasDipinjam = selectedOption.data('entitas-dipinjam') || '';
        const gudangAsal = selectedOption.data('gudang-asal') || '';
        const gudangTujuan = selectedOption.data('gudang-tujuan') || '';
        
        $('#in_entitas_peminjam').val(entitasPeminjam);
        $('input[name="in_entitas_peminjam"]').val(entitasPeminjam);
        $('#in_entitas_dipinjam').val(entitasDipinjam);
        $('input[name="in_entitas_dipinjam"]').val(entitasDipinjam);
        $('#in_gudang_asal').val(gudangAsal);
        $('input[name="in_gudang_asal"]').val(gudangAsal);
        $('#in_gudang_tujuan').val(gudangTujuan);
        $('input[name="in_gudang_tujuan"]').val(gudangTujuan);
        
        // Load detail produk dari peminjaman
        loadDetailPeminjaman(nomorPeminjaman);
    });
    
    // Fungsi untuk load detail produk dari peminjaman
    function loadDetailPeminjaman(nomorPeminjaman) {
        const gudangAsal = $('#in_gudang_asal').val();
        
        $.ajax({
            url: '../peminjaman/get_data.php?action=get_peminjaman_detail',
            type: 'POST',
            data: {
                nomor_peminjaman: nomorPeminjaman
            },
            dataType: 'json',
            beforeSend: function() {
                $('#tbodyListData').html('<tr><td colspan="6" class="text-center"><div class="spinner-border spinner-border-sm" role="status"></div> Memuat data...</td></tr>');
            },
            success: function(response) {
                if (response && response.length > 0) {
                    let tbody = '';
                    let no = 1;
                    let itemsToLoad = [];
                    
                    response.forEach(function(item) {
                        // Skip penyesuaian untuk pengembalian (hanya tampilkan data asli)
                        if (item.catatan && item.catatan.toLowerCase().includes('penyesuaian')) {
                            return; // Skip penyesuaian
                        }
                        
                        itemsToLoad.push(item);
                    });
                    
                    if (itemsToLoad.length === 0) {
                        $('#tbodyListData').html('<tr id="emptyRow"><td colspan="6" class="text-center">Tidak ada data produk untuk dikembalikan</td></tr>');
                        return;
                    }
                    
                    // Load stok untuk semua produk sekaligus
                    loadStokForProducts(itemsToLoad, gudangAsal, function(stokMap) {
                        itemsToLoad.forEach(function(item) {
                            const stok = stokMap[item.produk] || 0;
                            
                            tbody += '<tr data-id="' + (item.id || '') + '" data-produk="' + (item.produk || '') + '">';
                            tbody += '<td class="text-center">' + no + '</td>';
                            tbody += '<td>' + (item.produk || '-') + '</td>';
                            tbody += '<td class="text-center"><strong>' + (item.qty || 0) + '</strong></td>';
                            tbody += '<td class="text-center"><strong>' + stok + '</strong></td>';
                            tbody += '<td class="text-center">';
                            // Untuk tambah data pengembalian baru, jumlah dikembalikan default ke 0
                            tbody += '<input type="number" class="form-control text-center" name="in_jumlah_kembali[]" min="0" max="' + (item.qty || 0) + '" value="0" required>';
                            tbody += '<input type="hidden" name="in_produk[]" value="' + (item.produk || '') + '">';
                            tbody += '<input type="hidden" name="in_jumlah_dipinjam[]" value="' + (item.qty || 0) + '">';
                            tbody += '</td>';
                            tbody += '<td class="text-center">';
                            tbody += '<button type="button" class="btn btn-sm btn-danger btnDeleteRowPengembalian">';
                            tbody += '<i class="fas fa-trash-alt"></i>';
                            tbody += '</button>';
                            tbody += '</td>';
                            tbody += '</tr>';
                            no++;
                        });
                        
                        $('#tbodyListData').html(tbody);
                    });
                } else {
                    $('#tbodyListData').html('<tr id="emptyRow"><td colspan="6" class="text-center">Tidak ada data produk untuk dikembalikan</td></tr>');
                }
            },
            error: function(xhr, status, error) {
                console.error('Error loading detail peminjaman:', error);
                alert('Gagal memuat detail produk peminjaman');
                $('#tbodyListData').html('<tr id="emptyRow"><td colspan="6" class="text-center text-danger">Error memuat data</td></tr>');
            }
        });
    }
    
    // Fungsi untuk mengambil stok dari omni_stok_akhir berdasarkan gudang dan produk
    function loadStokForProducts(items, gudangAsal, callback) {
        if (!gudangAsal || items.length === 0) {
            const emptyMap = {};
            items.forEach(function(item) {
                emptyMap[item.produk] = 0;
            });
            callback(emptyMap);
            return;
        }
        
        // Ambil semua produk unik
        const produkList = items.map(item => item.produk).filter((v, i, a) => a.indexOf(v) === i);
        
        // Buat request untuk setiap produk
        let completed = 0;
        const stokMap = {};
        
        if (produkList.length === 0) {
            callback(stokMap);
            return;
        }
        
        produkList.forEach(function(produk) {
            $.ajax({
                url: '../peminjaman/get_data.php?action=get_stok',
                type: 'POST',
                data: {
                    produk: produk,
                    gudang: gudangAsal
                },
                dataType: 'json',
                success: function(response) {
                    if (response && response.status === 'success') {
                        stokMap[produk] = response.stok || 0;
                    } else {
                        stokMap[produk] = 0;
                    }
                    completed++;
                    if (completed === produkList.length) {
                        callback(stokMap);
                    }
                },
                error: function() {
                    stokMap[produk] = 0;
                    completed++;
                    if (completed === produkList.length) {
                        callback(stokMap);
                    }
                }
            });
        });
    }
    
    // Handler untuk delete row
    $(document).on('click', '.btnDeleteRowPengembalian', function() {
        const rowsCount = $('#tbodyListData tr').length;
        if (rowsCount <= 1) {
            alert("Minimal harus ada 1 baris.");
            return;
        }
        $(this).closest('tr').remove();
        // Renumber rows
        $('#tbodyListData tr').each(function(index) {
            $(this).find('td:first').text(index + 1);
        });
    });
    
    // Handler untuk submit form pengembalian
    $('#tambahForm').on('submit', function(e) {
        e.preventDefault();
        
        const nomorPeminjaman = $('#in_nomor_peminjaman').val();
        if (!nomorPeminjaman) {
            alert('Pilih Nomor Peminjaman terlebih dahulu!');
            return;
        }
        
        // Validasi jumlah dikembalikan tidak boleh melebihi jumlah dipinjam
        let isValid = true;
        $('#tbodyListData tr').each(function() {
            const $row = $(this);
            const jumlahDipinjam = parseFloat($row.find('input[name="in_jumlah_dipinjam[]"]').val()) || 0;
            const jumlahKembali = parseFloat($row.find('input[name="in_jumlah_kembali[]"]').val()) || 0;
            
            if (jumlahKembali > jumlahDipinjam) {
                isValid = false;
                $row.find('input[name="in_jumlah_kembali[]"]').addClass('is-invalid');
                return false;
            } else {
                $row.find('input[name="in_jumlah_kembali[]"]').removeClass('is-invalid');
            }
        });
        
        if (!isValid) {
            alert('Jumlah dikembalikan tidak boleh melebihi jumlah dipinjam!');
            return;
        }
        
        // Submit form
        const formData = $(this).serialize();
        const actionButton = $('button[type="submit"]:focus').val() || $('button[name="in_action_button"]:last').val();
        
        $.ajax({
            url: 'action.php?act=add',
            type: 'POST',
            data: formData + '&in_action_button=' + actionButton,
            dataType: 'json',
            success: function(response) {
                if (response.success) {
                    alert(response.message || 'Data pengembalian berhasil disimpan!');
                    $('#tambahModal').modal('hide');
                    if (typeof tablePengembalian !== 'undefined' && tablePengembalian) {
                        tablePengembalian.ajax.reload();
                    } else {
                        location.reload();
                    }
                } else {
                    alert(response.error || response.message || 'Gagal menyimpan data pengembalian');
                }
            },
            error: function(xhr, status, error) {
                console.error('AJAX Error:', error, xhr.responseText);
                alert('Gagal menyimpan data pengembalian. Silakan coba lagi.');
            }
        });
    });
    
    // PERBAIKAN: Handler untuk tombol View PDF
    $(document).on('click', '.view_pdf_btn', function() {
        const nomorPengembalian = $(this).data('nomor-peminjaman'); // Note: menggunakan data attribute yang sama
        if (!nomorPengembalian) {
            alert('Nomor pengembalian tidak ditemukan!');
            return;
        }
        
        // Tampilkan modal
        $('#viewDetailModal').modal('show');
        
        // Ambil data detail pengembalian dari get_data.php?action=get_peminjaman_details
        $.ajax({
            url: '../peminjaman/get_data.php?action=get_peminjaman_details',
            method: 'POST',
            data: { 
                nomor_peminjaman: nomorPengembalian,
                type: 'pengembalian'
            },
            beforeSend: function() {
                // Show loading spinner in modal body
                $('#viewDetailModal .modal-body').html(`
                    <div class="text-center">
                        <div class="spinner-border" role="status">
                            <span class="sr-only">Loading...</span>
                        </div>
                    </div>
                `);
            },
            success: function(response) {
                // Populate modal content dengan HTML dari get_data.php?action=get_peminjaman_details
                $('#viewDetailModal .modal-body').html(response);
            },
            error: function(xhr, status, error) {
                $('#viewDetailModal .modal-body').html(`
                    <div class="alert alert-danger">
                        <h5>Error</h5>
                        <p>Gagal memuat data detail pengembalian.</p>
                        <p class="mb-0"><small>Status: ${xhr.status} | Error: ${error}</small></p>
                    </div>
                `);
            }
        });
    });
    
    // Handler untuk tombol View Detail (jika masih diperlukan untuk fungsi lain)
    $(document).on('click', '.view_detail_btn', function() {
        const nomorMutasi = $(this).data('nomor-mutasi');
        if (!nomorMutasi) {
            alert('Nomor mutasi tidak ditemukan!');
            return;
        }
        
        // Tampilkan modal
        $('#viewDetailModal').modal('show');
        
        // ... kode untuk load detail data ...
    });
    
    // Handler untuk tombol Tambah Rincian di detail pengembalian
    $(document).on('click', '.addRowPeminjaman', function() {
        const nomorPeminjaman = $(this).data('nomor-peminjaman');
        const tbodyListData = $('#viewDetailModal').find('.tbodyListDataPeminjaman').filter(function() {
            return $(this).data('nomor-peminjaman') === nomorPeminjaman;
        })[0];
        
        if (tbodyListData) {
            // Hapus baris "Tidak ada data detail" jika ada
            const $tbody = $(tbodyListData);
            const emptyRow = $tbody.find('tr td[colspan]');
            if (emptyRow.length) {
                emptyRow.closest('tr').remove();
            }
            
            const rowCount = $tbody.find('tr').length + 1;
            const newRow = $('<tr></tr>');
            
            // Ambil gudang peminjam dari data attribute tbody
            const gudangAsal = $tbody.data('gudang-asal') || '';
            
            // Gunakan opsi dari index.php (yang sudah tersedia di halaman)
            // json_encode akan menghasilkan string JSON yang valid, yang kemudian akan di-parse oleh JavaScript
            const gudangOpts = <?= json_encode($gudangOptions ?? '', JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES) ?>;
            const productOpts = <?= json_encode($productOptions ?? '', JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES) ?>;
            
            newRow.html(`
                <td class="text-center">${rowCount}</td>
                <td>
                    <select name="in_gudang[]" class="form-control form-control-sm select2" required>
                        ${gudangOpts || '<option value="">-- Pilih Gudang --</option>'}
                    </select>
                </td>
                <td>
                    <select name="in_produk[]" class="form-control form-control-sm select2" required>
                        ${productOpts || '<option value="">-- Pilih Produk --</option>'}
                    </select>
                </td>
                <td class="text-center">
                    <input type="number" name="in_jumlah[]" class="form-control form-control-sm text-center" min="0" value="0" required>
                    <input type="hidden" name="in_id[]" value="">
                </td>
                <td class="text-center">
                    <button type="button" class="btn btn-sm btn-danger btnDeleteRowPengembalian">
                        <i class="fas fa-trash-alt"></i>
                    </button>
                </td>
            `);
            
            // Tandai sebagai data baru (bisa diedit)
            newRow.addClass('data-new');
            
            // Tambahkan row ke tbody
            $tbody.append(newRow);
            
            // Inisialisasi Select2 untuk dropdown baru
            newRow.find('.select2').select2({
                width: '100%',
                dropdownParent: $('#viewDetailModal')
            });
            
            // Set gudang peminjam sebagai default jika tersedia
            if (gudangAsal) {
                const gudangSelect = newRow.find('select[name="in_gudang[]"]');
                if (gudangSelect.find(`option[value="${gudangAsal}"]`).length > 0) {
                    gudangSelect.val(gudangAsal).trigger('change');
                }
            }
            
            // Tampilkan tombol Simpan Perubahan di footer
            $('#viewDetailModal').find('.modal-footer .simpanPerubahanPengembalian').attr('data-nomor-pengembalian', nomorPeminjaman).show();
        }
    });
    
    // Handler untuk tombol hapus baris pengembalian
    $(document).on('click', '.btnDeleteRowPengembalian', function() {
        const row = $(this).closest('tr');
        const tbody = row.closest('tbody');
        const nomorPeminjaman = tbody.data('nomor-peminjaman');
        
        // Cek status peminjaman dari modal atau dari data attribute
        const isDraft = $('#viewDetailModal').find('.formEditPengembalian[data-nomor-pengembalian="' + nomorPeminjaman + '"]').length > 0;
        
        // Untuk Draft, semua data bisa dihapus (termasuk existing)
        // Untuk Final, hanya data baru yang bisa dihapus
        if (!isDraft && row.hasClass('data-existing')) {
            alert('Data existing tidak dapat dihapus. Hanya data baru yang dapat dihapus.');
            return;
        }
        
        // Cek apakah masih ada baris lain
        const totalRows = tbody.find('tr').length;
        if (totalRows <= 1) {
            alert('Minimal harus ada 1 baris data.');
            return;
        }
        
        row.remove();
        
        // Renumber rows
        tbody.find('tr').each(function(index) {
            $(this).find('td:first').text(index + 1);
        });
        
        // Tombol Simpan Perubahan tetap tampil meskipun tidak ada data baru
        // Tidak perlu menyembunyikan tombol
    });
    
    // Handler untuk tombol Simpan Perubahan Pengembalian
    $(document).on('click', '.simpanPerubahanPengembalian', function(e) {
        e.preventDefault();
        const nomorPeminjaman = $(this).attr('data-nomor-pengembalian');
        if (!nomorPeminjaman || nomorPeminjaman === '') {
            alert('Nomor peminjaman tidak ditemukan!');
            return;
        }
        const form = $('#viewDetailModal').find('.formEditPengembalian').filter(function() {
            return $(this).data('nomor-pengembalian') === nomorPeminjaman;
        });
        
        if (!form.length) {
            alert('Form tidak ditemukan!');
            return;
        }
        
        // Validasi form (hanya validasi data baru, skip data existing yang disabled)
        let isValid = true;
        form.find('tr.data-new select[name="in_gudang[]"], tr.data-new select[name="in_produk[]"], tr.data-new input[name="in_jumlah[]"]').each(function() {
            if ($(this).is(':visible') && !$(this).is(':disabled') && !$(this).val()) {
                isValid = false;
                $(this).addClass('is-invalid');
            } else {
                $(this).removeClass('is-invalid');
            }
        });
        
        if (!isValid) {
            alert('Mohon lengkapi semua field yang wajib diisi!');
            return;
        }
        
        // Konfirmasi
        if (!confirm('Apakah Anda yakin ingin menyimpan perubahan detail peminjaman?')) {
            return;
        }
        
        // Kirim data dengan AJAX
        // Pastikan data existing (disabled) tetap dikirim
        const formData = new FormData();
        
        // Kumpulkan data untuk debugging
        const debugData = {
            gudang: [],
            produk: [],
            jumlah: [],
            id: []
        };
        
        // Ambil semua input dari form, termasuk yang disabled
        // Untuk disabled fields, kita perlu enable sementara untuk mendapatkan value
        form.find('input, select').each(function() {
            const $field = $(this);
            const name = $field.attr('name');
            if (name) {
                let value = '';
                let wasDisabled = false;
                
                // Jika disabled, enable sementara untuk mendapatkan value
                if ($field.is(':disabled')) {
                    wasDisabled = true;
                    $field.prop('disabled', false);
                }
                
                // Ambil value
                if ($field.is('select')) {
                    // Untuk select, ambil value langsung
                    value = $field.val() || '';
                } else {
                    // Untuk input, ambil value langsung
                    value = $field.val() || '';
                }
                
                // Kembalikan disabled state jika sebelumnya disabled
                if (wasDisabled) {
                    $field.prop('disabled', true);
                }
                
                // Append ke FormData
                if (name) {
                    // Untuk array fields, selalu append
                    if (name.endsWith('[]')) {
                        formData.append(name, value || '');
                        
                        // Debug: simpan ke array untuk logging
                        if (name === 'in_gudang[]') {
                            debugData.gudang.push(value || '');
                        } else if (name === 'in_produk[]') {
                            debugData.produk.push(value || '');
                        } else if (name === 'in_jumlah[]') {
                            debugData.jumlah.push(value || '');
                        } else if (name === 'in_id[]') {
                            debugData.id.push(value || '');
                        }
                    } else if (name === 'nomor_peminjaman') {
                        formData.append(name, value);
                    } else if (value !== '') {
                        formData.append(name, value);
                    }
                }
            }
        });
        
        // Debug logging
        console.log('Form Data to Send:', {
            gudang: debugData.gudang,
            produk: debugData.produk,
            jumlah: debugData.jumlah,
            id: debugData.id,
            nomor_peminjaman: formData.get('nomor_peminjaman')
        });
        
        formData.append('act', 'edit_detail');
        
        $.ajax({
            type: 'POST',
            url: 'action.php?act=edit_detail',
            data: formData,
            processData: false,
            contentType: false,
            dataType: 'json',
            success: function(response) {
                if (response.success) {
                    alert('Data detail peminjaman berhasil disimpan!');
                    // Destroy semua Select2 di modal sebelum reload
                    $('#viewDetailModal .select2').each(function() {
                        const $select = $(this);
                        if ($select.hasClass('select2-hidden-accessible')) {
                            try {
                                $select.select2('destroy');
                            } catch(e) {
                                // Ignore error
                            }
                        }
                    });
                    // Reload DataTables untuk update qty di dashboard tanpa refresh halaman
                    if (typeof tablePeminjaman !== 'undefined' && tablePeminjaman) {
                        tablePeminjaman.ajax.reload(null, false); // false = keep current page
                    }
                    // Reload view detail untuk menampilkan data terbaru
                    setTimeout(function() {
                        $('.view_pdf_btn[data-nomor-peminjaman="' + nomorPeminjaman + '"]').click();
                    }, 100);
                } else {
                    alert('Error: ' + (response.error || 'Gagal menyimpan data'));
                }
            },
            error: function(xhr, status, error) {
                console.error('AJAX Error:', error, xhr.responseText);
                alert('Gagal mengirim data. Pastikan semua form terisi dengan benar!');
            }
        });
    });
});
</script>

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

if (!isset($_SESSION["ssLogin"])) {
    ob_clean();
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Unauthorized access']);
    ob_end_flush();
    exit;
}

require_once "../../config/config.php";
require_once "../../config/functions.php";
require_once "query_helper.php";

// Ambil action dari GET atau POST
$action = isset($_GET['action']) ? $_GET['action'] : (isset($_POST['action']) ? $_POST['action'] : 'get_data');

// Routing berdasarkan action
switch ($action) {
    case 'get_peminjaman_detail':
        ob_clean();
        header('Content-Type: application/json');
        handleGetPeminjamanDetail();
        ob_end_flush();
        break;
    
    case 'get_peminjaman_details':
        // Handler untuk get_peminjaman_details (HTML output)
        handleGetPeminjamanDetails();
        break;
    
    case 'get_stok_by_produk':
        handleGetStokByProduk();
        break;
    
    case 'get_stok':
        handleGetStokByProduk();
        break;
    
    case 'get_entitas_code':
        handleGetEntitasCode();
        break;
    
    case 'get_gudang_by_entitas':
        handleGetGudangByEntitas();
        break;
    
    case 'get_list_nomor_peminjaman':
        handleGetListNomorPeminjaman();
        break;
    
    case 'get_sisa_qty_pengembalian':
        handleGetSisaQtyPengembalian();
        break;
    
    case 'get_data':
    default:
        // handleGetData() sudah mengurus output buffering dan exit()
        try {
            handleGetData();
        } catch (Throwable $e) {
            // Tangkap semua error termasuk fatal error
            while (ob_get_level()) {
                ob_end_clean();
            }
            header('Content-Type: application/json; charset=utf-8');
            error_log("Fatal error in get_data.php: " . $e->getMessage() . " | Trace: " . $e->getTraceAsString());
            echo json_encode([
                "draw" => isset($_GET['draw']) ? intval($_GET['draw']) : 1,
                "recordsTotal" => 0,
                "recordsFiltered" => 0,
                "data" => [],
                "error" => "Fatal error: " . $e->getMessage()
            ], JSON_UNESCAPED_UNICODE);
            exit();
        }
        break;
}

// Function ini tidak digunakan lagi, dihapus untuk optimasi

// Function untuk get data peminjaman (DataTables)
function handleGetData() {
    global $db_dc;
    
    // Pastikan database connection ada
    if (!isset($db_dc) || !$db_dc) {
        throw new Exception("Database connection not available");
    }
    
    // Test database connection
    if (!mysqli_ping($db_dc)) {
        throw new Exception("Database connection lost");
    }
    
    // Bersihkan output buffer di awal untuk memastikan tidak ada output sebelum JSON
    // Pastikan semua output buffer dibersihkan
    while (ob_get_level()) {
        ob_end_clean();
    }
    ob_start();
    
    try {
        // Ambil parameter dari request
        $start_date = isset($_GET['start_date']) ? trim($_GET['start_date']) : '';
        $end_date = isset($_GET['end_date']) ? trim($_GET['end_date']) : '';
        $entitas_peminjam = isset($_GET['entitas_peminjam']) ? $_GET['entitas_peminjam'] : '';
        $entitas_dipinjam = isset($_GET['entitas_dipinjam']) ? $_GET['entitas_dipinjam'] : '';
        $gudang_asal = isset($_GET['gudang_asal']) ? $_GET['gudang_asal'] : '';
        $gudang_tujuan = isset($_GET['gudang_tujuan']) ? $_GET['gudang_tujuan'] : '';
        // Ambil type dari GET atau POST (DataTables bisa kirim via GET)
        $type = isset($_GET['type']) ? trim($_GET['type']) : (isset($_POST['type']) ? trim($_POST['type']) : 'peminjaman');
        
        // Validasi type - hanya terima 'peminjaman' atau 'pengembalian'
        if ($type !== 'peminjaman' && $type !== 'pengembalian') {
            $type = 'peminjaman'; // Default ke peminjaman jika tidak valid
        }
        
        // Debug: log type untuk troubleshooting
        error_log("get_data.php - Type parameter: " . $type . " | GET: " . (isset($_GET['type']) ? $_GET['type'] : 'not set') . " | POST: " . (isset($_POST['type']) ? $_POST['type'] : 'not set'));

        // Validasi dan set default untuk tanggal jika kosong
        if (empty($start_date)) {
            $start_date = date('Y-m-d', strtotime('-29 days'));
        }
        if (empty($end_date)) {
            $end_date = date('Y-m-d');
        }
        
        // Validasi format tanggal
        $start_date_obj = DateTime::createFromFormat('Y-m-d', $start_date);
        $end_date_obj = DateTime::createFromFormat('Y-m-d', $end_date);
        
        if (!$start_date_obj || !$end_date_obj) {
            // Jika format salah, gunakan default
            $start_date = date('Y-m-d', strtotime('-29 days'));
            $end_date = date('Y-m-d');
        }
        
        // Pastikan format tanggal valid sebelum digunakan di query
        $start_date = $start_date_obj ? $start_date_obj->format('Y-m-d') : date('Y-m-d', strtotime('-29 days'));
        $end_date = $end_date_obj ? $end_date_obj->format('Y-m-d') : date('Y-m-d');
        
        // Escape untuk SQL injection prevention
        $start_date = mysqli_real_escape_string($db_dc, $start_date);
        $end_date = mysqli_real_escape_string($db_dc, $end_date);

        // DataTables parameters
        $draw = isset($_GET['draw']) ? intval($_GET['draw']) : 1;
        $start = isset($_GET['start']) ? intval($_GET['start']) : 0;
        $length = isset($_GET['length']) ? intval($_GET['length']) : 10;
        $search = isset($_GET['search']['value']) ? $_GET['search']['value'] : '';

        // Tentukan tabel berdasarkan type - PASTIKAN menggunakan tabel yang benar
        if ($type === 'pengembalian') {
            $table_name = 'pengembalian_stok';
            $tanggal_field = 'tanggal_pengembalian';
            $nomor_field = 'nomor_pengembalian';
            $status_field = 'status_pengembalian';
            $nomor_peminjaman_field = 'nomor_peminjaman'; // Kolom nomor peminjaman untuk pengembalian
        } else {
            $table_name = 'peminjaman_stok';
            $tanggal_field = 'tanggal_peminjaman';
            $nomor_field = 'nomor_peminjaman';
            $status_field = 'status_peminjaman';
            $nomor_peminjaman_field = null;
        }
        
        // Debug: log tabel yang digunakan - dengan detail lengkap
        error_log("get_data.php - Type: " . $type . " | Table: " . $table_name . " | GET type: " . (isset($_GET['type']) ? $_GET['type'] : 'not set') . " | POST type: " . (isset($_POST['type']) ? $_POST['type'] : 'not set'));
        
        // PASTIKAN: Force table untuk pengembalian - tidak boleh salah
        if ($type === 'pengembalian' && $table_name !== 'pengembalian_stok') {
            $table_name = 'pengembalian_stok';
            error_log("get_data.php - FORCED CORRECTION: Using pengembalian_stok table");
        }
        
        // PERBAIKAN: Query yang lebih aman untuk handle tanggal - TANPA DATE() atau CAST untuk menghindari error
        // Untuk pengembalian, ambil juga nomor_peminjaman asli
        $nomor_peminjaman_select = ($type === 'pengembalian' && isset($nomor_peminjaman_field)) 
            ? ", m.$nomor_peminjaman_field as nomor_peminjaman_original" 
            : "";
        $nomor_peminjaman_group = ($type === 'pengembalian' && isset($nomor_peminjaman_field)) 
            ? ", MIN(pg.nomor_peminjaman_original) as nomor_peminjaman_original" 
            : "";
        
        $query = "
            WITH data_grouped AS (
            SELECT 
                CASE WHEN m.$nomor_field IS NULL OR m.$nomor_field = '' OR TRIM(m.$nomor_field) = '' 
                        THEN CONCAT(COALESCE(m.entitas_peminjam, ''), '|', m.gudang_asal, '|', m.gudang_tujuan, '|', COALESCE(DATE(m.$tanggal_field), 'NULL'), '|', m.$status_field)
                    ELSE m.$nomor_field 
                END as nomor_peminjaman,
                m.$tanggal_field as tanggal_peminjaman,
                m.entitas_peminjam,
                m.entitas_dipinjam,
                m.gudang_asal,
                m.gudang_tujuan,
                m.$status_field as status_peminjaman,
                m.id,
                m.produk,
                m.qty
                $nomor_peminjaman_select
            FROM `$table_name` m
            WHERE (m.$tanggal_field >= '$start_date 00:00:00' 
                AND m.$tanggal_field < DATE_ADD('$end_date', INTERVAL 1 DAY))
                OR (m.$tanggal_field IS NULL AND m.$status_field = 'Draft')
            )
            SELECT 
                pg.nomor_peminjaman,
                pg.tanggal_peminjaman,
                pg.entitas_peminjam,
                pg.entitas_dipinjam,
                MIN(pg.gudang_asal) as gudang_asal,
                GROUP_CONCAT(DISTINCT pg.gudang_tujuan ORDER BY pg.gudang_tujuan SEPARATOR ', ') as gudang_tujuan,
                COUNT(DISTINCT pg.produk) as jumlah_item,
                SUM(pg.qty) as total_qty,
                pg.status_peminjaman,
                MIN(pg.id) as min_id
                $nomor_peminjaman_group
            FROM data_grouped pg
            WHERE 1=1
        ";

        // Optimasi: Gunakan helper function untuk build filter conditions
        if ($type === 'pengembalian') {
            require_once "../pengembalian/query_helper.php";
            $filterConditions = buildFilterConditionsPengembalian($db_dc, $entitas_peminjam, $entitas_dipinjam, $gudang_asal, $gudang_tujuan, $search, true);
        } else {
            $filterConditions = buildFilterConditionsPeminjaman($db_dc, $entitas_peminjam, $entitas_dipinjam, $gudang_asal, $gudang_tujuan, $search, true);
        }
        
        // Tambahkan filter ke query utama - filter diterapkan pada CTE
        if (!empty($filterConditions)) {
            // Filter diterapkan pada CTE data_grouped (masih menggunakan alias m.)
            $filterConditionsStr = implode(" AND ", $filterConditions);
            // Ganti alias pg. menjadi m. untuk filter di CTE
            $filterConditionsStr = str_replace('pg.', 'm.', $filterConditionsStr);
            // Tambahkan filter ke WHERE clause CTE
            $query = str_replace(
                "WHERE (m.$tanggal_field >= '$start_date 00:00:00'",
                "WHERE (" . $filterConditionsStr . ") AND (m.$tanggal_field >= '$start_date 00:00:00'",
                $query
            );
        }

        // Count query - gunakan helper function yang sesuai dengan type
        if ($type === 'pengembalian') {
            require_once "../pengembalian/query_helper.php";
            $countQueryTotal = getCountQueryPengembalian($db_dc, $start_date, $end_date, $entitas_peminjam, $entitas_dipinjam, $gudang_asal, $gudang_tujuan, $search, false);
            $countQueryFiltered = getCountQueryPengembalian($db_dc, $start_date, $end_date, $entitas_peminjam, $entitas_dipinjam, $gudang_asal, $gudang_tujuan, $search, true);
        } else {
            $countQueryTotal = getCountQueryPeminjaman($db_dc, $start_date, $end_date, $entitas_peminjam, $entitas_dipinjam, $gudang_asal, $gudang_tujuan, $search, false);
            $countQueryFiltered = getCountQueryPeminjaman($db_dc, $start_date, $end_date, $entitas_peminjam, $entitas_dipinjam, $gudang_asal, $gudang_tujuan, $search, true);
        }

        // Eksekusi count query untuk total records
        $countResultTotal = @mysqli_query($db_dc, $countQueryTotal);
        $recordsTotal = 0;
        if ($countResultTotal) {
            $countRowTotal = mysqli_fetch_assoc($countResultTotal);
            $recordsTotal = intval($countRowTotal['total']);
        } else {
            // Jika query error, gunakan fallback dan log error
            $error = mysqli_error($db_dc);
            error_log("Count query total error: " . $error);
            $recordsTotal = 0;
        }

        // Eksekusi count query untuk filtered records
        $countResultFiltered = @mysqli_query($db_dc, $countQueryFiltered);
        $recordsFiltered = 0;
        if ($countResultFiltered) {
            $countRowFiltered = mysqli_fetch_assoc($countResultFiltered);
            $recordsFiltered = intval($countRowFiltered['total']);
        } else {
            // Jika query error, gunakan fallback dan log error
            $error = mysqli_error($db_dc);
            error_log("Count query filtered error: " . $error);
            $recordsFiltered = 0;
        }

        // Tambahkan GROUP BY dan ORDER BY untuk query utama
        // Untuk pengembalian, tambahkan nomor_peminjaman_original di GROUP BY
        $nomor_peminjaman_group_by = ($type === 'pengembalian' && isset($nomor_peminjaman_field)) 
            ? ", pg.nomor_peminjaman_original" 
            : "";
        $query .= " GROUP BY 
            CASE WHEN pg.nomor_peminjaman IS NULL OR pg.nomor_peminjaman = '' OR TRIM(pg.nomor_peminjaman) = '' 
                THEN CONCAT(COALESCE(pg.entitas_peminjam, ''), '|', pg.gudang_asal, '|', pg.gudang_tujuan, '|', COALESCE(DATE(pg.tanggal_peminjaman), 'NULL'), '|', pg.status_peminjaman)
                ELSE pg.nomor_peminjaman 
            END,
            DATE(pg.tanggal_peminjaman), 
            pg.entitas_peminjam,
            pg.status_peminjaman
            $nomor_peminjaman_group_by";
        $query .= " ORDER BY 
            CASE WHEN pg.tanggal_peminjaman IS NULL THEN 0 ELSE 1 END DESC,
            pg.tanggal_peminjaman DESC, 
            CASE WHEN pg.nomor_peminjaman IS NULL OR pg.nomor_peminjaman = '' OR TRIM(pg.nomor_peminjaman) = '' 
                THEN '' 
                ELSE pg.nomor_peminjaman 
            END DESC";

        // Tambahkan LIMIT
        $query .= " LIMIT $start, $length";

        // Debug: log query yang akan dieksekusi (hanya bagian penting)
        error_log("get_data.php - Executing query with table: `$table_name` | type: $type | tanggal_field: $tanggal_field");
        
        // Eksekusi query dengan error handling sederhana
        $result = @mysqli_query($db_dc, $query);
        
        if (!$result) {
            $error_message = mysqli_error($db_dc);
            error_log("Query error: " . $error_message . " | Query: " . substr($query, 0, 500) . " | Table: `$table_name` | Type: $type");
            throw new Exception("Query error: " . $error_message);
        }
        
        // Debug: log jumlah row yang ditemukan
        $num_rows = mysqli_num_rows($result);
        error_log("get_data.php - Query executed successfully. Found $num_rows rows from table: `$table_name` (type: $type)");

        // Optimasi: Ambil semua data dulu, lalu hitung status secara batch
        $rowsData = [];
        $validNomorPeminjaman = [];
        
        if ($result && mysqli_num_rows($result) > 0) {
            while ($row = mysqli_fetch_assoc($result)) {
                // Normalisasi tanggal_peminjaman (disederhanakan)
                if (isset($row['tanggal_peminjaman'])) {
                    $tanggal_raw = $row['tanggal_peminjaman'];
                    if (empty($tanggal_raw) || $tanggal_raw === '0000-00-00' || $tanggal_raw === '0000-00-00 00:00:00') {
                        $row['tanggal_peminjaman'] = null;
                    } else {
                        // Ambil hanya bagian tanggal (YYYY-MM-DD)
                        $tanggal_clean = substr(trim($tanggal_raw), 0, 10);
                        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $tanggal_clean)) {
                            $row['tanggal_peminjaman'] = $tanggal_clean;
                        } else {
                            $row['tanggal_peminjaman'] = null;
                        }
                    }
                }
                $rowsData[] = $row;
                $nomor_peminjaman_original = $row['nomor_peminjaman'];
                if (!empty($nomor_peminjaman_original) && trim($nomor_peminjaman_original) != '') {
                    $validNomorPeminjaman[] = mysqli_real_escape_string($db_dc, $nomor_peminjaman_original);
                }
            }
        }
        
        // Optimasi: Gunakan helper functions untuk batch query yang lebih efisien
        $statusMap = [];
        $stokDipinjamMap = [];
        if (!empty($validNomorPeminjaman)) {
            $uniqueNomorPeminjaman = array_unique($validNomorPeminjaman);
            
            if ($type === 'pengembalian') {
                require_once "../pengembalian/query_helper.php";
                $peminjamanProductsMap = getPengembalianProductsBatch($db_dc, $uniqueNomorPeminjaman);
                $gudangTujuanMap = getGudangTujuanPengembalianBatch($db_dc, $uniqueNomorPeminjaman);
                $gudangAsalMap = getGudangAsalPengembalianBatch($db_dc, $uniqueNomorPeminjaman);
                list($logStokProductsMap, $logStokProductsMapMismatch) = getLogStokPengembalianBatch($db_dc, $uniqueNomorPeminjaman, $gudangTujuanMap);
                
                // Ambil nomor_peminjaman_original untuk mendapatkan stok dipinjam
                $nomorPeminjamanOriginalList = [];
                foreach ($rowsData as $row) {
                    if (isset($row['nomor_peminjaman_original']) && !empty($row['nomor_peminjaman_original']) && trim($row['nomor_peminjaman_original']) != '') {
                        $nomorPeminjamanOriginalList[] = mysqli_real_escape_string($db_dc, $row['nomor_peminjaman_original']);
                    }
                }
                if (!empty($nomorPeminjamanOriginalList)) {
                    $uniqueNomorPeminjamanOriginal = array_unique($nomorPeminjamanOriginalList);
                    $stokDipinjamMap = getStokDipinjamBatch($db_dc, $uniqueNomorPeminjamanOriginal);
                }
                
                $statusMap = calculateStatusPengembalianBatch($rowsData, $peminjamanProductsMap, $logStokProductsMap, $logStokProductsMapMismatch);
            } else {
                $peminjamanProductsMap = getPeminjamanProductsBatch($db_dc, $uniqueNomorPeminjaman);
                $gudangTujuanMap = getGudangTujuanPeminjamanBatch($db_dc, $uniqueNomorPeminjaman);
                $gudangAsalMap = getGudangAsalPeminjamanBatch($db_dc, $uniqueNomorPeminjaman);
                list($logStokProductsMap, $logStokProductsMapMismatch) = getLogStokPeminjamanBatch($db_dc, $uniqueNomorPeminjaman, $gudangTujuanMap);
                
                $statusMap = calculateStatusPeminjamanBatch($rowsData, $peminjamanProductsMap, $logStokProductsMap, $logStokProductsMapMismatch);
            }
        }

        // Process rows dengan status yang sudah dihitung
        $data = [];
        foreach ($rowsData as $row) {
            // Format tanggal - untuk Draft bisa NULL atau empty string
            $tanggal_peminjaman = $row['tanggal_peminjaman'] ?? null;
            
            // PERBAIKAN: Validasi yang lebih ketat untuk memastikan tanggal valid sebelum diproses
            $isValidDate = false;
            if (!empty($tanggal_peminjaman) && is_string($tanggal_peminjaman) && trim($tanggal_peminjaman) !== '') {
                $tanggal_peminjaman = trim($tanggal_peminjaman);
                // Cek format YYYY-MM-DD dan pastikan bukan nilai invalid
                if (strlen($tanggal_peminjaman) >= 10 && 
                    $tanggal_peminjaman !== '0000-00-00' && 
                    $tanggal_peminjaman !== '0000-00-00 00:00:00' &&
                    preg_match('/^\d{4}-\d{2}-\d{2}/', $tanggal_peminjaman)) {
                    // Validasi dengan DateTime untuk memastikan tanggal valid
                    $date = DateTime::createFromFormat('Y-m-d', substr($tanggal_peminjaman, 0, 10));
                    if ($date && $date->format('Y-m-d') === substr($tanggal_peminjaman, 0, 10)) {
                        $isValidDate = true;
                    }
                }
            }
            
            $tanggal = $isValidDate ? indoTgl(substr($tanggal_peminjaman, 0, 10)) : '<span class="text-muted">-</span>';
            
            // Status badge
            $statusBadge = '';
            $status_peminjaman = $row['status_peminjaman'];
            
            $calculatedStatus = 'final';
            $nomor_peminjaman_original = $row['nomor_peminjaman'];
            
            if ($status_peminjaman == 'Draft') {
                $statusBadge = '<span class="badge badge-warning">Draft</span>';
            } elseif ($status_peminjaman == 'Selesai') {
                // Jika status di database sudah Selesai, langsung tampilkan Selesai
                $statusBadge = '<span class="badge badge-success">Selesai</span>';
            } else if (!empty($nomor_peminjaman_original) && trim($nomor_peminjaman_original) != '' && isset($statusMap[$nomor_peminjaman_original])) {
                $calculatedStatus = $statusMap[$nomor_peminjaman_original]['status'];
                
                if ($calculatedStatus == 'selesai') {
                    $statusBadge = '<span class="badge badge-success">Selesai</span>';
                } elseif ($calculatedStatus == 'belum_selesai') {
                    $statusBadge = '<span class="badge badge-danger">Belum Selesai</span>';
                } elseif ($calculatedStatus == 'terproses') {
                    $statusBadge = '<span class="badge badge-info">Terproses</span>';
                } elseif ($status_peminjaman == 'Final' || $status_peminjaman == 'Aktif') {
                    $statusBadge = '<span class="badge badge-primary">Final</span>';
                } else {
                    $statusBadge = '<span class="badge badge-secondary">' . htmlspecialchars($status_peminjaman) . '</span>';
                }
            } elseif ($status_peminjaman == 'Final' || $status_peminjaman == 'Aktif') {
                $statusBadge = '<span class="badge badge-primary">Final</span>';
            } else {
                $statusBadge = '<span class="badge badge-secondary">' . htmlspecialchars($status_peminjaman) . '</span>';
            }
            
            // Handle nomor_peminjaman yang NULL (untuk Draft)
            $isDraftNoNomor = empty($nomor_peminjaman_original) || $nomor_peminjaman_original === null || $status_peminjaman == 'Draft';
            if (!$isDraftNoNomor && strpos($nomor_peminjaman_original, '|') !== false) {
                $isDraftNoNomor = true;
            }
            $nomor_peminjaman_display = $isDraftNoNomor ? '<span class="text-muted">-</span>' : $nomor_peminjaman_original;
            
            // Untuk pengembalian, ambil nomor_peminjaman_original (nomor peminjaman yang dipilih saat tambah data)
            $nomor_peminjaman_original_display = '';
            if ($type === 'pengembalian' && isset($row['nomor_peminjaman_original']) && !empty($row['nomor_peminjaman_original'])) {
                $nomor_peminjaman_original_display = htmlspecialchars($row['nomor_peminjaman_original']);
            } else {
                $nomor_peminjaman_original_display = '<span class="text-muted">-</span>';
            }
            
            $deleteIdentifier = $isDraftNoNomor ? 'DRAFT-ID-' . $row['min_id'] : $nomor_peminjaman_original;
            
            // Tombol aksi
            $aksi = '<div class="btn-group" role="group">';
            if ($status_peminjaman == 'Draft') {
                $editIdentifier = $isDraftNoNomor ? '' : htmlspecialchars($nomor_peminjaman_original);
                $editTanggal = '';
                if (isset($isValidDate) && $isValidDate) {
                    $editTanggal = substr($tanggal_peminjaman, 0, 10);
                }
                $aksi .= '<button type="button" class="btn btn-sm btn-info edit_btn" 
                            data-toggle="modal" 
                            data-target="#tambahModal"
                            data-id="' . ($isDraftNoNomor ? $row['min_id'] : $nomor_peminjaman_original) . '"
                            data-nomor_peminjaman="' . $editIdentifier . '"
                            data-tanggal="' . htmlspecialchars($editTanggal) . '"
                            data-entitas_peminjam="' . htmlspecialchars($row['entitas_peminjam']) . '"
                            data-entitas_dipinjam="' . htmlspecialchars($row['entitas_dipinjam']) . '"
                            data-gudang_asal="' . htmlspecialchars($row['gudang_asal']) . '"
                            data-gudang_tujuan="' . htmlspecialchars($row['gudang_tujuan']) . '"
                            data-status="' . htmlspecialchars($status_peminjaman) . '"
                            data-min-id="' . $row['min_id'] . '"
                            data-is-draft-no-nomor="' . ($isDraftNoNomor ? '1' : '0') . '"
                            title="Edit">
                            <i class="fas fa-edit"></i>
                          </button>';
            }
            
            if (($status_peminjaman == 'Final' || $status_peminjaman == 'Aktif') && !empty($nomor_peminjaman_original) && $nomor_peminjaman_original !== null) {
                $aksi .= '<button type="button" class="btn btn-sm btn-primary view_pdf_btn" 
                            data-nomor-peminjaman="' . htmlspecialchars($nomor_peminjaman_original, ENT_QUOTES) . '"
                            title="View Detail">
                            <i class="fas fa-eye"></i>
                          </button>';
            }
            
            $deleteDisabled = ''; // Tombol hapus selalu enabled
            // Gunakan fungsi yang sesuai berdasarkan type
            $deleteFunction = ($type === 'pengembalian') ? 'deletepengembalian' : 'deletepeminjaman';
            $deleteOnClick = 'onclick="' . $deleteFunction . '(\'' . htmlspecialchars($deleteIdentifier, ENT_QUOTES) . '\')"';
            $aksi .= '<button type="button" class="btn btn-sm btn-danger" 
                        ' . $deleteOnClick . '
                        title="Hapus">
                        <i class="fas fa-trash"></i>
                      </button>';
            $aksi .= '</div>';
            
            // Untuk pengembalian, tidak mengirim kolom gudang (10 kolom: Tanggal, Nomor Peminjaman, Nomor Pengembalian, Entitas Pengembali, Entitas Penerima, Jumlah Item, Stok Dipinjam, Total Qty, Status, Aksi)
            // Urutan entitas: Entitas Pengembali = entitas peminjam, Entitas Penerima = entitas dipinjam
            // Untuk peminjaman, mengirim kolom gudang (10 kolom)
            if ($type === 'pengembalian') {
                // Ambil stok dipinjam dari map berdasarkan nomor_peminjaman_original
                $stokDipinjam = 0;
                if (isset($row['nomor_peminjaman_original']) && !empty($row['nomor_peminjaman_original']) && isset($stokDipinjamMap[$row['nomor_peminjaman_original']])) {
                    $stokDipinjam = $stokDipinjamMap[$row['nomor_peminjaman_original']];
                }
                
                $data[] = [
                    $tanggal,
                    $nomor_peminjaman_original_display, // Nomor Peminjaman
                    $nomor_peminjaman_display, // Nomor Pengembalian
                    $row['entitas_peminjam'], // Entitas Pengembali (entitas peminjam ketika peminjaman)
                    $row['entitas_dipinjam'], // Entitas Penerima (entitas dipinjam ketika peminjaman)
                    $row['jumlah_item'],
                    number_format($stokDipinjam, 0, ',', '.'), // Stok Dipinjam
                    number_format($row['total_qty'], 0, ',', '.'), // Total Qty
                    $statusBadge,
                    $aksi
                ];
            } else {
                $data[] = [
                    $tanggal,
                    $nomor_peminjaman_display,
                    $row['entitas_dipinjam'],
                    $row['entitas_peminjam'],
                    $row['gudang_asal'],
                    $row['gudang_tujuan'],
                    $row['jumlah_item'],
                    number_format($row['total_qty'], 0, ',', '.'),
                    $statusBadge,
                    $aksi
                ];
            }
        }

        // Response untuk DataTables
        // Pastikan tidak ada output sebelum JSON
        while (ob_get_level() > 1) {
            ob_end_clean();
        }
        ob_clean();
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            "draw" => intval($draw),
            "recordsTotal" => $recordsTotal,
            "recordsFiltered" => $recordsFiltered,
            "data" => $data
        ], JSON_UNESCAPED_UNICODE);
        ob_end_flush();
        exit();
    } catch (Exception $e) {
        // Jika terjadi error, kirim response error dalam format JSON
        // Pastikan tidak ada output sebelum JSON
        while (ob_get_level() > 1) {
            ob_end_clean();
        }
        ob_clean();
        header('Content-Type: application/json; charset=utf-8');
        error_log("handleGetData Exception: " . $e->getMessage() . " | Trace: " . $e->getTraceAsString());
        echo json_encode([
            "draw" => isset($draw) ? intval($draw) : 1,
            "recordsTotal" => 0,
            "recordsFiltered" => 0,
            "data" => [],
            "error" => "Error: " . $e->getMessage()
        ], JSON_UNESCAPED_UNICODE);
        ob_end_flush();
        exit();
    }
}

// ... (fungsi-fungsi lainnya tetap sama seperti kode asli)
// Function untuk get detail peminjaman
function handleGetPeminjamanDetail() {
    global $db_dc;
    
    try {
        // Ambil type dari POST atau default ke peminjaman
        $type = isset($_POST['type']) ? trim($_POST['type']) : 'peminjaman';
        $table_name = ($type === 'pengembalian') ? 'pengembalian_stok' : 'peminjaman_stok';
        $tanggal_field = ($type === 'pengembalian') ? 'tanggal_pengembalian' : 'tanggal_peminjaman';
        $nomor_field = ($type === 'pengembalian') ? 'nomor_pengembalian' : 'nomor_peminjaman';
        $status_field = ($type === 'pengembalian') ? 'status_pengembalian' : 'status_peminjaman';
        
        if (isset($_POST['nomor_peminjaman']) || isset($_POST['min_id']) || isset($_POST['nomor_pengembalian'])) {
            $nomor_peminjaman = isset($_POST['nomor_peminjaman']) ? $_POST['nomor_peminjaman'] : '';
            $nomor_pengembalian = isset($_POST['nomor_pengembalian']) ? $_POST['nomor_pengembalian'] : '';
            $min_id = isset($_POST['min_id']) ? intval($_POST['min_id']) : 0;
            
            // Untuk pengembalian dengan nomor_pengembalian, gunakan sebagai nomor_field
            if ($type === 'pengembalian' && !empty($nomor_pengembalian) && empty($nomor_peminjaman)) {
                $nomor_peminjaman = $nomor_pengembalian; // Gunakan nomor_pengembalian sebagai identifier
            }
            
            if (empty($nomor_peminjaman) && $min_id > 0) {
                // Untuk pengembalian, ambil juga nomor_peminjaman untuk mengambil jumlah dipinjam
                $nomor_peminjaman_select = ($type === 'pengembalian') 
                    ? ", nomor_peminjaman" 
                    : "";
                $getRefQuery = "SELECT entitas_peminjam, gudang_asal, gudang_tujuan, $tanggal_field as tanggal_peminjaman, $status_field as status_peminjaman $nomor_peminjaman_select FROM $table_name WHERE id = ? LIMIT 1";
                $getRefStmt = mysqli_prepare($db_dc, $getRefQuery);
                if ($getRefStmt) {
                    mysqli_stmt_bind_param($getRefStmt, "i", $min_id);
                    mysqli_stmt_execute($getRefStmt);
                    $refResult = mysqli_stmt_get_result($getRefStmt);
                    $refRow = mysqli_fetch_assoc($refResult);
                    mysqli_stmt_close($getRefStmt);
                    
                    if ($refRow) {
                        $gudang_asal = mysqli_real_escape_string($db_dc, $refRow['gudang_asal']);
                        $gudang_tujuan = mysqli_real_escape_string($db_dc, $refRow['gudang_tujuan']);
                        $tanggal_peminjaman = $refRow['tanggal_peminjaman'];
                        $status_peminjaman = mysqli_real_escape_string($db_dc, $refRow['status_peminjaman']);
                        $nomorPeminjamanOriginal = ($type === 'pengembalian' && isset($refRow['nomor_peminjaman'])) ? $refRow['nomor_peminjaman'] : '';
                        
                        // Untuk pengembalian, ambil juga nomor_peminjaman dan gudang_asal
                        // Stok diambil terpisah dari omni_stok_akhir (tidak ada kolom stok di pengembalian_stok)
                        $nomor_peminjaman_select_query = ($type === 'pengembalian') 
                            ? ", nomor_peminjaman, gudang_asal" 
                            : "";
                        if ($tanggal_peminjaman === null || $tanggal_peminjaman === '') {
                            $query = "SELECT id, produk, qty, catatan $nomor_peminjaman_select_query FROM $table_name
                                      WHERE gudang_asal = ? AND gudang_tujuan = ? AND $tanggal_field IS NULL
                                      AND $status_field = ? AND ($nomor_field IS NULL OR $nomor_field = '' OR TRIM($nomor_field) = '')
                                      ORDER BY id ASC";
                            $stmt = mysqli_prepare($db_dc, $query);
                            if (!$stmt) {
                                throw new Exception("Error preparing statement: " . mysqli_error($db_dc));
                            }
                            mysqli_stmt_bind_param($stmt, "sss", $gudang_asal, $gudang_tujuan, $status_peminjaman);
                        } else {
                            $query = "SELECT id, produk, qty, catatan $nomor_peminjaman_select_query FROM $table_name
                                      WHERE gudang_asal = ? AND gudang_tujuan = ? AND $tanggal_field = ?
                                      AND $status_field = ? AND ($nomor_field IS NULL OR $nomor_field = '' OR TRIM($nomor_field) = '')
                                      ORDER BY id ASC";
                            $stmt = mysqli_prepare($db_dc, $query);
                            if (!$stmt) {
                                throw new Exception("Error preparing statement: " . mysqli_error($db_dc));
                            }
                            mysqli_stmt_bind_param($stmt, "ssss", $gudang_asal, $gudang_tujuan, $tanggal_peminjaman, $status_peminjaman);
                        }
                    } else {
                        throw new Exception("Data dengan ID $min_id tidak ditemukan");
                    }
                } else {
                    throw new Exception("Error preparing reference query: " . mysqli_error($db_dc));
                }
            } else {
                // Untuk pengembalian, ambil juga nomor_peminjaman dan gudang_asal
                // Stok diambil terpisah dari omni_stok_akhir (tidak ada kolom stok di pengembalian_stok)
                $nomor_peminjaman_select = ($type === 'pengembalian') 
                    ? ", nomor_peminjaman, gudang_asal" 
                    : "";
                $query = "SELECT id, produk, qty, catatan $nomor_peminjaman_select FROM $table_name WHERE $nomor_field = ? ORDER BY id ASC";
                $stmt = mysqli_prepare($db_dc, $query);
                if (!$stmt) {
                    throw new Exception("Error preparing statement: " . mysqli_error($db_dc));
                }
                mysqli_stmt_bind_param($stmt, "s", $nomor_peminjaman);
            }
            
            mysqli_stmt_execute($stmt);
            $result = mysqli_stmt_get_result($stmt);
            
            $data = [];
            $gudangAsal = null; // Untuk pengembalian, simpan gudang_asal untuk mengambil stok
            
            while ($row = mysqli_fetch_assoc($result)) {
                $itemData = [
                    'id' => $row['id'],
                    'produk' => $row['produk'],
                    'qty' => $row['qty'] ?? 0, // Default ke 0 jika NULL, untuk pengembalian akan mengambil dari pengembalian_stok saat edit
                    'catatan' => $row['catatan'] ?? ''
                ];
                
                // Untuk pengembalian, ambil jumlah dipinjam dari peminjaman_stok dan stok dari omni_stok_akhir
                if ($type === 'pengembalian') {
                    // Gunakan nomor_peminjaman dari row jika ada, jika tidak gunakan dari refRow (untuk draft)
                    $nomorPeminjamanOriginal = isset($row['nomor_peminjaman']) ? $row['nomor_peminjaman'] : (isset($nomorPeminjamanOriginal) ? $nomorPeminjamanOriginal : '');
                    $gudangAsalRow = isset($row['gudang_asal']) ? $row['gudang_asal'] : null;
                    $gudangAsal = $gudangAsalRow ? $gudangAsalRow : $gudangAsal;
                    
                    // Ambil jumlah dipinjam dari peminjaman_stok berdasarkan nomor_peminjaman dan produk
                    $jumlahDipinjam = 0;
                    if (!empty($nomorPeminjamanOriginal) && !empty($row['produk'])) {
                        $queryPeminjaman = "SELECT SUM(qty) as total_qty FROM peminjaman_stok 
                                           WHERE nomor_peminjaman = ? AND produk = ?";
                        $stmtPeminjaman = mysqli_prepare($db_dc, $queryPeminjaman);
                        if ($stmtPeminjaman) {
                            mysqli_stmt_bind_param($stmtPeminjaman, "ss", $nomorPeminjamanOriginal, $row['produk']);
                            mysqli_stmt_execute($stmtPeminjaman);
                            $resultPeminjaman = mysqli_stmt_get_result($stmtPeminjaman);
                            if ($rowPeminjaman = mysqli_fetch_assoc($resultPeminjaman)) {
                                $jumlahDipinjam = floatval($rowPeminjaman['total_qty'] ?? 0);
                            }
                            mysqli_stmt_close($stmtPeminjaman);
                        }
                    }
                    
                    // Ambil stok dari omni_stok_akhir kolom qty berdasarkan gudang_asal (gudang entitas pengembali) dan produk
                    $stok = 0;
                    if (!empty($gudangAsal) && !empty($row['produk'])) {
                        $gudangEscaped = mysqli_real_escape_string($db_dc, $gudangAsal);
                        $produkEscaped = mysqli_real_escape_string($db_dc, $row['produk']);
                        // Gunakan kolom qty dari omni_stok_akhir dengan JOIN ke gudang_omni untuk matching nama_gudang
                        $queryStok = "SELECT SUM(osa.qty) as stok 
                                     FROM omni_stok_akhir osa
                                     INNER JOIN gudang_omni go ON go.gudang COLLATE utf8mb4_unicode_ci = osa.gudang COLLATE utf8mb4_unicode_ci 
                                     AND go.tim COLLATE utf8mb4_unicode_ci = osa.tim COLLATE utf8mb4_unicode_ci
                                     WHERE osa.nama = ? AND go.nama_gudang COLLATE utf8mb4_unicode_ci = ? COLLATE utf8mb4_unicode_ci";
                        $stmtStok = mysqli_prepare($db_dc, $queryStok);
                        if ($stmtStok) {
                            mysqli_stmt_bind_param($stmtStok, "ss", $produkEscaped, $gudangEscaped);
                            mysqli_stmt_execute($stmtStok);
                            $resultStok = mysqli_stmt_get_result($stmtStok);
                            if ($resultStok && $rowStok = mysqli_fetch_assoc($resultStok)) {
                                $stok = floatval($rowStok['stok'] ?? 0);
                            }
                            mysqli_stmt_close($stmtStok);
                        }
                    }
                    
                    $itemData['jumlah_dipinjam'] = $jumlahDipinjam;
                    $itemData['stok'] = $stok;
                    // Tambahkan nomor_peminjaman ke response untuk pengembalian
                    if (!empty($nomorPeminjamanOriginal)) {
                        $itemData['nomor_peminjaman'] = $nomorPeminjamanOriginal;
                    }
                }
                
                $data[] = $itemData;
            }
            
            mysqli_stmt_close($stmt);
            
            // Debug logging
            error_log("get_peminjaman_detail - Type: $type, Data count: " . count($data));
            if (count($data) > 0 && $type === 'pengembalian') {
                error_log("get_peminjaman_detail - First item nomor_peminjaman: " . ($data[0]['nomor_peminjaman'] ?? 'NOT SET'));
            }
            
            echo json_encode($data);
        } else {
            error_log("get_peminjaman_detail - Error: Nomor peminjaman atau ID tidak ditemukan. POST data: " . json_encode($_POST));
            echo json_encode(['error' => 'Nomor peminjaman atau ID tidak ditemukan']);
        }
    } catch (Exception $e) {
        echo json_encode(['error' => 'Error: ' . $e->getMessage()]);
    }
}

// Function untuk get stok by produk (sama seperti mutasi)
function handleGetStokByProduk() {
    global $db_dc;
    
    header('Content-Type: application/json');
    
    if (isset($_POST['produk']) && isset($_POST['gudang'])) {
        $produk = $_POST['produk'];
        $gudang = $_POST['gudang'];
        
        $query = "SELECT SUM(osa.qty) as stok 
                  FROM omni_stok_akhir osa
                  INNER JOIN gudang_omni go ON go.gudang COLLATE utf8mb4_unicode_ci = osa.gudang COLLATE utf8mb4_unicode_ci 
                  AND go.tim COLLATE utf8mb4_unicode_ci = osa.tim COLLATE utf8mb4_unicode_ci
                  WHERE osa.nama = ? AND go.nama_gudang COLLATE utf8mb4_unicode_ci = ? COLLATE utf8mb4_unicode_ci";
        
        $stmt = mysqli_prepare($db_dc, $query);
        if ($stmt) {
            mysqli_stmt_bind_param($stmt, "ss", $produk, $gudang);
            mysqli_stmt_execute($stmt);
            $result = mysqli_stmt_get_result($stmt);
            
            $stok = 0;
            if ($row = mysqli_fetch_assoc($result)) {
                $stok = intval($row['stok'] ?? 0);
            }
            
            mysqli_stmt_close($stmt);
            
            echo json_encode([
                'status' => 'success',
                'stok' => $stok
            ]);
        } else {
            echo json_encode([
                'status' => 'error',
                'message' => 'Gagal mengambil data stok: ' . mysqli_error($db_dc),
                'stok' => 0
            ]);
        }
    } else {
        echo json_encode([
            'status' => 'error',
            'message' => 'Parameter tidak lengkap',
            'stok' => 0
        ]);
    }
}

// Function untuk get entitas code (sama seperti mutasi)
function handleGetEntitasCode() {
    global $db_dc;
    
    ob_start();
    error_reporting(E_ALL);
    ini_set('display_errors', 0);
    header('Content-Type: application/json');
    
    try {
        if (isset($_POST['gudang'])) {
            $gudang = $_POST['gudang'];
            
            $query = "SELECT bt.kode_tim, be.inisial as entitas
                      FROM gudang_omni go
                      INNER JOIN base_tim bt ON bt.tim COLLATE utf8mb4_unicode_ci = go.tim COLLATE utf8mb4_unicode_ci
                      INNER JOIN base_entitas be ON be.id = bt.id_entitas
                      WHERE go.nama_gudang COLLATE utf8mb4_unicode_ci = ? COLLATE utf8mb4_unicode_ci
                      LIMIT 1";
            
            $stmt = mysqli_prepare($db_dc, $query);
            if ($stmt) {
                mysqli_stmt_bind_param($stmt, "s", $gudang);
                if (mysqli_stmt_execute($stmt)) {
                    $result = mysqli_stmt_get_result($stmt);
                    
                    $code_entitas = '';
                    $entitas = '';
                    if ($row = mysqli_fetch_assoc($result)) {
                        $code_entitas = isset($row['kode_tim']) ? $row['kode_tim'] : '';
                        $entitas = isset($row['entitas']) ? $row['entitas'] : '';
                    }
                    mysqli_stmt_close($stmt);
                    
                    if (empty($code_entitas)) {
                        $code_entitas = strtoupper(substr($gudang, 0, 3));
                    }
                    
                    ob_clean();
                    echo json_encode([
                        'code_entitas' => $code_entitas,
                        'entitas' => $entitas
                    ]);
                    ob_end_flush();
                    exit;
                }
            }
        }
        
        ob_clean();
        echo json_encode(['error' => 'Parameter tidak ditemukan']);
        ob_end_flush();
        exit;
    } catch (Exception $e) {
        ob_clean();
        echo json_encode([
            'error' => 'Error: ' . $e->getMessage()
        ]);
        ob_end_flush();
        exit;
    }
}

// Function untuk get gudang by entitas (sama seperti mutasi)
function handleGetGudangByEntitas() {
    global $db_dc;
    
    header('Content-Type: application/json');
    
    if (isset($_POST['entitas'])) {
        $entitas = $_POST['entitas'];
        
        $entitasArray = is_array($entitas) ? $entitas : [$entitas];
        $entitasArray = array_filter(array_map('trim', $entitasArray));
        
        if (empty($entitasArray)) {
            echo json_encode(['gudang' => []]);
            return;
        }
        
        $placeholders = str_repeat('?,', count($entitasArray) - 1) . '?';
        $query = "SELECT DISTINCT go.nama_gudang 
                  FROM gudang_omni go
                  LEFT JOIN base_tim bt ON bt.tim = go.tim
                  LEFT JOIN base_entitas be ON be.id = bt.id_entitas
                  WHERE be.inisial IN ($placeholders)
                  ORDER BY go.nama_gudang ASC";
        
        $stmt = mysqli_prepare($db_dc, $query);
        if (!$stmt) {
            echo json_encode(['error' => 'Error preparing query: ' . mysqli_error($db_dc)]);
            return;
        }
        
        $types = str_repeat('s', count($entitasArray));
        mysqli_stmt_bind_param($stmt, $types, ...$entitasArray);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        
        $gudang = [];
        while ($row = mysqli_fetch_assoc($result)) {
            $gudang[] = [
                'nama_gudang' => $row['nama_gudang']
            ];
        }
        
        mysqli_stmt_close($stmt);
        echo json_encode(['gudang' => $gudang]);
    } else {
        echo json_encode(['error' => 'Entitas tidak ditemukan', 'gudang' => []]);
    }
}

/**
 * Function untuk get list nomor peminjaman yang bisa dikembalikan
 * Mengambil nomor peminjaman yang statusnya Final (bukan Draft)
 */
function handleGetListNomorPeminjaman() {
    global $db_dc;
    
    // Pastikan output buffer bersih
    while (ob_get_level()) {
        ob_end_clean();
    }
    
    header('Content-Type: application/json');
    
    try {
        // Validasi koneksi database
        if (!isset($db_dc) || !$db_dc) {
            throw new Exception("Database connection not available");
        }
        
        if (!mysqli_ping($db_dc)) {
            throw new Exception("Database connection lost");
        }
        
        // Query untuk mendapatkan list nomor peminjaman yang statusnya Final
        // Hanya ambil yang memiliki nomor_peminjaman (bukan Draft tanpa nomor)
        $query = "SELECT DISTINCT 
                    m.nomor_peminjaman,
                    m.tanggal_peminjaman,
                    m.entitas_peminjam,
                    m.entitas_dipinjam,
                    m.gudang_asal,
                    m.gudang_tujuan
                  FROM peminjaman_stok m
                  WHERE m.nomor_peminjaman IS NOT NULL 
                    AND m.nomor_peminjaman != '' 
                    AND TRIM(m.nomor_peminjaman) != ''
                    AND m.status_peminjaman = 'Final'
                  ORDER BY m.tanggal_peminjaman DESC, m.nomor_peminjaman DESC
                  LIMIT 1000";
        
        error_log("get_list_nomor_peminjaman - Query: " . $query);
        
        $result = mysqli_query($db_dc, $query);
        
        if (!$result) {
            $error = mysqli_error($db_dc);
            error_log("get_list_nomor_peminjaman - Query error: " . $error);
            throw new Exception("Query error: " . $error);
        }
        
        $list = [];
        $count = 0;
        while ($row = mysqli_fetch_assoc($result)) {
            $nomorPeminjaman = $row['nomor_peminjaman'] ?? '';
            
            // Cek apakah nomor peminjaman sudah selesai dikembalikan
            // Ambil total qty per produk dari peminjaman_stok
            $queryPeminjaman = "SELECT 
                                produk,
                                SUM(qty) as total_qty_dipinjam
                              FROM peminjaman_stok
                              WHERE nomor_peminjaman = ?
                              GROUP BY produk";
            
            $stmtPeminjaman = mysqli_prepare($db_dc, $queryPeminjaman);
            if ($stmtPeminjaman) {
                mysqli_stmt_bind_param($stmtPeminjaman, "s", $nomorPeminjaman);
                mysqli_stmt_execute($stmtPeminjaman);
                $resultPeminjaman = mysqli_stmt_get_result($stmtPeminjaman);
                
                $qtyDipinjamPerProduk = [];
                while ($rowPeminjaman = mysqli_fetch_assoc($resultPeminjaman)) {
                    $produk = $rowPeminjaman['produk'];
                    $qtyDipinjam = floatval($rowPeminjaman['total_qty_dipinjam']);
                    if ($qtyDipinjam > 0) {
                        $qtyDipinjamPerProduk[$produk] = $qtyDipinjam;
                    }
                }
                mysqli_stmt_close($stmtPeminjaman);
                
                // Ambil total qty per produk dari pengembalian_stok
                $queryPengembalian = "SELECT 
                                    produk,
                                    SUM(qty) as total_qty_dikembalikan
                                  FROM pengembalian_stok
                                  WHERE nomor_peminjaman = ?
                                  GROUP BY produk";
                
                $stmtPengembalian = mysqli_prepare($db_dc, $queryPengembalian);
                $qtyDikembalikanPerProduk = [];
                if ($stmtPengembalian) {
                    mysqli_stmt_bind_param($stmtPengembalian, "s", $nomorPeminjaman);
                    mysqli_stmt_execute($stmtPengembalian);
                    $resultPengembalian = mysqli_stmt_get_result($stmtPengembalian);
                    
                    while ($rowPengembalian = mysqli_fetch_assoc($resultPengembalian)) {
                        $produk = $rowPengembalian['produk'];
                        $qtyDikembalikan = floatval($rowPengembalian['total_qty_dikembalikan']);
                        if ($qtyDikembalikan > 0) {
                            $qtyDikembalikanPerProduk[$produk] = $qtyDikembalikan;
                        }
                    }
                    mysqli_stmt_close($stmtPengembalian);
                }
                
                // Cek apakah semua produk sudah selesai dikembalikan
                $isSelesai = true;
                if (empty($qtyDipinjamPerProduk)) {
                    // Jika tidak ada data peminjaman, skip
                    continue;
                }
                
                foreach ($qtyDipinjamPerProduk as $produk => $qtyDipinjam) {
                    $qtyDikembalikan = isset($qtyDikembalikanPerProduk[$produk]) ? floatval($qtyDikembalikanPerProduk[$produk]) : 0;
                    
                    // Gunakan toleransi untuk perbandingan floating point
                    if (abs($qtyDikembalikan - $qtyDipinjam) >= 0.01) {
                        $isSelesai = false;
                        break;
                    }
                }
                
                // Hanya tambahkan ke list jika belum selesai dikembalikan
                if (!$isSelesai) {
                    $list[] = [
                        'nomor_peminjaman' => $nomorPeminjaman,
                        'tanggal_peminjaman' => $row['tanggal_peminjaman'] ?? '',
                        'entitas_peminjam' => $row['entitas_peminjam'] ?? '',
                        'entitas_dipinjam' => $row['entitas_dipinjam'] ?? '',
                        'gudang_asal' => $row['gudang_asal'] ?? '',
                        'gudang_tujuan' => $row['gudang_tujuan'] ?? ''
                    ];
                    $count++;
                }
            }
        }
        
        error_log("get_list_nomor_peminjaman - Found " . $count . " records");
        
        echo json_encode([
            'success' => true, 
            'data' => $list,
            'count' => $count
        ], JSON_UNESCAPED_UNICODE);
        
        exit;
    } catch (Exception $e) {
        error_log("get_list_nomor_peminjaman - Exception: " . $e->getMessage());
        while (ob_get_level()) {
            ob_end_clean();
        }
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false,
            'error' => 'Error: ' . $e->getMessage(),
            'data' => []
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

/**
 * Function untuk get sisa qty yang belum dikembalikan per produk untuk nomor peminjaman tertentu
 */
function handleGetSisaQtyPengembalian() {
    global $db_dc;
    
    try {
        while (ob_get_level()) {
            ob_end_clean();
        }
        header('Content-Type: application/json; charset=utf-8');
        
        $nomor_peminjaman = isset($_POST['nomor_peminjaman']) ? trim($_POST['nomor_peminjaman']) : '';
        
        if (empty($nomor_peminjaman)) {
            echo json_encode([
                'success' => false,
                'error' => 'Nomor peminjaman tidak boleh kosong',
                'data' => []
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }
        
        // Ambil total qty per produk dari peminjaman_stok
        $queryPeminjaman = "SELECT 
                            produk,
                            SUM(qty) as total_qty_dipinjam
                          FROM peminjaman_stok
                          WHERE nomor_peminjaman = ?
                          GROUP BY produk";
        
        $stmtPeminjaman = mysqli_prepare($db_dc, $queryPeminjaman);
        if (!$stmtPeminjaman) {
            throw new Exception("Error preparing query: " . mysqli_error($db_dc));
        }
        
        mysqli_stmt_bind_param($stmtPeminjaman, "s", $nomor_peminjaman);
        mysqli_stmt_execute($stmtPeminjaman);
        $resultPeminjaman = mysqli_stmt_get_result($stmtPeminjaman);
        
        $qtyDipinjamPerProduk = [];
        while ($rowPeminjaman = mysqli_fetch_assoc($resultPeminjaman)) {
            $produk = $rowPeminjaman['produk'];
            $qtyDipinjam = floatval($rowPeminjaman['total_qty_dipinjam']);
            if ($qtyDipinjam > 0) {
                $qtyDipinjamPerProduk[$produk] = $qtyDipinjam;
            }
        }
        mysqli_stmt_close($stmtPeminjaman);
        
        // Ambil total qty per produk dari pengembalian_stok (yang sudah Final)
        $queryPengembalian = "SELECT 
                                produk,
                                SUM(qty) as total_qty_dikembalikan
                              FROM pengembalian_stok
                              WHERE nomor_peminjaman = ?
                                AND status_pengembalian = 'Final'
                              GROUP BY produk";
        
        $stmtPengembalian = mysqli_prepare($db_dc, $queryPengembalian);
        $qtyDikembalikanPerProduk = [];
        if ($stmtPengembalian) {
            mysqli_stmt_bind_param($stmtPengembalian, "s", $nomor_peminjaman);
            mysqli_stmt_execute($stmtPengembalian);
            $resultPengembalian = mysqli_stmt_get_result($stmtPengembalian);
            
            while ($rowPengembalian = mysqli_fetch_assoc($resultPengembalian)) {
                $produk = $rowPengembalian['produk'];
                $qtyDikembalikan = floatval($rowPengembalian['total_qty_dikembalikan']);
                if ($qtyDikembalikan > 0) {
                    $qtyDikembalikanPerProduk[$produk] = $qtyDikembalikan;
                }
            }
            mysqli_stmt_close($stmtPengembalian);
        }
        
        // Hitung sisa qty per produk
        $sisaQtyPerProduk = [];
        foreach ($qtyDipinjamPerProduk as $produk => $qtyDipinjam) {
            $qtyDikembalikan = isset($qtyDikembalikanPerProduk[$produk]) ? floatval($qtyDikembalikanPerProduk[$produk]) : 0;
            $sisaQty = $qtyDipinjam - $qtyDikembalikan;
            if ($sisaQty > 0) {
                $sisaQtyPerProduk[$produk] = $sisaQty;
            } else {
                $sisaQtyPerProduk[$produk] = 0;
            }
        }
        
        echo json_encode([
            'success' => true,
            'data' => $sisaQtyPerProduk
        ], JSON_UNESCAPED_UNICODE);
        exit;
        
    } catch (Exception $e) {
        error_log("get_sisa_qty_pengembalian - Exception: " . $e->getMessage());
        while (ob_get_level()) {
            ob_end_clean();
        }
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false,
            'error' => 'Error: ' . $e->getMessage(),
            'data' => []
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

/**
 * Function untuk get peminjaman details (HTML output)
 * Menampilkan HTML detail peminjaman dengan catatan penyesuaian
 */
function handleGetPeminjamanDetails() {
    global $db_dc;
    
    ob_clean();
    header('Content-Type: text/html; charset=utf-8');
    
    try {
        if (!isset($_POST['nomor_peminjaman'])) {
            throw new Exception('Nomor peminjaman tidak ditemukan');
        }
        
        // Ambil type dari POST atau default ke peminjaman
        $type = isset($_POST['type']) ? trim($_POST['type']) : 'peminjaman';
        $table_name = ($type === 'pengembalian') ? 'pengembalian_stok' : 'peminjaman_stok';
        $tanggal_field = ($type === 'pengembalian') ? 'tanggal_pengembalian' : 'tanggal_peminjaman';
        $nomor_field = ($type === 'pengembalian') ? 'nomor_pengembalian' : 'nomor_peminjaman';
        $status_field = ($type === 'pengembalian') ? 'status_pengembalian' : 'status_peminjaman';
        
        $nomor_peminjaman = mysqli_real_escape_string($db_dc, $_POST['nomor_peminjaman']);
        
        // Query untuk mengambil data peminjaman/pengembalian dengan catatan
        // Untuk pengembalian, ambil juga nomor_peminjaman (kolom yang menyimpan nomor peminjaman asli)
        $nomor_peminjaman_original_select = ($type === 'pengembalian') 
            ? ", m.nomor_peminjaman as nomor_peminjaman_original" 
            : "";
        
        $query = "SELECT 
                        m.$nomor_field as nomor_peminjaman,
                        m.$tanggal_field as tanggal_peminjaman,
                        m.entitas_peminjam,
                        m.entitas_dipinjam,
                        m.gudang_asal,
                        m.gudang_tujuan,
                        m.produk,
                        m.qty,
                        m.catatan,
                        m.$status_field as status_peminjaman,
                        m.id
                        $nomor_peminjaman_original_select
                      FROM $table_name m
                      WHERE m.$nomor_field = ?
                      ORDER BY 
                        CASE WHEN m.catatan IS NULL OR m.catatan = '' OR m.catatan NOT LIKE '%Penyesuaian%' THEN 0 ELSE 1 END,
                        m.id ASC";
        
        $stmt = mysqli_prepare($db_dc, $query);
        if (!$stmt) {
            throw new Exception("Error preparing query: " . mysqli_error($db_dc));
        }
        
        mysqli_stmt_bind_param($stmt, "s", $nomor_peminjaman);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        
        $headerData = null;
        $peminjamanData = [];
        $totalQty = 0;
        
        while ($row = mysqli_fetch_assoc($result)) {
            if ($headerData === null) {
                $headerData = [
                    'nomor_peminjaman' => $row['nomor_peminjaman'],
                    'tanggal_peminjaman' => $row['tanggal_peminjaman'],
                    'entitas_peminjam' => $row['entitas_peminjam'],
                    'entitas_dipinjam' => $row['entitas_dipinjam'],
                    'gudang_asal' => $row['gudang_asal'],
                    'gudang_tujuan' => $row['gudang_tujuan'],
                    'status_peminjaman' => $row['status_peminjaman']
                ];
                // Untuk pengembalian, simpan nomor_peminjaman_original
                if ($type === 'pengembalian' && isset($row['nomor_peminjaman_original'])) {
                    $headerData['nomor_peminjaman_original'] = $row['nomor_peminjaman_original'];
                }
            }
            
            // Include semua data, termasuk penyesuaian
            $isPenyesuaian = !empty($row['catatan']) && stripos($row['catatan'], 'Penyesuaian') !== false;
            
            $qtyValue = intval($row['qty']);
            if (!empty($row['produk']) && $qtyValue != 0) {
                $productData = [
                    'id' => $row['id'],
                    'produk' => $row['produk'],
                    'qty' => $qtyValue,
                    'catatan' => $row['catatan'] ?? '',
                    'is_penyesuaian' => $isPenyesuaian
                ];
                
                $peminjamanData[] = $productData;
                if ($qtyValue > 0) {
                    $totalQty += $qtyValue;
                }
            }
        }
        
        mysqli_stmt_close($stmt);
        
        if ($headerData === null) {
            throw new Exception('Data peminjaman tidak ditemukan');
        }
        
        // Hitung jumlah terkirim dan diterima per produk dari log_stok (hanya untuk Final, bukan Draft)
        // Untuk pengembalian, jumlah dikembalikan diambil dari grouping total qty di tabel pengembalian_stok
        $gudangTujuan = $headerData['gudang_tujuan'] ?? '';
        $gudangAsal = $headerData['gudang_asal'] ?? '';
        $nomor_peminjaman = $headerData['nomor_peminjaman'] ?? '';
        $jumlahTerkirimPerProduk = [];
        $jumlahDiterimaPerProduk = []; // Untuk status (dari log_stok)
        $jumlahDikembalikanPerProduk = []; // Untuk kolom Jml. Dikembalikan (dari pengembalian_stok)
        $overallStatus = 'final';
        
        // Ambil jumlah dikembalikan dari grouping total qty di tabel pengembalian_stok
        // Untuk pengembalian: berdasarkan nomor_pengembalian
        // Untuk peminjaman: berdasarkan nomor_peminjaman (mencari di kolom nomor_peminjaman di tabel pengembalian_stok)
        if (!empty($nomor_peminjaman)) {
            if ($type === 'pengembalian') {
                $nomorPengembalianEscaped = mysqli_real_escape_string($db_dc, $nomor_peminjaman);
                $queryJumlahDikembalikan = "SELECT 
                                produk,
                                SUM(qty) as jumlah_dikembalikan
                             FROM pengembalian_stok
                             WHERE nomor_pengembalian = '{$nomorPengembalianEscaped}'
                             GROUP BY produk";
            } else {
                // Untuk peminjaman, ambil dari pengembalian_stok berdasarkan nomor_peminjaman
                $nomorPeminjamanEscaped = mysqli_real_escape_string($db_dc, $nomor_peminjaman);
                $queryJumlahDikembalikan = "SELECT 
                                produk,
                                SUM(qty) as jumlah_dikembalikan
                             FROM pengembalian_stok
                             WHERE nomor_peminjaman = '{$nomorPeminjamanEscaped}'
                             GROUP BY produk";
            }
            
            $resultJumlahDikembalikan = mysqli_query($db_dc, $queryJumlahDikembalikan);
            if ($resultJumlahDikembalikan) {
                while ($rowJml = mysqli_fetch_assoc($resultJumlahDikembalikan)) {
                    $produk = $rowJml['produk'];
                    $jumlahDikembalikan = floatval($rowJml['jumlah_dikembalikan']);
                    if ($jumlahDikembalikan > 0) {
                        $jumlahDikembalikanPerProduk[$produk] = $jumlahDikembalikan;
                        // Untuk pengembalian, jumlah dikembalikan juga digunakan untuk status
                        if ($type === 'pengembalian') {
                            $jumlahDiterimaPerProduk[$produk] = $jumlahDikembalikan;
                        }
                    }
                }
            }
        }
        
        // Tentukan kategori log_stok berdasarkan type
        $log_stok_kategori = ($type === 'pengembalian') ? 'Pengembalian' : 'Peminjaman';
        $log_stok_prefix = ($type === 'pengembalian') ? 'PB ' : 'PJ ';
        
        // Untuk peminjaman, ambil jumlah terkirim dan diterima dari log_stok (untuk status, bukan untuk jml dikembalikan)
        // Untuk pengembalian, jumlah dikembalikan sudah diambil di atas
        if ($type !== 'pengembalian' && !empty($gudangTujuan) && !empty($gudangAsal) && !empty($nomor_peminjaman) && $headerData['status_peminjaman'] !== 'Draft') {
            $gudangTujuanEscaped = mysqli_real_escape_string($db_dc, $gudangTujuan);
            $gudangAsalEscaped = mysqli_real_escape_string($db_dc, $gudangAsal);
            $nomorPeminjamanEscaped = mysqli_real_escape_string($db_dc, $nomor_peminjaman);
            
            // Query untuk jumlah terkirim (dari gudang asal/peminjam, nama_file mengandung nomor_peminjaman)
            $pmPrefix = mysqli_real_escape_string($db_dc, $log_stok_prefix . $nomor_peminjaman);
            $queryLogTerkirim = "SELECT 
                            ls.varian as produk,
                            SUM(ls.jumlah) as jumlah_terkirim
                         FROM log_stok ls
                         WHERE ls.kategori = '{$log_stok_kategori}'
                         AND ls.nama_gudang COLLATE utf8mb4_unicode_ci = '{$gudangAsalEscaped}' COLLATE utf8mb4_unicode_ci
                         AND (
                             ls.nama_file COLLATE utf8mb4_unicode_ci = '{$nomorPeminjamanEscaped}' COLLATE utf8mb4_unicode_ci
                             OR ls.nama_file COLLATE utf8mb4_unicode_ci = '{$pmPrefix}' COLLATE utf8mb4_unicode_ci
                             OR ls.nama_file COLLATE utf8mb4_unicode_ci LIKE CONCAT('{$log_stok_prefix}', '{$nomorPeminjamanEscaped}', '%') COLLATE utf8mb4_unicode_ci
                         )
                         GROUP BY ls.varian";
            
            // Query untuk jumlah diterima (di gudang tujuan/dipinjam, nama_file mengandung nomor_peminjaman)
            $queryLogDiterima = "SELECT 
                            ls.varian as produk,
                            SUM(ls.jumlah) as jumlah_diterima
                         FROM log_stok ls
                         WHERE ls.kategori = '{$log_stok_kategori}'
                         AND ls.nama_gudang COLLATE utf8mb4_unicode_ci = '{$gudangTujuanEscaped}' COLLATE utf8mb4_unicode_ci
                         AND ls.jumlah > 0
                         AND (
                             ls.nama_file COLLATE utf8mb4_unicode_ci = '{$nomorPeminjamanEscaped}' COLLATE utf8mb4_unicode_ci
                             OR ls.nama_file COLLATE utf8mb4_unicode_ci = '{$pmPrefix}' COLLATE utf8mb4_unicode_ci
                             OR ls.nama_file COLLATE utf8mb4_unicode_ci LIKE CONCAT('{$log_stok_prefix}', '{$nomorPeminjamanEscaped}', '%') COLLATE utf8mb4_unicode_ci
                         )
                         GROUP BY ls.varian";
            
            $resultLogTerkirim = mysqli_query($db_dc, $queryLogTerkirim);
            $resultLogDiterima = mysqli_query($db_dc, $queryLogDiterima);
            
            if ($resultLogTerkirim) {
                while ($rowLog = mysqli_fetch_assoc($resultLogTerkirim)) {
                    $produk = $rowLog['produk'];
                    $jumlahTerkirimRaw = floatval($rowLog['jumlah_terkirim']);
                    $jumlahTerkirimPerProduk[$produk] = $jumlahTerkirimRaw;
                }
            } else {
                error_log("Query Jml Terkirim error: " . mysqli_error($db_dc) . " | Query: " . substr($queryLogTerkirim, 0, 500));
            }
            
            if ($resultLogDiterima) {
                while ($rowLog = mysqli_fetch_assoc($resultLogDiterima)) {
                    $produk = $rowLog['produk'];
                    $jumlahDiterima = floatval($rowLog['jumlah_diterima']);
                    if ($jumlahDiterima > 0) {
                        $jumlahDiterimaPerProduk[$produk] = $jumlahDiterima;
                    }
                }
            }
        }
        
        // Hitung status keseluruhan
        $status_peminjaman = $headerData['status_peminjaman'] ?? '';
        if ($status_peminjaman == 'Draft') {
            $overallStatus = 'draft';
        } else if (!empty($peminjamanData)) {
            $peminjamanProductsMap = [];
            foreach ($peminjamanData as $product) {
                $produk = $product['produk'];
                if (!isset($peminjamanProductsMap[$produk])) {
                    $peminjamanProductsMap[$produk] = 0;
                }
                $peminjamanProductsMap[$produk] += $product['qty'];
            }
            
            // Untuk pengembalian, bandingkan jumlah dipinjam dengan jumlah dikembalikan
            if ($type === 'pengembalian') {
                // Ambil jumlah dipinjam dari peminjaman_stok berdasarkan nomor_peminjaman_original
                $nomorPeminjamanOriginal = isset($headerData['nomor_peminjaman_original']) ? $headerData['nomor_peminjaman_original'] : null;
                
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
                            $jumlahDikembalikan = isset($jumlahDiterimaPerProduk[$produk]) ? floatval($jumlahDiterimaPerProduk[$produk]) : 0;
                            $qtyDipinjamFloat = floatval($qtyDipinjam);
                            
                            if (abs($jumlahDikembalikan - $qtyDipinjamFloat) >= 0.01) {
                                $allMatch = false;
                                break;
                            }
                        }
                        
                        if ($hasAnyData && $allMatch) {
                            $overallStatus = 'selesai';
                        } else {
                            $overallStatus = 'belum_selesai';
                        }
                    } else {
                        $overallStatus = 'final';
                    }
                } else {
                    $overallStatus = 'final';
                }
            } else {
                // Untuk peminjaman, status mengikuti yang di table: membandingkan jumlah dipinjam dengan jumlah dikembalikan
                if (!empty($jumlahDikembalikanPerProduk)) {
                    $allMatch = true;
                    $hasAnyData = false;
                    
                    foreach ($peminjamanProductsMap as $produk => $qty) {
                        $hasAnyData = true;
                        $jumlahDikembalikan = isset($jumlahDikembalikanPerProduk[$produk]) ? floatval($jumlahDikembalikanPerProduk[$produk]) : null;
                        $qtyFloat = floatval($qty);
                        
                        if ($jumlahDikembalikan === null || abs($jumlahDikembalikan - $qtyFloat) >= 0.01) {
                            $allMatch = false;
                            break;
                        }
                    }
                    
                    if ($hasAnyData && $allMatch) {
                        $overallStatus = 'selesai';
                    } else {
                        $overallStatus = 'belum_selesai';
                    }
                } else {
                    // Tidak ada data dikembalikan → Final (sama seperti di table)
                    $overallStatus = 'final';
                }
            }
        }
        
        // Grouping produk
        $groupedProducts = [];
        foreach ($peminjamanData as $product) {
            $key = $product['produk'];
            $jumlahTerkirim = isset($jumlahTerkirimPerProduk[$product['produk']]) ? $jumlahTerkirimPerProduk[$product['produk']] : null;
            // Untuk kolom Jml. Dikembalikan, gunakan data dari pengembalian_stok
            $jumlahDiterima = isset($jumlahDikembalikanPerProduk[$product['produk']]) ? $jumlahDikembalikanPerProduk[$product['produk']] : null;
            
            if (!isset($groupedProducts[$key])) {
                $groupedProducts[$key] = [
                    'produk' => $product['produk'],
                    'gudang' => [$headerData['gudang_asal'] ?? ''],
                    'qty' => $product['qty'],
                    'jumlah_terkirim' => $jumlahTerkirim,
                    'jumlah_diterima' => $jumlahDiterima,
                    'has_penyesuaian' => $product['is_penyesuaian'] ?? false
                ];
            } else {
                if (!in_array($headerData['gudang_asal'] ?? '', $groupedProducts[$key]['gudang'])) {
                    $groupedProducts[$key]['gudang'][] = $headerData['gudang_asal'] ?? '';
                }
                $groupedProducts[$key]['qty'] += $product['qty'];
                if ($jumlahTerkirim !== null) {
                    $groupedProducts[$key]['jumlah_terkirim'] = $jumlahTerkirim;
                }
                if ($jumlahDiterima !== null) {
                    $groupedProducts[$key]['jumlah_diterima'] = $jumlahDiterima;
                }
                if ($product['is_penyesuaian'] ?? false) {
                    $groupedProducts[$key]['has_penyesuaian'] = true;
                }
            }
        }
        
        foreach ($groupedProducts as &$product) {
            if (is_array($product['gudang'])) {
                $product['gudang'] = implode(', ', $product['gudang']);
            }
        }
        unset($product);
        
        $allProducts = array_values($groupedProducts);
        
        // Query untuk mengambil data company identity dari base_entitas berdasarkan entitas_peminjam
        $companyData = null;
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
        
        // Format tanggal
        $tanggalFormatted = $headerData['tanggal_peminjaman'] ? date('d/m/Y', strtotime($headerData['tanggal_peminjaman'])) : '-';
        $nomor_peminjaman_display = $headerData['nomor_peminjaman'] ?: '-';
        $nomor_peminjaman_original_display = ($type === 'pengembalian' && isset($headerData['nomor_peminjaman_original'])) 
            ? $headerData['nomor_peminjaman_original'] 
            : '-';
        
        // Mulai output HTML
        ?>
<div class="row mb-4">
    <div class="col-md-6">
        <table class="table border-0" border="0">
            <?php if ($type === 'pengembalian'): ?>
            <tr class="text-left">
                <th width="40%">Tanggal Pengembalian</th>
                <td width="5%" class="text-center">:</td>
                <td><?= htmlspecialchars($tanggalFormatted) ?></td>
            </tr>
            <tr class="text-left">
                <th width="40%">Nomor Peminjaman</th>
                <td width="5%" class="text-center">:</td>
                <td><?= htmlspecialchars($nomor_peminjaman_original_display) ?></td>
            </tr>
            <tr class="text-left">
                <th width="40%">Nomor Pengembalian</th>
                <td width="5%" class="text-center">:</td>
                <td><?= htmlspecialchars($nomor_peminjaman_display) ?></td>
            </tr>
            <tr class="text-left">
                <th width="40%">Entitas Pengembali</th>
                <td width="5%" class="text-center">:</td>
                <td><?= htmlspecialchars($headerData['entitas_peminjam'] ?? '-') ?></td>
            </tr>
            <tr class="text-left">
                <th width="40%">Entitas Penerima</th>
                <td width="5%" class="text-center">:</td>
                <td><?= htmlspecialchars($headerData['entitas_dipinjam'] ?? '-') ?></td>
            </tr>
            <tr class="text-left">
                <th width="40%">Gudang Pengembali</th>
                <td width="5%" class="text-center">:</td>
                <td><?= htmlspecialchars($headerData['gudang_asal']) ?></td>
            </tr>
            <tr class="text-left">
                <th width="40%">Gudang Penerima</th>
                <td width="5%" class="text-center">:</td>
                <td><?= htmlspecialchars($headerData['gudang_tujuan']) ?></td>
            </tr>
            <?php else: ?>
            <tr class="text-left">
                <th width="40%">Tanggal Peminjaman</th>
                <td width="5%" class="text-center">:</td>
                <td><?= htmlspecialchars($tanggalFormatted) ?></td>
            </tr>
            <tr class="text-left">
                <th width="40%">Nomor Peminjaman</th>
                <td width="5%" class="text-center">:</td>
                <td><?= htmlspecialchars($nomor_peminjaman_display) ?></td>
            </tr>
            <tr class="text-left">
                <th width="40%">Entitas Peminjam</th>
                <td width="5%" class="text-center">:</td>
                <td><?= htmlspecialchars($headerData['entitas_peminjam'] ?? '-') ?></td>
            </tr>
            <tr class="text-left">
                <th width="40%">Entitas Dipinjam</th>
                <td width="5%" class="text-center">:</td>
                <td><?= htmlspecialchars($headerData['entitas_dipinjam'] ?? '-') ?></td>
            </tr>
            <tr class="text-left">
                <th width="40%">Gudang Peminjam</th>
                <td width="5%" class="text-center">:</td>
                <td><?= htmlspecialchars($headerData['gudang_asal']) ?></td>
            </tr>
            <tr class="text-left">
                <th width="40%">Gudang Dipinjam</th>
                <td width="5%" class="text-center">:</td>
                <td><?= htmlspecialchars($headerData['gudang_tujuan']) ?></td>
            </tr>
            <?php endif; ?>
            <tr class="text-left">
                <th width="40%">Status</th>
                <td width="5%" class="text-center">:</td>
                <td>
                    <?php
                    if ($overallStatus == 'draft') {
                        echo '<span class="badge badge-warning">Draft</span>';
                    } elseif ($overallStatus == 'selesai') {
                        echo '<span class="badge badge-success">Selesai</span>';
                    } elseif ($overallStatus == 'belum_selesai') {
                        echo '<span class="badge badge-danger">Belum Selesai</span>';
                    } elseif ($overallStatus == 'terproses') {
                        echo '<span class="badge badge-info">Terproses</span>';
                    } elseif ($overallStatus == 'final') {
                        echo '<span class="badge badge-primary">Final</span>';
                    } else {
                        echo '<span class="badge badge-secondary">' . htmlspecialchars($status_peminjaman) . '</span>';
                    }
                    ?>
                </td>
            </tr>
        </table>
    </div>
    
    <!-- Kolom Kanan: Company Identity -->
    <div class="col-md-6">
        <div class="card" style="border: none; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">
            <div class="card-body">
                <h6 class="font-weight-bold mb-3">
                    <i class="fas fa-building mr-2"></i>Company Identity
                </h6>
                <div class="mb-2 d-flex align-items-start">
                    <i class="fas fa-user text-muted mr-2 mt-1" style="width: 20px;"></i>
                    <div>
                        <strong>Name:</strong> <span><?= htmlspecialchars($companyData['nama'] ?? '-') ?></span>
                    </div>
                </div>
                <div class="mb-2 d-flex align-items-start">
                    <i class="fas fa-map-marker-alt text-muted mr-2 mt-1" style="width: 20px;"></i>
                    <div>
                        <strong>Address:</strong> <span><?= htmlspecialchars($companyData['alamat'] ?? '-') ?></span>
                    </div>
                </div>
                <div class="mb-2 d-flex align-items-start">
                    <i class="fas fa-phone text-muted mr-2 mt-1" style="width: 20px;"></i>
                    <div>
                        <strong>Phone Number:</strong> <span><?= htmlspecialchars($companyData['telp'] ?? '-') ?></span>
                    </div>
                </div>
                <div class="mb-2 d-flex align-items-start">
                    <i class="fas fa-envelope text-muted mr-2 mt-1" style="width: 20px;"></i>
                    <div>
                        <strong>Email Address:</strong> 
                        <?php if (!empty($companyData['email']) && $companyData['email'] != '-'): ?>
                            <a href="mailto:<?= htmlspecialchars($companyData['email']) ?>" style="color: #007bff;"><?= htmlspecialchars($companyData['email']) ?></a>
                        <?php else: ?>
                            <span class="text-muted">-</span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Tabel Read-only dengan Jml Dipinjam dan Dikembalikan -->
<table class="table table-striped table-bordered table-hover">
    <thead>
        <tr class="text-center">
            <th width="5%">No</th>
            <th width="15%">Gudang</th>
            <th width="25%">Produk</th>
            <th width="15%" class="text-nowrap">Jml. Dipinjam</th>
            <th width="15%" class="text-nowrap">Jml. Dikembalikan</th>
            <th width="15%">Status</th>
        </tr>
    </thead>
    <tbody>
        <?php 
        $noList = 1;
        $total_jumlah_peminjaman = 0;
        $total_jumlah_diterima = 0;
        
        foreach ($allProducts as $product) {
            $total_jumlah_peminjaman += $product['qty'];
            
            if ($product['jumlah_diterima'] !== null) {
                $total_jumlah_diterima += $product['jumlah_diterima'];
            }
            
            $statusBadge = '';
            
            if ($product['jumlah_diterima'] === null) {
                // Tidak ada data di log_stok gudang tujuan → Final
                $statusBadge = '<span class="badge badge-primary">Final</span>';
            } else {
                // Gunakan perbandingan dengan toleransi untuk menghindari masalah floating point
                $jumlahDiterima = floatval($product['jumlah_diterima']);
                $qty = floatval($product['qty']);
                
                if (abs($jumlahDiterima - $qty) < 0.01) {
                    // Jml diterima = qty → Selesai
                    $statusBadge = '<span class="badge badge-success"><i class="far fa-check-circle"></i> Selesai</span>';
                } else {
                    // Jml diterima != qty → Belum Selesai
                    $statusBadge = '<span class="badge badge-danger"><i class="fas fa-times-circle"></i> Belum Selesai</span>';
                }
            }
        ?>
        <tr>
            <td><?= $noList ?></td>
            <td class="text-left"><?= htmlspecialchars($product['gudang']) ?></td>
            <td class="text-left">
                <?= htmlspecialchars($product['produk']) ?>
                <?php if (!empty($product['has_penyesuaian'])): ?>
                    <br class="my-0 mb-1" />
                    <small class="badge bg-warning"><i class="fas fa-exclamation-circle"></i> Penyesuaian</small>
                <?php endif ?>
            </td>
            <td class="text-center"><?= number_format($product['qty'], 0) ?></td>
            <td class="text-center">
                <?php 
                if ($product['jumlah_diterima'] !== null) {
                    echo number_format($product['jumlah_diterima'], 0);
                } else {
                    echo '-';
                }
                ?>
            </td>
            <td class="text-center"><?= $statusBadge ?></td>
        </tr>
        <?php 
            $noList++;
        } 
        ?>
    </tbody>
    <tfoot>
        <tr>
            <th colspan="3" class="text-center">Total</th>
            <th class="text-center"><?= number_format($total_jumlah_peminjaman, 0) ?></th>
            <th class="text-center"><?= $total_jumlah_diterima != 0 ? number_format($total_jumlah_diterima, 0) : '-' ?></th>
            <th></th>
        </tr>
    </tfoot>
</table>

<!-- Form Editable (Accordion) -->
<div class="accordion col-12 mt-4" id="accordionDetailPeminjaman">
    <div class="card">
        <div class="card-header p-0 border-0" id="headingDetailPeminjaman">
            <button class="btn btn-info btn-block text-left p-2" type="button" data-toggle="collapse" data-target="#collapseDetailPeminjaman" aria-expanded="true" aria-controls="collapseDetailPeminjaman">
                <div class="d-flex justify-content-between">
                    <h4 class="mb-0 my-auto"><i class="fas fa-file-alt"></i> Detail Data Peminjaman</h4>
                    <i class="fas fa-chevron-down my-auto"></i>
                </div>
            </button>
        </div>

        <div id="collapseDetailPeminjaman" class="collapse show" aria-labelledby="headingDetailPeminjaman" data-parent="#accordionDetailPeminjaman">
            <div class="card-body">
                <div class="text-left mb-3">
                    <button type="button" class="btn btn-sm btn-success addRowPeminjaman" data-nomor-peminjaman="<?= htmlspecialchars($nomor_peminjaman, ENT_QUOTES) ?>">
                        <i class="fas fa-plus-circle"></i> Tambah Rincian
                    </button>
                </div>

                <form class="formEditPeminjaman" data-nomor-peminjaman="<?= htmlspecialchars($nomor_peminjaman, ENT_QUOTES) ?>" method="post">
                    <input type="hidden" name="nomor_peminjaman" value="<?= htmlspecialchars($nomor_peminjaman) ?>">
                    <table class="datatables table table-striped table-bordered table-hover">
                        <thead>
                            <tr class="text-center">
                                <th width="5%">No</th>
                                <th width="25%">Gudang</th>
                                <th width="50%">Produk</th>
                                <th width="15%">Jumlah</th>
                                <th width="5%">Aksi</th>
                            </tr>
                        </thead>
                        <tbody class="tbodyListDataPeminjaman" data-nomor-peminjaman="<?= htmlspecialchars($nomor_peminjaman, ENT_QUOTES) ?>" data-gudang-asal="<?= htmlspecialchars($headerData['gudang_asal'] ?? '', ENT_QUOTES) ?>">
                        <?php
                        $noDetailPeminjaman = 1;
                        // Query untuk mengambil semua detail peminjaman/pengembalian
                        $queryDetailPeminjaman = "SELECT 
                                                    m.id,
                                                    m.gudang_asal as gudang,
                                                    m.produk,
                                                    m.qty,
                                                    m.catatan
                                                  FROM $table_name m
                                                  WHERE m.$nomor_field = ?
                                                  ORDER BY 
                                                    CASE WHEN m.catatan IS NULL OR m.catatan = '' OR m.catatan NOT LIKE '%Penyesuaian%' THEN 0 ELSE 1 END,
                                                    m.id ASC";
                        $stmtDetailPeminjaman = mysqli_prepare($db_dc, $queryDetailPeminjaman);
                        if ($stmtDetailPeminjaman) {
                            mysqli_stmt_bind_param($stmtDetailPeminjaman, "s", $nomor_peminjaman);
                            mysqli_stmt_execute($stmtDetailPeminjaman);
                            $resultDetailPeminjaman = mysqli_stmt_get_result($stmtDetailPeminjaman);
                            
                            while ($rowDetail = mysqli_fetch_assoc($resultDetailPeminjaman)) {
                                $isPenyesuaianDetail = !empty($rowDetail['catatan']) && stripos($rowDetail['catatan'], 'Penyesuaian') !== false;
                                $rowClassDetail = $isPenyesuaianDetail ? 'table-warning' : '';
                                $isDraft = $headerData['status_peminjaman'] === 'Draft';
                                $produkDisabledAttr = $isDraft ? '' : 'disabled';
                                $jumlahReadonlyAttr = $isDraft ? '' : 'readonly';
                                $deleteDisabledAttr = ''; // Tombol hapus selalu enabled
                                
                                // Query untuk mendapatkan opsi gudang dan produk
                                // (Ini akan diisi dengan JavaScript, tapi kita buat struktur HTML dulu)
                                ?>
                                <tr class="<?= $rowClassDetail ?> data-existing" data-id="<?= $rowDetail['id'] ?>">
                                    <td class="text-center"><?= $noDetailPeminjaman ?></td>
                                    <td>
                                        <select name="in_gudang[]" class="form-control select-gudang" <?= $produkDisabledAttr ?>>
                                            <option value="<?= htmlspecialchars($rowDetail['gudang']) ?>" selected><?= htmlspecialchars($rowDetail['gudang']) ?></option>
                                        </select>
                                    </td>
                                    <td>
                                        <select name="in_produk[]" class="form-control select-produk" <?= $produkDisabledAttr ?>>
                                            <option value="<?= htmlspecialchars($rowDetail['produk']) ?>" selected><?= htmlspecialchars($rowDetail['produk']) ?></option>
                                        </select>
                                    </td>
                                    <td class="text-center">
                                        <input type="number" class="form-control" name="in_jumlah[]" value="<?= $rowDetail['qty'] ?>" <?= $jumlahReadonlyAttr ?>>
                                        <input type="hidden" name="in_id[]" value="<?= $rowDetail['id'] ?>">
                                    </td>
                                    <td class="text-center">
                                        <button type="button" class="btn btn-sm btn-danger btnDeleteRowPeminjaman" <?= $deleteDisabledAttr ?>>
                                            <i class="fas fa-trash-alt"></i>
                                        </button>
                                    </td>
                                </tr>
                                <?php
                                $noDetailPeminjaman++;
                            }
                            mysqli_stmt_close($stmtDetailPeminjaman);
                        }
                        ?>
                        </tbody>
                    </table>
                </form>
            </div>
        </div>
    </div>
</div>

<?php
// Hanya tampilkan table pengembalian jika type adalah peminjaman (bukan pengembalian)
if ($type === 'peminjaman' && !empty($nomor_peminjaman)) {
    // Query untuk mengambil data pengembalian berdasarkan nomor peminjaman
    // Hanya ambil data yang memiliki nomor_pengembalian (tidak NULL) untuk menghindari data draft
    $nomorPeminjamanEscaped = mysqli_real_escape_string($db_dc, $nomor_peminjaman);
    $queryPengembalian = "SELECT 
                            ps.nomor_pengembalian,
                            ps.tanggal_pengembalian,
                            ps.status_pengembalian,
                            ps.entitas_peminjam,
                            ps.entitas_dipinjam,
                            ps.gudang_asal,
                            ps.gudang_tujuan,
                            COUNT(DISTINCT ps.produk) as jumlah_item,
                            SUM(ps.qty) as total_qty
                         FROM pengembalian_stok ps
                         WHERE ps.nomor_peminjaman = '{$nomorPeminjamanEscaped}'
                         AND ps.nomor_pengembalian IS NOT NULL
                         AND ps.nomor_pengembalian != ''
                         AND TRIM(ps.nomor_pengembalian) != ''
                         GROUP BY ps.nomor_pengembalian, ps.tanggal_pengembalian, ps.status_pengembalian, 
                                  ps.entitas_peminjam, ps.entitas_dipinjam, ps.gudang_asal, ps.gudang_tujuan
                         ORDER BY ps.tanggal_pengembalian DESC, ps.nomor_pengembalian DESC";
    
    $resultPengembalian = mysqli_query($db_dc, $queryPengembalian);
    $pengembalianData = [];
    
    if ($resultPengembalian) {
        while ($rowPengembalian = mysqli_fetch_assoc($resultPengembalian)) {
            $pengembalianData[] = [
                'nomor_pengembalian' => $rowPengembalian['nomor_pengembalian'] ?? '-',
                'tanggal_pengembalian' => $rowPengembalian['tanggal_pengembalian'] ?? null,
                'status_pengembalian' => $rowPengembalian['status_pengembalian'] ?? '-',
                'entitas_peminjam' => $rowPengembalian['entitas_peminjam'] ?? '-',
                'entitas_dipinjam' => $rowPengembalian['entitas_dipinjam'] ?? '-',
                'gudang_asal' => $rowPengembalian['gudang_asal'] ?? '-',
                'gudang_tujuan' => $rowPengembalian['gudang_tujuan'] ?? '-',
                'jumlah_item' => intval($rowPengembalian['jumlah_item'] ?? 0),
                'total_qty' => floatval($rowPengembalian['total_qty'] ?? 0)
            ];
        }
    }
    ?>
    
    <!-- Table Data Pengembalian -->
    <div class="col-12 mt-4">
        <div class="card">
            <div class="card-header bg-info text-white">
                <h5 class="mb-0"><i class="fas fa-undo-alt mr-2"></i>Data Pengembalian</h5>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-bordered table-hover table-striped mb-0">
                        <thead class="thead-light">
                            <tr class="text-center">
                                <th width="5%">No</th>
                                <th width="12%">Tanggal</th>
                                <th width="15%">Nomor Pengembalian</th>
                                <th width="12%">Entitas Pengembali</th>
                                <th width="12%">Entitas Penerima</th>
                                <th width="12%">Gudang Pengembali</th>
                                <th width="12%">Gudang Penerima</th>
                                <th width="8%">Jumlah Item</th>
                                <th width="10%">Total Qty</th>
                                <th width="10%">Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            if (empty($pengembalianData)) {
                                ?>
                                <tr>
                                    <td colspan="10" class="text-center text-muted">
                                        <i class="fas fa-info-circle mr-2"></i>Belum ada data pengembalian
                                    </td>
                                </tr>
                                <?php
                            } else {
                                $noPengembalian = 1;
                                foreach ($pengembalianData as $pengembalian) {
                                    $tanggalFormatted = $pengembalian['tanggal_pengembalian'] 
                                        ? date('d/m/Y', strtotime($pengembalian['tanggal_pengembalian'])) 
                                        : '-';
                                    
                                    $statusBadge = '';
                                    if ($pengembalian['status_pengembalian'] === 'Draft') {
                                        $statusBadge = '<span class="badge badge-warning">Draft</span>';
                                    } elseif ($pengembalian['status_pengembalian'] === 'Selesai') {
                                        $statusBadge = '<span class="badge badge-success">Selesai</span>';
                                    } elseif ($pengembalian['status_pengembalian'] === 'Final') {
                                        $statusBadge = '<span class="badge badge-primary">Final</span>';
                                    } else {
                                        $statusBadge = '<span class="badge badge-secondary">' . htmlspecialchars($pengembalian['status_pengembalian']) . '</span>';
                                    }
                                    ?>
                                    <tr>
                                        <td class="text-center"><?= $noPengembalian ?></td>
                                        <td class="text-center"><?= htmlspecialchars($tanggalFormatted) ?></td>
                                        <td class="text-center">
                                            <strong><?= htmlspecialchars($pengembalian['nomor_pengembalian']) ?></strong>
                                        </td>
                                        <td class="text-left"><?= htmlspecialchars($pengembalian['entitas_peminjam']) ?></td>
                                        <td class="text-left"><?= htmlspecialchars($pengembalian['entitas_dipinjam']) ?></td>
                                        <td class="text-left"><?= htmlspecialchars($pengembalian['gudang_asal']) ?></td>
                                        <td class="text-left"><?= htmlspecialchars($pengembalian['gudang_tujuan']) ?></td>
                                        <td class="text-center"><?= number_format($pengembalian['jumlah_item'], 0) ?></td>
                                        <td class="text-center"><strong><?= number_format($pengembalian['total_qty'], 0) ?></strong></td>
                                        <td class="text-center"><?= $statusBadge ?></td>
                                    </tr>
                                    <?php
                                    $noPengembalian++;
                                }
                            }
                            ?>
                        </tbody>
                        <?php if (!empty($pengembalianData)): ?>
                        <tfoot>
                            <tr class="font-weight-bold">
                                <td colspan="7" class="text-right">Total</td>
                                <td class="text-center"><?= count($pengembalianData) ?></td>
                                <td class="text-center">
                                    <?= number_format(array_sum(array_column($pengembalianData, 'total_qty')), 0) ?>
                                </td>
                                <td></td>
                            </tr>
                        </tfoot>
                        <?php endif; ?>
                    </table>
                </div>
            </div>
        </div>
    </div>
    <?php
}
?>

        <?php
        
        ob_end_flush();
    } catch (Exception $e) {
        ob_clean();
        echo '<div class="alert alert-danger">Error: ' . htmlspecialchars($e->getMessage()) . '</div>';
        ob_end_flush();
    }
}
?>



