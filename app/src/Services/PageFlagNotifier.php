<?php
namespace NexusCMS\Services;

use NexusCMS\Models\PageFlag;
use NexusCMS\Models\User;

final class PageFlagNotifier {
  public static function notifyCreated(array $flag): void {
    self::sendToOwners(
      $flag,
      (string)($flag['current_owner_role'] ?? ''),
      'New flagged page: ' . ((string)($flag['page_title'] ?? 'Untitled page')),
      self::buildOwnerMessage($flag, 'A new page flag has been created and routed to your queue.')
    );
  }

  public static function notifyEscalated(array $flag, string $fromRole): void {
    self::sendToOwners(
      $flag,
      (string)($flag['current_owner_role'] ?? ''),
      'Escalated flagged page: ' . ((string)($flag['page_title'] ?? 'Untitled page')),
      self::buildOwnerMessage($flag, 'A page flag has been escalated from ' . PageFlag::roleLabel($fromRole) . ' to your queue.')
    );
  }

  public static function notifyReporterUpdate(array $flag, string $subjectLead, string $messageLead, ?string $note = null): void {
    $email = trim((string)($flag['reporter_email'] ?? ''));
    if ($email === '') return;
    $siteName = (string)($flag['site_name'] ?? ('Site #' . (int)($flag['site_id'] ?? 0)));
    $body = $subjectLead . "\n\n"
      . $messageLead . "\n\n"
      . 'Site: ' . $siteName . "\n"
      . 'Page: ' . ((string)($flag['page_title'] ?? 'Untitled page')) . "\n"
      . 'Link: ' . ((string)($flag['page_url'] ?? '')) . "\n"
      . 'Current owner: ' . PageFlag::roleLabel((string)($flag['current_owner_role'] ?? '')) . "\n"
      . 'Status: ' . ucfirst((string)($flag['status'] ?? 'open')) . "\n";
    if ($note !== null && trim($note) !== '') {
      $body .= "\nMessage:\n" . trim($note) . "\n";
    }
    Mailer::send([
      'to' => [$email],
      'subject' => $subjectLead . ': ' . ((string)($flag['page_title'] ?? 'Untitled page')),
      'text' => $body,
    ]);
  }

  private static function sendToOwners(array $flag, string $ownerRole, string $subject, string $messageLead): void {
    $recipients = User::notificationRecipientsForRoleAndSite($ownerRole, (int)($flag['site_id'] ?? 0));
    if (!$recipients) return;
    $body = self::buildOwnerMessage($flag, $messageLead);
    Mailer::send([
      'to' => $recipients,
      'subject' => $subject,
      'text' => $body,
    ]);
  }

  private static function buildOwnerMessage(array $flag, string $lead): string {
    $siteName = (string)($flag['site_name'] ?? ('Site #' . (int)($flag['site_id'] ?? 0)));
    $reporterName = trim((string)($flag['reporter_name'] ?? 'Unknown user'));
    $reporterEmail = trim((string)($flag['reporter_email'] ?? ''));
    $reporterRole = PageFlag::roleLabel((string)($flag['reporter_role'] ?? ''));
    $queueUrl = (function_exists('base_path') ? rtrim((string)base_path(), '/') : '') . '/admin/notifications.php';

    $body = $lead . "\n\n"
      . 'Site: ' . $siteName . "\n"
      . 'Page: ' . ((string)($flag['page_title'] ?? 'Untitled page')) . "\n"
      . 'Page path: ' . ((string)($flag['page_path'] ?? '')) . "\n"
      . 'Page link: ' . ((string)($flag['page_url'] ?? '')) . "\n"
      . 'Reporter: ' . $reporterName . ($reporterEmail !== '' ? ' <' . $reporterEmail . '>' : '') . "\n"
      . 'Reporter role: ' . $reporterRole . "\n"
      . 'Assigned to: ' . PageFlag::roleLabel((string)($flag['current_owner_role'] ?? '')) . "\n"
      . 'Description:' . "\n" . trim((string)($flag['description'] ?? '')) . "\n\n"
      . 'Open the queue: ' . $queueUrl . "\n";
    return $body;
  }
}
