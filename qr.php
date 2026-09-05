<?php
ob_start();
// Enable error reporting to catch future issues
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// FIXED: Case-sensitive path for Android servers
require_once "Common/config.php";
date_default_timezone_set('Asia/Kolkata');

// Check login
if (!isset($_SESSION["user_id"])) {
    header("Location: login.php");
    exit;
}
$user_id = (int)$_SESSION["user_id"];

// AUTO-CREATE deposit_requests TABLE IF NOT EXISTS
$conn->query("CREATE TABLE IF NOT EXISTS deposit_requests (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    amount DECIMAL(10,2) NOT NULL,
    order_id VARCHAR(100) NULL,
    status VARCHAR(50) DEFAULT 'Pending',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)");

// FIX: Safely add order_id column using try/catch instead of @ suppression
try {
    $conn->query("ALTER TABLE deposit_requests ADD COLUMN order_id VARCHAR(100) NULL");
} catch (Exception $e) {
    // Silently ignore if column already exists
}

// Get the requested amount via URL, default to 50 if missing
$amount = isset($_GET['amount']) ? intval($_GET['amount']) : 50;
$valid_amounts = [10, 20, 50, 100, 200];
if (!in_array($amount, $valid_amounts)) {
    $amount = 50;
}

// Generate a Unique Order ID (TXN_ + user_id + timestamp format)
$order_id = "TXN_" . $user_id . "_" . time();

// --- BACKEND API REQUEST TO UPI AGGREGATOR ---
$api_url = "https://your-aggregator-api.com/create_order"; // REPLACE THIS with your aggregator's endpoint
$api_key = "YOUR_API_TOKEN_HERE"; // REPLACE THIS with your actual API key

$payload = json_encode([
    "amount" => $amount,
    "order_id" => $order_id,
    "customer_id" => (string)$user_id
]);

$ch = curl_init($api_url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Content-Type: application/json',
    'Authorization: Bearer ' . $api_key
]);
$api_response = curl_exec($ch);
curl_close($ch);

$response_data = json_decode($api_response, true);

// Extract the dynamic intent URL from the aggregator's response. 
$upi_url = $response_data['intent_url'] ?? $response_data['payment_url'] ?? '';

// Fallback to static URL generation ONLY if the API call fails during testing
if (empty($upi_url)) {
    $merchant_upi = "7014057524@slc"; 
    $merchant_name = "BattleArena";
    $upi_url = "upi://pay?pa=" . urlencode($merchant_upi) . "&pn=" . urlencode($merchant_name) . "&am=" . number_format($amount, 2, '.', '') . "&tr=" . $order_id . "&cu=INR";
}

// Insert Pending Request into Database with error handling to catch query issues
$check_query = "SELECT id FROM deposit_requests WHERE user_id = ? AND amount = ? AND status = 'Pending' AND created_at > (NOW() - INTERVAL 1 MINUTE)";
$check_spam = $conn->prepare($check_query);

if (!$check_spam) {
    die("Prepare failed (Check Spam): " . $conn->error);
}

$check_spam->bind_param("id", $user_id, $amount);
$check_spam->execute();
$spam_result = $check_spam->get_result();

if ($spam_result->num_rows === 0) {
    $insert_query = "INSERT INTO deposit_requests (user_id, amount, order_id, status) VALUES (?, ?, ?, 'Pending')";
    $stmt = $conn->prepare($insert_query);
    
    if (!$stmt) {
        die("Prepare failed (Insert): " . $conn->error);
    }
    
    $stmt->bind_param("ids", $user_id, $amount, $order_id);
    $stmt->execute();
    $stmt->close();
}
$check_spam->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Complete Payment - Battle Arena</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    <!-- Include QRCode.js library for dynamic generation -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
</head>
<body class="bg-gray-950 flex items-center justify-center min-h-screen p-4">

    <!-- QR CARD -->
    <div class="bg-white rounded-[32px] w-full max-w-sm p-8 text-center shadow-xl relative">
        <h2 class="text-gray-900 font-bold text-lg mb-1">Paying To</h2>
        <h1 class="text-black text-3xl font-bold mb-4">Battle Arena</h1>
        
        <div class="inline-block bg-teal-50 border border-teal-100 text-teal-900 font-bold text-2xl px-6 py-1 rounded-full mb-6">
            ₹<?php echo number_format($amount, 2); ?>
        </div>
        
        <!-- Dynamic QR Code Container -->
        <div class="relative flex justify-center items-center border-2 border-teal-400 rounded-2xl p-4 mb-4 bg-white mx-auto w-56 h-56">
            <div id="qrcode"></div>
        </div>

        <!-- Live Timer -->
        <div class="mb-4 text-red-500 font-bold text-xl tracking-wider" id="timer">05:00</div>
        
        <p class="text-[11px] text-gray-500 mb-6 font-bold uppercase tracking-wide">Scan with any UPI App or click below</p>

        <!-- One-Click UPI Intent Button -->
        <a href="<?php echo $upi_url; ?>" class="flex items-center justify-center w-full bg-indigo-600 text-white font-bold rounded-xl py-3.5 hover:bg-indigo-700 transition shadow-lg mb-4 gap-2">
            <i class="fa-solid fa-mobile-screen-button text-lg"></i> Pay via UPI App
        </a>
        
        <!-- UTR Submission Form (Replaces Checking Payment Status) -->
        <form action="claim_deposit.php" method="POST" class="space-y-3 mt-2">
            <input type="hidden" name="expected_amount" value="<?php echo htmlspecialchars($amount); ?>">
            <input type="hidden" name="order_id" value="<?php echo htmlspecialchars($order_id); ?>">
            
            <div>
                <input type="text" name="utr" placeholder="Enter 12-digit UTR / RRN" maxlength="12" pattern="[0-9]{12}" required 
                       class="w-full bg-gray-50 border border-gray-300 text-black text-center text-sm rounded-xl py-3 px-3 outline-none focus:border-indigo-600 font-semibold">
            </div>
            
            <button type="submit" class="w-full bg-emerald-600 hover:bg-emerald-500 text-white font-bold rounded-xl py-3 text-sm transition shadow-lg shadow-emerald-900/20">
                Submit UTR & Verify
            </button>
        </form>
        
        <!-- Return Button -->
        <div class="mt-4">
            <a href="wallet.php" class="text-xs text-gray-400 hover:text-gray-600 font-semibold underline">
                Return to Wallet
            </a>
        </div>
    </div>

    <!-- GENERATE QR & TIMER SCRIPT -->
    <script>
        // Generate Dynamic QR Code based on the API intent link
        new QRCode(document.getElementById("qrcode"), {
            text: "<?php echo $upi_url; ?>",
            width: 188,
            height: 188,
            colorDark : "#000000",
            colorLight : "#ffffff",
            correctLevel : QRCode.CorrectLevel.H
        });

        // 5 Minute Timer Logic
        let time = 300; 
        const timerEl = document.getElementById('timer');
        
        const interval = setInterval(() => {
            time--;
            let m = Math.floor(time / 60).toString().padStart(2, '0');
            let s = (time % 60).toString().padStart(2, '0');
            timerEl.textContent = `${m}:${s}`;
            
            if(time <= 0) {
                clearInterval(interval);
                alert('Payment session has expired.');
                window.location.href = 'wallet.php';
            }
        }, 1000);
    </script>
</body>
</html>
