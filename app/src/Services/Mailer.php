<?php
namespace NexusCMS\Services;

final class Mailer {
  public static function send(array $message): bool {
    $config = function_exists('app_config') ? (app_config('mail') ?? []) : [];
    $transport = (string)($config['transport'] ?? 'log');
    $fromEmail = trim((string)($config['from_email'] ?? 'noreply@nexuscms.local'));
    $fromName = trim((string)($config['from_name'] ?? 'NexusCMS'));
    $subjectPrefix = trim((string)($config['subject_prefix'] ?? '[NexusCMS]'));
    $logFile = (string)($config['log_file'] ?? (__DIR__ . '/../../../storage/mail.log'));

    $to = array_values(array_filter(array_map('trim', (array)($message['to'] ?? []))));
    if (!$to) return false;

    $subject = trim((string)($message['subject'] ?? 'Notification'));
    if ($subjectPrefix !== '') $subject = $subjectPrefix . ' ' . $subject;
    $text = trim((string)($message['text'] ?? ''));
    if ($text === '') return false;

    $headers = [
      'MIME-Version: 1.0',
      'Content-Type: text/plain; charset=UTF-8',
      'From: ' . ($fromName !== '' ? ($fromName . ' <' . $fromEmail . '>') : $fromEmail),
      'Reply-To: ' . $fromEmail,
      'X-Mailer: NexusCMS',
    ];

    if ($transport === 'mail') {
      $ok = true;
      foreach ($to as $recipient) {
        $sent = @mail($recipient, $subject, $text, implode("\r\n", $headers));
        $ok = $ok && $sent;
      }
      if ($ok) return true;
      self::log($logFile, $to, $subject, $text, 'mail_failed_fallback_to_log');
      return false;
    }

    self::log($logFile, $to, $subject, $text, 'logged_only');
    return true;
  }

  private static function log(string $file, array $to, string $subject, string $text, string $mode): void {
    $dir = dirname($file);
    if (!is_dir($dir)) @mkdir($dir, 0777, true);
    $payload = [
      'sent_at' => date('c'),
      'mode' => $mode,
      'to' => $to,
      'subject' => $subject,
      'text' => $text,
    ];
    @file_put_contents($file, json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL, FILE_APPEND);
  }
}
