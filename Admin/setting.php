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
// HELPER FUNCTION
// =====================================================

function get_setting($conn, $key)
{
    $stmt = $conn->prepare(
        "SELECT setting_value
         FROM settings
         WHERE setting_key = ?
         LIMIT 1"
    );

    if (!$stmt) {
        return "";
    }

    $stmt->bind_param("s", $key);
    $stmt->execute();

    $result = $stmt->get_result();
    $row = $result->fetch_assoc();

    $stmt->close();

    return $row["setting_value"] ?? "";
}


// =====================================================
// VARIABLES
// =====================================================

$message = "";
$message_type = "";


// =====================================================
// CURRENT SETTINGS
// =====================================================

$admin_upi_id =
    get_setting($conn, "admin_upi_id");

$admin_qr_code =
    get_setting($conn, "admin_qr_code");


// =====================================================
// SAVE SETTINGS
// =====================================================

if (
    $_SERVER["REQUEST_METHOD"] === "POST" &&
    isset($_POST["save_settings"])
) {

    $upi_id =
        trim($_POST["admin_upi_id"] ?? "");


    // -------------------------------------------------
    // Basic validation
    // -------------------------------------------------

    if (strlen($upi_id) > 255) {

        $message =
            "UPI ID is too long.";

        $message_type = "error";

    } else {

        // -------------------------------------------------
        // SAVE UPI DISPLAY VALUE
        // -------------------------------------------------

        $stmt = $conn->prepare(
            "INSERT INTO settings
                (setting_key, setting_value)
             VALUES
                ('admin_upi_id', ?)
             ON DUPLICATE KEY UPDATE
                setting_value = VALUES(setting_value)"
        );


        if (!$stmt) {

            $message =
                "Unable to save settings.";

            $message_type = "error";

        } else {

            $stmt->bind_param(
                "s",
                $upi_id
            );


            if ($stmt->execute()) {

                $message =
                    "Settings saved successfully.";

                $message_type =
                    "success";

                $admin_upi_id =
                    $upi_id;

            } else {

                $message =
                    "Unable to save settings.";

                $message_type =
                    "error";
            }


            $stmt->close();
        }
    }


    // =================================================
    // QR CODE UPLOAD
    // =================================================

    if (
        isset($_FILES["qr_code"]) &&
        $_FILES["qr_code"]["error"] !== UPLOAD_ERR_NO_FILE
    ) {

        if (
            $_FILES["qr_code"]["error"] !== UPLOAD_ERR_OK
        ) {

            $message =
                "QR image upload failed.";

            $message_type =
                "error";

        } else {

            $file =
                $_FILES["qr_code"];


            // ---------------------------------------------
            // Maximum file size: 2 MB
            // ---------------------------------------------

            if ($file["size"] > 2 * 1024 * 1024) {

                $message =
                    "QR image must be smaller than 2 MB.";

                $message_type =
                    "error";

            } else {

                // ---------------------------------------------
                // Check MIME type
                // ---------------------------------------------

                $finfo =
                    finfo_open(FILEINFO_MIME_TYPE);

                $mime =
                    finfo_file(
                        $finfo,
                        $file["tmp_name"]
                    );

                finfo_close($finfo);


                $allowed_types = [
                    "image/jpeg" => "jpg",
                    "image/png"  => "png",
                    "image/webp" => "webp"
                ];


                if (
                    !isset(
                        $allowed_types[$mime]
                    )
                ) {

                    $message =
                        "Only JPG, PNG or WEBP images are allowed.";

                    $message_type =
                        "error";

                } else {

                    // -----------------------------------------
                    // Upload directory
                    // -----------------------------------------

                    $upload_dir =
                        "../uploads/";


                    if (
                        !is_dir($upload_dir)
                    ) {

                        mkdir(
                            $upload_dir,
                            0755,
                            true
                        );
                    }


                    // -----------------------------------------
                    // Generate safe filename
                    // -----------------------------------------

                    $filename =
                        "admin_qr_" .
                        bin2hex(
                            random_bytes(8)
                        ) .
                        "." .
                        $allowed_types[$mime];


                    $destination =
                        $upload_dir .
                        $filename;


                    // -----------------------------------------
                    // Move uploaded file
                    // -----------------------------------------

                    if (
                        move_uploaded_file(
                            $file["tmp_name"],
                            $destination
                        )
                    ) {

                        $qr_path =
                            "uploads/" .
                            $filename;


                        // -------------------------------------
                        // Delete old QR file if present
                        // -------------------------------------

                        $old_qr =
                            get_setting(
                                $conn,
                                "admin_qr_code"
                            );


                        if (
                            $old_qr !== "" &&
                            strpos(
                                $old_qr,
                                "uploads/"
                            ) === 0
                        ) {

                            $old_file =
                                "../" .
                                $old_qr;


                            if (
                                is_file($old_file)
                            ) {

                                unlink($old_file);
                            }
                        }


                        // -------------------------------------
                        // Save new QR path
                        // -------------------------------------

                        $stmt =
                            $conn->prepare(
                                "INSERT INTO settings
                                    (setting_key, setting_value)
                                 VALUES
                                    ('admin_qr_code', ?)
                                 ON DUPLICATE KEY UPDATE
                                    setting_value =
                                    VALUES(setting_value)"
                            );


                        if ($stmt) {

                            $stmt->bind_param(
                                "s",
                                $qr_path
                            );


                            if (
                                $stmt->execute()
                            ) {

                                $admin_qr_code =
                                    $qr_path;

                                $message =
                                    "Settings and QR image saved successfully.";

                                $message_type =
                                    "success";

                            } else {

                                $message =
                                    "QR uploaded but could not be saved.";

                                $message_type =
                                    "error";
                            }


                            $stmt->close();

                        } else {

                            $message =
                                "Unable to save QR information.";

                            $message_type =
                                "error";
                        }

                    } else {

                        $message =
                            "Unable to upload QR image.";

                        $message_type =
                            "error";
                    }
                }
            }
        }
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
        Admin Settings - Battle Arena
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


<!-- =====================================================
     HEADER
===================================================== -->

<header
    class="sticky
           top-0
           z-50
           bg-gray-950/95
           backdrop-blur
           border-b
           border-gray-800">


    <div
        class="max-w-3xl
               mx-auto
               px-4
               py-4
               flex
               items-center
               gap-3">


        <a
            href="dashboard.php"
            class="w-10
                   h-10
                   rounded-xl
                   bg-gray-800
                   flex
                   items-center
                   justify-center
                   hover:bg-gray-700">


            <i
                class="fa-solid
                       fa-arrow-left"></i>


        </a>


        <div>

            <p
                class="text-xs
                       text-gray-500">

                Admin Panel

            </p>


            <h1
                class="font-bold">

                Settings

            </h1>

        </div>

    </div>

</header>


<!-- =====================================================
     MAIN
===================================================== -->

<main
    class="max-w-3xl
           mx-auto
           px-4
           py-6
           pb-12">


    <!-- =================================================
         PAGE INTRO
    ================================================== -->

    <section
        class="mb-6">


        <p
            class="text-indigo-400
                   text-xs
                   font-semibold">

            WALLET CONFIGURATION

        </p>


        <h2
            class="text-2xl
                   font-bold
                   mt-1">

            Payment Display Settings

        </h2>


        <p
            class="text-gray-500
                   text-sm
                   mt-2">

            Configure the information displayed in the
            wallet's test-credit interface.

        </p>

    </section>


    <!-- =================================================
         MESSAGE
    ================================================== -->

    <?php if ($message !== ""): ?>

        <div
            class="mb-6
                   rounded-xl
                   p-4
                   border
                   <?php

                   echo $message_type === "success"

                       ? "bg-green-950/50 border-green-800 text-green-300"

                       : "bg-red-950/50 border-red-800 text-red-300";

                   ?>">


            <i
                class="fa-solid
                       <?php

                       echo $message_type === "success"

                           ? "fa-circle-check"

                           : "fa-circle-exclamation";

                       ?>
                       mr-2"></i>


            <?= htmlspecialchars($message) ?>


        </div>

    <?php endif; ?>


    <!-- =================================================
         SETTINGS FORM
    ================================================== -->

    <section
        class="bg-gray-900
               border
               border-gray-800
               rounded-2xl
               p-5
               mb-6">


        <form
            method="POST"
            enctype="multipart/form-data"
            class="space-y-6">


            <input
                type="hidden"
                name="save_settings"
                value="1">


            <!-- =========================================
                 UPI DISPLAY VALUE
            ========================================== -->

            <div>


                <label
                    class="block
                           text-sm
                           font-medium
                           text-gray-300
                           mb-2">


                    UPI ID / Display ID


                </label>


                <input
                    type="text"
                    name="admin_upi_id"
                    value="<?= htmlspecialchars($admin_upi_id) ?>"
                    maxlength="255"
                    placeholder="example@upi"
                    class="w-full
                           bg-gray-950
                           border
                           border-gray-800
                           rounded-xl
                           px-4
                           py-3.5
                           outline-none
                           focus:border-indigo-500">


                <p
                    class="text-xs
                           text-gray-500
                           mt-2">

                    This value is stored as configuration
                    for the test-credit wallet interface.

                </p>

            </div>


            <!-- =========================================
                 QR CODE
            ========================================== -->

            <div>


                <label
                    class="block
                           text-sm
                           font-medium
                           text-gray-300
                           mb-2">


                    QR Code Image


                </label>


                <div
                    class="border
                           border-dashed
                           border-gray-700
                           rounded-xl
                           p-5">


                    <input
                        type="file"
                        name="qr_code"
                        accept="image/png,image/jpeg,image/webp"
                        class="w-full
                               text-sm
                               text-gray-400">


                    <p
                        class="text-xs
                               text-gray-500
                               mt-2">

                        JPG, PNG or WEBP • Maximum 2 MB

                    </p>

                </div>

            </div>


            <!-- =========================================
                 CURRENT QR
            ========================================== -->

            <?php if ($admin_qr_code !== ""): ?>

                <div>


                    <p
                        class="text-sm
                               font-medium
                               text-gray-300
                               mb-3">

                        Current QR Image

                    </p>


                    <div
                        class="bg-white
                               rounded-2xl
                               p-4
                               w-fit">


                        <img
                            src="../<?= htmlspecialchars($admin_qr_code) ?>"
                            alt="Current QR Code"
                            class="w-52
                                   h-52
                                   object-contain">


                    </div>

                </div>

            <?php endif; ?>


            <!-- =========================================
                 SAVE BUTTON
            ========================================== -->

            <button
                type="submit"
                class="w-full
                       bg-indigo-600
                       hover:bg-indigo-500
                       rounded-xl
                       py-3.5
                       font-semibold
                       transition">


                <i
                    class="fa-solid
                           fa-floppy-disk
                           mr-2"></i>


                Save Settings


            </button>


        </form>

    </section>


    <!-- =================================================
         STATUS CARD
    ================================================== -->

    <section
        class="bg-gray-900
               border
               border-gray-800
               rounded-2xl
               p-5">


        <div
            class="flex
                   items-start
                   gap-3">


            <div
                class="w-10
                       h-10
                       rounded-xl
                       bg-indigo-950
                       flex
                       items-center
                       justify-center
                       shrink-0">


                <i
                    class="fa-solid
                           fa-circle-info
                           text-indigo-400"></i>


            </div>


            <div>


                <h3
                    class="font-semibold">

                    Test-Credit Wallet

                </h3>


                <p
                    class="text-sm
                           text-gray-500
                           mt-1">

                    The wallet system currently uses
                    test credits. No real-money payment
                    is processed by this page.

                </p>

            </div>


        </div>


    </section>


</main>


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