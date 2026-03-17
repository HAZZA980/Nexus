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
