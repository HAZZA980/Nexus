<?php
namespace NexusCMS\Services;

use NexusCMS\Core\DB;
use NexusCMS\Models\Site;

final class AdminChatbot {
  public static function reply(array $messages, array $actor): array {
    $apiKey = trim((string)(app_config('ai.gemini_api_key') ?? ''));
    if ($apiKey === '') {
      return [
        'ok' => false,
        'error' => 'Gemini is not configured. Set GEMINI_API_KEY in the web server environment, then reload PHP/XAMPP.'
      ];
    }
    if (!function_exists('curl_init')) {
      return ['ok' => false, 'error' => 'The PHP cURL extension is required for Gemini requests.'];
    }

    $contents = self::normaliseMessages($messages);
    if (!$contents) {
      return ['ok' => false, 'error' => 'Send a message first.'];
    }

    $model = trim((string)(app_config('ai.gemini_model') ?? 'gemini-2.5-flash'));
    if ($model === '') $model = 'gemini-2.5-flash';
    $model = preg_replace('~^models/~', '', $model);
    $endpoint = rtrim((string)(app_config('ai.gemini_endpoint') ?? 'https://generativelanguage.googleapis.com/v1beta'), '/');
    $url = $endpoint . '/models/' . rawurlencode($model) . ':generateContent';

    $payload = [
      'system_instruction' => [
        'parts' => [[
          'text' => self::systemPrompt($actor),
        ]],
      ],
      'contents' => $contents,
      'generationConfig' => [
        'temperature' => 0.2,
        'topP' => 0.8,
        'maxOutputTokens' => 900,
      ],
    ];

    $ch = curl_init($url);
    curl_setopt_array($ch, [
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_POST => true,
      CURLOPT_HTTPHEADER => [
        'Content-Type: application/json',
        'x-goog-api-key: ' . $apiKey,
      ],
      CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
      CURLOPT_CONNECTTIMEOUT => 8,
      CURLOPT_TIMEOUT => 30,
    ]);

    $raw = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($raw === false || $raw === '') {
      return ['ok' => false, 'error' => $curlError !== '' ? $curlError : 'Gemini did not return a response.'];
    }

    $json = json_decode($raw, true);
    if ($status < 200 || $status >= 300) {
      $message = (string)($json['error']['message'] ?? 'Gemini request failed.');
      return ['ok' => false, 'error' => $message, 'status' => $status];
    }

    $text = self::extractText(is_array($json) ? $json : []);
    if ($text === '') {
      return ['ok' => false, 'error' => 'Gemini returned an empty answer.'];
    }

    return ['ok' => true, 'reply' => $text];
  }

  private static function normaliseMessages(array $messages): array {
    $messages = array_slice($messages, -10);
    $contents = [];
    foreach ($messages as $item) {
      if (!is_array($item)) continue;
      $role = strtolower(trim((string)($item['role'] ?? 'user')));
      $text = trim((string)($item['content'] ?? ''));
      if ($text === '') continue;
      if (strlen($text) > 3000) $text = substr($text, 0, 3000);
      $contents[] = [
        'role' => $role === 'assistant' || $role === 'model' ? 'model' : 'user',
        'parts' => [['text' => $text]],
      ];
    }
    return $contents;
  }

  private static function extractText(array $json): string {
    $parts = $json['candidates'][0]['content']['parts'] ?? [];
    if (!is_array($parts)) return '';
    $out = [];
    foreach ($parts as $part) {
      if (is_array($part) && isset($part['text'])) $out[] = (string)$part['text'];
    }
    return trim(implode("\n", $out));
  }

  private static function systemPrompt(array $actor): string {
    $base = base_path();
    $role = strtolower(trim((string)($actor['role'] ?? '')));
    $name = trim((string)($actor['name'] ?? 'administrator'));
    $siteAccess = (array)($actor['site_access'] ?? []);
    $stats = self::adminStats($role, $siteAccess);
    $sites = $stats['sites'] ? implode(', ', array_slice($stats['sites'], 0, 12)) : 'none listed';
    if (count($stats['sites']) > 12) $sites .= ', and more';

    $userNav = role_level($role) >= role_level('website_admin')
      ? "{$base}/admin/users.php manages users; {$base}/admin/user_new.php creates users."
      : 'User management is hidden unless the staff member has Website Admin or Super Admin access.';

    return <<<PROMPT
You are the NexusCMS Admin Assistant for internal administration staff.

Scope:
- Help staff navigate and understand the NexusCMS admin system only.
- Do not use, summarize, or rewrite content from the individual content websites as your knowledge base.
- You may mention how to reach content editing screens from the admin area, but keep answers focused on CMS administration.
- You cannot make changes, publish, delete, create users, or update settings. Tell the user which screen to use and what to check.
- If the user asks for a risky or destructive action, explain the navigation and remind them to confirm selections before submitting.
- Keep answers concise and practical. Prefer exact admin links when useful.

Current staff member:
- Name: {$name}
- Role: {$role}
- Accessible sites: {$sites}
- Visible site count: {$stats['site_count']}
- Visible page count: {$stats['page_count']}

NexusCMS admin map:
- {$base}/ is the dashboard for messages, alerts, analytics summaries, and quick links.
- {$base}/admin/index.php lists sites, statuses, duplicate/archive/delete row actions, and bulk status actions.
- {$base}/admin/site.php?id=SITE_ID manages one site's pages and site-level content settings.
- {$base}/admin/page_builder.php?id=PAGE_ID edits a page with save draft, publish, preview, revisions, and locked-page rules.
- {$base}/admin/site_new.php creates a new site.
- {$base}/admin/images.php manages media uploads.
- {$base}/admin/databases.php manages database-style resources and citation/example data.
- {$base}/admin/notifications.php shows reported page issues and escalation workflow.
- {$base}/admin/settings.php manages the current staff account, password, theme, and access overview.
- {$userNav}
- Use the AI icon next to the notifications icon in the top-right admin bar to open this assistant.

Answer style:
- Start with the direct answer.
- Use short steps when navigation has more than one action.
- When a question needs a specific site or page and the user has not named it, ask for that site/page name.
PROMPT;
  }

  private static function adminStats(string $role, array $siteAccess): array {
    $sites = [];
    $siteIds = [];
    try {
      foreach (Site::all() as $site) {
        $slug = (string)($site['slug'] ?? '');
        if ($role !== 'super_admin' && !in_array('*', $siteAccess, true) && !in_array($slug, $siteAccess, true)) {
          continue;
        }
        $siteIds[] = (int)$site['id'];
        $sites[] = trim((string)($site['name'] ?? $slug ?: 'Untitled site'));
      }
    } catch (\Throwable $e) {
      return ['site_count' => 0, 'page_count' => 0, 'sites' => []];
    }

    $pageCount = 0;
    if ($siteIds) {
      try {
        $ph = implode(',', array_fill(0, count($siteIds), '?'));
        $st = DB::pdo()->prepare("SELECT COUNT(*) FROM pages WHERE site_id IN ({$ph})");
        $st->execute($siteIds);
        $pageCount = (int)$st->fetchColumn();
      } catch (\Throwable $e) {
        $pageCount = 0;
      }
    }

    sort($sites, SORT_NATURAL | SORT_FLAG_CASE);
    return ['site_count' => count($sites), 'page_count' => $pageCount, 'sites' => $sites];
  }
}
