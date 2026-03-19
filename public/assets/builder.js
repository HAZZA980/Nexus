(() => {
  const shell = document.querySelector('.nx-shell');
  const canvas = document.getElementById('canvas');
  const insp = document.getElementById('insp');
  const inspHint = document.getElementById('inspHint');

  const inspectorView = document.getElementById('inspectorView');
  const revisionsView = document.getElementById('revisionsView');
  const revList = document.getElementById('revList');
  const currentRevLine = document.getElementById('currentRevLine');
  const rightTitle = document.getElementById('rightTitle');

  const tUnlink = document.getElementById('t_unlink');

  const apiRevPreviewToken = shell.dataset.apiRevPreviewToken;

  const pageId = parseInt(shell?.dataset?.pageId || '0', 10);
  const csrf = shell?.dataset?.csrf || '';

  const apiSave = shell.dataset.apiSave;
  const apiPublish = shell.dataset.apiPublish;
  const apiUnpublish = shell.dataset.apiUnpublish;
  const apiPreviewToken = shell.dataset.apiPreviewToken;

  const apiRevCreate = shell.dataset.apiRevCreate;
  const apiRevList = shell.dataset.apiRevList;
  const apiRevDelete = shell.dataset.apiRevDelete;
  const apiRevRestore = shell.dataset.apiRevRestore;

  const previewUrlBase = shell.dataset.preview;

  const publishToggle = document.getElementById('publishToggle');
  const openRevisionsBtn = document.getElementById('openRevisions');
  const backToInspectorBtn = document.getElementById('backToInspector');
  const saveAsRevisionBtn = document.getElementById('saveAsRevision');
  const revModal = document.getElementById('revModal');
  const revSaveBtn = document.getElementById('revSave');
  const revCancelBtn = document.getElementById('revCancel');
  const revCloseBtn = document.getElementById('revModalClose');
  const revNameInput = document.getElementById('revName');
  const revNoteInput = document.getElementById('revNote');
  const revMilestoneInput = document.getElementById('revMilestone');
  const previewBtn = document.getElementById('preview');
  const saveStateEl = document.getElementById('saveState');
  const saveErrorEl = document.getElementById('saveError');
  const retrySaveBtn = document.getElementById('retrySave');
  const statusBadge = document.getElementById('statusBadge');
  const publishBtn = document.getElementById('publishBtn');
  const publishToggleBtn = document.getElementById('publishToggleBtn');
  const scheduleBtn = document.getElementById('publishSchedule');
  const openRevisionsTopBtn = document.getElementById('openRevisionsTop');
  const saveDd = document.getElementById('saveDD');
  const publishDd = document.getElementById('publishDD');
  const scheduleModal = document.getElementById('scheduleModal');
  const scheduleWhen = document.getElementById('scheduleWhen');
  const scheduleSave = document.getElementById('scheduleSave');
  const scheduleCancel = document.getElementById('scheduleCancel');
  const scheduleClose = document.getElementById('scheduleClose');

  const textToolbar = document.getElementById('textToolbar');
  const tLink = document.getElementById('t_link');
  const tFontFamily = document.getElementById('t_fontFamily');
  const tFontSize = document.getElementById('t_fontSize');

  // --- Determine routing style (for debug only)
  const routingMode = apiSave.includes('/index.php/') ? 'non-clean' : 'clean';

  let savedTextSelection = null;
  let isDirty = true;
  let pageStatus = (statusBadge?.textContent || 'draft').toLowerCase();

  const setSaveState = (state, message) => {
    if (saveStateEl) saveStateEl.textContent = message || (state === 'saved' ? 'All changes saved' : 'Unsaved changes');
    if (saveErrorEl) saveErrorEl.style.display = state === 'error' ? '' : 'none';
    const saveBtn = document.getElementById('save');
    if (saveBtn) saveBtn.disabled = (state !== 'dirty' && state !== 'error') || !isDirty;
  };

  const markDirty = () => {
    isDirty = true;
    setSaveState('dirty', 'Unsaved changes');
    updateUndoRedoButtons();
  };

  const markSaved = () => {
    isDirty = false;
    setSaveState('saved', 'All changes saved');
  };

  const setStatusBadge = (status) => {
    pageStatus = status;
    if (statusBadge) {
      statusBadge.className = `nx-status nx-status-${status}`;
      statusBadge.textContent = status === 'published' ? 'Published' : status === 'scheduled' ? 'Scheduled' : 'Draft';
    }
    if (publishBtn) publishBtn.textContent = status === 'published' ? 'Update' : 'Publish';
    if (publishToggleBtn) {
      const lbl = publishToggleBtn.querySelector('.nx-dd-label');
      const nextText = status === 'published' ? 'Unpublish' : 'Publish now';
      if (lbl) lbl.textContent = nextText;
      else publishToggleBtn.textContent = nextText;
    }
    if (scheduleBtn) {
      const lbl = scheduleBtn.querySelector('.nx-dd-label');
      if (lbl) lbl.textContent = 'Schedule publish…';
      else scheduleBtn.textContent = 'Schedule publish…';
    }
  };
  setStatusBadge(pageStatus);
  setSaveState('dirty', 'Unsaved changes');

  function normalizeDoc(doc) {
    doc = doc && typeof doc === "object" ? doc : {};
    doc.page = doc.page && typeof doc.page === "object" ? doc.page : {};

    doc.page.backgroundColor ??= "";
    doc.page.textColor ??= "";
    doc.page.fontFamily ??= "";
    doc.page.fontSize ??= "";
    doc.page.citationExampleId ??= "";

    doc.rows = Array.isArray(doc.rows) ? doc.rows : [];
    doc.rows.forEach((row) => {
      const cols = Array.isArray(row?.cols) ? row.cols : [];
      cols.forEach((col) => {
        const blocks = Array.isArray(col?.blocks) ? col.blocks : [];
        blocks.forEach((blk) => {
          if (!blk || typeof blk !== 'object') return;
          if (blk.type !== 'image') return;
          blk.props = blk.props && typeof blk.props === 'object' ? blk.props : {};
          blk.props.imageRatio = normalizeImageRatio(blk.props.imageRatio);
        });
      });
    });
    return doc;
  }

  const IMAGE_RATIO_OPTIONS = [
    { value: '16-9', label: 'Landscape (16:9)' },
    { value: '4-3', label: 'Standard (4:3)' },
    { value: '3-2', label: 'Photo (3:2)' },
    { value: '1-1', label: 'Square (1:1)' },
    { value: '9-16', label: 'Portrait (9:16)' },
  ];

  const normalizeImageRatio = (raw) => {
    const val = String(raw || '').trim().toLowerCase();
    return IMAGE_RATIO_OPTIONS.some(opt => opt.value === val) ? val : '16-9';
  };

  function applyPageStyles() {
    const el = document.querySelector(".nexus-page");
    if (!el) return;

    const p = doc.page || {};

    if (p.backgroundColor) el.style.setProperty("--nexus-page-bg", p.backgroundColor);
    else el.style.removeProperty("--nexus-page-bg");

    if (p.textColor) el.style.setProperty("--nexus-text", p.textColor);
    else el.style.removeProperty("--nexus-text");

    if (p.fontFamily) el.style.setProperty("--nexus-font", p.fontFamily);
    else el.style.removeProperty("--nexus-font");

    if (p.fontSize) el.style.setProperty("--nexus-font-size", p.fontSize + "px");
    else el.style.removeProperty("--nexus-font-size");
  }

  function saveTextSelection() {
    const sel = window.getSelection();
    if (sel && sel.rangeCount > 0) {
      savedTextSelection = sel.getRangeAt(0).cloneRange();
    }
  }

  function restoreTextSelection() {
    if (!savedTextSelection) return;
    const sel = window.getSelection();
    sel.removeAllRanges();
    sel.addRange(savedTextSelection);
  }

  // --- Load doc (prefer compatible unsaved local state)
  const storageKey = `nx_unsaved_doc_${pageId}`;
  const pageUpdatedAt = shell?.dataset?.pageUpdatedAt || '';
  const allowLegacyResume = new URLSearchParams(window.location.search).get('resume') === '1';
  let doc = (() => {
    try {
      const raw = sessionStorage.getItem(storageKey);
      if (raw) {
        const parsed = JSON.parse(raw);
        // New format: wrapped payload with metadata; only restore if source version matches.
        if (parsed && typeof parsed === 'object' && parsed.__meta && parsed.doc) {
          const savedUpdatedAt = String(parsed.__meta.pageUpdatedAt || '');
          if (!pageUpdatedAt || savedUpdatedAt === pageUpdatedAt) {
            return parsed.doc;
          }
        }
        // Legacy format fallback can be explicitly resumed via ?resume=1
        if (allowLegacyResume && parsed && typeof parsed === 'object' && Array.isArray(parsed.rows)) {
          return parsed;
        }
      }
    } catch {}
    return window.NX_DOC || { version: 1, rows: [] };
  })();

  // ✅ normalize doc shape once
  doc = normalizeDoc(doc);

  function persistUnsaved() {
    try {
      sessionStorage.setItem(storageKey, JSON.stringify({
        __meta: { pageUpdatedAt },
        doc
      }));
    } catch {}
    markDirty();
  }

  const history = [];
  const future = [];
  const updateUndoRedoButtons = () => {
    const undoBtn = document.getElementById('undo');
    const redoBtn = document.getElementById('redo');
    if (undoBtn) undoBtn.disabled = history.length <= 1; // need at least baseline + one entry
    if (redoBtn) redoBtn.disabled = future.length === 0;
  };
  const pushInitialHistory = () => {
    history.length = 0;
    future.length = 0;
    history.push(JSON.stringify(doc)); // baseline
    updateUndoRedoButtons();
  };
  const pushHistory = () => {
    history.push(JSON.stringify(doc));
    if (history.length > 80) history.shift();
    future.length = 0;
    updateUndoRedoButtons();
  };
  pushInitialHistory();
  updateUndoRedoButtons();

  const siteSlug = (shell?.dataset?.siteSlug || '').toLowerCase();
  const collectionStyle = (shell?.dataset?.collectionStyle || '').trim();
  const CITATION_URL = `${shell.dataset.base}/api/citation/examples?site_slug=${encodeURIComponent(siteSlug)}${collectionStyle ? `&referencing_style=${encodeURIComponent(collectionStyle)}` : ''}`;
  const CITATION_FALLBACK = [
    {
      id: 'book_one_author',
      label: 'Book with one author',
      heading: 'Example: book with one author',
      body: 'In-text citations\\n\\nThe overview by McCormick (2023) confirms Hill’s experience (2023, pp. 46–52).\\n\\nNB: No page number citation for McCormick because the reference is to the whole book.\\n\\nSpecific pages are being cited in Hill’s book.\\n\\nReference list\\n\\nHill, F. (2023) There’s nothing for you here: finding opportunity in the twenty-first century. Mariner Books.\\n\\nMcCormick, J.M. (2023) American foreign policy and process. 7th edn. Cambridge University Press.',
      youTry: 'Surname, Initial. (Year of publication) Title. Edition. Publisher.',
      citationOrder: 'Author/editor\\nYear of publication (in round brackets)\\nTitle (in italics)\\nEdition (if not the first)\\nPublisher\\nSeries and volume number (where relevant)'
    }
  ];
  let citationExamplesCache = null;

  async function loadCitationExamples() {
    if (citationExamplesCache) return citationExamplesCache;
    if (siteSlug === 'cite-them-right') {
      try {
        const res = await fetch(CITATION_URL, { credentials: 'same-origin' });
        if (!res.ok) throw new Error('HTTP ' + res.status);
        const data = await res.json();
        if (data && data.ok && Array.isArray(data.examples) && data.examples.length) {
          citationExamplesCache = data.examples;
          return citationExamplesCache;
        }
      } catch (e) {}
    } else {
      try {
        const res = await fetch(`${shell.dataset.base}/public/assets/harvard_examples.json`, { credentials: 'same-origin' });
        if (res.ok) {
          const data = await res.json();
          if (Array.isArray(data) && data.length) {
            citationExamplesCache = data;
            return citationExamplesCache;
          }
        }
      } catch (e) {}
    }
    citationExamplesCache = CITATION_FALLBACK;
    return CITATION_FALLBACK;
  }

  const uuid = () => 'b_' + Math.random().toString(16).slice(2) + Date.now().toString(16);
  const esc = (s) => String(s).replace(/[&<>"']/g, c => ({
    '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'
  }[c]));
  const trunc = (s, n = 10) => {
    s = String(s || '');
    if (s.length <= n) return s;
    return s.slice(0, n) + '…';
  };
  const formatCitationOrder = (val) => {
    return (val || '')
      .split('\n')
      .map(line => line.replace(/\s+$/, '')) // keep leading markers, trim right
      .filter(line => line.trim() !== '')
      .join('\n');
  };
  const setPageCitationExample = (id) => {
    doc.page = doc.page || {};
    doc.page.citationExampleId = id || '';
  };
  const getPageCitationExample = () => {
    return (doc.page && doc.page.citationExampleId) ? doc.page.citationExampleId : '';
  };
  const applyExampleData = (blk, ex) => {
    if (!ex) return;
    blk.props = blk.props || {};
    blk.props.exampleId = ex.id;
    blk.props.heading = ex.heading || '';
    blk.props.body = ex.body || ex.bodyHtml || '';
    blk.props.bodyHtml = ex.bodyHtml || ex.body || '';
    blk.props.youTry = ex.youTry || ex.youTryHtml || '';
  };
  const applyCitationOrderToBlock = (blk, ex) => {
    if (!ex) return;
    const formatted = formatCitationOrder(ex.citationOrder || ex.citationOrderHtml || ex.body || '');
    blk.props = blk.props || {};
    blk.props.exampleId = ex.id;
    blk.props.body = formatted;
    blk.props.html = formatted.replace(/\n/g, '<br>');
  };
  const applyYouTryToBlock = (blk, ex) => {
    if (!ex) return;
    const youTryText = ex.youTry || ex.youTryHtml || '';
    blk.props = blk.props || {};
    blk.props.exampleId = ex.id;
    blk.props.body = youTryText;
    blk.props.html = youTryText.replace(/\n/g, '<br>');
  };
  const ensureDefaultCitationExample = (examples) => {
    if (!examples || !examples.length) return;
    const current = getPageCitationExample();
    const firstId = examples[0].id;
    if (!current && firstId) {
      propagateCitationExample(firstId, examples, { onlyUnset: true });
    }
  };
  const propagateCitationExample = (selId, examples, opts = {}) => {
    if (!selId) return;
    setPageCitationExample(selId);
    const skipId = opts.skipId;
    const onlyUnset = opts.onlyUnset === true;
    const ex = (examples || []).find(x => x.id === selId) || (examples || [])[0];
    if (!ex) return;
    const applyToDocBlocks = (blocks) => {
      blocks.forEach((b) => {
        if (!b || typeof b !== 'object') return;
        const pid = b.id;
        const p = b.props || {};
        const already = !!p.exampleId;
        if (skipId && pid === skipId) return;
        if (onlyUnset && already) return;
        if (b.type === 'exampleCard') applyExampleData(b, ex);
        if (b.type === 'citationOrder') applyCitationOrderToBlock(b, ex);
        if (b.type === 'youTry') applyYouTryToBlock(b, ex);
      });
    };
    (doc.rows || []).forEach(row => {
      if (!row || !Array.isArray(row.cols)) return;
      row.cols.forEach(col => applyToDocBlocks(col.blocks || []));
    });
  };

  const defBlock = (type) => {
    switch (type) {
      case 'heading': return { id: uuid(), type: 'heading', props: { level: 2, text: 'Heading' }, styleText:{} };
      case 'text':    return { id: uuid(), type: 'text', props: { text: 'Text…', bgColor: '' }, styleText:{} };
      case 'image':   return { id: uuid(), type: 'image', props: { src: '', alt: '', imageRatio: '16-9' } };
      case 'video':   return { id: uuid(), type: 'video', props: { url: '' } };
      case 'card':    return { id: uuid(), type: 'card', props: { title: 'Card title', body: 'Card body…' }, styleText:{} };
      case 'youTry':  return { id: uuid(), type: 'youTry', props: { title: 'You try', body: 'Try it yourself…' }, styleText:{} };
      case 'textbox': return { id: uuid(), type: 'textbox', props: { label: 'Textbox', placeholder: 'Type here…', lines: 3, bgColor: '' } };
      case 'citationOrder': return { id: uuid(), type: 'citationOrder', props: { title: 'Citation order', body: '• Author/editor\n• Year of publication (in round brackets)\n• Title (in italics)\n• Edition (edition number if not the first edn and/or rev. edn)\n• Publisher\n• Series and volume number (where relevant)' }, styleText:{} };
      case 'exampleCard': return { id: uuid(), type: 'exampleCard', props: { exampleId: 'book_one_author', heading: 'Example: book with one author', body: 'In-text citations\n\nThe overview by McCormick (2023) confirms Hill’s experience (2023, pp. 46–52).\n\nNB: No page number citation for McCormick because the reference is to the whole book.\n\nSpecific pages are being cited in Hill’s book.\n\nReference list\n\nHill, F. (2023) There’s nothing for you here: finding opportunity in the twenty-first century. Mariner Books.\n\nMcCormick, J.M. (2023) American foreign policy and process. 7th edn. Cambridge University Press.', youTry: 'Surname, Initial. (Year of publication) Title. Edition. Publisher.', showYouTry: true }, styleText:{} };
      case 'panel': return { id: uuid(), type: 'panel', props: { image: '', alt: '', body: 'Panel text…', layout: 'img-top', splitRatio: '50-50' }, styleText:{} };
      case 'testimonial': return { id: uuid(), type: 'testimonial', props: { body: 'I am meeting my deadlines consistently!' }, styleText:{} };
      case 'download': return { id: uuid(), type: 'download', props: { label: 'Download', url: '' }, styleText:{} };
      case 'heroBanner': return { id: uuid(), type: 'heroBanner', props: { heading: 'Welcome to Cite Them Right', cta: 'Choose your referencing style', ctaHtml: '', bgImage: '', overlayOpacity: 0.6 }, styleText:{} };
      case 'table': {
        return {
          id: uuid(),
          type: 'table',
          props: {
            data: [
              ['Header 1','Header 2','Header 3'],
              ['Row 1 col 1','Row 1 col 2','Row 1 col 3']
            ],
            headerRow: true,
            headerCol: false,
            density: 'default',
            rowStyle: 'none',
            gridLines: 'subtle',
            textAlign: 'left',
            vAlign: 'middle',
            colResize: true
          }
        };
      }
      case 'heroCard': return { id: uuid(), type: 'heroCard', props: { title: 'Hero Title', body: 'Hero text', bgImage: '', bgColor: '#111827', overlayOpacity: 0.35 }, styleText:{} };
      case 'dragWords': return { id: uuid(), type: 'dragWords', props: {
        instruction: 'Drag or click the correct words into the blanks',
        sentence: 'The {1} sat on the {2}.',
        answers: ['cat', 'mat'],
        pool: ['dog', 'mat', 'cat', 'floor'],
        feedbackCorrect: '✅ Well done! All answers are correct.',
        feedbackPartial: 'Keep trying — some answers are not correct yet.',
        checkLabel: 'Check answers',
        resetLabel: 'Reset'
      }};
      case 'multipleChoiceQuiz': return { id: uuid(), type: 'multipleChoiceQuiz', props: {
        questions: [
          {
            id: uuid(),
            questionHtml: '',
            questionText: '',
            showImage: false,
            image: '',
            imageAlt: '',
            options: [
              { id: uuid(), label: '', correct: false },
              { id: uuid(), label: '', correct: false }
            ],
            correctOptionId: null
          }
        ],
        shuffle: false,
        maxAttempts: 0, // 0 = unlimited
        showFeedback: true,
        feedbackTiming: 'submit', // submit | onSelect
        requireAnswer: true,
        showReset: true,
        allowResetOnCorrect: false,
        showExplanationAfter: 'submit', // submit | incorrectOnly
        correctMsg: 'Correct',
        incorrectMsg: 'Try again',
        explanationHtml: '',
        explanationText: '',
        styleVariant: 'default',
        showBorder: true,
        spacing: 'default',
        accentColor: '',
        correctColor: '',
        incorrectColor: ''
      }, styleText:{} };
      case 'flipCard': return { id: uuid(), type: 'flipCard', props: {
        frontTitle: 'Dialogue card — front',
        frontBody: 'Front content goes here. You can add text or an image.',
        frontImage: '',
        backTitle: 'Back of card',
        backBody: 'Back content goes here.',
        backImage: '',
        turnLabel: 'Turn',
        turnBackLabel: 'Turn back'
      }, styleText:{} };
      case 'trueFalse': return { id: uuid(), type: 'trueFalse', props: {
        question: 'Is this statement true?',
        trueLabel: 'True',
        falseLabel: 'False',
        correct: 'true',
        feedbackCorrect: 'Correct!',
        feedbackWrong: 'Not quite — try again.'
      }, styleText:{} };
      case 'accordion':
      case 'accordionTabs': return { id: uuid(), type: 'accordionTabs', props: {
        mode: 'accordion', // accordion | tabs
        allowMultiple: false,
        allowCollapseAll: true,
        defaultOpen: 'none', // none | first | custom
        defaultIndex: 0,
        tabsDefault: 'first', // first | custom
        tabsIndex: 0,
        tabsAlign: 'left', // left | center | right
        tabsStyle: 'underline', // underline | pills | segmented
        styleVariant: 'default',
        showDividers: true,
        showIndicator: true,
        indicatorPosition: 'right',
        spacing: 'default',
        headerBg: '',
        headerColor: '',
        activeHeaderBg: '',
        activeHeaderColor: '',
        contentBg: '',
        borderColor: '',
        showBorder: true,
        headerImgPos: 'left',
        headerImgSize: 'large',
        items: [
          { id: uuid(), title: 'Accordion item', body: '', bodyHtml: '', openDefault: false, headerImg:'', headerAlt:'', showHeaderImg:false }
        ]
      }};
      case 'carousel': return { id: uuid(), type: 'carousel', props: {
        slidesToShow: 1,
        size: 'large',
        autoplay: false,
        interval: 5,
        pauseOnHover: true,
        background: 'linear-gradient(135deg,#fdfaf3,#fffefb)',
        slides: [
          { title: 'Welcome to Skills for Study', body: 'Your hub for productivity, focus and effective study habits.', image: '', layout: 'text-left' },
          { title: 'Stay on track', body: 'Set gentle reminders and pace your study sessions.', image: '', layout: 'text-right' }
        ]
      }};
      case 'divider': return { id: uuid(), type: 'divider', props: {} };
      default:        return { id: uuid(), type: 'text', props: { text: 'Text…' }, styleText:{} };
    }
  };

  const ACCORDION_TABS_MAX = 4;
  const accordionInspectorState = {};
  const accordionPreviewState = {};

  function ensureAccordionTabsProps(blk) {
    blk.props = blk.props || {};
    const p = blk.props;
    p.mode = p.mode === 'tabs' ? 'tabs' : 'accordion';
    const normalizeItem = (it, i) => {
      const item = it && typeof it === 'object' ? it : {};
      if (!item.id) item.id = uuid();
      if (!item.title) item.title = `Item ${i + 1}`;
      item.body = item.body || '';
      item.bodyHtml = item.bodyHtml || '';
      item.openDefault = !!item.openDefault;
      item.headerImg = item.headerImg || '';
      item.headerAlt = item.headerAlt || '';
      item.showHeaderImg = !!item.showHeaderImg;
      return item;
    };
    if (!Array.isArray(p.items) || !p.items.length) {
      p.items = [normalizeItem({ title: 'Item 1' }, 0)];
    } else {
      p.items = p.items.map((it, i) => normalizeItem(it, i));
    }
    p.allowMultiple = !!p.allowMultiple;
    p.allowCollapseAll = p.allowCollapseAll !== false;
    p.defaultOpen = ['none','first','custom'].includes(p.defaultOpen) ? p.defaultOpen : 'none';
    const cappedDefault = Number.isInteger(p.defaultIndex) ? Math.max(0, p.defaultIndex) : 0;
    p.defaultIndex = Math.min(cappedDefault, Math.max(0, p.items.length - 1));
    p.tabsDefault = ['first','custom'].includes(p.tabsDefault) ? p.tabsDefault : 'first';
    p.tabsIndex = Math.min(Math.max(0, Number.isInteger(p.tabsIndex) ? p.tabsIndex : 0), Math.max(0, p.items.length - 1));
    if (p.mode === 'tabs') p.tabsIndex = Math.min(p.tabsIndex, ACCORDION_TABS_MAX - 1);
    p.tabsAlign = ['left','center','right'].includes(p.tabsAlign) ? p.tabsAlign : 'left';
    p.tabsStyle = ['underline','pills','segmented'].includes(p.tabsStyle) ? p.tabsStyle : 'underline';
    p.styleVariant = ['default','minimal','bordered'].includes(p.styleVariant) ? p.styleVariant : 'default';
    p.showDividers = p.showDividers !== false;
    p.showIndicator = p.showIndicator !== false;
    p.indicatorPosition = ['left','right'].includes(p.indicatorPosition) ? p.indicatorPosition : 'right';
    p.spacing = ['compact','default','spacious'].includes(p.spacing) ? p.spacing : 'default';
    p.headerBg = p.headerBg || '';
    p.headerColor = p.headerColor || '';
    p.activeHeaderBg = p.activeHeaderBg || '';
    p.activeHeaderColor = p.activeHeaderColor || '';
    p.contentBg = p.contentBg || '';
    p.borderColor = p.borderColor || '';
    p.showBorder = p.showBorder !== false;
    p.headerImgPos = ['left','right'].includes(p.headerImgPos) ? p.headerImgPos : 'left';
    p.headerImgSize = ['small','medium','large'].includes(p.headerImgSize) ? p.headerImgSize : 'medium';
    return p;
  }
  // Back-compat alias
  const ensureAccordionProps = ensureAccordionTabsProps;

  const getAccordionFocus = (id, max) => {
    const idx = accordionInspectorState[id] ?? 0;
    if (!max || max <= 0) return 0;
    return Math.min(Math.max(0, idx), max - 1);
  };
  const setAccordionFocus = (id, idx) => { accordionInspectorState[id] = idx; };

  const captureAccordionPreviewState = (blk) => {
    if (!blk?.id) return null;
    const id = blk.id;
    const accEl = canvas.querySelector(`.nx-accordion[data-bid="${CSS.escape(id)}"]`);
    const tabEl = canvas.querySelector(`.nx-tabs[data-bid="${CSS.escape(id)}"]`);
    if (accEl) {
      const opens = [];
      accEl.querySelectorAll('.nx-accordion-item').forEach((it, i) => {
        if (it.classList.contains('is-open')) opens.push(i);
      });
      accordionPreviewState[id] = { mode: 'accordion', opens };
      return accordionPreviewState[id];
    }
    if (tabEl) {
      const tabs = Array.from(tabEl.querySelectorAll('.nx-tab'));
      const active = Math.max(0, tabs.findIndex(t => t.classList.contains('active')));
      accordionPreviewState[id] = { mode: 'tabs', active };
      return accordionPreviewState[id];
    }
    return null;
  };

  const restoreAccordionPreviewState = (blk, state) => {
    if (!blk?.id) return;
    const accProps = ensureAccordionTabsProps(blk);
    const renderLen = accProps.mode === 'tabs'
      ? Math.min(accProps.items.length, ACCORDION_TABS_MAX)
      : accProps.items.length;
    const desiredIdx = getAccordionFocus(blk.id, renderLen);
    const desired = Number.isInteger(desiredIdx) ? desiredIdx : 0;

    if (state?.mode === 'accordion') {
      const accEl = canvas.querySelector(`.nx-accordion[data-bid="${CSS.escape(blk.id)}"]`);
      if (accEl) {
        const items = accEl.querySelectorAll('.nx-accordion-item');
        const openSet = new Set(state.opens || []);
        items.forEach((item, i) => {
          const open = openSet.has(i);
          const btn = item.querySelector('.nx-accordion-head');
          const panel = item.querySelector('.nx-accordion-panel');
          item.classList.toggle('is-open', open);
          if (btn) btn.setAttribute('aria-expanded', open ? 'true' : 'false');
          if (panel) {
            panel.hidden = !open;
            panel.style.maxHeight = open ? 'none' : '0px';
          }
        });
        return;
      }
    }

    if (state?.mode === 'tabs' || accProps.mode === 'tabs') {
      const tabEl = canvas.querySelector(`.nx-tabs[data-bid="${CSS.escape(blk.id)}"]`);
      if (!tabEl) return;
      const tabs = tabEl.querySelectorAll('.nx-tab');
      const panels = tabEl.querySelectorAll('.nx-tab-panel');
      const idx = Math.min(Math.max(0, desired), tabs.length - 1);
      tabs.forEach((t, i) => {
        const on = i === idx;
        t.classList.toggle('active', on);
        t.setAttribute('aria-selected', on ? 'true' : 'false');
      });
      panels.forEach((p, i) => {
        const on = i === idx;
        p.classList.toggle('active', on);
        if (on) p.removeAttribute('hidden'); else p.setAttribute('hidden', '');
      });
    }
  };

  // Text color presets (toolbar)
  const NX_COLOR_PRESETS = [
    { name: 'Black',   val: '#000000' },
    { name: 'Gray',    val: '#6b7280' },
    { name: 'White',   val: '#ffffff' },
    { name: 'Off white', val: '#f5f7fb' },
    { name: 'Red',     val: '#ef4444' },
    { name: 'Orange',  val: '#f97316' },
    { name: 'Yellow',  val: '#eab308' },
    { name: 'Green',   val: '#22c55e' },
    { name: 'Teal',    val: '#14b8a6' },
    { name: 'Cyan',    val: '#06b6d4' },
    { name: 'Blue',    val: '#3b82f6' },
    { name: 'Indigo',  val: '#6366f1' },
    { name: 'Purple',  val: '#a855f7' },
    { name: 'Pink',    val: '#ec4899' },
    { name: 'Navy',    val: '#1e3a8a' },
    { name: 'Brown',   val: '#7c2d12' },
    { name: 'Maroon',  val: '#7f1d1d' },
  ];

  // Row background presets (distinct from text presets)
  const NX_ROW_BG_PRESETS = [
    { name: 'Panel (default)', val: '' }, // empty = CSS default
    { name: 'White',          val: '#ffffff' },
    { name: 'Warm off-white', val: '#faf7f0' },
    { name: 'Cool off-white', val: '#f5f7fb' },
    { name: 'Sand',           val: '#f3efe6' },
    { name: 'Mist',           val: '#eef2f7' },
    { name: 'Mint',           val: '#eefaf3' },
    { name: 'Blush',          val: '#fff1f2' },
  ];

  function renderColorPalette(id, selected) {
    const sel = (selected || '#111827').toLowerCase();
    const hasPreset = NX_COLOR_PRESETS.some(c => c.val.toLowerCase() === sel);
    return `
      <div class="nx-color-shell" id="${id}">
        <button type="button"
          class="nx-swatch nx-swatch-main"
          data-trigger="color-toggle"
          style="background:${sel}"
          aria-haspopup="true"
          aria-expanded="false"
          aria-label="Text color chooser">
        </button>
        <div class="nx-palette nx-palette-flyout" role="listbox" aria-label="Color palette">
          ${NX_COLOR_PRESETS.map(c => {
            const isOn = c.val.toLowerCase() === sel;
            return `
              <button type="button"
                class="nx-swatch ${isOn ? 'active' : ''}"
                data-color="${c.val}"
                style="background:${c.val}"
                title="${esc(c.name)}"
                aria-label="${esc(c.name)}"
                aria-selected="${isOn ? 'true' : 'false'}">
              </button>
            `;
          }).join('')}
          <button type="button"
            class="nx-swatch nx-swatch-custom ${hasPreset ? '' : 'active'}"
            data-color-custom="1"
            style="${hasPreset ? '' : `background:${sel}`}"
            title="Custom color"
            aria-label="Custom color"
            aria-selected="${hasPreset ? 'false' : 'true'}">+</button>
          <input type="color" class="nx-color-input" value="${sel}" aria-label="Custom hex color">
        </div>
      </div>
    `;
  }

  function renderRowBgPalette(id, selected) {
    const sel = (selected || '').toLowerCase();
    return `
      <div class="nx-palette" id="${id}" role="listbox" aria-label="Row background palette">
        ${NX_ROW_BG_PRESETS.map(c => {
          const isOn = (c.val || '').toLowerCase() === sel;
          const swatchBg = c.val || 'linear-gradient(135deg,#fff,#f3f4f6)';
          return `
            <button type="button"
              class="nx-swatch ${isOn ? 'active' : ''}"
              data-color="${c.val}"
              style="background:${swatchBg}"
              title="${esc(c.name)}"
              aria-label="${esc(c.name)}"
              aria-selected="${isOn ? 'true' : 'false'}">
            </button>
          `;
        }).join('')}
      </div>
    `;
  }

  function bindColorPalette(id, onPick) {
    const root = document.getElementById(id);
    if (!root) return;
    const flyout = root.querySelector('.nx-palette-flyout');
    const toggle = root.querySelector('[data-trigger="color-toggle"]');
    const customBtn = root.querySelector('[data-color-custom="1"]');
    const customInput = root.querySelector('.nx-color-input');

    const closeFlyout = () => {
      if (flyout) flyout.style.display = 'none';
      if (toggle) toggle.setAttribute('aria-expanded', 'false');
    };

    toggle?.addEventListener('click', (e) => {
      e.preventDefault();
      if (!flyout) return;
      const isOpen = flyout.style.display === 'grid';
      flyout.style.display = isOpen ? 'none' : 'grid';
      toggle.setAttribute('aria-expanded', isOpen ? 'false' : 'true');
    });

    root.addEventListener('click', (e) => {
      const btn = e.target.closest('button[data-color]');
      if (!btn) return;
      const color = btn.dataset.color || '';
      onPick(color);
      root.querySelectorAll('.nx-swatch[data-color]').forEach(s => s.classList.remove('active'));
      btn.classList.add('active');
      if (toggle) toggle.style.background = color || '#111827';
      closeFlyout();
    });

    customBtn?.addEventListener('click', (e) => {
      e.preventDefault();
      e.stopPropagation();
      customInput?.click();
    });

    customInput?.addEventListener('input', () => {
      const color = customInput.value || '#111827';
      onPick(color);
      root.querySelectorAll('.nx-swatch[data-color], .nx-swatch[data-color-custom]').forEach(s => s.classList.remove('active'));
      customBtn?.classList.add('active');
      if (customBtn) customBtn.style.background = color;
      if (toggle) toggle.style.background = color;
    });

    customInput?.addEventListener('change', () => {
      closeFlyout();
    });

    document.addEventListener('click', (e) => {
      if (!root.contains(e.target)) closeFlyout();
    });
  }

  let selected = null;     // {r,c,b}
  let selectedRow = null;  // number | null

  const ensureRow = (row) => {
    if (!row.cols || row.cols.length === 0) row.cols = [{ span: 12, blocks: [] }];
    row.cols.forEach(c => {
      if (!c.span) c.span = 12;
      if (!c.blocks) c.blocks = [];
    });
  };

  function getSelectedBlock() {
    if (!selected) return null;
    return doc.rows?.[selected.r]?.cols?.[selected.c]?.blocks?.[selected.b] || null;
  }

  function getSelectedRow() {
    if (selectedRow == null) return null;
    return doc.rows?.[selectedRow] || null;
  }

  function ensureRowStyle(row) {
    row.styleRow = row.styleRow && typeof row.styleRow === 'object' ? row.styleRow : {};
    if (row.styleRow.bgEnabled == null) row.styleRow.bgEnabled = true;
    if (row.styleRow.bgColor == null) row.styleRow.bgColor = '';
    return row.styleRow;
  }

  function ensureRowMeta(row) {
    if (row.collapsed == null) row.collapsed = false;
    if (row.equalHeight == null) row.equalHeight = true;
    return row;
  }

  function isTextLike(blk) {
    return blk && (
      blk.type === 'heading' ||
      blk.type === 'text' ||
      blk.type === 'card' ||
      blk.type === 'youTry' ||
      blk.type === 'citationOrder' ||
      blk.type === 'exampleCard' ||
      blk.type === 'panel' ||
      blk.type === 'testimonial' ||
      blk.type === 'download' ||
      blk.type === 'heroCard' ||
      blk.type === 'heroPage' // legacy docs
      || blk.type === 'carousel'
      || blk.type === 'accordionTabs'
      || blk.type === 'accordion'
      || blk.type === 'multipleChoiceQuiz'
    );
  }

  function ensureTextStyle(blk) {
    blk.styleText = blk.styleText || {};
    return blk.styleText;
  }

  function ensureBlockStyle(blk) {
    blk.style = blk.style || {};
    return blk.style;
  }

  function ensureMCQProps(blk) {
    blk.props = blk.props || {};
    const p = blk.props;
    const normalizeQ = (q) => {
      const qs = q && typeof q === 'object' ? q : {};
      qs.id = qs.id || uuid();
      qs.questionHtml = qs.questionHtml || '';
      qs.questionText = qs.questionText || '';
      qs.showImage = !!qs.showImage;
      qs.image = qs.image || '';
      qs.imageAlt = qs.imageAlt || '';
      if (!Array.isArray(qs.options) || qs.options.length < 2) {
        qs.options = [
          { id: uuid(), label: '', correct: false },
          { id: uuid(), label: '', correct: false }
        ];
      } else {
        qs.options = qs.options.map(opt => ({
          id: opt?.id || uuid(),
          label: opt?.label || '',
          correct: !!opt?.correct
        }));
      }
      // enforce single correct
      const cIdx = qs.options.findIndex(o => o.correct);
      if (cIdx === -1) {
        qs.correctOptionId = qs.correctOptionId || null;
      } else {
        qs.correctOptionId = qs.options[cIdx].id;
      }
      qs.options = qs.options.map(o => ({ ...o, correct: o.id === qs.correctOptionId }));
      return qs;
    };
    if (!Array.isArray(p.questions) || !p.questions.length) {
      p.questions = [normalizeQ({})];
    } else {
      p.questions = p.questions.map(normalizeQ);
    }
    p.shuffle = !!p.shuffle;
    p.maxAttempts = Math.max(0, parseInt(p.maxAttempts ?? 0, 10) || 0);
    p.showFeedback = p.showFeedback !== false;
    p.feedbackTiming = ['submit','onSelect'].includes(p.feedbackTiming) ? p.feedbackTiming : 'submit';
    p.requireAnswer = p.requireAnswer !== false;
    p.showReset = p.showReset !== false;
    p.allowResetOnCorrect = !!p.allowResetOnCorrect;
    p.showExplanationAfter = ['submit','incorrectOnly'].includes(p.showExplanationAfter) ? p.showExplanationAfter : 'submit';
    p.correctMsg = p.correctMsg || 'Correct';
    p.incorrectMsg = p.incorrectMsg || 'Try again';
    p.explanationHtml = p.explanationHtml || '';
    p.explanationText = p.explanationText || '';
    p.styleVariant = ['default','minimal','bordered'].includes(p.styleVariant) ? p.styleVariant : 'default';
    p.showBorder = p.showBorder !== false;
    p.spacing = ['compact','default','spacious'].includes(p.spacing) ? p.spacing : 'default';
    p.accentColor = p.accentColor || '';
    p.correctColor = p.correctColor || '';
    p.incorrectColor = p.incorrectColor || '';
    return p;
  }

  function styleObjToCss(s) {
    const css = [];
    for (const [k,v] of Object.entries(s || {})) {
      if (v === '' || v == null) continue;
      const prop = k.replace(/[A-Z]/g, m => '-' + m.toLowerCase());
      css.push(`${prop}:${v}`);
    }
    return css.join(';');
  }

  function inlineTextCSS(blk) {
    if (!isTextLike(blk)) return '';
    const st = ensureTextStyle(blk);
    const css = [];
    if (st.fontFamily) css.push(`font-family:${st.fontFamily}`);
    if (st.fontSize) css.push(`font-size:${parseInt(st.fontSize,10)}px`);
    if (st.color) css.push(`color:${st.color}`);
    if (st.bold) css.push(`font-weight:800`);
    if (st.italic) css.push(`font-style:italic`);
    if (st.underline) css.push(`text-decoration:underline`);
    if (st.align) css.push(`text-align:${st.align}`);
    css.push('line-height:1.2');
    return css.join(';');
  }

  function blockPreviewHTML(blk) {
  const p = blk.props || {};
  const style = inlineTextCSS(blk);

  const wrap = (innerHtml) => style
    ? `<div style="${style}">${innerHtml}</div>`
    : `<div>${innerHtml}</div>`;

  const hasHtml = (v) => typeof v === 'string' && /<[^>]+>/.test(v);
  const formatMarked = (str) => {
    if (!str) return '';
    const escaped = String(str)
      .replace(/&/g,'&amp;')
      .replace(/</g,'&lt;')
      .replace(/>/g,'&gt;')
      .replace(/"/g,'&quot;')
      .replace(/'/g,'&#39;');
    // Apply bold first so double-asterisk sequences are not captured by the italic rule
    const withBold = escaped.replace(/\*\*(.+?)\*\*/g,'<strong>$1</strong>');
    // Italics: single asterisks not part of a double-asterisk pair
    const withItalics = withBold.replace(/(?<!\*)\*(?!\*)(.+?)(?<!\*)\*(?!\*)/g,'<em>$1</em>');
    return withItalics.replace(/\r?\n/g,'<br>');
  };

  if (blk.type === 'heading') {
    const inner = hasHtml(p.html)
      ? p.html
      : formatMarked(p.text || 'Heading');
    const level = Math.min(6, Math.max(1, parseInt(p.level || 2, 10)));
    return wrap(`<h${level} style="margin:0">${inner}</h${level}>`);
  }

  if (blk.type === 'text') {
    const inner = hasHtml(p.html)
      ? p.html
      : formatMarked((p.text || '').slice(0, 220));
    const bg = p.bgColor ? `background:${esc(p.bgColor)};` : '';
    return wrap(`<div style="padding:10px;border:1px dashed rgba(17,24,39,.18);border-radius:10px;${bg}">${inner}</div>`);
  }

  if (blk.type === 'card') {
    const title = esc(p.title || 'Card title');
    const body = hasHtml(p.html)
      ? p.html
      : formatMarked((p.body || '').slice(0, 180));
    return wrap(`<div><b>${title}</b><div style="margin-top:6px">${body}</div></div>`);
  }

  if (blk.type === 'youTry') {
    const title = esc(p.title || 'You try');
    const body = hasHtml(p.html)
      ? p.html
      : formatMarked((p.body || '').slice(0, 180));
    return wrap(`<div><b>${title}</b><div style="margin-top:6px">${body}</div></div>`);
  }

  if (blk.type === 'panel') {
    const body = hasHtml(p.bodyHtml)
      ? p.bodyHtml
      : formatMarked((p.body || '').slice(0, 260));
    const img = p.image ? `<div class="panel-img" style="background:url('${esc(p.image)}') center/cover no-repeat;"></div>` : `<div class="panel-img panel-img--placeholder">Add image</div>`;
    const layout = p.layout || 'img-top';
    const horizontal = layout === 'img-left' || layout === 'img-right';
    const split = p.splitRatio || '50-50';
    const colsMap = {
      '50-50': '1fr 1fr',
      '60-40': '3fr 2fr',
      '40-60': '2fr 3fr',
      '70-30': '7fr 3fr',
      '30-70': '3fr 7fr'
    };
    const cols = colsMap[split] || '1fr 1fr';
    const order = (wantFirst) => {
      if (horizontal) return wantFirst ? (layout === 'img-left' ? img : body) : (layout === 'img-left' ? body : img);
      return wantFirst ? img : body; // stacked
    };
    return `
      <div class="nx-panel-preview ${horizontal ? 'panel-horizontal' : 'panel-stacked'}" data-layout="${layout}" style="${horizontal ? `grid-template-columns:${cols}` : ''}">
        ${order(true)}
        ${horizontal ? order(false) : `<div class="panel-body" style="text-align:center">${body}</div>`}
      </div>
    `;
  }

  if (blk.type === 'testimonial') {
    const body = hasHtml(p.bodyHtml)
      ? p.bodyHtml
      : formatMarked((p.body || 'I am meeting my deadlines consistently!').slice(0, 200));
    return `
      <div class="nx-testimonial-preview">
        <span class="qt">“</span>
        <span class="copy">${body}</span>
        <span class="qt">”</span>
      </div>
    `;
  }

  if (blk.type === 'download') {
    const label = esc(p.label || 'Download');
    const url = esc(p.url || '#');
    return `
      <a class="nx-download-preview" href="${url}" target="_blank" rel="noopener">
        <span class="icon">⬇</span>
        <span>${label}</span>
      </a>
    `;
  }

  if (blk.type === 'textbox') {
    const label = esc(p.label || 'Textbox');
    const placeholder = esc(p.placeholder || 'Type here…');
    const lines = Math.max(1, Math.min(12, parseInt(p.lines ?? 3, 10) || 3));
    const bg = p.bgColor ? `background:${esc(p.bgColor)};` : 'background:rgba(255,255,255,.85);';
    return `<div><div style="font-weight:700;margin-bottom:6px">${label}</div><div style="padding:10px;border:1px dashed rgba(17,24,39,.25);border-radius:10px;min-height:${lines*16 + 20}px;color:rgba(17,24,39,.8);${bg}">${placeholder}</div></div>`;
  }

  if (blk.type === 'citationOrder') {
    const title = esc(p.title || 'Citation order');
    const normalized = formatCitationOrder(p.body || '');
    const body = hasHtml(p.html)
      ? p.html
      : formatMarked(normalized.slice(0, 320));
    return wrap(`
      <div style="border:1px solid rgba(17,24,39,.18);border-radius:14px;padding:14px;background:#f7f7f5">
        <div style="font-weight:900;margin-bottom:8px">${title}</div>
        <div>${body}</div>
      </div>
    `);
  }

  if (blk.type === 'exampleCard') {
    const heading = esc(p.heading || 'Example');
    const body = hasHtml(p.bodyHtml)
      ? p.bodyHtml
      : formatMarked((p.body || '').slice(0, 420));
    const youTry = hasHtml(p.youTry)
      ? (p.youTry || '')
      : formatMarked((p.youTry || 'Your turn…').slice(0, 180));
    const showTry = p.showYouTry !== false;
    const extraClass = showTry ? '' : ' nx-examplecard--single';
    const rightCol = showTry ? `
          <div style="padding:12px 14px">
            <div style="font-weight:900;margin-bottom:8px">You try</div>
            <div style="border:1px solid rgba(17,24,39,.35);border-radius:12px;padding:10px 12px;color:rgba(17,24,39,.82)">${youTry}</div>
          </div>` : '';
    return `
      <div class="nx-examplecard${extraClass}" style="display:grid;grid-template-columns:${showTry ? '1fr 1fr' : '1fr'};gap:10px;align-items:start;border:1px solid rgba(17,24,39,.18);border-radius:14px;overflow:hidden;background:#f9f9f7">
        <div style="background:#e2e3e6;padding:12px 14px">
          <div style="font-weight:900;margin-bottom:8px">${heading}</div>
          <div style="white-space:pre-line">${body}</div>
        </div>
        ${rightCol}
      </div>
    `;
  }

  if (blk.type === 'heroCard' || blk.type === 'heroPage') {
    const title = esc(p.title || 'Hero Title');
    const body = hasHtml(p.bodyHtml)
      ? p.bodyHtml
      : esc((p.body || 'Hero text').slice(0, 280)).replace(/\n/g, '<br>');
    const bg = p.bgImage ? `url(${esc(p.bgImage)})` : (p.bgColor || '#111827');
    return `
      <div style="position:relative;overflow:hidden;border-radius:16px;border:1px solid rgba(17,24,39,.2);min-height:200px;background:${bg};background-size:cover;background-position:center">
        <div style="position:absolute;inset:0;background:rgba(0,0,0,${p.overlayOpacity ?? 0.35});"></div>
        <div style="position:relative;padding:16px;">
          <div style="background:#ffffff; border-radius:14px; padding:12px 14px; max-width:70%; box-shadow:0 8px 24px rgba(0,0,0,.18);">
            <div style="font-weight:900;font-size:18px;margin-bottom:8px;color:#111827">${title}</div>
            <div style="color:#111827">${body}</div>
          </div>
        </div>
      </div>
    `;
  }

  if (blk.type === 'heroBanner') {
    const heading = esc(p.heading || 'Welcome');
    const ctaContent = p.ctaHtml
      ? (hasHtml(p.ctaHtml) ? p.ctaHtml : formatMarked(p.ctaHtml))
      : esc(p.cta || 'Learn more');
    const bg = p.bgImage ? `url(${esc(p.bgImage)})` : 'linear-gradient(135deg,#1f2937,#111827)';
    const overlay = Math.min(Math.max(parseFloat(p.overlayOpacity ?? 0.6), 0), 1);
    return `
      <div class="nx-hero-banner-preview" style="background:${bg};background-size:cover;background-position:center;position:relative;overflow:hidden;border-radius:10px;min-height:240px;border:1px solid rgba(0,0,0,.15);display:grid;place-items:center;padding:24px;">
        <div style="max-width:780px;width:100%;text-align:center;color:#fff;display:grid;gap:18px;padding:24px;border-radius:14px;box-shadow:0 12px 32px rgba(0,0,0,.22);background:rgba(0,0,0,${overlay});">
          <div style="font-size:32px;font-weight:800;line-height:1.2;">${heading}</div>
          <div style="display:inline-flex;justify-content:center;">
            <span style="display:inline-flex;align-items:center;gap:8px;padding:12px 18px;background:#1f3b87;border-radius:12px;color:#fff;font-weight:800;">${ctaContent}</span>
          </div>
        </div>
      </div>
    `;
  }

  if (blk.type === 'table') {
    const data = Array.isArray(p.data) && p.data.length ? p.data : [['', '', ''], ['', '', '']];
    const rows = data.length;
    const cols = Math.max(...data.map(r => r.length), 1);
    const density = p.density || 'default';
    const rowStyle = p.rowStyle || 'none';
    const gridLines = p.gridLines || 'subtle';
    const textAlign = p.textAlign || 'left';
    const vAlign = p.vAlign || 'middle';
    const headerRow = !!p.headerRow;
    const headerCol = !!p.headerCol;
    const allowResize = p.colResize !== false;
    const colWidths = Array.isArray(p.colWidths) ? p.colWidths : [];

    const densityPad = density === 'compact' ? '6px 8px' : density === 'spacious' ? '12px 14px' : '9px 12px';
    const border =
      gridLines === 'full' ? '1px solid rgba(17,24,39,.18)'
      : gridLines === 'subtle' ? '1px solid rgba(17,24,39,.12)'
      : '1px solid transparent';

    const colgroup = Array.from({ length: cols }).map((_, cIdx) => {
      const width = colWidths[cIdx] || 'auto';
      return `<col style="width:${esc(width)}">`;
    }).join('');

    const rowsHtml = data.map((row, rIdx) => {
      const isHeadRow = headerRow && rIdx === 0;
      const stripedBg = rowStyle === 'striped' && rIdx % 2 === 1 ? 'background:rgba(17,24,39,.04);' : '';
      const cells = Array.from({ length: cols }).map((_, cIdx) => {
        const cell = row[cIdx] ?? '';
        const isHeadCol = headerCol && cIdx === 0;
        const tag = (isHeadRow || isHeadCol) ? 'th' : 'td';
        return `<${tag}
          class="nx-table-cell"
          data-row="${rIdx}"
          data-col="${cIdx}"
          contenteditable="true"
          style="padding:${densityPad};border:${border};text-align:${textAlign};vertical-align:${vAlign};${stripedBg}"
        >${formatMarked(cell || '')}</${tag}>`;
      }).join('');
      return `<tr data-row="${rIdx}">${cells}</tr>`;
    }).join('');

    return `
      <div class="nx-table-block" data-bid="${blk.id}" data-density="${density}" data-grid="${gridLines}">
        <div class="nx-table-toolbar">
          <div class="nx-table-hint">${allowResize ? 'Resize cols enabled' : 'Fixed cols'} • Enter → new row • Tab ↔</div>
          <div class="nx-table-actions">
            <button type="button" class="btn small nx-table-add-row" data-pos="below">+ Row</button>
            <button type="button" class="btn small nx-table-add-col" data-pos="right">+ Column</button>
          </div>
        </div>
        <div class="nx-table-scroller">
          <table class="nx-table-preview" data-density="${density}" data-grid="${gridLines}">
            <colgroup>${colgroup}</colgroup>
            ${rowsHtml}
          </table>
        </div>
      </div>
    `;
  }

  if (blk.type === 'image') return esc(p.src ? `Image: ${trunc(p.src, 10)}` : 'Image: set URL in inspector');
  if (blk.type === 'video') return esc(p.url ? `Video: ${trunc(p.url, 10)}` : 'Video: set embed URL');
  if (blk.type === 'carousel') {
    const slides = Array.isArray(p.slides) ? p.slides : [];
    const first = slides[0] || {};
    const title = esc((first.title || 'Carousel slide').slice(0, 80));
    const body = esc((first.body || '').slice(0, 120));
    const show = p.slidesToShow || 1;
    const bg = esc(p.background || '');
    const dots = Math.max(2, slides.length || 2);
    const active = Math.min(Math.max(0, p.activeSlide || 0), slides.length - 1);
    const textCss = inlineTextCSS(blk);
    return `
      <div class="nx-carousel-preview" style="background:${bg || 'linear-gradient(135deg,#fdfaf3,#fffefb)'}">
        <div class="nx-car-preview-copy">
          <div class="nx-car-preview-title" style="${textCss}">${title}</div>
          <div class="nx-car-preview-body" style="${textCss}">${body}</div>
        </div>
        <div class="nx-car-preview-dots">
      ${Array.from({length:dots}).map((_,i)=>`<span class="${i===active?'active':''}"></span>`).join('')}
    </div>
    <div class="nx-car-preview-meta">Showing ${show} at a time</div>
    <div class="nx-car-preview-nav">
      <button type="button" class="smallbtn nx-car-preview-prev">‹</button>
      <button type="button" class="smallbtn nx-car-preview-next">›</button>
    </div>
  </div>
`;
  }

  if (blk.type === 'dragWords') {
    const sentence = esc((p.sentence || '').replace(/\{(\d+)\}/g, '___'));
    const pool = (p.pool || []).slice(0, 6).map(w => esc(w)).join(', ');
    return `
      <div style="border:1px dashed rgba(17,24,39,.25);padding:10px;border-radius:12px;background:#f7f7f5">
        <div style="font-weight:800;margin-bottom:6px">${esc(p.instruction || 'Drag the words')}</div>
        <div style="margin-bottom:6px">${sentence || 'Sentence with blanks'}</div>
        <div style="font-size:13px;color:#374151">Words: ${pool || 'add words'}</div>
      </div>
    `;
  }
  if (blk.type === 'flipCard') {
    const front = esc((p.frontTitle || 'Front').slice(0, 40));
    const back = esc((p.backTitle || 'Back').slice(0, 40));
    return `
      <div style="border:1px solid rgba(17,24,39,.2);border-radius:14px;overflow:hidden">
        <div style="padding:10px;background:#e5e7eb;font-weight:800">${front}</div>
        <div style="padding:10px;background:#f9fafb;color:#1f2937">${back}</div>
      </div>
    `;
  }
  if (blk.type === 'trueFalse') {
    const q = esc((p.question || 'True or false?').slice(0, 140));
    return `
      <div style="border:1px solid rgba(17,24,39,.18);border-radius:12px;padding:10px;background:#f8fafc">
        <div style="font-weight:800;margin-bottom:6px">${q}</div>
        <div style="display:flex;gap:8px">
          <span style="padding:6px 10px;border-radius:999px;background:rgba(34,197,94,.15);color:#166534;font-weight:700">T</span>
          <span style="padding:6px 10px;border-radius:999px;background:rgba(239,68,68,.15);color:#991b1b;font-weight:700">F</span>
        </div>
      </div>
    `;
  }
  if (blk.type === 'multipleChoiceQuiz') {
    const mcq = ensureMCQProps(blk);
    const questions = mcq.questions || [];
    const expHtml = (mcq.explanationHtml && mcq.explanationHtml.trim())
      ? mcq.explanationHtml
      : esc(mcq.explanationText || '').replace(/\n/g,'<br>');
    const vars = [];
    if (mcq.accentColor) vars.push(`--mcq-accent:${mcq.accentColor}`);
    if (mcq.correctColor) vars.push(`--mcq-correct:${mcq.correctColor}`);
    if (mcq.incorrectColor) vars.push(`--mcq-incorrect:${mcq.incorrectColor}`);
    return `
      <div class="nx-mcq" data-bid="${blk.id}"
        data-spacing="${mcq.spacing}"
        data-style="${mcq.styleVariant}"
        data-border="${mcq.showBorder ? 'on' : 'off'}"
        data-shuffle="${mcq.shuffle ? 'on' : 'off'}"
        data-max-attempts="${mcq.maxAttempts}"
        data-feedback="${mcq.showFeedback ? 'on' : 'off'}"
        data-feedback-timing="${mcq.feedbackTiming}"
        data-require="${mcq.requireAnswer ? 'on' : 'off'}"
        data-show-reset="${mcq.showReset ? 'on' : 'off'}"
        data-reset-correct="${mcq.allowResetOnCorrect ? 'on' : 'off'}"
        data-exp="${mcq.showExplanationAfter}"
        data-correct-msg="${esc(mcq.correctMsg)}"
        data-incorrect-msg="${esc(mcq.incorrectMsg)}"
        style="${vars.join(';')}">
        ${(questions || []).map((q, idx) => {
          const hasImage = q.showImage && q.image;
          const questionHtml = (q.questionHtml && q.questionHtml.trim())
            ? q.questionHtml
            : (esc(q.questionText || `Question ${idx + 1}`).replace(/\n/g,'<br>'));
          const opts = mcq.shuffle ? [...(q.options || [])].sort(() => Math.random() - 0.5) : (q.options || []);
          return `
            <div class="nx-mcq-q" data-qid="${q.id}" data-correct="${esc(q.correctOptionId || '')}">
              <div class="nx-mcq-head">
                <div class="nx-mcq-question"><span class="nx-muted">Question ${idx + 1}</span><br>${questionHtml || '<span class="nx-muted">Add question…</span>'}</div>
                ${hasImage ? `<div class="nx-mcq-media"><img src="${esc(q.image)}" alt="${esc(q.imageAlt || '')}"></div>` : ''}
              </div>
              <div class="nx-mcq-options" role="radiogroup" aria-label="Multiple choice options">
                ${opts.map((opt, oIdx) => `
                  <label class="nx-mcq-option">
                    <input type="radio" name="mcq-${blk.id}-${q.id}" data-opt="${esc(opt.id)}">
                    <span class="nx-mcq-optionlabel">${opt.label ? esc(opt.label) : `Option ${oIdx + 1}`}</span>
                  </label>
                `).join('')}
              </div>
              <div class="nx-mcq-actions">
                <button type="button" class="smallbtn nx-mcq-check">Check answer</button>
                <button type="button" class="smallbtn nx-mcq-reset" style="${mcq.showReset ? '' : 'display:none'}">Reset</button>
              </div>
              <div class="nx-mcq-feedback" aria-live="polite"></div>
            </div>
          `;
        }).join('')}
        ${mcq.explanationHtml || mcq.explanationText ? `<div class="nx-mcq-explain" data-state="hidden">${expHtml}</div>` : ''}
      </div>
    `;
  }
  if (blk.type === 'accordionTabs' || blk.type === 'accordion') {
    const acc = ensureAccordionTabsProps(blk);
    const items = acc.items || [];
    const renderItems = acc.mode === 'tabs' ? items.slice(0, ACCORDION_TABS_MAX) : items;
    const vars = [];
    if (acc.headerBg) vars.push(`--acc-head-bg:${acc.headerBg}`);
    if (acc.headerColor) vars.push(`--acc-head-color:${acc.headerColor}`);
    if (acc.activeHeaderBg) vars.push(`--acc-head-active:${acc.activeHeaderBg}`);
    if (acc.activeHeaderColor) vars.push(`--acc-head-active-color:${acc.activeHeaderColor}`);
    if (acc.contentBg) vars.push(`--acc-body-bg:${acc.contentBg}`);
    if (acc.borderColor) vars.push(`--acc-border:${acc.borderColor}`);

    const spacing = acc.spacing || 'default';
    const variant = acc.styleVariant || 'default';
    const showDividers = acc.showDividers !== false;
    const imgPos = acc.headerImgPos || 'left';
    const imgSize = acc.headerImgSize || 'medium';
    const focusIdx = getAccordionFocus(blk.id, renderItems.length);

    const renderHeaderImg = (it) => {
      if (!it.showHeaderImg || !it.headerImg) return '';
      return `<span class="nx-acc-thumb ${imgPos === 'right' ? 'thumb-right' : 'thumb-left'} size-${imgSize}" style="background-image:url('${esc(it.headerImg)}')" aria-hidden="true"></span>`;
    };

    if (acc.mode === 'tabs') {
      const active = Number.isInteger(focusIdx) ? focusIdx : (acc.tabsDefault === 'custom'
        ? Math.min(acc.tabsIndex, Math.max(0, renderItems.length - 1))
        : 0);
      const tabsStyleVars = [...vars, `--nx-tab-count:${renderItems.length || 1}`].join(';');
      return `
        <div class="nx-tabs" data-align="${acc.tabsAlign}" data-style="${acc.tabsStyle}" data-border="${acc.showBorder ? 'on' : 'off'}" data-bid="${blk.id}" style="${tabsStyleVars}">
          <div class="nx-tabs-list" role="tablist">
            ${renderItems.map((it, idx) => `
              <button role="tab" class="nx-tab ${idx===active?'active':''}" aria-selected="${idx===active}" aria-controls="tab-panel-${blk.id}-${idx}" id="tab-${blk.id}-${idx}">
                ${renderHeaderImg(it)}
                <span class="nx-tab-label">${esc(it.title || `Item ${idx+1}`)}</span>
              </button>
            `).join('')}
          </div>
          <div class="nx-tabs-panels">
            ${renderItems.map((it, idx) => {
              const bodyHtml = (it.bodyHtml && it.bodyHtml.trim()) ? it.bodyHtml : esc((it.body || '').slice(0, 320)).replace(/\n/g,'<br>');
              return `
                <div role="tabpanel" class="nx-tab-panel ${idx===active?'active':''}" id="tab-panel-${blk.id}-${idx}" aria-labelledby="tab-${blk.id}-${idx}" ${idx===active?'':'hidden'} style="${idx===active?'':'display:none'}">
                  <div class="nx-accordion-body">${bodyHtml || '<span class="nx-muted">Add content…</span>'}</div>
                </div>
              `;
            }).join('')}
          </div>
        </div>
      `;
    }

    const allowMultiple = !!acc.allowMultiple;
    const openSet = new Set();
    if (acc.defaultOpen === 'first' && items[0]) openSet.add(0);
    if (acc.defaultOpen === 'custom' && items[acc.defaultIndex]) openSet.add(acc.defaultIndex);
    items.forEach((it, idx) => {
      if (it.openDefault) openSet.add(idx);
    });
    if (Number.isInteger(focusIdx)) openSet.add(focusIdx);
    if (!allowMultiple && openSet.size > 1) {
      const first = [...openSet][0];
      openSet.clear(); openSet.add(first);
    }
    const indPos = acc.showIndicator ? acc.indicatorPosition : 'none';

    return `
      <div class="nx-accordion"
        data-allow="${allowMultiple ? 'multiple' : 'single'}"
        data-collapse="${acc.allowCollapseAll !== false ? 'allow' : 'force'}"
        data-indicator="${indPos}"
        data-spacing="${spacing}"
        data-style="${variant}"
        data-dividers="${showDividers ? 'on' : 'off'}"
        data-border="${acc.showBorder ? 'on' : 'off'}"
        data-bid="${blk.id}"
        style="${vars.join(';')}">
        ${items.map((it, idx) => {
          const isOpen = openSet.has(idx);
          const headId = `acc-head-${blk.id}-${idx}`;
          const panelId = `acc-panel-${blk.id}-${idx}`;
          const bodyHtml = (it.bodyHtml && it.bodyHtml.trim()) ? it.bodyHtml : esc((it.body || '').slice(0, 320)).replace(/\n/g,'<br>');
          const thumb = renderHeaderImg(it);
          return `
            <div class="nx-accordion-item ${isOpen ? 'is-open' : ''}" data-idx="${idx}">
              <button type="button" class="nx-accordion-head" id="${headId}" aria-expanded="${isOpen ? 'true' : 'false'}" aria-controls="${panelId}">
                ${indPos === 'left' ? '<span class="nx-accordion-chevron" aria-hidden="true">▸</span>' : ''}
                ${imgPos === 'left' ? thumb : ''}
                <span class="nx-accordion-title">${esc(it.title || `Item ${idx + 1}`)}</span>
                ${imgPos === 'right' ? thumb : ''}
                ${indPos === 'right' ? '<span class="nx-accordion-chevron" aria-hidden="true">▸</span>' : ''}
              </button>
              <div class="nx-accordion-panel" id="${panelId}" role="region" aria-labelledby="${headId}" ${isOpen ? '' : 'hidden'}>
                <div class="nx-accordion-body">${bodyHtml || '<span class="nx-muted">Add content…</span>'}</div>
              </div>
            </div>
          `;
        }).join('')}
      </div>
    `;
  }
  if (blk.type === 'divider') return '<hr style="border:0;border-top:1px solid rgba(17,24,39,.18)">';
  return '';
}

  // Safe wrapper to avoid render crashes from malformed blocks
  function blockPreviewSafe(blk) {
    try {
      if (!blk || typeof blk !== 'object') return '<div class="nx-muted">Invalid block</div>';
      return blockPreviewHTML(blk);
    } catch (err) {
      console.error('Block preview error', blk, err);
      return '<div class="nx-muted">Preview unavailable</div>';
    }
  }

  function initAccordionPreview(el) {
    if (!el || el.dataset.accInit === '1') return;
    el.dataset.accInit = '1';
    const allowMultiple = el.dataset.allow === 'multiple';
    const allowCollapse = el.dataset.collapse !== 'force';
  const items = el.querySelectorAll('.nx-accordion-item');

  const savePreviewState = () => {
    const opens = [];
    items.forEach((it, i) => { if (it.classList.contains('is-open')) opens.push(i); });
    if (el.dataset.bid) {
      accordionPreviewState[el.dataset.bid] = { mode: 'accordion', opens };
    }
  };

  const setOpen = (item, open, instant = false) => {
    const btn = item.querySelector('.nx-accordion-head');
    const panel = item.querySelector('.nx-accordion-panel');
    const chevron = btn?.querySelector('.nx-accordion-chevron');
    if (!btn || !panel) return;
    const applyHeight = () => {
      panel.style.maxHeight = open ? `${panel.scrollHeight}px` : '0px';
    };
    btn.setAttribute('aria-expanded', open ? 'true' : 'false');
    panel.setAttribute('aria-hidden', open ? 'false' : 'true');
    if (chevron) chevron.classList.toggle('open', open);
    if (open) {
      item.classList.add('is-open');
      panel.hidden = false;
      applyHeight();
      if (!instant) {
        setTimeout(() => { panel.style.maxHeight = 'none'; }, 200);
      } else {
        panel.style.maxHeight = 'none';
      }
    } else {
      item.classList.remove('is-open');
      if (instant) {
        panel.style.maxHeight = '0px';
        panel.hidden = true;
        return;
      }
      panel.style.maxHeight = `${panel.scrollHeight}px`;
      requestAnimationFrame(() => {
        panel.style.maxHeight = '0px';
      });
      const onEnd = () => {
        panel.hidden = true;
        panel.style.maxHeight = '0px';
        panel.removeEventListener('transitionend', onEnd);
      };
      panel.addEventListener('transitionend', onEnd);
      setTimeout(onEnd, 300); // fallback if transitionend doesn't fire
    }
  };

    items.forEach((item) => {
      const btn = item.querySelector('.nx-accordion-head');
      if (!btn) return;
      const idx = parseInt(item.dataset.idx || '-1', 10);
      const toggle = () => {
        const isOpen = item.classList.contains('is-open');
        if (Number.isInteger(idx)) {
          setAccordionFocus(el.dataset.bid, idx);
          if (selected && getSelectedBlock()?.id === el.dataset.bid) {
            renderInspector();
          }
        }
        if (isOpen && !allowCollapse && !allowMultiple) {
          return;
        }
        if (!isOpen && !allowMultiple) {
          items.forEach(it => { if (it !== item) setOpen(it, false); });
        }
        setOpen(item, !isOpen);
        savePreviewState();
      };
      btn.addEventListener('click', (e) => {
        e.preventDefault();
        toggle();
      });
      btn.addEventListener('keydown', (e) => {
        if (e.key === 'Enter' || e.key === ' ') {
          e.preventDefault();
          toggle();
        }
      });
    });

  // Initialize heights
  items.forEach(item => setOpen(item, item.classList.contains('is-open'), true));
  savePreviewState();
}

  function initTabsPreview(el) {
    if (!el || el.dataset.tabInit === '1') return;
    el.dataset.tabInit = '1';
    const tabs = Array.from(el.querySelectorAll('.nx-tab'));
    const panels = Array.from(el.querySelectorAll('.nx-tab-panel'));
    const initialActive = Math.max(0, tabs.findIndex(t => t.classList.contains('active')));
    const setActive = (idx) => {
      tabs.forEach((t,i) => {
        const on = i===idx;
        t.classList.toggle('active', on);
        t.setAttribute('aria-selected', on ? 'true' : 'false');
        const panel = panels[i];
        if (panel) {
          panel.classList.toggle('active', on);
          if (on) {
            panel.removeAttribute('hidden');
            panel.style.display = '';
          } else {
            panel.setAttribute('hidden','');
            panel.style.display = 'none';
          }
        }
      });
    };
    setActive(initialActive >= 0 ? initialActive : 0);
    tabs.forEach((t,idx) => {
      t.addEventListener('click', (e) => {
        e.preventDefault();
        setActive(idx);
        setAccordionFocus(el.dataset.bid, idx);
        if (selected && getSelectedBlock()?.id === el.dataset.bid) {
          renderInspector();
        }
      });
      t.addEventListener('keydown', (e) => {
        if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); setActive(idx); }
        if (e.key === 'ArrowRight' || e.key === 'ArrowLeft') {
          e.preventDefault();
          const dir = e.key === 'ArrowRight' ? 1 : -1;
          const next = (idx + dir + tabs.length) % tabs.length;
          tabs[next]?.focus();
          setActive(next);
        }
      });
    });
  }

  function bindAccordionPreviews() {
    canvas?.querySelectorAll('.nx-accordion').forEach(initAccordionPreview);
    canvas?.querySelectorAll('.nx-tabs').forEach(initTabsPreview);
  }

  function initTableBlock(el) {
    if (!el || el.dataset.tableInit === '1') return;
    el.dataset.tableInit = '1';
    const bid = el.dataset.bid;
    const blk = getBlockById(bid);
    if (!blk) return;
    ensureTableProps(blk);

    const table = el.querySelector('table');
    const addRowBtn = el.querySelector('.nx-table-add-row');
    const addColBtn = el.querySelector('.nx-table-add-col');

    const syncInspectorCounts = () => {
      const rows = blk.props.data.length;
      const cols = Math.max(...blk.props.data.map(r => r.length), 0);
      el.dataset.rows = rows;
      el.dataset.cols = cols;
      if (getSelectedBlock()?.id === bid) renderInspector();
    };

    const persist = () => {
      persistUnsaved();
      render();
    };

    const onCellInput = (e) => {
      const r = parseInt(e.target.dataset.row || '-1', 10);
      const c = parseInt(e.target.dataset.col || '-1', 10);
      if (r < 0 || c < 0) return;
      startEditSession();
      ensureTableProps(blk);
      blk.props.data[r][c] = e.target.innerText;
      persist();
    };

    const addRow = (pos = 'below') => {
      startEditSession();
      ensureTableProps(blk);
      const cols = Math.max(...blk.props.data.map(r => r.length), 1);
      const row = Array.from({ length: cols }).map(() => '');
      if (pos === 'above') blk.props.data.unshift(row);
      else blk.props.data.push(row);
      persist();
    };

    const addCol = (pos = 'right') => {
      startEditSession();
      ensureTableProps(blk);
      blk.props.data = blk.props.data.map((r) => {
        const row = Array.isArray(r) ? r.slice() : [];
        if (pos === 'left') row.unshift('');
        else row.push('');
        return row;
      });
      persist();
    };

    const deleteRow = (idx) => {
      if (blk.props.data.length <= 1) return;
      startEditSession();
      blk.props.data.splice(idx, 1);
      persist();
    };

    const deleteCol = (idx) => {
      const cols = Math.max(...blk.props.data.map(r => r.length), 0);
      if (cols <= 1) return;
      startEditSession();
      blk.props.data = blk.props.data.map((r) => {
        const row = Array.isArray(r) ? r.slice() : [];
        row.splice(idx, 1);
        return row;
      });
      persist();
    };

    table?.querySelectorAll('.nx-table-cell').forEach((cell) => {
      cell.addEventListener('input', onCellInput);
      cell.addEventListener('keydown', (e) => {
        const r = parseInt(cell.dataset.row || '0', 10);
        const c = parseInt(cell.dataset.col || '0', 10);
        const rows = blk.props.data.length;
        const cols = Math.max(...blk.props.data.map(row => row.length), 1);
        if (e.key === 'Tab') {
          e.preventDefault();
          const dir = e.shiftKey ? -1 : 1;
          let nextIndex = r * cols + c + dir;
          let max = rows * cols - 1;
          if (nextIndex < 0) nextIndex = 0;
          if (nextIndex > max) {
            addRow('below');
            return;
          }
          const nr = Math.floor(nextIndex / cols);
          const nc = nextIndex % cols;
          table.querySelector(`[data-row="${nr}"][data-col="${nc}"]`)?.focus();
        }
        if (e.key === 'Enter' && !e.shiftKey) {
          e.preventDefault();
          if (r === rows - 1) {
            addRow('below');
            return;
          }
          table.querySelector(`[data-row="${r + 1}"][data-col="${c}"]`)?.focus();
        }
      });
    });

    table?.querySelectorAll('tr').forEach((rowEl, idx) => {
      const addAbove = document.createElement('button');
      addAbove.type = 'button';
      addAbove.className = 'nx-table-rowbtn nx-row-add-above';
      addAbove.textContent = '+';
      addAbove.addEventListener('click', () => addRow('above'));

      const addBelow = document.createElement('button');
      addBelow.type = 'button';
      addBelow.className = 'nx-table-rowbtn nx-row-add-below';
      addBelow.textContent = '+';
      addBelow.addEventListener('click', () => addRow('below'));

      const del = document.createElement('button');
      del.type = 'button';
      del.className = 'nx-table-rowbtn nx-row-del';
      del.textContent = '×';
      del.addEventListener('click', () => deleteRow(idx));

      rowEl.appendChild(addAbove);
      rowEl.appendChild(addBelow);
      rowEl.appendChild(del);
    });

    if (addRowBtn) addRowBtn.addEventListener('click', () => addRow('below'));
    if (addColBtn) addColBtn.addEventListener('click', () => addCol('right'));

    // Column controls
    const header = document.createElement('div');
    header.className = 'nx-table-colbar';
    const colsCount = Math.max(...blk.props.data.map(r => r.length), 1);
    for (let i = 0; i < colsCount; i += 1) {
      const colBtn = document.createElement('div');
      colBtn.className = 'nx-table-colctrl';
      colBtn.innerHTML = `
        <button type="button" class="nx-table-col-add-left" data-col="${i}">+</button>
        <button type="button" class="nx-table-col-del" data-col="${i}">×</button>
        <button type="button" class="nx-table-col-add-right" data-col="${i}">+</button>
      `;
      header.appendChild(colBtn);
    }
    el.querySelector('.nx-table-scroller')?.prepend(header);

    header.querySelectorAll('.nx-table-col-add-left').forEach(btn => {
      btn.addEventListener('click', (e) => {
        const idx = parseInt(e.target.dataset.col || '0', 10);
        startEditSession();
        blk.props.data = blk.props.data.map((r) => {
          const row = Array.isArray(r) ? r.slice() : [];
          row.splice(idx, 0, '');
          return row;
        });
        persist();
      });
    });
    header.querySelectorAll('.nx-table-col-add-right').forEach(btn => {
      btn.addEventListener('click', (e) => {
        const idx = parseInt(e.target.dataset.col || '0', 10);
        startEditSession();
        blk.props.data = blk.props.data.map((r) => {
          const row = Array.isArray(r) ? r.slice() : [];
          row.splice(idx + 1, 0, '');
          return row;
        });
        persist();
      });
    });
    header.querySelectorAll('.nx-table-col-del').forEach(btn => {
      btn.addEventListener('click', (e) => {
        const idx = parseInt(e.target.dataset.col || '0', 10);
        deleteCol(idx);
      });
    });

    syncInspectorCounts();
  }

  function bindTableBlocks() {
    canvas?.querySelectorAll('.nx-table-block').forEach(initTableBlock);
  }

  function initMCQPreview(el) {
    if (!el || el.dataset.mcqInit === '1') return;
    el.dataset.mcqInit = '1';
    const explain = el.querySelector('.nx-mcq-explain');
    const maxAttempts = Math.max(0, parseInt(el.dataset.maxAttempts || '0', 10) || 0);
    const requireAns = el.dataset.require === 'on';
    const showFeedback = el.dataset.feedback !== 'off';
    const feedbackTiming = el.dataset.feedbackTiming || 'submit';
    const showReset = el.dataset.showReset !== 'off';
    const allowResetOnCorrect = el.dataset.resetCorrect === 'on';
    const showExp = el.dataset.exp || 'submit';
    const correctMsg = el.dataset.correctMsg || 'Correct';
    const incorrectMsg = el.dataset.incorrectMsg || 'Try again';

    const attachQuestion = (qEl) => {
      const radios = Array.from(qEl.querySelectorAll('input[type="radio"][name]'));
      const checkBtn = qEl.querySelector('.nx-mcq-check');
      const resetBtn = qEl.querySelector('.nx-mcq-reset');
      const feedback = qEl.querySelector('.nx-mcq-feedback');
      const correctId = qEl.dataset.correct || '';
      let attempts = 0;

      const setFeedback = (ok) => {
        if (!showFeedback || !feedback) return;
        feedback.textContent = ok ? correctMsg : incorrectMsg;
        feedback.className = `nx-mcq-feedback ${ok ? 'is-correct' : 'is-incorrect'}`;
        if (explain) {
          const shouldShow = showExp === 'submit' ? true : !ok;
          explain.dataset.state = shouldShow ? 'show' : 'hidden';
        }
      };

      const clearFeedback = () => {
        if (feedback) {
          feedback.textContent = '';
          feedback.className = 'nx-mcq-feedback';
        }
        if (explain) explain.dataset.state = 'hidden';
      };

      const reset = () => {
        radios.forEach(r => { r.checked = false; r.disabled = false; r.closest('.nx-mcq-option')?.classList.remove('is-correct','is-incorrect'); });
        attempts = 0;
        clearFeedback();
        if (checkBtn && requireAns) checkBtn.disabled = true;
      };

      const evaluate = () => {
        const selected = radios.find(r => r.checked);
        if (!selected) {
          if (requireAns && feedback) {
            feedback.textContent = 'Select an option first.';
            feedback.className = 'nx-mcq-feedback';
          }
          return;
        }
        attempts += 1;
        const ok = selected.dataset.opt === correctId;
        radios.forEach(r => {
          const wrap = r.closest('.nx-mcq-option');
          wrap?.classList.remove('is-correct','is-incorrect');
          if (showFeedback) {
            if (r.dataset.opt === correctId && ok) wrap?.classList.add('is-correct');
            if (r.checked && !ok) wrap?.classList.add('is-incorrect');
          }
        });
        setFeedback(ok);
        if ((maxAttempts && attempts >= maxAttempts) || (ok && !allowResetOnCorrect && !showReset)) {
          radios.forEach(r => r.disabled = true);
          if (checkBtn) checkBtn.disabled = true;
        }
      };

      radios.forEach(r => {
        r.addEventListener('change', () => {
          if (checkBtn && requireAns) checkBtn.disabled = false;
          if (feedbackTiming === 'onSelect') evaluate();
        });
      });
      checkBtn?.addEventListener('click', (e) => { e.preventDefault(); evaluate(); });
      resetBtn?.addEventListener('click', (e) => { e.preventDefault(); reset(); });
      if (!showReset && resetBtn) resetBtn.style.display = 'none';
      if (checkBtn && requireAns) checkBtn.disabled = true;
    };

    el.querySelectorAll('.nx-mcq-q').forEach(attachQuestion);
  }

  function bindMCQPreviews() {
    canvas?.querySelectorAll('.nx-mcq').forEach(initMCQPreview);
  }


  function updateBlockCard(blk) {
    if (!blk?.id) return;
    const el = canvas.querySelector(`.block[data-bid="${CSS.escape(blk.id)}"]`);
    if (!el) return;

    const prev = el.querySelector('.nx-block-preview');
    if (!prev) return;

    const prevState = captureAccordionPreviewState(blk);
    prev.innerHTML = blockPreviewHTML(blk);
    bindAccordionPreviews();
    bindMCQPreviews();
    restoreAccordionPreviewState(blk, prevState);
  }


  // --- Accordions (left)
  document.querySelectorAll('.nx-acc-h').forEach(btn => {
    const id = btn.dataset.acc;
    const body = document.getElementById(`acc-${id}`);
    if (!body) return;

    if (body.classList.contains('open')) btn.classList.add('is-open');

    btn.addEventListener('click', () => {
      body.classList.toggle('open');
      btn.classList.toggle('is-open', body.classList.contains('open'));
    });
  });

  // --- Right panel switching
  function showInspector() {
    if (rightTitle) rightTitle.textContent = 'Inspector';
    inspectorView.style.display = '';
    revisionsView.style.display = 'none';
    backToInspectorBtn.style.display = 'none';
    syncToolbarFromSelection();
  }

  function showRevisions() {
    if (rightTitle) rightTitle.textContent = 'Revisions';
    inspectorView.style.display = 'none';
    revisionsView.style.display = '';
    if (textToolbar) textToolbar.style.display = 'none';
    backToInspectorBtn.style.display = '';
    loadRevisions();
  }

  openRevisionsBtn?.addEventListener('click', showRevisions);
  backToInspectorBtn?.addEventListener('click', showInspector);

  // Panel collapse toggles
  const toggleLeft = document.getElementById('toggleLeft');
  const toggleRight = document.getElementById('toggleRight');
  const refreshToggleLabels = () => {
    if (toggleLeft) toggleLeft.textContent = document.body.classList.contains('left-collapsed') ? '+' : '−';
    if (toggleRight) toggleRight.textContent = document.body.classList.contains('right-collapsed') ? '+' : '−';
  };
  toggleLeft?.addEventListener('click', () => {
    document.body.classList.toggle('left-collapsed');
    refreshToggleLabels();
  });
  toggleRight?.addEventListener('click', () => {
    document.body.classList.toggle('right-collapsed');
    refreshToggleLabels();
  });
  refreshToggleLabels();

  function applyToolbarChange(mutator){
    const blk = getSelectedBlock();
    if (!blk || !isTextLike(blk)) return;
    pushHistory();
    const st = ensureTextStyle(blk);
    mutator(st);
    persistUnsaved();
    render();
  }

  function findVisibleRichFields() {
    return Array.from(document.querySelectorAll('#inspectorView .nx-rich[contenteditable="true"]')).filter(el => el.offsetParent !== null);
  }

  function applyInlineFormat(cmd) {
    restoreTextSelection();
    const sel = window.getSelection();
    const active = document.activeElement;
    const richFields = findVisibleRichFields();
    const target = (active?.isContentEditable && active.closest('#inspectorView')) ? active : richFields[0];

    // Prefer current selection
    if (target && sel && sel.rangeCount > 0 && !sel.isCollapsed && target.contains(sel.anchorNode)) {
      document.execCommand(cmd, false, null);
      target.dispatchEvent(new Event('input', { bubbles: true }));
      return;
    }

    // Fallback to whole field when only one rich area
    if (target && richFields.length === 1) {
      target.focus();
      const range = document.createRange();
      range.selectNodeContents(target);
      sel?.removeAllRanges();
      sel?.addRange(range);
      document.execCommand(cmd, false, null);
      target.dispatchEvent(new Event('input', { bubbles: true }));
    }
  }

  function applyRichCommand(cmd, value) {
    restoreTextSelection();
    const sel = window.getSelection();
    const richFields = findVisibleRichFields();
    const active = document.activeElement;
    const target = (active?.isContentEditable && active.closest('#inspectorView')) ? active : richFields[0];
    if (!target) return;

    let hasRange = sel && sel.rangeCount > 0 && !sel.isCollapsed && target.contains(sel.anchorNode);
    if (!hasRange && richFields.length === 1) {
      target.focus();
      const range = document.createRange();
      range.selectNodeContents(target);
      sel?.removeAllRanges();
      sel?.addRange(range);
      hasRange = true;
    }
    if (!hasRange) return;

    try { document.execCommand('styleWithCSS', false, true); } catch {}
    document.execCommand(cmd, false, value);
    target.dispatchEvent(new Event('input', { bubbles: true }));
  }

  function applyList(listType) {
    restoreTextSelection();
    const sel = window.getSelection();
    const richFields = findVisibleRichFields();
    const active = document.activeElement;
    const target = (active?.isContentEditable && active.closest('#inspectorView')) ? active : richFields[0];
    if (!target) return;

    let hasRange = sel && sel.rangeCount > 0 && target.contains(sel.anchorNode);
    if (!hasRange && richFields.length === 1) {
      target.focus();
      const range = document.createRange();
      range.selectNodeContents(target);
      sel?.removeAllRanges();
      sel?.addRange(range);
      hasRange = true;
    }
    if (!hasRange) return;

    document.execCommand(listType === 'ol' ? 'insertOrderedList' : 'insertUnorderedList', false, null);
    target.dispatchEvent(new Event('input', { bubbles: true }));
  }

  function applyFontSize(px) {
    const size = Math.max(10, Math.min(72, parseInt(px || '0', 10) || 0));
    restoreTextSelection();
    const sel = window.getSelection();
    const richFields = findVisibleRichFields();
    const active = document.activeElement;
    const target = (active?.isContentEditable && active.closest('#inspectorView')) ? active : richFields[0];
    if (!target) return;

    let hasRange = sel && sel.rangeCount > 0 && !sel.isCollapsed && target.contains(sel.anchorNode);
    if (!hasRange && richFields.length === 1) {
      target.focus();
      const range = document.createRange();
      range.selectNodeContents(target);
      sel?.removeAllRanges();
      sel?.addRange(range);
      hasRange = true;
    }
    if (!hasRange) return;

    try { document.execCommand('styleWithCSS', false, true); } catch {}
    document.execCommand('fontSize', false, '7');
    target.querySelectorAll('font[size="7"]').forEach(node => {
      const span = document.createElement('span');
      span.style.fontSize = `${size}px`;
      span.innerHTML = node.innerHTML;
      node.replaceWith(span);
    });
    target.dispatchEvent(new Event('input', { bubbles: true }));
  }

  function setToolbarFontSizeValue(sizePx) {
    if (!tFontSize) return;
    const size = parseInt(sizePx || '0', 10);
    if (!Number.isFinite(size) || size <= 0) {
      tFontSize.value = '';
      return;
    }
    if (tFontSize.tagName !== 'SELECT') {
      tFontSize.value = String(size);
      return;
    }

    const numericOpts = Array.from(tFontSize.options)
      .map((opt) => parseInt(opt.value || '', 10))
      .filter((n) => Number.isFinite(n));

    if (!numericOpts.length) {
      tFontSize.value = '';
      return;
    }

    let best = numericOpts[0];
    let bestDiff = Math.abs(size - best);
    for (let i = 1; i < numericOpts.length; i++) {
      const n = numericOpts[i];
      const diff = Math.abs(size - n);
      if (diff < bestDiff) {
        best = n;
        bestDiff = diff;
      }
    }
    tFontSize.value = String(best);
  }

  function inferBlockFontSizePx(blk) {
    if (!blk || !blk.id) return null;
    const st = ensureTextStyle(blk);
    if (st.fontSize) {
      const direct = parseInt(st.fontSize, 10);
      if (Number.isFinite(direct) && direct > 0) return direct;
    }
    const blockEl = canvas.querySelector(`.block[data-bid="${CSS.escape(blk.id)}"]`);
    const previewEl = blockEl?.querySelector('.nx-block-preview');
    if (!previewEl) return null;

    const candidates = previewEl.querySelectorAll('[style*="font-size"], h1, h2, h3, h4, h5, h6, p, li, div, span, a, strong, em');
    let target = null;
    for (const el of candidates) {
      if ((el.textContent || '').trim()) {
        target = el;
        break;
      }
    }
    if (!target) target = previewEl;

    const computed = window.getComputedStyle(target).fontSize || '';
    const px = parseInt(computed, 10);
    return Number.isFinite(px) && px > 0 ? px : null;
  }

  function syncToolbarFromSelection() {
    if (textToolbar) textToolbar.style.display = 'flex';

    if (revisionsView && revisionsView.style.display !== 'none') {
      return;
    }

    const blk = getSelectedBlock();
    if (!blk || !isTextLike(blk)) {
      // Clear state when nothing text-like is selected but keep bar visible
      document.querySelectorAll('.nx-toolbtn[data-tcmd]').forEach(b => b.classList.remove('active'));
      if (tFontFamily) tFontFamily.value = '';
      setToolbarFontSizeValue(null);
      const holder = document.getElementById('t_color_palette');
      if (holder) {
        holder.innerHTML = renderColorPalette('t_color_palette_inner', '#111827');
        bindColorPalette('t_color_palette_inner', (hex) => {
          applyRichCommand('foreColor', hex);
        });
      }
      return;
    }

    const st = ensureTextStyle(blk);

    if (tFontFamily) tFontFamily.value = st.fontFamily || '';
    setToolbarFontSizeValue(inferBlockFontSizePx(blk));

    const holder = document.getElementById('t_color_palette');
    if (holder) {
      holder.innerHTML = renderColorPalette('t_color_palette_inner', st.color || '#111827');
      bindColorPalette('t_color_palette_inner', (hex) => {
        applyRichCommand('foreColor', hex);
      });
    }

    document.querySelectorAll('.nx-toolbtn[data-tcmd]').forEach(b => b.classList.remove('active'));
    if (st.bold)      document.querySelector('.nx-toolbtn[data-tcmd="bold"]')?.classList.add('active');
    if (st.italic)    document.querySelector('.nx-toolbtn[data-tcmd="italic"]')?.classList.add('active');
    if (st.underline) document.querySelector('.nx-toolbtn[data-tcmd="underline"]')?.classList.add('active');
    if (st.align === 'left')   document.querySelector('.nx-toolbtn[data-tcmd="alignLeft"]')?.classList.add('active');
    if (st.align === 'center') document.querySelector('.nx-toolbtn[data-tcmd="alignCenter"]')?.classList.add('active');
    if (st.align === 'right')  document.querySelector('.nx-toolbtn[data-tcmd="alignRight"]')?.classList.add('active');
  }

  // Text toolbar buttons (bold/italic/underline/align)
  document.querySelectorAll('.nx-toolbtn[data-tcmd]').forEach(btn => {
    // Prevent toolbar focus from clearing selection
    btn.addEventListener('mousedown', (e) => {
      e.preventDefault();
      restoreTextSelection();
    });
    btn.addEventListener('click', (e) => {
      e.preventDefault();
      const cmd = btn.dataset.tcmd;
      if (!cmd) return;

      if (cmd === 'bold')      return applyInlineFormat('bold');
      if (cmd === 'italic')    return applyInlineFormat('italic');
      if (cmd === 'underline') return applyInlineFormat('underline');
      if (cmd === 'ul')        return applyList('ul');
      if (cmd === 'ol')        return applyList('ol');

      if (cmd === 'alignLeft')   return applyRichCommand('justifyLeft');
      if (cmd === 'alignCenter') return applyRichCommand('justifyCenter');
      if (cmd === 'alignRight')  return applyRichCommand('justifyRight');
    });
  });

  tFontFamily?.addEventListener('change', e => {
    const val = e.target.value || '';
    if (!val) return;
    applyRichCommand('fontName', val);
  });
  tFontSize?.addEventListener('change', e => applyFontSize(e.target.value));

  // Real hyperlink insertion uses contenteditable
  tLink?.addEventListener('mousedown', (e) => {
    e.preventDefault();

    // If CTA text (hero banner) is active, set the CTA link directly
    const active = document.activeElement;
    if (active && active.id === 'p_hb_cta') {
      const urlRaw = prompt('Enter URL for CTA (https://...)');
      if (!urlRaw) return;
      let url = urlRaw.trim();
      if (!/^https?:\/\//i.test(url) && !/^mailto:/i.test(url)) url = 'https://' + url;
      const linkInput = document.getElementById('p_hb_link');
      if (linkInput) linkInput.value = url;
      const blk = getSelectedBlock();
      if (blk) { blk.props = blk.props || {}; blk.props.ctaLink = url; persistUnsaved(); updateBlockCard(blk); }
      return;
    }

    restoreTextSelection();

    const sel = window.getSelection();
    const rangeOk = sel && sel.rangeCount > 0 && !sel.isCollapsed;
    const richFields = findVisibleRichFields();
    const target = (sel?.anchorNode && sel.anchorNode.parentElement?.closest('.nx-rich')) || document.activeElement?.closest?.('.nx-rich') || richFields[0];

    if (!rangeOk || !target || !target.contains(sel.anchorNode)) {
      alert('Highlight the text in the inspector, then click Link.');
      return;
    }

    const urlRaw = prompt('Enter URL (https://...)');
    if (!urlRaw) return;

    let url = urlRaw.trim();
    if (!/^https?:\/\//i.test(url) && !/^mailto:/i.test(url)) url = 'https://' + url;

    document.execCommand('createLink', false, url);

    const range = sel.getRangeAt(0);
    const links = new Set();
    const common = range.commonAncestorContainer.nodeType === 1
      ? range.commonAncestorContainer
      : range.commonAncestorContainer.parentElement;

    [sel.anchorNode, sel.focusNode, range.startContainer, range.endContainer].forEach((node) => {
      const el = node && node.nodeType === 1 ? node : node?.parentElement;
      const link = el?.closest?.('a');
      if (link && target.contains(link)) links.add(link);
    });
    common?.querySelectorAll?.('a').forEach((aEl) => {
      if (target.contains(aEl)) links.add(aEl);
    });

    links.forEach((a) => {
      a.setAttribute('target', '_blank');
      a.setAttribute('rel', 'noopener noreferrer');
      // Preserve existing typography; hyperlink should not force color/underline changes.
      a.style.color = 'inherit';
      a.style.textDecoration = 'inherit';
    });

    target.dispatchEvent(new Event('input', { bubbles: true }));
  });

  // Unlink
  tUnlink?.addEventListener('mousedown', (e) => {
    e.preventDefault();

    const rich = document.getElementById('p_html');
    if (!rich || !rich.isContentEditable) return;

    const sel = window.getSelection();
    if (!sel || sel.rangeCount === 0) return;

    document.execCommand('unlink', false, null);

    const blk = getSelectedBlock();
    if (blk) {
      startEditSession();
      blk.props = blk.props || {};
      blk.props.html = rich.innerHTML;
      blk.props.text = rich.innerText || '';
      persistUnsaved();
      updateBlockCard(blk);
      endEditSession();
    }
  });

  // --- Networking
  async function post(url, body) {
    const res = await fetch(url, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      credentials: 'same-origin',
      body: JSON.stringify(body)
    });
    const data = await res.json().catch(() => null);
    if (!res.ok) throw new Error(data?.error || 'Request failed');
    return data;
  }

  async function getJSON(url) {
    const res = await fetch(url, { credentials: 'same-origin' });
    const text = await res.text();

    let data = null;
    try { data = JSON.parse(text); } catch {}

    if (!res.ok) throw new Error(`HTTP ${res.status}: ${text.slice(0, 180)}`);
    if (!data || typeof data !== 'object') throw new Error(`Invalid JSON: ${text.slice(0, 180)}`);
    return data;
  }

  // --- Revisions UI
  async function loadRevisions() {
    try {
      const now = new Date();
      currentRevLine.textContent = `Current revision (unsaved state may exist). Last viewed: ${now.toLocaleString()}`;

      const data = await getJSON(`${apiRevList}?page_id=${pageId}`);
      const items = Array.isArray(data.items) ? data.items : [];

      revList.innerHTML = '';
      if (!items.length) {
        revList.innerHTML = `<div class="nx-muted">No revisions saved yet.</div>`;
        return;
      }

      items.forEach(it => {
        const row = document.createElement('div');
        row.className = 'nx-rev';

        const label = esc(it.name || `Revision #${it.id}`);
        const notePreview = it.note ? esc(String(it.note).slice(0,120)) : '<em>No notes</em>';
        const milestone = it.is_milestone ? '⭐' : '';

        row.innerHTML = `
          <div class="nx-rev-head">
            <div>
              <div style="display:flex;align-items:center;gap:6px;"><b>${label}</b> ${milestone}</div>
              <div class="nx-muted">${esc(it.created_at || '')}</div>
              <div class="nx-rev-note">${notePreview}</div>
            </div>
            <div class="nx-rev-actions">
              <button class="smallbtn" data-act="view" data-id="${it.id}" type="button">View</button>
              <button class="smallbtn" data-act="restore" data-id="${it.id}" type="button">Restore</button>
              <div class="nx-dd">
                <button class="smallbtn" data-act="menu" data-id="${it.id}" aria-label="More">⋮</button>
                <div class="nx-dd-menu">
                  <button type="button" data-act="${it.is_milestone ? 'unmark' : 'mark'}" data-id="${it.id}">${it.is_milestone ? 'Unmark milestone' : 'Mark milestone'}</button>
                  <button type="button" data-act="delete" data-id="${it.id}" style="color:#fca5a5;">Delete</button>
                </div>
              </div>
            </div>
          </div>
        `;

        row.addEventListener('click', async (e) => {
          const btn = e.target.closest('button');
          if (!btn) return;
          const act = btn.dataset.act;
          const id = parseInt(btn.dataset.id, 10);

          if (act === 'view') {
            window.open(`${shell.dataset.base}/admin/revision_view.php?id=${id}`, '_blank');
            return;
          }

          if (act === 'mark' || act === 'unmark') {
            await post(`${shell.dataset.base}/api/revisions/milestone`, { _csrf: csrf, revision_id: id, flag: act === 'mark' });
            await loadRevisions();
            return;
          }

          if (act === 'delete') {
            const ok = confirm('Delete this revision?');
            if (!ok) return;
            await post(apiRevDelete, { _csrf: csrf, revision_id: id });
            await loadRevisions();
            return;
          }

          if (act === 'restore') {
            const replace = confirm('Restore options:\nOK = Replace current page revision\nCancel = Create separate page');
            const mode = replace ? 'replace' : 'duplicate';
            const resp = await post(apiRevRestore, { _csrf: csrf, revision_id: id, mode });

            if (mode === 'replace') {
              sessionStorage.removeItem(storageKey);
              alert('Revision restored (replaced current). Reloading editor.');
              location.reload();
              return;
            } else {
              alert('Revision restored into a new page. Opening editor for new page.');
              window.location.href = `${shell.dataset.base}/admin/page_builder.php?id=${resp.new_page_id}`;
              return;
            }
          }
        });

        revList.appendChild(row);
      });
    } catch (err) {
      revList.innerHTML = `<div class="nx-muted">Failed to load revisions: ${esc(err.message)}</div>`;
    }
  }

  const quickSave = async () => {
    if (!isDirty) return;
    try {
      setSaveState('saving', 'Saving…');
      await post(apiSave, { _csrf: csrf, page_id: pageId, doc });
      markSaved();
    } catch (e) {
      setSaveState('error', 'Save failed');
      console.error(e);
    }
  };
  document.getElementById('save')?.addEventListener('click', quickSave);

  // --- Save as new revision
  const openRevModal = () => {
    if (!revModal) return;
    revModal.style.display = 'flex';
    revNameInput.value = '';
    revNoteInput.value = '';
    revMilestoneInput.checked = false;
    revNameInput.focus();
  };
  const closeRevModal = () => { if (revModal) revModal.style.display = 'none'; };

  revCancelBtn?.addEventListener('click', closeRevModal);
  revCloseBtn?.addEventListener('click', closeRevModal);
  window.addEventListener('keydown', (e) => {
    if (revModal && revModal.style.display !== 'none') {
      if (e.key === 'Escape') { closeRevModal(); }
      if ((e.metaKey || e.ctrlKey) && e.key === 'Enter') { revSaveBtn?.click(); }
    }
  });

  saveAsRevisionBtn?.addEventListener('click', () => {
    openRevModal();
  });

  revSaveBtn?.addEventListener('click', async () => {
    try {
      setSaveState('saving', 'Saving…');
      await post(apiSave, { _csrf: csrf, page_id: pageId, doc });
      await post(apiRevCreate, {
        _csrf: csrf,
        page_id: pageId,
        doc,
        name: revNameInput.value || null,
        note: revNoteInput.value || null,
        is_milestone: !!revMilestoneInput.checked
      });
      closeRevModal();
      if (revisionsView.style.display !== 'none') await loadRevisions();
      markSaved();
    } catch (e) {
      setSaveState('error', 'Save failed');
      console.error(e);
    }
  });

  const doPublish = async () => {
    try {
      if (isDirty) {
        await quickSave();
      }
      setSaveState('saving', 'Publishing…');
      await post(apiPublish, { _csrf: csrf, page_id: pageId, doc });
      setStatusBadge('published');
      setSaveState('saved', 'Published just now');
    } catch (e) {
      setSaveState('error', 'Publish failed');
      console.error(e);
    }
  };
  const doUnpublish = async () => {
    try {
      setSaveState('saving', 'Unpublishing…');
      await post(apiUnpublish, { _csrf: csrf, page_id: pageId });
      setStatusBadge('draft');
      markDirty();
    } catch (e) {
      setSaveState('error', 'Unpublish failed');
      console.error(e);
    }
  };
  publishBtn?.addEventListener('click', () => {
    if (pageStatus === 'published' && !isDirty) {
      setSaveState('saved', 'Published');
      return;
    }
    doPublish();
  });
  publishToggleBtn?.addEventListener('click', () => {
    if (pageStatus === 'published') return doUnpublish();
    return doPublish();
  });
  const openSchedule = () => {
    if (!scheduleModal || !scheduleWhen) return;
    const now = new Date();
    now.setMinutes(now.getMinutes() + 30);
    scheduleWhen.value = now.toISOString().slice(0,16);
    scheduleModal.style.display = 'flex';
  };
  const closeSchedule = () => { if (scheduleModal) scheduleModal.style.display = 'none'; };
  scheduleBtn?.addEventListener('click', (e) => { e.stopPropagation(); openSchedule(); });
  scheduleCancel?.addEventListener('click', closeSchedule);
  scheduleClose?.addEventListener('click', closeSchedule);
  scheduleSave?.addEventListener('click', async () => {
    if (!scheduleWhen?.value) return;
    try {
      await quickSave();
      setStatusBadge('scheduled');
      setSaveState('saved', `Scheduled for ${scheduleWhen.value}`);
      closeSchedule();
    } catch (err) {
      setSaveState('error', 'Schedule failed');
      console.error(err);
    }
  });

  // Dropdown toggles for save/publish
  const closeDropdowns = () => {
    saveDd?.classList.remove('open');
    publishDd?.classList.remove('open');
  };
  saveDd?.addEventListener('click', (e) => {
    e.stopPropagation();
    const isOpen = saveDd.classList.contains('open');
    closeDropdowns();
    if (!isOpen) saveDd.classList.add('open');
  });
  publishDd?.addEventListener('click', (e) => {
    e.stopPropagation();
    const isOpen = publishDd.classList.contains('open');
    closeDropdowns();
    if (!isOpen) publishDd.classList.add('open');
  });
  openRevisionsTopBtn?.addEventListener('click', (e) => {
    e.preventDefault();
    closeDropdowns();
    showRevisions();
  });
  document.addEventListener('click', closeDropdowns);
  document.addEventListener('keydown', (e) => {
    if (e.key !== 'Escape') return;
    const wasSaveOpen = !!saveDd?.classList.contains('open');
    const wasPublishOpen = !!publishDd?.classList.contains('open');
    closeDropdowns();
    if (wasSaveOpen) saveDd?.querySelector('button')?.focus();
    else if (wasPublishOpen) publishDd?.querySelector('button')?.focus();
  });

  // --- Preview (unsaved changes reflected)
  const launchPreview = async () => {
    try {
      const resp = await post(apiPreviewToken, { _csrf: csrf, page_id: pageId, doc });
      const token = resp.token;
      const url = `${previewUrlBase}?preview_token=${encodeURIComponent(token)}`;
      window.location.href = url;
    } catch (err) {
      setSaveState('error', 'Preview failed');
      console.error(err);
    }
  };
  previewBtn?.addEventListener('click', (e) => {
    e.preventDefault();
    launchPreview();
  });

  // --- Add row, undo/redo
  const handleAddRow = () => {
    pushHistory();
    doc.rows.push({ cols: [{ span: 12, blocks: [] }], styleRow: { bgEnabled: true, bgColor: '' } });
    persistUnsaved();
    render();
    updateUndoRedoButtons();
  };
  document.getElementById('addRow')?.addEventListener('click', handleAddRow);
  document.getElementById('addRowToolbar')?.addEventListener('click', handleAddRow);

  document.getElementById('undo')?.addEventListener('click', () => {
    if (history.length <= 1) return; // keep baseline
    future.push(JSON.stringify(doc));
    history.pop(); // drop current snapshot
    doc = normalizeDoc(JSON.parse(history[history.length - 1]));
    selected = null;
    selectedRow = null;
    persistUnsaved();
    render();
    updateUndoRedoButtons();
  });

  document.getElementById('redo')?.addEventListener('click', () => {
    if (!future.length) return;
    const next = future.pop();
    if (!next) return;
    history.push(JSON.stringify(doc));
    doc = normalizeDoc(JSON.parse(next));
    selected = null;
    selectedRow = null;
    persistUnsaved();
    render();
    updateUndoRedoButtons();
  });

  // --- Inspector editing sessions
  const editState = { pushed: false };
  function startEditSession() {
    if (!editState.pushed) {
      pushHistory();
      editState.pushed = true;
    }
  }
  function endEditSession() { editState.pushed = false; }

  // Border/shadow preset mapping (used by inspector)
  function renderInspector() {
    try {
    const blk = getSelectedBlock();

    // --- Row inspector mode
    if (!blk && selectedRow != null) {
      const row = getSelectedRow();
      if (!row) { selectedRow = null; return; }

      const rs = ensureRowStyle(row);
      inspHint.style.display = 'none';

      let html = `<div class="nx-muted" style="margin:8px 0">Row: <b>${selectedRow + 1}</b></div>`;
      html += `
        <div class="nx-sep"></div>
        <div class="nx-strong" style="margin-bottom:8px">Row background</div>

        <label class="nx-toggle" style="margin-bottom:10px">
          <input id="row_bg_enabled" type="checkbox" ${rs.bgEnabled ? 'checked' : ''}>
          <span class="nx-toggle-ui"></span>
          <span class="nx-toggle-label">Enabled</span>
        </label>

        <div id="row_bg_palette_holder" style="${rs.bgEnabled ? '' : 'opacity:.5;pointer-events:none'}">
          ${renderRowBgPalette('row_bg_palette', rs.bgColor)}
        </div>
      `;

      insp.innerHTML = html;

      document.getElementById('row_bg_enabled')?.addEventListener('change', (e) => {
        pushHistory();
        rs.bgEnabled = !!e.target.checked;
        persistUnsaved();
        render();
        renderInspector();
      });

      bindColorPalette('row_bg_palette', (hex) => {
        pushHistory();
        rs.bgColor = hex || '';
        persistUnsaved();
        render();
        renderInspector();
      });

      endEditSession();
      return;
    }

    // --- No selection
    if (!blk) {
      inspHint.style.display = 'block';
      insp.innerHTML = '';
      endEditSession();
      return;
    }

    // Preserve selection for link insertion (only if fields exist)
    ['mouseup', 'keyup', 'touchend'].forEach(evt => {
      document.getElementById('p_text')?.addEventListener(evt, saveTextSelection);
    });
    ['mouseup', 'keyup', 'touchend'].forEach(evt => {
      document.getElementById('p_body')?.addEventListener(evt, saveTextSelection);
    });
    ['mouseup', 'keyup', 'touchend'].forEach(evt => {
      document.getElementById('acc_item_body')?.addEventListener(evt, saveTextSelection);
    });
    ['mouseup', 'keyup', 'touchend'].forEach(evt => {
      document.getElementById('mcq_question')?.addEventListener(evt, saveTextSelection);
      document.getElementById('mcq_explain')?.addEventListener(evt, saveTextSelection);
    });

    inspHint.style.display = 'none';
    const p = blk.props || {};
    const inputStyle =
      `width:100%;padding:10px;border-radius:12px;border:1px solid rgba(255,255,255,.12);` +
      `background:rgba(0,0,0,.2);color:#e6eaf2`;

    const typeLabel = blk.type === 'multipleChoiceQuiz' ? 'Multiple Choice Quiz' : blk.type;
    let html = `<div class="nx-muted" style="margin:8px 0">Type: <b>${esc(typeLabel)}</b></div>`;
    if (blk.effect) html += `<div class="nx-muted" style="margin:6px 0">Effect: <b>${esc(blk.effect)}</b></div>`;

    if (blk.type === 'heading') {
      html += `
        <label class="nx-muted">Text</label><br>
        <div id="p_html" class="nx-rich" contenteditable="true" style="${inputStyle};min-height:44px;white-space:pre-wrap"></div>
        <br><br>
        <label class="nx-muted">Level (1-6)</label><br>
        <input id="p_level" type="number" min="1" max="6" value="${p.level || 2}" style="${inputStyle}">
      `;
    } else if (blk.type === 'text') {
      html += `
        <label class="nx-muted">Text</label><br>
        <div id="p_html" class="nx-rich" contenteditable="true" style="${inputStyle};min-height:160px;white-space:pre-wrap"></div>
        <br><br>
        <label class="nx-muted">Background</label>
        <div id="p_text_bg_palette" style="margin-top:6px;"></div>
      `;
    } else if (blk.type === 'image') {
      const ratio = normalizeImageRatio(p.imageRatio);
      html += `
        <label class="nx-muted">Image URL</label><br>
        <input id="p_src" value="${esc(p.src || '')}" style="${inputStyle}">
        <br><br>
        <label class="nx-muted">Upload image</label><br>
        <input id="p_src_file" type="file" accept="image/*" style="color:#e6eaf2">
        <br><br>
        <label class="nx-muted">Image ratio</label><br>
        <select id="p_img_ratio" style="${inputStyle}">
          ${IMAGE_RATIO_OPTIONS.map(opt => `<option value="${opt.value}" ${ratio === opt.value ? 'selected' : ''}>${opt.label}</option>`).join('')}
        </select>
        <br><br>
        <label class="nx-muted">Alt text</label><br>
        <input id="p_alt" value="${esc(p.alt || '')}" style="${inputStyle}">
      `;
    } else if (blk.type === 'panel') {
      html += `
        <label class="nx-muted">Image URL</label><br>
        <input id="p_image" value="${esc(p.image || '')}" style="${inputStyle}">
        <br><br>
        <label class="nx-muted">Upload image</label><br>
        <input id="p_image_file" type="file" accept="image/*" style="color:#e6eaf2">
        <br><br>
        <label class="nx-muted">Alt text</label><br>
        <input id="p_alt" value="${esc(p.alt || '')}" style="${inputStyle}">
        <br><br>
        <label class="nx-muted">Text</label><br>
        <div id="p_html" class="nx-rich" contenteditable="true" style="${inputStyle};min-height:140px;white-space:pre-wrap"></div>
        <br><br>
        <label class="nx-muted">Layout</label><br>
        <select id="p_layout" style="${inputStyle}">
          <option value="img-left" ${p.layout === 'img-left' ? 'selected' : ''}>Image left / Text right</option>
          <option value="img-right" ${p.layout === 'img-right' ? 'selected' : ''}>Image right / Text left</option>
          <option value="img-top" ${p.layout === 'img-top' ? 'selected' : ''}>Image top / Text bottom</option>
          <option value="text-top" ${p.layout === 'text-top' ? 'selected' : ''}>Text top / Image bottom</option>
        </select>
        <br><br>
        <div id="panel_split_wrap" style="${(p.layout === 'img-left' || p.layout === 'img-right') ? '' : 'opacity:.4;pointer-events:none'}">
          <label class="nx-muted">Split ratio</label><br>
          <select id="p_split_ratio" style="${inputStyle}">
            <option value="30-70" ${p.splitRatio === '30-70' ? 'selected' : ''}>30 / 70</option>
            <option value="40-60" ${p.splitRatio === '40-60' ? 'selected' : ''}>40 / 60</option>
            <option value="50-50" ${p.splitRatio === '50-50' ? 'selected' : ''}>50 / 50</option>
            <option value="60-40" ${p.splitRatio === '60-40' ? 'selected' : ''}>60 / 40</option>
            <option value="70-30" ${p.splitRatio === '70-30' ? 'selected' : ''}>70 / 30</option>
          </select>
        </div>
      `;
    } else if (blk.type === 'testimonial') {
      html += `
        <label class="nx-muted">Quote text</label><br>
        <div id="p_html" class="nx-rich" contenteditable="true" style="${inputStyle};min-height:80px;white-space:pre-wrap"></div>
      `;
    } else if (blk.type === 'download') {
      html += `
        <label class="nx-muted">Button text</label><br>
        <input id="p_dl_label" value="${esc(p.label || 'Download')}" style="${inputStyle}">
        <br><br>
        <label class="nx-muted">Link URL</label><br>
        <input id="p_dl_url" value="${esc(p.url || '')}" style="${inputStyle}">
        <br><br>
        <label class="nx-muted">Upload file</label><br>
        <input id="p_dl_file" type="file" style="color:#e6eaf2">
      `;
    } else if (blk.type === 'heroBanner') {
      html += `
        <label class="nx-muted">Heading</label><br>
        <input id="p_hb_heading" value="${esc(p.heading || '')}" style="${inputStyle}">
        <br><br>
        <label class="nx-muted">CTA text</label><br>
        <div id="p_hb_cta_rich" class="nx-rich" contenteditable="true" style="${inputStyle};min-height:60px;white-space:pre-wrap">${esc(p.cta || '')}</div>
        <br><br>
        <label class="nx-muted">Background image URL</label><br>
        <input id="p_hb_bg" value="${esc(p.bgImage || '')}" style="${inputStyle}">
        <div style="display:flex;align-items:center;gap:8px;margin:8px 0 14px;">
          <label for="p_hb_bg_file" class="btn small" style="margin:0;">Browse…</label>
          <input id="p_hb_bg_file" type="file" accept="image/*" style="display:none">
          <button type="button" class="btn small" id="p_hb_bg_clear">Remove</button>
        </div>
        <label class="nx-muted">Overlay opacity (0-1)</label><br>
        <input id="p_hb_overlay" type="number" min="0" max="1" step="0.05" value="${p.overlayOpacity ?? 0.6}" style="${inputStyle}">
      `;
    } else if (blk.type === 'table') {
      ensureTableProps(blk);
      const rows = blk.props.data.length;
      const cols = Math.max(...blk.props.data.map(r => r.length), 1);
      html += `
        <div style="display:flex;gap:12px;flex-wrap:wrap;margin-bottom:10px">
          <div class="nx-muted">Rows: <b>${rows}</b></div>
          <div class="nx-muted">Columns: <b>${cols}</b></div>
        </div>
        <div style="display:grid;gap:10px;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));align-items:center">
          <label class="nx-muted">Density
            <select id="p_tbl_density" style="${inputStyle}">
              <option value="compact" ${p.density==='compact'?'selected':''}>Compact</option>
              <option value="default" ${p.density==='default'?'selected':''}>Default</option>
              <option value="spacious" ${p.density==='spacious'?'selected':''}>Spacious</option>
            </select>
          </label>
          <label class="nx-muted">Row styling
            <select id="p_tbl_rowstyle" style="${inputStyle}">
              <option value="none" ${p.rowStyle==='none'?'selected':''}>None</option>
              <option value="striped" ${p.rowStyle==='striped'?'selected':''}>Striped</option>
            </select>
          </label>
          <label class="nx-muted">Grid lines
            <select id="p_tbl_grid" style="${inputStyle}">
              <option value="none" ${p.gridLines==='none'?'selected':''}>None</option>
              <option value="subtle" ${p.gridLines==='subtle'?'selected':''}>Subtle</option>
              <option value="full" ${p.gridLines==='full'?'selected':''}>Full</option>
            </select>
          </label>
          <label class="nx-muted">Text align
            <select id="p_tbl_align" style="${inputStyle}">
              <option value="left" ${p.textAlign==='left'?'selected':''}>Left</option>
              <option value="center" ${p.textAlign==='center'?'selected':''}>Center</option>
              <option value="right" ${p.textAlign==='right'?'selected':''}>Right</option>
            </select>
          </label>
          <label class="nx-muted">Vertical align
            <select id="p_tbl_valign" style="${inputStyle}">
              <option value="top" ${p.vAlign==='top'?'selected':''}>Top</option>
              <option value="middle" ${p.vAlign==='middle'?'selected':''}>Middle</option>
              <option value="bottom" ${p.vAlign==='bottom'?'selected':''}>Bottom</option>
            </select>
          </label>
        </div>
        <div style="display:flex;gap:16px;flex-wrap:wrap;margin:12px 0">
          <label class="nx-toggle">
            <input id="p_tbl_headerrow" type="checkbox" ${p.headerRow ? 'checked' : ''}>
            <span class="nx-toggle-ui"></span>
            <span class="nx-toggle-label">Header row</span>
          </label>
          <label class="nx-toggle">
            <input id="p_tbl_headercol" type="checkbox" ${p.headerCol ? 'checked' : ''}>
            <span class="nx-toggle-ui"></span>
            <span class="nx-toggle-label">Header column</span>
          </label>
          <label class="nx-toggle">
            <input id="p_tbl_resize" type="checkbox" ${p.colResize !== false ? 'checked' : ''}>
            <span class="nx-toggle-ui"></span>
            <span class="nx-toggle-label">Allow column resize</span>
          </label>
        </div>
      `;
    } else if (blk.type === 'video') {
      html += `
        <label class="nx-muted">Embed URL (iframe src)</label><br>
        <input id="p_url" value="${esc(p.url || '')}" style="${inputStyle}">
        <br><br>
        <label class="nx-muted">Upload video</label><br>
        <input id="p_url_file" type="file" accept="video/*" style="color:#e6eaf2">
      `;
    } else if (blk.type === 'dragWords') {
      const ansList = Array.isArray(p.answers) ? p.answers.join(', ') : '';
      const poolList = Array.isArray(p.pool) ? p.pool.join(', ') : '';
      html += `
        <label class="nx-muted">Instruction</label><br>
        <input id="p_dw_instruction" value="${esc(p.instruction || '')}" style="${inputStyle}">
        <br><br>
        <label class="nx-muted">Sentence (use {1}, {2}, … for blanks)</label><br>
        <input id="p_dw_sentence" value="${esc(p.sentence || '')}" style="${inputStyle}">
        <br><br>
        <label class="nx-muted">Answers (comma separated, order matches blanks)</label><br>
        <input id="p_dw_answers" value="${esc(ansList)}" style="${inputStyle}">
        <br><br>
        <label class="nx-muted">Word bank (comma separated)</label><br>
        <input id="p_dw_pool" value="${esc(poolList)}" style="${inputStyle}">
        <br><br>
        <label class="nx-muted">Feedback (all correct)</label><br>
        <input id="p_dw_fb_ok" value="${esc(p.feedbackCorrect || '')}" style="${inputStyle}">
        <br><br>
        <label class="nx-muted">Feedback (not all correct)</label><br>
        <input id="p_dw_fb_not" value="${esc(p.feedbackPartial || '')}" style="${inputStyle}">
        <br><br>
        <label class="nx-muted">Buttons</label><br>
        <div style="display:flex;gap:8px;flex-wrap:wrap">
          <input id="p_dw_check_label" value="${esc(p.checkLabel || 'Check answers')}" style="${inputStyle};flex:1;min-width:160px" placeholder="Check label">
          <input id="p_dw_reset_label" value="${esc(p.resetLabel || 'Reset')}" style="${inputStyle};flex:1;min-width:160px" placeholder="Reset label">
        </div>
      `;
    } else if (blk.type === 'flipCard') {
      html += `
        <label class="nx-muted">Front title</label><br>
        <input id="p_fc_front_title" value="${esc(p.frontTitle || '')}" style="${inputStyle}">
        <br><br>
        <label class="nx-muted">Front body</label><br>
        <div id="p_fc_front_body" class="nx-rich" contenteditable="true" style="${inputStyle};min-height:120px;white-space:pre-wrap"></div>
        <br><br>
        <label class="nx-muted">Front image URL (optional)</label><br>
        <input id="p_fc_front_img" value="${esc(p.frontImage || '')}" style="${inputStyle}">
        <br><br>
        <label class="nx-muted">Back title</label><br>
        <input id="p_fc_back_title" value="${esc(p.backTitle || '')}" style="${inputStyle}">
        <br><br>
        <label class="nx-muted">Back body</label><br>
        <div id="p_fc_back_body" class="nx-rich" contenteditable="true" style="${inputStyle};min-height:120px;white-space:pre-wrap"></div>
        <br><br>
        <label class="nx-muted">Back image URL (optional)</label><br>
        <input id="p_fc_back_img" value="${esc(p.backImage || '')}" style="${inputStyle}">
        <br><br>
        <label class="nx-muted">Button labels</label>
        <div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:6px">
          <input id="p_fc_turn" value="${esc(p.turnLabel || 'Turn')}" style="${inputStyle};flex:1;min-width:140px">
          <input id="p_fc_turnback" value="${esc(p.turnBackLabel || 'Turn back')}" style="${inputStyle};flex:1;min-width:140px">
        </div>
      `;
    } else if (blk.type === 'trueFalse') {
      const isTrue = (p.correct || 'true') === 'true';
      html += `
        <label class="nx-muted">Question</label><br>
        <div id="p_tf_question" class="nx-rich" contenteditable="true" style="${inputStyle};min-height:100px;white-space:pre-wrap"></div>
        <br><br>
        <label class="nx-muted">Button labels</label>
        <div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:6px">
          <input id="p_tf_true_lbl" value="${esc(p.trueLabel || 'True')}" style="${inputStyle};flex:1;min-width:120px">
          <input id="p_tf_false_lbl" value="${esc(p.falseLabel || 'False')}" style="${inputStyle};flex:1;min-width:120px">
        </div>
        <br>
        <label class="nx-muted">Correct answer</label><br>
        <select id="p_tf_correct" class="nx-toolsel" style="width:100%">
          <option value="true" ${isTrue ? 'selected' : ''}>True</option>
          <option value="false" ${!isTrue ? 'selected' : ''}>False</option>
        </select>
        <br><br>
        <label class="nx-muted">Feedback (correct)</label><br>
        <input id="p_tf_fb_ok" value="${esc(p.feedbackCorrect || '')}" style="${inputStyle}">
        <br><br>
        <label class="nx-muted">Feedback (wrong)</label><br>
        <input id="p_tf_fb_wrong" value="${esc(p.feedbackWrong || '')}" style="${inputStyle}">
      `;
    } else if (blk.type === 'multipleChoiceQuiz') {
      const mcq = ensureMCQProps(blk);
      const spacingSel = ['compact','default','spacious'].map(opt => `<option value="${opt}" ${mcq.spacing===opt?'selected':''}>${opt[0].toUpperCase()}${opt.slice(1)}</option>`).join('');
      html += `
        <div class="nx-strong" style="margin-bottom:8px">Questions</div>
        <div id="mcq_questions" style="display:flex;flex-direction:column;gap:8px">
          ${mcq.questions.map((q, idx) => `
            <div class="mcq-q-row" data-qid="${q.id}" draggable="true" style="border:1px solid rgba(255,255,255,.12);border-radius:10px;padding:8px;">
              <div class="mcq-q-header" style="display:flex;align-items:center;gap:8px;cursor:pointer;">
                <span class="nx-drag-handle" aria-hidden="true">⋮⋮</span>
                <div style="flex:1;">
                  <div class="mcq-q-title">Question ${idx + 1}</div>
                  <div class="mcq-q-sub nx-muted" style="font-size:12px;line-height:1.4;"></div>
                </div>
                <button type="button" class="smallbtn" data-act="toggle">▼</button>
                <button type="button" class="smallbtn" data-act="delq" ${mcq.questions.length<=1?'disabled':''}>Remove</button>
              </div>
              <div class="mcq-q-body" style="display:none;padding:8px 4px;">
                <label class="nx-muted">Question</label>
                <div id="mcq_question_${q.id}" class="nx-rich" contenteditable="true" style="${inputStyle};min-height:120px;white-space:pre-wrap">${q.questionHtml || q.questionText || ''}</div>
                <br>
                <label class="nx-toggle" style="display:inline-flex;align-items:center;gap:8px;margin-bottom:8px">
                  <input id="mcq_show_image_${q.id}" type="checkbox" ${q.showImage ? 'checked' : ''}>
                  <span class="nx-toggle-ui"></span>
                  <span class="nx-toggle-label">Show image</span>
                </label>
                <div id="mcq_media_wrap_${q.id}" style="${q.showImage ? '' : 'display:none'}">
                  <input id="mcq_img_${q.id}" value="${esc(q.image)}" style="${inputStyle}" placeholder="Image URL">
                  <br><br>
                  <input id="mcq_img_alt_${q.id}" value="${esc(q.imageAlt)}" style="${inputStyle}" placeholder="Alt text">
                </div>
                <div style="margin-top:10px">
                  <div class="nx-strong" style="margin-bottom:6px">Options</div>
                  <div id="mcq_opts_list_${q.id}" style="display:flex;flex-direction:column;gap:6px;">
                    ${q.options.map((opt, oIdx) => `
                      <div class="nx-acc-itemrow" data-idx="${oIdx}" draggable="true">
                        <span class="nx-drag-handle" aria-hidden="true">⋮⋮</span>
                        <input class="nx-inp" data-role="opt-label" data-idx="${oIdx}" value="${esc(opt.label || '')}" placeholder="Option ${oIdx + 1}" style="flex:1;min-width:140px">
                        <label style="display:flex;align-items:center;gap:6px;">
                          <input type="radio" name="mcq_correct_${q.id}" data-idx="${oIdx}" ${opt.correct ? 'checked' : ''}>
                          <span class="nx-muted">Correct</span>
                        </label>
                        <button class="smallbtn" type="button" data-act="del" data-idx="${oIdx}" ${q.options.length<=2?'disabled':''}>✕</button>
                      </div>
                    `).join('')}
                  </div>
                  <button class="smallbtn" type="button" id="mcq_add_opt_${q.id}" style="margin-top:6px;">Add option</button>
                </div>
              </div>
            </div>
          `).join('')}
        </div>
        <div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:8px">
          <button class="smallbtn" id="mcq_add_question" type="button">Add question</button>
        </div>

        <div class="nx-sep"></div>
        <div class="nx-strong" style="margin-bottom:8px">Behaviour</div>
        <label class="nx-toggle" style="display:inline-flex;align-items:center;gap:8px;margin-bottom:8px">
          <input id="mcq_shuffle" type="checkbox" ${mcq.shuffle ? 'checked' : ''}>
          <span class="nx-toggle-ui"></span>
          <span class="nx-toggle-label">Shuffle options</span>
        </label>
        <br>
        <label class="nx-muted">Attempts</label>
        <select id="mcq_attempts" class="nx-toolsel" style="width:100%;margin-top:6px">
          <option value="0" ${mcq.maxAttempts===0?'selected':''}>Unlimited</option>
          ${[1,2,3,4,5].map(n => `<option value="${n}" ${mcq.maxAttempts===n?'selected':''}>${n}</option>`).join('')}
        </select>
        <br><br>
        <label class="nx-toggle" style="display:inline-flex;align-items:center;gap:8px;margin-bottom:8px">
          <input id="mcq_require" type="checkbox" ${mcq.requireAnswer ? 'checked' : ''}>
          <span class="nx-toggle-ui"></span>
          <span class="nx-toggle-label">Require answer before submit</span>
        </label>
        <label class="nx-toggle" style="display:inline-flex;align-items:center;gap:8px;margin-bottom:8px">
          <input id="mcq_reset" type="checkbox" ${mcq.showReset ? 'checked' : ''}>
          <span class="nx-toggle-ui"></span>
          <span class="nx-toggle-label">Show reset button</span>
        </label>
        <label class="nx-toggle" style="display:inline-flex;align-items:center;gap:8px;margin-bottom:8px">
          <input id="mcq_reset_correct" type="checkbox" ${mcq.allowResetOnCorrect ? 'checked' : ''}>
          <span class="nx-toggle-ui"></span>
          <span class="nx-toggle-label">Allow reset after correct</span>
        </label>
        <br>
        <label class="nx-muted">Feedback timing</label>
        <select id="mcq_timing" class="nx-toolsel" style="width:100%;margin-top:6px">
          <option value="submit" ${mcq.feedbackTiming==='submit'?'selected':''}>On submit</option>
          <option value="onSelect" ${mcq.feedbackTiming==='onSelect'?'selected':''}>On selection</option>
        </select>
        <br><br>
        <label class="nx-toggle" style="display:inline-flex;align-items:center;gap:8px;margin-bottom:8px">
          <input id="mcq_show_fb" type="checkbox" ${mcq.showFeedback ? 'checked' : ''}>
          <span class="nx-toggle-ui"></span>
          <span class="nx-toggle-label">Show feedback</span>
        </label>
        <br>
        <label class="nx-muted">Explanation visibility</label>
        <select id="mcq_exp_mode" class="nx-toolsel" style="width:100%;margin-top:6px">
          <option value="submit" ${mcq.showExplanationAfter==='submit'?'selected':''}>Always after submit</option>
          <option value="incorrectOnly" ${mcq.showExplanationAfter==='incorrectOnly'?'selected':''}>Only after incorrect</option>
        </select>

        <div class="nx-sep"></div>
        <div class="nx-strong" style="margin-bottom:8px">Feedback content</div>
        <label class="nx-muted">Correct message</label>
        <input id="mcq_msg_ok" value="${esc(mcq.correctMsg)}" style="${inputStyle}">
        <br><br>
        <label class="nx-muted">Incorrect message</label>
        <input id="mcq_msg_bad" value="${esc(mcq.incorrectMsg)}" style="${inputStyle}">
        <br><br>
        <label class="nx-muted">Explanation (optional)</label>
        <div id="mcq_explain_global" class="nx-rich" contenteditable="true" style="${inputStyle};min-height:120px;white-space:pre-wrap">${mcq.explanationHtml || mcq.explanationText || ''}</div>

        <div class="nx-sep"></div>
        <div class="nx-strong" style="margin-bottom:8px">Style</div>
        <label class="nx-muted">Preset</label>
        <select id="mcq_style" class="nx-toolsel" style="width:100%;margin-top:6px">
          ${['default','minimal','bordered'].map(opt => `<option value="${opt}" ${mcq.styleVariant===opt?'selected':''}>${opt[0].toUpperCase()}${opt.slice(1)}</option>`).join('')}
        </select>
        <br><br>
        <label class="nx-toggle" style="display:inline-flex;align-items:center;gap:8px;margin-bottom:8px">
          <input id="mcq_border" type="checkbox" ${mcq.showBorder ? 'checked' : ''}>
          <span class="nx-toggle-ui"></span>
          <span class="nx-toggle-label">Show border</span>
        </label>
        <br>
        <label class="nx-muted">Spacing</label>
        <select id="mcq_spacing" class="nx-toolsel" style="width:100%;margin-top:6px">
          ${spacingSel}
        </select>
        <br><br>
        <div class="nx-muted" style="margin-bottom:6px">Colors (optional)</div>
        <input id="mcq_accent" value="${esc(mcq.accentColor)}" placeholder="Accent color" style="${inputStyle}">
        <input id="mcq_correct_color" value="${esc(mcq.correctColor)}" placeholder="Correct color" style="${inputStyle}">
        <input id="mcq_incorrect_color" value="${esc(mcq.incorrectColor)}" placeholder="Incorrect color" style="${inputStyle}">
      `;
    } else if (blk.type === 'accordionTabs' || blk.type === 'accordion') {
      const acc = ensureAccordionTabsProps(blk);
      const items = acc.items || [];
      const maxTabs = ACCORDION_TABS_MAX;
      const tabCap = Math.max(1, Math.min(items.length, maxTabs));
      acc.tabsIndex = Math.min(acc.tabsIndex, tabCap - 1);
      const renderLen = acc.mode === 'tabs' ? tabCap : items.length;
      const activeIdx = getAccordionFocus(blk.id, renderLen);
      const activeItem = items[activeIdx] || items[0];
      const itemOptions = items.map((it,i) => `<option value="${i}" ${acc.defaultIndex===i ? 'selected' : ''}>Item ${i + 1}</option>`).join('');
      const tabOptions = items.slice(0, maxTabs).map((it,i) => `<option value="${i}" ${acc.tabsIndex===i ? 'selected' : ''}>Item ${i + 1}</option>`).join('');
      const titleForBtn = (t, i) => esc(t || `Item ${i + 1}`);
      const atTabLimit = acc.mode === 'tabs' && items.length >= maxTabs;
      const tabLimitNote = acc.mode === 'tabs'
        ? `<div class="nx-muted" style="margin-top:6px">Tabs span the full width and show up to ${maxTabs} items. Reorder to choose which appear.</div>`
        : '';

      html += `
        <div class="nx-strong" style="margin-bottom:8px">Items</div>
        <div id="acc_items_list">
          ${items.map((it, idx) => `
            <div class="nx-acc-itemrow" data-idx="${idx}" draggable="true">
              <span class="nx-drag-handle" aria-hidden="true">⋮⋮</span>
              <button type="button" class="nx-itemchip ${idx === activeIdx ? 'active' : ''}" data-idx="${idx}" aria-label="Edit item ${idx + 1}">
                ${titleForBtn(it.title, idx)}
              </button>
              <button class="smallbtn" type="button" data-act="del" data-idx="${idx}" ${items.length === 1 ? 'disabled' : ''}>Remove</button>
            </div>
          `).join('')}
        </div>
        <div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:10px">
          <button class="smallbtn" id="acc_add_item" type="button" ${atTabLimit ? 'disabled' : ''} ${atTabLimit ? `title="Tabs are limited to ${maxTabs} items"` : ''}>Add item</button>
        </div>
        ${tabLimitNote}

        <div class="nx-sep"></div>
        <div class="nx-strong" style="margin-bottom:8px">Mode</div>
        <div class="nx-mode-toggle">
          <button class="smallbtn ${acc.mode==='accordion'?'active':''}" id="acc_mode_acc">
            <span>Accordion</span>
            <span class="mode-current">Current</span>
          </button>
          <button class="smallbtn ${acc.mode==='tabs'?'active':''}" id="acc_mode_tabs">
            <span>Tabs</span>
            <span class="mode-current">Current</span>
          </button>
        </div>

        <div class="nx-strong" style="margin-bottom:8px">Item content</div>
        <label class="nx-muted">Title</label><br>
        <input id="acc_item_title" value="${esc(activeItem?.title || '')}" style="${inputStyle}">
        <br><br>
        <label class="nx-toggle" style="display:inline-flex;align-items:center;gap:8px;margin-bottom:8px">
          <input id="acc_item_showimg" type="checkbox" ${activeItem?.showHeaderImg ? 'checked' : ''}>
          <span class="nx-toggle-ui"></span>
          <span class="nx-toggle-label">Show header image</span>
        </label>
        <div id="acc_item_img_wrap" style="${activeItem?.showHeaderImg ? '' : 'display:none'}">
          <label class="nx-muted">Header image URL</label><br>
          <input id="acc_item_img" value="${esc(activeItem?.headerImg || '')}" style="${inputStyle}" placeholder="https://...">
          <br><br>
          <label class="nx-muted">Alt text</label><br>
          <input id="acc_item_alt" value="${esc(activeItem?.headerAlt || '')}" style="${inputStyle}">
        </div>
        <br>
        <label class="nx-muted">Body</label><br>
        <div id="acc_item_body" class="nx-rich" contenteditable="true" style="${inputStyle};min-height:160px;white-space:pre-wrap"></div>
        <br><br>
        <label class="nx-toggle" style="display:inline-flex;align-items:center;gap:8px">
          <input id="acc_item_open" type="checkbox" ${activeItem?.openDefault ? 'checked' : ''}>
          <span class="nx-toggle-ui"></span>
          <span class="nx-toggle-label">Open by default (overrides global)</span>
        </label>

        <div class="nx-sep"></div>
        <div class="nx-strong" style="margin-bottom:8px">Behaviour</div>
        <div id="acc_behaviour_acc" style="${acc.mode === 'accordion' ? '' : 'display:none'}">
          <label class="nx-toggle" style="display:inline-flex;align-items:center;gap:8px;margin-bottom:8px">
            <input id="acc_allow_multi" type="checkbox" ${acc.allowMultiple ? 'checked' : ''}>
            <span class="nx-toggle-ui"></span>
            <span class="nx-toggle-label">Allow multiple open items</span>
          </label>
          <label class="nx-toggle" style="display:inline-flex;align-items:center;gap:8px;margin-bottom:10px">
            <input id="acc_allow_collapse" type="checkbox" ${acc.allowCollapseAll ? 'checked' : ''}>
            <span class="nx-toggle-ui"></span>
            <span class="nx-toggle-label">Allow collapse all</span>
          </label>
          <label class="nx-muted">Default open item</label>
          <select id="acc_default_open" class="nx-toolsel" style="width:100%;margin-top:6px">
            <option value="none" ${acc.defaultOpen === 'none' ? 'selected' : ''}>None</option>
            <option value="first" ${acc.defaultOpen === 'first' ? 'selected' : ''}>First item</option>
            <option value="custom" ${acc.defaultOpen === 'custom' ? 'selected' : ''}>Custom item</option>
          </select>
          <div id="acc_default_custom" style="margin-top:8px;${acc.defaultOpen === 'custom' ? '' : 'display:none'}">
            <select id="acc_default_index" class="nx-toolsel" style="width:100%">
              ${itemOptions}
            </select>
          </div>
        </div>
        <div id="acc_behaviour_tabs" style="${acc.mode === 'tabs' ? '' : 'display:none'}">
          <label class="nx-muted">Default active tab</label>
          <select id="acc_tabs_default" class="nx-toolsel" style="width:100%;margin-top:6px">
            <option value="first" ${acc.tabsDefault === 'first' ? 'selected' : ''}>First</option>
            <option value="custom" ${acc.tabsDefault === 'custom' ? 'selected' : ''}>Custom</option>
          </select>
          <div id="acc_tabs_custom" style="margin-top:8px;${acc.tabsDefault === 'custom' ? '' : 'display:none'}">
            <select id="acc_tabs_index" class="nx-toolsel" style="width:100%">
              ${tabOptions}
            </select>
          </div>
          <br>
          <label class="nx-muted">Tab alignment</label>
          <select id="acc_tabs_align" class="nx-toolsel" style="width:100%;margin-top:6px">
            ${['left','center','right'].map(opt => `<option value="${opt}" ${acc.tabsAlign===opt?'selected':''}>${opt[0].toUpperCase()}${opt.slice(1)}</option>`).join('')}
          </select>
          <br><br>
          <label class="nx-muted">Tabs style</label>
          <select id="acc_tabs_style" class="nx-toolsel" style="width:100%;margin-top:6px">
            ${['underline','pills','segmented'].map(opt => `<option value="${opt}" ${acc.tabsStyle===opt?'selected':''}>${opt[0].toUpperCase()}${opt.slice(1)}</option>`).join('')}
          </select>
        </div>

        <div class="nx-sep"></div>
        <div class="nx-strong" style="margin-bottom:8px">Style</div>
        <label class="nx-muted">Component style</label>
        <select id="acc_style" class="nx-toolsel" style="width:100%;margin-top:6px">
          ${['default','minimal','bordered'].map(opt => `<option value="${opt}" ${acc.styleVariant === opt ? 'selected' : ''}>${opt[0].toUpperCase()}${opt.slice(1)}</option>`).join('')}
        </select>
        <br><br>
        <label class="nx-toggle" style="display:inline-flex;align-items:center;gap:8px;margin-bottom:10px">
          <input id="acc_show_border" type="checkbox" ${acc.showBorder ? 'checked' : ''}>
          <span class="nx-toggle-ui"></span>
          <span class="nx-toggle-label">Show border</span>
        </label>
        <label class="nx-toggle" style="display:inline-flex;align-items:center;gap:8px;margin-bottom:10px">
          <input id="acc_show_dividers" type="checkbox" ${acc.showDividers ? 'checked' : ''}>
          <span class="nx-toggle-ui"></span>
          <span class="nx-toggle-label">Show dividers</span>
        </label>
        <label class="nx-muted">Chevron / indicator (accordion)</label>
        <select id="acc_indicator" class="nx-toolsel" style="width:100%;margin-top:6px">
          <option value="right" ${acc.showIndicator && acc.indicatorPosition === 'right' ? 'selected' : ''}>Show (right)</option>
          <option value="left" ${acc.showIndicator && acc.indicatorPosition === 'left' ? 'selected' : ''}>Show (left)</option>
          <option value="none" ${!acc.showIndicator ? 'selected' : ''}>Hide</option>
        </select>
        <br><br>
        <label class="nx-muted">Header image position</label>
        <select id="acc_img_pos" class="nx-toolsel" style="width:100%;margin-top:6px">
          <option value="left" ${acc.headerImgPos==='left'?'selected':''}>Left</option>
          <option value="right" ${acc.headerImgPos==='right'?'selected':''}>Right</option>
        </select>
        <br><br>
        <label class="nx-muted">Header image size</label>
        <select id="acc_img_size" class="nx-toolsel" style="width:100%;margin-top:6px">
          <option value="small" ${acc.headerImgSize==='small'?'selected':''}>Small</option>
          <option value="medium" ${acc.headerImgSize==='medium'?'selected':''}>Medium</option>
          <option value="large" ${acc.headerImgSize==='large'?'selected':''}>Large</option>
        </select>
        <br><br>
        <label class="nx-muted">Spacing</label>
        <select id="acc_spacing" class="nx-toolsel" style="width:100%;margin-top:6px">
          <option value="compact" ${acc.spacing === 'compact' ? 'selected' : ''}>Compact</option>
          <option value="default" ${acc.spacing === 'default' ? 'selected' : ''}>Default</option>
          <option value="spacious" ${acc.spacing === 'spacious' ? 'selected' : ''}>Spacious</option>
        </select>
        <br><br>
        <div class="nx-muted" style="margin-bottom:6px">Colors (optional)</div>
        <div style="display:grid;gap:8px">
          <input id="acc_header_bg" value="${esc(acc.headerBg)}" placeholder="Header background (#hex or rgba)" style="${inputStyle}">
          <input id="acc_header_color" value="${esc(acc.headerColor)}" placeholder="Header text color" style="${inputStyle}">
          <input id="acc_active_bg" value="${esc(acc.activeHeaderBg)}" placeholder="Active header background" style="${inputStyle}">
          <input id="acc_active_color" value="${esc(acc.activeHeaderColor)}" placeholder="Active header text color" style="${inputStyle}">
          <input id="acc_body_bg" value="${esc(acc.contentBg)}" placeholder="Content background" style="${inputStyle}">
          <input id="acc_border_color" value="${esc(acc.borderColor)}" placeholder="Border / divider color" style="${inputStyle}">
        </div>
      `;
    } else if (blk.type === 'card') {
      html += `
        <label class="nx-muted">Title</label><br>
        <input id="p_title" value="${esc(p.title || '')}" style="${inputStyle}">
        <br><br>
        <label class="nx-muted">Body</label><br>
        <div id="p_html" class="nx-rich" contenteditable="true" style="${inputStyle};min-height:160px;white-space:pre-wrap"></div>
      `;
    } else if (blk.type === 'youTry') {
      html += `
        <div class="nx-strong" style="margin-bottom:6px">Select citation example</div>
        <div id="youTrySelectWrap" class="nx-muted">Loading…</div>
        <div class="nx-sep"></div>
        <details class="nx-muted" style="background:rgba(255,255,255,0.04);padding:8px 10px;border-radius:10px;border:1px solid var(--border);" ${p.body || p.title ? 'open' : ''}>
          <summary style="cursor:pointer;font-weight:700;color:var(--text)">Custom override</summary>
          <div style="margin-top:10px">
            <label class="nx-muted">Title</label><br>
            <input id="p_title" value="${esc(p.title || '')}" style="${inputStyle}">
            <br><br>
            <label class="nx-muted">Body</label><br>
            <div id="p_html" class="nx-rich" contenteditable="true" style="${inputStyle};min-height:140px;white-space:pre-wrap"></div>
            <div class="nx-muted" style="margin-top:6px">Edits here save to this page only (not the citation database).</div>
          </div>
        </details>
      `;
    } else if (blk.type === 'citationOrder') {
      html += `
        <div class="nx-strong" style="margin-bottom:6px">Select citation example</div>
        <div id="citationExampleSelect" class="nx-muted">Loading…</div>
        <div class="nx-sep"></div>
        <details class="nx-muted" style="background:rgba(255,255,255,0.04);padding:8px 10px;border-radius:10px;border:1px solid var(--border);" ${p.body || p.title ? 'open' : ''}>
          <summary style="cursor:pointer;font-weight:700;color:var(--text)">Custom override</summary>
          <div style="margin-top:10px">
            <label class="nx-muted">Title</label><br>
            <input id="p_title" value="${esc(p.title || '')}" style="${inputStyle}">
            <br><br>
            <label class="nx-muted">Body (use bullets/lines)</label><br>
            <div id="p_html" class="nx-rich" contenteditable="true" style="${inputStyle};min-height:160px;white-space:pre-wrap"></div>
            <div class="nx-muted" style="margin-top:6px">Edits here save to this page only (not the citation database).</div>
          </div>
        </details>
      `;
    } else if (blk.type === 'exampleCard') {
      const showToggle = p.showYouTry !== false;
      html += `
        <div class="nx-strong" style="margin-bottom:8px">Select an Example</div>
        <div id="exampleSelectWrap" class="nx-muted">Loading…</div>
        <div class="nx-sep"></div>
        <label class="nx-toggle" style="display:inline-flex;align-items:center;gap:10px;margin-bottom:10px">
          <input id="p_youtry_toggle" type="checkbox" ${showToggle ? 'checked' : ''}>
          <span class="nx-toggle-ui"></span>
          <span class="nx-toggle-label">You Try</span>
        </label>
      `;
    } else if (blk.type === 'carousel') {
      try {
        const slides = Array.isArray(p.slides) ? p.slides : [];
        const size = p.size || 'large';
        const show = Math.max(1, Math.min(5, parseInt(p.slidesToShow ?? 1, 10) || 1));
        const autoplay = !!p.autoplay;
        const interval = Math.max(2, parseInt(p.interval ?? 5, 10) || 5);
        const pauseOnHover = p.pauseOnHover !== false;
        const active = Math.min(Math.max(0, p.activeSlide ?? 0), Math.max(0, slides.length - 1));
        const bgPresets = [
          { key:'light', label:'Light', val:'#f8fafc' },
          { key:'sunny', label:'Soft yellow', val:'linear-gradient(135deg,#fff8d6,#fffef4)' },
          { key:'mint', label:'Soft mint', val:'linear-gradient(135deg,#f3fff6,#f7fffb)' },
          { key:'sky', label:'Soft blue', val:'linear-gradient(135deg,#f3f9ff,#ffffff)' },
          { key:'slate', label:'Dark', val:'#0f172a' }
        ];
        const advPresets = [
          { key:'sunrise', label:'Sunrise gradient', val:'linear-gradient(135deg,#ffe9d2,#fff8e5)' },
          { key:'citrus', label:'Citrus gradient', val:'linear-gradient(135deg,#fff8e1,#fdf3c4)' },
          { key:'mintfade', label:'Mint gradient', val:'linear-gradient(135deg,#e8fff5,#f7fffb)' },
          { key:'ocean', label:'Ocean fade', val:'linear-gradient(135deg,#eaf3ff,#ffffff)' }
        ];
        const bsLocal = ensureBlockStyle(blk);
        const shadowOnLocal = !!bsLocal.boxShadow && bsLocal.boxShadow !== '';
        const marginOnLocal = !!bsLocal.marginBottom;
        const bgValue = p.background || bgPresets[0].val;
        const bgKey = [...bgPresets, ...advPresets].find(x => x.val === bgValue)?.key || '';
        html += `
        <div class="nx-strong" style="margin-bottom:8px">Slides</div>
        <div style="display:flex;align-items:center;gap:8px;margin-bottom:10px;flex-wrap:wrap">
          <button class="smallbtn" type="button" id="car_prev">Prev</button>
          <div class="nx-muted" id="car_pos">Slide ${active + 1} of ${slides.length || 1}</div>
          <button class="smallbtn" type="button" id="car_next">Next</button>
          <button class="smallbtn" type="button" id="carouselAdd">Add slide</button>
          <button class="smallbtn" type="button" id="carouselRemove" style="border-color:rgba(239,68,68,.35);background:rgba(239,68,68,.12)">Remove</button>
        </div>
        <label class="nx-muted">Title</label>
        <input id="p_car_title" style="${inputStyle}">
        <br><br>
        <label class="nx-muted">Body</label>
        <div id="p_html" class="nx-rich" contenteditable="true" style="${inputStyle};min-height:140px;white-space:pre-wrap"></div>
        <br><br>
        <label class="nx-muted">Image</label>
        <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
          <input id="p_car_img" style="${inputStyle};flex:1" placeholder="https://...">
          <button class="smallbtn" type="button" id="p_car_img_replace">Replace</button>
          <button class="smallbtn" type="button" id="p_car_img_remove" style="border-color:rgba(239,68,68,.35);background:rgba(239,68,68,.12)">Remove</button>
        </div>
        <div style="margin-top:10px">
          <button class="smallbtn" type="button" id="p_car_layout_toggle">Layout options</button>
          <div id="p_car_layout_wrap" style="display:none;margin-top:8px">
            <select id="p_car_layout" class="nx-toolsel" style="width:100%">
              ${['text-left','text-right','stacked'].map(opt => `<option value="${opt}">${opt.replace('text-','Text ')}</option>`).join('')}
            </select>
          </div>
        </div>
        <div class="nx-sep"></div>
        <div class="nx-strong" style="margin:8px 0">Carousel settings</div>
        <div style="margin-bottom:12px">
          <div class="nx-strong" style="margin-bottom:6px">Layout</div>
          <label class="nx-muted">Slides visible at once</label>
          <input id="p_carousel_show" type="number" min="1" max="5" value="${show}" style="${inputStyle}">
          <br><br>
          <label class="nx-muted">Carousel size</label>
          <select id="p_carousel_size" class="nx-toolsel" style="width:100%">
            ${['small','medium','large'].map(opt => `<option value="${opt}" ${opt===size?'selected':''}>${opt}</option>`).join('')}
          </select>
        </div>
        <div style="margin-bottom:12px">
          <div class="nx-strong" style="margin-bottom:6px">Motion</div>
          <label class="nx-toggle" style="display:inline-flex;align-items:center;gap:8px;margin-bottom:10px">
            <input id="p_carousel_autoplay" type="checkbox" ${autoplay ? 'checked' : ''}>
            <span class="nx-toggle-ui"></span>
            <span class="nx-toggle-label">Auto-play</span>
          </label>
          <div id="p_carousel_timer_wrap" style="margin-left:4px; ${autoplay ? '' : 'display:none'}">
            <label class="nx-muted">Timer (seconds)</label>
            <input id="p_carousel_interval" type="number" min="2" max="30" value="${interval}" style="${inputStyle}">
            <label class="nx-toggle" style="display:inline-flex;align-items:center;gap:8px;margin-top:8px">
              <input id="p_carousel_pause" type="checkbox" ${pauseOnHover ? 'checked' : ''}>
              <span class="nx-toggle-ui"></span>
              <span class="nx-toggle-label">Pause on hover</span>
            </label>
          </div>
        </div>
        <div style="margin-bottom:12px">
          <div class="nx-strong" style="margin-bottom:6px">Appearance</div>
          <label class="nx-muted">Background</label>
          <select id="p_carousel_bg_select" class="nx-toolsel" style="width:100%">
            ${bgPresets.map(opt => `<option value="${opt.key}" ${opt.key===bgKey?'selected':''}>${opt.label}</option>`).join('')}
          </select>
          <label class="nx-toggle" style="margin-top:10px;display:inline-flex;align-items:center;gap:8px">
            <input id="blk_shadow_toggle" type="checkbox" ${shadowOnLocal ? 'checked' : ''}>
            <span class="nx-toggle-ui"></span>
            <span class="nx-toggle-label">Box shadow</span>
          </label>
          <label class="nx-toggle" style="margin-top:8px;display:inline-flex;align-items:center;gap:8px">
            <input id="blk_margin" type="checkbox" ${marginOnLocal ? 'checked' : ''}>
            <span class="nx-toggle-ui"></span>
            <span class="nx-toggle-label">Margin below</span>
          </label>
        </div>
        <details id="car_adv" style="margin-bottom:12px">
          <summary class="nx-strong" style="cursor:pointer">Advanced</summary>
          <div style="margin-top:8px">
            <label class="nx-muted">Background presets</label>
            <select id="p_carousel_bg_adv" class="nx-toolsel" style="width:100%">
              <option value="">Choose a gradient</option>
              ${advPresets.map(opt => `<option value="${opt.key}" ${opt.key===bgKey?'selected':''}>${opt.label}</option>`).join('')}
            </select>
          </div>
        </details>
      `;
      } catch (err) {
        console.error('Carousel inspector render error', err);
        html += `<div class="nx-muted">Unable to render carousel inspector.</div>`;
      }
    } else if (blk.type === 'heroCard' || blk.type === 'heroPage') {
      html += `
        <label class="nx-muted">Title</label><br>
        <input id="p_title" value="${esc(p.title || '')}" style="${inputStyle}">
        <br><br>
        <label class="nx-muted">Body</label><br>
        <div id="p_html" class="nx-rich" contenteditable="true" style="${inputStyle};min-height:180px;white-space:pre-wrap"></div>
        <br><br>
        <label class="nx-muted">Background image URL</label><br>
        <input id="p_bgImage" value="${esc(p.bgImage || '')}" style="${inputStyle}">
        <br><br>
        <label class="nx-muted">Fallback background color</label><br>
        <input id="p_bgColor" value="${esc(p.bgColor || '#111827')}" style="${inputStyle}">
        <br><br>
        <label class="nx-muted">Overlay opacity (0-0.9)</label><br>
        <input id="p_overlay" type="number" min="0" max="0.9" step="0.05" value="${p.overlayOpacity ?? 0.35}" style="${inputStyle}">
      `;
    } else if (blk.type === 'textbox') {
      html += `
        <label class="nx-muted">Label</label><br>
        <input id="p_label" value="${esc(p.label || '')}" style="${inputStyle}">
        <br><br>
        <label class="nx-muted">Placeholder</label><br>
        <input id="p_placeholder" value="${esc(p.placeholder || '')}" style="${inputStyle}">
        <br><br>
        <label class="nx-muted">Lines (1-12)</label><br>
        <input id="p_lines" type="number" min="1" max="12" value="${parseInt(p.lines ?? 3,10) || 3}" style="${inputStyle}">
        <br><br>
        <label class="nx-muted">Background</label>
        <div id="p_textbox_bg_palette" style="margin-top:6px;"></div>
      `;
    } else {
      html += `<div class="nx-muted">No editable fields.</div>`;
    }

    if (blk.type !== 'carousel') {
      // --- Block appearance (border + shadow presets)
      const bs = ensureBlockStyle(blk);
      // keep default border unless shadow is explicitly on
      if (bs.boxShadow) bs.border = 'none';
      const shadowOn = !!bs.boxShadow && bs.boxShadow !== '';

      html += `
        <div class="nx-sep"></div>
        <div class="nx-strong" style="margin-bottom:8px">Block appearance</div>

        <label class="nx-toggle" style="margin-bottom:10px;display:inline-flex;align-items:center;gap:8px">
          <input id="blk_margin" type="checkbox" ${bs.marginBottom ? 'checked' : ''}>
          <span class="nx-toggle-ui"></span>
          <span class="nx-toggle-label">Margin below</span>
        </label>

        <label class="nx-toggle" style="margin-bottom:6px;display:inline-flex;align-items:center;gap:8px">
          <input id="blk_shadow_toggle" type="checkbox" ${shadowOn ? 'checked' : ''}>
          <span class="nx-toggle-ui"></span>
          <span class="nx-toggle-label">Box shadow</span>
        </label>
      `;
    }

    html += `
      <div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:12px">
        <button class="smallbtn" id="dup" type="button">Duplicate</button>
        <button class="smallbtn" id="del" type="button"
          style="border-color:rgba(239,68,68,.35);background:rgba(239,68,68,.12)">Delete</button>
      </div>
    `;

    insp.innerHTML = html;

    const initRichField = (id, initialVal, onChange) => {
      const el = document.getElementById(id);
      if (!el) return null;

      const asHtml = String(initialVal || '')
        .replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;')
        .replace(/\n/g,'<br>');
      const initial = /<\/?[a-z][\s\S]*>/i.test(String(initialVal || ''))
        ? String(initialVal || '')
        : asHtml;
      el.innerHTML = initial;

      const handleInput = () => {
        const htmlVal = el.innerHTML;
        const textVal = el.textContent || '';
        onChange(htmlVal, textVal);
        persistUnsaved();
        updateBlockCard(blk);
      };

      el.addEventListener('focus', startEditSession, true);
      el.addEventListener('input', handleInput, true);
      el.addEventListener('blur', endEditSession, true);

      // allow tab insertion for accordion body instead of focus change
      if (id === 'acc_item_body') {
        el.addEventListener('keydown', (e) => {
          if (e.key === 'Tab') {
            e.preventDefault();
            document.execCommand('insertHTML', false, '&nbsp;&nbsp;');
            handleInput();
          }
        }, true);
      }
      return el;
    };

    // Bind block appearance sliders
    document.getElementById('blk_margin')?.addEventListener('change', (e) => {
      startEditSession();
      const bs = ensureBlockStyle(blk);
      bs.marginBottom = e.target.checked ? '18px' : '';
      persistUnsaved();
      render();
    });

    document.getElementById('blk_shadow_toggle')?.addEventListener('change', (e) => {
      startEditSession();
      const bs = ensureBlockStyle(blk);
      // Softer, more diffused shadow; no spread to avoid outline effect
      bs.boxShadow = e.target.checked ? '0 18px 48px rgba(15,23,42,.16), 0 6px 18px rgba(15,23,42,.12)' : '';
      bs.border = e.target.checked ? 'none' : '';
      persistUnsaved();
      render();
    });

    // Rich text field (p_html)
    const baseRichInitial =
      (p.html ?? '') ||
      (blk.type === 'card' ? (p.body ?? '') : (p.text ?? ''));

    initRichField('p_html', (() => {
      if (blk.type === 'youTry') return (p.html ?? '') || (p.body ?? '');
      if (blk.type === 'citationOrder') return (p.html ?? '') || (p.body ?? '');
      if (blk.type === 'exampleCard') return (p.bodyHtml ?? '') || (p.body ?? '');
      if (blk.type === 'panel') return (p.bodyHtml ?? '') || (p.body ?? '');
      if (blk.type === 'testimonial') return (p.bodyHtml ?? '') || (p.body ?? '');
      if (blk.type === 'download') return (p.bodyHtml ?? '') || (p.body ?? '');
      if (blk.type === 'heroCard' || blk.type === 'heroPage') return (p.bodyHtml ?? '') || (p.body ?? '');
      if (blk.type === 'heroBanner') return (p.bodyHtml ?? '') || (p.body ?? '');
      return baseRichInitial;
    })(), (htmlVal, textVal) => {
      blk.props = blk.props || {};
      blk.props.html = htmlVal;
      blk.props.text = textVal;
      if (blk.type === 'card') blk.props.body = textVal;
      if (blk.type === 'youTry') blk.props.body = textVal;
      if (blk.type === 'citationOrder') blk.props.body = textVal;
      if (blk.type === 'exampleCard') { blk.props.body = textVal; blk.props.bodyHtml = htmlVal; }
      if (blk.type === 'panel') { blk.props.body = textVal; blk.props.bodyHtml = htmlVal; }
      if (blk.type === 'testimonial') { blk.props.body = textVal; blk.props.bodyHtml = htmlVal; }
      if (blk.type === 'download') { blk.props.body = textVal; blk.props.bodyHtml = htmlVal; }
      if (blk.type === 'heroBanner') { blk.props.body = textVal; blk.props.bodyHtml = htmlVal; }
      if (blk.type === 'heroCard' || blk.type === 'heroPage') { blk.props.body = textVal; blk.props.bodyHtml = htmlVal; }
    });

    const setProp = (k, v) => {
      startEditSession();
      blk.props = blk.props || {};
      blk.props[k] = v;
      persistUnsaved();
      updateBlockCard(blk);
    };

    const bind = (id, key, parser) => {
      const el = document.getElementById(id);
      if (!el) return;
      el.addEventListener('focus', startEditSession);
      el.addEventListener('input', (e) => setProp(key, parser ? parser(e.target.value) : e.target.value));
      el.addEventListener('blur', endEditSession);
    };

    if (blk.type === 'heading') bind('p_level', 'level', (v) => parseInt(v || '2', 10));
    if (blk.type === 'image')  {
      bind('p_src', 'src');
      bind('p_alt', 'alt');
      bind('p_img_ratio', 'imageRatio', normalizeImageRatio);
    }
    if (blk.type === 'panel')  {
      bind('p_image', 'image');
      bind('p_alt', 'alt');
      bind('p_layout', 'layout');
      const splitWrap = document.getElementById('panel_split_wrap');
      const splitSelect = document.getElementById('p_split_ratio');
      const toggleSplit = () => {
        if (!splitWrap) return;
        const isSide = (blk.props.layout === 'img-left' || blk.props.layout === 'img-right');
        splitWrap.style.opacity = isSide ? '' : '.4';
        splitWrap.style.pointerEvents = isSide ? '' : 'none';
      };
      if (splitSelect) {
        splitSelect.addEventListener('change', (e) => {
          startEditSession();
          blk.props = blk.props || {};
          blk.props.splitRatio = e.target.value;
          persistUnsaved();
          render();
        });
      }
      const layoutSel = document.getElementById('p_layout');
      layoutSel?.addEventListener('change', toggleSplit);
      toggleSplit();
    }
    if (blk.type === 'download')  { bind('p_dl_label', 'label'); bind('p_dl_url', 'url'); }
    if (blk.type === 'heroBanner')  {
      bind('p_hb_heading', 'heading');
      bind('p_hb_bg', 'bgImage');
      bind('p_hb_overlay', 'overlayOpacity', (v) => parseFloat(v || 0.6));
    }
    if (blk.type === 'table') {
      const updateSelect = (id, key) => {
        const el = document.getElementById(id);
        if (!el) return;
        el.addEventListener('change', (e) => {
          startEditSession();
          ensureTableProps(blk);
          blk.props[key] = e.target.value;
          persistUnsaved();
          render();
        });
      };
      const updateToggle = (id, key) => {
        const el = document.getElementById(id);
        if (!el) return;
        el.addEventListener('change', (e) => {
          startEditSession();
          ensureTableProps(blk);
          blk.props[key] = !!e.target.checked;
          persistUnsaved();
          render();
        });
      };
      updateSelect('p_tbl_density', 'density');
      updateSelect('p_tbl_rowstyle', 'rowStyle');
      updateSelect('p_tbl_grid', 'gridLines');
      updateSelect('p_tbl_align', 'textAlign');
      updateSelect('p_tbl_valign', 'vAlign');
      updateToggle('p_tbl_headerrow', 'headerRow');
      updateToggle('p_tbl_headercol', 'headerCol');
      updateToggle('p_tbl_resize', 'colResize');
    }
    if (blk.type === 'video')  bind('p_url', 'url');
    const imgFile = document.getElementById('p_src_file');
    if (imgFile) {
      imgFile.addEventListener('change', (e) => {
        const file = e.target.files && e.target.files[0];
        if (!file || !file.type.startsWith('image/')) return;
        const url = URL.createObjectURL(file);
        setProp('src', url);
      });
    }
    const panelImgFile = document.getElementById('p_image_file');
    if (panelImgFile) {
      panelImgFile.addEventListener('change', (e) => {
        const file = e.target.files && e.target.files[0];
        if (!file || !file.type.startsWith('image/')) return;
        const url = URL.createObjectURL(file);
        setProp('image', url);
      });
    }
    const heroBgFile = document.getElementById('p_hb_bg_file');
    if (heroBgFile) {
      heroBgFile.addEventListener('change', (e) => {
        const file = e.target.files && e.target.files[0];
        if (!file || !file.type.startsWith('image/')) return;
        const url = URL.createObjectURL(file);
        setProp('bgImage', url);
      });
    }
    const heroBgClear = document.getElementById('p_hb_bg_clear');
    if (heroBgClear) {
      heroBgClear.addEventListener('click', () => {
        setProp('bgImage', '');
        const bgUrl = document.getElementById('p_hb_bg');
        if (bgUrl) bgUrl.value = '';
      });
    }

    const heroCtaRich = document.getElementById('p_hb_cta_rich');
    if (heroCtaRich) {
      heroCtaRich.addEventListener('focus', startEditSession);
      heroCtaRich.addEventListener('input', () => {
        blk.props = blk.props || {};
        blk.props.cta = heroCtaRich.innerText || '';
        blk.props.ctaHtml = heroCtaRich.innerHTML || '';
        persistUnsaved();
        updateBlockCard(blk);
      });
      heroCtaRich.addEventListener('blur', endEditSession);
    }
    const dlFile = document.getElementById('p_dl_file');
    if (dlFile) {
      dlFile.addEventListener('change', (e) => {
        const file = e.target.files && e.target.files[0];
        if (!file) return;
        const url = URL.createObjectURL(file);
        setProp('url', url);
      });
    }
    const vidFile = document.getElementById('p_url_file');
    if (vidFile) {
      vidFile.addEventListener('change', (e) => {
        const file = e.target.files && e.target.files[0];
        if (!file || !file.type.startsWith('video/')) return;
        const url = URL.createObjectURL(file);
        setProp('url', url);
      });
    }
    if (blk.type === 'card')   bind('p_title', 'title');
    if (blk.type === 'youTry') bind('p_title', 'title');
    if (blk.type === 'citationOrder') bind('p_title', 'title');
    if (blk.type === 'dragWords') {
      const parseList = (val) => val.split(',').map(v => v.trim()).filter(Boolean);
      bind('p_dw_instruction', 'instruction');
      bind('p_dw_sentence', 'sentence');
      bind('p_dw_answers', 'answers', (v) => parseList(v));
      bind('p_dw_pool', 'pool', (v) => parseList(v));
      bind('p_dw_fb_ok', 'feedbackCorrect');
      bind('p_dw_fb_not', 'feedbackPartial');
      bind('p_dw_check_label', 'checkLabel');
      bind('p_dw_reset_label', 'resetLabel');
    }
    if (blk.type === 'flipCard') {
      bind('p_fc_front_title', 'frontTitle');
      bind('p_fc_front_img', 'frontImage');
      bind('p_fc_back_title', 'backTitle');
      bind('p_fc_back_img', 'backImage');
      bind('p_fc_turn', 'turnLabel');
      bind('p_fc_turnback', 'turnBackLabel');

      initRichField('p_fc_front_body', (p.frontBodyHtml ?? p.frontBody ?? ''), (htmlVal, textVal) => {
        blk.props.frontBodyHtml = htmlVal;
        blk.props.frontBody = textVal;
      });
      initRichField('p_fc_back_body', (p.backBodyHtml ?? p.backBody ?? ''), (htmlVal, textVal) => {
        blk.props.backBodyHtml = htmlVal;
        blk.props.backBody = textVal;
      });
    }
    if (blk.type === 'trueFalse') {
      bind('p_tf_true_lbl', 'trueLabel');
      bind('p_tf_false_lbl', 'falseLabel');
      const select = document.getElementById('p_tf_correct');
      if (select) {
        select.addEventListener('change', (e) => setProp('correct', e.target.value === 'false' ? 'false' : 'true'));
      }
      bind('p_tf_fb_ok', 'feedbackCorrect');
      bind('p_tf_fb_wrong', 'feedbackWrong');

      initRichField('p_tf_question', (p.questionHtml ?? p.question ?? ''), (htmlVal, textVal) => {
        blk.props.questionHtml = htmlVal;
        blk.props.question = textVal;
      });
    }
    if (blk.type === 'multipleChoiceQuiz') {
      const mcq = ensureMCQProps(blk);
      const refresh = () => { persistUnsaved(); render(); };
      const refreshPreview = () => { persistUnsaved(); updateBlockCard(blk); bindMCQPreviews(); };

      const syncMCQEdits = () => {
        mcq.questions.forEach((q) => {
          const qEl = document.getElementById(`mcq_question_${q.id}`);
          if (qEl) {
            q.questionHtml = qEl.innerHTML;
            q.questionText = qEl.innerText || '';
          }
          const imgEl = document.getElementById(`mcq_img_${q.id}`);
          const altEl = document.getElementById(`mcq_img_alt_${q.id}`);
          const showEl = document.getElementById(`mcq_show_image_${q.id}`);
          if (imgEl) q.image = imgEl.value || '';
          if (altEl) q.imageAlt = altEl.value || '';
          if (showEl) q.showImage = !!showEl.checked;
          const opts = q.options || [];
          document.querySelectorAll(`#mcq_opts_list_${q.id} input[data-role="opt-label"]`).forEach(inp => {
            const idxOpt = parseInt(inp.dataset.idx, 10);
            if (Number.isInteger(idxOpt) && opts[idxOpt]) {
              opts[idxOpt].label = inp.value || '';
            }
          });
          const selected = document.querySelector(`#mcq_opts_list_${q.id} input[name="mcq_correct_${q.id}"]:checked`);
          if (selected) {
            const idxOpt = parseInt(selected.dataset.idx, 10);
            if (Number.isInteger(idxOpt) && opts[idxOpt]) {
              opts.forEach((opt,i) => opt.correct = i === idxOpt);
              q.correctOptionId = opts[idxOpt].id;
            }
          }
        });
        persistUnsaved();
      };

      const questionList = document.getElementById('mcq_questions');
      const addQuestion = document.getElementById('mcq_add_question');

      const bindQuestionRow = (q, idx) => {
        const wrap = document.querySelector(`.mcq-q-row[data-qid="${q.id}"]`);
        if (!wrap) return;
        const header = wrap.querySelector('.mcq-q-header');
        const body = wrap.querySelector('.mcq-q-body');
        const title = wrap.querySelector('.mcq-q-title');
        const removeBtn = wrap.querySelector('[data-act="delq"]');
        const expandBtn = wrap.querySelector('[data-act="toggle"]');

        const setLabel = () => {
          if (title) title.textContent = `Question ${idx + 1}`;
          const preview = wrap.querySelector('.mcq-q-sub');
          if (preview) preview.textContent = (q.questionText || q.questionHtml || '').split('\n')[0].slice(0, 60);
        };
        setLabel();

        expandBtn?.addEventListener('click', () => {
          document.querySelectorAll('.mcq-q-body').forEach(b => { if (b !== body) b.style.display = 'none'; });
          document.querySelectorAll('.mcq-q-row').forEach(r => r.classList.remove('open'));
          body.style.display = body.style.display === 'none' ? '' : 'none';
          wrap.classList.toggle('open', body.style.display !== 'none');
        });
        // default first open
        if (idx === 0) { body.style.display = ''; wrap.classList.add('open'); }

        removeBtn?.addEventListener('click', () => {
          if (mcq.questions.length <= 1) return;
          const hasContent = (q.questionText?.trim() || q.questionHtml?.trim() || q.options?.some(o => o.label?.trim()));
          if (hasContent && !confirm('Remove this question?')) return;
          startEditSession();
          const pos = mcq.questions.findIndex(qq => qq.id === q.id);
          if (pos >= 0) mcq.questions.splice(pos,1);
          persistUnsaved();
          renderInspector();
          updateBlockCard(blk);
        });
      };

      const bindQuestionContent = (q, idx) => {
        initRichField(`mcq_question_${q.id}`, (q.questionHtml ?? q.questionText ?? ''), (htmlVal, textVal) => {
          q.questionHtml = htmlVal;
          q.questionText = textVal;
          refreshPreview();
        });
        initRichField(`mcq_explain_${q.id}`, (mcq.explanationHtml ?? mcq.explanationText ?? ''), (htmlVal, textVal) => {
          blk.props.explanationHtml = htmlVal;
          blk.props.explanationText = textVal;
          refreshPreview();
        });

        const showImg = document.getElementById(`mcq_show_image_${q.id}`);
        const imgWrap = document.getElementById(`mcq_media_wrap_${q.id}`);
        showImg?.addEventListener('change', (e) => {
          startEditSession();
          q.showImage = !!e.target.checked;
          if (imgWrap) imgWrap.style.display = q.showImage ? '' : 'none';
          refreshPreview();
          endEditSession();
        });
        const bindQField = (id, key) => {
          const el = document.getElementById(id);
          el?.addEventListener('input', (e) => { q[key] = e.target.value || ''; refreshPreview(); });
        };
        bindQField(`mcq_img_${q.id}`, 'image');
        bindQField(`mcq_img_alt_${q.id}`, 'imageAlt');

        const opts = q.options;
        const renderOpts = () => {
          const list = document.getElementById(`mcq_opts_list_${q.id}`);
          if (!list) return;
          list.innerHTML = opts.map((opt, oIdx) => `
            <div class="nx-acc-itemrow" data-idx="${oIdx}" draggable="true">
              <span class="nx-drag-handle" aria-hidden="true">⋮⋮</span>
              <input class="nx-inp" data-role="opt-label" data-idx="${oIdx}" value="${esc(opt.label || '')}" placeholder="Option ${oIdx + 1}" style="flex:1;min-width:140px">
              <label style="display:flex;align-items:center;gap:6px;">
                <input type="radio" name="mcq_correct_${q.id}" data-idx="${oIdx}" ${opt.correct ? 'checked' : ''}>
                <span class="nx-muted">Correct</span>
              </label>
              <button class="smallbtn" type="button" data-act="del" data-idx="${oIdx}" ${opts.length<=2?'disabled':''}>✕</button>
            </div>
          `).join('');

          list.querySelectorAll('[data-act="del"]').forEach(btn => {
            btn.addEventListener('click', () => {
              const idxOpt = parseInt(btn.dataset.idx, 10);
              if (!Number.isInteger(idxOpt) || opts.length <= 2) return;
              startEditSession();
              const removed = opts.splice(idxOpt,1)[0];
              if (removed?.id === q.correctOptionId) q.correctOptionId = null;
              persistUnsaved();
              renderOpts();
              updateBlockCard(blk);
              bindMCQPreviews();
            });
          });
          list.querySelectorAll('input[data-role="opt-label"]').forEach(inp => {
            inp.addEventListener('input', (e) => {
              const idxOpt = parseInt(inp.dataset.idx, 10);
              if (!Number.isInteger(idxOpt) || !opts[idxOpt]) return;
              startEditSession();
              opts[idxOpt].label = e.target.value;
              refreshPreview();
            });
            inp.addEventListener('blur', endEditSession);
          });
          list.querySelectorAll(`input[name="mcq_correct_${q.id}"]`).forEach(radio => {
            radio.addEventListener('change', () => {
              const idxOpt = parseInt(radio.dataset.idx, 10);
              if (!Number.isInteger(idxOpt) || !opts[idxOpt]) return;
              startEditSession();
              opts.forEach((opt,i) => opt.correct = i === idxOpt);
              q.correctOptionId = opts[idxOpt].id;
              refreshPreview();
              endEditSession();
            });
          });
          list.querySelectorAll('.nx-acc-itemrow').forEach(row => {
            row.addEventListener('dragstart', (e) => { e.dataTransfer.setData('nx/mcqopt', row.dataset.idx || ''); });
            row.addEventListener('dragover', (e) => { e.preventDefault(); row.classList.add('dragover'); });
            row.addEventListener('dragleave', () => row.classList.remove('dragover'));
            row.addEventListener('drop', (e) => {
              e.preventDefault(); row.classList.remove('dragover');
              const from = parseInt(e.dataTransfer.getData('nx/mcqopt'), 10);
              const to = parseInt(row.dataset.idx || '-1', 10);
              if (!Number.isInteger(from) || !Number.isInteger(to) || from === to) return;
              startEditSession();
              const moved = opts.splice(from,1)[0];
              opts.splice(to,0,moved);
              persistUnsaved();
              renderOpts();
              updateBlockCard(blk);
              bindMCQPreviews();
            });
          });
        };
        renderOpts();

        const addOpt = document.getElementById(`mcq_add_opt_${q.id}`);
        addOpt?.addEventListener('click', () => {
          if (opts.length >= 6) return alert('Maximum 6 options.');
          syncMCQEdits();
          startEditSession();
          opts.push({ id: uuid(), label: '', correct: false });
          persistUnsaved();
          renderOpts();
          updateBlockCard(blk);
          bindMCQPreviews();
          endEditSession();
        });
      };

      // Quiz-level binds
      const shuffle = document.getElementById('mcq_shuffle');
      shuffle?.addEventListener('change', (e) => { startEditSession(); blk.props.shuffle = !!e.target.checked; refreshPreview(); endEditSession(); });
      const attempts = document.getElementById('mcq_attempts');
      attempts?.addEventListener('change', (e) => { startEditSession(); blk.props.maxAttempts = Math.max(0, parseInt(e.target.value || '0', 10) || 0); refreshPreview(); endEditSession(); });
      const req = document.getElementById('mcq_require');
      req?.addEventListener('change', (e) => { startEditSession(); blk.props.requireAnswer = !!e.target.checked; refreshPreview(); endEditSession(); });
      const reset = document.getElementById('mcq_reset');
      reset?.addEventListener('change', (e) => { startEditSession(); blk.props.showReset = !!e.target.checked; refreshPreview(); endEditSession(); });
      const resetCorrect = document.getElementById('mcq_reset_correct');
      resetCorrect?.addEventListener('change', (e) => { startEditSession(); blk.props.allowResetOnCorrect = !!e.target.checked; refreshPreview(); endEditSession(); });
      const timing = document.getElementById('mcq_timing');
      timing?.addEventListener('change', (e) => { startEditSession(); blk.props.feedbackTiming = ['submit','onSelect'].includes(e.target.value) ? e.target.value : 'submit'; refreshPreview(); endEditSession(); });
      const showFb = document.getElementById('mcq_show_fb');
      showFb?.addEventListener('change', (e) => { startEditSession(); blk.props.showFeedback = !!e.target.checked; refreshPreview(); endEditSession(); });
      const expMode = document.getElementById('mcq_exp_mode');
      expMode?.addEventListener('change', (e) => { startEditSession(); blk.props.showExplanationAfter = ['submit','incorrectOnly'].includes(e.target.value) ? e.target.value : 'submit'; refreshPreview(); endEditSession(); });
      bind('mcq_msg_ok', 'correctMsg');
      bind('mcq_msg_bad', 'incorrectMsg');
      initRichField('mcq_explain_global', (mcq.explanationHtml ?? mcq.explanationText ?? ''), (htmlVal, textVal) => {
        blk.props.explanationHtml = htmlVal;
        blk.props.explanationText = textVal;
        refreshPreview();
      });

      bind('mcq_style', 'styleVariant', (v) => ['default','minimal','bordered'].includes(v) ? v : 'default');
      const borderToggle = document.getElementById('mcq_border');
      borderToggle?.addEventListener('change', (e) => { startEditSession(); blk.props.showBorder = !!e.target.checked; refreshPreview(); endEditSession(); });
      bind('mcq_spacing', 'spacing', (v) => ['compact','default','spacious'].includes(v) ? v : 'default');
      bind('mcq_accent', 'accentColor', (v) => v.trim());
      bind('mcq_correct_color', 'correctColor', (v) => v.trim());
      bind('mcq_incorrect_color', 'incorrectColor', (v) => v.trim());

      // Build helper bindings for each question
      mcq.questions.forEach(bindQuestionRow);
      mcq.questions.forEach(bindQuestionContent);

      // Drag reorder questions
      document.querySelectorAll('.mcq-q-row').forEach(row => {
        row.addEventListener('dragstart', (e) => { e.dataTransfer.setData('nx/mcq_q', row.dataset.qid || ''); });
        row.addEventListener('dragover', (e) => { e.preventDefault(); row.classList.add('dragover'); });
        row.addEventListener('dragleave', () => row.classList.remove('dragover'));
        row.addEventListener('drop', (e) => {
          e.preventDefault(); row.classList.remove('dragover');
          const fromId = e.dataTransfer.getData('nx/mcq_q');
          const toId = row.dataset.qid;
          const fromIdx = mcq.questions.findIndex(q => q.id === fromId);
          const toIdx = mcq.questions.findIndex(q => q.id === toId);
          if (fromIdx < 0 || toIdx < 0 || fromIdx === toIdx) return;
          startEditSession();
          const moved = mcq.questions.splice(fromIdx,1)[0];
          mcq.questions.splice(toIdx,0,moved);
          persistUnsaved();
          renderInspector();
          updateBlockCard(blk);
        });
      });

      addQuestion?.addEventListener('click', () => {
        startEditSession();
        mcq.questions.push({
          id: uuid(),
          questionHtml: '',
          questionText: '',
          showImage: false,
          image: '',
          imageAlt: '',
          options: [
            { id: uuid(), label: '', correct: false },
            { id: uuid(), label: '', correct: false }
          ],
          correctOptionId: null
        });
        persistUnsaved();
        renderInspector();
        updateBlockCard(blk);
      });
    }
    if (blk.type === 'accordionTabs' || blk.type === 'accordion') {
      const acc = ensureAccordionTabsProps(blk);
      const maxTabs = ACCORDION_TABS_MAX;
      const items = acc.items;
      const tabCap = Math.max(1, Math.min(items.length, maxTabs));
      const renderLen = acc.mode === 'tabs' ? tabCap : items.length;
      const activeIdx = getAccordionFocus(blk.id, renderLen);
      const activeItem = items[activeIdx] || items[0];

      const refreshAll = () => {
        persistUnsaved();
        render();
      };
      const refreshPreview = () => {
        persistUnsaved();
        updateBlockCard(blk);
      };

      document.getElementById('acc_add_item')?.addEventListener('click', () => {
        if (acc.mode === 'tabs' && items.length >= maxTabs) {
          alert(`Tabs are limited to ${maxTabs} items to stay full width.`);
          return;
        }
        pushHistory();
        items.push({ id: uuid(), title: `Item ${items.length + 1}`, body: '', bodyHtml: '', openDefault: false, headerImg:'', headerAlt:'', showHeaderImg:false });
        setAccordionFocus(blk.id, items.length - 1);
        refreshAll();
      });

      document.querySelectorAll('#acc_items_list [data-act="del"]').forEach(btn => {
        btn.addEventListener('click', () => {
          const idx = parseInt(btn.dataset.idx, 10);
          if (!Number.isInteger(idx) || items.length <= 1) return;
          const target = items[idx];
          const hasContent = (target.title?.trim() || target.body?.trim() || target.bodyHtml?.trim() || target.headerImg?.trim());
          if (hasContent && !confirm('Remove this item? Content will be deleted.')) return;
          pushHistory();
          items.splice(idx, 1);
          setAccordionFocus(blk.id, Math.max(0, idx - 1));
          refreshAll();
        });
      });

      document.querySelectorAll('#acc_items_list .nx-itemchip').forEach(btn => {
        btn.addEventListener('click', () => {
          const idx = parseInt(btn.dataset.idx, 10);
          if (!Number.isInteger(idx)) return;
          if (acc.mode === 'tabs' && idx >= maxTabs) {
            alert(`Tabs are limited to ${maxTabs} items. Reorder items to choose which appear.`);
            return;
          }
          setAccordionFocus(blk.id, idx);
          updateBlockCard(blk);
          renderInspector();
        });
      });

      document.getElementById('acc_mode_acc')?.addEventListener('click', () => {
        pushHistory();
        acc.mode = 'accordion';
        setAccordionFocus(blk.id, Math.min(getAccordionFocus(blk.id, items.length), items.length - 1));
        persistUnsaved();
        renderInspector();
        updateBlockCard(blk);
        bindAccordionPreviews();
      });
      document.getElementById('acc_mode_tabs')?.addEventListener('click', () => {
        pushHistory();
        acc.mode = 'tabs';
        const cap = Math.max(1, Math.min(items.length, maxTabs));
        const desired = getAccordionFocus(blk.id, cap);
        setAccordionFocus(blk.id, Math.max(0, Math.min(desired, cap - 1)));
        acc.tabsIndex = Math.min(acc.tabsIndex, cap - 1);
        persistUnsaved();
        renderInspector();
        updateBlockCard(blk);
        bindAccordionPreviews();
      });

      // Drag to reorder items
      document.querySelectorAll('#acc_items_list .nx-acc-itemrow').forEach(row => {
        row.addEventListener('dragstart', (e) => {
          e.dataTransfer.setData('nx/acc', row.dataset.idx || '');
        });
        row.addEventListener('dragover', (e) => { e.preventDefault(); row.classList.add('dragover'); });
        row.addEventListener('dragleave', () => row.classList.remove('dragover'));
        row.addEventListener('drop', (e) => {
          e.preventDefault();
          row.classList.remove('dragover');
          const from = parseInt(e.dataTransfer.getData('nx/acc'), 10);
          const to = parseInt(row.dataset.idx || '-1', 10);
          if (!Number.isInteger(from) || !Number.isInteger(to) || from === to) return;
          pushHistory();
          const moved = items.splice(from, 1)[0];
          items.splice(to, 0, moved);
          setAccordionFocus(blk.id, to);
          refreshAll();
        });
      });

      initRichField('acc_item_body', (activeItem?.bodyHtml ?? activeItem?.body ?? ''), (htmlVal, textVal) => {
        startEditSession();
        activeItem.bodyHtml = htmlVal;
        activeItem.body = textVal;
        refreshPreview();
      });

      const titleInput = document.getElementById('acc_item_title');
      if (titleInput) {
        titleInput.addEventListener('focus', startEditSession);
        titleInput.addEventListener('input', (e) => {
          activeItem.title = e.target.value || '';
          const chip = document.querySelector(`#acc_items_list .nx-itemchip[data-idx="${activeIdx}"]`);
          if (chip) chip.textContent = activeItem.title || `Item ${activeIdx + 1}`;
          refreshPreview();
        });
        titleInput.addEventListener('blur', endEditSession);
      }

      const openChk = document.getElementById('acc_item_open');
      if (openChk) {
        openChk.addEventListener('change', (e) => {
          startEditSession();
          activeItem.openDefault = !!e.target.checked;
          refreshPreview();
          endEditSession();
        });
      }

      const showImg = document.getElementById('acc_item_showimg');
      const imgWrap = document.getElementById('acc_item_img_wrap');
      if (showImg) {
        showImg.addEventListener('change', (e) => {
          startEditSession();
          activeItem.showHeaderImg = !!e.target.checked;
          if (imgWrap) imgWrap.style.display = activeItem.showHeaderImg ? '' : 'none';
          refreshPreview();
          endEditSession();
        });
      }
      const imgInput = document.getElementById('acc_item_img');
      if (imgInput) {
        imgInput.addEventListener('focus', startEditSession);
        imgInput.addEventListener('input', (e) => {
          activeItem.headerImg = e.target.value || '';
          refreshPreview();
        });
        imgInput.addEventListener('blur', endEditSession);
      }
      const altInput = document.getElementById('acc_item_alt');
      if (altInput) {
        altInput.addEventListener('focus', startEditSession);
        altInput.addEventListener('input', (e) => {
          activeItem.headerAlt = e.target.value || '';
          refreshPreview();
        });
        altInput.addEventListener('blur', endEditSession);
      }

      const allowMulti = document.getElementById('acc_allow_multi');
      if (allowMulti) {
        allowMulti.addEventListener('change', (e) => {
          startEditSession();
          acc.allowMultiple = !!e.target.checked;
          refreshPreview();
          endEditSession();
        });
      }
      const allowCollapse = document.getElementById('acc_allow_collapse');
      if (allowCollapse) {
        allowCollapse.addEventListener('change', (e) => {
          startEditSession();
          acc.allowCollapseAll = !!e.target.checked;
          refreshPreview();
          endEditSession();
        });
      }

      const defSelect = document.getElementById('acc_default_open');
      if (defSelect) {
        defSelect.addEventListener('change', (e) => {
          startEditSession();
          const val = e.target.value;
          acc.defaultOpen = ['none','first','custom'].includes(val) ? val : 'none';
          persistUnsaved();
          renderInspector();
          updateBlockCard(blk);
          bindAccordionPreviews();
          endEditSession();
        });
      }
      const defIdx = document.getElementById('acc_default_index');
      if (defIdx) {
        defIdx.addEventListener('change', (e) => {
          startEditSession();
          acc.defaultIndex = Math.max(0, parseInt(e.target.value || '0', 10) || 0);
          refreshPreview();
          endEditSession();
        });
      }

      const tabsDefault = document.getElementById('acc_tabs_default');
      if (tabsDefault) {
        tabsDefault.addEventListener('change', (e) => {
          startEditSession();
          acc.tabsDefault = e.target.value === 'custom' ? 'custom' : 'first';
          if (acc.mode === 'tabs') acc.tabsIndex = Math.min(acc.tabsIndex, tabCap - 1);
          persistUnsaved();
          renderInspector();
          updateBlockCard(blk);
          bindAccordionPreviews();
          endEditSession();
        });
      }
      const tabsIndex = document.getElementById('acc_tabs_index');
      if (tabsIndex) {
        tabsIndex.addEventListener('change', (e) => {
          startEditSession();
          acc.tabsIndex = Math.min(tabCap - 1, Math.max(0, parseInt(e.target.value || '0', 10) || 0));
          refreshPreview();
          endEditSession();
        });
      }
      bind('acc_tabs_align', 'tabsAlign', (v) => ['left','center','right'].includes(v) ? v : 'left');
      bind('acc_tabs_style', 'tabsStyle', (v) => ['underline','pills','segmented'].includes(v) ? v : 'underline');

      bind('acc_style', 'styleVariant', (v) => ['default','minimal','bordered'].includes(v) ? v : 'default');

      const divToggle = document.getElementById('acc_show_dividers');
      if (divToggle) {
        divToggle.addEventListener('change', (e) => {
          startEditSession();
          acc.showDividers = !!e.target.checked;
          refreshPreview();
        });
      }
      const borderToggle = document.getElementById('acc_show_border');
      if (borderToggle) {
        borderToggle.addEventListener('change', (e) => {
          startEditSession();
          acc.showBorder = !!e.target.checked;
          refreshPreview();
        });
      }

      const indSel = document.getElementById('acc_indicator');
      if (indSel) {
        indSel.addEventListener('change', (e) => {
          startEditSession();
          const val = e.target.value;
          if (val === 'none') {
            acc.showIndicator = false;
          } else {
            acc.showIndicator = true;
            acc.indicatorPosition = val === 'left' ? 'left' : 'right';
          }
          refreshPreview();
          endEditSession();
        });
      }

      bind('acc_spacing', 'spacing', (v) => ['compact','default','spacious'].includes(v) ? v : 'default');
      bind('acc_header_bg', 'headerBg', (v) => v.trim());
      bind('acc_header_color', 'headerColor', (v) => v.trim());
      bind('acc_active_bg', 'activeHeaderBg', (v) => v.trim());
      bind('acc_active_color', 'activeHeaderColor', (v) => v.trim());
      bind('acc_body_bg', 'contentBg', (v) => v.trim());
      bind('acc_border_color', 'borderColor', (v) => v.trim());
      bind('acc_img_pos', 'headerImgPos', (v) => ['left','right'].includes(v) ? v : 'left');
      bind('acc_img_size', 'headerImgSize', (v) => ['small','medium','large'].includes(v) ? v : 'medium');
    }
    if (blk.type === 'exampleCard') {
      const toggle = document.getElementById('p_youtry_toggle');
      if (toggle) {
        toggle.addEventListener('change', (e) => {
          startEditSession();
          blk.props.showYouTry = !!e.target.checked;
          persistUnsaved();
          render();
        });
      }

      loadCitationExamples().then((examples) => {
        ensureDefaultCitationExample(examples);
        const wrap = document.getElementById('exampleSelectWrap');
        if (!wrap) return;
        const current = blk.props.exampleId || getPageCitationExample() || examples[0]?.id;
        const currentEx = examples.find(x => x.id === current) || examples[0];

        wrap.innerHTML = `
          <select id="p_example_select" class="nx-toolsel" style="width:100%">
            ${examples.map(ex => `<option value="${esc(ex.id)}" ${ex.id===current ? 'selected' : ''}>${esc(ex.label || ex.heading || ex.id)}</option>`).join('')}
          </select>
        `;

        const select = document.getElementById('p_example_select');
        if (select) {
          select.addEventListener('change', (e) => {
            startEditSession();
            const selId = e.target.value;
            const ex = examples.find(x => x.id === selId) || examples[0];
            applyExampleData(blk, ex);
            propagateCitationExample(selId, examples, { skipId: blk.id, onlyUnset: true });
            persistUnsaved();
            render();
          });
        }

        // ensure props populated on first load if missing
        if (currentEx && (!blk.props.exampleId || !blk.props.heading || !blk.props.youTry)) {
          applyExampleData(blk, currentEx);
          propagateCitationExample(currentEx.id, examples, { skipId: blk.id, onlyUnset: true });
          persistUnsaved();
          render();
        }
      }).catch(() => {});
    }
    if (blk.type === 'citationOrder') {
      loadCitationExamples().then((examples) => {
        const wrap = document.getElementById('citationExampleSelect');
        if (!wrap) return;
        ensureDefaultCitationExample(examples);
        const current = blk.props.exampleId || getPageCitationExample() || examples[0]?.id;
        wrap.innerHTML = `
          <select id="p_citation_select" class="nx-toolsel" style="width:100%">
            ${examples.map(ex => `<option value="${esc(ex.id)}" ${ex.id===current ? 'selected' : ''}>${esc(ex.label || ex.heading || ex.id)}</option>`).join('')}
          </select>
        `;
        const select = document.getElementById('p_citation_select');
        const applySelection = (selId) => {
          startEditSession();
          const ex = examples.find(x => x.id === selId) || examples[0];
          propagateCitationExample(selId, examples, { skipId: blk.id, onlyUnset: true });
          applyCitationOrderToBlock(blk, ex);
          persistUnsaved();
          render();
        };
        if (select) {
          select.addEventListener('change', (e) => applySelection(e.target.value));
        }
        if (!blk.props.body && examples.length) applySelection(current);
      }).catch(() => {});
    }
    if (blk.type === 'youTry') {
      loadCitationExamples().then((examples) => {
        const wrap = document.getElementById('youTrySelectWrap');
        if (!wrap) return;
        ensureDefaultCitationExample(examples);
        const current = blk.props.exampleId || getPageCitationExample() || examples[0]?.id;
        wrap.innerHTML = `
          <select id="p_youtry_select" class="nx-toolsel" style="width:100%">
            ${examples.map(ex => `<option value="${esc(ex.id)}" ${ex.id===current ? 'selected' : ''}>${esc(ex.label || ex.heading || ex.id)}</option>`).join('')}
          </select>
        `;
        const select = document.getElementById('p_youtry_select');
        const applySelection = (selId) => {
          startEditSession();
          const ex = examples.find(x => x.id === selId) || examples[0];
          propagateCitationExample(selId, examples, { skipId: blk.id, onlyUnset: true });
          applyYouTryToBlock(blk, ex);
          persistUnsaved();
          render();
        };
        if (select) {
          select.addEventListener('change', (e) => applySelection(e.target.value));
        }
        if (!blk.props.body && examples.length) applySelection(current);
      }).catch(() => {});
    }
    if (blk.type === 'heroCard' || blk.type === 'heroPage') {
      bind('p_title', 'title');
      bind('p_bgImage', 'bgImage');
      bind('p_bgColor', 'bgColor');
      bind('p_overlay', 'overlayOpacity', (v) => {
        const n = parseFloat(v);
        if (Number.isFinite(n)) return Math.max(0, Math.min(0.9, n));
        return 0.35;
      });
    }
    if (blk.type === 'text') {
      const holder = document.getElementById('p_text_bg_palette');
      if (holder) {
        holder.innerHTML = renderColorPalette('p_text_bg_palette_inner', p.bgColor || '');
        bindColorPalette('p_text_bg_palette_inner', (hex) => {
          startEditSession();
          blk.props = blk.props || {};
          blk.props.bgColor = hex || '';
          persistUnsaved();
          updateBlockCard(blk);
        });
      }
    }
    if (blk.type === 'textbox') {
      bind('p_label', 'label');
      bind('p_placeholder', 'placeholder');
      bind('p_lines', 'lines', (v) => Math.max(1, Math.min(12, parseInt(v || '3', 10) || 3)));
      const holder = document.getElementById('p_textbox_bg_palette');
      if (holder) {
        holder.innerHTML = renderColorPalette('p_textbox_bg_palette_inner', p.bgColor || '');
        bindColorPalette('p_textbox_bg_palette_inner', (hex) => {
          startEditSession();
          blk.props = blk.props || {};
          blk.props.bgColor = hex || '';
          persistUnsaved();
          updateBlockCard(blk);
        });
      }
    }
    if (blk.type === 'carousel') {
      const bgPresets = [
        { key:'light', label:'Light', val:'#f8fafc' },
        { key:'sunny', label:'Soft yellow', val:'linear-gradient(135deg,#fff8d6,#fffef4)' },
        { key:'mint', label:'Soft mint', val:'linear-gradient(135deg,#f3fff6,#f7fffb)' },
        { key:'sky', label:'Soft blue', val:'linear-gradient(135deg,#f3f9ff,#ffffff)' },
        { key:'slate', label:'Dark', val:'#0f172a' }
      ];
      const advPresets = [
        { key:'sunrise', label:'Sunrise gradient', val:'linear-gradient(135deg,#ffe9d2,#fff8e5)' },
        { key:'citrus', label:'Citrus gradient', val:'linear-gradient(135deg,#fff8e1,#fdf3c4)' },
        { key:'mintfade', label:'Mint gradient', val:'linear-gradient(135deg,#e8fff5,#f7fffb)' },
        { key:'ocean', label:'Ocean fade', val:'linear-gradient(135deg,#eaf3ff,#ffffff)' }
      ];

      const bgSel = document.getElementById('p_carousel_bg_select');
      if (bgSel) {
        bgSel.addEventListener('change', (e) => {
          startEditSession();
          const opt = bgPresets.find(x => x.key === e.target.value) || bgPresets[0];
          blk.props.background = opt.val;
          const adv = document.getElementById('p_carousel_bg_adv');
          if (adv) adv.value = '';
          persistUnsaved();
          render();
        });
      }
      const bgAdv = document.getElementById('p_carousel_bg_adv');
      if (bgAdv) {
        bgAdv.addEventListener('change', (e) => {
          startEditSession();
          const opt = advPresets.find(x => x.key === e.target.value);
          if (opt) blk.props.background = opt.val;
          const mainSel = document.getElementById('p_carousel_bg_select');
          if (mainSel && opt) mainSel.value = '';
          persistUnsaved();
          render();
        });
      }
      const showInput = document.getElementById('p_carousel_show');
      if (showInput) {
        showInput.addEventListener('input', (e) => {
          startEditSession();
          blk.props.slidesToShow = Math.max(1, Math.min(5, parseInt(e.target.value || '1', 10) || 1));
          persistUnsaved();
          render();
        });
      }
      const sizeSel = document.getElementById('p_carousel_size');
      if (sizeSel) {
        sizeSel.addEventListener('change', (e) => {
          startEditSession();
          blk.props.size = e.target.value || 'large';
          persistUnsaved();
          render();
        });
      }
      const autoToggle = document.getElementById('p_carousel_autoplay');
      const timerWrap = document.getElementById('p_carousel_timer_wrap');
      if (autoToggle) {
        autoToggle.addEventListener('change', (e) => {
          startEditSession();
          blk.props.autoplay = !!e.target.checked;
          if (timerWrap) timerWrap.style.display = e.target.checked ? '' : 'none';
          persistUnsaved();
          render();
        });
      }
      const intervalInput = document.getElementById('p_carousel_interval');
      if (intervalInput) {
        intervalInput.addEventListener('input', (e) => {
          startEditSession();
          const val = Math.max(2, Math.min(30, parseInt(e.target.value || '5', 10) || 5));
          blk.props.interval = val;
          intervalInput.value = val;
          persistUnsaved();
          render();
        });
      }
      const pauseToggle = document.getElementById('p_carousel_pause');
      if (pauseToggle) {
        pauseToggle.addEventListener('change', (e) => {
          startEditSession();
          blk.props.pauseOnHover = !!e.target.checked;
          persistUnsaved();
          render();
        });
      }
      let slideIdx = Math.min(Math.max(0, blk.props.activeSlide ?? 0), Math.max(0, (blk.props.slides?.length || 1) - 1));
      const titleInput = document.getElementById('p_car_title');
      const imgInput = document.getElementById('p_car_img');
      const layoutSel = document.getElementById('p_car_layout');
      const posLabel = document.getElementById('car_pos');
      const richBody = document.getElementById('p_html');
      const layoutToggle = document.getElementById('p_car_layout_toggle');
      const layoutWrap = document.getElementById('p_car_layout_wrap');
      const imgReplace = document.getElementById('p_car_img_replace');
      const imgRemove = document.getElementById('p_car_img_remove');
      const bodyPlaceholder = 'Add slide body…';

      const ensureSlides = () => { blk.props.slides = Array.isArray(blk.props.slides) ? blk.props.slides : []; if (!blk.props.slides.length) blk.props.slides.push({ title:'', body:'', image:'', layout:'text-left' }); };
      const syncFields = () => {
        ensureSlides();
        const s = blk.props.slides[slideIdx] || {};
        if (posLabel) posLabel.textContent = `Slide ${slideIdx + 1} of ${blk.props.slides.length}`;
        if (titleInput) titleInput.value = s.title || '';
        if (imgInput) imgInput.value = s.image || '';
        if (layoutSel) layoutSel.value = s.layout || 'text-left';
        if (richBody) {
          const initial = (s.bodyHtml ?? s.body ?? '').toString();
          if (!initial) {
            richBody.textContent = bodyPlaceholder;
            richBody.classList.add('nx-placeholder');
            richBody.dataset.placeholder = '1';
          } else {
            richBody.dataset.placeholder = '0';
            richBody.classList.remove('nx-placeholder');
            richBody.innerHTML = /<\/?[a-z][\s\S]*>/i.test(initial) ? initial : initial.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/\n/g,'<br>');
          }
        }
      };
      syncFields();
      const setActive = (n) => {
        ensureSlides();
        slideIdx = (n + blk.props.slides.length) % blk.props.slides.length;
        blk.props.activeSlide = slideIdx;
        syncFields();
        persistUnsaved();
        render();
      };
      document.getElementById('car_prev')?.addEventListener('click', () => setActive(slideIdx - 1));
      document.getElementById('car_next')?.addEventListener('click', () => setActive(slideIdx + 1));
      document.getElementById('carouselAdd')?.addEventListener('click', () => {
        startEditSession();
        ensureSlides();
        blk.props.slides.push({ title:'New slide', body:'', image:'', layout:'text-left' });
        slideIdx = blk.props.slides.length - 1;
        blk.props.activeSlide = slideIdx;
        persistUnsaved();
        render();
      });
      document.getElementById('carouselRemove')?.addEventListener('click', () => {
        if (!blk.props.slides || blk.props.slides.length <= 1) return;
        startEditSession();
        blk.props.slides.splice(slideIdx, 1);
        slideIdx = Math.max(0, slideIdx - 1);
        blk.props.activeSlide = slideIdx;
        persistUnsaved();
        render();
      });
      titleInput?.addEventListener('input', (e) => {
        startEditSession(); ensureSlides(); blk.props.slides[slideIdx].title = e.target.value; persistUnsaved(); updateBlockCard(blk);
      });
      imgInput?.addEventListener('input', (e) => {
        startEditSession(); ensureSlides(); blk.props.slides[slideIdx].image = e.target.value; persistUnsaved(); updateBlockCard(blk);
      });
      imgReplace?.addEventListener('click', () => {
        startEditSession();
        const url = prompt('Enter image URL', blk.props.slides?.[slideIdx]?.image || '');
        if (url != null) {
          ensureSlides();
          blk.props.slides[slideIdx].image = url.trim();
          if (imgInput) imgInput.value = blk.props.slides[slideIdx].image;
          persistUnsaved();
          updateBlockCard(blk);
        }
      });
      imgRemove?.addEventListener('click', () => {
        startEditSession();
        ensureSlides();
        blk.props.slides[slideIdx].image = '';
        if (imgInput) imgInput.value = '';
        persistUnsaved();
        updateBlockCard(blk);
      });
      layoutSel?.addEventListener('change', (e) => {
        startEditSession(); ensureSlides(); blk.props.slides[slideIdx].layout = e.target.value; persistUnsaved(); render();
      });
      if (layoutToggle && layoutWrap) {
        layoutToggle.addEventListener('click', () => {
          layoutWrap.style.display = layoutWrap.style.display === 'none' ? '' : 'none';
        });
      }
      if (richBody) {
        richBody.addEventListener('focus', startEditSession);
        richBody.addEventListener('input', () => {
          ensureSlides();
          if (richBody.dataset.placeholder === '1') {
            richBody.dataset.placeholder = '0';
            richBody.classList.remove('nx-placeholder');
          }
          blk.props.slides[slideIdx].body = richBody.innerText || '';
          blk.props.slides[slideIdx].bodyHtml = richBody.innerHTML;
          persistUnsaved();
          updateBlockCard(blk);
        });
        richBody.addEventListener('blur', () => {
          if (!richBody.innerText.trim()) {
            richBody.textContent = bodyPlaceholder;
            richBody.dataset.placeholder = '1';
            richBody.classList.add('nx-placeholder');
            blk.props.slides[slideIdx].body = '';
            blk.props.slides[slideIdx].bodyHtml = '';
            persistUnsaved();
            updateBlockCard(blk);
          }
          endEditSession();
        });
      }
    }

    document.getElementById('del')?.addEventListener('click', () => {
      pushHistory();
      doc.rows[selected.r].cols[selected.c].blocks.splice(selected.b, 1);
      selected = null;
      persistUnsaved();
      render();
    });

    document.getElementById('dup')?.addEventListener('click', () => {
      pushHistory();
      const clone = JSON.parse(JSON.stringify(blk));
      clone.id = uuid();
      doc.rows[selected.r].cols[selected.c].blocks.splice(selected.b + 1, 0, clone);
      persistUnsaved();
      render();
    });
    } catch (err) {
      console.error('Inspector render error', err);
      if (insp) insp.innerHTML = `<div class="nx-muted">Inspector unavailable. Check console.</div>`;
      if (inspHint) inspHint.style.display = 'none';
      endEditSession();
    }
  }

  function layoutToCols(act) {
    if (act === 'split2') return [6, 6];
    if (act === 'split3') return [4, 4, 4];
    if (act === 'split4') return [3, 3, 3, 3];
    return null;
  }

  function countBlocksInCols(cols, startIndex) {
    let n = 0;
    for (let i = startIndex; i < cols.length; i++) {
      n += (cols[i]?.blocks?.length || 0);
    }
    return n;
  }

  function applyRowLayout(row, act) {
    const spans = layoutToCols(act);
    if (!spans) return false;

    row.cols = row.cols || [];
    row.cols.forEach(c => { c.blocks = c.blocks || []; });

    const oldCols = row.cols.map(c => ({
      span: c.span || 12,
      blocks: Array.isArray(c.blocks) ? c.blocks : []
    }));

    const newCount = spans.length;
    const oldCount = oldCols.length;

    if (newCount < oldCount) {
      const droppedBlocks = countBlocksInCols(oldCols, newCount);
      if (droppedBlocks > 0) {
        const ok = confirm('There are content blocks in the column you are dropping, drop anyway?');
        if (!ok) return false;
      }
    }

    const newCols = spans.map((span, i) => ({
      span,
      blocks: oldCols[i]?.blocks ? [...oldCols[i].blocks] : []
    }));

    row.cols = newCols;
    return true;
  }

  // --- Render canvas
  function render() {
    try {
      applyPageStyles();

      if (!doc || !Array.isArray(doc.rows)) {
        doc = { rows: [{ cols: [{ span: 12, blocks: [] }] }] };
      }

      canvas.innerHTML = '';
      doc.rows = doc.rows || [];
      doc.rows.forEach(ensureRow);

    doc.rows.forEach((row, r) => {
      ensureRowMeta(row);
      const rowEl = document.createElement('div');
      rowEl.className = 'rowbox';

      // Row background (editor) from styleRow
      const rs = ensureRowStyle(row);
      if (!rs.bgEnabled) {
        rowEl.style.background = 'transparent';
        rowEl.style.borderStyle = 'dashed';
      } else {
        // default subtle panel; if user picked preset, use it
        rowEl.style.background = rs.bgColor ? rs.bgColor : 'rgba(255,255,255,.55)';
      }

      if (selectedRow === r) rowEl.classList.add('selected-row');
      if (row.equalHeight) rowEl.classList.add('row-equal');
      if (row.equalHeight) rowEl.style.setProperty('--nx-eq-cols', String(Math.max(1, row.cols.length || 1)));

      const head = document.createElement('div');
      head.className = 'rowhead';
      head.setAttribute('draggable', 'true');
      head.innerHTML = `
        <div style="display:flex;align-items:center;gap:10px;flex:1">
          <div class="nx-muted" data-act="selectrow" style="cursor:pointer">Row ${r + 1}</div>
          <button class="smallbtn" data-act="collapse" type="button">${row.collapsed ? 'Expand Row' : 'Collapse Row'}</button>
          <button class="smallbtn nx-equal-btn ${row.equalHeight ? 'is-on' : ''}" data-act="equalheight" type="button" aria-pressed="${row.equalHeight ? 'true' : 'false'}" title="${row.equalHeight ? 'Equal height on' : 'Equal height off'}">⇳</button>
        </div>
        <div style="display:flex;gap:8px;flex-wrap:wrap;justify-content:flex-end">
          <button class="smallbtn" data-act="split2" type="button">6/6</button>
          <button class="smallbtn" data-act="split3" type="button">4/4/4</button>
          <button class="smallbtn" data-act="split4" type="button">3/3/3/3</button>
          <button class="smallbtn" data-act="delrow" type="button"
            style="border-color:rgba(239,68,68,.35);background:rgba(239,68,68,.12)">Delete Row</button>
        </div>
      `;

      head.addEventListener('click', (e) => {
        const labelAct = e.target?.dataset?.act;
        if (labelAct === 'selectrow') {
          selectedRow = r;
          selected = null;
          render();
          renderInspector();
          syncToolbarFromSelection();
          return;
        }

        const btn = e.target.closest('button');
        if (!btn) return;
        const act = btn.dataset.act;

        if (act === 'collapse') {
          pushHistory();
          row.collapsed = !row.collapsed;
          persistUnsaved();
          render();
          return;
        }

        if (act === 'equalheight') {
          pushHistory();
          row.equalHeight = !row.equalHeight;
          persistUnsaved();
          render();
          return;
        }

        if (act === 'delrow') {
          const hasBlocks = row.cols?.some(c => (c.blocks?.length || 0) > 0);
          if (hasBlocks && !confirm('This row contains content. Delete it?')) return;
          pushHistory();
          doc.rows.splice(r, 1);
          selected = null;
          selectedRow = null;
          persistUnsaved();
          render();
          return;
        }

        pushHistory();
        const changed = applyRowLayout(row, act);
        if (!changed) return;

        persistUnsaved();
        render();
      });

      head.addEventListener('dragstart', (e) => {
        if (e.target.closest('button')) { e.preventDefault(); return; }
        e.dataTransfer.setData('nx/row', String(r));
        e.dataTransfer.effectAllowed = 'move';
      });
      head.addEventListener('dragover', (e) => {
        if (e.dataTransfer.types.includes('nx/row')) {
          e.preventDefault();
          head.classList.add('row-dragover');
        }
      });
      head.addEventListener('dragleave', () => head.classList.remove('row-dragover'));
      head.addEventListener('drop', (e) => {
        head.classList.remove('row-dragover');
        const src = e.dataTransfer.getData('nx/row');
        if (src === '') return;
        const from = parseInt(src, 10);
        const to = r;
        if (!Number.isInteger(from) || from === to) return;
        pushHistory();
        const [moved] = doc.rows.splice(from, 1);
        doc.rows.splice(to, 0, moved);
        selected = null;
        selectedRow = null;
        persistUnsaved();
        render();
      });

      const cols = document.createElement('div');
      cols.className = 'cols';
      if (row.collapsed) cols.style.display = 'none';

      row.cols.forEach((col, c) => {
        const colEl = document.createElement('div');
        colEl.className = 'colbox';
        let span = Math.max(3, Math.min(12, col.span || 12));
        col.span = span;
        colEl.style.setProperty('--span', String(span));
        const colBlocks = (col.blocks || []);
        if (row.equalHeight && colBlocks.length === 1) colEl.classList.add('col-equal-one');

        const ch = document.createElement('div');
        ch.style.display = 'flex';
        ch.style.justifyContent = 'space-between';
        ch.style.alignItems = 'center';
        ch.style.gap = '8px';
        ch.innerHTML = `
          <div style="display:flex;gap:6px">
            <button class="smallbtn" data-act="minus" type="button">-</button>
            <button class="smallbtn" data-act="plus" type="button">+</button>
            <button class="smallbtn" data-act="delcol" type="button" title="Delete column">🗑</button>
          </div>
          <div class="nx-muted">Col ${c + 1} span ${span}</div>
        `;

      ch.addEventListener('click', (e) => {
        const btn = e.target.closest('button');
        if (!btn) return;
        e.stopPropagation();
        const act = btn.dataset.act;

        if (act === 'delcol') {
          const hasBlocks = (col.blocks?.length || 0) > 0;
          if (hasBlocks && !confirm('This column contains content. Delete it?')) return;

          pushHistory();
          if (row.cols.length === 1) {
            row.cols[0].blocks = [];
            row.cols[0].span = 12;
          } else {
            row.cols.splice(c, 1);
          }
          selected = null;
          persistUnsaved();
          render();
          return;
        }

        pushHistory();
        if (act === 'minus') col.span = Math.max(3, (col.span || 12) - 1);
        if (act === 'plus')  col.span = Math.min(12, (col.span || 12) + 1);
        persistUnsaved();
        render();
      });

      colEl.appendChild(ch);

        // Drop blocks into columns
        colEl.addEventListener('dragover', (e) => {
          e.preventDefault();
          colEl.classList.add('dragover');
        });
        colEl.addEventListener('dragleave', () => colEl.classList.remove('dragover'));

        colEl.addEventListener('drop', (e) => {
          e.preventDefault();
          colEl.classList.remove('dragover');

          const type = e.dataTransfer.getData('nx/type');
          const moving = e.dataTransfer.getData('nx/move');
          pushHistory();

          if (moving) {
            const m = JSON.parse(moving);
            const movedBlk = doc.rows[m.r].cols[m.c].blocks.splice(m.b, 1)[0];
            doc.rows[r].cols[c].blocks.push(movedBlk);
          } else if (type) {
            doc.rows[r].cols[c].blocks.push(defBlock(type));
          }
          persistUnsaved();
          render();
        });

        // Blocks
        (colBlocks).forEach((blk, b) => {
          if (!blk || typeof blk !== 'object') { console.warn('Skipping malformed block', blk); return; }
          if (!blk.id) blk.id = uuid();

          const el = document.createElement('div');
          el.className = 'block';
          if (row.equalHeight && colBlocks.length === 1) el.classList.add('block-fill');
          el.draggable = true;
          el.dataset.bid = blk.id;

          // Apply block wrapper styles (border/shadow/etc.)
          if (blk.style && typeof blk.style === 'object') {
            const css = styleObjToCss(blk.style);
            if (css) el.setAttribute('style', css);
          }

          el.innerHTML = `
            <div class="nx-muted" style="display:flex;justify-content:space-between">
              <span>${esc(blk.type)}${blk.effect ? ' <span class="nx-star">★</span>' : ''}</span>
              <span>drag</span>
            </div>
            <div class="nx-block-preview" style="margin-top:6px">${blockPreviewSafe(blk)}</div>
          `;

          // Dragover to allow repositioning OR effect drop
          el.addEventListener('dragover', (e) => {
            const hasMove = e.dataTransfer.types.includes('nx/move');
            const hasEff = e.dataTransfer.types.includes('nx/effect');
            if (!hasMove && !hasEff) return;
            e.preventDefault();
            if (hasMove) el.classList.add('block-drop-before');
          });

          el.addEventListener('dragleave', () => {
            el.classList.remove('block-drop-before');
          });

          el.addEventListener('drop', (e) => {
            const moving = e.dataTransfer.getData('nx/move');
            const eff = e.dataTransfer.getData('nx/effect');
            el.classList.remove('block-drop-before');
            if (moving) {
              e.preventDefault();
              const m = JSON.parse(moving);
              const fromBlocks = doc.rows?.[m.r]?.cols?.[m.c]?.blocks;
              if (!fromBlocks) return;
              const movedBlk = fromBlocks.splice(m.b, 1)[0];
              pushHistory();
              const destBlocks = doc.rows[r].cols[c].blocks;
              let insertAt = b;
              if (m.r === r && m.c === c && m.b < b) insertAt -= 1; // account for removal offset
              destBlocks.splice(insertAt, 0, movedBlk);
              selected = { r, c, b: insertAt };
              persistUnsaved();
              render();
              return;
            }
            if (eff) {
              e.preventDefault();
              pushHistory();
              blk.effect = eff;
              persistUnsaved();
              render();
            }
          });

          el.addEventListener('click', (e) => {
            e.stopPropagation();

            // if revisions are open, close them automatically
            if (revisionsView && revisionsView.style.display !== 'none') {
              showInspector();
            }

            const interactiveTarget = e.target.closest('.nx-accordion-head, .nx-tab');
            const wasSelected = selected && selected.r === r && selected.c === c && selected.b === b;
            selected = { r, c, b };
            selectedRow = null;
            if (interactiveTarget) {
              document.querySelectorAll('.block.selected').forEach(bl => bl.classList.remove('selected'));
              el.classList.add('selected');
            } else if (!wasSelected) {
              render();
            }
            renderInspector();
            syncToolbarFromSelection();
          });

          el.addEventListener('dragstart', (e) => {
            e.dataTransfer.setData('nx/move', JSON.stringify({ r, c, b }));
          });

          if (selected && selected.r === r && selected.c === c && selected.b === b) {
            el.classList.add('selected');
          }

          colEl.appendChild(el);
        });

        cols.appendChild(colEl);
      });

      rowEl.appendChild(head);
      rowEl.appendChild(cols);
      canvas.appendChild(rowEl);
    });

    // Validate selection still exists
    if (selected) {
      const blk = doc.rows?.[selected.r]?.cols?.[selected.c]?.blocks?.[selected.b];
      if (!blk) selected = null;
    }
    if (selectedRow != null && !doc.rows?.[selectedRow]) selectedRow = null;

    bindAccordionPreviews();
    bindMCQPreviews();
    renderInspector();
    syncToolbarFromSelection();
    } catch (err) {
      console.error('Render error', err);
      canvas.innerHTML = `<div style="padding:20px;border:1px solid rgba(239,68,68,.35);background:rgba(239,68,68,.1);border-radius:12px;color:#ef4444;">Editor render error. Reload the page to continue.</div>`;
    }
  }

  // Palette drag sources
  document.querySelectorAll('.nx-item').forEach((el) => {
    el.addEventListener('dragstart', (e) => {
      e.dataTransfer.setData('nx/type', el.dataset.type || '');
    });
  });

  // Close revisions if user clicks empty canvas area
  canvas?.addEventListener('click', () => {
    if (revisionsView && revisionsView.style.display !== 'none') {
      showInspector();
    }
  });

  // Initial doc
  if (!doc.rows || !doc.rows.length) {
    doc.rows = [{ cols: [{ span: 12, blocks: [] }], styleRow: { bgEnabled: true, bgColor: '' } }];
  } else {
    // ensure row style defaults exist
    doc.rows.forEach(r => ensureRowStyle(r));
  }

  // Start in inspector mode
  showInspector();
  applyPageStyles();
  render();
})();
