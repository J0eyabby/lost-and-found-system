<?php
require_once __DIR__ . "/session_bootstrap.php";
require_once __DIR__ . "/db.php";
require_once __DIR__ . "/account_guard.php";
enforceActiveAccount($conn);
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>How Lost and Found Works</title>
    <link rel="stylesheet" href="css/style.css?v=theme-20261014-10">
</head>
<body>
<?php require __DIR__ . "/site_header.php"; ?>
<main class="container legal-page help-page">
    <header class="legal-heading">
        <p class="eyebrow">Help and support</p>
        <h1>How Lost and Found Works</h1>
        <p>Use these simple steps to report an item, search the noticeboard, and handle claims safely.</p>
    </header>

    <div class="help-grid">
        <section class="help-card">
            <span class="help-step">1</span>
            <h2>Report a lost or found item</h2>
            <p>Sign in, add the item name, category, location, date, description, and a clear photo when available. Keep private identifying details out of the public description.</p>
            <a class="button button-small button-secondary" href="login.php?next=report_item.php">Report an item</a>
        </section>
        <section class="help-card">
            <span class="help-step">2</span>
            <h2>Search and compare listings</h2>
            <p>Search by keywords and narrow results by status, category, location, and date. Open an item to see its full public details and shareable link.</p>
            <a class="button button-small button-secondary" href="index.php#find-an-item">Find an item</a>
        </section>
        <section class="help-card">
            <span class="help-step">3</span>
            <h2>Submit a claim carefully</h2>
            <p>For a found item, submit information that reasonably demonstrates ownership. The administrator may request more information before making a decision.</p>
            <a class="button button-small button-secondary" href="found_items.php">Browse found items</a>
        </section>
        <section class="help-card">
            <span class="help-step">4</span>
            <h2>Follow updates and arrange a safe handover</h2>
            <p>Check your notifications and claims dashboard for updates. Only arrange a handover after verification, and avoid sharing sensitive information publicly.</p>
            <a class="button button-small button-secondary" href="terms.php#claims">Read claim guidance</a>
        </section>
    </div>

    <section class="help-support">
        <h2>Need help?</h2>
        <p>For account, report, or claim questions, sign in and use the support options in Settings. If you suspect abuse or unauthorized access, notify an administrator promptly.</p>
        <div class="help-actions">
            <a class="button button-secondary" href="settings.php#help-support">Open support settings</a>
            <a class="button button-secondary" href="terms.php">Terms and privacy</a>
        </div>
    </section>
</main>
<?php require __DIR__ . "/site_footer.php"; ?>
</body>
</html>
