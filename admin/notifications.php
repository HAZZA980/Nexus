<?php
require __DIR__ . '/../app/bootstrap.php';
require_admin();

use NexusCMS\Core\Security;
use NexusCMS\Models\PageFlag;
use NexusCMS\Models\User;
use NexusCMS\Services\PageFlagNotifier;

$base = base_path();
$activeNav = 'notifications';
$themeIsLight = ui_theme_is_light();
$csrfToken = Security::csrfToken();
$userId = (int)($_SESSION['user_id'] ?? 0);
$siteAccess = array_map('strval', (array)($_SESSION['site_access'] ?? []));
$currentUser = User::findById($userId);

if (!$currentUser) {
  redirect('/admin/login.php');
}

$role = strtolower((string)($currentUser['role'] ?? $_SESSION['user_role'] ?? ''));
$actor = [
  'id' => $userId,
  'name' => trim((string)($currentUser['display_name'] ?? $currentUser['email'] ?? 'Administrator')),
  'role' => $role,
];

$flash = $_SESSION['admin_notifications_flash'] ?? null;
unset($_SESSION['admin_notifications_flash']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!Security::checkCsrf($_POST['_csrf'] ?? null)) {
    $_SESSION['admin_notifications_flash'] = ['type' => 'error', 'message' => 'Security check failed.'];
    header('Location: ' . $base . '/admin/notifications.php');
    exit;
  }

  $flagId = (int)($_POST['flag_id'] ?? 0);
  $view = trim((string)($_POST['view'] ?? 'inbox'));
  $redirectUrl = $base . '/admin/notifications.php?view=' . urlencode($view);
  if ($flagId > 0) $redirectUrl .= '&flag=' . $flagId;

  try {
    $flag = PageFlag::findVisibleToUser($flagId, $userId, $role, $siteAccess);
    if (!$flag) throw new RuntimeException('Issue not found.');
    $mode = (string)($_POST['mode'] ?? '');
    $note = trim((string)($_POST['note'] ?? ''));

    if ($mode === 'comment') {
      if ($note === '') throw new RuntimeException('Enter a comment before sending.');
      PageFlag::addComment($flagId, $actor, $note);
      if ((int)($flag['reporter_user_id'] ?? 0) !== $userId) {
        $flag['site_name'] = (string)($flag['site_name'] ?? '');
        PageFlagNotifier::notifyReporterUpdate($flag, 'Update on your flagged page', 'A staff member has replied to the issue you reported.', $note);
      }
      $_SESSION['admin_notifications_flash'] = ['type' => 'notice', 'message' => 'Comment added.'];
    } elseif ($mode === 'resolve') {
      PageFlag::resolve($flagId, $actor, $note);
      $updated = PageFlag::findVisibleToUser($flagId, $userId, 'super_admin', ['*']) ?: $flag;
      PageFlagNotifier::notifyReporterUpdate($updated, 'Flag resolved', 'Your flagged page issue has been marked as resolved.', $note);
      $_SESSION['admin_notifications_flash'] = ['type' => 'notice', 'message' => 'Issue resolved.'];
    } elseif ($mode === 'escalate') {
      $fromRole = (string)($flag['current_owner_role'] ?? '');
      PageFlag::escalate($flagId, $actor, $note);
      $updated = PageFlag::findVisibleToUser($flagId, $userId, 'super_admin', ['*']) ?: $flag;
      PageFlagNotifier::notifyEscalated($updated, $fromRole);
      PageFlagNotifier::notifyReporterUpdate($updated, 'Flag escalated', 'Your flagged page issue has been escalated to the next support level.', $note);
      $_SESSION['admin_notifications_flash'] = ['type' => 'notice', 'message' => 'Issue escalated.'];
    }
  } catch (\Throwable $e) {
    $_SESSION['admin_notifications_flash'] = ['type' => 'error', 'message' => $e->getMessage() ?: 'Unable to update notification.'];
  }

  header('Location: ' . $redirectUrl);
  exit;
}

function notif_time(?string $ts): string {
  if (!$ts) return '—';
  $time = strtotime($ts);
  if (!$time) return '—';
  $diff = max(0, time() - $time);
  if ($diff < 60) return 'Just now';
  $units = [31536000 => 'year', 2592000 => 'month', 604800 => 'week', 86400 => 'day', 3600 => 'hour', 60 => 'minute'];
  foreach ($units as $secs => $label) {
    if ($diff >= $secs) {
      $val = (int)floor($diff / $secs);
      return $val . ' ' . $label . ($val === 1 ? '' : 's') . ' ago';
    }
  }
  return '—';
}

$inbox = PageFlag::inboxForUser($userId, $role, $siteAccess);
$reported = PageFlag::reportedByUser($userId, $role, $siteAccess);
$view = ($_GET['view'] ?? 'inbox') === 'reported' ? 'reported' : 'inbox';
$selectedId = (int)($_GET['flag'] ?? 0);
$list = $view === 'reported' ? $reported : $inbox;
$selected = null;

if ($selectedId > 0) {
  $selected = PageFlag::findVisibleToUser($selectedId, $userId, $role, $siteAccess);
}
if (!$selected && $list) {
  $selected = PageFlag::findVisibleToUser((int)$list[0]['id'], $userId, $role, $siteAccess);
}
$events = $selected ? PageFlag::eventsForFlag((int)$selected['id']) : [];
$canActOnSelected = $selected && (PageFlag::canonicalRole($role) === 'super_admin' || ($selected['current_owner_role'] ?? '') === PageFlag::canonicalRole($role));
$canEscalate = $canActOnSelected && $selected && ($selected['status'] ?? '') !== 'resolved' && PageFlag::nextOwnerRole((string)($selected['current_owner_role'] ?? '')) !== ($selected['current_owner_role'] ?? '');
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Notifications — NexusCMS Admin</title>
  <script>
    (function(){
      document.documentElement.classList.toggle('theme-light', <?= $themeIsLight ? 'true' : 'false' ?>);
    })();
  </script>
  <link rel="stylesheet" href="<?= $base ?>/public/assets/admin-shared.css?v=20260322">
  <style>
    body{margin:0;background:var(--admin-bg);color:var(--admin-text);font:14px/1.4 Arial, Helvetica, sans-serif;}
    .content{padding:14px;display:grid;gap:12px;}
    .panel{background:var(--admin-surface);border:1px solid var(--admin-line);border-radius:4px;}
    .panel-head{display:flex;align-items:center;justify-content:space-between;gap:8px;padding:10px 12px;border-bottom:1px solid var(--admin-line);}
    .panel-title{margin:0;font-size:16px;font-weight:700;color:var(--admin-text-strong)}
    .notice,.error-banner{margin:0;padding:10px 12px;border-radius:4px;border:1px solid transparent;font-size:13px}
    .notice{border-color:color-mix(in srgb, var(--admin-success) 40%, var(--admin-line));background:color-mix(in srgb, var(--admin-success) 16%, transparent);color:var(--admin-success)}
    .error-banner{border-color:color-mix(in srgb, var(--admin-danger) 40%, var(--admin-line));background:color-mix(in srgb, var(--admin-danger) 14%, transparent);color:var(--admin-danger)}
    .summary{display:grid;grid-template-columns:repeat(4,minmax(120px,1fr));gap:8px;padding:10px 12px;}
    .metric{border:1px solid var(--admin-line);border-radius:4px;padding:8px;background:var(--admin-surface-2)}
    .metric-label{font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.4px;color:var(--admin-muted)}
    .metric-value{margin-top:2px;font-size:20px;font-weight:700;color:var(--admin-text-strong)}
    .split{display:grid;grid-template-columns:360px 1fr;gap:12px}
    .tabs{display:flex;gap:8px;padding:10px 12px;border-bottom:1px solid var(--admin-line);background:var(--admin-surface-2)}
    .tab{display:inline-flex;align-items:center;padding:7px 10px;border:1px solid var(--admin-line);border-radius:999px;text-decoration:none;color:var(--admin-text-strong);font-weight:700}
    .tab.active{background:color-mix(in srgb, var(--admin-accent) 18%, transparent);border-color:color-mix(in srgb, var(--admin-accent) 42%, var(--admin-line))}
    .queue{display:grid;gap:0;max-height:calc(100vh - 210px);overflow:auto}
    .queue-item{display:block;padding:12px;border-bottom:1px solid var(--admin-line);text-decoration:none;color:inherit}
    .queue-item:hover{background:color-mix(in srgb, var(--admin-accent) 10%, transparent)}
    .queue-item.active{background:color-mix(in srgb, var(--admin-accent) 14%, transparent)}
    .queue-title{font-weight:700;color:var(--admin-text-strong)}
    .queue-meta,.muted{color:var(--admin-muted);font-size:12px}
    .queue-chip{display:inline-flex;align-items:center;padding:2px 8px;border-radius:999px;border:1px solid var(--admin-line);font-size:11px;font-weight:700}
    .detail{padding:12px;display:grid;gap:12px}
    .detail-card{border:1px solid var(--admin-line);border-radius:4px;background:var(--admin-surface-2)}
    .detail-grid{display:grid;grid-template-columns:160px 1fr;gap:8px 10px;padding:12px}
    .detail-label{font-size:12px;font-weight:700;color:var(--admin-muted)}
    .detail-value{color:var(--admin-text)}
    .description{padding:12px;border-top:1px solid var(--admin-line);white-space:pre-wrap}
    .event-list{display:grid;gap:8px;padding:12px}
    .event{border:1px solid var(--admin-line);border-radius:4px;padding:10px;background:var(--admin-surface)}
    .event-head{display:flex;justify-content:space-between;gap:8px;font-size:12px;color:var(--admin-muted);margin-bottom:6px}
    .event-body{white-space:pre-wrap}
    .form-stack{display:grid;gap:8px;padding:12px}
    textarea{width:100%;min-height:110px;padding:10px;border-radius:4px;border:1px solid var(--admin-line);background:var(--admin-surface);color:var(--admin-text);font:inherit;resize:vertical}
    .actions{display:flex;gap:8px;flex-wrap:wrap}
    .btn{display:inline-flex;align-items:center;justify-content:center;min-height:30px;padding:0 10px;border:1px solid var(--admin-line);border-radius:4px;background:var(--admin-surface-2);color:var(--admin-text-strong);font-size:13px;font-weight:600;cursor:pointer}
    .btn.primary{border-color:color-mix(in srgb, var(--admin-accent) 60%, var(--admin-line));background:var(--admin-accent);color:#fff}
    .btn.danger{border-color:color-mix(in srgb, var(--admin-danger) 56%, var(--admin-line));background:color-mix(in srgb, var(--admin-danger) 8%, transparent);color:var(--admin-danger)}
    .empty{padding:14px;color:var(--admin-muted)}
    @media (max-width:1100px){.split{grid-template-columns:1fr}}
    @media (max-width:640px){.summary,.detail-grid{grid-template-columns:1fr}}
  </style>
</head>
<body>
  <?php include __DIR__ . '/partials/header.php'; ?>
  <main class="content">
    <?php if (is_array($flash) && (($flash['message'] ?? '') !== '')): ?>
      <p class="<?= ($flash['type'] ?? 'notice') === 'error' ? 'error-banner' : 'notice' ?>"><?= Security::e((string)$flash['message']) ?></p>
    <?php endif; ?>

    <section class="panel">
      <div class="panel-head">
        <h1 class="panel-title">Notifications</h1>
      </div>
      <div class="summary">
        <div class="metric"><div class="metric-label">Inbox</div><div class="metric-value"><?= count($inbox) ?></div></div>
        <div class="metric"><div class="metric-label">Reported By Me</div><div class="metric-value"><?= count($reported) ?></div></div>
        <div class="metric"><div class="metric-label">Role</div><div class="metric-value"><?= Security::e(PageFlag::roleLabel($role)) ?></div></div>
        <div class="metric"><div class="metric-label">Open Queue</div><div class="metric-value"><?= PageFlag::inboxCountForUser($userId, $role, $siteAccess) ?></div></div>
      </div>
    </section>

    <section class="split">
      <section class="panel">
        <div class="tabs">
          <a class="tab <?= $view === 'inbox' ? 'active' : '' ?>" href="<?= $base ?>/admin/notifications.php?view=inbox">Inbox</a>
          <a class="tab <?= $view === 'reported' ? 'active' : '' ?>" href="<?= $base ?>/admin/notifications.php?view=reported">Reported by Me</a>
        </div>
        <?php if (!$list): ?>
          <div class="empty">No notifications in this view.</div>
        <?php else: ?>
          <div class="queue">
            <?php foreach ($list as $item): ?>
              <a class="queue-item <?= $selected && (int)$selected['id'] === (int)$item['id'] ? 'active' : '' ?>" href="<?= $base ?>/admin/notifications.php?view=<?= Security::e($view) ?>&flag=<?= (int)$item['id'] ?>">
                <div style="display:flex;justify-content:space-between;gap:8px;align-items:flex-start;">
                  <div class="queue-title"><?= Security::e((string)($item['page_title'] ?: 'Untitled page')) ?></div>
                  <span class="queue-chip"><?= Security::e(PageFlag::roleLabel((string)($item['current_owner_role'] ?? ''))) ?></span>
                </div>
                <div class="queue-meta"><?= Security::e((string)($item['site_name'] ?? '')) ?> • <?= Security::e((string)($item['reporter_name'] ?? 'Unknown')) ?> • <?= Security::e(notif_time((string)($item['updated_at'] ?? ''))) ?></div>
                <div class="muted"><?= Security::e(substr(trim((string)($item['description'] ?? '')), 0, 120)) ?></div>
              </a>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </section>

      <section class="panel">
        <?php if (!$selected): ?>
          <div class="empty">Choose a notification to see the details.</div>
        <?php else: ?>
          <div class="panel-head">
            <h2 class="panel-title"><?= Security::e((string)($selected['page_title'] ?: 'Untitled page')) ?></h2>
          </div>
          <div class="detail">
            <section class="detail-card">
              <div class="detail-grid">
                <div class="detail-label">Site</div><div class="detail-value"><?= Security::e((string)($selected['site_name'] ?? '')) ?></div>
                <div class="detail-label">Assigned to</div><div class="detail-value"><?= Security::e(PageFlag::roleLabel((string)($selected['current_owner_role'] ?? ''))) ?></div>
                <div class="detail-label">Reporter</div><div class="detail-value"><?= Security::e((string)($selected['reporter_name'] ?? '')) ?> (<?= Security::e((string)($selected['reporter_email'] ?? '')) ?>)</div>
                <div class="detail-label">Reporter role</div><div class="detail-value"><?= Security::e(PageFlag::roleLabel((string)($selected['reporter_role'] ?? ''))) ?></div>
                <div class="detail-label">Page link</div><div class="detail-value"><a href="<?= Security::e((string)($selected['page_url'] ?? '#')) ?>" target="_blank" rel="noreferrer"><?= Security::e((string)($selected['page_path'] ?: $selected['page_url'])) ?></a></div>
                <div class="detail-label">Status</div><div class="detail-value"><?= Security::e(ucfirst((string)($selected['status'] ?? 'open'))) ?></div>
                <div class="detail-label">Created</div><div class="detail-value"><?= Security::e(notif_time((string)($selected['created_at'] ?? ''))) ?></div>
              </div>
              <div class="description"><?= Security::e((string)($selected['description'] ?? '')) ?></div>
            </section>

            <section class="detail-card">
              <div class="panel-head" style="border-bottom:1px solid var(--admin-line);padding:10px 12px;">
                <h3 class="panel-title">Activity</h3>
              </div>
              <?php if (!$events): ?>
                <div class="empty">No activity yet.</div>
              <?php else: ?>
                <div class="event-list">
                  <?php foreach ($events as $event): ?>
                    <div class="event">
                      <div class="event-head">
                        <span><?= Security::e((string)($event['user_name'] ?: 'System')) ?> • <?= Security::e(PageFlag::roleLabel((string)($event['user_role'] ?? ''))) ?> • <?= Security::e(ucfirst((string)($event['action_type'] ?? 'comment'))) ?></span>
                        <span><?= Security::e(notif_time((string)($event['created_at'] ?? ''))) ?></span>
                      </div>
                      <?php if (trim((string)($event['body'] ?? '')) !== ''): ?>
                        <div class="event-body"><?= Security::e((string)$event['body']) ?></div>
                      <?php endif; ?>
                      <?php if (($event['action_type'] ?? '') === 'escalated'): ?>
                        <div class="muted">Escalated from <?= Security::e(PageFlag::roleLabel((string)($event['from_role'] ?? ''))) ?> to <?= Security::e(PageFlag::roleLabel((string)($event['to_role'] ?? ''))) ?>.</div>
                      <?php endif; ?>
                    </div>
                  <?php endforeach; ?>
                </div>
              <?php endif; ?>
            </section>

            <section class="detail-card">
              <div class="panel-head" style="border-bottom:1px solid var(--admin-line);padding:10px 12px;">
                <h3 class="panel-title">Actions</h3>
              </div>
              <form method="post" class="form-stack">
                <input type="hidden" name="_csrf" value="<?= Security::e($csrfToken) ?>">
                <input type="hidden" name="flag_id" value="<?= (int)$selected['id'] ?>">
                <input type="hidden" name="view" value="<?= Security::e($view) ?>">
                <textarea name="note" placeholder="Add context, troubleshooting notes, or an escalation reason."></textarea>
                <div class="actions">
                  <button class="btn" type="submit" name="mode" value="comment">Add comment</button>
                  <?php if ($canActOnSelected && ($selected['status'] ?? '') !== 'resolved'): ?>
                    <button class="btn primary" type="submit" name="mode" value="resolve">Resolve</button>
                  <?php endif; ?>
                  <?php if ($canEscalate): ?>
                    <button class="btn danger" type="submit" name="mode" value="escalate">Escalate to <?= Security::e(PageFlag::roleLabel(PageFlag::nextOwnerRole((string)($selected['current_owner_role'] ?? '')))) ?></button>
                  <?php endif; ?>
                </div>
              </form>
            </section>
          </div>
        <?php endif; ?>
      </section>
    </section>
  </main>
</body>
</html>
