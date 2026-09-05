<?php
// webhook.php - Listens for payment success signals from your UPI Aggregator
// FIXED: Matched exact case for 'Common' directory
require_once "Common/config.php";

// 1. Catch the hidden JSON data sent by the UPI gateway
$json_data = file_get_contents('php://input');
$data = json_decode($json_data, true);

// If someone tries to visit this page directly in a browser, block them.
if (!$data) {
    http_response_code(400);
    exit("Invalid Payload. This endpoint is for API webhooks only.");
}

// 2. Extract the payment data
$order_id = $data['client_txn_id'] ?? $data['order_id'] ?? '';
$amount = floatval($data['amount'] ?? 0);
$status = strtoupper($data['status'] ?? ''); 
$upi_txn_id = $data['upi_txn_id'] ?? 'N/A'; // The 12-digit UTR from the bank

if ($status === 'SUCCESS' || $status === 'COMPLETED') {
    
    $conn->begin_transaction();
    
    try {
        // 3. Find the matching Pending deposit request we created in qr.php
        $stmt = $conn->prepare("SELECT user_id, amount, status FROM deposit_requests WHERE order_id = ? FOR UPDATE");
        $stmt->bind_param("s", $order_id);
        $stmt->execute();
        $request = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        
        // 4. If the request exists and hasn't been credited yet
        // FIXED: Made the status check case-insensitive so it catches both 'Pending' and 'pending'
        if ($request && strtolower($request['status']) === 'pending') {
            $user_id = $request['user_id'];
            $requested_amount = (float)$request['amount'];
            
            // Safety check: Ensure they didn't alter the amount in their UPI app
            if ($amount >= $requested_amount) {
                
                // STEP A: Mark request as Approved
                $stmt = $conn->prepare("UPDATE deposit_requests SET status = 'Approved' WHERE order_id = ?");
                $stmt->bind_param("s", $order_id);
                $stmt->execute();
                $stmt->close();
                
                // STEP B: Instantly add money to user's wallet
                $stmt = $conn->prepare("UPDATE users SET wallet_balance = wallet_balance + ?, deposit_balance = deposit_balance + ? WHERE id = ?");
                $stmt->bind_param("ddi", $amount, $amount, $user_id);
                $stmt->execute();
                $stmt->close();
                
                // STEP C: Log the transaction so it appears perfectly in the wallet history
                $desc = "Funds added via UPI (UTR: " . $upi_txn_id . ")";
                $stmt = $conn->prepare("INSERT INTO transactions (user_id, amount, type, description) VALUES (?, ?, 'credit', ?)");
                $stmt->bind_param("ids", $user_id, $amount, $desc);
                $stmt->execute();
                $stmt->close();
                
                $conn->commit();
                
                // Tell the aggregator we successfully processed the payment
                http_response_code(200);
                echo "Webhook Processed Successfully";
                exit;
            }
        }
        $conn->commit(); // Unlock database if already processed
        
    } catch (Exception $e) {
        $conn->rollback();
        http_response_code(500);
        exit("Database Error");
    }
}

// If the payment failed or was already processed, still respond with 200 so the aggregator stops pinging you
http_response_code(200);
echo "Webhook Received";
?>
