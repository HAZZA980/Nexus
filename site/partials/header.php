<?php
$preset = $header['preset'];
$variant = $header['style']['variant'] ?? 'light';
$sticky  = !empty($header['style']['sticky']);
?>

<header class="nx-site-header nx-header--<?= Security::e($variant) ?> <?= $sticky ? 'is-sticky' : '' ?>">
  <div class="nx-header-inner">
    <div class="nx-brand">
      <?= Security::e($header['brandText'] ?? '') ?>
    </div>

    <nav class="nx-nav">
      <?php foreach ($header['items'] as $item): ?>
        <a href="<?= Security::e($item['href']) ?>">
          <?= Security::e($item['label']) ?>
        </a>
      <?php endforeach; ?>
    </nav>

    <?php if (!empty($header['cta']['label'])): ?>
      <a class="nx-btn-primary" href="<?= Security::e($header['cta']['href']) ?>">
        <?= Security::e($header['cta']['label']) ?>
      </a>
    <?php endif; ?>
  </div>
</header>
