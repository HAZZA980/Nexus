// Site-level JS hooks
(function () {
  function getPrimaryBlock(col) {
    return col.querySelector(
      ':scope > * > .nx-panel, :scope > * > .nx-card, :scope > * > .nx-examplecard, :scope > * > .nx-citation, :scope > * > .nx-herobanner'
    );
  }

  function clearEqualStyles(row) {
    const cols = row.querySelectorAll(':scope > .nx-col');
    cols.forEach((col) => {
      const inner = col.firstElementChild;
      if (inner) {
        inner.style.minHeight = '';
        inner.style.height = '';
      }
      const block = getPrimaryBlock(col);
      if (block) {
        block.style.minHeight = '';
        block.style.height = '';
      }
    });
  }

  function applyEqualHeightRows() {
    const rows = document.querySelectorAll('.nexus-page .nx-row');
    rows.forEach((row) => {
      clearEqualStyles(row);
      if (!row.classList.contains('nx-row--equal')) return;
      if (window.matchMedia('(max-width: 768px)').matches) return;

      const cols = Array.from(row.querySelectorAll(':scope > .nx-col'));
      const inners = cols.map((col) => col.firstElementChild).filter(Boolean);
      if (!inners.length) return;

      let maxHeight = 0;
      inners.forEach((inner) => {
        const h = Math.max(inner.getBoundingClientRect().height, inner.scrollHeight);
        if (h > maxHeight) maxHeight = h;
      });
      if (!maxHeight) return;

      const px = Math.ceil(maxHeight) + 'px';
      inners.forEach((inner) => {
        inner.style.minHeight = px;
        inner.style.height = '100%';
      });
      cols.forEach((col) => {
        const block = getPrimaryBlock(col);
        if (block) {
          block.style.minHeight = px;
          block.style.height = '100%';
        }
      });
    });
  }

  let raf = 0;
  function scheduleEqualRows() {
    if (raf) cancelAnimationFrame(raf);
    raf = requestAnimationFrame(applyEqualHeightRows);
  }

  document.addEventListener('DOMContentLoaded', scheduleEqualRows);
  window.addEventListener('resize', scheduleEqualRows);
  window.addEventListener('load', scheduleEqualRows);
  document.addEventListener(
    'load',
    function (e) {
      if (e && e.target && e.target.tagName === 'IMG') scheduleEqualRows();
    },
    true
  );
})();

(function () {
  function buildSuggestion(item) {
    const link = document.createElement('a');
    link.className = 'ctr-search-suggestion';
    link.href = item.url || '#';

    const title = document.createElement('span');
    title.className = 'ctr-search-suggestion-title';
    title.textContent = item.title || '';
    link.appendChild(title);

    const metaParts = [item.style, item.category].filter(Boolean);
    if (metaParts.length) {
      const meta = document.createElement('span');
      meta.className = 'ctr-search-suggestion-meta';
      meta.textContent = metaParts.join(' • ');
      link.appendChild(meta);
    }

    if (item.match_label) {
      const match = document.createElement('span');
      match.className = 'ctr-search-suggestion-match';
      match.textContent = 'Matched on: ' + item.match_label;
      link.appendChild(match);
    }

    if (item.snippet) {
      const snippet = document.createElement('span');
      snippet.className = 'ctr-search-suggestion-snippet';
      snippet.textContent = item.snippet;
      link.appendChild(snippet);
    }

    return link;
  }

  function initCtrAutocomplete() {
    const form = document.querySelector('.ctr-search-form[data-autocomplete-endpoint]');
    if (!form) return;

    const input = form.querySelector('.ctr-search-input[name="q"]');
    const panel = form.querySelector('.ctr-search-suggestions');
    const endpoint = form.getAttribute('data-autocomplete-endpoint') || '';
    if (!input || !panel || !endpoint) return;

    let aborter = null;
    let debounceId = 0;
    let requestId = 0;

    function closePanel() {
      panel.hidden = true;
      panel.innerHTML = '';
      input.setAttribute('aria-expanded', 'false');
    }

    function openPanel() {
      panel.hidden = false;
      input.setAttribute('aria-expanded', 'true');
    }

    function renderItems(items) {
      panel.innerHTML = '';
      if (!items.length) {
        const empty = document.createElement('div');
        empty.className = 'ctr-search-empty';
        empty.textContent = 'No matching citation pages found.';
        panel.appendChild(empty);
        openPanel();
        return;
      }

      items.forEach((item) => panel.appendChild(buildSuggestion(item)));
      openPanel();
    }

    function loadSuggestions(query) {
      const trimmed = query.trim();
      if (trimmed.length < 2) {
        closePanel();
        return;
      }

      if (aborter) aborter.abort();
      aborter = new AbortController();
      const currentRequest = ++requestId;

      fetch(endpoint + '?q=' + encodeURIComponent(trimmed) + '&limit=8', {
        headers: { Accept: 'application/json' },
        signal: aborter.signal,
      })
        .then((response) => (response.ok ? response.json() : Promise.reject(new Error('Request failed'))))
        .then((payload) => {
          if (currentRequest !== requestId) return;
          renderItems(Array.isArray(payload.items) ? payload.items : []);
        })
        .catch((error) => {
          if (error && error.name === 'AbortError') return;
          closePanel();
        });
    }

    input.addEventListener('input', function () {
      window.clearTimeout(debounceId);
      debounceId = window.setTimeout(function () {
        loadSuggestions(input.value);
      }, 140);
    });

    input.addEventListener('focus', function () {
      if (input.value.trim().length >= 2) loadSuggestions(input.value);
    });

    input.addEventListener('keydown', function (event) {
      if (event.key === 'Escape') closePanel();
    });

    document.addEventListener('click', function (event) {
      if (!form.contains(event.target)) closePanel();
    });
  }

  document.addEventListener('DOMContentLoaded', initCtrAutocomplete);
})();
