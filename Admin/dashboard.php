<?php

require_once "../common/config.php";

// =====================================================
// ADMIN ACCESS CHECK
// =====================================================

if (
    !isset($_SESSION["admin_logged_in"]) ||
    $_SESSION["admin_logged_in"] !== true
) {
    header("Location: login.php");
    exit;
}


// =====================================================
// ADMIN LOGOUT
// =====================================================

if (isset($_GET["logout"])) {

    unset($_SESSION["admin_logged_in"]);
    unset($_SESSION["admin_username"]);

    header("Location: login.php");
    exit;
}


// =====================================================
// GET ADMIN NAME
// =====================================================

$admin_username =
    $_SESSION["admin_username"] ?? "Admin";


// =====================================================
// GET PLAYER COUNT
// =====================================================

$player_count = 0;

$result = $conn->query(
    "SELECT COUNT(*) AS total
     FROM users"
);

if ($result) {

    $row = $result->fetch_assoc();

    $player_count = (int)$row["total"];
}


// =====================================================
// GET TOURNAMENT COUNT
// =====================================================

$tournament_count = 0;

$result = $conn->query(
    "SELECT COUNT(*) AS total
     FROM tournaments"
);

if ($result) {

    $row = $result->fetch_assoc();

    $tournament_count = (int)$row["total"];
}


// =====================================================
// GET UPCOMING COUNT
// =====================================================

$upcoming_count = 0;

$result = $conn->query(
    "SELECT COUNT(*) AS total
     FROM tournaments
     WHERE status = 'Upcoming'"
);

if ($result) {

    $row = $result->fetch_assoc();

    $upcoming_count = (int)$row["total"];
}


// =====================================================
// GET LIVE COUNT
// =====================================================

$live_count = 0;

$result = $conn->query(
    "SELECT COUNT(*) AS total
     FROM tournaments
     WHERE status = 'Live'"
);

if ($result) {

    $row = $result->fetch_assoc();

    $live_count = (int)$row["total"];
}


// =====================================================
// GET RECENT TOURNAMENTS
// =====================================================

$recent_tournaments = [];

$result = $conn->query(
    "SELECT
        id,
        title,
        game_name,
        match_time,
        status
     FROM tournaments
     ORDER BY match_time DESC
     LIMIT 10"
);

if ($result) {

    while ($row = $result->fetch_assoc()) {

        $recent_tournaments[] = $row;
    }
}

?>

<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta name="viewport"
          content="width=device-width, initial-scale=1.0">

    <title>Admin Dashboard - Battle Arena</title>

    <script src="https://cdn.tailwindcss.com"></script>

    <link rel="stylesheet"
          href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">

</head>


<body class="bg-gray-950 text-white min-h-screen">


<!-- =====================================================
     HEADER
===================================================== -->

<header class="border-b border-gray-800
               bg-gray-950/95
               backdrop-blur
               sticky top-0
               z-50">

    <div class="max-w-5xl mx-auto
                px-4 py-4
                flex items-center
                justify-between
                gap-4">

        <div class="flex items-center gap-3">

            <div class="w-11 h-11
                        rounded-xl
                        bg-indigo-600
                        flex items-center
                        justify-center">

                <i class="fa-solid
                          fa-shield-halved"></i>

            </div>


            <div>

                <p class="text-xs
                          text-gray-500">

                    Battle Arena

                </p>

                <h1 class="font-bold">

                    Admin Dashboard

                </h1>

            </div>

        </div>


        <a href="?logout=1"
           onclick="return confirm('Logout from admin panel?');"
           class="text-sm
                  text-red-400
                  hover:text-red-300">

            <i class="fa-solid
                      fa-right-from-bracket
                      mr-1"></i>

            Logout

        </a>

    </div>

</header>


<!-- =====================================================
     MAIN
===================================================== -->

<main class="max-w-5xl mx-auto
             px-4 py-6">


    <!-- =================================================
         WELCOME
    ================================================== -->

    <div class="mb-7">

        <p class="text-indigo-400
                  text-sm
                  font-medium">

            OWNER PANEL

        </p>

        <h2 class="text-2xl
                   font-bold
                   mt-1">

            Welcome, <?php
            echo htmlspecialchars($admin_username);
            ?>

        </h2>

        <p class="text-gray-500
                  text-sm mt-2">

            Manage your tournament platform from here.

        </p>

    </div>


    <!-- =================================================
         STAT CARDS
    ================================================== -->

    <div class="grid grid-cols-2
                lg:grid-cols-4
                gap-3
                mb-7">


        <!-- Players -->

        <div class="bg-gray-900
                    border border-gray-800
                    rounded-2xl
                    p-4">

            <div class="w-10 h-10
                        rounded-xl
                        bg-blue-950
                        flex items-center
                        justify-center
                        mb-3">

                <i class="fa-solid
                          fa-users
                          text-blue-400"></i>

            </div>

            <p class="text-xs
                      text-gray-500">

                Players

            </p>

            <p class="text-2xl
                      font-bold
                      mt-1">

                <?php
                echo $player_count;
                ?>

            </p>

        </div>


        <!-- Tournaments -->

        <div class="bg-gray-900
                    border border-gray-800
                    rounded-2xl
                    p-4">

            <div class="w-10 h-10
                        rounded-xl
                        bg-indigo-950
                        flex items-center
                        justify-center
                        mb-3">

                <i class="fa-solid
                          fa-trophy
                          text-indigo-400"></i>

            </div>

            <p class="text-xs
                      text-gray-500">

                Tournaments

            </p>

            <p class="text-2xl
                      font-bold
                      mt-1">

                <?php
                echo $tournament_count;
                ?>

            </p>

        </div>


        <!-- Upcoming -->

        <div class="bg-gray-900
                    border border-gray-800
                    rounded-2xl
                    p-4">

            <div class="w-10 h-10
                        rounded-xl
                        bg-green-950
                        flex items-center
                        justify-center
                        mb-3">

                <i class="fa-solid
                          fa-calendar
                          text-green-400"></i>

            </div>

            <p class="text-xs
                      text-gray-500">

                Upcoming

            </p>

            <p class="text-2xl
                      font-bold
                      mt-1">

                <?php
                echo $upcoming_count;
                ?>

            </p>

        </div>


        <!-- Live -->

        <div class="bg-gray-900
                    border border-gray-800
                    rounded-2xl
                    p-4">

            <div class="w-10 h-10
                        rounded-xl
                        bg-red-950
                        flex items-center
                        justify-center
                        mb-3">

                <i class="fa-solid
                          fa-tower-broadcast
                          text-red-400"></i>

            </div>

            <p class="text-xs
                      text-gray-500">

                Live

            </p>

            <p class="text-2xl
                      font-bold
                      mt-1">

                <?php
                echo $live_count;
                ?>

            </p>

        </div>

    </div>


    <!-- =================================================
         QUICK ACTIONS
    ================================================== -->

    <div class="mb-7">

        <h2 class="text-lg
                   font-bold
                   mb-4">

            Quick Actions

        </h2>


        <div class="grid
                    grid-cols-1
                    sm:grid-cols-2
                    gap-3">


            <!-- Manage Tournaments -->

            <a href="tournaments.php"
               class="bg-gray-900
                      border border-gray-800
                      rounded-2xl
                      p-5
                      hover:border-indigo-500
                      transition">

                <div class="flex
                            items-center
                            gap-4">

                    <div class="w-12 h-12
                                rounded-xl
                                bg-indigo-950
                                flex items-center
                                justify-center">

                        <i class="fa-solid
                                  fa-trophy
                                  text-indigo-400"></i>

                    </div>


                    <div>

                        <h3 class="font-semibold">

                            Manage Tournaments

                        </h3>

                        <p class="text-xs
                                  text-gray-500
                                  mt-1">

                            Create and manage matches

                        </p>

                    </div>

                </div>

            </a>


            <!-- Manage Players -->

            <a href="players.php"
               class="bg-gray-900
                      border border-gray-800
                      rounded-2xl
                      p-5
                      hover:border-indigo-500
                      transition">

                <div class="flex
                            items-center
                            gap-4">

                    <div class="w-12 h-12
                                rounded-xl
                                bg-blue-950
                                flex items-center
                                justify-center">

                        <i class="fa-solid
                                  fa-users
                                  text-blue-400"></i>

                    </div>


                    <div>

                        <h3 class="font-semibold">

                            Manage Players

                        </h3>

                        <p class="text-xs
                                  text-gray-500
                                  mt-1">

                            View registered players

                        </p>

                    </div>

                </div>

            </a>


            <!-- Deposit Requests -->

            <a href="deposit_requests.php"
               class="bg-gray-900
                      border border-gray-800
                      rounded-2xl
                      p-5
                      hover:border-green-500
                      transition">

                <div class="flex
                            items-center
                            gap-4">

                    <div class="w-12 h-12
                                rounded-xl
                                bg-green-950
                                flex items-center
                                justify-center">

                        <i class="fa-solid
                                  fa-file-invoice-dollar
                                  text-green-400"></i>

                    </div>


                    <div>

                        <h3 class="font-semibold">

                            Deposit Requests

                        </h3>

                        <p class="text-xs
                                  text-gray-500
                                  mt-1">

                            Approve player funds

                        </p>

                    </div>

                </div>

            </a>


            <!-- Withdrawal Requests -->

            <a href="withdrawal_requests.php"
               class="bg-gray-900
                      border border-gray-800
                      rounded-2xl
                      p-5
                      hover:border-yellow-500
                      transition">

                <div class="flex
                            items-center
                            gap-4">

                    <div class="w-12 h-12
                                rounded-xl
                                bg-yellow-950
                                flex items-center
                                justify-center">

                        <i class="fa-solid
                                  fa-money-bill-transfer
                                  text-yellow-400"></i>

                    </div>


                    <div>

                        <h3 class="font-semibold">

                            Withdrawal Requests

                        </h3>

                        <p class="text-xs
                                  text-gray-500
                                  mt-1">

                            Manage player payouts

                        </p>

                    </div>

                </div>

            </a>

        </div>

    </div>


    <!-- =================================================
         RECENT TOURNAMENTS
    ================================================== -->

    <div>

        <div class="flex
                    items-center
                    justify-between
                    mb-4">

            <h2 class="text-lg
                       font-bold">

                Recent Tournaments

            </h2>


            <a href="tournaments.php"
               class="text-sm
                      text-indigo-400
                      hover:text-indigo-300">

                View All

            </a>

        </div>


        <?php if (count($recent_tournaments) > 0): ?>

            <div class="bg-gray-900
                        border border-gray-800
                        rounded-2xl
                        overflow-hidden">

                <div class="overflow-x-auto">

                    <table class="w-full
                                  text-sm">

                        <thead class="bg-gray-800/60">

                            <tr>

                                <th class="text-left
                                           px-4 py-3
                                           text-gray-400
                                           font-medium">

                                    Tournament

                                </th>

                                <th class="text-left
                                           px-4 py-3
                                           text-gray-400
                                           font-medium">

                                    Game

                                </th>

                                <th class="text-left
                                           px-4 py-3
                                           text-gray-400
                                           font-medium">

                                    Time

                                </th>

                                <th class="text-left
                                           px-4 py-3
                                           text-gray-400
                                           font-medium">

                                    Status

                                </th>

                            </tr>

                        </thead>


                        <tbody>

                            <?php foreach (
                                $recent_tournaments
                                as $tournament
                            ): ?>

                                <tr class="border-t
                                           border-gray-800">

                                    <td class="px-4 py-4">

                                        <p class="font-medium">

                                            <?php
                                            echo htmlspecialchars(
                                                $tournament["title"]
                                            );
                                            ?>

                                        </p>

                                    </td>


                                    <td class="px-4 py-4
                                               text-gray-400">

                                        <?php
                                        echo htmlspecialchars(
                                            $tournament["game_name"]
                                        );
                                        ?>

                                    </td>


                                    <td class="px-4 py-4
                                               text-gray-400
                                               whitespace-nowrap">

                                        <?php
                                        echo date(
                                            "d M Y, h:i A",
                                            strtotime(
                                                $tournament["match_time"]
                                            )
                                        );
                                        ?>

                                    </td>


                                    <td class="px-4 py-4">

                                        <?php

                                        $status =
                                            $tournament["status"];

                                        $status_class =
                                            "bg-gray-800 text-gray-400";

                                        if (
                                            $status === "Upcoming"
                                        ) {

                                            $status_class =
                                                "bg-green-950 text-green-400";

                                        } elseif (
                                            $status === "Live"
                                        ) {

                                            $status_class =
                                                "bg-red-950 text-red-400";

                                        } elseif (
                                            $status === "Completed"
                                        ) {

                                            $status_class =
                                                "bg-blue-950 text-blue-400";
                                        }

                                        ?>

                                        <span class="inline-block
                                                     text-xs
                                                     rounded-full
                                                     px-3 py-1
                                                     <?php
                                                     echo $status_class;
                                                     ?>">

                                            <?php
                                            echo htmlspecialchars(
                                                $status
                                            );
                                            ?>

                                        </span>

                                    </td>

                                </tr>

                            <?php endforeach; ?>

                        </tbody>

                    </table>

                </div>

            </div>

        <?php else: ?>

            <div class="bg-gray-900
                        border border-gray-800
                        rounded-2xl
                        p-8
                        text-center">

                <i class="fa-solid
                          fa-trophy
                          text-3xl
                          text-gray-700"></i>

                <p class="text-gray-500
                          mt-3">

                    No tournaments created yet.

                </p>

            </div>

        <?php endif; ?>

    </div>

</main>


<!-- =====================================================
     FOOTER
===================================================== -->

<footer class="max-w-5xl mx-auto
               px-4 py-8
               text-center">

    <p class="text-xs
              text-gray-600">

        Battle Arena Admin Panel

    </p>

</footer>


<script>

// Disable right click
document.addEventListener(
    "contextmenu",
    function(event) {
        event.preventDefault();
    }
);


// Disable text selection
document.addEventListener(
    "selectstart",
    function(event) {
        event.preventDefault();
    }
);

</script>

</body>
</html>
