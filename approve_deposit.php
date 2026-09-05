<?php
session_start();
// Use ../ because this file is inside the Admin folder
require_once "../Common/config.php";

// Make sure only admins can run this
// if (!isset($_SESSION['admin_logged_in'])) { exit('Unauthorized'); }

if (isset($_GET['order_id'])) {
    $order_id = $_GET['order_id'];
    
    $conn->begin_transaction();
    try {
        // Find the specific request
        $stmt = $conn->prepare("SELECT user_id, amount FROM deposit_requests WHERE order_id = ? AND status != 'Approved' FOR UPDATE");
        $stmt->bind_param("s", $order_id);
        $stmt->execute();
        $request = $stmt->get_result()->fetch_assoc();
        
        if ($request) {
            $user_id = $request['user_id'];
            $amount = (float)$request['amount'];
            
            // 1. Mark Approved
            $stmt1 = $conn->prepare("UPDATE deposit_requests SET status = 'Approved' WHERE order_id = ?");
            $stmt1->bind_param("s", $order_id);
            $stmt1->execute();
            
            // 2. Add to Wallet
            $stmt2 = $conn->prepare("UPDATE users SET wallet_balance = wallet_balance + ?, deposit_balance = deposit_balance + ? WHERE id = ?");
            $stmt2->bind_param("ddi", $amount, $amount, $user_id);
            $stmt2->execute();
            
            $conn->commit();
            echo "<script>alert('Success: Wallet Updated!'); window.location.href='deposit_requests.php';</script>";
        } else {
            echo "<script>alert('Already approved or not found.'); window.location.href='deposit_requests.php';</script>";
        }
    } catch (Exception $e) {
        $conn->rollback();
        echo "Error: Database update failed.";
    }
}
?>
