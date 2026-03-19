<?php

namespace NexusCMS\Services;

use NexusCMS\Core\Security;

final class Renderer
{
public static function render(array $doc): string
{
  $rows = $doc['rows'] ?? [];
  $html = '';

  foreach ($rows as $row) {
$cols = is_array($row['cols'] ?? null) ? $row['cols'] : [];
$rowStyle = is_array($row['styleRow'] ?? null) ? $row['styleRow'] : [];
$bgEnabled = array_key_exists('bgEnabled', $rowStyle) ? (bool)$rowStyle['bgEnabled'] : true;
$equalHeight = !empty($row['equalHeight']);

$rowClass = $bgEnabled ? 'nx-row nx-row--panel' : 'nx-row nx-row--plain';
if ($equalHeight) $rowClass .= ' nx-row--equal';

$vars = [];
if ($bgEnabled && !empty($rowStyle['bgColor']) && is_string($rowStyle['bgColor'])) {
  $vars[] = '--nexus-row-bg:' . Security::e(trim($rowStyle['bgColor']));
}
if ($equalHeight) {
  $vars[] = '--nx-eq-cols:' . max(1, count($cols));
}
$styleAttr = $vars ? ' style="' . implode(';', $vars) . '"' : '';

$html .= '<div class="' . $rowClass . '"' . $styleAttr . '>';

    foreach ($cols as $col) {
      $span = (int)($col['span'] ?? 12);
      $span = max(1, min(12, $span));

      $html .= '<div class="nx-col nx-col-' . $span . '">';

      foreach (($col['blocks'] ?? []) as $blk) {
        if (is_array($blk)) $html .= self::block($blk);
      }

      $html .= '</div>';
    }

    $html .= '</div>';
  }

  // ✅ Page-level defaults (light bg + dark text) live on this wrapper.
  // Optional overrides can come from $doc['page'] via CSS variables.
  $page = is_array($doc['page'] ?? null) ? $doc['page'] : [];
  $vars = [];

  if (!empty($page['backgroundColor']) && is_string($page['backgroundColor'])) {
    $vars[] = '--nexus-page-bg:' . Security::e(trim($page['backgroundColor']));
  }
  if (!empty($page['textColor']) && is_string($page['textColor'])) {
    $vars[] = '--nexus-text:' . Security::e(trim($page['textColor']));
  }
  if (!empty($page['fontFamily']) && is_string($page['fontFamily'])) {
    $vars[] = '--nexus-font:' . Security::e(trim($page['fontFamily']));
  }
  if (isset($page['fontSize'])) {
    $fs = (int)$page['fontSize'];
    if ($fs > 0 && $fs <= 140) $vars[] = '--nexus-font-size:' . $fs . 'px';
  }

  $styleAttr = $vars ? ' style="' . implode(';', $vars) . '"' : '';

  return '<div class="nexus-page"' . $styleAttr . '>' . $html . '</div>';
}

  /**
   * ---- Block wrapper style (blk.style) ----
   * Allow-list + camelCase->kebab-case
   */
  private static function safeBlockCss(array $style): string
  {
    $allowed = [
      'background-color',
      'border',
      'border-radius',
      'box-shadow',
      'padding',
      'margin',
      'margin-bottom',
      'opacity',
      // you can extend carefully:
      'max-width',
      'min-height',
      'text-align', // sometimes used on wrappers
    ];

    $css = [];
    foreach ($style as $k => $v) {
      if (!is_string($k)) continue;
      if ($v === null) continue;

      $val = trim((string)$v);
      if ($val === '') continue;

      // prevent CSS injection chaining
      if (str_contains($val, ';')) continue;

      // camelCase -> kebab-case
      $prop = strtolower(preg_replace('/([a-z])([A-Z])/', '$1-$2', $k));

      if (!in_array($prop, $allowed, true)) continue;

      // allow only reasonable characters in values (still flexible)
      // (keeps rgb/rgba, px, %, spaces, commas, parentheses, #, etc.)
      if (!preg_match('/^[a-zA-Z0-9\s\.\,\#\-\(\)\/%:]+$/', $val)) continue;

      $css[] = $prop . ':' . Security::e($val);
    }

    return implode(';', $css);
  }

  /**
   * ---- Text style (blk.styleText) ----
   */
  private static function safeTextCss(array $styleText): string
  {
    $css = [];

    if (!empty($styleText['fontFamily'])) {
      $ff = preg_replace('/[^a-zA-Z0-9 ,"\-\(\)]+/', '', (string)$styleText['fontFamily']);
      if ($ff !== '') $css[] = "font-family:{$ff}";
    }

    if (!empty($styleText['fontSize'])) {
      $fs = (int)$styleText['fontSize'];
      if ($fs > 0 && $fs <= 140) $css[] = "font-size:{$fs}px";
    }

    if (!empty($styleText['color'])) {
      $c = (string)$styleText['color'];
      if (preg_match('/^#[0-9a-fA-F]{3}([0-9a-fA-F]{3})?$/', $c)) $css[] = "color:{$c}";
    }

    if (!empty($styleText['bold'])) $css[] = "font-weight:800";
    if (!empty($styleText['italic'])) $css[] = "font-style:italic";
    if (!empty($styleText['underline'])) $css[] = "text-decoration:underline";

    if (!empty($styleText['align']) && in_array($styleText['align'], ['left','center','right'], true)) {
      $css[] = "text-align:{$styleText['align']}";
    }

    // keep it readable
    $css[] = "line-height:1.35";

    return implode(';', $css);
  }

  /**
   * Wrap block HTML with:
   * - outer wrapper styling from blk.style
   * - inner text styling from blk.styleText (applied when $applyTextCss true)
   */
  private static function wrapWithStyles(string $html, array $blk, bool $applyTextCss): string
  {
    $outerCss = '';
    if (isset($blk['style']) && is_array($blk['style'])) {
      $outerCss = self::safeBlockCss($blk['style']);
    }

    // Outer wrapper always allowed
    if ($outerCss !== '') {
      $html = '<div style="' . $outerCss . '">' . $html . '</div>';
    }

    // Text wrapper only when desired (text blocks)
    if ($applyTextCss) {
      $textCss = '';
      if (isset($blk['styleText']) && is_array($blk['styleText'])) {
        $textCss = self::safeTextCss($blk['styleText']);
      }
      if ($textCss !== '') {
        $html = '<div style="' . Security::e($textCss) . '">' . $html . '</div>';
      }
    }

    return $html;
  }

  private static function jsonInline(mixed $value): string
  {
    return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
  }

  private static function normalizeCitation(string $s): string
  {
    // strip tags, collapse whitespace
    $s = strip_tags($s);
    $s = preg_replace('/\s+/', ' ', $s);
    return trim($s);
  }

  /**
   * Safe inline HTML for rich text. Allows a small set of tags and cleans <a>.
   */
  private static function safeInlineHtml(string $html): string
  {
    // remove script/style blocks
    $html = preg_replace('#<(script|style)\b[^>]*>.*?</\1>#is', '', $html);

    // convert common block tags to line breaks to preserve paragraph structure
    $html = preg_replace('#</?(div|p)\b[^>]*>#i', '<br>', $html);
    // normalize consecutive breaks
    $html = preg_replace('#(<br\s*/?>\s*)+#i', '<br>', $html);

    // remove on* handlers
    $html = preg_replace('/\son\w+="[^"]*"/i', '', $html);
    $html = preg_replace("/\son\w+='[^']*'/i", '', $html);

    // allow only these tags
    $allowed = '<a><b><strong><i><em><u><br><span>';
    $html = strip_tags($html, $allowed);

    // normalize <a> tags with href and force target/rel + underline
    $html = preg_replace_callback('#<a\b([^>]*)>#i', function ($m) {
      $attrs = $m[1] ?? '';

      // extract href
      if (!preg_match('/href\s*=\s*("|\')([^"\']+)\\1/i', $attrs, $hm)) {
        return '<a>';
      }

      $href = trim($hm[2]);

      // force scheme for safety/consistency
      if (!preg_match('#^(https?://|mailto:)#i', $href)) {
        $href = 'https://' . $href;
      }

      $hrefEsc = Security::e($href);

      // underline so users can see links
      $style = 'text-decoration:underline;text-underline-offset:3px;';

      return '<a href="' . $hrefEsc . '" target="_blank" rel="noopener noreferrer" style="' . $style . '">';
    }, $html);

    return $html;
  }

  /**
   * Plain-text markup: escape, convert *italic* to <em>, preserve newlines.
   */
  private static function formatMarkedText(string $text): string
  {
    if ($text === '') return '';
    $escaped = Security::e($text);
    // Bold first, then italics (single asterisks not part of bold)
    $withBold = preg_replace('/\*\*(.+?)\*\*/', '<strong>$1</strong>', $escaped);
    $withItalics = preg_replace('/(?<!\*)\*(?!\*)(.+?)(?<!\*)\*(?!\*)/', '<em>$1</em>', $withBold);
    return nl2br($withItalics);
  }

  /**
   * Normalize bullet-style text: remove duplicated markers and apply a single bullet symbol.
   */
  private static function normalizeBulletText(string $text): string
  {
    if ($text === '') return '';
    $lines = preg_split('/\r?\n/', $text);
    $normalized = array_map(function ($line) {
      $trim = trim($line);
      if ($trim === '') return '';
      // Strip leading markers twice to cover already-bulleted input.
      $trim = preg_replace('/^[•\-\*]\s*/', '', $trim);
      $trim = preg_replace('/^[•\-\*]\s*/', '', $trim);
      return '• ' . $trim;
    }, $lines);
    return implode("\n", array_filter($normalized, fn($l) => $l !== ''));
  }

  private static function hasHtml(string $str): bool
  {
    return preg_match('/<[^>]+>/', $str) === 1;
  }

  private static function block(array $blk): string
  {
    $type = (string)($blk['type'] ?? 'text');
    $p = is_array($blk['props'] ?? null) ? $blk['props'] : [];

    switch ($type) {
      case 'heading': {
  $lvl = max(1, min(6, (int)($p['level'] ?? 2)));

  $htmlRaw = (string)($p['html'] ?? '');
  if ($htmlRaw !== '') {
    $inner = self::safeInlineHtml($htmlRaw);
  } else {
    $inner = Security::e((string)($p['text'] ?? 'Heading'));
  }

  $html = "<h{$lvl}>{$inner}</h{$lvl}>";

  return self::wrapWithStyles($html, $blk, true);
}


      case 'text': {
        $htmlRaw = (string)($p['html'] ?? '');
        $bg = trim((string)($p['bgColor'] ?? ''));
        // Keep text block rendering faithful to editor content:
        // do not inject extra padding/border/radius when bgColor is set.
        $bgStyle = $bg !== '' ? "background:{$bg};" : '';
        if ($htmlRaw !== '') {
          $inner = self::safeInlineHtml($htmlRaw);
          // Avoid phantom top/bottom spacing from stored leading/trailing <br> tags.
          $inner = preg_replace('/^(?:\s*<br\s*\/?>\s*)+/i', '', $inner);
          $inner = preg_replace('/(?:\s*<br\s*\/?>\s*)+$/i', '', $inner);
          $html = "<div class=\"nx-text\" style=\"{$bgStyle}\">{$inner}</div>";
        } else {
          $txt = nl2br(Security::e((string)($p['text'] ?? '')));
          $html = "<div class=\"nx-text\" style=\"{$bgStyle}\">{$txt}</div>";
        }

        return self::wrapWithStyles($html, $blk, true);
      }

      case 'card': {
        $title = Security::e((string)($p['title'] ?? 'Hero Title'));

        $bodyRaw = (string)($p['html'] ?? '');
        if ($bodyRaw !== '') {
          $body = self::safeInlineHtml($bodyRaw);
        } else {
          $body = nl2br(Security::e((string)($p['body'] ?? 'Hero text')));
        }

        $html = "<div class=\"nx-card\">"
          . "<div class=\"nx-card-title\">{$title}</div>"
          . "<div class=\"nx-card-body\">{$body}</div>"
          . "</div>";

        return self::wrapWithStyles($html, $blk, true);
      }

      case 'panel': {
        $layout = (string)($p['layout'] ?? 'img-left');
        if (!in_array($layout, ['img-left','img-right','img-top','text-top'], true)) {
          $layout = 'img-top';
        }
        $stacked = ($layout === 'img-top' || $layout === 'text-top');
        $split = (string)($p['splitRatio'] ?? '50-50');
        $colsMap = [
          '50-50' => '1fr 1fr',
          '60-40' => '3fr 2fr',
          '70-30' => '7fr 3fr',
        ];
        $cols = $colsMap[$split] ?? '1fr 1fr';

        $bodyRaw = (string)($p['bodyHtml'] ?? ($p['html'] ?? ''));
        if ($bodyRaw !== '') {
          $body = self::hasHtml($bodyRaw) ? self::safeInlineHtml($bodyRaw) : self::formatMarkedText($bodyRaw);
        } else {
          $body = self::formatMarkedText((string)($p['body'] ?? ''));
        }

        $imgSrc = trim((string)($p['image'] ?? ''));
        $imgAlt = Security::e((string)($p['alt'] ?? ''));
        $media = $imgSrc !== ''
          ? "<div class=\"nx-panel-media\"><img src=\"" . Security::e($imgSrc) . "\" alt=\"{$imgAlt}\" loading=\"lazy\"></div>"
          : "<div class=\"nx-panel-media nx-panel-media--placeholder\"></div>";
        $text = "<div class=\"nx-panel-body\">{$body}</div>";

        $first = $media;
        $second = $text;
        if ($layout === 'img-right') { $first = $text; $second = $media; }
        if ($layout === 'text-top')  { $first = $text; $second = $media; }
        if ($layout === 'img-top')   { $first = $media; $second = $text; }

        $extra = $stacked ? ' nx-panel--stacked' : '';
        $style = (!$stacked) ? "style=\"grid-template-columns:{$cols};\"" : '';
        $html = "<div class=\"nx-panel nx-panel--{$layout}{$extra}\" {$style}>{$first}{$second}</div>";

        return self::wrapWithStyles($html, $blk, true);
      }

      case 'youTry': {
        $title = Security::e((string)($p['title'] ?? 'You try'));

        $bodyRaw = (string)($p['html'] ?? '');
        if ($bodyRaw !== '') {
          $body = self::safeInlineHtml($bodyRaw);
        } else {
          $body = nl2br(Security::e((string)($p['body'] ?? '')));
        }

        $html = "<div class=\"nx-youtry\">"
          . "<div class=\"nx-youtry-title\">{$title}</div>"
          . "<div class=\"nx-youtry-body\">{$body}</div>"
          . "</div>";

        return self::wrapWithStyles($html, $blk, true);
      }

      case 'textbox': {
        $label = Security::e((string)($p['label'] ?? 'Textbox'));
        $ph = Security::e((string)($p['placeholder'] ?? ''));
        $lines = (int)($p['lines'] ?? 3);
        if ($lines < 1) $lines = 1;
        if ($lines > 12) $lines = 12;
        $bg = trim((string)($p['bgColor'] ?? ''));
        $bgStyle = $bg !== '' ? "background:{$bg};" : '';

        $textarea = "<label class=\"nx-textbox\">"
          . "<div class=\"nx-textbox-label\">{$label}</div>"
          . "<textarea rows=\"{$lines}\" placeholder=\"{$ph}\" aria-label=\"{$label}\" style=\"{$bgStyle}\"></textarea>"
          . "</label>";

        return self::wrapWithStyles($textarea, $blk, false);
      }

      case 'citationOrder': {
        $title = Security::e((string)($p['title'] ?? 'Citation order'));

        $bodyRaw = (string)($p['html'] ?? '');
        if ($bodyRaw !== '') {
          $body = self::hasHtml($bodyRaw) ? self::safeInlineHtml($bodyRaw) : self::formatMarkedText($bodyRaw);
        } else {
          $plain = (string)($p['body'] ?? '');
          $normalized = self::normalizeBulletText($plain);
          $body = self::formatMarkedText($normalized);
        }

        $html = "<div class=\"nx-citation\">"
          . "<div class=\"nx-citation-title\">{$title}</div>"
          . "<div class=\"nx-citation-body\">{$body}</div>"
          . "</div>";

        return self::wrapWithStyles($html, $blk, true);
      }

      case 'exampleCard': {
        $heading = Security::e((string)($p['heading'] ?? 'Example'));

        $bodyRaw = (string)($p['bodyHtml'] ?? ($p['html'] ?? ''));
        if ($bodyRaw !== '') {
          $body = self::hasHtml($bodyRaw) ? self::safeInlineHtml($bodyRaw) : self::formatMarkedText($bodyRaw);
        } else {
          $body = self::formatMarkedText((string)($p['body'] ?? ''));
        }

        $youTryRaw = (string)($p['youTry'] ?? '');
        $youTry = self::hasHtml($youTryRaw) ? self::safeInlineHtml($youTryRaw) : self::formatMarkedText($youTryRaw);
        $showTry = !array_key_exists('showYouTry', $p) || (bool)$p['showYouTry'];
        $extraClass = $showTry ? '' : ' nx-examplecard--single';

        $html = "<div class=\"nx-examplecard{$extraClass}\">"
          . "<div class=\"nx-examplecard-left\">"
            . "<div class=\"nx-examplecard-heading\">{$heading}</div>"
            . "<div class=\"nx-examplecard-body\">{$body}</div>"
          . "</div>";

        if ($showTry) {
          $uid = uniqid('yt_', false);
          $expected = (string)($p['youTry'] ?? '');
          $expectedHtml = self::hasHtml($expected) ? self::safeInlineHtml($expected) : self::formatMarkedText($expected);

          $html .= "<div class=\"nx-examplecard-right\">"
            . "<div class=\"nx-examplecard-try-title\">You try</div>"
            . "<div class=\"nx-trybox\">"
              . "<div id=\"{$uid}_input\" class=\"nx-try-input\" aria-label=\"Enter your citation\" contenteditable=\"true\">{$expectedHtml}</div>"
            . "</div>"
            . "</div>"
            . "</div>";
        }

        $html .= "</div>";

        return self::wrapWithStyles($html, $blk, true);
      }

      case 'heroBanner': {
        $heading = Security::e((string)($p['heading'] ?? 'Welcome'));
        $ctaText = (string)($p['cta'] ?? 'Learn more');
        $ctaRaw = (string)($p['ctaHtml'] ?? '');
        $cta = $ctaRaw !== '' ? (self::hasHtml($ctaRaw) ? self::safeInlineHtml($ctaRaw) : self::formatMarkedText($ctaRaw)) : Security::e($ctaText);
        $bg = trim((string)($p['bgImage'] ?? ''));
        $overlay = isset($p['overlayOpacity']) ? (float)$p['overlayOpacity'] : 0.6;
        if ($overlay < 0) $overlay = 0;
        if ($overlay > 1) $overlay = 1;

        $bgStyle = $bg !== '' ? "background-image:url('".Security::e($bg)."');" : "background:linear-gradient(135deg,#1f2937,#111827);";

        $html = "<div class=\"nx-herobanner\" style=\"{$bgStyle}\">"
          . "<div class=\"nx-herobanner-inner\">"
            . "<div class=\"nx-herobanner-card\" style=\"background:rgba(0,0,0,{$overlay});\">"
              . "<div class=\"nx-herobanner-text\">{$heading}</div>"
              . "<div class=\"nx-herobanner-cta\">{$cta}</div>"
            . "</div>"
          . "</div>"
        . "</div>";

        return self::wrapWithStyles($html, $blk, true);
      }

      case 'table': {
        $data = isset($p['data']) && is_array($p['data']) ? $p['data'] : [['', '', ''], ['', '', '']];
        $rows = count($data);
        $cols = 0;
        foreach ($data as $row) {
          if (is_array($row)) $cols = max($cols, count($row));
        }
        if ($cols === 0) $cols = 1;
        $headerRow = !empty($p['headerRow']);
        $headerCol = !empty($p['headerCol']);
        $density = $p['density'] ?? 'default';
        $rowStyle = $p['rowStyle'] ?? 'none';
        $gridLines = $p['gridLines'] ?? 'subtle';
        $textAlign = $p['textAlign'] ?? 'left';
        $vAlign = $p['vAlign'] ?? 'middle';
        $colWidths = isset($p['colWidths']) && is_array($p['colWidths']) ? $p['colWidths'] : [];

        $classes = [
          'nx-table',
          'density-' . Security::e($density),
          'grid-' . Security::e($gridLines),
          'rowstyle-' . Security::e($rowStyle),
          'valign-' . Security::e($vAlign),
          'align-' . Security::e($textAlign),
        ];

        $colgroup = '';
        for ($c = 0; $c < $cols; $c++) {
          $w = $colWidths[$c] ?? 'auto';
          $w = $w === '' ? 'auto' : Security::e($w);
          $colgroup .= "<col style=\"width:{$w}\">";
        }

        $tbody = '';
        foreach ($data as $rIdx => $row) {
          if (!is_array($row)) $row = [];
          while (count($row) < $cols) $row[] = '';
          $cells = '';
          foreach ($row as $cIdx => $cell) {
            $isHead = ($headerRow && $rIdx === 0) || ($headerCol && $cIdx === 0);
            $tag = $isHead ? 'th' : 'td';
            $cellHtml = self::formatMarkedText((string)$cell);
            $cells .= "<{$tag}>{$cellHtml}</{$tag}>";
          }
          $tbody .= "<tr>{$cells}</tr>";
        }

        $html = "<div class=\"" . implode(' ', $classes) . "\">"
          . "<div class=\"nx-table-wrap\">"
            . "<table>"
              . "<colgroup>{$colgroup}</colgroup>"
              . "<tbody>{$tbody}</tbody>"
            . "</table>"
          . "</div>"
        . "</div>";

        return self::wrapWithStyles($html, $blk, true);
      }

      case 'heroCard':
      case 'heroPage': { // legacy name kept for existing docs
        $title = Security::e((string)($p['title'] ?? ''));
        $bodyRaw = (string)($p['bodyHtml'] ?? ($p['html'] ?? ''));
        if ($bodyRaw !== '') {
          $body = self::safeInlineHtml($bodyRaw);
        } else {
          $body = nl2br(Security::e((string)($p['body'] ?? '')));
        }

        $bgImage = trim((string)($p['bgImage'] ?? ''));
        $bgColor = trim((string)($p['bgColor'] ?? '#111827'));
        $overlay = isset($p['overlayOpacity']) ? (float)$p['overlayOpacity'] : 0.35;
        if ($overlay < 0) $overlay = 0;
        if ($overlay > 0.9) $overlay = 0.9;

        $bgStyle = $bgImage !== '' ? "background-image:url('".Security::e($bgImage)."');" : '';
        if ($bgStyle === '') $bgStyle = "background:{$bgColor};";

        $html = "<div class=\"nx-hero\" style=\"{$bgStyle}\">"
          . "<div class=\"nx-hero-overlay\" style=\"opacity:{$overlay};\"></div>"
          . "<div class=\"nx-hero-inner\">"
            . "<div class=\"nx-hero-card\">"
              . "<div class=\"nx-hero-title\">{$title}</div>"
              . "<div class=\"nx-hero-body\">{$body}</div>"
            . "</div>"
          . "</div>"
        . "</div>";

        return self::wrapWithStyles($html, $blk, true);
      }

      case 'image': {
        $src = trim((string)($p['src'] ?? ''));
        if ($src === '') return '';

        $alt = Security::e((string)($p['alt'] ?? ''));
        $srcEsc = Security::e($src);
        $ratioRaw = strtolower(trim((string)($p['imageRatio'] ?? '16-9')));
        $ratioMap = [
          '16-9' => '16 / 9',
          '4-3' => '4 / 3',
          '3-2' => '3 / 2',
          '1-1' => '1 / 1',
          '9-16' => '9 / 16',
        ];
        $ratioCss = $ratioMap[$ratioRaw] ?? '16 / 9';

        $img = "<img class=\"nx-img\" src=\"{$srcEsc}\" alt=\"{$alt}\" style=\"aspect-ratio:{$ratioCss};width:100%;height:auto;object-fit:cover;object-position:center;\">";
        return self::wrapWithStyles($img, $blk, false);
      }

      case 'download': {
        $labelRaw = (string)($p['label'] ?? 'Download');
        $label = Security::e($labelRaw === '' ? 'Download' : $labelRaw);
        $urlRaw = trim((string)($p['url'] ?? ''));
        $url = $urlRaw !== '' ? Security::e($urlRaw) : '#';
        $disabled = $urlRaw === '';
        $attrs = $disabled ? 'aria-disabled="true"' : 'target="_blank" rel="noopener"';

        $html = "<a class=\"nx-download\" href=\"{$url}\" {$attrs}>"
          . "<span class=\"nx-download-ic\">⬇</span>"
          . "<span class=\"nx-download-text\">{$label}</span>"
          . "</a>";

        return self::wrapWithStyles($html, $blk, true);
      }

      case 'testimonial': {
        $bodyRaw = (string)($p['bodyHtml'] ?? ($p['html'] ?? ''));
        if ($bodyRaw !== '') {
          $body = self::hasHtml($bodyRaw) ? self::safeInlineHtml($bodyRaw) : self::formatMarkedText($bodyRaw);
        } else {
          $body = self::formatMarkedText((string)($p['body'] ?? ''));
        }
        $html = "<div class=\"nx-testimonial\">"
          . "<span class=\"nx-testimonial-qt\">&ldquo;</span>"
          . "<div class=\"nx-testimonial-copy\">{$body}</div>"
          . "<span class=\"nx-testimonial-qt\">&rdquo;</span>"
          . "</div>";

        return self::wrapWithStyles($html, $blk, true);
      }

      case 'video': {
        $url = trim((string)($p['url'] ?? ''));
        if ($url === '') return '';

        $urlEsc = Security::e($url);
        $v = "<div class=\"nx-video\"><iframe src=\"{$urlEsc}\" loading=\"lazy\" allowfullscreen></iframe></div>";
        return self::wrapWithStyles($v, $blk, false);
      }

      case 'accordion':
      case 'accordionTabs': {
        $itemsIn = is_array($p['items'] ?? null) ? $p['items'] : [];
        if (!$itemsIn) {
          $itemsIn = [
            ['title' => 'Item 1', 'body' => '', 'bodyHtml' => '', 'openDefault' => false, 'headerImg' => '', 'headerAlt' => '', 'showHeaderImg' => false],
          ];
        }
        $items = [];
        foreach ($itemsIn as $i => $it) {
          $subsIn = is_array($it['subItems'] ?? null) ? $it['subItems'] : [];
          $subs = [];
          foreach ($subsIn as $sub) {
            $label = trim((string)($sub['label'] ?? ''));
            $url = trim((string)($sub['url'] ?? ''));
            if ($label === '') continue;
            $subs[] = ['label' => $label, 'url' => $url];
          }
          $items[] = [
            'title' => (string)($it['title'] ?? ('Item ' . ($i + 1))),
            'body' => (string)($it['body'] ?? ''),
            'bodyHtml' => (string)($it['bodyHtml'] ?? ''),
            'openDefault' => !empty($it['openDefault']),
            'headerImg' => (string)($it['headerImg'] ?? ''),
            'headerAlt' => (string)($it['headerAlt'] ?? ''),
            'showHeaderImg' => !empty($it['showHeaderImg']),
            'subItems' => $subs,
          ];
        }

        $mode = ($p['mode'] ?? 'accordion') === 'tabs' ? 'tabs' : 'accordion';
        $accStyle = in_array($p['accStyle'] ?? 'standard', ['standard','grouped'], true) ? $p['accStyle'] : 'standard';
        if ($mode === 'tabs') $accStyle = 'standard';
        $isGrouped = $mode === 'accordion' && $accStyle === 'grouped';

        $allowMultiple = $isGrouped ? true : !empty($p['allowMultiple']);
        $allowCollapseAll = $isGrouped ? true : (!array_key_exists('allowCollapseAll', $p) || (bool)$p['allowCollapseAll']);
        $defaultOpen = in_array($p['defaultOpen'] ?? 'none', ['none','first','custom'], true) ? $p['defaultOpen'] : 'none';
        $defaultIndex = (int)($p['defaultIndex'] ?? 0);
        if ($defaultIndex < 0) $defaultIndex = 0;
        if ($defaultIndex >= count($items)) $defaultIndex = max(0, count($items) - 1);

        $tabsDefault = in_array($p['tabsDefault'] ?? 'first', ['first','custom'], true) ? $p['tabsDefault'] : 'first';
        $tabsIndex = (int)($p['tabsIndex'] ?? 0);
        if ($tabsIndex < 0) $tabsIndex = 0;
        if ($tabsIndex >= count($items)) $tabsIndex = max(0, count($items) - 1);
        $tabsAlign = in_array($p['tabsAlign'] ?? 'left', ['left','center','right'], true) ? $p['tabsAlign'] : 'left';
        $tabsStyle = in_array($p['tabsStyle'] ?? 'underline', ['underline','pills','segmented'], true) ? $p['tabsStyle'] : 'underline';

        $styleVariant = in_array($p['styleVariant'] ?? 'default', ['default','minimal','bordered'], true) ? $p['styleVariant'] : 'default';
        $showDividers = !array_key_exists('showDividers', $p) || (bool)$p['showDividers'];
        $showIndicator = !array_key_exists('showIndicator', $p) || (bool)$p['showIndicator'];
        $indicatorPosition = in_array($p['indicatorPosition'] ?? 'right', ['left','right'], true) ? $p['indicatorPosition'] : 'right';
        $spacing = in_array($p['spacing'] ?? 'default', ['compact','default','spacious'], true) ? $p['spacing'] : 'default';
        $headerImgPos = in_array($p['headerImgPos'] ?? 'left', ['left','right'], true) ? $p['headerImgPos'] : 'left';
        $headerImgSize = in_array($p['headerImgSize'] ?? 'medium', ['small','medium','large'], true) ? $p['headerImgSize'] : 'medium';
        $showBorder = !array_key_exists('showBorder', $p) || (bool)$p['showBorder'];

        if ($isGrouped) {
          $showIndicator = true;
          $indicatorPosition = 'right';
          $styleVariant = 'minimal';
          $spacing = 'compact';
          $showDividers = true;
        }

        $styleVars = [];
        $colors = [
          'headerBg' => '--acc-head-bg',
          'headerColor' => '--acc-head-color',
          'activeHeaderBg' => '--acc-head-active',
          'activeHeaderColor' => '--acc-head-active-color',
          'contentBg' => '--acc-body-bg',
          'borderColor' => '--acc-border',
        ];
        foreach ($colors as $key => $cssVar) {
          $val = trim((string)($p[$key] ?? ''));
          if ($val !== '') $styleVars[] = $cssVar . ':' . Security::e($val);
        }
        $styleVars[] = '--nx-tab-count:' . max(1, count($items));
        $styleInline = $styleVars ? ' style="' . implode(';', $styleVars) . '"' : '';

        $accId = uniqid('acc_', false);

        if ($mode === 'tabs') {
          $active = $tabsDefault === 'custom' ? $tabsIndex : 0;
          if ($active >= count($items)) $active = 0;
          $tabsHtml = '';
          $panelsHtml = '';
          foreach ($items as $i => $it) {
            $bodyRaw = $it['bodyHtml'] !== '' ? $it['bodyHtml'] : $it['body'];
            // Preserve author-entered line breaks when plain text is used
            if ($it['bodyHtml'] === '' && is_string($bodyRaw)) {
              $bodyRaw = nl2br($bodyRaw);
            }
            $body = $bodyRaw !== '' ? self::safeInlineHtml($bodyRaw) : '';
            $thumb = ($it['showHeaderImg'] && $it['headerImg'])
              ? '<span class="nx-acc-thumb size-' . Security::e($headerImgSize) . '" style="background-image:url(\'' . Security::e($it['headerImg']) . '\')" aria-hidden="true"></span>'
              : '';
            $tabsHtml .= "<button role=\"tab\" class=\"nx-tab" . ($i===$active ? " active" : "") . "\" aria-selected=\"" . ($i===$active ? "true" : "false") . "\" aria-controls=\"tab-panel-{$accId}-{$i}\" id=\"tab-{$accId}-{$i}\">{$thumb}<span class=\"nx-tab-label\">" . Security::e($it['title']) . "</span></button>";
            $panelsHtml .= "<div role=\"tabpanel\" class=\"nx-tab-panel" . ($i===$active ? " active" : "") . "\" id=\"tab-panel-{$accId}-{$i}\" aria-labelledby=\"tab-{$accId}-{$i}\"" . ($i===$active ? '' : ' hidden style=\"display:none\"') . ">"
              . "<div class=\"nx-accordion-body\">" . ($body !== '' ? $body : '<span class="nx-muted">Add content…</span>') . "</div>"
              . "</div>";
          }

          $tabs = "<div class=\"nx-tabs\" id=\"{$accId}\" data-align=\"{$tabsAlign}\" data-style=\"{$tabsStyle}\" data-border=\"" . ($showBorder ? 'on' : 'off') . "\"{$styleInline}>"
            . "<div class=\"nx-tabs-list\" role=\"tablist\">{$tabsHtml}</div>"
            . "<div class=\"nx-tabs-panels\">{$panelsHtml}</div>"
            . "</div>";
          $tabs .= <<<HTML
<script>(function(){
  const root=document.getElementById("{$accId}");
  if(!root)return;
  const tabs=[...root.querySelectorAll('.nx-tab')];
  const panels=[...root.querySelectorAll('.nx-tab-panel')];
  function setActive(idx){
    tabs.forEach((t,i)=>{
      const on=i===idx;
      t.classList.toggle('active',on);
      t.setAttribute('aria-selected',on?'true':'false');
      if(panels[i]){
        panels[i].classList.toggle('active',on);
        if(on){
          panels[i].removeAttribute('hidden');
          panels[i].style.display='';
        }else{
          panels[i].setAttribute('hidden','');
          panels[i].style.display='none';
        }
      }
    });
  }
  tabs.forEach((t,idx)=>{
    t.addEventListener('click',e=>{e.preventDefault();setActive(idx);});
    t.addEventListener('keydown',e=>{
      if(e.key==='Enter'||e.key===' '){e.preventDefault();setActive(idx);}
      if(e.key==='ArrowRight'||e.key==='ArrowLeft'){e.preventDefault();const dir=e.key==='ArrowRight'?1:-1;const next=(idx+dir+tabs.length)%tabs.length;tabs[next]?.focus();setActive(next);}
    });
  });
})();</script>
HTML;
          return self::wrapWithStyles($tabs, $blk, true);
        }

        $open = [];
        if ($defaultOpen === 'first' && isset($items[0])) $open[0] = true;
        if ($defaultOpen === 'custom' && isset($items[$defaultIndex])) $open[$defaultIndex] = true;
        foreach ($items as $i => $it) {
          if (!empty($it['openDefault'])) $open[$i] = true;
        }
        if (!$allowMultiple && count($open) > 1) {
          $firstKey = array_key_first($open);
          $open = [$firstKey => true];
        }

        $itemsHtml = '';
        if ($isGrouped) {
          $parent = $items[0] ?? ['title' => 'Group', 'subItems' => []];
          $children = array_slice($items, 1);
          $isOpen = isset($open[0]);
          $headId = "acc-head-{$accId}-0";
          $panelId = "acc-panel-{$accId}-0";
          $subs = $parent['subItems'] ?? [];
          $parentList = '';
          if ($subs) {
            $parentList .= '<ul class="nx-accordion-sublist">';
            foreach ($subs as $s) {
              $label = $s['label'] ?? '';
              $url = $s['url'] ?? '';
              if ($label === '') continue;
              $parentList .= $url !== ''
                ? '<li><a href="' . Security::e($url) . '">' . Security::e($label) . '</a></li>'
                : '<li>' . Security::e($label) . '</li>';
            }
            $parentList .= '</ul>';
          }
          $childHtml = '';
          foreach ($children as $i => $it) {
            $subs2 = $it['subItems'] ?? [];
            $list = '';
            if ($subs2) {
              $list .= '<ul class="nx-accordion-sublist">';
              foreach ($subs2 as $s) {
                $label = $s['label'] ?? '';
                $url = $s['url'] ?? '';
                if ($label === '') continue;
                $list .= $url !== ''
                  ? '<li><a href="' . Security::e($url) . '">' . Security::e($label) . '</a></li>'
                  : '<li>' . Security::e($label) . '</li>';
              }
              $list .= '</ul>';
            } else {
              $list = '<div class="nx-muted" style="padding:6px 0;">Add sub-items…</div>';
            }
            $childHtml .= '<div class="nx-accordion-childrow">'
              . '<button type="button" class="nx-accordion-childhead" data-idx="' . $i . '">'
              . '<span class="nx-accordion-title">' . Security::e($it['title'] ?? ('Item ' . ($i + 2))) . '</span>'
              . '<span class="nx-accordion-plus" aria-hidden="true">+</span>'
              . '</button>'
              . '<div class="nx-accordion-childpanel" hidden>'
              . '<div class="nx-accordion-body">' . $list . '</div>'
              . '</div>'
              . '</div>';
          }
          $itemsHtml = "<div class=\"nx-accordion-item nx-accordion-parent" . ($isOpen ? " is-open" : "") . "\" data-idx=\"0\">"
            . "<button type=\"button\" class=\"nx-accordion-head\" id=\"{$headId}\" aria-expanded=\"" . ($isOpen ? 'true' : 'false') . "\" aria-controls=\"{$panelId}\">"
            . "<span class=\"nx-accordion-title\">" . Security::e($parent['title'] ?? 'Group') . "</span>"
            . "<span class=\"nx-accordion-plus\" aria-hidden=\"true\">" . ($isOpen ? '−' : '+') . "</span>"
            . "</button>"
            . "<div class=\"nx-accordion-panel\" id=\"{$panelId}\" role=\"region\" aria-labelledby=\"{$headId}\"" . ($isOpen ? '' : ' hidden') . ">"
            . "<div class=\"nx-accordion-body\">" . $parentList . "<div class=\"nx-accordion-childlist\">{$childHtml}</div></div>"
            . "</div>"
            . "</div>";
        } else {
          foreach ($items as $i => $it) {
            $isOpen = isset($open[$i]);
            $headId = "acc-head-{$accId}-{$i}";
            $panelId = "acc-panel-{$accId}-{$i}";

            $bodyRaw = $it['bodyHtml'] !== '' ? $it['bodyHtml'] : $it['body'];
            $body = $bodyRaw !== '' ? self::safeInlineHtml($bodyRaw) : '';
            $thumb = ($it['showHeaderImg'] && $it['headerImg'])
              ? '<span class="nx-acc-thumb size-' . Security::e($headerImgSize) . '" style="background-image:url(\'' . Security::e($it['headerImg']) . '\')" aria-hidden="true"></span>'
              : '';
            $itemsHtml .= "<div class=\"nx-accordion-item" . ($isOpen ? " is-open" : "") . "\" data-idx=\"{$i}\">"
              . "<button type=\"button\" class=\"nx-accordion-head\" id=\"{$headId}\" aria-expanded=\"" . ($isOpen ? 'true' : 'false') . "\" aria-controls=\"{$panelId}\">"
              . ($showIndicator && $indicatorPosition === 'left' ? '<span class="nx-accordion-chevron" aria-hidden="true">▸</span>' : '')
              . ($headerImgPos === 'left' ? $thumb : '')
              . "<span class=\"nx-accordion-title\">" . Security::e($it['title']) . "</span>"
              . ($headerImgPos === 'right' ? $thumb : '')
              . ($showIndicator && $indicatorPosition === 'right' ? '<span class="nx-accordion-chevron" aria-hidden="true">▸</span>' : '')
              . "</button>"
              . "<div class=\"nx-accordion-panel\" id=\"{$panelId}\" role=\"region\" aria-labelledby=\"{$headId}\"" . ($isOpen ? '' : ' hidden') . ">"
              . "<div class=\"nx-accordion-body\">" . ($body !== '' ? $body : '<span class="nx-muted">Add content…</span>') . "</div>"
              . "</div>"
              . "</div>";
          }
        }

        if ($isGrouped) {
          $accordion = "<div class=\"nx-accordion nx-accordion--grouped\" id=\"{$accId}\" data-grouped=\"1\" data-allow=\"multiple\" data-collapse=\"allow\" data-indicator=\"right\" data-spacing=\"compact\" data-style=\"minimal\" data-dividers=\"on\" data-border=\"" . ($showBorder ? 'on' : 'off') . "\"{$styleInline}>{$itemsHtml}</div>";
        } else {
          $accordion = "<div class=\"nx-accordion\" id=\"{$accId}\" data-allow=\"" . ($allowMultiple ? 'multiple' : 'single') . "\" data-collapse=\"" . ($allowCollapseAll ? 'allow' : 'force') . "\" data-indicator=\"" . ($showIndicator ? $indicatorPosition : 'none') . "\" data-spacing=\"{$spacing}\" data-style=\"{$styleVariant}\" data-dividers=\"" . ($showDividers ? 'on' : 'off') . "\" data-border=\"" . ($showBorder ? 'on' : 'off') . "\"{$styleInline}>{$itemsHtml}</div>";
        }
        $accordion .= <<<HTML
<script>(function(){
  const root=document.getElementById("{$accId}");
  if(!root)return;
  const isGrouped=root.dataset.grouped==="1";
  const allowMultiple=root.dataset.allow==="multiple";
  const allowCollapse=root.dataset.collapse!=="force";
  const items=[...root.querySelectorAll('.nx-accordion-item')];
  const childRows=isGrouped?[...root.querySelectorAll('.nx-accordion-childrow')]:[];
  const closeAllChildren=()=>{
    childRows.forEach(row=>{
      const panel=row.querySelector('.nx-accordion-childpanel');
      const plus=row.querySelector('.nx-accordion-plus');
      if(!panel)return;
      panel.hidden=true;
      panel.style.maxHeight='0px';
      if(plus) plus.textContent='+';
      row.classList.remove('is-open');
    });
  };
  const toggleChild=(row)=>{
    const panel=row.querySelector('.nx-accordion-childpanel');
    const plus=row.querySelector('.nx-accordion-plus');
    if(!panel)return;
    const open=panel.hidden!==false;
    panel.hidden=!open;
    if(open){
      panel.style.maxHeight=panel.scrollHeight+'px';
      setTimeout(()=>{panel.style.maxHeight='none';},160);
      row.classList.add('is-open');
    }else{
      panel.style.maxHeight=panel.scrollHeight+'px';
      requestAnimationFrame(()=>{panel.style.maxHeight='0px';});
      panel.addEventListener('transitionend',function h(){panel.hidden=true;panel.removeEventListener('transitionend',h);});
      setTimeout(()=>{panel.hidden=true;},220);
      row.classList.remove('is-open');
    }
    if(plus) plus.textContent=open?'−':'+';
  };
  function setOpen(item, open){
    const btn=item.querySelector('.nx-accordion-head');
    const panel=item.querySelector('.nx-accordion-panel');
    const plus=item.querySelector('.nx-accordion-plus');
    if(!btn||!panel)return;
    btn.setAttribute('aria-expanded', open?'true':'false');
    panel.setAttribute('aria-hidden', open?'false':'true');
    if(plus) plus.textContent=open?'−':'+';
    if(isGrouped && item.classList.contains('nx-accordion-parent') && !open){
      closeAllChildren();
    }
    if(open){
      item.classList.add('is-open');
      panel.hidden=false;
      panel.style.maxHeight=panel.scrollHeight+'px';
      setTimeout(()=>{panel.style.maxHeight='none';},180);
    }else{
      item.classList.remove('is-open');
      panel.style.maxHeight=panel.scrollHeight+'px';
      requestAnimationFrame(()=>{panel.style.maxHeight='0px';});
      panel.addEventListener('transitionend',function h(){panel.hidden=true;panel.removeEventListener('transitionend',h);});
    }
  }
  items.forEach((item)=>{
    const btn=item.querySelector('.nx-accordion-head');
    if(!btn)return;
    const toggle=()=>{
      const open=item.classList.contains('is-open');
      if(open && !allowCollapse && !allowMultiple) return;
      if(!open && !allowMultiple){
        items.forEach(it=>{ if(it!==item) setOpen(it,false); });
      }
      if(isGrouped && item.dataset.idx==="0" && open){
        // collapsing parent closes all children
        items.forEach(it=>setOpen(it,false));
        return;
      }
      setOpen(item,!open);
    };
    btn.addEventListener('click',e=>{e.preventDefault();toggle();});
    btn.addEventListener('keydown',e=>{if(e.key==='Enter'||e.key===' '){e.preventDefault();toggle();}});
    setOpen(item, item.classList.contains('is-open'));
  });
  if(childRows.length){
    childRows.forEach(row=>{
      const head=row.querySelector('.nx-accordion-childhead');
      if(head){
        head.addEventListener('click',e=>{e.preventDefault();toggleChild(row);});
      }
    });
    closeAllChildren();
  }
})();</script>
HTML;

        return self::wrapWithStyles($accordion, $blk, true);
      }

      case 'dragWords': {
        $instruction = Security::e((string)($p['instruction'] ?? 'Drag or click the words into the blanks.'));
        $sentence = (string)($p['sentence'] ?? 'Fill the blanks.');
        $checkLabel = Security::e((string)($p['checkLabel'] ?? 'Check answers'));
        $resetLabel = Security::e((string)($p['resetLabel'] ?? 'Reset'));
        $answers = array_values(array_filter(array_map('trim', (array)($p['answers'] ?? [])), fn($v) => $v !== ''));
        if (!$answers) $answers = ['answer'];
        $pool = array_values(array_filter(array_map('trim', (array)($p['pool'] ?? [])), fn($v) => $v !== ''));
        if (!$pool) $pool = $answers;

        // ensure sentence contains matching placeholders
        $tokenCount = preg_match_all('/\{(\d+)\}/', $sentence, $m) ? count($m[1]) : 0;
        if ($tokenCount === 0) {
          // add placeholders to end to match answers length
          $sentence = Security::e($sentence) . ' ' . implode(' ', array_map(fn($i) => '{'.($i+1).'}', array_keys($answers)));
          $tokenCount = count($answers);
        } else {
          $sentence = Security::e($sentence);
        }

        $uid = uniqid('dw_', false);

        // Replace tokens with blanks
        $out = preg_replace_callback('/\{(\d+)\}/', function($m) use ($answers) {
          $idx = (int)$m[1] - 1;
          $label = isset($answers[$idx]) ? '&nbsp;&nbsp;&nbsp;' : '___';
          return '<span class="nx-dw-blank" data-blank="'.($idx).'">'.$label.'</span>';
        }, $sentence);

        $poolBtns = '';
        foreach ($pool as $w) {
          $poolBtns .= '<button type="button" class="nx-dw-word" draggable="true">'.Security::e($w).'</button>';
        }

        $answersJson = self::jsonInline($answers);
        $fbOkJson = self::jsonInline((string)($p['feedbackCorrect'] ?? 'Great job!'));
        $fbNoJson = self::jsonInline((string)($p['feedbackPartial'] ?? 'Not quite yet.'));

        $html = <<<HTML
<div class="nx-dragwords" id="{$uid}">
  <div class="nx-dw-instr">{$instruction}</div>
  <div class="nx-dw-sentence">{$out}</div>
  <div class="nx-dw-pool" data-pool>{$poolBtns}</div>
  <div class="nx-dw-actions">
    <button type="button" class="nx-dw-check" data-check>{$checkLabel}</button>
    <button type="button" class="nx-dw-reset" data-reset>{$resetLabel}</button>
  </div>
  <div class="nx-dw-feedback" data-feedback style="display:none"></div>
</div>
<script>(function(){
  const root=document.getElementById("{$uid}");if(!root)return;
  const answers={$answersJson};
  const blanks=[...root.querySelectorAll('[data-blank]')];
  const pool=root.querySelector('[data-pool]');
  const feedback=root.querySelector('[data-feedback]');
  let dragged=null;

  function setFeedback(msg, ok){
    if(!feedback)return;
    feedback.style.display='block';
    feedback.textContent=msg;
    feedback.className='nx-dw-feedback '+(ok?'ok':'no');
  }

  function enableWord(btn){
    btn.addEventListener('dragstart',e=>{dragged=btn; e.dataTransfer.setData('text/plain',''); btn.classList.add('dragging');});
    btn.addEventListener('dragend',()=>{btn.classList.remove('dragging'); dragged=null;});
    btn.addEventListener('click',()=>{
      const currentParent=btn.parentElement;
      if(currentParent && currentParent.hasAttribute('data-pool')){
        const empty=blanks.find(b=>!b.querySelector('.nx-dw-word'));
        if(empty) empty.appendChild(btn);
      } else if(pool){ pool.appendChild(btn); }
    });
  }

  function makeDropZone(zone){
    zone.addEventListener('dragover',e=>{e.preventDefault(); zone.classList.add('over');});
    zone.addEventListener('dragleave',()=>zone.classList.remove('over'));
    zone.addEventListener('drop',e=>{
      e.preventDefault(); zone.classList.remove('over');
      if(!dragged) return;
      if(zone.hasAttribute('data-pool')){
        pool.appendChild(dragged);
      }else{
        const existing=zone.querySelector('.nx-dw-word');
        if(existing) pool.appendChild(existing);
        zone.appendChild(dragged);
      }
    });
  }

  root.querySelectorAll('.nx-dw-word').forEach(enableWord);
  blanks.forEach(makeDropZone);
  if(pool) makeDropZone(pool);

  const checkBtn=root.querySelector('[data-check]');
  if(checkBtn){
    checkBtn.addEventListener('click',()=>{
      let correct=0;
      blanks.forEach((b,i)=>{
        const word=b.querySelector('.nx-dw-word');
        const ans=(answers[i]||'').toLowerCase().trim();
        if(word && word.textContent.toLowerCase().trim()===ans){correct++; b.classList.add('ok'); b.classList.remove('no');}
        else{b.classList.add('no'); b.classList.remove('ok');}
      });
      const fbOk={$fbOkJson};
      const fbNo={$fbNoJson};
      setFeedback(correct===blanks.length?fbOk:fbNo, correct===blanks.length);
    });
  }

  const resetBtn=root.querySelector('[data-reset]');
  if(resetBtn){
    resetBtn.addEventListener('click',()=>{
      blanks.forEach(b=>{
        const w=b.querySelector('.nx-dw-word');
        if(w) pool.appendChild(w);
        b.classList.remove('ok','no');
      });
      if(feedback){feedback.style.display='none';feedback.textContent='';}
    });
  }
})();</script>
HTML;

        return self::wrapWithStyles($html, $blk, false);
      }

      case 'flipCard': {
        $frontTitle = Security::e((string)($p['frontTitle'] ?? 'Front'));
        $backTitle  = Security::e((string)($p['backTitle'] ?? 'Back'));

        $frontBodyRaw = (string)($p['frontBodyHtml'] ?? ($p['frontBody'] ?? ''));
        $backBodyRaw  = (string)($p['backBodyHtml'] ?? ($p['backBody'] ?? ''));
        $frontBody = $frontBodyRaw !== '' ? self::safeInlineHtml($frontBodyRaw) : '';
        $backBody  = $backBodyRaw !== '' ? self::safeInlineHtml($backBodyRaw) : '';

        $frontImg = trim((string)($p['frontImage'] ?? ''));
        $backImg  = trim((string)($p['backImage'] ?? ''));

        $uid = uniqid('fc_', false);

        $turn = Security::e((string)($p['turnLabel'] ?? 'Turn'));
        $turnBack = Security::e((string)($p['turnBackLabel'] ?? 'Turn back'));

        $frontImgHtml = $frontImg !== '' ? '<img src="'.Security::e($frontImg).'" alt="" class="nx-fc-img">' : '';
        $backImgHtml  = $backImg  !== '' ? '<img src="'.Security::e($backImg ).'" alt="" class="nx-fc-img">' : '';

        $html = <<<HTML
<div class="nx-flipcard" id="{$uid}">
  <div class="nx-fc-inner">
    <div class="nx-fc-face nx-fc-front">
      <div class="nx-fc-title">{$frontTitle}</div>
      {$frontImgHtml}
      <div class="nx-fc-body">{$frontBody}</div>
      <button type="button" class="nx-fc-btn" data-turn>{$turn}</button>
    </div>
    <div class="nx-fc-face nx-fc-back">
      <div class="nx-fc-title">{$backTitle}</div>
      {$backImgHtml}
      <div class="nx-fc-body">{$backBody}</div>
      <button type="button" class="nx-fc-btn" data-turnback>{$turnBack}</button>
    </div>
  </div>
</div>
<script>(function(){
  const root=document.getElementById("{$uid}");if(!root)return;
  const inner=root.querySelector('.nx-fc-inner');
  const btnF=root.querySelector('[data-turn]');
  const btnB=root.querySelector('[data-turnback]');
  btnF?.addEventListener('click',()=>inner?.classList.add('is-back'));
  btnB?.addEventListener('click',()=>inner?.classList.remove('is-back'));
})();</script>
HTML;

        return self::wrapWithStyles($html, $blk, true);
      }

      case 'trueFalse': {
        $questionRaw = (string)($p['questionHtml'] ?? ($p['question'] ?? ''));
        $question = $questionRaw !== '' ? self::safeInlineHtml($questionRaw) : Security::e((string)($p['question'] ?? ''));

        $trueLabel = Security::e((string)($p['trueLabel'] ?? 'True'));
        $falseLabel = Security::e((string)($p['falseLabel'] ?? 'False'));
        $correct = (string)($p['correct'] ?? 'true') === 'false' ? 'false' : 'true';

        $fbOk = Security::e((string)($p['feedbackCorrect'] ?? 'Correct!'));
        $fbWrong = Security::e((string)($p['feedbackWrong'] ?? 'Not quite — try again.'));

        $uid = uniqid('tf_', false);

        $correctJson = self::jsonInline($correct);
        $tfOkJson = self::jsonInline($fbOk);
        $tfWrongJson = self::jsonInline($fbWrong);

        $html = <<<HTML
<div class="nx-truefalse" id="{$uid}">
  <div class="nx-tf-question">{$question}</div>
  <div class="nx-tf-buttons">
    <button type="button" class="nx-tf-btn nx-tf-true" data-val="true">{$trueLabel}</button>
    <button type="button" class="nx-tf-btn nx-tf-false" data-val="false">{$falseLabel}</button>
  </div>
  <div class="nx-tf-feedback" data-feedback style="display:none"></div>
</div>
<script>(function(){
  const root=document.getElementById("{$uid}");if(!root)return;
  const correct={$correctJson};
  const fbOk={$tfOkJson};
  const fbWrong={$tfWrongJson};
  const fbEl=root.querySelector('[data-feedback]');
  root.querySelectorAll('.nx-tf-btn').forEach(btn=>{
    btn.addEventListener('click',()=>{
      const val=btn.dataset.val==='false'?'false':'true';
      const ok=val===correct;
      if(fbEl){
        fbEl.style.display='block';
        fbEl.textContent=ok?fbOk:fbWrong;
        fbEl.className='nx-tf-feedback '+(ok?'ok':'no');
      }
      root.querySelectorAll('.nx-tf-btn').forEach(b=>b.classList.remove('active'));
      btn.classList.add('active');
    });
  });
})();</script>
HTML;

        return self::wrapWithStyles($html, $blk, true);
      }

      case 'multipleChoiceQuiz': {
        $p = is_array($blk['props'] ?? null) ? $blk['props'] : [];

        $questions = [];
        foreach ((array)($p['questions'] ?? []) as $q) {
          if (!is_array($q)) continue;
          $qId = (string)($q['id'] ?? uniqid('q_', false));
          $opts = [];
          foreach ((array)($q['options'] ?? []) as $opt) {
            if (!is_array($opt)) continue;
            $optId = (string)($opt['id'] ?? uniqid('opt_', false));
            $opts[] = [
              'id' => $optId,
              'label' => (string)($opt['label'] ?? ''),
              'correct' => !empty($opt['correct']),
            ];
          }
          while (count($opts) < 2) {
            $opts[] = ['id' => uniqid('opt_', false), 'label' => '', 'correct' => false];
          }
          $correctId = null;
          foreach ($opts as $o) { if (!empty($o['correct'])) { $correctId = $o['id']; break; } }
          if (!$correctId && isset($q['correctOptionId'])) $correctId = (string)$q['correctOptionId'];
          if (!$correctId && isset($opts[0]['id'])) $correctId = $opts[0]['id'];
          foreach ($opts as &$o) { $o['correct'] = ($o['id'] ?? '') === $correctId; }
          unset($o);
          $questions[] = [
            'id' => $qId,
            'questionHtml' => $q['questionHtml'] ?? '',
            'questionText' => $q['questionText'] ?? '',
            'showImage' => !empty($q['showImage']),
            'image' => $q['image'] ?? '',
            'imageAlt' => $q['imageAlt'] ?? '',
            'options' => $opts,
            'correctOptionId' => $correctId,
          ];
        }
        if (!$questions) {
          $questions = [[
            'id' => uniqid('q_', false),
            'questionHtml' => '',
            'questionText' => '',
            'showImage' => false,
            'image' => '',
            'imageAlt' => '',
            'options' => [
              ['id' => uniqid('opt_', false), 'label' => '', 'correct' => true],
              ['id' => uniqid('opt_', false), 'label' => '', 'correct' => false],
            ],
            'correctOptionId' => null,
          ]];
          $questions[0]['correctOptionId'] = $questions[0]['options'][0]['id'];
        }

        $shuffle = !empty($p['shuffle']);
        $maxAttempts = max(0, (int)($p['maxAttempts'] ?? 0));
        $showFeedback = ($p['showFeedback'] ?? true) !== false;
        $feedbackTiming = in_array(($p['feedbackTiming'] ?? 'submit'), ['submit','onSelect'], true) ? ($p['feedbackTiming'] ?? 'submit') : 'submit';
        $require = ($p['requireAnswer'] ?? true) !== false;
        $showReset = ($p['showReset'] ?? true) !== false;
        $allowResetOnCorrect = !empty($p['allowResetOnCorrect']);
        $showExplanationAfter = in_array(($p['showExplanationAfter'] ?? 'submit'), ['submit','incorrectOnly'], true) ? ($p['showExplanationAfter'] ?? 'submit') : 'submit';
        $correctMsg = Security::e((string)($p['correctMsg'] ?? 'Correct'));
        $incorrectMsg = Security::e((string)($p['incorrectMsg'] ?? 'Try again'));
        $explainRaw = (string)($p['explanationHtml'] ?? ($p['explanationText'] ?? ''));
        if ($explainRaw === '' && isset($p['explanationText'])) $explainRaw = nl2br((string)$p['explanationText']);
        $explain = $explainRaw !== '' ? self::safeInlineHtml($explainRaw) : '';

        $styleVariant = in_array(($p['styleVariant'] ?? 'default'), ['default','minimal','bordered'], true) ? ($p['styleVariant'] ?? 'default') : 'default';
        $showBorder = ($p['showBorder'] ?? true) !== false;
        $spacing = in_array(($p['spacing'] ?? 'default'), ['compact','default','spacious'], true) ? ($p['spacing'] ?? 'default') : 'default';
        $accent = trim((string)($p['accentColor'] ?? ''));
        $cOk = trim((string)($p['correctColor'] ?? ''));
        $cBad = trim((string)($p['incorrectColor'] ?? ''));

        $styleVars = [];
        if ($accent !== '') $styleVars[] = '--mcq-accent:' . Security::e($accent);
        if ($cOk !== '') $styleVars[] = '--mcq-correct:' . Security::e($cOk);
        if ($cBad !== '') $styleVars[] = '--mcq-incorrect:' . Security::e($cBad);
        $styleInline = $styleVars ? ' style="' . implode(';', $styleVars) . '"' : '';

        $borderAttr = $showBorder ? 'on' : 'off';
        $shuffleAttr = $shuffle ? 'on' : 'off';
        $fbAttr = $showFeedback ? 'on' : 'off';
        $reqAttr = $require ? 'on' : 'off';
        $resetAttr = $showReset ? 'on' : 'off';
        $resetCorrectAttr = $allowResetOnCorrect ? 'on' : 'off';
        $expAttr = $showExplanationAfter;
        $uid = uniqid('mcq_', false);

        $questionsHtml = '';
        foreach ($questions as $idx => $q) {
          $questionRaw = (string)($q['questionHtml'] ?? ($q['questionText'] ?? ''));
          if ($questionRaw === '' && isset($q['questionText'])) $questionRaw = nl2br((string)$q['questionText']);
          $question = $questionRaw !== '' ? self::safeInlineHtml($questionRaw) : '<span class="nx-muted">Add question…</span>';
          $mediaHtml = '';
          if (!empty($q['showImage']) && !empty($q['image'])) {
            $img = Security::e((string)$q['image']);
            $alt = Security::e((string)($q['imageAlt'] ?? ''));
            $mediaHtml = '<div class="nx-mcq-media"><img src="' . $img . '" alt="' . $alt . '"></div>';
          }
          $renderOptions = $q['options'] ?? [];
          if ($shuffle) {
            $renderOptions = $renderOptions ? array_values($renderOptions) : [];
            shuffle($renderOptions);
          }
          $optsHtml = '';
          foreach ($renderOptions as $oIdx => $opt) {
            $label = $opt['label'] !== '' ? Security::e((string)$opt['label']) : 'Option ' . ($oIdx + 1);
            $id = Security::e((string)($opt['id'] ?? uniqid('opt_', false)));
            $optsHtml .= '<label class="nx-mcq-option"><input type="radio" name="mcq-' . $uid . '-' . Security::e((string)$q['id']) . '" data-opt="' . $id . '"><span class="nx-mcq-optionlabel">' . $label . '</span></label>';
          }
          $questionsHtml .= '<div class="nx-mcq-q" data-qid="' . Security::e((string)$q['id']) . '" data-correct="' . Security::e((string)($q['correctOptionId'] ?? '')) . '">'
            . '<div class="nx-mcq-head">'
            . '<div class="nx-mcq-question"><span class="nx-muted">Question ' . ($idx + 1) . '</span><br>' . $question . '</div>'
            . $mediaHtml
            . '</div>'
            . '<div class="nx-mcq-options" role="radiogroup" aria-label="Multiple choice options">'
            . $optsHtml
            . '</div>'
            . '<div class="nx-mcq-actions">'
            . '<button type="button" class="smallbtn nx-mcq-check">Check answer</button>'
            . '<button type="button" class="smallbtn nx-mcq-reset" style="' . ($showReset ? '' : 'display:none') . '">Reset</button>'
            . '</div>'
            . '<div class="nx-mcq-feedback" aria-live="polite"></div>'
            . '</div>';
        }

        $explainHtml = $explain !== '' ? '<div class="nx-mcq-explain" data-state="hidden">' . $explain . '</div>' : '';

        $html = <<<HTML
<div class="nx-mcq" id="{$uid}"
  data-spacing="{$spacing}"
  data-style="{$styleVariant}"
  data-border="{$borderAttr}"
  data-shuffle="{$shuffleAttr}"
  data-max-attempts="{$maxAttempts}"
  data-feedback="{$fbAttr}"
  data-feedback-timing="{$feedbackTiming}"
  data-require="{$reqAttr}"
  data-show-reset="{$resetAttr}"
  data-reset-correct="{$resetCorrectAttr}"
  data-exp="{$expAttr}"
  data-correct-msg="{$correctMsg}"
  data-incorrect-msg="{$incorrectMsg}"{$styleInline}>
  {$questionsHtml}
  {$explainHtml}
</div>
<script>(function(){
  const root=document.getElementById("{$uid}");
  if(!root) return;
  const qs=[...root.querySelectorAll('.nx-mcq-q')];
  const requireAns=root.dataset.require==='on';
  const showFeedback=root.dataset.feedback!=='off';
  const timing=root.dataset.feedbackTiming||'submit';
  const maxAttempts=parseInt(root.dataset.maxAttempts||'0',10)||0;
  const showReset=root.dataset.showReset!=='off';
  const allowResetCorrect=root.dataset.resetCorrect==='on';
  const showExp=root.dataset.exp||'submit';
  const msgOk=root.dataset.correctMsg||'Correct';
  const msgBad=root.dataset.incorrectMsg||'Try again';
  const explain=root.querySelector('.nx-mcq-explain');

  qs.forEach(q=>{
    const radios=[...q.querySelectorAll('input[type="radio"]')];
    const check=q.querySelector('.nx-mcq-check');
    const reset=q.querySelector('.nx-mcq-reset');
    const fb=q.querySelector('.nx-mcq-feedback');
    const correct=q.dataset.correct||'';
    let attempts=0;
    const clear=()=>{
      if(fb){ fb.textContent=''; fb.className='nx-mcq-feedback'; }
      radios.forEach(r=>{
        const wrap=r.closest('.nx-mcq-option');
        if(wrap) wrap.classList.remove('is-correct','is-incorrect');
        r.disabled=false;
      });
      if(explain) explain.dataset.state='hidden';
      if(check && requireAns) check.disabled=true;
    };
    const resetFn=()=>{
      radios.forEach(r=>{ r.checked=false; });
      attempts=0;
      clear();
    };
    const evaluate=()=>{
      const sel=radios.find(r=>r.checked);
      if(!sel){
        if(requireAns && fb){
          fb.textContent='Select an option first.';
          fb.className='nx-mcq-feedback';
        }
        return;
      }
      attempts++;
      const ok=sel.dataset.opt===correct;
      radios.forEach(r=>{
        const wrap=r.closest('.nx-mcq-option');
        if(!wrap) return;
        wrap.classList.remove('is-correct','is-incorrect');
        if(showFeedback){
          if(r.dataset.opt===correct) wrap.classList.add('is-correct');
          if(r.checked && r.dataset.opt!==correct) wrap.classList.add('is-incorrect');
        }
      });
      if(showFeedback && fb){
        fb.textContent=ok?msgOk:msgBad;
        fb.className='nx-mcq-feedback '+(ok?'is-correct':'is-incorrect');
      }
      if(explain){
        const show=showExp==='submit' ? true : !ok;
        explain.dataset.state=show ? 'show' : 'hidden';
      }
      const hitLimit = maxAttempts && attempts>=maxAttempts;
      if(hitLimit || (ok && !allowResetCorrect && !showReset)){
        radios.forEach(r=>{ r.disabled=true; });
        if(check) check.disabled=true;
      }
    };
    radios.forEach(r=>{
      r.addEventListener('change', ()=>{
        if(check && requireAns) check.disabled=false;
        if(timing==='onSelect') evaluate();
      });
    });
    if(check) check.addEventListener('click', e=>{ e.preventDefault(); evaluate(); });
    if(reset) reset.addEventListener('click', e=>{ e.preventDefault(); resetFn(); });
    if(check && requireAns) check.disabled=true;
  });
})();</script>
HTML;

        return self::wrapWithStyles($html, $blk, true);
      }

      case 'carousel': {
        return self::renderCarousel($blk);
      }

      case 'divider': {
        $hr = "<hr>";
        return self::wrapWithStyles($hr, $blk, false);
      }

      default:
        return '';
    }
  }

  private static function renderCarousel(array $blk): string
  {
    $p = is_array($blk['props'] ?? null) ? $blk['props'] : [];
    $slides = array_values(array_filter((array)($p['slides'] ?? []), fn($s) => is_array($s)));
    if (!$slides) $slides = [['title'=>'Slide','body'=>'','image'=>'']];

    $show = max(1, min(5, (int)($p['slidesToShow'] ?? 1)));
    $size = in_array(($p['size'] ?? 'large'), ['small','medium','large'], true) ? $p['size'] : 'large';
    $autoplay = !empty($p['autoplay']);
    $bg = trim((string)($p['background'] ?? ''));
    if ($bg === '') {
      $bg = 'radial-gradient(120px at 10% 20%, rgba(234,179,8,.35), transparent 55%),'
          . 'radial-gradient(100px at 85% 25%, rgba(74,222,128,.20), transparent 50%),'
          . 'radial-gradient(140px at 90% 70%, rgba(59,130,246,.15), transparent 55%),'
          . 'linear-gradient(135deg,#fdfaf3,#fffefb)';
    }
    $uid = uniqid('car_', false);

    $wrapperClass = "nx-carousel nx-car-hero size-{$size}";
    $styleParts = [];
    if ($bg !== '') $styleParts[] = 'background:' . Security::e($bg);
    // height by size
    if ($size === 'large') $styleParts[] = 'min-height:40vh';
    if ($size === 'medium') $styleParts[] = 'min-height:25vh';
    if ($size === 'small') $styleParts[] = 'min-height:10vh';
    $style = $styleParts ? 'style="' . implode(';', $styleParts) . '"' : '';

    $slidesHtml = '';
    foreach ($slides as $s) {
      if (!is_array($s)) $s = [];
      $title = Security::e((string)($s['title'] ?? ''));
      $bodyRaw = (string)($s['body'] ?? '');
      $body = $bodyRaw !== '' ? self::safeInlineHtml($bodyRaw) : '';
      $img = trim((string)($s['image'] ?? ''));
      $imgHtml = $img !== '' ? '<div class="nx-car-img" style="background-image:url(\''.Security::e($img).'\');"></div>' : '<div class="nx-car-bubbles"></div>';
      $layoutRaw = $s['layout'] ?? 'text-left';
      $layout = in_array($layoutRaw, ['text-left','text-right','stacked'], true) ? $layoutRaw : 'text-left';
      $slidesHtml .= '<div class="nx-car-slide layout-'.$layout.'">'
        . '<div class="nx-car-copy">'
        . ($title !== '' ? '<div class="nx-car-title">'.$title.'</div>' : '')
        . ($body !== '' ? '<div class="nx-car-body">'.$body.'</div>' : '')
        . '</div>'
        . '<div class="nx-car-visual">'.$imgHtml.'</div>'
        . '</div>';
    }

    $dotHtml = str_repeat('<button type="button" class="nx-car-dot"></button>', count($slides));
    $autoFlag = $autoplay ? 'true' : 'false';

    $html = <<<HTML
<div class="{$wrapperClass}" id="{$uid}" {$style}>
  <div class="nx-car-track" data-show="{$show}">
    {$slidesHtml}
  </div>
  <div class="nx-car-dots">{$dotHtml}</div>
  <button class="nx-car-nav prev" aria-label="Previous slide">‹</button>
  <button class="nx-car-nav next" aria-label="Next slide">›</button>
</div>
<script>(function(){
  const root=document.getElementById("{$uid}");
  if(!root) return;
  const track=root.querySelector('.nx-car-track');
  const slides=[...root.querySelectorAll('.nx-car-slide')];
  const dots=[...root.querySelectorAll('.nx-car-dot')];
  const show=Math.max(1, parseInt(track.dataset.show||'1',10));
  let idx=0;
  let timer=null;
  function update(){
    const w=100/show;
    slides.forEach(s=>s.style.flex="0 0 "+w+"%");
    track.style.transform="translateX(-"+(idx*w)+"%)";
    dots.forEach((d,i)=>d.classList.toggle('active', i===idx));
  }
  function next(){ idx=(idx+1)%slides.length; update(); }
  function prev(){ idx=(idx-1+slides.length)%slides.length; update(); }
  root.querySelector('.nx-car-nav.prev')?.addEventListener('click',()=>{prev(); resetTimer();});
  root.querySelector('.nx-car-nav.next')?.addEventListener('click',()=>{next(); resetTimer();});
  dots.forEach((d,i)=>d.addEventListener('click',()=>{idx=i; update(); resetTimer();}));
  function resetTimer(){
    if ({$autoFlag} && slides.length>1){
      clearInterval(timer);
      timer=setInterval(next,5000);
    }
  }
  update();
  resetTimer();
})();</script>
HTML;

    return self::wrapWithStyles($html, $blk, false);
  }
}
