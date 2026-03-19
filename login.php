<?php
require __DIR__ . '/app/bootstrap.php';

use NexusCMS\Models\User;
use NexusCMS\Core\Security;
use NexusCMS\Core\DB;

$error = null;
$notice = null;
$mode = isset($_GET['mode']) ? trim((string)$_GET['mode']) : 'login';
$mode = in_array($mode, ['login', 'signup'], true) ? $mode : 'login';
$return = isset($_GET['return']) ? trim((string)$_GET['return']) : '/';

/**
 * Keep redirects local and base-path aware.
 */
function normalize_return_path(string $candidate): string {
  $base = rtrim(base_path(), '/');
  $to = trim($candidate);
  if ($to === '') return '/';

  if (preg_match('#^https?://#i', $to)) {
    $path = (string)(parse_url($to, PHP_URL_PATH) ?? '/');
    $query = (string)(parse_url($to, PHP_URL_QUERY) ?? '');
    $to = $path . ($query !== '' ? ('?' . $query) : '');
  }

  if ($base !== '' && str_starts_with($to, $base)) {
    $to = substr($to, strlen($base));
  }

  if ($to === '' || $to[0] !== '/') $to = '/' . ltrim($to, '/');
  return $to;
}

if (isset($_SESSION['user_id']) && (int)$_SESSION['user_id'] > 0 && $_SERVER['REQUEST_METHOD'] !== 'POST') {
  $to = normalize_return_path($return);
  header('Location: ' . rtrim(base_path(), '/') . $to);
  exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!Security::checkCsrf($_POST['_csrf'] ?? null)) {
    $error = "Security check failed.";
  } else {
    $action = trim((string)($_POST['action'] ?? 'login'));
    $mode = in_array($action, ['login', 'signup'], true) ? $action : 'login';
    $email = strtolower(trim((string)($_POST['email'] ?? '')));
    $pass  = (string)($_POST['password'] ?? '');
    $return = trim((string)($_POST['return'] ?? '/'));

    if ($mode === 'signup') {
      $displayName = trim((string)($_POST['display_name'] ?? ''));
      $confirm = (string)($_POST['password_confirm'] ?? '');
      $institutionName = trim((string)($_POST['institution_name'] ?? ''));

      if ($email === '') $error = 'Email is required.';
      elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) $error = 'Enter a valid email address.';
      elseif (strlen($pass) < 8) $error = 'Password must be at least 8 characters.';
      elseif ($pass !== $confirm) $error = 'Passwords do not match.';
      elseif ($institutionName === '') $error = 'Please select your institution.';
      elseif (User::findByEmail($email)) $error = 'An account with this email already exists.';

      if (!$error) {
        try {
          $hash = password_hash($pass, PASSWORD_DEFAULT);
          $accessJson = json_encode(['institution' => $institutionName], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
          $st = DB::pdo()->prepare("INSERT INTO users (email, password_hash, display_name, role, access) VALUES (?,?,?,?,?)");
          $st->execute([$email, $hash, $displayName ?: $email, 'student', $accessJson]);
          $uid = (int)DB::pdo()->lastInsertId();
          $user = User::findById($uid);
          if (!$user) {
            $error = 'Unable to create account.';
          } else {
            establish_user_session($user);
            $to = normalize_return_path($return);
            header('Location: ' . rtrim(base_path(), '/') . $to);
            exit;
          }
        } catch (\Throwable $e) {
          $error = 'Unable to create account right now.';
        }
      }
    } else {
      $user = User::findByEmail($email);
      if ($user && !empty($user['password_hash']) && password_verify($pass, $user['password_hash'])) {
        establish_user_session($user);
        $to = normalize_return_path($return);
        header('Location: ' . rtrim(base_path(), '/') . $to);
        exit;
      } else {
        $error = "Invalid credentials.";
      }
    }
  }
}

$loginCardClass = $mode === 'login' ? 'tab is-active' : 'tab';
$signupCardClass = $mode === 'signup' ? 'tab is-active' : 'tab';
?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Bloomsbury Portal</title>
  <style>
    :root{
      --bg:#eef0f4;
      --card:#ffffff;
      --line:#d7dce3;
      --text:#1d2430;
      --muted:#647084;
      --primary:#223f85;
      --primary-2:#7f8ca8;
    }
    *{box-sizing:border-box}
    body{
      margin:0;
      min-height:100vh;
      font-family:"Open Sans","Segoe UI",Arial,sans-serif;
      background:radial-gradient(1200px 500px at 100% -10%, #dfe6f2 0%, transparent 60%), var(--bg);
      color:var(--text);
      display:grid;
      place-items:center;
      padding:24px;
    }
    .card{
      width:min(900px, 100%);
      background:var(--card);
      border:1px solid var(--line);
      border-radius:20px;
      box-shadow:0 18px 46px rgba(17,24,39,.08);
      overflow:hidden;
    }
    .top{
      padding:22px 26px 14px;
      border-bottom:1px solid var(--line);
      display:flex;
      justify-content:space-between;
      align-items:end;
      gap:12px;
      flex-wrap:wrap;
    }
    .brand{font-size:22px;font-weight:700;letter-spacing:.2px}
    .brand span{color:#1080c2}
    .sub{color:var(--muted);font-size:14px}
    .tabs{display:flex;gap:8px;padding:12px 26px;border-bottom:1px solid var(--line);background:#f8f9fb}
    .tab{
      appearance:none;border:1px solid var(--line);background:#fff;color:var(--text);
      padding:9px 13px;border-radius:10px;cursor:pointer;font-weight:600;font-size:14px;text-decoration:none;
    }
    .tab.is-active{background:var(--primary);border-color:var(--primary);color:#fff}
    .content{display:grid;grid-template-columns:1fr;gap:0}
    .pane{padding:22px 26px}
    .row{display:grid;grid-template-columns:1fr 1fr;gap:12px}
    label{display:block;margin:8px 0 6px;font-size:13px;color:var(--muted);font-weight:700}
    input{
      width:100%;padding:11px 12px;border:1px solid var(--line);border-radius:10px;
      font-size:14px;background:#fff;color:var(--text);
    }
    input:focus{outline:none;border-color:#9aa6be;box-shadow:0 0 0 3px rgba(34,63,133,.1)}
    .btn{
      margin-top:14px;width:100%;padding:12px 14px;border-radius:10px;border:1px solid var(--primary);
      background:var(--primary);color:#fff;font-weight:700;cursor:pointer;font-size:14px;
    }
    .hint{margin-top:8px;color:var(--muted);font-size:12px}
    .error,.notice{margin:0 26px 14px;padding:10px 12px;border-radius:10px;font-size:14px;font-weight:600}
    .error{background:#fff0f0;border:1px solid #f4c7c7;color:#9f1d1d}
    .notice{background:#eef9f1;border:1px solid #c7e8cf;color:#15613b}
    .inst-wrap{position:relative}
    .inst-list{
      margin-top:6px;border:1px solid var(--line);border-radius:10px;background:#fff;
      max-height:220px;overflow:auto;display:none;
    }
    .inst-item{
      width:100%;text-align:left;background:#fff;border:none;border-bottom:1px solid #eef1f4;
      padding:10px 12px;cursor:pointer;color:var(--text);font-size:14px;
    }
    .inst-item:last-child{border-bottom:none}
    .inst-item:hover,.inst-item:focus{background:#f3f6fb;outline:none}
    .inst-empty{padding:10px 12px;color:var(--muted);font-size:13px}
    .hidden{display:none}
    @media (max-width:700px){
      .row{grid-template-columns:1fr}
      .top,.tabs,.pane{padding-left:16px;padding-right:16px}
      .error,.notice{margin-left:16px;margin-right:16px}
    }
  </style>
</head>
<body>
  <div class="card">
    <div class="top">
      <div>
        <div class="brand">Bloomsbury <span>Portal</span></div>
        <div class="sub">Sign in or create an account to continue</div>
      </div>
    </div>
    <div class="tabs">
      <a class="<?= Security::e($loginCardClass) ?>" href="<?= Security::e(rtrim(base_path(), '/')) ?>/login.php?mode=login&amp;return=<?= urlencode($return) ?>">Log in</a>
      <a class="<?= Security::e($signupCardClass) ?>" href="<?= Security::e(rtrim(base_path(), '/')) ?>/login.php?mode=signup&amp;return=<?= urlencode($return) ?>">Sign up</a>
    </div>

    <?php if ($error): ?><div class="error"><?= Security::e($error) ?></div><?php endif; ?>
    <?php if ($notice): ?><div class="notice"><?= Security::e($notice) ?></div><?php endif; ?>

    <div class="content">
      <div class="pane <?= $mode === 'login' ? '' : 'hidden' ?>" id="pane-login">
        <form method="post" autocomplete="on">
          <input type="hidden" name="_csrf" value="<?= Security::e(Security::csrfToken()) ?>">
          <input type="hidden" name="action" value="login">
          <input type="hidden" name="return" value="<?= Security::e($return) ?>">
          <label>Email</label>
          <input name="email" type="email" required value="<?= Security::e((string)($_POST['email'] ?? '')) ?>">
          <label>Password</label>
          <input name="password" type="password" required>
          <button class="btn" type="submit">Log in</button>
        </form>
      </div>

      <div class="pane <?= $mode === 'signup' ? '' : 'hidden' ?>" id="pane-signup">
        <form method="post" autocomplete="on">
          <input type="hidden" name="_csrf" value="<?= Security::e(Security::csrfToken()) ?>">
          <input type="hidden" name="action" value="signup">
          <input type="hidden" name="return" value="<?= Security::e($return) ?>">
          <div class="row">
            <div>
              <label>Display name</label>
              <input name="display_name" type="text" value="<?= Security::e((string)($_POST['display_name'] ?? '')) ?>">
            </div>
            <div>
              <label>Email</label>
              <input name="email" type="email" required value="<?= Security::e((string)($_POST['email'] ?? '')) ?>">
            </div>
          </div>
          <div class="row">
            <div>
              <label>Password</label>
              <input name="password" type="password" minlength="8" required>
            </div>
            <div>
              <label>Confirm password</label>
              <input name="password_confirm" type="password" minlength="8" required>
            </div>
          </div>
          <label>Institution</label>
          <div class="inst-wrap">
            <input id="institution_search" type="text" placeholder="Search institution name..." autocomplete="off" value="<?= Security::e((string)($_POST['institution_name'] ?? '')) ?>">
            <input id="institution_name" name="institution_name" type="hidden" value="<?= Security::e((string)($_POST['institution_name'] ?? '')) ?>">
            <div id="institution_list" class="inst-list" role="listbox" aria-label="Institution results"></div>
          </div>
          <button class="btn" type="submit">Create account</button>
        </form>
      </div>
    </div>
  </div>
  <script>
    (function(){
      const search = document.getElementById('institution_search');
      const hidden = document.getElementById('institution_name');
      const list = document.getElementById('institution_list');
      const signupPane = document.getElementById('pane-signup');
      if (!search || !hidden || !list || !signupPane) return;
      if (signupPane.classList.contains('hidden')) return;

      let timer = null;
      let ctrl = null;
      let options = [];

      const esc = (s) => String(s).replace(/[&<>"']/g, (m) => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[m]));

      const render = () => {
        if (!options.length) {
          list.innerHTML = '<div class="inst-empty">No institutions found.</div>';
          list.style.display = 'block';
          return;
        }
        list.innerHTML = options.map((name, i) => `<button class="inst-item" type="button" data-i="${i}">${esc(name)}</button>`).join('');
        list.style.display = 'block';
      };

      const fetchInstitutions = async (q) => {
        if (ctrl) ctrl.abort();
        ctrl = new AbortController();
        const query = encodeURIComponent(q);
        const sources = [
          `https://api.openalex.org/institutions?search=${query}&per-page=20`,
          `https://universities.hipolabs.com/search?name=${query}`
        ];

        for (const url of sources) {
          try {
            const res = await fetch(url, { signal: ctrl.signal });
            if (!res.ok) continue;
            const data = await res.json();
            if (url.includes('openalex')) {
              const rows = Array.isArray(data?.results) ? data.results : [];
              const names = rows.map(r => String(r?.display_name || '').trim()).filter(Boolean);
              if (names.length) return names;
            } else {
              const rows = Array.isArray(data) ? data : [];
              const names = rows.map(r => String(r?.name || '').trim()).filter(Boolean);
              if (names.length) return names;
            }
          } catch (e) {
            if (e && e.name === 'AbortError') return [];
          }
        }
        return [];
      };

      search.addEventListener('input', () => {
        hidden.value = search.value.trim();
        const q = search.value.trim();
        if (timer) clearTimeout(timer);
        if (q.length < 2) {
          options = [];
          list.style.display = 'none';
          return;
        }
        timer = setTimeout(async () => {
          options = await fetchInstitutions(q);
          render();
        }, 220);
      });

      list.addEventListener('click', (e) => {
        const btn = e.target.closest('.inst-item');
        if (!btn) return;
        const idx = Number(btn.getAttribute('data-i'));
        const chosen = options[idx] || '';
        if (!chosen) return;
        search.value = chosen;
        hidden.value = chosen;
        list.style.display = 'none';
      });

      document.addEventListener('click', (e) => {
        if (!e.target.closest('.inst-wrap')) list.style.display = 'none';
      });
    })();
  </script>
</body>
</html>
