<?php
$siteFooterBrand = isset($siteBrand) && trim((string) $siteBrand) !== ""
    ? (string) $siteBrand
    : "LOST AND FOUND";
?>
<footer class="site-footer">
    <p>&copy; <?= date("Y") ?> <?= htmlspecialchars($siteFooterBrand, ENT_QUOTES, "UTF-8") ?>. All rights reserved.</p>
    <a href="help.php">How it works</a>
    <a href="terms.php">Terms &amp; Privacy</a>
</footer>
