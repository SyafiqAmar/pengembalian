<?php
// File get_data.php untuk pengembalian
// File ini mendelegasikan semua request ke peminjaman/get_data.php dengan type=pengembalian
// karena kedua modul menggunakan struktur data yang sama

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

// Start output buffering untuk mencegah output sebelum JSON
if (ob_get_level() == 0) {
    ob_start();
}

// Set type=pengembalian untuk semua request sebelum include peminjaman/get_data.php
if (!isset($_GET['type'])) {
    $_GET['type'] = 'pengembalian';
}
if (!isset($_POST['type'])) {
    $_POST['type'] = 'pengembalian';
}

// Include peminjaman/get_data.php yang sudah memiliki semua fungsi yang diperlukan
// File tersebut akan menangani semua endpoint dengan type=pengembalian
require_once __DIR__ . '/../peminjaman/get_data.php';

