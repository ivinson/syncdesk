<?php //Social media sharing meta tags (delete if you don't want them)
 ?>
<meta charset="utf-8">
<meta http-equiv="X-UA-Compatible" content="IE=edge">
<?php 
//viewport tag is inside the template
// <meta name="viewport" content="width=device-width, initial-scale=1">
?>
<meta name="description" content="">
<meta name="author" content="">

<?php //URL for website (link address) ?>
<meta property="og:url" content="">

<?php //type of site ?>
<meta property="og:type" content="website">

<?php //title of site (title of share) ?>
<meta property="og:title" content="<?= $settings->site_name ?>">

<?php //description of site (text which appears when sharing) ?>
<meta property="og:description" content="<?= $settings->copyright ?>">

<?php //URL for preview image ?>
<meta property="og:image" content="">
<link rel="shortcut icon" href="<?=$us_url_root?>favicon.ico">

<?php
// Check if the current page is the login screen to inject custom branding overrides
if (strpos($_SERVER['PHP_SELF'], 'login.php') !== false) {
?>
    <!-- Premium Brand Style Overrides for UserSpice Login Screen -->
    <style>
        body {
            background: radial-gradient(circle at 50% 50%, #0b0f19 0%, #020617 100%) !important;
            min-height: 100vh;
            display: flex !important;
            align-items: center !important;
            justify-content: center !important;
        }

        /* Strictly hide the navbar, headers, footers, and copyright text blocks */
        body > nav, 
        body > .navbar, 
        body > footer, 
        body > header,
        body > p,
        nav, 
        .navbar, 
        header, 
        footer, 
        .footer, 
        #footer, 
        .copyright {
            display: none !important;
        }

        /* Remove any stray text-center paragraphs that are not inside the login modal card */
        p.text-center:not(#loginModal p),
        div.text-center:not(#loginModal div) {
            display: none !important;
        }

        /* Hide registration link */
        a[href*="join.php"] {
            display: none !important;
        }

        #loginModal {
            background: transparent !important;
            display: flex !important;
            align-items: center !important;
            justify-content: center !important;
            position: relative !important;
        }

        #loginModal .modal-dialog {
            margin: 0 auto !important;
            width: 100% !important;
            max-width: 440px !important;
        }

        /* Glassmorphism Dark Theme Card */
        #loginModal .modal-content {
            background: rgba(11, 15, 25, 0.75) !important;
            backdrop-filter: blur(16px) !important;
            -webkit-backdrop-filter: blur(16px) !important;
            border: 1px solid rgba(255, 255, 255, 0.08) !important;
            border-radius: 24px !important;
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.6) !important;
            color: #ffffff !important;
            padding: 1rem 1.5rem !important;
        }

        #loginModal .modal-header {
            border-bottom: 1px solid rgba(255, 255, 255, 0.08) !important;
            padding-bottom: 1.25rem !important;
            display: flex !important;
            align-items: center !important;
            justify-content: space-between !important;
        }

        #loginModal .modal-header b {
            display: flex !important;
            align-items: center !important;
        }

        #loginModal .modal-header .btn-close, 
        #loginModal .modal-header .close {
            filter: invert(1) !important;
            opacity: 0.6 !important;
            outline: none !important;
        }

        #loginModal .modal-header .btn-close:hover, 
        #loginModal .modal-header .close:hover {
            opacity: 1 !important;
        }

        /* Form Inputs Integration */
        #loginModal .form-control {
            background-color: rgba(255, 255, 255, 0.04) !important;
            border: 1px solid rgba(255, 255, 255, 0.12) !important;
            color: #ffffff !important;
            border-radius: 10px !important;
            padding: 0.65rem 1rem !important;
            font-size: 0.95rem !important;
            transition: all 0.2s ease-in-out !important;
        }

        #loginModal .form-control:focus {
            background-color: rgba(255, 255, 255, 0.06) !important;
            border-color: #e11d48 !important;
            box-shadow: 0 0 0 3px rgba(225, 29, 72, 0.3) !important;
            color: #ffffff !important;
        }

        #loginModal .form-label {
            color: #94a3b8 !important;
            font-weight: 500 !important;
            font-size: 0.88rem !important;
            margin-bottom: 0.5rem !important;
        }

        #loginModal .input-group-text {
            background-color: rgba(255, 255, 255, 0.04) !important;
            border: 1px solid rgba(255, 255, 255, 0.12) !important;
            border-left: none !important;
            color: #94a3b8 !important;
            border-radius: 0 10px 10px 0 !important;
        }

        /* Button Styling Overrides to Sync Magenta */
        #loginModal .btn-primary, 
        #loginModal button.submit {
            background-color: #e11d48 !important;
            border-color: #e11d48 !important;
            border-radius: 10px !important;
            padding: 0.7rem 1.5rem !important;
            font-weight: 600 !important;
            font-size: 0.95rem !important;
            transition: all 0.2s ease-in-out !important;
            color: #ffffff !important;
        }

        #loginModal .btn-primary:hover, 
        #loginModal button.submit:hover {
            background-color: #be123c !important;
            border-color: #be123c !important;
            transform: translateY(-1px) !important;
            box-shadow: 0 4px 12px rgba(225, 29, 72, 0.3) !important;
        }

        /* Text and Links Styling */
        #loginModal a {
            color: #f43f5e !important;
            font-weight: 500 !important;
            transition: all 0.2s ease !important;
            text-decoration: none !important;
        }

        #loginModal a:hover {
            color: #be123c !important;
            text-decoration: underline !important;
        }

        #loginModal .form-text, 
        #loginModal .text-body-secondary, 
        #loginModal p.text-body-secondary {
            color: #64748b !important;
        }

        #loginModal .modal-body {
            padding-top: 1.5rem !important;
        }

        /* Copyright alignment */
        .alternate-background + p,
        body > p.text-center {
            color: #64748b !important;
            font-size: 0.85rem !important;
            margin-top: 1.5rem !important;
        }
    </style>

    <!-- DOM Injection for the Sync Brand Logo -->
    <script>
        document.addEventListener("DOMContentLoaded", function() {
            // Find the modal title inside the header
            const modalHeaderTitle = document.querySelector("#loginModal .modal-header b");
            if (modalHeaderTitle) {
                // If it is not the Two-Factor Authentication window, replace text with logo_magenta.png
                if (modalHeaderTitle.textContent.trim().indexOf("Two-Factor") === -1 && 
                    modalHeaderTitle.textContent.trim().indexOf("2FA") === -1) {
                    modalHeaderTitle.innerHTML = '<img src="../assets/logo_magenta.png" alt="Sync Logo" style="max-height: 38px; max-width: 140px; object-fit: contain;">';
                }
            }
        });
    </script>
<?php
}
?>
