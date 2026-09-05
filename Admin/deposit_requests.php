<?php
// Force display errors on screen for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

ob_start();
if (session_status() === PHP_SESSION_NONE) { session_start(); }

require_once "../Common/config.php";

if (!isset($_SESSION["admin_logged_in"]) || $_SESSION["admin_logged_in"] !== true) {
    header("Location: login.php");
    exit;
}

$message = "";
$message_type = "";

if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["deposit_action"])) {
    $deposit_id = intval($_POST["deposit_id"] ?? 0);
    $action = $_POST["deposit_action"] ?? "";

    if ($deposit_id > 0 && in_array($action, ["approve", "reject"])) {
        if ($action === "approve") {
            $conn->begin_transaction();
            try {
                // Pointing to deposit_requests table
                $stmt = $conn->prepare("SELECT id, user_id, amount, status FROM deposit_requests WHERE id = ? LIMIT 1 FOR UPDATE");
                $stmt->bind_param("i", $deposit_id);
                $stmt->execute();
                $result = $stmt->get_result();
                $deposit = $result ? $result->fetch_assoc() : null;
                $stmt->close();

                // Check for both Pending and Under Review
                if (!$deposit || ($deposit["status"] !== "Pending" && $deposit["status"] !== "Under Review")) {
                    throw new Exception("Request already processed.");
                }

                $user_id = (int)$deposit["user_id"];
                $amount = (float)$deposit["amount"];

                $stmt = $conn->prepare("UPDATE users SET wallet_balance = wallet_balance + ?, deposit_balance = deposit_balance + ? WHERE id = ?");
                $stmt->bind_param("ddi", $amount, $amount, $user_id);
                $stmt->execute();
                $stmt->close();

                $stmt = $conn->prepare("INSERT INTO transactions (user_id, type, amount, description, reference_id) VALUES (?, 'credit', ?, 'Funds added via UPI App', ?)");
                $stmt->bind_param("idi", $user_id, $amount, $deposit_id);
                $stmt->execute();
                $stmt->close();

                // Update status to Approved
                $stmt = $conn->prepare("UPDATE deposit_requests SET status = 'Approved' WHERE id = ?");
                $stmt->bind_param("i", $deposit_id);
                $stmt->execute();
                $stmt->close();

                $conn->commit();
                $message = "Deposit approved. ₹{$amount} added to player.";
                $message_type = "success";
            } catch (Exception $e) {
                $conn->rollback();
                $message = "Error processing request: " . $e->getMessage();
                $message_type = "error";
            }
        } elseif ($action === "reject") {
            $stmt = $conn->prepare("UPDATE deposit_requests SET status = 'Rejected' WHERE id = ? AND (status = 'Pending' OR status = 'Under Review')");
            $stmt->bind_param("i", $deposit_id);
            $stmt->execute();
            $stmt->close();
            $message = "Deposit rejected.";
            $message_type = "success";
        }
    }
}

$deposits = [];
// Selecting utr from deposit_requests
$sql = "SELECT d.id, d.user_id, d.amount, d.utr, d.status, d.created_at, u.username 
        FROM deposit_requests d 
        LEFT JOIN users u ON u.id = d.user_id 
        ORDER BY CASE WHEN d.status = 'Under Review' THEN 0 WHEN d.status = 'Pending' THEN 1 ELSE 2 END, d.created_at DESC";
$result = $conn->query($sql);
if ($result) {
    while ($row = $result->fetch_assoc()) { $deposits[] = $row; }
}

$pending_count = count(array_filter($deposits, function($d) { return $d["status"] === "Pending" || $d["status"] === "Under Review"; }));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Deposit Requests</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
</head>
<body class="bg-gray-950 text-white min-h-screen">
<header class="sticky top-0 z-50 bg-gray-950/95 backdrop-blur border-b border-gray-800">
    <div class="max-w-5xl mx-auto px-4 py-4 flex items-center justify-between">
        <div class="flex items-center gap-3">
            <a href="dashboard.php" class="w-10 h-10 rounded-xl bg-gray-800 flex items-center justify-center hover:bg-gray-700"><i class="fa-solid fa-arrow-left"></i></a>
            <div>
                <p class="text-xs text-gray-500">Admin Panel</p>
                <h1 class="font-bold">Deposit Requests</h1>
            </div>
        </div>
        <span class="text-xs bg-yellow-950 text-yellow-400 rounded-full px-3 py-1"><?= $pending_count ?> Pending / Review</span>
    </div>
</header>
<main class="max-w-5xl mx-auto px-4 py-6 pb-12">
    <?php if ($message !== ""): ?>
        <div class="mb-6 rounded-xl p-4 border <?= $message_type === "success" ? "bg-green-950/50 border-green-800 text-green-300" : "bg-red-950/50 border-red-800 text-red-300" ?>">
            <?= htmlspecialchars($message) ?>
        </div>
    <?php endif; ?>

    <?php if (count($deposits) > 0): ?>
        <div class="space-y-4">
            <?php foreach ($deposits as $deposit): ?>
                <?php
                $status = $deposit["status"];
                if ($status === "Under Review") {
                    $status_class = "bg-blue-950 text-blue-400";
                } elseif ($status === "Pending") {
                    $status_class = "bg-yellow-950 text-yellow-400";
                } elseif ($status === "Approved") {
                    $status_class = "bg-green-950 text-green-400";
                } else {
                    $status_class = "bg-red-950 text-red-400";
                }
                ?>
                <section class="bg-gray-900 border border-gray-800 rounded-2xl p-5">
                    <div class="flex items-start justify-between gap-3 mb-5">
                        <div>
                            <p class="text-xs text-gray-500">Request #<?= (int)$deposit["id"] ?></p>
                            <h2 class="font-bold text-lg mt-1"><?= htmlspecialchars($deposit["username"] ?? 'Unknown') ?></h2>
                        </div>
                        <span class="text-xs font-semibold rounded-full px-3 py-1 <?= $status_class ?>"><?= htmlspecialchars($status) ?></span>
                    </div>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 mb-5">
                        <div class="bg-gray-950 rounded-xl p-4 border border-gray-800">
                            <p class="text-xs text-gray-500">Amount</p>
                            <p class="text-2xl font-bold mt-1 text-green-400">₹<?= number_format((float)$deposit["amount"], 2) ?></p>
                        </div>
                        <div class="bg-gray-950 rounded-xl p-4 border border-gray-800">
                            <p class="text-xs text-gray-500">Date</p>
                            <p class="font-semibold mt-1"><?= date("d M Y, h:i A", strtotime($deposit["created_at"])) ?></p>
                        </div>
                    </div>
                    <div class="bg-gray-950 border border-gray-800 rounded-xl p-4 mb-5">
                        <p class="text-xs text-gray-500 uppercase tracking-wide font-semibold">12-Digit UTR / Ref No.</p>
                        <p class="font-mono text-lg text-indigo-300 break-all mt-2"><?= htmlspecialchars($deposit["utr"] ?? 'Not Submitted Yet') ?></p>
                    </div>
                    <?php if ($status === "Pending" || $status === "Under Review"): ?>
                        <div class="grid grid-cols-2 gap-3">
                            <form method="POST" onsubmit="return confirm('Approve this deposit?');">
                                <input type="hidden" name="deposit_id" value="<?= (int)$deposit["id"] ?>">
                                <input type="hidden" name="deposit_action" value="approve">
                                <button type="submit" class="w-full bg-green-600 hover:bg-green-500 rounded-xl py-3 font-semibold"><i class="fa-solid fa-check mr-1"></i> Approve</button>
                            </form>
                            <form method="POST" onsubmit="return confirm('Reject this request?');">
                                <input type="hidden" name="deposit_id" value="<?= (int)$deposit["id"] ?>">
                                <input type="hidden" name="deposit_action" value="reject">
                                <button type="submit" class="w-full border border-red-800 text-red-400 hover:bg-red-950 rounded-xl py-3 font-semibold"><i class="fa-solid fa-xmark mr-1"></i> Reject</button>
                            </form>
                        </div>
                    <?php endif; ?>
                </section>
            <?php endforeach; ?>
        </div>
    <?php else: ?>
        <div class="bg-gray-900 border border-gray-800 rounded-2xl p-10 text-center text-gray-500">No requests found.</div>
    <?php endif; ?>
</main>
</body>
</html>
