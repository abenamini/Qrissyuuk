<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

date_default_timezone_set('Asia/Jakarta');

$dbFile = __DIR__ . '/transactions.json';

// Kunci rahasia agar webhook tidak bisa ditembak sembarang orang
$WEBHOOK_SECRET = 'RAHASIA123';

// Inisialisasi data awal (sesuai contoh gambar) jika file belum ada
if (!file_exists($dbFile)) {
    $initialData = [
        [
            'id' => 210,
            'provider' => 'GOPAY',
            'phone' => '081234567891',
            'nominal' => 200000,
            'totalPaid' => 105068,
            'date' => '10 Mar 2026, 19.06',
            'status' => 'PROCESSING', // Dalam Proses
            'createdAt' => time() - 600
        ],
        [
            'id' => 209,
            'provider' => 'GOPAY',
            'phone' => '081234567891',
            'nominal' => 200000,
            'totalPaid' => 200142,
            'date' => '10 Mar 2026, 18.45',
            'status' => 'PENDING', // Menunggu Pembayaran
            'createdAt' => time() - 1800
        ]
    ];
    file_put_contents($dbFile, json_encode($initialData, JSON_PRETTY_PRINT));
}

function getTransactions($dbFile) {
    $content = file_get_contents($dbFile);
    return json_decode($content, true) ?: [];
}

function saveTransactions($dbFile, $data) {
    file_put_contents($dbFile, json_encode($data, JSON_PRETTY_PRINT), LOCK_EX);
}

function formatDateIndo() {
    $months = [1=>'Jan','Feb','Mar','Apr','Mei','Jun','Jul','Agu','Sep','Okt','Nov','Des'];
    $d = date('j');
    $m = $months[(int)date('n')];
    $y = date('Y');
    $time = date('H.i');
    return "$d $m $y, $time";
}

$action = $_GET['action'] ?? '';

// ==========================================================
// CEK KELAYAKAN DISKON PEMBELIAN PERTAMA (GET /api.php?action=check_promo&phone=...)
// ==========================================================
if ($action === 'check_promo') {
    $phone = preg_replace('/[^0-9]/', '', $_GET['phone'] ?? '');
    $txList = getTransactions($dbFile);
    $eligible = true;

    if ($phone !== '') {
        foreach ($txList as $tx) {
            if ($tx['phone'] === $phone) {
                $eligible = false;
                break;
            }
        }
    }

    echo json_encode(['status' => 'success', 'eligible' => $eligible]);
    exit;
}

// ==========================================================
// 1. CEK DAFTAR TRANSAKSI (GET /api.php?action=list&phone=...)
// ==========================================================
if ($action === 'list') {
    $phone = trim($_GET['phone'] ?? '');
    $txList = getTransactions($dbFile);

    if ($phone !== '') {
        $txList = array_values(array_filter($txList, function($tx) use ($phone) {
            return strpos($tx['phone'], $phone) !== false;
        }));
    }

    echo json_encode(['status' => 'success', 'data' => $txList]);
    exit;
}

// ==========================================================
// 2. CEK SATU TRANSAKSI REAL-TIME (GET /api.php?action=check&id=...)
// ==========================================================
if ($action === 'check') {
    $id = (int)($_GET['id'] ?? 0);
    $txList = getTransactions($dbFile);
    foreach ($txList as $tx) {
        if ((int)$tx['id'] === $id) {
            echo json_encode(['status' => 'success', 'data' => $tx]);
            exit;
        }
    }
    echo json_encode(['status' => 'error', 'message' => 'Transaksi tidak ditemukan']);
    exit;
}

// ==========================================================
// 3. BUAT TRANSAKSI BARU (POST /api.php?action=create)
// ==========================================================
if ($action === 'create' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    $provider = strtoupper(trim($input['provider'] ?? 'GOPAY'));
    $phone = preg_replace('/[^0-9]/', '', $input['phone'] ?? '');
    $nominal = (int)($input['nominal'] ?? 0);
    $useFirstDiscount = !empty($input['useFirstDiscount']);

    if (empty($phone) || $nominal < 1000) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'Nomor E-Wallet atau nominal tidak valid']);
        exit;
    }

    $txList = getTransactions($dbFile);

    // Validasi: Pastikan nomor E-Wallet belum pernah bertransaksi jika mengklaim Diskon Pembelian Pertama 25%
    if ($useFirstDiscount) {
        foreach ($txList as $tx) {
            if ($tx['phone'] === $phone) {
                http_response_code(400);
                echo json_encode([
                    'status' => 'error',
                    'code' => 'PROMO_NOT_ELIGIBLE',
                    'message' => 'Maaf, Diskon Pembelian Pertama 25% hanya berlaku 1x untuk nomor E-Wallet yang belum pernah bertransaksi.'
                ]);
                exit;
            }
        }
    }

    // Cari ID tertinggi lalu tambah 1
    $maxId = 208;
    $activeTotals = [];
    foreach ($txList as $tx) {
        if ($tx['id'] > $maxId) $maxId = $tx['id'];
        if ($tx['status'] === 'PENDING') {
            $activeTotals[] = (int)$tx['totalPaid'];
        }
    }

    // Hitung Diskon Pembelian Pertama 25% jika diaktifkan dan memenuhi syarat
    $discountAmount = $useFirstDiscount ? (int)round($nominal * 0.25) : 0;
    $baseAmount = $nominal - $discountAmount;

    // Buat 3 digit kode unik yang belum dipakai oleh transaksi PENDING lain
    $uniqueCode = rand(101, 899);
    $totalPaid = $baseAmount + $uniqueCode;
    while (in_array($totalPaid, $activeTotals)) {
        $uniqueCode = rand(101, 899);
        $totalPaid = $baseAmount + $uniqueCode;
    }

    $newTx = [
        'id' => $maxId + 1,
        'provider' => $provider,
        'phone' => $phone,
        'nominal' => $nominal,
        'discountApplied' => $useFirstDiscount,
        'discountAmount' => $discountAmount,
        'totalPaid' => $totalPaid,
        'date' => formatDateIndo(),
        'status' => 'PENDING',
        'createdAt' => time()
    ];

    array_unshift($txList, $newTx);
    saveTransactions($dbFile, $txList);

    echo json_encode(['status' => 'success', 'data' => $newTx]);
    exit;
}

// ==========================================================
// 4. WEBHOOK OTOMATIS PEMBACA NOTIFIKASI QRIS
//    (POST /api.php?action=webhook)
// ==========================================================
if ($action === 'webhook') {
    $rawInput = file_get_contents('php://input');
    $jsonInput = json_decode($rawInput, true);

    $secret = $_POST['secret'] ?? $_GET['secret'] ?? ($jsonInput['secret'] ?? '');
    if ($secret !== $WEBHOOK_SECRET) {
        http_response_code(403);
        echo json_encode(['status' => 'error', 'message' => 'Invalid Secret Key']);
        exit;
    }

    // Bisa menerima teks notifikasi mentah (contoh: "DANA Bisnis: Kamu menerima Rp105.068 via QRIS")
    // atau langsung parameter nominal angka
    $notifText = $_POST['text'] ?? ($jsonInput['text'] ?? '');
    $directAmount = $_POST['amount'] ?? ($jsonInput['amount'] ?? 0);

    $detectedAmount = 0;

    if (!empty($directAmount)) {
        $detectedAmount = (int)preg_replace('/[^0-9]/', '', (string)$directAmount);
    } elseif (!empty($notifText)) {
        // Hilangkan desimal ",00" di belakang jika ada (misal Rp 105.068,00 -> Rp 105.068)
        $cleanText = preg_replace('/,[0-9]{2}\b/', '', $notifText);
        // Ekstrak angka setelah kata "Rp" atau angka ribuan di dalam teks notifikasi
        if (preg_match('/Rp[\s\.]*([0-9\.]+)/i', $cleanText, $matches)) {
            $detectedAmount = (int)str_replace('.', '', $matches[1]);
        } elseif (preg_match('/\b([0-9]{1,3}(?:\.[0-9]{3})+|[0-9]{4,9})\b/', $cleanText, $matches)) {
            $detectedAmount = (int)str_replace('.', '', $matches[1]);
        }
    }

    if ($detectedAmount <= 0) {
        echo json_encode(['status' => 'ignored', 'message' => 'Nominal tidak terdeteksi pada notifikasi']);
        exit;
    }

    // Cari transaksi PENDING yang memiliki totalPaid == $detectedAmount
    $txList = getTransactions($dbFile);
    $matchedTx = null;

    foreach ($txList as &$tx) {
        if ($tx['status'] === 'PENDING' && (int)$tx['totalPaid'] === $detectedAmount) {
            $tx['status'] = 'PROCESSING'; // Otomatis berubah jadi "Dalam Proses"
            $tx['paidAt'] = formatDateIndo();
            $matchedTx = $tx;
            break;
        }
    }

    if ($matchedTx) {
        saveTransactions($dbFile, $txList);
        echo json_encode([
            'status' => 'success',
            'message' => 'Pembayaran cocok! Status diubah ke Dalam Proses.',
            'transaction' => $matchedTx
        ]);
    } else {
        echo json_encode([
            'status' => 'not_found',
            'message' => "Tidak ada transaksi PENDING dengan Total Dibayar Rp " . number_format($detectedAmount, 0, ',', '.')
        ]);
    }
    exit;
}

echo json_encode(['status' => 'error', 'message' => 'Endpoint tidak ditemukan']);
