<?php
require_once "../Common/config.php";

// =====================================================
// ADMIN ACCESS CHECK
// =====================================================
if (!isset($_SESSION["admin_logged_in"]) || $_SESSION["admin_logged_in"] !== true) {
    header("Location: login.php");
    exit;
}

$message = "";
$message_type = "";

// =====================================================
// AUTO-ADD CONFIGURATION COLUMNS (Added dynamic_prizes)
// =====================================================
$required_columns = [
    "category" => "VARCHAR(100) NOT NULL DEFAULT 'Full Map'",
    "total_slots" => "INT NOT NULL DEFAULT 48",
    "entry_fee" => "DECIMAL(10,2) NOT NULL DEFAULT 0",
    "prize_pool" => "DECIMAL(10,2) NOT NULL DEFAULT 0",
    "admin_commission" => "DECIMAL(10,2) NOT NULL DEFAULT 0",
    "prize_type" => "VARCHAR(30) NOT NULL DEFAULT 'normal'",
    "position_1_prize" => "DECIMAL(10,2) NOT NULL DEFAULT 0",
    "position_2_prize" => "DECIMAL(10,2) NOT NULL DEFAULT 0",
    "position_3_prize" => "DECIMAL(10,2) NOT NULL DEFAULT 0",
    "position_4_prize" => "DECIMAL(10,2) NOT NULL DEFAULT 0",
    "position_5_prize" => "DECIMAL(10,2) NOT NULL DEFAULT 0",
    "kill_prize" => "DECIMAL(10,2) NOT NULL DEFAULT 0",
    "dynamic_prizes" => "TEXT NULL" // NEW DYNAMIC PRIZE COLUMN
];

foreach ($required_columns as $column => $definition) {
    $check = $conn->query("SHOW COLUMNS FROM tournaments LIKE '" . $conn->real_escape_string($column) . "'");
    if ($check && $check->num_rows === 0) {
        $conn->query("ALTER TABLE tournaments ADD COLUMN `$column` $definition");
    }
}

$categories = [
    "Full Map", "Full Map Survival", "Lone Wolf 1v1", "Lone Wolf 2v2", 
    "Lone Wolf Headshot 1v1", "Lone Wolf Headshot 2v2", "Clash Squad 1v1", 
    "Clash Squad 2v2", "Clash Squad 4v4", "Clash Squad Headshot 1v1", 
    "Clash Squad Headshot 2v2", "Clash Squad Headshot 4v4"
];

// =====================================================
// CREATE TOURNAMENT
// =====================================================
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["create_tournament"])) {
    $title = trim($_POST["title"] ?? "");
    $game_name = trim($_POST["game_name"] ?? "");
    $category = trim($_POST["category"] ?? "");
    $match_time = trim($_POST["match_time"] ?? "");
    $entry_fee = max(0, (float)($_POST["entry_fee"] ?? 0));
    $prize_pool = max(0, (float)($_POST["prize_pool"] ?? 0));
    $admin_commission = max(0, (float)($_POST["admin_commission"] ?? 0));
    $prize_type = trim($_POST["prize_type"] ?? "normal");
    $kill_prize = max(0, (float)($_POST["kill_prize"] ?? 0));

    // Compile Dynamic Prizes
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
    $dynamic_prizes = json_encode($prizes);

    // Old position inputs safely reset to 0 to prevent DB errors
    $position_1_prize = $position_2_prize = $position_3_prize = $position_4_prize = $position_5_prize = 0;

    // VALIDATION
    if ($title === "" || $game_name === "" || $category === "" || $match_time === "") {
        $message = "Please fill in all required fields.";
        $message_type = "error";
    } elseif (!in_array($category, $categories, true)) {
        $message = "Invalid tournament category.";
        $message_type = "error";
    } else {
        // AUTOMATIC SLOT CALCULATION
        if (strpos($category, "1v1") !== false) {
            $total_slots = 2;
        } elseif (strpos($category, "2v2") !== false) {
            $total_slots = 4;
        } elseif (strpos($category, "4v4") !== false) {
            $total_slots = 8;
        } else {
            $total_slots = max(2, intval($_POST["total_slots"] ?? 48));
        }

        $special_category = ($category === "Full Map" || $category === "Full Map Survival");
        if (!$special_category) {
            $prize_type = "normal";
            $dynamic_prizes = "[]";
            $kill_prize = 0;
        }

        $allowed_prize_types = ["normal", "position", "kill", "position_kill"];
        if (!in_array($prize_type, $allowed_prize_types, true)) $prize_type = "normal";

        if ($prize_type !== "position" && $prize_type !== "position_kill") {
            $dynamic_prizes = "[]";
        }
        if ($prize_type !== "kill" && $prize_type !== "position_kill") $kill_prize = 0;

        $status = "Upcoming";
        $stmt = $conn->prepare(
            "INSERT INTO tournaments (
                title, game_name, category, total_slots, entry_fee, prize_pool, admin_commission, 
                prize_type, position_1_prize, position_2_prize, position_3_prize, 
                position_4_prize, position_5_prize, kill_prize, match_time, status, dynamic_prizes
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
        );

        if ($stmt) {
            $stmt->bind_param("sssidddsddddddsss", 
                $title, $game_name, $category, $total_slots, $entry_fee, $prize_pool, 
                $admin_commission, $prize_type, $position_1_prize, $position_2_prize, 
                $position_3_prize, $position_4_prize, $position_5_prize, $kill_prize, 
                $match_time, $status, $dynamic_prizes
            );
            
            if ($stmt->execute()) {
                $message = "Tournament created successfully.";
                $message_type = "success";
            } else {
                $message = "Unable to create tournament: " . $stmt->error;
                $message_type = "error";
            }
            $stmt->close();
        }
    }
}

// =====================================================
// UPDATE & DELETE TOURNAMENT
// =====================================================
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["update_tournament"])) {
    $tournament_id = intval($_POST["tournament_id"] ?? 0);
    $status = $_POST["status"] ?? "Upcoming";
    $room_id = trim($_POST["room_id"] ?? "");
    $room_password = trim($_POST["room_password"] ?? "");
    $allowed_statuses = ["Upcoming", "Live", "Completed"];

    if (!in_array($status, $allowed_statuses, true)) {
        $message = "Invalid tournament status.";
        $message_type = "error";
    } elseif ($tournament_id > 0) {
        $stmt = $conn->prepare("UPDATE tournaments SET status = ?, room_id = ?, room_password = ? WHERE id = ?");
        if ($stmt) {
            $stmt->bind_param("sssi", $status, $room_id, $room_password, $tournament_id);
            if ($stmt->execute()) {
                $message = "Tournament updated successfully.";
                $message_type = "success";
            }
            $stmt->close();
        }
    }
}

if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["delete_tournament"])) {
    $tournament_id = intval($_POST["tournament_id"] ?? 0);
    if ($tournament_id > 0) {
        $stmt = $conn->prepare("DELETE FROM tournaments WHERE id = ?");
        if ($stmt) {
            $stmt->bind_param("i", $tournament_id);
            if ($stmt->execute()) {
                $message = "Tournament deleted.";
                $message_type = "success";
            }
            $stmt->close();
        }
    }
}

// GET TOURNAMENTS
$tournaments = [];
$result = $conn->query("SELECT * FROM tournaments ORDER BY match_time DESC");
if ($result) {
    while ($row = $result->fetch_assoc()) $tournaments[] = $row;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Tournaments</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
</head>
<body class="bg-gray-950 text-white min-h-screen">

<header class="sticky top-0 z-50 bg-gray-950/95 backdrop-blur border-b border-gray-800">
    <div class="max-w-5xl mx-auto px-4 py-4 flex items-center justify-between">
        <div class="flex items-center gap-3">
            <a href="dashboard.php" class="w-10 h-10 rounded-xl bg-gray-800 flex items-center justify-center">
                <i class="fa-solid fa-arrow-left"></i>
            </a>
            <div>
                <p class="text-xs text-gray-500">Admin Panel</p>
                <h1 class="font-bold">Tournaments</h1>
            </div>
        </div>
        <a href="dashboard.php" class="text-sm text-indigo-400">Dashboard</a>
    </div>
</header>

<main class="max-w-5xl mx-auto px-4 py-6">

<?php if ($message !== ""): ?>
    <div class="mb-6 rounded-xl p-4 <?= $message_type === 'success' ? 'bg-green-950/50 border border-green-800 text-green-300' : 'bg-red-950/50 border border-red-800 text-red-300' ?>">
        <i class="fa-solid <?= $message_type === 'success' ? 'fa-circle-check' : 'fa-circle-exclamation' ?> mr-2"></i>
        <?= htmlspecialchars($message) ?>
    </div>
<?php endif; ?>

<section class="bg-gray-900 border border-gray-800 rounded-2xl p-5 mb-7">
    <div class="mb-6">
        <p class="text-indigo-400 text-xs font-medium">NEW MATCH</p>
        <h2 class="text-xl font-bold mt-1">Create Tournament</h2>
    </div>

    <form method="POST" class="space-y-5">
        <input type="hidden" name="create_tournament" value="1">
        
        <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
            <div>
                <label class="block text-sm text-gray-300 mb-2">Tournament Title</label>
                <input type="text" name="title" placeholder="Example: Evening Battle" class="w-full bg-gray-800 border border-gray-700 rounded-xl py-3.5 px-4 outline-none focus:border-indigo-500" required>
            </div>
            <div>
                <label class="block text-sm text-gray-300 mb-2">Game Name</label>
                <input type="text" name="game_name" value="Free Fire" class="w-full bg-gray-800 border border-gray-700 rounded-xl py-3.5 px-4 outline-none focus:border-indigo-500" required>
            </div>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
            <div>
                <label class="block text-sm text-gray-300 mb-2">Tournament Category</label>
                <select id="category" name="category" class="w-full bg-gray-800 border border-gray-700 rounded-xl py-3.5 px-4 outline-none focus:border-indigo-500" required>
                    <?php foreach ($categories as $category): ?>
                        <option value="<?= htmlspecialchars($category) ?>"><?= htmlspecialchars($category) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label class="block text-sm text-gray-300 mb-2">Match Date & Time</label>
                <input type="datetime-local" name="match_time" class="w-full bg-gray-800 border border-gray-700 rounded-xl py-3.5 px-4 outline-none focus:border-indigo-500" required>
            </div>
        </div>

        <!-- DYNAMIC SLOTS INPUT -->
        <div id="totalSlotsDiv">
            <label class="block text-sm text-gray-300 mb-2">Total Player Slots <span class="text-indigo-400">(Adjustable for Map modes)</span></label>
            <input type="number" name="total_slots" min="2" value="48" class="w-full bg-gray-800 border border-gray-700 rounded-xl py-3.5 px-4 outline-none focus:border-indigo-500">
            <p class="text-xs text-gray-500 mt-2"><i class="fa-solid fa-circle-info mr-1"></i> Fixed modes (1v1, 2v2, 4v4) will automatically lock their slot limits regardless of this input.</p>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-3 gap-5">
            <div>
                <label class="block text-sm text-gray-300 mb-2">Entry Fee (Coins)</label>
                <input type="number" name="entry_fee" min="0" step="0.01" value="0" class="w-full bg-gray-800 border border-gray-700 rounded-xl py-3.5 px-4 outline-none focus:border-indigo-500" required>
            </div>
            <div>
                <label class="block text-sm text-gray-300 mb-2">Prize Pool (Coins)</label>
                <input type="number" name="prize_pool" min="0" step="0.01" value="0" class="w-full bg-gray-800 border border-gray-700 rounded-xl py-3.5 px-4 outline-none focus:border-indigo-500" required>
            </div>
            <div>
                <label class="block text-sm text-gray-300 mb-2">Admin Commission</label>
                <input type="number" name="admin_commission" min="0" step="0.01" value="0" class="w-full bg-gray-800 border border-gray-700 rounded-xl py-3.5 px-4 outline-none focus:border-indigo-500" required>
            </div>
        </div>

        <div id="specialPrizeSection" class="hidden bg-gray-950 border border-gray-800 rounded-2xl p-4">
            <div class="mb-4">
                <p class="text-xs text-indigo-400 font-semibold">SPECIAL PRIZES</p>
                <h3 class="font-bold mt-1">Prize Distribution</h3>
            </div>
            <select id="prizeType" name="prize_type" class="w-full bg-gray-800 border border-gray-700 rounded-xl px-4 py-3 mb-4">
                <option value="normal">Normal Prize Pool</option>
                <option value="position">Position Prize</option>
                <option value="kill">Per Kill</option>
                <option value="position_kill">Position + Per Kill</option>
            </select>
            
            <!-- DYNAMIC POSITION PRIZES -->
            <div id="positionPrizeFields" class="hidden space-y-3">
                <p class="text-sm font-semibold">Custom Position Prizes</p>
                <div id="prize_container" class="space-y-3">
                    <div class="flex gap-2 prize-row">
                        <input type="text" name="positions[]" placeholder="E.g., 1st Place" class="w-1/2 bg-gray-800 border border-gray-700 rounded-xl px-4 py-2 outline-none focus:border-indigo-500 text-sm">
                        <input type="number" name="amounts[]" placeholder="Amount (₹)" class="w-1/2 bg-gray-800 border border-gray-700 rounded-xl px-4 py-2 outline-none focus:border-indigo-500 text-sm">
                        <button type="button" onclick="this.parentElement.remove()" class="px-3 text-red-400 bg-gray-800 rounded-xl border border-gray-700 hover:bg-gray-700"><i class="fa-solid fa-trash"></i></button>
                    </div>
                </div>
                <button type="button" onclick="addPrizeRow()" class="w-full bg-gray-800 hover:bg-gray-700 border border-gray-700 rounded-xl py-2 text-sm font-semibold text-indigo-400">+ Add Another Position</button>
            </div>

            <div id="killPrizeField" class="hidden mt-4">
                <label class="block text-sm font-medium mb-2">Prize Per Kill</label>
                <input type="number" name="kill_prize" min="0" step="0.01" class="w-full bg-gray-800 border border-gray-700 rounded-xl px-4 py-3">
            </div>
        </div>

        <button type="submit" class="w-full bg-indigo-600 hover:bg-indigo-500 rounded-xl py-3.5 font-semibold"><i class="fa-solid fa-plus mr-2"></i> Create Tournament</button>
    </form>
</section>

<section>
    <div class="flex items-center justify-between mb-4">
        <h2 class="text-xl font-bold mt-1">All Tournaments</h2>
        <span class="text-sm text-gray-500"><?= count($tournaments) ?> total</span>
    </div>
    
    <?php if (count($tournaments) > 0): ?>
        <div class="space-y-4">
            <?php foreach ($tournaments as $tournament): ?>
                <div class="bg-gray-900 border border-gray-800 rounded-2xl p-5">
                    <div class="flex items-start justify-between gap-3 mb-4">
                        <div>
                            <h3 class="font-bold text-lg"><?= htmlspecialchars($tournament["title"]) ?></h3>
                            <span class="inline-block mt-1 text-xs bg-indigo-950 text-indigo-400 rounded-full px-3 py-1"><?= htmlspecialchars($tournament["category"]) ?></span>
                            <span class="inline-block mt-1 text-xs bg-gray-800 text-gray-300 rounded-full px-3 py-1 ml-1"><i class="fa-solid fa-users text-xs mr-1"></i><?= $tournament["total_slots"] ?? 48 ?> Slots</span>
                        </div>
                        <span class="text-xs rounded-full px-3 py-1 bg-gray-800 text-gray-400"><?= htmlspecialchars($tournament["status"]) ?></span>
                    </div>

                    <form method="POST" class="space-y-4 border-t border-gray-800 pt-4">
                        <input type="hidden" name="update_tournament" value="1">
                        <input type="hidden" name="tournament_id" value="<?= (int)$tournament["id"] ?>">
                        <div class="grid grid-cols-2 gap-3">
                            <input type="text" name="room_id" value="<?= htmlspecialchars($tournament["room_id"] ?? "") ?>" placeholder="Room ID" class="w-full bg-gray-800 border border-gray-700 rounded-xl px-4 py-3">
                            <input type="text" name="room_password" value="<?= htmlspecialchars($tournament["room_password"] ?? "") ?>" placeholder="Password" class="w-full bg-gray-800 border border-gray-700 rounded-xl px-4 py-3">
                        </div>
                        <select name="status" class="w-full bg-gray-800 border border-gray-700 rounded-xl px-4 py-3">
                            <?php foreach (["Upcoming", "Live", "Completed"] as $stat): ?>
                                <option value="<?= $stat ?>" <?= $tournament["status"] === $stat ? "selected" : "" ?>><?= $stat ?></option>
                            <?php endforeach; ?>
                        </select>
                        
                        <!-- NEW PLAYER DETAILS LINK BUTTON -->
                        <a href="manage_tournament.php?id=<?= (int)$tournament["id"] ?>" class="w-full flex items-center justify-center bg-gray-800 border border-gray-700 hover:bg-gray-700 rounded-xl py-3 font-semibold text-indigo-400 transition">
                            <i class="fa-solid fa-users mr-2"></i> Player Details
                        </a>

                        <div class="flex gap-3 mt-4">
                            <button type="submit" class="w-2/3 bg-indigo-600 hover:bg-indigo-500 transition rounded-xl py-3 font-semibold">Save</button>
                    </form>
                    <form method="POST" onsubmit="return confirm('Delete?');" class="w-1/3">
                        <input type="hidden" name="delete_tournament" value="1">
                        <input type="hidden" name="tournament_id" value="<?= (int)$tournament["id"] ?>">
                        <button type="submit" class="w-full border border-red-900 text-red-400 hover:bg-red-950 rounded-xl py-3 font-semibold transition"><i class="fa-solid fa-trash"></i></button>
                    </form>
                        </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</section>
</main>

<script>
const categorySelect = document.getElementById("category");
const specialPrizeSection = document.getElementById("specialPrizeSection");
const prizeType = document.getElementById("prizeType");
const positionPrizeFields = document.getElementById("positionPrizeFields");
const killPrizeField = document.getElementById("killPrizeField");
const totalSlotsDiv = document.getElementById("totalSlotsDiv");

function updatePrizeOptions() {
    const category = categorySelect.value;
    const specialCategory = category === "Full Map" || category === "Full Map Survival";
    
    // Hide Slot input for fixed modes
    if (category.includes("1v1") || category.includes("2v2") || category.includes("4v4")) {
        totalSlotsDiv.classList.add("hidden");
    } else {
        totalSlotsDiv.classList.remove("hidden");
    }

    if (specialCategory) specialPrizeSection.classList.remove("hidden");
    else { specialPrizeSection.classList.add("hidden"); prizeType.value = "normal"; }
    updatePrizeFields();
}

function updatePrizeFields() {
    const type = prizeType.value;
    if (type === "position" || type === "position_kill") positionPrizeFields.classList.remove("hidden");
    else positionPrizeFields.classList.add("hidden");
    
    if (type === "kill" || type === "position_kill") killPrizeField.classList.remove("hidden");
    else killPrizeField.classList.add("hidden");
}

function addPrizeRow() {
    const html = `
    <div class="flex gap-2 prize-row">
        <input type="text" name="positions[]" placeholder="E.g., Rank 2-5" class="w-1/2 bg-gray-800 border border-gray-700 rounded-xl px-4 py-2 outline-none focus:border-indigo-500 text-sm">
        <input type="number" name="amounts[]" placeholder="Amount (₹)" class="w-1/2 bg-gray-800 border border-gray-700 rounded-xl px-4 py-2 outline-none focus:border-indigo-500 text-sm">
        <button type="button" onclick="this.parentElement.remove()" class="px-3 text-red-400 bg-gray-800 rounded-xl border border-gray-700 hover:bg-gray-700"><i class="fa-solid fa-trash"></i></button>
    </div>`;
    document.getElementById('prize_container').insertAdjacentHTML('beforeend', html);
}

categorySelect.addEventListener("change", updatePrizeOptions);
prizeType.addEventListener("change", updatePrizeFields);
updatePrizeOptions();
</script>
</body>
</html>
