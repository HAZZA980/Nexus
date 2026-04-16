<?php
namespace NexusCMS\Support;

final class PagePath
{
  public static function normalizeSegment(string $segment): string
  {
    $segment = strtolower(trim($segment));
    $segment = preg_replace('/[^a-z0-9-]+/', '-', $segment);
    $segment = preg_replace('/-+/', '-', $segment);
    return trim((string)$segment, '-');
  }

  public static function split(string $path): array
  {
    $path = trim(str_replace('\\', '/', $path), '/');
    if ($path === '') return [];
    $parts = preg_split('#/+#', $path) ?: [];
    $parts = array_map([self::class, 'normalizeSegment'], $parts);
    return array_values(array_filter($parts, static fn($part) => $part !== ''));
  }

  public static function normalizePath(string $path): string
  {
    return implode('/', self::split($path));
  }

  public static function join(array $segments): string
  {
    $parts = [];
    foreach ($segments as $segment) {
      if (!is_string($segment)) continue;
      $normalized = self::normalizeSegment($segment);
      if ($normalized !== '') $parts[] = $normalized;
    }
    return implode('/', $parts);
  }

  public static function encodeForUrl(string $path): string
  {
    $parts = self::split($path);
    return implode('/', array_map('rawurlencode', $parts));
  }

  public static function publicUrl(string $base, string $siteSlug, string $pagePath): string
  {
    $base = rtrim($base, '/');
    $siteSlug = rawurlencode(trim($siteSlug));
    $pagePath = self::encodeForUrl($pagePath);
    return $base . '/s/' . $siteSlug . ($pagePath !== '' ? '/' . $pagePath : '');
  }
}
