<?php
$basePrefix = rtrim((string)($base ?? (function_exists('base_path') ? base_path() : '')), '/');
$siteSlug = (string)($safeSlug ?? ($site['slug'] ?? 'cite-them-right'));
$siteBase = $basePrefix . '/s/' . rawurlencode($siteSlug);
?>
<footer class="ctr-footer">
  <div class="ctr-footer-top">
    <div class="ctr-shell ctr-footer-top-inner">
      <div class="ctr-footer-logo">
        <img src="https://res.cloudinary.com/bloomsbury-publishing-public/image/upload/f_auto%2Cq_auto/CTRCOL/citethemrightlogo.png" alt="Cite Them Right" />
      </div>
      <div class="ctr-footer-links">
        <a href="<?= htmlspecialchars($siteBase . '/admin-portal', ENT_QUOTES, 'UTF-8') ?>">Manage Site Content</a>
        <a href="<?= htmlspecialchars($siteBase . '/how-to-access', ENT_QUOTES, 'UTF-8') ?>">How To Access</a>
        <a href="<?= htmlspecialchars($siteBase . '/about', ENT_QUOTES, 'UTF-8') ?>">About</a>
        <a href="<?= htmlspecialchars($siteBase . '/contact-us', ENT_QUOTES, 'UTF-8') ?>">Contact Us</a>
        <a href="<?= htmlspecialchars($siteBase . '/accessibility', ENT_QUOTES, 'UTF-8') ?>">Accessibility</a>
        <a href="<?= htmlspecialchars($siteBase . '/help', ENT_QUOTES, 'UTF-8') ?>">Help</a>
        <a href="<?= htmlspecialchars($siteBase . '/for-librarians', ENT_QUOTES, 'UTF-8') ?>">For Librarians</a>
      </div>
      <div class="ctr-footer-social">
        <a href="https://www.facebook.com/bloomsburyacademic" target="_blank" rel="noopener noreferrer">f</a>
        <a href="https://twitter.com/BloomsburyAcad" target="_blank" rel="noopener noreferrer">x</a>
      </div>
    </div>
  </div>

  <div class="ctr-footer-bottom">
    <div class="ctr-shell ctr-footer-bottom-inner">
      <div>Copyright Bloomsbury Publishing Plc 2025</div>
      <div class="ctr-footer-legal">
        <a href="<?= htmlspecialchars($siteBase . '/terms-and-conditions', ENT_QUOTES, 'UTF-8') ?>">Terms and Conditions</a>
        <a href="<?= htmlspecialchars($siteBase . '/privacy-policy', ENT_QUOTES, 'UTF-8') ?>">Privacy</a>
      </div>
    </div>
  </div>
</footer>
