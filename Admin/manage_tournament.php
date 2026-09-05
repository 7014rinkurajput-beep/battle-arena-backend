<?php
// Turn on error reporting to catch bugs
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once "../Common/config.php";
require_admin_login();

$page_title = "Manage Tournament";
$message = "";
$error = "";

// AUTO-ADD COLUMNS IF MISSING
$chk_prize = $conn->query("SHOW COLUMNS FROM tournaments LIKE 'dynamic_prizes'");
if ($chk_prize && $chk_prize->num_rows === 0) {
    $conn->query("ALTER TABLE tournaments ADD COLUMN dynamic_prizes TEXT NULL");
}
$chk_cat = $conn->query("SHOW COLUMNS FROM tournaments LIKE 'category'");
if ($chk_cat && $chk_cat->num_rows === 0) {
    $conn->query("ALTER TABLE tournaments ADD COLUMN category VARCHAR(100) DEFAULT 'Full Map' AFTER game_name");
}
$chk_p_kills = $conn->query("SHOW COLUMNS FROM participants LIKE 'kills'");
if ($chk_p_kills && $chk_p_kills->num_rows === 0) {
    $conn->query("ALTER TABLE participants ADD COLUMN kills INT DEFAULT 0");
}
$chk_p_win = $conn->query("SHOW COLUMNS FROM participants LIKE 'winning'");
if ($chk_p_win && $chk_p_win->num_rows === 0) {
    $conn->query("ALTER TABLE participants ADD COLUMN winning DECIMAL(10,2) DEFAULT 0.00");
}
$chk_u_dep = $conn->query("SHOW COLUMNS FROM users LIKE 'deposit_balance'");
if ($chk_u_dep && $chk_u_dep->num_rows === 0) {
    $conn->query("ALTER TABLE users ADD COLUMN deposit_balance DECIMAL(10,2) DEFAULT 0.00");
}
$chk_u_win = $conn->query("SHOW COLUMNS FROM users LIKE 'winning_balance'");
if ($chk_u_win && $chk_u_win->num_rows === 0) {
    $conn->query("ALTER TABLE users ADD COLUMN winning_balance DECIMAL(10,2) DEFAULT 0.00");
}

// =====================================================
// GET TOURNAMENT ID
// =====================================================
$tournament_id = filter_input(INPUT_GET, "id", FILTER_VALIDATE_INT);
if (!$tournament_id) {
    header("Location: tournaments.php");
    exit;
}

// =====================================================
// HANDLE FORM SUBMISSIONS
// =====================================================
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $action = $_POST["action"] ?? "";

    if ($action === "room_update") {
        $room_id = trim($_POST["room_id"] ?? "");
        $room_password = trim($_POST["room_password"] ?? "");

        $stmt = $conn->prepare("UPDATE tournaments SET room_id = ?, room_password = ? WHERE id = ?");
        if ($stmt) {
            $stmt->bind_param("ssi", $room_id, $room_password, $tournament_id);
            if ($stmt->execute()) {
                $message = "Room details updated successfully.";
            } else {
                $error = "Unable to update room details.";
            }
            $stmt->close();
        }
    }
    elseif ($action === "category_update") {
        $category = trim($_POST["category"] ?? "Full Map");
        $stmt = $conn->prepare("UPDATE tournaments SET category = ? WHERE id = ?");
        if ($stmt) {
            $stmt->bind_param("si", $category, $tournament_id);
            if ($stmt->execute()) {
                $message = "Tournament category updated successfully.";
            } else {
                $error = "Unable to update category.";
            }
            $stmt->close();
        }
    }
    elseif ($action === "prizes_update") {
        $positions = $_POST['positions'] ?? [];
        $amounts = $_POST['amounts'] ?? [];
        $prizes = [];
        
        for ($i = 0; $i < count($positions); $i++) {
            if (trim($positions[$i]) !== "" && trim($amounts[$i]) !== "") {
                $prizes[] = [
                    "pos" => trim($positions[$i]),
                    "amt" => (float)trim($amounts[$i])
                ];
            }
        }
        $json_prizes = json_encode($prizes);

        $stmt = $conn->prepare("UPDATE tournaments SET dynamic_prizes = ? WHERE id = ?");
        if ($stmt) {
            $stmt->bind_param("si", $json_prizes, $tournament_id);
            if ($stmt->execute()) {
                $message = "Custom prizes updated successfully.";
            } else {
                $error = "Unable to update prizes.";
            }
            $stmt->close();
        }
    }
    elseif ($action === "status_update") {
        $status = trim($_POST["status"] ?? "");
        $allowed_statuses = ["Upcoming", "Live", "Completed"];

        if (in_array($status, $allowed_statuses, true)) {
            $stmt = $conn->prepare("UPDATE tournaments SET status = ? WHERE id = ?");
            if ($stmt) {
                $stmt->bind_param("si", $status, $tournament_id);
                if ($stmt->execute()) {
                    $message = "Tournament status updated.";
                } else {
                    $error = "Unable to update status.";
                }
                $stmt->close();
            }
        }
    }
    elseif ($action === "save_results") {
        $participant_ids = $_POST['participant_id'] ?? [];
        $kills_arr = $_POST['kills'] ?? [];
        $winning_arr = $_POST['winning'] ?? [];

        $conn->begin_transaction();
        try {
            $t_stmt = $conn->prepare("SELECT title FROM tournaments WHERE id = ? LIMIT 1");
            $t_stmt->bind_param("i", $tournament_id);
            $t_stmt->execute();
            $t_res = $t_stmt->get_result()->fetch_assoc();
            $t_stmt->close();
            $tourn_title = $t_res["title"] ?? "Tournament";

            for ($i = 0; $i < count($participant_ids); $i++) {
                $p_id = intval($participant_ids[$i]);
                $kills = intval($kills_arr[$i] ?? 0);
                $new_winning = max(0, floatval($winning_arr[$i] ?? 0));

                if ($p_id <= 0) continue;

                $p_query = $conn->prepare("SELECT user_id, winning FROM participants WHERE id = ? AND tournament_id = ? LIMIT 1 FOR UPDATE");
                $p_query->bind_param("ii", $p_id, $tournament_id);
                $p_query->execute();
                $p_data = $p_query->get_result()->fetch_assoc();
                $p_query->close();

                if (!$p_data) continue;

                $target_user_id = intval($p_data["user_id"]);
                $old_winning = floatval($p_data["winning"]);
                $diff = $new_winning - $old_winning;

                $up_part = $conn->prepare("UPDATE participants SET kills = ?, winning = ? WHERE id = ?");
                $up_part->bind_param("idi", $kills, $new_winning, $p_id);
                $up_part->execute();
                $up_part->close();

                if ($diff != 0) {
                    $u_query = $conn->prepare("SELECT deposit_balance, winning_balance FROM users WHERE id = ? LIMIT 1 FOR UPDATE");
                    $u_query->bind_param("i", $target_user_id);
                    $u_query->execute();
                    $u_user = $u_query->get_result()->fetch_assoc();
                    $u_query->close();

                    if ($u_user) {
                        $dep_bal = floatval($u_user["deposit_balance"]);
                        $win_bal = floatval($u_user["winning_balance"]) + $diff;
                        if ($win_bal < 0) $win_bal = 0.00;
                        $tot_wallet = $dep_bal + $win_bal;

                        $up_user = $conn->prepare("UPDATE users SET winning_balance = ?, wallet_balance = ? WHERE id = ?");
                        $up_user->bind_param("ddi", $win_bal, $tot_wallet, $target_user_id);
                        $up_user->execute();
                        $up_user->close();

                        if ($diff > 0) {
                            $desc = "Tournament Winning - " . $tourn_title;
                            $tr_stmt = $conn->prepare("INSERT INTO transactions (user_id, amount, type, description) VALUES (?, ?, 'credit', ?)");
                            $tr_stmt->bind_param("ids", $target_user_id, $diff, $desc);
                            $tr_stmt->execute();
                            $tr_stmt->close();
                        }
                    }
                }
            }

            $conn->commit();
            $message = "Match results saved and winnings distributed successfully.";
        } catch (Exception $e) {
            $conn->rollback();
            $error = "Error saving results: " . $e->getMessage();
        }
    }
}

// =====================================================
// GET TOURNAMENT
// =====================================================
$stmt = $conn->prepare(
    "SELECT id, title, game_name, category, entry_fee, prize_pool, match_time, room_id, room_password, status, dynamic_prizes, created_at 
     FROM tournaments WHERE id = ? LIMIT 1"
);
$stmt->bind_param("i", $tournament_id);
$stmt->execute();
$result = $stmt->get_result();
$tournament = $result->fetch_assoc();
$stmt->close();

if (!$tournament) {
    header("Location: tournaments.php");
    exit;
}

// =====================================================
// GET PARTICIPANTS
// =====================================================
$participants = [];
$query = "SELECT p.id, p.user_id, p.in_game_name, p.kills, p.winning, u.username, u.email, u.mobile 
          FROM participants p INNER JOIN users u ON u.id = p.user_id 
          WHERE p.tournament_id = ? ORDER BY p.winning DESC, p.kills DESC, p.id ASC";
          
$stmt = $conn->prepare($query);

if (!$stmt) {
    die("<div style='background:#7f1d1d; color:#fca5a5; padding:20px; font-family:sans-serif; margin:20px; border-radius:10px;'><strong>Database Query Error:</strong> " . $conn->error . "</div>");
}

$stmt->bind_param("i", $tournament_id);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $participants[] = $row;
}
$stmt->close();

$available_categories = [
    "Full Map", "Full Map Survival", "Lone Wolf 1v1", "Lone Wolf 2v2", 
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
    <title>Manage Tournament</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
</head>
<body class="bg-gray-950 text-white min-h-screen">

<header class="sticky top-0 z-50 bg-gray-950/95 backdrop-blur border-b border-gray-800">
    <div class="max-w-lg mx-auto px-4 py-4 flex items-center gap-3">
        <a href="tournaments.php" class="w-10 h-10 rounded-xl bg-gray-900 border border-gray-800 flex items-center justify-center">
            <i class="fa-solid fa-arrow-left"></i>
        </a>
        <div>
            <p class="text-xs text-gray-500">Admin Panel</p>
            <h1 class="font-bold">Manage Tournament</h1>
        </div>
    </div>
</header>

<main class="max-w-lg mx-auto px-4 py-6 pb-10">

    <section class="bg-gray-900 border border-gray-800 rounded-2xl p-5 mb-5">
        <p class="text-xs text-indigo-400 font-semibold">TOURNAMENT</p>
        <h2 class="text-xl font-bold mt-1"><?= htmlspecialchars($tournament["title"] ?? "") ?></h2>
        <p class="text-sm text-gray-500 mt-1"><?= htmlspecialchars($tournament["game_name"] ?? "") ?> &bull; <span class="text-indigo-400 font-medium"><?= htmlspecialchars($tournament["category"] ?? "Full Map") ?></span></p>
        <div class="mt-4 bg-gray-950 rounded-xl p-4">
            <p class="text-xs text-gray-500">Match Time</p>
            <p class="font-semibold mt-1"><?= htmlspecialchars(date("d M Y, h:i A", strtotime($tournament["match_time"]))) ?></p>
        </div>
    </section>

    <?php if ($message !== ""): ?>
        <div class="bg-green-950/50 border border-green-800 rounded-xl p-4 mb-5 text-sm text-green-300">
            <i class="fa-solid fa-circle-check mr-2"></i><?= htmlspecialchars($message) ?>
        </div>
    <?php endif; ?>

    <?php if ($error !== ""): ?>
        <div class="bg-red-950/50 border border-red-800 rounded-xl p-4 mb-5 text-sm text-red-300">
            <i class="fa-solid fa-circle-exclamation mr-2"></i><?= htmlspecialchars($error) ?>
        </div>
    <?php endif; ?>

    <!-- CHANGE CATEGORY SECTION -->
    <section class="bg-gray-900 border border-gray-800 rounded-2xl p-5 mb-5">
        <h2 class="font-bold mb-4"><i class="fa-solid fa-list text-indigo-400 mr-2"></i> Tournament Category</h2>
        <form method="POST" action="manage_tournament.php?id=<?= (int)$tournament_id ?>">
            <input type="hidden" name="action" value="category_update">
            <select name="category" class="w-full bg-gray-950 border border-gray-800 rounded-xl px-4 py-3 mb-4 outline-none focus:border-indigo-500 text-white">
                <?php foreach ($available_categories as $cat): ?>
                    <option value="<?= htmlspecialchars($cat) ?>" <?= (($tournament["category"] ?? "Full Map") === $cat) ? "selected" : "" ?>><?= htmlspecialchars($cat) ?></option>
                <?php endforeach; ?>
            </select>
            <button type="submit" class="w-full bg-indigo-600 hover:bg-indigo-500 rounded-xl py-3 font-semibold transition">Update Category</button>
        </form>
    </section>

    <!-- DYNAMIC PRIZES SECTION -->
    <section class="bg-gray-900 border border-gray-800 rounded-2xl p-5 mb-5">
        <h2 class="font-bold mb-4"><i class="fa-solid fa-trophy text-yellow-400 mr-2"></i> Custom Prizes</h2>
        <form method="POST" action="manage_tournament.php?id=<?= (int)$tournament_id ?>">
            <input type="hidden" name="action" value="prizes_update">
            
            <div id="prize_container" class="space-y-3 mb-4">
                <?php 
                $prizes = json_decode($tournament['dynamic_prizes'] ?? '[]', true);
                if (empty($prizes)): ?>
                    <div class="flex gap-2 prize-row">
                        <input type="text" name="positions[]" placeholder="E.g., 1st Place" class="w-1/2 bg-gray-950 border border-gray-800 rounded-xl px-4 py-2 outline-none focus:border-indigo-500 text-sm">
                        <input type="number" name="amounts[]" placeholder="Amount (₹)" class="w-1/2 bg-gray-950 border border-gray-800 rounded-xl px-4 py-2 outline-none focus:border-indigo-500 text-sm">
                        <button type="button" onclick="this.parentElement.remove()" class="px-3 text-red-400 bg-gray-950 rounded-xl border border-gray-800 hover:bg-gray-800"><i class="fa-solid fa-trash"></i></button>
                    </div>
                <?php else: ?>
                    <?php foreach ($prizes as $pz): ?>
                    <div class="flex gap-2 prize-row">
                        <input type="text" name="positions[]" value="<?= htmlspecialchars($pz['pos']) ?>" placeholder="E.g., 1st Place" class="w-1/2 bg-gray-950 border border-gray-800 rounded-xl px-4 py-2 outline-none focus:border-indigo-500 text-sm">
                        <input type="number" name="amounts[]" value="<?= htmlspecialchars($pz['amt']) ?>" placeholder="Amount (₹)" class="w-1/2 bg-gray-950 border border-gray-800 rounded-xl px-4 py-2 outline-none focus:border-indigo-500 text-sm">
                        <button type="button" onclick="this.parentElement.remove()" class="px-3 text-red-400 bg-gray-950 rounded-xl border border-gray-800 hover:bg-gray-800"><i class="fa-solid fa-trash"></i></button>
                    </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
            
            <button type="button" onclick="addPrizeRow()" class="w-full bg-gray-800 hover:bg-gray-700 border border-gray-700 rounded-xl py-2 text-sm font-semibold mb-4 text-indigo-400">+ Add Another Position</button>
            <button type="submit" class="w-full bg-indigo-600 hover:bg-indigo-500 rounded-xl py-3 font-semibold">Save Custom Prizes</button>
        </form>
    </section>

    <!-- ROOM DETAILS -->
    <section class="bg-gray-900 border border-gray-800 rounded-2xl p-5 mb-5">
        <h2 class="font-bold mb-4"><i class="fa-solid fa-door-open text-indigo-400 mr-2"></i> Room Details</h2>
        <form method="POST" action="manage_tournament.php?id=<?= (int)$tournament_id ?>">
            <input type="hidden" name="action" value="room_update">
            <label class="block text-sm font-medium mb-2">Room ID</label>
            <input type="text" name="room_id" value="<?= htmlspecialchars($tournament["room_id"] ?? "") ?>" maxlength="100" class="w-full bg-gray-950 border border-gray-800 rounded-xl px-4 py-3 mb-4 outline-none focus:border-indigo-500">
            <label class="block text-sm font-medium mb-2">Room Password</label>
            <input type="text" name="room_password" value="<?= htmlspecialchars($tournament["room_password"] ?? "") ?>" maxlength="100" class="w-full bg-gray-950 border border-gray-800 rounded-xl px-4 py-3 mb-5 outline-none focus:border-indigo-500">
            <button type="submit" class="w-full bg-indigo-600 hover:bg-indigo-500 rounded-xl py-3 font-semibold">Update Room</button>
        </form>
    </section>

    <!-- STATUS -->
    <section class="bg-gray-900 border border-gray-800 rounded-2xl p-5 mb-5">
        <h2 class="font-bold mb-4">Tournament Status</h2>
        <form method="POST" action="manage_tournament.php?id=<?= (int)$tournament_id ?>">
            <input type="hidden" name="action" value="status_update">
            <select name="status" class="w-full bg-gray-950 border border-gray-800 rounded-xl px-4 py-3 mb-4">
                <?php
                $statuses = ["Upcoming", "Live", "Completed"];
                foreach ($statuses as $status):
                ?>
                    <option value="<?= htmlspecialchars($status) ?>" <?= $tournament["status"] === $status ? "selected" : "" ?>><?= htmlspecialchars($status) ?></option>
                <?php endforeach; ?>
            </select>
            <button type="submit" class="w-full bg-gray-800 hover:bg-gray-700 border border-gray-700 rounded-xl py-3 font-semibold">Update Status</button>
        </form>
    </section>

    <!-- PARTICIPANTS & RESULTS -->
    <section class="bg-gray-900 border border-gray-800 rounded-2xl p-5">
        <div class="flex justify-between items-center mb-4">
            <div>
                <h2 class="font-bold">Participants & Results</h2>
                <p class="text-xs text-gray-500 mt-1"><?= count($participants) ?> joined • Enter Kills & Winnings</p>
            </div>
            <div class="w-10 h-10 rounded-xl bg-gray-800 flex items-center justify-center">
                <i class="fa-solid fa-trophy text-yellow-400"></i>
            </div>
        </div>

        <?php if (count($participants) > 0): ?>
            <form method="POST" action="manage_tournament.php?id=<?= (int)$tournament_id ?>" class="space-y-4">
                <input type="hidden" name="action" value="save_results">
                
                <div class="space-y-3">
                    <?php foreach ($participants as $index => $participant): ?>
                        <div class="bg-gray-950 border border-gray-800 rounded-xl p-4">
                            <input type="hidden" name="participant_id[]" value="<?= (int)$participant["id"] ?>">
                            <div class="flex items-center justify-between gap-3 mb-3">
                                <div class="flex items-center gap-3 min-w-0">
                                    <div class="w-9 h-9 rounded-lg bg-indigo-950 flex items-center justify-center shrink-0">
                                        <span class="text-xs font-bold text-indigo-400"><?= $index + 1 ?></span>
                                    </div>
                                    <div class="min-w-0">
                                        <p class="font-semibold text-white truncate">@<?= htmlspecialchars($participant["username"] ?? "Unknown") ?></p>
                                        <p class="text-xs text-indigo-400 font-medium truncate">IGN: <?= htmlspecialchars($participant["in_game_name"] ?? "Not provided") ?></p>
                                    </div>
                                </div>
                            </div>

                            <div class="grid grid-cols-2 gap-3 pt-2 border-t border-gray-800">
                                <div>
                                    <label class="block text-[11px] text-gray-400 mb-1">Kills</label>
                                    <input type="number" name="kills[]" value="<?= intval($participant["kills"]) ?>" min="0" class="w-full bg-gray-900 border border-gray-800 rounded-lg px-3 py-2 text-xs text-white outline-none focus:border-indigo-500">
                                </div>
                                <div>
                                    <label class="block text-[11px] text-gray-400 mb-1">Winning (₹)</label>
                                    <input type="number" step="0.01" name="winning[]" value="<?= floatval($participant["winning"]) ?>" min="0" class="w-full bg-gray-900 border border-gray-800 rounded-lg px-3 py-2 text-xs text-yellow-400 font-semibold outline-none focus:border-indigo-500">
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>

                <button type="submit" class="w-full bg-green-600 hover:bg-green-500 rounded-xl py-3.5 font-semibold text-sm transition shadow-lg shadow-green-900/20">
                    <i class="fa-solid fa-floppy-disk mr-2"></i> Save Results & Distribute Winnings
                </button>
            </form>
        <?php else: ?>
            <div class="text-center py-8 text-gray-500">
                <i class="fa-solid fa-users text-2xl mb-3"></i>
                <p>No participants yet.</p>
            </div>
        <?php endif; ?>
    </section>
</main>

<script>
function addPrizeRow() {
    const html = `
    <div class="flex gap-2 prize-row mt-3">
        <input type="text" name="positions[]" placeholder="E.g., Rank 2-5" class="w-1/2 bg-gray-950 border border-gray-800 rounded-xl px-4 py-2 outline-none focus:border-indigo-500 text-sm">
        <input type="number" name="amounts[]" placeholder="Amount (₹)" class="w-1/2 bg-gray-950 border border-gray-800 rounded-xl px-4 py-2 outline-none focus:border-indigo-500 text-sm">
        <button type="button" onclick="this.parentElement.remove()" class="px-3 text-red-400 bg-gray-950 rounded-xl border border-gray-800 hover:bg-gray-800"><i class="fa-solid fa-trash"></i></button>
    </div>`;
    document.getElementById('prize_container').insertAdjacentHTML('beforeend', html);
}
</script>

</body>
</html>
