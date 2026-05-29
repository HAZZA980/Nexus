<?php
use NexusCMS\Core\Security;

$assistantBase = $base ?? base_path();
$assistantCsrf = $csrfToken ?? Security::csrfToken();
$assistantConfigured = trim((string)(app_config('ai.gemini_api_key') ?? '')) !== '';
?>
<div class="nx-ai-chat" id="nxAiChat">
  <button type="button" class="nx-icon-btn nx-ai-toggle" id="nxAiToggle" aria-label="Open AI assistant" title="AI assistant" aria-expanded="false" aria-controls="nxAiPanel">
    <svg class="nx-ai-toggle-icon" viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="2">
      <path d="M12 3l1.6 4.4L18 9l-4.4 1.6L12 15l-1.6-4.4L6 9l4.4-1.6L12 3Z"/>
      <path d="M19 14l.8 2.2L22 17l-2.2.8L19 20l-.8-2.2L16 17l2.2-.8L19 14Z"/>
      <path d="M5 13l.7 1.8L7.5 15.5l-1.8.7L5 18l-.7-1.8-1.8-.7 1.8-.7L5 13Z"/>
    </svg>
  </button>
  <section class="nx-ai-panel" id="nxAiPanel" aria-label="AI assistant" hidden>
    <div class="nx-ai-head">
      <div>
        <h2>AI Assistant</h2>
        <span><span class="nx-ai-dot <?= $assistantConfigured ? '' : 'off' ?>" aria-hidden="true"></span><?= $assistantConfigured ? 'Gemini connected' : 'Gemini key missing' ?></span>
      </div>
    </div>
    <?php if (!$assistantConfigured): ?>
      <p class="nx-ai-notice">Set <code>GEMINI_API_KEY</code> in <code>.env</code>, then reload PHP/XAMPP.</p>
    <?php endif; ?>
    <div class="nx-ai-chips" aria-label="Prompt shortcuts">
      <button type="button" data-nx-ai-prompt="Where do I go to create a new site?">New site</button>
      <button type="button" data-nx-ai-prompt="How do I publish a page after editing it?">Publish page</button>
      <button type="button" data-nx-ai-prompt="Where can I review reported page issues?">Reports</button>
      <button type="button" data-nx-ai-prompt="How do I check which sites I can access?">Access</button>
    </div>
    <div class="nx-ai-messages" id="nxAiMessages" aria-live="polite">
      <div class="nx-ai-message assistant">Ask about NexusCMS admin navigation, settings, site management, media, reports, or page workflow.</div>
    </div>
    <form class="nx-ai-composer" id="nxAiComposer">
      <textarea id="nxAiInput" name="message" autocomplete="off" placeholder="Ask the admin assistant..." required></textarea>
      <button type="submit" id="nxAiSend">Send</button>
    </form>
  </section>
</div>

<style>
  .nx-ai-chat{
    --nx-ai-surface:var(--admin-surface,var(--surface,#111827));
    --nx-ai-surface-2:var(--admin-surface-2,var(--surface-2,#0b1220));
    --nx-ai-line:var(--admin-line,var(--line,#334155));
    --nx-ai-text:var(--admin-text,var(--text,#e5e7eb));
    --nx-ai-text-strong:var(--admin-text-strong,var(--text-strong,#f8fafc));
    --nx-ai-muted:var(--admin-muted,var(--muted,#94a3b8));
    --nx-ai-accent:var(--admin-accent,var(--accent,#3b82f6));
    --nx-ai-danger:var(--admin-danger,var(--danger,#f87171));
    --nx-ai-success:var(--admin-success,var(--ok,#22c55e));
    --nx-ai-warn:var(--admin-warn,var(--warn,#f59e0b));
    position:relative;
    display:inline-flex;
  }
  .nx-ai-chat .nx-ai-toggle{display:inline-flex;align-items:center;justify-content:center;width:32px;height:32px;padding:0;border:1px solid var(--nx-ai-line);border-radius:4px;background:var(--nx-ai-surface-2);color:var(--nx-ai-text-strong);cursor:pointer}
  .nx-ai-chat .nx-ai-toggle:hover{background:color-mix(in srgb, var(--nx-ai-accent) 12%, var(--nx-ai-surface-2));border-color:color-mix(in srgb, var(--nx-ai-accent) 36%, var(--nx-ai-line))}
  .nx-ai-toggle-icon{width:17px;height:17px;display:block}
  .nx-ai-panel{position:fixed;top:0;right:0;bottom:auto;left:auto;width:min(430px,100vw);height:100vh;height:100dvh;max-height:100vh;max-height:100dvh;display:grid;grid-template-rows:auto auto auto 1fr auto;background:var(--nx-ai-surface);border:1px solid var(--nx-ai-line);border-top:0;border-right:0;border-bottom:0;border-radius:6px 0 0 6px;box-shadow:0 18px 50px rgba(0,0,0,.28);z-index:3900;color:var(--nx-ai-text);overflow:hidden;contain:layout paint}
  .nx-ai-panel[hidden]{display:none}
  .nx-ai-head{position:sticky;top:0;z-index:1;padding:11px 12px;border-bottom:1px solid var(--nx-ai-line);background:var(--nx-ai-surface-2)}
  .nx-ai-head h2{margin:0 0 3px;font-size:14px;line-height:1.2;color:var(--nx-ai-text-strong)}
  .nx-ai-head span{display:inline-flex;align-items:center;gap:7px;font-size:12px;font-weight:700;color:var(--nx-ai-muted)}
  .nx-ai-dot{width:8px;height:8px;border-radius:50%;background:var(--nx-ai-success)}
  .nx-ai-dot.off{background:var(--nx-ai-danger)}
  .nx-ai-notice{margin:0;padding:9px 12px;border-bottom:1px solid var(--nx-ai-line);background:color-mix(in srgb, var(--nx-ai-warn) 12%, transparent);color:var(--nx-ai-text);font-size:12px}
  .nx-ai-chips{display:flex;align-items:flex-start;gap:7px;flex-wrap:wrap;padding:9px 10px;border-bottom:1px solid var(--nx-ai-line);background:var(--nx-ai-surface-2)}
  .nx-ai-chips button{display:inline-flex;align-items:center;justify-content:center;min-height:30px;border:1px solid var(--nx-ai-line);border-radius:999px;background:var(--nx-ai-surface);color:var(--nx-ai-text-strong);font:inherit;font-size:12px;font-weight:700;line-height:1;padding:0 10px;cursor:pointer;white-space:nowrap}
  .nx-ai-chips button:hover{border-color:color-mix(in srgb, var(--nx-ai-accent) 45%, var(--nx-ai-line));background:color-mix(in srgb, var(--nx-ai-accent) 10%, var(--nx-ai-surface))}
  .nx-ai-messages{overflow-y:auto;overflow-x:hidden;overscroll-behavior-y:contain;-webkit-overflow-scrolling:touch;padding:12px;display:flex;flex-direction:column;gap:9px;min-height:0}
  .nx-ai-message{max-width:92%;padding:9px 10px;border:1px solid var(--nx-ai-line);border-radius:6px;white-space:pre-wrap;overflow-wrap:anywhere;font-size:13px;line-height:1.4}
  .nx-ai-message.user{align-self:flex-end;background:var(--nx-ai-accent);border-color:var(--nx-ai-accent);color:#fff}
  .nx-ai-message.assistant{align-self:flex-start;background:var(--nx-ai-surface-2);color:var(--nx-ai-text)}
  .nx-ai-message.error{align-self:flex-start;background:color-mix(in srgb, var(--nx-ai-danger) 12%, var(--nx-ai-surface));border-color:color-mix(in srgb, var(--nx-ai-danger) 40%, var(--nx-ai-line));color:var(--nx-ai-danger)}
  .nx-ai-composer{display:grid;grid-template-columns:1fr auto;gap:8px;border-top:1px solid var(--nx-ai-line);background:var(--nx-ai-surface);padding:10px}
  .nx-ai-composer textarea{width:100%;height:42px;min-height:42px;max-height:120px;resize:none;overflow-y:hidden;border:1px solid var(--nx-ai-line);border-radius:4px;background:var(--nx-ai-surface-2);color:var(--nx-ai-text);font:inherit;font-size:13px;line-height:1.35;padding:9px}
  .nx-ai-composer textarea:focus{outline:2px solid color-mix(in srgb, var(--nx-ai-accent) 55%, transparent);outline-offset:1px}
  .nx-ai-composer button{align-self:end;min-height:36px;border:1px solid color-mix(in srgb, var(--nx-ai-accent) 60%, var(--nx-ai-line));border-radius:4px;background:var(--nx-ai-accent);color:#fff;font:inherit;font-size:13px;font-weight:700;cursor:pointer;padding:0 12px}
  .nx-ai-composer button:disabled{opacity:.58;cursor:not-allowed}
  @media (max-width:640px){
    .nx-ai-panel{right:0;left:0;top:0;width:auto;height:100vh;height:100dvh;max-height:100vh;max-height:100dvh;border-left:0;border-radius:0}
    .nx-ai-composer{grid-template-columns:1fr}
    .nx-ai-composer button{width:100%}
  }
</style>

<script nonce="<?= Security::e(csp_nonce()) ?>">
  (function () {
    var root = document.getElementById('nxAiChat');
    if (!root || root.dataset.ready === '1') return;
    root.dataset.ready = '1';

    var toggle = document.getElementById('nxAiToggle');
    var panel = document.getElementById('nxAiPanel');
    var form = document.getElementById('nxAiComposer');
    var input = document.getElementById('nxAiInput');
    var send = document.getElementById('nxAiSend');
    var messagesEl = document.getElementById('nxAiMessages');
    var endpoint = <?= json_encode($assistantBase . '/api/admin/chatbot', JSON_UNESCAPED_SLASHES) ?>;
    var csrf = <?= json_encode($assistantCsrf, JSON_UNESCAPED_SLASHES) ?>;
    var history = [];

    function openPanel() {
      panel.hidden = false;
      toggle.setAttribute('aria-expanded', 'true');
      window.setTimeout(function () { input && input.focus(); }, 0);
    }

    function closePanel() {
      panel.hidden = true;
      toggle.setAttribute('aria-expanded', 'false');
    }

    function addMessage(role, text) {
      var el = document.createElement('div');
      el.className = 'nx-ai-message ' + role;
      el.textContent = text;
      messagesEl.appendChild(el);
      messagesEl.scrollTop = messagesEl.scrollHeight;
    }

    function setBusy(busy) {
      send.disabled = busy;
      input.disabled = busy;
      send.textContent = busy ? 'Sending' : 'Send';
    }

    function resizeInput() {
      if (!input) return;
      input.style.height = '42px';
      input.style.height = Math.min(input.scrollHeight, 120) + 'px';
      input.style.overflowY = input.scrollHeight > 120 ? 'auto' : 'hidden';
    }

    function submitMessage(text) {
      text = String(text || '').trim();
      if (!text) return;
      history.push({ role: 'user', content: text });
      addMessage('user', text);
      input.value = '';
      resizeInput();
      setBusy(true);

      fetch(endpoint, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf },
        body: JSON.stringify({ _csrf: csrf, messages: history.slice(-10) })
      })
        .then(function (res) { return res.json().catch(function () { return { ok: false, error: 'Invalid server response.' }; }); })
        .then(function (data) {
          if (!data || !data.ok) {
            addMessage('error', (data && data.error) ? data.error : 'The assistant could not answer.');
            return;
          }
          history.push({ role: 'assistant', content: data.reply });
          addMessage('assistant', data.reply);
        })
        .catch(function () {
          addMessage('error', 'The assistant endpoint is unavailable.');
        })
        .finally(function () {
          setBusy(false);
          if (!panel.hidden) input.focus();
        });
    }

    toggle.addEventListener('click', function (event) {
      event.preventDefault();
      event.stopPropagation();
      panel.hidden ? openPanel() : closePanel();
    });

    panel.addEventListener('click', function (event) {
      event.stopPropagation();
    });

    document.addEventListener('click', function () {
      if (!panel.hidden) closePanel();
    });

    document.addEventListener('keydown', function (event) {
      if (event.key === 'Escape' && !panel.hidden) closePanel();
    });

    form.addEventListener('submit', function (event) {
      event.preventDefault();
      submitMessage(input.value);
    });

    input.addEventListener('keydown', function (event) {
      if (event.key === 'Enter' && !event.shiftKey) {
        event.preventDefault();
        form.requestSubmit();
      }
    });
    input.addEventListener('input', resizeInput);
    resizeInput();

    root.querySelectorAll('[data-nx-ai-prompt]').forEach(function (button) {
      button.addEventListener('click', function () {
        submitMessage(button.getAttribute('data-nx-ai-prompt') || '');
      });
    });
  })();
</script>
