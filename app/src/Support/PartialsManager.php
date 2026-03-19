<?php
namespace NexusCMS\Support;

final class PartialsManager {
  public static function safeSlug(string $slug): string {
    $slug = strtolower($slug);
    $slug = preg_replace('/[^a-z0-9-]+/', '-', $slug);
    $slug = trim($slug, '-');
    return $slug;
  }

  public static function projectRoot(): string {
    // __DIR__ is app/src/Support — go up 3 levels to repo root
    $root = realpath(dirname(__DIR__, 3));
    return $root ?: dirname(__DIR__, 3);
  }

  public static function paths(string $slug): array {
    $slug = self::safeSlug($slug);
    $root = rtrim(self::projectRoot(), '/');
    $siteDir = $root . '/sites/' . $slug;
    $partialsDir = $siteDir . '/partials';
    $assetsDir = $siteDir . '/assets';
    return [
      'root' => $root,
      'siteDir' => $siteDir,
      'partialsDir' => $partialsDir,
      'header' => $partialsDir . '/header.php',
      'footer' => $partialsDir . '/footer.php',
      'assetsDir' => $assetsDir,
      'css' => $assetsDir . '/site.css',
      'js' => $assetsDir . '/site.js',
    ];
  }

  public static function ensureFile(string $path, string $root, string $content): string {
    $path = str_replace('\\', '/', $path);
    $root = rtrim(str_replace('\\', '/', $root), '/');
    $dir = dirname($path);
    if (strpos($path, '..') !== false) throw new \RuntimeException('Invalid path');
    if (strncmp($path, $root, strlen($root)) !== 0) throw new \RuntimeException('Path outside project root');
    if (!is_dir($dir)) {
      if (!mkdir($dir, 0775, true) && !is_dir($dir)) {
        throw new \RuntimeException('Failed to create directory: ' . $dir);
      }
    }
    if (!file_exists($path)) {
      file_put_contents($path, $content);
      return 'created';
    }
    return 'exists';
  }

  public static function boilerplateHeader(string $siteSlug, string $siteName): string {
    $safeSlug = self::safeSlug($siteSlug);
    $name = $siteName ?: 'Site';
    return <<<PHP
<?php
// Header partial for {$name} ({$safeSlug})
?>
<header class="site-header" style="padding:16px 20px;border-bottom:1px solid rgba(0,0,0,.08);display:flex;align-items:center;justify-content:space-between;gap:12px">
  <div style="font-weight:700;font-size:18px">{$name}</div>
  <nav aria-label="Primary">
    <ul style="display:flex;gap:14px;list-style:none;padding:0;margin:0;">
      <li><a href="/s/{$safeSlug}/home">Home</a></li>
      <li><a href="/s/{$safeSlug}/about">About</a></li>
      <li><a href="/s/{$safeSlug}/contact">Contact</a></li>
    </ul>
  </nav>
  <form action="/s/{$safeSlug}/search" method="get" role="search" style="display:flex;gap:8px;align-items:center">
    <label class="sr-only" for="site-search">Search</label>
    <input id="site-search" name="q" type="search" placeholder="Search {$name}" style="padding:8px 10px;border:1px solid #cbd5e1;border-radius:8px;min-width:180px">
    <button type="submit" style="padding:8px 12px;border-radius:8px;border:1px solid #cbd5e1;background:#2563eb;color:#fff;cursor:pointer">Search</button>
  </form>
</header>
PHP;
  }

  public static function boilerplateFooter(string $siteSlug, string $siteName): string {
    $safeSlug = self::safeSlug($siteSlug);
    $year = date('Y');
    $name = $siteName ?: 'Site';
    return <<<PHP
<?php
// Footer partial for {$name} ({$safeSlug})
?>
<footer class="site-footer" style="padding:18px 20px;border-top:1px solid rgba(0,0,0,.08);background:#0f172a;color:#e7ecf4">
  <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px">
    <div style="font-weight:700">{$name}</div>
    <div style="display:flex;gap:14px;flex-wrap:wrap">
      <a href="/s/{$safeSlug}/privacy" style="color:#cbd5f5;text-decoration:none">Privacy</a>
      <a href="/s/{$safeSlug}/terms" style="color:#cbd5f5;text-decoration:none">Terms</a>
      <a href="/s/{$safeSlug}/contact" style="color:#cbd5f5;text-decoration:none">Contact</a>
    </div>
  </div>
  <div style="margin-top:6px;color:rgba(231,236,244,.75)">© {$year} {$name}</div>
</footer>
PHP;
  }

  public static function boilerplateCss(): string {
    return "/* Site-level CSS overrides */\nbody { }\n";
  }

  public static function boilerplateJs(): string {
    return "// Site-level JS hooks\n";
  }
}
