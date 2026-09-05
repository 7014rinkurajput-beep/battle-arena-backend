<?php

if (!isset($page_title)) {
    $page_title = "Battle Arena";
}

$user_logged_in =
    isset($_SESSION["user_id"]) &&
    is_numeric($_SESSION["user_id"]);

$wallet_balance = 0;

if ($user_logged_in) {

    $user_id = (int)$_SESSION["user_id"];

    $stmt = $conn->prepare(
        "SELECT wallet_balance
         FROM users
         WHERE id = ?
         LIMIT 1"
    );

    if ($stmt) {

        $stmt->bind_param(
            "i",
            $user_id
        );

        $stmt->execute();

        $result = $stmt->get_result();

        if ($row = $result->fetch_assoc()) {

            $wallet_balance =
                (float)$row["wallet_balance"];

        }

        $stmt->close();
    }
}

?>

<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width,
                 initial-scale=1.0,
                 maximum-scale=1.0,
                 user-scalable=no">

    <title>
        <?= e($page_title) ?> - Battle Arena
    </title>

    <script src="https://cdn.tailwindcss.com"></script>

    <link
        rel="stylesheet"
        href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">

</head>


<body
    class="bg-gray-950
           text-white
           min-h-screen
           select-none">


<header
    class="sticky
           top-0
           z-50
           bg-gray-950/95
           backdrop-blur
           border-b
           border-gray-800">

    <div
        class="max-w-lg
               mx-auto
               px-4
               py-3
               flex
               items-center
               justify-between">


        <a
            href="<?= $user_logged_in ? "index.php" : "login.php" ?>"
            class="flex
                   items-center
                   gap-3">

            <div
                class="w-10
                       h-10
                       rounded-xl
                       bg-indigo-600
                       flex
                       items-center
                       justify-center">

                <i
                    class="fa-solid
                          fa-trophy"></i>

            </div>

            <div>

                <h1
                    class="font-bold
                           leading-none">

                    Battle Arena

                </h1>

                <p
                    class="text-[10px]
                           text-gray-500
                           mt-1">

                    Tournament Platform

                </p>

            </div>

        </a>


        <?php if ($user_logged_in): ?>

            <a
                href="wallet.php"
                class="flex
                       items-center
                       gap-2
                       bg-gray-900
                       border
                       border-gray-800
                       rounded-xl
                       px-3
                       py-2">

                <i
                    class="fa-solid
                          fa-wallet
                          text-indigo-400"></i>

                <span
                    class="text-sm
                           font-semibold">

                    ₹<?= number_format(
                        $wallet_balance,
                        2
                    ) ?>

                </span>

            </a>

        <?php endif; ?>

    </div>

</header>


<main
    class="max-w-lg
           mx-auto
           px-4
           pb-24">