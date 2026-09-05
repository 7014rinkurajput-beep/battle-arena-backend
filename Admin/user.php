<?php

require_once "../Common/config.php";

require_admin_login();

$page_title = "Users";

$message = "";
$error = "";


// =====================================================
// HANDLE USER ACTIONS
// =====================================================

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $action = $_POST["action"] ?? "";


    // -------------------------------------------------
    // BLOCK / UNBLOCK
    // -------------------------------------------------

    if (
        $action === "toggle_block"
    ) {

        $user_id = filter_input(
            INPUT_POST,
            "user_id",
            FILTER_VALIDATE_INT
        );


        if (!$user_id) {

            $error = "Invalid user.";

        } else {

            /*
             * The original database schema does not define
             * a blocked column.
             *
             * Therefore this version does not silently
             * modify the database schema.
             *
             * A block feature can be added later when the
             * database is explicitly extended.
             */

            $error =
                "User blocking is not enabled in the current database schema.";
        }
    }
}


// =====================================================
// GET USERS
// =====================================================

$users = [];


$result = $conn->query(
    "SELECT
        id,
        username,
        email,
        wallet_balance,
        created_at
     FROM users
     ORDER BY created_at DESC"
);


if ($result) {

    while ($row = $result->fetch_assoc()) {

        $users[] = $row;
    }
}

?>

<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0">

    <title>
        Users - Admin
    </title>

    <script src="https://cdn.tailwindcss.com"></script>

    <link
        rel="stylesheet"
        href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">

</head>


<body
    class="bg-gray-950
           text-white
           min-h-screen">


<header
    class="sticky top-0 z-50
           bg-gray-950/95
           backdrop-blur
           border-b border-gray-800">

    <div
        class="max-w-lg mx-auto
               px-4 py-4
               flex items-center gap-3">

        <a
            href="dashboard.php"
            class="w-10 h-10
                   rounded-xl
                   bg-gray-900
                   border border-gray-800
                   flex items-center
                   justify-center">

            <i class="fa-solid fa-arrow-left"></i>

        </a>


        <div>

            <p
                class="text-xs
                       text-gray-500">

                Admin Panel

            </p>

            <h1 class="font-bold">

                Users

            </h1>

        </div>

    </div>

</header>


<main
    class="max-w-lg mx-auto
           px-4 py-6 pb-10">


    <div class="mb-6">

        <p
            class="text-xs
                   text-indigo-400
                   font-semibold">

            USER MANAGEMENT

        </p>


        <h2
            class="text-2xl
                   font-bold mt-1">

            Registered Users

        </h2>


        <p
            class="text-sm
                   text-gray-500 mt-2">

            <?= count($users) ?>
            registered user(s)

        </p>

    </div>


    <?php if ($message !== ""): ?>

        <div
            class="bg-green-950/50
                   border border-green-800
                   rounded-xl
                   p-4 mb-5
                   text-sm
                   text-green-300">

            <?= e($message) ?>

        </div>

    <?php endif; ?>


    <?php if ($error !== ""): ?>

        <div
            class="bg-red-950/50
                   border border-red-800
                   rounded-xl
                   p-4 mb-5
                   text-sm
                   text-red-300">

            <?= e($error) ?>

        </div>

    <?php endif; ?>


    <?php if (count($users) > 0): ?>

        <div class="space-y-4">

            <?php foreach ($users as $user): ?>

                <div
                    class="bg-gray-900
                           border border-gray-800
                           rounded-2xl
                           p-5">


                    <div
                        class="flex
                               items-start
                               justify-between
                               gap-3">


                        <div
                            class="flex
                                   items-center
                                   gap-3
                                   min-w-0">


                            <div
                                class="w-11 h-11
                                       rounded-xl
                                       bg-indigo-950
                                       flex items-center
                                       justify-center
                                       shrink-0">

                                <i
                                    class="fa-solid
                                          fa-user
                                          text-indigo-400"></i>

                            </div>


                            <div class="min-w-0">

                                <h3
                                    class="font-bold
                                           truncate">

                                    <?= e(
                                        $user["username"]
                                    ) ?>

                                </h3>


                                <p
                                    class="text-xs
                                           text-gray-500
                                           truncate">

                                    <?= e(
                                        $user["email"]
                                    ) ?>

                                </p>

                            </div>

                        </div>


                        <span
                            class="text-[10px]
                                   bg-green-950
                                   text-green-400
                                   px-2 py-1
                                   rounded-full
                                   shrink-0">

                            Active

                        </span>

                    </div>


                    <div
                        class="grid
                               grid-cols-2
                               gap-3 mt-4">


                        <div
                            class="bg-gray-950
                                   rounded-xl
                                   p-3">

                            <p
                                class="text-[10px]
                                       text-gray-500">

                                Wallet

                            </p>


                            <p
                                class="font-bold mt-1">

                                ₹<?= number_format(
                                    (float)$user["wallet_balance"],
                                    2
                                ) ?>

                            </p>

                        </div>


                        <div
                            class="bg-gray-950
                                   rounded-xl
                                   p-3">

                            <p
                                class="text-[10px]
                                       text-gray-500">

                                Joined

                            </p>


                            <p
                                class="text-xs
                                       font-semibold mt-1">

                                <?= e(
                                    date(
                                        "d M Y",
                                        strtotime(
                                            $user["created_at"]
                                        )
                                    )
                                ) ?>

                            </p>

                        </div>

                    </div>


                    <div
                        class="mt-4
                               bg-gray-950
                               rounded-xl
                               p-3">

                        <p
                            class="text-xs
                                   text-gray-500">

                            User ID

                        </p>


                        <p
                            class="font-mono
                                   text-sm mt-1">

                            #<?= (int)$user["id"] ?>

                        </p>

                    </div>


                    <!-- MATCH HISTORY -->

                    <a
                        href="manage_tournament.php"
                        class="block
                               text-center
                               mt-4
                               bg-gray-800
                               border border-gray-700
                               rounded-xl
                               py-3
                               text-sm
                               font-semibold">

                        <i
                            class="fa-solid
                                  fa-clock-rotate-left
                                  mr-1"></i>

                        Match Management

                    </a>

                </div>

            <?php endforeach; ?>

        </div>

    <?php else: ?>

        <div
            class="bg-gray-900
                   border border-gray-800
                   rounded-2xl
                   p-8
                   text-center">

            <i
                class="fa-solid
                      fa-users
                      text-3xl
                      text-gray-700"></i>


            <h3
                class="font-bold
                       mt-4">

                No Users

            </h3>


            <p
                class="text-sm
                       text-gray-500
                       mt-2">

                No registered users found.

            </p>

        </div>

    <?php endif; ?>


</main>


</body>
</html>