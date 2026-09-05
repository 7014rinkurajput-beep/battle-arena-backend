<?php
// deposit_webhook.php
ini_set('display_errors', 0);
error_reporting(E_ALL);

header('Content-Type: application/json');
require_once "Common/config.php";

// Define your strong shared secret key
define('WEBHOOK_SECRET', 'YOUR_SUPER_SECRET_KEY_12345');

// 1. Verify Secret via URL parameter
$secret = $_GET['secret'] ?? '';

if ($secret !== WEBHOOK_SECRET) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
    exit;
}

// 2. Read JSON Payload from Google Apps Script
$input = file_get_contents('php://input');
$data = json_decode($input, true);

$utr = trim($data['utr'] ?? '');
$amount = floatval($data['amount'] ?? 0);
$sender_upi = trim($data['sender_upi'] ?? '');

// 3. Strict Validation for 12-digit UTR and valid amount
if (empty($utr) || strlen($utr) !== 12 || !ctype_digit($utr) || $amount <= 0) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Invalid UTR or Amount format']);
    exit;
}

// 4. Securely insert into incoming_bank_credits with duplicate protection
$stmt = $conn->prepare("INSERT IGNORE INTO incoming_bank_credits (utr, amount, sender_upi) VALUES (?, ?, ?)");
$stmt->bind_param("sds", $utr, $amount, $sender_upi);

if ($stmt->execute()) {
    $affected_rows = $stmt->affected_rows;
    $stmt->close();

    http_response_code(200);
    echo json_encode([
        'status' => 'success', 
        'message' => $affected_rows > 0 ? 'Credit logged successfully' : 'Duplicate UTR ignored'
    ]);
} else {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Database error: ' . $conn->error]);
}
?>
