<?php
session_start();
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once "Common/config.php";
date_default_timezone_set('Asia/Kolkata');

if (!isset($_SESSION["user_id"])) {
    header("Location: login.php");
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $utr = trim($_POST['utr'] ?? '');
    $expected_amount = floatval($_POST['expected_amount'] ?? 0);
    $user_id = (int)$_SESSION["user_id"];

    if (strlen($utr) !== 12 || !ctype_digit($utr)) {
        echo "<script>alert('Invalid UTR format. Must be a 12-digit number.'); window.history.back();</script>";
        exit;
    }

    // 1. Check if credit exists in incoming_bank_credits
    $stmt = $conn->prepare("SELECT id, amount, is_claimed FROM incoming_bank_credits WHERE utr = ?");
    $stmt->bind_param("s", $utr);
    $stmt->execute();
    $result = $stmt->get_result();
    $credit = $result->fetch_assoc();
    $stmt->close();

    if (!$credit) {
        echo "<script>alert('Deposit not found yet! Please wait 30 seconds for the confirmation email to process and try again.'); window.history.back();</script>";
        exit;
    }

    if ((int)$credit['is_claimed'] === 1) {
        echo "<script>alert('Error: This UTR has already been claimed!'); window.history.back();</script>";
        exit;
    }

    if (floatval($credit['amount']) !== $expected_amount) {
        echo "<script>alert('Amount mismatch! The paid amount does not match this UTR.'); window.history.back();</script>";
        exit;
    }

    // 2. Start Transaction using MySQLi
    $conn->begin_transaction();
    try {
        // Update credit status
        $updateStmt = $conn->prepare("UPDATE incoming_bank_credits SET is_claimed = 1, claimed_by_user_id = ? WHERE id = ? AND is_claimed = 0");
        $updateStmt->bind_param("ii", $user_id, $credit['id']);
        $updateStmt->execute();

        if ($updateStmt->affected_rows === 0) {
            throw new Exception("Race condition: UTR was already claimed.");
        }
        $updateStmt->close();

        // Add balance to deposit_balance and wallet_balance in users table
        $walletStmt = $conn->prepare("UPDATE users SET deposit_balance = deposit_balance + ?, wallet_balance = wallet_balance + ? WHERE id = ?");
        $walletStmt->bind_param("ddi", $expected_amount, $expected_amount, $user_id);
        $walletStmt->execute();
        $walletStmt->close();

        // Insert transaction record
        $desc = "Deposit via UTR: " . $utr;
        $txStmt = $conn->prepare("INSERT INTO transactions (user_id, amount, type, description) VALUES (?, ?, 'credit', ?)");
        $txStmt->bind_param("ids", $user_id, $expected_amount, $desc);
        $txStmt->execute();
        $txStmt->close();

        $conn->commit();
        echo "<script>alert('Deposit successful! ₹{$expected_amount} added to your wallet.'); window.location.href='wallet.php';</script>";

    } catch (Exception $e) {
        $conn->rollback();
        echo "<script>alert('Transaction failed: " . addslashes($e->getMessage()) . "'); window.history.back();</script>";
    }
} else {
    header("Location: wallet.php");
    exit;
}
?>
