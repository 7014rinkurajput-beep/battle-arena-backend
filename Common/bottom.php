</main>


<?php if (
    isset($_SESSION["user_id"]) &&
    is_numeric($_SESSION["user_id"])
): ?>

<nav
    class="fixed
           bottom-0
           left-0
           right-0
           z-50
           bg-gray-950/95
           backdrop-blur
           border-t
           border-gray-800">


    <div
        class="max-w-lg
               mx-auto
               grid
               grid-cols-4">


        <!-- HOME -->

        <a
            href="index.php"
            class="flex
                   flex-col
                   items-center
                   justify-center
                   py-3
                   text-gray-400
                   hover:text-indigo-400">

            <i
                class="fa-solid
                      fa-house
                      text-lg"></i>

            <span
                class="text-[10px]
                       mt-1">

                Home

            </span>

        </a>


        <!-- MY TOURNAMENTS -->

        <a
            href="my_tournaments.php"
            class="flex
                   flex-col
                   items-center
                   justify-center
                   py-3
                   text-gray-400
                   hover:text-indigo-400">

            <i
                class="fa-solid
                      fa-trophy
                      text-lg"></i>

            <span
                class="text-[10px]
                       mt-1">

                Tournaments

            </span>

        </a>


        <!-- WALLET -->

        <a
            href="wallet.php"
            class="flex
                   flex-col
                   items-center
                   justify-center
                   py-3
                   text-gray-400
                   hover:text-indigo-400">

            <i
                class="fa-solid
                      fa-wallet
                      text-lg"></i>

            <span
                class="text-[10px]
                       mt-1">

                Wallet

            </span>

        </a>


        <!-- PROFILE -->

        <a
            href="profile.php"
            class="flex
                   flex-col
                   items-center
                   justify-center
                   py-3
                   text-gray-400
                   hover:text-indigo-400">

            <i
                class="fa-solid
                      fa-user
                      text-lg"></i>

            <span
                class="text-[10px]
                       mt-1">

                Profile

            </span>

        </a>

    </div>

</nav>

<?php endif; ?>


<script>

// =====================================================
// MOBILE APP STYLE
// =====================================================

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


// Disable keyboard zoom
document.addEventListener(
    "keydown",
    function(event) {

        if (
            event.ctrlKey &&
            (
                event.key === "+" ||
                event.key === "-" ||
                event.key === "=" ||
                event.key === "0"
            )
        ) {

            event.preventDefault();

        }

    }
);


// Disable Ctrl + mouse wheel zoom
document.addEventListener(
    "wheel",
    function(event) {

        if (event.ctrlKey) {
            event.preventDefault();
        }

    },
    {
        passive: false
    }
);

</script>


</body>
</html>