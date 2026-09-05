<?php

require_once "common/config.php";

// =====================================================
// CHECK USER LOGIN
// =====================================================

if (!isset($_SESSION["user_id"])) {
    header("Location: login.php");
    exit;
}

$user_id = $_SESSION["user_id"];


// =====================================================
// GET USER INFORMATION (UPDATED FOR NEW BALANCES)
// =====================================================

$stmt = $conn->prepare(
    "SELECT username, wallet_balance, deposit_balance, winning_balance
     FROM users
     WHERE id = ?
     LIMIT 1"
);

$stmt->bind_param("i", $user_id);
$stmt->execute();

$result = $stmt->get_result();
$user = $result->fetch_assoc();

$stmt->close();


// =====================================================
// GET UPCOMING / LIVE TOURNAMENTS
// =====================================================

$active_tournaments = [];

$stmt = $conn->prepare(
    "SELECT
        t.id,
        t.title,
        t.game_name,
        t.entry_fee,
        t.prize_pool,
        t.match_time,
        t.room_id,
        t.room_password,
        t.status
     FROM tournaments t
     INNER JOIN participants p
        ON p.tournament_id = t.id
     WHERE p.user_id = ?
       AND t.status IN ('Upcoming', 'Live')
     ORDER BY t.match_time ASC"
);

$stmt->bind_param("i", $user_id);
$stmt->execute();

$result = $stmt->get_result();

while ($row = $result->fetch_assoc()) {
    $active_tournaments[] = $row;
}

$stmt->close();


// =====================================================
// GET COMPLETED TOURNAMENTS
// =====================================================

$completed_tournaments = [];

$stmt = $conn->prepare(
    "SELECT
        t.id,
        t.title,
        t.game_name,
        t.entry_fee,
        t.prize_pool,
        t.match_time,
        t.status
     FROM tournaments t
     INNER JOIN participants p
        ON p.tournament_id = t.id
     WHERE p.user_id = ?
       AND t.status = 'Completed'
     ORDER BY t.match_time DESC"
);

$stmt->bind_param("i", $user_id);
$stmt->execute();

$result = $stmt->get_result();

while ($row = $result->fetch_assoc()) {
    $completed_tournaments[] = $row;
}

$stmt->close();


// =====================================================
// DETERMINE USER RESULT
// =====================================================
//
// The current database schema does not contain a
// winner_id/result column. Therefore, according to
// the provided schema, we can safely identify
// completed participation but cannot determine
// whether this user was the Winner.
//
// We will display "Participated" for completed
// tournaments until the database specification
// provides a winner field.
// =====================================================

?>

<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta name="viewport"
          content="width=device-width, initial-scale=1.0">

    <title>My Tournaments - Battle Arena</title>

    <script src="https://cdn.tailwindcss.com"></script>

    <link rel="stylesheet"
          href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">

</head>


<body class="bg-gray-950 text-white
             min-h-screen pb-24">


<!-- =====================================================
     HEADER
===================================================== -->

<header class="sticky top-0 z-50
               bg-gray-950/95
               backdrop-blur
               border-b border-gray-800">

    <div class="max-w-3xl mx-auto
                px-4 py-4
                flex items-center
                justify-between">

        <div>

            <p class="text-xs text-gray-500">
                Battle Arena
            </p>

            <h1 class="text-xl font-bold">
                My Tournaments
            </h1>

        </div>


        <div class="bg-gray-900
                    border border-gray-800
                    rounded-xl
                    px-3 py-2">

            <!-- UPDATED: Now dynamically calculates deposit + winning balance -->
            <i class="fa-solid fa-wallet
                      text-indigo-400 mr-1"></i>

            ₹<?php
            echo number_format(
                (float)(($user["deposit_balance"] ?? 0) + ($user["winning_balance"] ?? 0)),
                2
            );
            ?>

        </div>

    </div>

</header>


<!-- =====================================================
     MAIN
===================================================== -->

<main class="max-w-3xl mx-auto
             px-4 py-6">


    <!-- TABS -->

    <div class="grid grid-cols-2
                bg-gray-900
                border border-gray-800
                rounded-xl
                p-1 mb-6">

        <button
            type="button"
            onclick="showTab('active')"
            id="activeTab"
            class="py-3 rounded-lg
                   font-semibold
                   bg-indigo-600
                   text-white">

            Upcoming / Live

        </button>


        <button
            type="button"
            onclick="showTab('completed')"
            id="completedTab"
            class="py-3 rounded-lg
                   font-semibold
                   text-gray-400">

            Completed

        </button>

    </div>


    <!-- =================================================
         UPCOMING / LIVE
    ================================================== -->

    <section id="activeSection">

        <?php if (count($active_tournaments) > 0): ?>

            <div class="space-y-4">

                <?php foreach ($active_tournaments as $tournament): ?>

                    <div class="bg-gray-900
                                border border-gray-800
                                rounded-2xl
                                p-5">


                        <!-- TITLE -->

                        <div class="flex
                                    items-start
                                    justify-between
                                    gap-3">

                            <div>

                                <h2 class="font-bold text-lg">

                                    <?php
                                    echo htmlspecialchars(
                                        $tournament["title"]
                                    );
                                    ?>

                                </h2>

                                <p class="text-sm
                                          text-gray-500 mt-1">

                                    <i class="fa-solid
                                              fa-gamepad mr-1"></i>

                                    <?php
                                    echo htmlspecialchars(
                                        $tournament["game_name"]
                                    );
                                    ?>

                                </p>

                            </div>


                            <?php if ($tournament["status"] === "Live"): ?>

                                <span class="text-xs
                                             bg-red-950
                                             text-red-400
                                             border border-red-800
                                             rounded-full
                                             px-3 py-1">

                                    <i class="fa-solid
                                              fa-circle
                                              text-[7px]
                                              mr-1"></i>

                                    LIVE

                                </span>

                            <?php else: ?>

                                <span class="text-xs
                                             bg-green-950
                                             text-green-400
                                             border border-green-800
                                             rounded-full
                                             px-3 py-1">

                                    Upcoming

                                </span>

                            <?php endif; ?>

                        </div>


                        <!-- MATCH TIME -->

                        <div class="bg-gray-800/60
                                    rounded-xl
                                    p-3 mt-4">

                            <p class="text-xs
                                      text-gray-500">

                                Match Time

                            </p>

                            <p class="font-semibold mt-1">

                                <?php
                                echo date(
                                    "d M Y, h:i A",
                                    strtotime(
                                        $tournament["match_time"]
                                    )
                                );
                                ?>

                            </p>

                        </div>


                        <!-- ENTRY / PRIZE -->

                        <div class="grid grid-cols-2
                                    gap-3 mt-3">

                            <div class="bg-gray-800/60
                                        rounded-xl p-3">

                                <p class="text-xs
                                          text-gray-500">

                                    Entry Fee

                                </p>

                                <p class="font-semibold mt-1">

                                    ₹<?php
                                    echo number_format(
                                        (float)$tournament["entry_fee"],
                                        2
                                    );
                                    ?>

                                </p>

                            </div>


                            <div class="bg-gray-800/60
                                        rounded-xl p-3">

                                <p class="text-xs
                                          text-gray-500">

                                    Prize Pool

                                </p>

                                <p class="font-semibold
                                          text-yellow-400
                                          mt-1">

                                    ₹<?php
                                    echo number_format(
                                        (float)$tournament["prize_pool"],
                                        2
                                    );
                                    ?>

                                </p>

                            </div>

                        </div>


                        <!-- ROOM DETAILS FOR LIVE -->

                        <?php if ($tournament["status"] === "Live"): ?>

                            <div class="mt-4
                                        bg-indigo-950/40
                                        border border-indigo-800
                                        rounded-xl
                                        p-4">

                                <div class="flex
                                            items-center
                                            gap-2 mb-3">

                                    <i class="fa-solid
                                              fa-door-open
                                              text-indigo-400"></i>

                                    <p class="font-semibold">

                                        Room Details

                                    </p>

                                </div>


                                <div class="grid grid-cols-2
                                            gap-3">

                                    <div>

                                        <p class="text-xs
                                                  text-gray-500">

                                            Room ID

                                        </p>

                                        <p class="font-semibold
                                                  mt-1
                                                  break-all">

                                            <?php
                                            echo htmlspecialchars(
                                                $tournament["room_id"]
                                                    ?? "Not added"
                                            );
                                            ?>

                                        </p>

                                    </div>


                                    <div>

                                        <p class="text-xs
                                                  text-gray-500">

                                            Password

                                        </p>

                                        <p class="font-semibold
                                                  mt-1
                                                  break-all">

                                            <?php
                                            echo htmlspecialchars(
                                                $tournament["room_password"]
                                                    ?? "Not added"
                                            );
                                            ?>

                                        </p>

                                    </div>

                                </div>

                            </div>

                        <?php endif; ?>

                    </div>

                <?php endforeach; ?>

            </div>

        <?php else: ?>

            <div class="bg-gray-900
                        border border-gray-800
                        rounded-2xl
                        p-8
                        text-center">

                <div class="w-16 h-16
                            mx-auto mb-4
                            rounded-2xl
                            bg-gray-800
                            flex items-center
                            justify-center">

                    <i class="fa-solid
                              fa-trophy
                              text-2xl
                              text-gray-600"></i>

                </div>

                <h3 class="font-semibold
                           text-lg">

                    No Active Tournaments

                </h3>

                <p class="text-gray-500
                          text-sm mt-2">

                    Tournaments you join will appear here.

                </p>

            </div>

        <?php endif; ?>

    </section>


    <!-- =================================================
         COMPLETED
    ================================================== -->

    <section id="completedSection"
             class="hidden">

        <?php if (count($completed_tournaments) > 0): ?>

            <div class="space-y-4">

                <?php foreach ($completed_tournaments as $tournament): ?>

                    <a href="match_result.php?tournament_id=<?php echo $tournament['id']; ?>" 
                       class="block bg-gray-900 border border-gray-800 rounded-2xl p-5 hover:border-indigo-600 transition shadow-lg">


                        <div class="flex
                                    items-start
                                    justify-between
                                    gap-3">

                            <div>

                                <h2 class="font-bold">

                                    <?php
                                    echo htmlspecialchars(
                                        $tournament["title"]
                                    );
                                    ?>

                                </h2>

                                <p class="text-sm
                                          text-gray-500 mt-1">

                                    <?php
                                    echo htmlspecialchars(
                                        $tournament["game_name"]
                                    );
                                    ?>

                                </p>

                            </div>


                            <span class="text-xs
                                         bg-gray-800
                                         text-gray-400
                                         rounded-full
                                         px-3 py-1">

                                Completed

                            </span>

                        </div>


                        <div class="mt-4
                                    flex items-center
                                    justify-between
                                    gap-3">

                            <div>

                                <p class="text-xs
                                          text-gray-500">

                                    Match Date

                                </p>

                                <p class="text-sm
                                          font-semibold mt-1">

                                    <?php
                                    echo date(
                                        "d M Y, h:i A",
                                        strtotime(
                                            $tournament["match_time"]
                                        )
                                    );
                                    ?>

                                </p>

                            </div>


                            <div class="text-right">

                                <p class="text-xs
                                          text-gray-500">

                                    Result

                                </p>

                                <p class="text-sm
                                          font-semibold
                                          text-indigo-400
                                          mt-1 flex items-center gap-1 justify-end">

                                    <span>View Results</span>
                                    <i class="fa-solid fa-chevron-right text-xs"></i>

                                </p>

                            </div>

                        </div>

                    </a>

                <?php endforeach; ?>

            </div>

        <?php else: ?>

            <div class="bg-gray-900
                        border border-gray-800
                        rounded-2xl
                        p-8
                        text-center">

                <i class="fa-solid
                          fa-clock-rotate-left
                          text-3xl
                          text-gray-600"></i>

                <h3 class="font-semibold
                           text-lg mt-4">

                    No Completed Tournaments

                </h3>

                <p class="text-gray-500
                          text-sm mt-2">

                    Your completed matches will appear here.

                </p>

            </div>

        <?php endif; ?>

    </section>

</main>


<!-- =====================================================
     BOTTOM NAVIGATION
===================================================== -->

<nav class="fixed bottom-0 left-0 right-0
           z-50
           bg-gray-900
           border-t border-gray-800">

    <div class="max-w-3xl mx-auto
                grid grid-cols-4">

        <a href="index.php"
           class="py-3 text-center
                  text-gray-500">

            <i class="fa-solid fa-house
                      block text-lg"></i>

            <span class="text-[11px]">
                Home
            </span>

        </a>


        <a href="my_tournaments.php"
           class="py-3 text-center
                  text-indigo-400">

            <i class="fa-solid fa-trophy
                      block text-lg"></i>

            <span class="text-[11px]">
                Tournaments
            </span>

        </a>


        <a href="wallet.php"
           class="py-3 text-center
                  text-gray-500">

            <i class="fa-solid fa-wallet
                      block text-lg"></i>

            <span class="text-[11px]">
                Wallet
            </span>

        </a>


        <a href="profile.php"
           class="py-3 text-center
                  text-gray-500">

            <i class="fa-solid fa-user
                      block text-lg"></i>

            <span class="text-[11px]">
                Profile
            </span>

        </a>

    </div>

</nav>


<!-- =====================================================
     TAB JAVASCRIPT
===================================================== -->

<script>

function showTab(tab) {

    const activeSection =
        document.getElementById("activeSection");

    const completedSection =
        document.getElementById("completedSection");

    const activeTab =
        document.getElementById("activeTab");

    const completedTab =
        document.getElementById("completedTab");


    if (tab === "active") {

        activeSection.classList.remove("hidden");
        completedSection.classList.add("hidden");

        activeTab.classList.add(
            "bg-indigo-600",
            "text-white"
        );

        activeTab.classList.remove(
            "text-gray-400"
        );

        completedTab.classList.remove(
            "bg-indigo-600",
            "text-white"
        );

        completedTab.classList.add(
            "text-gray-400"
        );

    } else {

        completedSection.classList.remove("hidden");
        activeSection.classList.add("hidden");

        completedTab.classList.add(
            "bg-indigo-600",
            "text-white"
        );

        completedTab.classList.remove(
            "text-gray-400"
        );

        activeTab.classList.remove(
            "bg-indigo-600",
            "text-white"
        );

        activeTab.classList.add(
            "text-gray-400"
        );
    }
}

</script>

</body>
</html>
