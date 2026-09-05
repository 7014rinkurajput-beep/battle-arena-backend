<?php
require_once "common/config.php";

if (!isset($_SESSION["user_id"])) {
    header("Location: login.php");
    exit;
}

$user_id = $_SESSION["user_id"];
$tournament_id = intval($_GET["tournament_id"] ?? 0);

if ($tournament_id <= 0) {
    header("Location: my_tournaments.php");
    exit;
}

// Fetch tournament details
$stmt = $conn->prepare("SELECT id, title, game_name, match_time, status, prize_pool FROM tournaments WHERE id = ? LIMIT 1");
$stmt->bind_param("i", $tournament_id);
$stmt->execute();
$tournament = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$tournament || $tournament["status"] !== "Completed") {
    header("Location: my_tournaments.php");
    exit;
}

// Fetch leaderboard / participants ordered by winning descending, then kills descending
$participants = [];
$stmt = $conn->prepare("SELECT p.in_game_name, p.kills, p.winning, p.user_id FROM participants p WHERE p.tournament_id = ? ORDER BY p.winning DESC, p.kills DESC");
$stmt->bind_param("i", $tournament_id);
$stmt->execute();
$res = $stmt->get_result();
while ($row = $res->fetch_assoc()) {
    $participants[] = $row;
}
$stmt->close();

// Get user wallet for header
$stmt = $conn->prepare("SELECT wallet_balance FROM users WHERE id = ? LIMIT 1");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
$stmt->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Match Result - Battle Arena</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
</head>
<body class="bg-gray-950 text-white min-h-screen pb-24">

<header class="sticky top-0 z-50 bg-gray-950/95 backdrop-blur border-b border-gray-800">
    <div class="max-w-3xl mx-auto px-4 py-4 flex items-center justify-between">
        <div class="flex items-center gap-3">
            <a href="my_tournaments.php" class="w-10 h-10 rounded-xl bg-gray-800 flex items-center justify-center hover:bg-gray-700">
                <i class="fa-solid fa-arrow-left"></i>
            </a>
            <div>
                <p class="text-xs text-gray-500"><?= htmlspecialchars($tournament["title"]) ?></p>
                <h1 class="text-lg font-bold">Match Result</h1>
            </div>
        </div>
        <div class="bg-gray-900 border border-gray-800 rounded-xl px-3 py-2">
            <i class="fa-solid fa-wallet text-indigo-400 mr-1"></i> ₹<?= number_format((float)$user["wallet_balance"], 2) ?>
        </div>
    </div>
</header>

<main class="max-w-3xl mx-auto px-4 py-6">
    <div class="bg-gray-900 border border-gray-800 rounded-2xl overflow-hidden shadow-xl">
        <div class="p-4 bg-gray-800/50 border-b border-gray-800 text-center">
            <h2 class="font-bold text-base text-indigo-400">Leaderboard & Standings</h2>
            <p class="text-xs text-gray-400 mt-0.5"><?= htmlspecialchars($tournament["game_name"]) ?> • Prize Pool: ₹<?= number_format((float)$tournament["prize_pool"], 2) ?></p>
        </div>

        <?php if (count($participants) > 0): ?>
            <div class="overflow-x-auto">
                <table class="w-full text-left border-collapse">
                    <thead>
                        <tr class="border-b border-gray-800 text-gray-400 text-xs uppercase bg-gray-950/60">
                            <th class="py-3 px-4 w-16 text-center">#</th>
                            <th class="py-3 px-4">Player Name</th>
                            <th class="py-3 px-4 text-center">Kills</th>
                            <th class="py-3 px-4 text-right">Winning</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-800/60 text-sm">
                        <?php foreach ($participants as $index => $p): 
                            $rank = $index + 1;
                            $is_current_user = ((int)$p["user_id"] === (int)$user_id);
                        ?>
                            <tr class="<?= $is_current_user ? 'bg-indigo-950/30' : 'hover:bg-gray-800/30' ?>">
                                <td class="py-3.5 px-4 text-center font-bold text-gray-400">
                                    <?php if ($rank === 1): ?>
                                        <span class="inline-flex items-center justify-center w-6 h-6 rounded-full bg-yellow-500/20 text-yellow-400 font-bold text-xs border border-yellow-500/40">1</span>
                                    <?php elseif ($rank === 2): ?>
                                        <span class="inline-flex items-center justify-center w-6 h-6 rounded-full bg-gray-300/20 text-gray-300 font-bold text-xs border border-gray-400/40">2</span>
                                    <?php elseif ($rank === 3): ?>
                                        <span class="inline-flex items-center justify-center w-6 h-6 rounded-full bg-amber-700/20 text-amber-500 font-bold text-xs border border-amber-600/40">3</span>
                                    <?php else: ?>
                                        <?= $rank ?>
                                    <?php endif; ?>
                                </td>
                                <td class="py-3.5 px-4 font-medium <?= $is_current_user ? 'text-indigo-300 font-semibold' : 'text-white' ?>">
                                    <?= htmlspecialchars($p["in_game_name"] ?? "Player") ?>
                                    <?php if ($is_current_user): ?>
                                        <span class="ml-2 text-[10px] bg-indigo-900 text-indigo-300 px-2 py-0.5 rounded-full border border-indigo-700">You</span>
                                    <?php endif; ?>
                                </td>
                                <td class="py-3.5 px-4 text-center font-semibold text-gray-300">
                                    <?= (int)$p["kills"] ?>
                                </td>
                                <td class="py-3.5 px-4 text-right font-bold text-yellow-400">
                                    ₹<?= number_format((float)$p["winning"], 2) ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <div class="p-8 text-center text-gray-500">
                <p>No participants found for this tournament.</p>
            </div>
        <?php endif; ?>
    </div>
</main>

</body>
</html>
