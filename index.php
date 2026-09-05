<?php
// Enable error reporting to reveal the exact cause of any future 500 errors
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once "Common/config.php";
date_default_timezone_set('Asia/Kolkata');

if (!isset($_SESSION["user_id"])) {
    header("Location: login.php");
    exit;
}
$user_id = $_SESSION["user_id"];
$message = "";
$message_type = "";

$selected_category = trim($_GET["category"] ?? "All");

$check_cols = ["in_game_name" => "VARCHAR(100) NULL", "ff_uid" => "VARCHAR(50) NULL"];
foreach ($check_cols as $col => $def) {
    $chk = $conn->query("SHOW COLUMNS FROM participants LIKE '$col'");
    if ($chk && $chk->num_rows === 0) {
        try { $conn->query("ALTER TABLE participants ADD COLUMN `$col` $def"); } catch (Exception $e) {}
    }
}
$check_cols_t = ["room_id" => "VARCHAR(100) NULL", "room_password" => "VARCHAR(100) NULL", "category" => "VARCHAR(100) DEFAULT 'Full Map'"];
foreach ($check_cols_t as $col => $def) {
    $chk = $conn->query("SHOW COLUMNS FROM tournaments LIKE '$col'");
    if ($chk && $chk->num_rows === 0) {
        try { $conn->query("ALTER TABLE tournaments ADD COLUMN `$col` $def"); } catch (Exception $e) {}
    }
}

// HANDLE TOURNAMENT JOIN
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["join_tournament"])) {
    $tournament_id = intval($_POST["tournament_id"] ?? 0);
    $in_game_name = trim($_POST["in_game_name"] ?? "");
    $ff_uid = trim($_POST["ff_uid"] ?? "");

    if ($tournament_id <= 0 || $in_game_name === "" || $ff_uid === "") {
        $message = "Please enter your valid Free Fire details.";
        $message_type = "error";
    } else {
        $conn->begin_transaction();
        try {
            $stmt = $conn->prepare("SELECT t.id, t.title, t.entry_fee, t.status, t.total_slots, (SELECT COUNT(id) FROM participants WHERE tournament_id = t.id) as joined_count FROM tournaments t WHERE t.id = ? LIMIT 1 FOR UPDATE");
            $stmt->bind_param("i", $tournament_id);
            $stmt->execute();
            $result = $stmt->get_result();
            if ($result->num_rows !== 1) throw new Exception("Tournament not found.");
            $tournament = $result->fetch_assoc();
            $stmt->close();

            if ($tournament["status"] !== "Upcoming") throw new Exception("This tournament has already started or is closed.");
            if ((int)$tournament["joined_count"] >= (int)$tournament["total_slots"]) throw new Exception("This tournament is already full.");

            $stmt = $conn->prepare("SELECT id FROM participants WHERE user_id = ? AND tournament_id = ? LIMIT 1");
            $stmt->bind_param("ii", $user_id, $tournament_id);
            $stmt->execute();
            if ($stmt->get_result()->num_rows > 0) throw new Exception("You have already joined this tournament.");
            $stmt->close();

            // SAFE COLUMN CHECKS
            try { $conn->query("ALTER TABLE users ADD COLUMN deposit_balance DECIMAL(10,2) DEFAULT 0.00"); } catch (Exception $e) {}
            try { $conn->query("ALTER TABLE users ADD COLUMN winning_balance DECIMAL(10,2) DEFAULT 0.00"); } catch (Exception $e) {}
            try { $conn->query("ALTER TABLE users ADD COLUMN wallet_balance DECIMAL(10,2) DEFAULT 0.00"); } catch (Exception $e) {}
            try { $conn->query("ALTER TABLE users ADD COLUMN account_status VARCHAR(20) DEFAULT 'Active'"); } catch (Exception $e) {}

            $stmt = $conn->prepare("SELECT wallet_balance, deposit_balance, winning_balance, account_status FROM users WHERE id = ? LIMIT 1 FOR UPDATE");
            $stmt->bind_param("i", $user_id);
            $stmt->execute();
            $user_wallet = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            // BLOCK JOINING IF FROZEN
            if (($user_wallet['account_status'] ?? 'Active') === 'Frozen') {
                throw new Exception("Your account is currently frozen. You cannot join tournaments.");
            }

            $deposit_balance = (float)($user_wallet["deposit_balance"] ?? 0);
            $winning_balance = (float)($user_wallet["winning_balance"] ?? 0);
            $total_balance = (float)($user_wallet["wallet_balance"] ?? ($deposit_balance + $winning_balance));
            $entry_fee = (float)$tournament["entry_fee"];

            if ($total_balance < $entry_fee) throw new Exception("Insufficient wallet balance.");

            if ($deposit_balance >= $entry_fee) {
                $new_deposit_balance = $deposit_balance - $entry_fee;
                $new_winning_balance = $winning_balance;
            } else {
                $remaining_fee = $entry_fee - $deposit_balance;
                $new_deposit_balance = 0.00;
                $new_winning_balance = $winning_balance - $remaining_fee;
            }
            $new_total_balance = $new_deposit_balance + $new_winning_balance;

            $stmt = $conn->prepare("UPDATE users SET wallet_balance = ?, deposit_balance = ?, winning_balance = ? WHERE id = ?");
            $stmt->bind_param("dddi", $new_total_balance, $new_deposit_balance, $new_winning_balance, $user_id);
            if (!$stmt->execute()) throw new Exception("Unable to update wallet balance.");
            $stmt->close();

            $stmt = $conn->prepare("INSERT INTO participants (user_id, tournament_id, in_game_name, ff_uid) VALUES (?, ?, ?, ?)");
            $stmt->bind_param("iiss", $user_id, $tournament_id, $in_game_name, $ff_uid);
            if (!$stmt->execute()) throw new Exception("Unable to join tournament.");
            $stmt->close();

            $desc = "Tournament entry fee - " . $tournament["title"];
            $stmt = $conn->prepare("INSERT INTO transactions (user_id, amount, type, description) VALUES (?, ?, 'debit', ?)");
            $stmt->bind_param("ids", $user_id, $entry_fee, $desc);
            $stmt->execute();
            $stmt->close();

            $conn->commit();
            $message = "You joined the tournament successfully.";
            $message_type = "success";
        } catch (Exception $e) {
            $conn->rollback();
            $message = $e->getMessage();
            $message_type = "error";
        }
    }
}

// SAFE COLUMN CHECKS
try { $conn->query("ALTER TABLE users ADD COLUMN deposit_balance DECIMAL(10,2) DEFAULT 0.00"); } catch (Exception $e) {}
try { $conn->query("ALTER TABLE users ADD COLUMN winning_balance DECIMAL(10,2) DEFAULT 0.00"); } catch (Exception $e) {}
try { $conn->query("ALTER TABLE users ADD COLUMN wallet_balance DECIMAL(10,2) DEFAULT 0.00"); } catch (Exception $e) {}
try { $conn->query("ALTER TABLE users ADD COLUMN account_status VARCHAR(20) DEFAULT 'Active'"); } catch (Exception $e) {}

$stmt = $conn->prepare("SELECT username, wallet_balance, deposit_balance, winning_balance, account_status FROM users WHERE id = ? LIMIT 1");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
$stmt->close();

$joined_tournaments = [];
$stmt = $conn->prepare("SELECT tournament_id FROM participants WHERE user_id = ?");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$res = $stmt->get_result();
while($r = $res->fetch_assoc()) $joined_tournaments[] = $r['tournament_id'];
$stmt->close();

$tournaments = [];
if ($selected_category === "All") {
    $stmt = $conn->prepare("SELECT t.*, COUNT(p.id) as joined_count FROM tournaments t LEFT JOIN participants p ON t.id = p.tournament_id WHERE t.status IN ('Upcoming', 'Live') GROUP BY t.id ORDER BY t.match_time ASC");
} else {
    $stmt = $conn->prepare("SELECT t.*, COUNT(p.id) as joined_count FROM tournaments t LEFT JOIN participants p ON t.id = p.tournament_id WHERE t.status IN ('Upcoming', 'Live') AND t.category = ? GROUP BY t.id ORDER BY t.match_time ASC");
    $stmt->bind_param("s", $selected_category);
}
$stmt->execute();
$result = $stmt->get_result();
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $match_ts = strtotime($row['match_time']);
        $current_ts = time();
        
        if ($row['status'] === 'Upcoming' && $current_ts >= $match_ts) {
            $row['status'] = 'Live';
            $conn->query("UPDATE tournaments SET status = 'Live' WHERE id = " . $row['id']);
        }
        
        if ($row['status'] === 'Live' && $current_ts >= ($match_ts + 2700)) {
            $row['status'] = 'Completed';
            $conn->query("UPDATE tournaments SET status = 'Completed' WHERE id = " . $row['id']);
            continue; 
        }
        
        $tournaments[] = $row;
    }
}
$stmt->close();

$categories = [
    "All", "Full Map", "Full Map Survival", "Lone Wolf 1v1", "Lone Wolf 2v2", 
    "Lone Wolf Headshot 1v1", "Lone Wolf Headshot 2v2", "Clash Squad 1v1", 
    "Clash Squad 2v2", "Clash Squad 4v4", "Clash Squad Headshot 1v1", 
    "Clash Squad Headshot 2v2", "Clash Squad Headshot 4v4"
];
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Battle Arena - Home</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    <style>
        .no-scrollbar::-webkit-scrollbar { display: none; }
        .no-scrollbar { -ms-overflow-style: none; scrollbar-width: none; }
    </style>
</head>
<body class="bg-gray-950 text-white min-h-screen pb-24">

<header class="sticky top-0 z-50 bg-gray-950/95 backdrop-blur border-b border-gray-800">
    <div class="max-w-3xl mx-auto px-4 py-4 flex items-center justify-between">
        <div>
            <p class="text-xs text-gray-500">Welcome back</p>
            <h1 class="text-lg font-bold"><?= htmlspecialchars($user["username"] ?? 'Player') ?></h1>
        </div>
        <div class="bg-gray-900 border border-gray-800 rounded-xl px-3 py-2 flex items-center gap-2">
            <i class="fa-solid fa-wallet text-indigo-400"></i>
            <span class="font-semibold">₹<?= number_format((float)(($user["deposit_balance"] ?? 0) + ($user["winning_balance"] ?? 0)), 2) ?></span>
        </div>
    </div>
</header>

<main class="max-w-3xl mx-auto px-4 py-6">

    <!-- FROZEN BANNER -->
    <?php if (($user['account_status'] ?? 'Active') === 'Frozen'): ?>
        <div class="mb-6 rounded-xl p-4 bg-yellow-950/50 border border-yellow-800 text-yellow-400 flex items-start gap-3 shadow-lg shadow-yellow-900/20">
            <i class="fa-solid fa-lock mt-1 text-lg"></i>
            <div>
                <h3 class="font-bold">Account Frozen</h3>
                <p class="text-sm mt-1">Your account is restricted. You cannot join new tournaments. Please contact support.</p>
            </div>
        </div>
    <?php endif; ?>

    <div class="mb-5">
        <p class="text-indigo-400 text-sm font-medium">TOURNAMENTS</p>
        <h2 class="text-2xl font-bold mt-1">Battle Arena Matches</h2>
        <p class="text-gray-500 text-sm mt-1">Choose a category and join the battle.</p>
    </div>

    <div class="mb-4">
        <div class="relative">
            <span class="absolute inset-y-0 left-0 flex items-center pl-4 pointer-events-none text-gray-500">
                <i class="fa-solid fa-magnifying-glass"></i>
            </span>
            <input type="text" id="tournamentSearch" placeholder="Search tournaments by name..." onkeyup="filterTournaments()" class="w-full bg-gray-900 border border-gray-800 rounded-xl py-3 pl-11 pr-4 text-sm text-white placeholder-gray-500 outline-none focus:border-indigo-500">
        </div>
    </div>

    <div class="flex overflow-x-auto gap-2 pb-3 mb-6 no-scrollbar">
        <?php foreach ($categories as $cat): ?>
            <a href="index.php?category=<?= urlencode($cat) ?>" 
               class="whitespace-nowrap px-4 py-2 rounded-xl text-xs font-semibold transition <?= $selected_category === $cat ? 'bg-indigo-600 text-white shadow-lg shadow-indigo-600/30' : 'bg-gray-900 text-gray-400 border border-gray-800 hover:bg-gray-800' ?>">
                <?= htmlspecialchars($cat) ?>
            </a>
        <?php endforeach; ?>
    </div>

    <?php if ($message !== ""): ?>
        <div class="mb-6 rounded-xl p-4 <?= $message_type === 'success' ? 'bg-green-950/50 border border-green-800 text-green-300' : 'bg-red-950/50 border border-red-800 text-red-300' ?>">
            <div class="flex items-start gap-3">
                <i class="fa-solid <?= $message_type === 'success' ? 'fa-circle-check' : 'fa-circle-exclamation' ?> mt-1"></i>
                <span><?= htmlspecialchars($message) ?></span>
            </div>
        </div>
    <?php endif; ?>

    <?php if (count($tournaments) > 0): ?>
        <div class="space-y-4" id="tournamentList">
            <?php foreach ($tournaments as $tournament): ?>
                <?php
                    $joined = (int)$tournament["joined_count"];
                    $total = (int)($tournament["total_slots"] ?? 48);
                    $percentage = ($total > 0) ? ($joined / $total) * 100 : 0;
                    $is_full = ($joined >= $total);
                    $has_joined = in_array($tournament["id"], $joined_tournaments);
                    
                    $match_ts = strtotime($tournament['match_time']);
                    $reveal_ts = $match_ts - 300; 
                    $current_ts = time();
                    $can_reveal = ($current_ts >= $reveal_ts || $tournament['status'] === 'Live');

                    $room_id_display = !empty($tournament['room_id']) ? htmlspecialchars($tournament['room_id']) : 'Not Set';
                    $room_pass_display = !empty($tournament['room_password']) ? htmlspecialchars($tournament['room_password']) : 'Not Set';
                    $dyn_prizes = !empty($tournament['dynamic_prizes']) ? $tournament['dynamic_prizes'] : '[]';
                ?>
                <div class="tournament-card bg-gray-900 border <?= $tournament['status'] === 'Live' ? 'border-red-900/50 shadow-red-900/10' : ($has_joined ? 'border-indigo-600 shadow-indigo-900/20' : 'border-gray-800') ?> rounded-2xl p-5 shadow-lg"
                     data-title="<?= htmlspecialchars(strtolower($tournament["title"])) ?>">
                    
                    <div class="flex items-start justify-between gap-3">
                        <div>
                            <h3 class="text-lg font-bold"><?= htmlspecialchars($tournament["title"]) ?></h3>
                            <p class="text-gray-500 text-sm mt-1"><i class="fa-solid fa-gamepad mr-1"></i> <?= htmlspecialchars($tournament["game_name"]) ?></p>
                        </div>
                        <?php if ($tournament['status'] === 'Live'): ?>
                            <span class="text-xs bg-red-950 text-red-500 border border-red-800 rounded-full px-3 py-1 font-bold animate-pulse"><i class="fa-solid fa-circle text-[8px] mr-1"></i>LIVE</span>
                        <?php elseif ($has_joined): ?>
                            <span class="text-xs bg-indigo-950 text-indigo-400 border border-indigo-800 rounded-full px-3 py-1 font-bold">Joined</span>
                        <?php else: ?>
                            <span class="text-xs bg-green-950 text-green-400 border border-green-800 rounded-full px-3 py-1">Upcoming</span>
                        <?php endif; ?>
                    </div>

                    <div class="grid grid-cols-2 gap-3 mt-5">
                        <div class="bg-gray-800/60 rounded-xl p-3">
                            <p class="text-xs text-gray-500">Match Time</p>
                            <p class="text-sm font-semibold mt-1"><?= date("d M Y, h:i A", $match_ts) ?></p>
                        </div>
                        <div class="bg-gray-800/60 rounded-xl p-3">
                            <p class="text-xs text-gray-500">Category</p>
                            <p class="text-sm font-semibold mt-1 text-indigo-400"><?= htmlspecialchars($tournament["category"] ?? 'Full Map') ?></p>
                        </div>
                        
                        <div class="bg-gray-800/60 rounded-xl p-3 cursor-pointer hover:bg-gray-700 transition col-span-2"
                             data-prizes="<?= htmlspecialchars($dyn_prizes, ENT_QUOTES, 'UTF-8') ?>"
                             onclick="openPrizeModal(this.getAttribute('data-prizes'))">
                            <div class="flex justify-between items-center">
                                <div>
                                    <p class="text-xs text-gray-500">Prize Pool <i class="fa-solid fa-circle-info ml-1"></i></p>
                                    <p class="text-sm font-semibold mt-1 text-yellow-400">₹<?= number_format((float)$tournament["prize_pool"], 2) ?></p>
                                </div>
                                <i class="fa-solid fa-chevron-right text-gray-600"></i>
                            </div>
                        </div>
                    </div>

                    <?php if ($has_joined): ?>
                        <div class="mt-4 bg-gray-950 rounded-xl border border-gray-800 p-4">
                            <p class="text-[11px] text-center text-gray-400 mb-3 uppercase tracking-wider font-semibold">Match Room Details</p>
                            
                            <?php if ($can_reveal): ?>
                                <div class="flex justify-between items-center bg-gray-900 rounded-lg p-3 border border-gray-800">
                                    <div class="w-1/2 text-center">
                                        <p class="text-[10px] text-gray-500 uppercase">Room ID</p>
                                        <p class="text-sm font-mono font-bold text-white tracking-wider mt-0.5"><?= $room_id_display ?></p>
                                    </div>
                                    <div class="w-px h-8 bg-gray-800"></div>
                                    <div class="w-1/2 text-center">
                                        <p class="text-[10px] text-gray-500 uppercase">Password</p>
                                        <p class="text-sm font-mono font-bold text-white tracking-wider mt-0.5"><?= $room_pass_display ?></p>
                                    </div>
                                </div>
                            <?php else: ?>
                                <div class="text-center py-2.5 px-3 text-xs text-amber-400 bg-amber-950/30 border border-amber-900/50 rounded-lg">
                                    <i class="fa-solid fa-clock mr-1.5"></i> Room ID & Password unlock automatically at <strong><?= date("h:i A", $reveal_ts) ?></strong> (5 mins before match).
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>

                    <div class="flex items-center justify-between mt-5 bg-gray-950 p-3.5 rounded-xl border border-gray-800">
                        <div class="flex-1 mr-4">
                            <p class="font-bold text-lg mb-1.5"><?= $joined ?>/<?= $total ?></p>
                            <div class="w-full bg-gray-800 h-1.5 rounded-full overflow-hidden">
                                <div class="bg-blue-500 h-full rounded-full transition-all duration-500" style="width: <?= min(100, $percentage) ?>%;"></div>
                            </div>
                        </div>

                        <div>
                            <?php if ($tournament['status'] === 'Live'): ?>
                                <button type="button" disabled class="bg-gray-800 text-gray-500 cursor-not-allowed rounded-xl px-5 py-3 font-bold text-sm">STARTED</button>
                            <?php elseif ($has_joined): ?>
                                <button type="button" disabled class="bg-indigo-900/50 text-indigo-300 border border-indigo-800/50 rounded-xl px-5 py-3 font-bold text-sm">JOINED</button>
                            <?php elseif ($is_full): ?>
                                <button type="button" disabled class="bg-gray-800 text-gray-500 rounded-xl px-5 py-3 font-bold text-sm">FULL</button>
                            <!-- FROZEN JOIN LOCK -->
                            <?php elseif (($user['account_status'] ?? 'Active') === 'Frozen'): ?>
                                <button type="button" onclick="alert('Your account is frozen. Joining matches is disabled.')" class="bg-gray-800 text-gray-500 rounded-xl px-5 py-3 font-bold text-sm cursor-not-allowed border border-gray-700">
                                    <i class="fa-solid fa-lock mr-1 text-gray-600"></i> LOCKED
                                </button>
                            <?php else: ?>
                                <button type="button" onclick="openRulesModal('<?= htmlspecialchars($tournament['category'] ?? 'Full Map') ?>', <?= (int)$tournament['id'] ?>, <?= (float)$tournament['entry_fee'] ?>)" 
                                        class="bg-indigo-600 hover:bg-indigo-500 rounded-xl px-5 py-3 font-bold text-sm flex items-center transition">
                                    <i class="fa-solid fa-coins text-yellow-400 mr-2"></i>
                                    <?= (float)$tournament['entry_fee'] ?> JOIN
                                </button>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php else: ?>
        <div class="bg-gray-900 border border-gray-800 rounded-2xl p-8 text-center">
            <div class="w-16 h-16 mx-auto mb-4 rounded-2xl bg-gray-800 flex items-center justify-center">
                <i class="fa-solid fa-trophy text-2xl text-gray-600"></i>
            </div>
            <h3 class="font-semibold text-lg">No Tournaments Found</h3>
            <p class="text-gray-500 text-sm mt-2">No active matches found for this category.</p>
        </div>
    <?php endif; ?>
</main>

<nav class="fixed bottom-0 left-0 right-0 z-50 bg-gray-900 border-t border-gray-800">
    <div class="max-w-3xl mx-auto grid grid-cols-4">
        <a href="index.php" class="py-3 text-center text-indigo-400"><i class="fa-solid fa-house block text-lg"></i><span class="text-[11px]">Home</span></a>
        <a href="my_tournaments.php" class="py-3 text-center text-gray-500 hover:text-white"><i class="fa-solid fa-trophy block text-lg"></i><span class="text-[11px]">Tournaments</span></a>
        <a href="wallet.php" class="py-3 text-center text-gray-500 hover:text-white"><i class="fa-solid fa-wallet block text-lg"></i><span class="text-[11px]">Wallet</span></a>
        <a href="profile.php" class="py-3 text-center text-gray-500 hover:text-white"><i class="fa-solid fa-user block text-lg"></i><span class="text-[11px]">Profile</span></a>
    </div>
</nav>

<!-- PRIZE MODAL -->
<div id="prizeModal" class="fixed inset-0 z-50 bg-black/80 hidden items-center justify-center px-4 backdrop-blur-sm">
    <div class="bg-gray-900 border border-gray-800 rounded-2xl p-6 w-full max-w-sm relative">
        <button onclick="closeModals()" class="absolute top-4 right-4 text-gray-500 hover:text-white"><i class="fa-solid fa-xmark text-xl"></i></button>
        <h3 class="text-xl font-bold text-white mb-4">Position Prizes</h3>
        <div class="space-y-3" id="prizeList"></div>
        <button onclick="closeModals()" class="w-full mt-6 bg-gray-800 text-white rounded-xl py-3 font-semibold">Close</button>
    </div>
</div>

<!-- MATCH RULES MODAL -->
<div id="rulesModal" class="fixed inset-0 z-50 bg-black/80 hidden items-center justify-center px-4 backdrop-blur-sm">
    <div class="bg-gray-900 border border-gray-800 rounded-2xl w-full max-w-md relative flex flex-col max-h-[85vh]">
        <button onclick="closeModals()" class="absolute top-4 right-4 text-gray-500 hover:text-white"><i class="fa-solid fa-xmark text-xl"></i></button>
        <div class="p-6 border-b border-gray-800">
            <p class="text-indigo-400 text-xs font-semibold uppercase" id="rulesCategory">Category</p>
            <h3 class="text-xl font-bold text-white mt-1">Match Rules</h3>
        </div>
        <div class="p-6 overflow-y-auto space-y-4 text-sm text-gray-300" id="rulesList"></div>
        <div class="p-6 border-t border-gray-800 bg-gray-950/50 rounded-b-2xl">
            <button onclick="openRegistrationModal()" class="w-full bg-indigo-600 hover:bg-indigo-500 text-white rounded-xl py-3.5 font-semibold transition flex items-center justify-center gap-2">Accept & Next <i class="fa-solid fa-arrow-right"></i></button>
        </div>
    </div>
</div>

<!-- REGISTRATION MODAL -->
<div id="registrationModal" class="fixed inset-0 z-50 bg-black/80 hidden items-center justify-center px-4 backdrop-blur-sm">
    <div class="bg-gray-900 border border-gray-800 rounded-2xl p-6 w-full max-w-sm relative">
        <button onclick="closeModals()" class="absolute top-4 right-4 text-gray-500 hover:text-white"><i class="fa-solid fa-xmark text-xl"></i></button>
        <h3 class="text-xl font-bold text-white mb-1">Enter Game Details</h3>
        <p class="text-xs text-gray-500 mb-6">Please provide your exact Free Fire details.</p>
        <form onsubmit="proceedToBill(event)" class="space-y-4">
            <div>
                <label class="block text-sm text-gray-300 mb-2">In-Game Name</label>
                <input type="text" id="inputIgn" placeholder="E.g., PRO_GAMER_99" required class="w-full bg-gray-800 border border-gray-700 rounded-xl py-3 px-4 text-white">
            </div>
            <div>
                <label class="block text-sm text-gray-300 mb-2">Free Fire UID</label>
                <input type="number" id="inputUid" placeholder="E.g., 1234567890" required class="w-full bg-gray-800 border border-gray-700 rounded-xl py-3 px-4 text-white">
            </div>
            <button type="submit" class="w-full mt-2 bg-indigo-600 hover:bg-indigo-500 text-white rounded-xl py-3.5 font-semibold transition">Continue to Bill</button>
        </form>
    </div>
</div>

<!-- BILL SUMMARY MODAL -->
<div id="billModal" class="fixed inset-0 z-50 bg-black/80 hidden items-center justify-center px-4 backdrop-blur-sm">
    <div class="bg-gray-900 border border-gray-800 rounded-2xl p-6 w-full max-w-sm relative">
        <button onclick="closeModals()" class="absolute top-4 right-4 text-gray-500 hover:text-white"><i class="fa-solid fa-xmark text-xl"></i></button>
        <h3 class="text-xl font-bold text-white mb-1">Payment Summary</h3>
        <p class="text-xs text-gray-500 mb-6">Review your entry fee details before confirming.</p>
        
        <div class="space-y-4 bg-gray-950 p-4 rounded-xl border border-gray-800 mb-6 text-sm">
            <div class="flex justify-between items-center">
                <span class="text-gray-400">Current Balance</span>
                <span class="font-bold text-yellow-400" id="billCurrentBalance">₹0.00</span>
            </div>
            <div class="flex justify-between items-center">
                <span class="text-gray-400">Match Entry Fee</span>
                <span class="font-bold text-white" id="billEntryFee">₹0.00</span>
            </div>
            <div class="border-t border-gray-800 pt-3 flex justify-between items-center">
                <span class="font-semibold text-white">Total Payable</span>
                <span class="font-bold text-green-400 text-lg" id="billTotalPayable">₹0.00</span>
            </div>
        </div>

        <form method="POST" action="">
            <input type="hidden" name="join_tournament" value="1">
            <input type="hidden" name="tournament_id" id="billTournamentId" value="">
            <input type="hidden" name="in_game_name" id="billIgn" value="">
            <input type="hidden" name="ff_uid" id="billUid" value="">
            <button type="submit" class="w-full bg-green-600 hover:bg-green-500 text-white rounded-xl py-3.5 font-semibold transition shadow-lg">Confirm & Join</button>
        </form>
    </div>
</div>

<!-- GLOBAL RULES POPUP (Shows on App Open / Resume) -->
<div id="globalRulesPopup" class="fixed inset-0 z-[100] bg-black/90 hidden items-center justify-center px-4 backdrop-blur-sm">
    <div class="bg-gray-900 border border-gray-800 rounded-3xl p-6 w-full max-w-sm relative shadow-2xl">
        
        <!-- CROSS BUTTON -->
        <button onclick="closeGlobalRules()" class="absolute top-4 left-4 w-9 h-9 flex items-center justify-center bg-gray-800 border border-gray-700 text-gray-400 hover:text-white hover:bg-red-500/20 rounded-full transition z-10">
            <i class="fa-solid fa-xmark text-lg"></i>
        </button>

        <div class="text-center mt-2 mb-6">
            <div class="w-14 h-14 bg-indigo-950 text-indigo-400 rounded-2xl flex items-center justify-center mx-auto mb-3 border border-indigo-900">
                <i class="fa-solid fa-file-shield text-2xl"></i>
            </div>
            <h3 class="text-2xl font-bold text-white">Important Rules</h3>
            <p class="text-[11px] text-gray-500 mt-1 uppercase tracking-widest font-bold">Please read before playing</p>
        </div>

        <div class="space-y-3 max-h-[55vh] overflow-y-auto pb-2 text-sm text-gray-300 no-scrollbar">
            
            <div class="bg-gray-950 p-3.5 rounded-xl border border-gray-800 flex gap-3">
                <i class="fa-solid fa-wallet text-indigo-400 mt-1 shrink-0"></i>
                <p><strong class="text-white block mb-0.5">Withdrawal Processing:</strong> Your withdrawal request will be processed within one hour of submission.</p>
            </div>
            
            <div class="bg-gray-950 p-3.5 rounded-xl border border-gray-800 flex gap-3">
                <i class="fa-solid fa-video text-indigo-400 mt-1 shrink-0"></i>
                <div>
                    <p><strong class="text-white block mb-0.5">Lobby Recording:</strong> Lobby recording is mandatory from the moment you join the custom room until the match begins.</p>
                    <p class="text-[11px] text-gray-500 mt-1.5 font-medium leading-relaxed italic border-l-2 border-gray-700 pl-2">"Custom room join karne se lekar match start hone tak screen recording rakhna zaroori hai."</p>
                </div>
            </div>
            
            <div class="bg-gray-950 p-3.5 rounded-xl border border-gray-800 flex gap-3">
                <i class="fa-solid fa-gavel text-indigo-400 mt-1 shrink-0"></i>
                <p><strong class="text-white block mb-0.5">Cheating & Refunds:</strong> If an opponent defeats you by breaking the rules or using hacks, your screen recording will serve as the sole proof required to review the match and issue a refund.</p>
            </div>
            
            <div class="bg-gray-950 p-3.5 rounded-xl border border-gray-800 flex gap-3">
                <i class="fa-solid fa-headset text-indigo-400 mt-1 shrink-0"></i>
                <p><strong class="text-white block mb-0.5">Customer Support:</strong> You can raise a complaint through the Customer Service feature, located in your profile section.</p>
            </div>

        </div>
        
        <button onclick="closeGlobalRules()" class="w-full mt-5 bg-indigo-600 hover:bg-indigo-500 text-white rounded-xl py-3.5 font-bold transition shadow-lg shadow-indigo-900/20">I Understand</button>
    </div>
</div>

<script>
const userWalletBalance = <?= (float)(($user["deposit_balance"] ?? 0) + ($user["winning_balance"] ?? 0)) ?>;
let selectedTournamentId = null;
let selectedEntryFee = 0;

const tournamentRules = {
    "Full Map": [
        "Lobby Recording: Custom Room Join Karne Se Match Start Hone Tak Recording Rakhna Zaroori Hai.",
        "Min Level 40 | Hs <70% (Cs Career) | No Emulator / Pc/OTG (Keyboard & Mouse).",
        "No UID in Match Registration.",
        "Room Id and Password: 2-5 Minute Before Match Time | Check Id & password From App | No Waiting Time | Match Starts On Time. Max Wait: 5 Min Extra.",
        "Esports Mode | Revival: Off | Character Skill: On | Gun Attributes: OFF | Vehicle: On.",
        "Bans( )- Horse Ban | Double Vector Ban.",
        "Allowed()- Sniper, M79, Single Vector, Ryden Character = Allowed.",
        "Missed Match = No Refund | Team - Up / Abuse / Rule Break / Invite Unregistered Player = No Prize + Penalty.",
        "Pov Recordings Mandatory (Replay Not Accepted).",
        "Hack/Panel / Blacklisted Id = Direct Ban (No Excuse).",
        "Killed by Hacker = Contact Customer Support With Full Proof.",
        "Duo/Squad Entry: Slot Management is Your Responsibility.",
        "Raise Issue With Customer Support Within 2 Hours.",
        "Clash 2.0 Reserves The Right To Modify Time, Rules & Prizes."
    ],
    "Full Map Survival": [
        "Lobby Recording: Custom Room Join Karne Se Match Start Hone Tak Recording Rakhna Zaroori Hai.",
        "Min Level 40 | Hs <70% (Cs Career) | No Emulator / Pc / OTG (Keyboard & Mouse).",
        "No UID in Match Registration.",
        "Room Id and Password: 2-5 Minute Before Match Time | Check Id & password From App | No Waiting Time | Match Starts On Time. Max Wait: 5 Min Extra.",
        "Esports Mode | Revival: Off | Character Skill: On | Gun Attributes: OFF | Vehicle: On | Precise Aim: On | Headshot: Off | Moving Safe Zone: Off.",
        "Bans( )- Horse, Sniper, Double Vector, M79, Ryden = Banned.",
        "Allowed()- Single Vector = Allowed.",
        "Missed Match = No Refund | Team - Up / Abuse / Rule Break / Invite Unregistered Player = No Prize + Penalty.",
        "Pov Recordings Mandatory (Replay Not Accepted).",
        "Hack/Panel / Blacklisted Id = Direct Ban (No Excuse).",
        "Custom Room Full / Kicked By Host = Record Full Proof and Customer Support For Full Refund.",
        "Killed by Hacker = Contact Customer Support With Full Proof.",
        "Raise Issue With Customer Support Within 2 Hours.",
        "Clash 2.0 Reserves The Right To Modify Time, Rules & Prizes."
    ],
    "Lone Wolf 1v1": [
        "Min Level 40 | Hs <70% (Cs Career) | No Emulator / PC/OTG (Keyboard & Mouse).",
        "No Uid In Match Registration.",
        "Room Id and Password: 2-5 Minute Before Match Time | Check Id & password From App | No Waiting Time | Match Starts On Time. Max Wait: 5 Min Extra.",
        "Character Skill: ON | Gun Attributes: OFF.",
        "BANS ( )- Ryden Ban.",
        "Allowed ( )- Double Vector Allowed | Height, Zone Pack & Heal Battle Allowed.",
        "If The Room Is Incorrect, Exit in First Round. No Refunds For Completed Matches.",
        "Missed Match = No Refund | Rule Break / Abuse = No Prize + Penalty.",
        "Pov Recordings Mandatory (Replay Not Accepted).",
        "Hack / Panel / Blacklisted I'd = Direct Ban (No Excuse).",
        "Match Limit: Maximum 10 Matches Per Day | Only 2 Continuous Matches Allowed, Then 1 Match Gap Required | Cancelled / Unplayed Matches Will Also Be Counted | Rule Break = 50 Coins Penalty (No Warning).",
        "Raise Issues With Customer Support Within 1 Hour.",
        "Clash 2.0 Reserves The Right To Modify Match Time, Rules & Prizes."
    ],
    "Lone Wolf 2v2": [
        "Min Level 40 | Hs <70% (Cs Career) | No Emulator / PC / OTG (Keyboard & Mouse).",
        "No Uid In Match Registration.",
        "Room Id and Password: 2-5 Minute Before Match Time | Check Id & password From App | No Waiting Time | Match Starts On Time. Max Wait: 5 Min Extra | Duo Slot = Both Players Must Join Together |",
        "Character Skill: ON | Gun Attributes: OFF.",
        "BANS ( )-Ryden Ban.",
        "Allowed ( )- Double Vector Allowed | Height, Zone Pack & Heal Battle Allowed.",
        "If The Room Is Incorrect, Exit in First Round. No Refunds For Completed Matches.",
        "Missed Match = No Refund | Rule Break / Abuse = No Prize + Penalty.",
        "Pov Recordings Mandatory (Replay Not Accepted).",
        "Hack / Panel / Blacklisted I'd = Direct Ban (No Excuse).",
        "Match Limit: Maximum 10 Matches Per Day | Only 2 Continuous Matches Allowed, Then 1 Match Gap Required. | Cancelled / Unplayed Matches Will Also Be Counted | Rule Break = 50 Coins Penalty (No Warning).",
        "Raise Issues With Customer Support Within 1 Hour.",
        "Clash 2.0 Reserves The Right To Modify Match Time, Rules & Prizes."
    ],
    "Clash Squad 1v1": [
        "Min Level 40 | Hs <70% (Cs Career) | No Emulator / PC / OTG (Keyboard & Mouse).",
        "No UID In Match Registration.",
        "Room Id and Password: 2-5 Minute Before Match Time | Check Id & password From App | No Waiting Time | Match Starts On Time. Max Wait: 5 Min Extra.",
        "Random Store | 7 Rounds (Total) | 9950 Coins | Character Skill: ON | Gun Attributes: OFF | Unlimited Ammo & Gloo Wall.",
        "BANS (X)- Orion Ban | Double Vector Ban | Self Zone Pack & Heal Battle Ban | Force Zone Pack to Opponent Ban | Height Ban | Throwables: Only Gloo Wall & Scanner Allowed; All Others Banned.",
        "Allowed ( )- Container & Minor Height Allowed | Max 3 Gloo Wall Height Allowed.",
        "If The Room Is Incorrect, Exit in First Round. No Refunds For Completed Matches.",
        "Missed Match = No Refund | Rule Break / Abuse = No Prize + Penalty.",
        "POV Recordings Mandatory (Replay Not Accepted).",
        "Hack / Panel / Blacklisted I'd = Direct Ban (No Excuse).",
        "Match Limit: Maximum 10 Matches Per Day | Only 2 Continuous Matches Allowed, Then 1 Match Gap Required | Cancelled / Unplayed Matches Will Also Be Counted | Rule Break = 50 Coins Penalty (No Warning).",
        "Raise Issue With Customer Support Within 1 Hour.",
        "Clash 2.0 Reserves The Right To Modify Time, Rules & Prizes."
    ],
    "Clash Squad 2v2": [
        "Min Level 40 | Hs <70% (Cs Career) | No Emulator / PC / OTG (Keyboard & Mouse).",
        "No UID In Match Registration.",
        "Room Id and Password: 2-5 Minute Before Match Time | Check Id & password From App | No Waiting Time | Match Starts On Time. Max Wait: 5 Min Extra.",
        "Random Store | 7 Rounds (Total) | 9950 Coins | Character Skill: ON | Gun Attributes: OFF | Unlimited Ammo & Gloo Wall.",
        "BANS (X)- Orion Ban | Double Vector Ban | Self Zone Pack & Heal Battle Ban | Force Zone Pack to Opponent Ban | Height Ban | Throwables: Only Gloo Wall & Scanner Allowed; All Others Banned.",
        "Allowed ( )- Container & Minor Height Allowed | Max 3 Gloo Wall Height Allowed.",
        "If The Room Is Incorrect, Exit in First Round. No Refunds For Completed Matches.",
        "Missed Match = No Refund | Rule Break / Abuse = No Prize + Penalty.",
        "POV Recordings Mandatory (Replay Not Accepted).",
        "Hack / Panel / Blacklisted I'd = Direct Ban (No Excuse).",
        "Match Limit: Maximum 10 Matches Per Day | Only 2 Continuous Matches Allowed, Then 1 Match Gap Required | Cancelled / Unplayed Matches Will Also Be Counted | Rule Break = 50 Coins Penalty (No Warning).",
        "Raise Issue With Customer Support Within 1 Hour.",
        "Clash 2.0 Reserves The Right To Modify Time, Rules & Prizes."
    ],
    "Clash Squad 4v4": [
        "Min Level 40 | Hs <70% (Cs Career) | No Emulator / PC / OTG (Keyboard & Mouse).",
        "No UID In Match Registration.",
        "Room Id and Password: 2-5 Minute Before Match Time | Check Id & password From App | No Waiting Time | Match Starts On Time. Max Wait: 5 Min Extra.",
        "Random Store | 7 Rounds (Total) | 9950 Coins | Character Skill: ON | Gun Attributes: OFF | Unlimited Ammo & Gloo Wall.",
        "BANS (X)- Orion Ban | Double Vector Ban | Self Zone Pack & Heal Battle Ban | Force Zone Pack to Opponent Ban | Height Ban | Throwables: Only Gloo Wall & Scanner Allowed; All Others Banned.",
        "Allowed ( )- Container & Minor Height Allowed | Max 3 Gloo Wall Height Allowed.",
        "If The Room Is Incorrect, Exit in First Round. No Refunds For Completed Matches.",
        "Missed Match = No Refund | Rule Break / Abuse = No Prize + Penalty.",
        "POV Recordings Mandatory (Replay Not Accepted).",
        "Hack / Panel / Blacklisted I'd = Direct Ban (No Excuse).",
        "Match Limit: Maximum 10 Matches Per Day | Only 2 Continuous Matches Allowed, Then 1 Match Gap Required | Cancelled / Unplayed Matches Will Also Be Counted | Rule Break = 50 Coins Penalty (No Warning).",
        "Raise Issue With Customer Support Within 1 Hour.",
        "Clash 2.0 Reserves The Right To Modify Time, Rules & Prizes."
    ]
};

tournamentRules["Full Map Survival"] = tournamentRules["Survival"] || tournamentRules["Full Map"];
tournamentRules["Lone Wolf Headshot 1v1"] = tournamentRules["Lone Wolf 1v1"];
tournamentRules["Lone Wolf Headshot 2v2"] = tournamentRules["Lone Wolf 2v2"];
tournamentRules["Clash Squad Headshot 1v1"] = tournamentRules["Clash Squad 1v1"];
tournamentRules["Clash Squad Headshot 2v2"] = tournamentRules["Clash Squad 2v2"];
tournamentRules["Clash Squad Headshot 4v4"] = tournamentRules["Clash Squad 4v4"];

function closeModals() {
    document.getElementById('prizeModal').classList.replace('flex', 'hidden');
    document.getElementById('rulesModal').classList.replace('flex', 'hidden');
    document.getElementById('registrationModal').classList.replace('flex', 'hidden');
    document.getElementById('billModal').classList.replace('flex', 'hidden');
}

function openPrizeModal(prizesJson) {
    const list = document.getElementById('prizeList');
    list.innerHTML = ''; 
    try {
        const prizesArray = JSON.parse(prizesJson);
        if (Array.isArray(prizesArray) && prizesArray.length > 0) {
            prizesArray.forEach(prize => {
                list.innerHTML += `<div class="flex justify-between items-center bg-gray-950 p-3 rounded-xl border border-gray-800"><span class="text-gray-400 font-medium">${prize.pos}</span><span class="text-indigo-400 font-bold">₹${parseFloat(prize.amt).toFixed(2)}</span></div>`;
            });
        } else {
            list.innerHTML = '<p class="text-gray-500 text-center">No position prizes mapped.</p>';
        }
    } catch(e) {
        list.innerHTML = '<p class="text-red-500 text-center">Error loading prizes.</p>';
    }
    document.getElementById('prizeModal').classList.replace('hidden', 'flex');
}

function openRulesModal(categoryId, tournamentId, entryFee) {
    selectedTournamentId = tournamentId;
    selectedEntryFee = entryFee;
    document.getElementById('rulesCategory').innerText = categoryId;
    const rulesList = document.getElementById('rulesList');
    rulesList.innerHTML = ''; 
    const rulesArray = tournamentRules[categoryId] || tournamentRules["Full Map"];
    rulesArray.forEach((rule, index) => {
        rulesList.innerHTML += `<div class="flex gap-3"><span class="text-indigo-500 font-bold">${index + 1}.</span><p>${rule}</p></div>`;
    });
    document.getElementById('rulesModal').classList.replace('hidden', 'flex');
}

function openRegistrationModal() {
    closeModals();
    document.getElementById('registrationModal').classList.replace('hidden', 'flex');
}

function proceedToBill(event) {
    event.preventDefault();
    const ign = document.getElementById('inputIgn').value.trim();
    const uid = document.getElementById('inputUid').value.trim();
    if(!ign || !uid) {
        alert('Please fill in your in-game name and UID.');
        return;
    }

    document.getElementById('billTournamentId').value = selectedTournamentId;
    document.getElementById('billIgn').value = ign;
    document.getElementById('billUid').value = uid;

    document.getElementById('billCurrentBalance').innerText = '₹' + userWalletBalance.toFixed(2);
    document.getElementById('billEntryFee').innerText = '₹' + selectedEntryFee.toFixed(2);
    document.getElementById('billTotalPayable').innerText = '₹' + selectedEntryFee.toFixed(2);

    closeModals();
    document.getElementById('billModal').classList.replace('hidden', 'flex');
}

function filterTournaments() {
    const searchVal = document.getElementById('tournamentSearch').value.toLowerCase();
    const cards = document.querySelectorAll('.tournament-card');
    
    cards.forEach(card => {
        const title = card.getAttribute('data-title');
        if (title.includes(searchVal)) {
            card.style.display = 'block';
        } else {
            card.style.display = 'none';
        }
    });
}

// =========================================================
// SMART RULES POPUP LOGIC
// =========================================================
document.addEventListener("DOMContentLoaded", function() {
    const rulesPopup = document.getElementById('globalRulesPopup');

    function checkAndShowPopup() {
        if (!sessionStorage.getItem('rulesRead')) {
            rulesPopup.classList.remove('hidden');
            rulesPopup.classList.add('flex');
        }
    }

    // Check immediately on page load
    checkAndShowPopup();

    document.addEventListener("visibilitychange", function() {
        // ONLY clear memory if the app transitions to "visible" on this exact page
        // This stops normal page navigation from triggering the popup reset
        if (document.visibilityState === 'visible') {
            sessionStorage.removeItem('rulesRead');
            checkAndShowPopup();
        }
    });
});

function closeGlobalRules() {
    const rulesPopup = document.getElementById('globalRulesPopup');
    rulesPopup.classList.add('hidden');
    rulesPopup.classList.remove('flex');
    sessionStorage.setItem('rulesRead', 'true');
}
</script>
</body>
</html>
