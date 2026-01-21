# NexusCMS

NexusCMS is a custom PHP-based page builder CMS inspired by modern editors such as Webflow and Word.  
It provides a block-based visual editor with revisions, previews, and fine-grained styling controls.

---

## ✨ Core Features

### Page Builder
- Drag-and-drop rows, columns, and blocks
- Column layouts: 12, 6/6, 4/4/4, 3/3/3/3
- Content blocks:
  - Heading
  - Text (rich text)
  - Image
  - Video
  - Card
  - Divider

### Inspector
- Contextual inspector opens when selecting a block
- Inline rich text editing (contenteditable)
- Text formatting:
  - Bold, Italic, Underline
  - Font family & size
  - Alignment
  - Hyperlinks (add/remove)
- Block styling:
  - Background color
  - Border
  - Border radius
  - Box shadow
  - Padding / Margin

### Color System (Word-style)
- No free color picker
- Preset palette only (red, blue, green, purple, etc.)
- Default page background: off-white
- Ensures design consistency across pages

### Effects
- Drag effects onto blocks
- Fade In
- Fade In Up
- Slide In Right
- Scale In
- Visual indicator on effected blocks

---

## 🕘 Revisions System

- Save without creating a revision (default)
- “Save as new revision” creates a snapshot
- Only last **5 revisions** are kept (auto-pruned)
- Revisions panel:
  - View revision (opens preview in new tab)
  - Restore revision:
    - Replace current page
    - OR duplicate as a new page
  - Delete revision

---

## 👀 Preview System

- Preview works even if page is **unpublished**
- Preview reflects **unsaved changes**
- Uses session-based preview tokens
- Returning from preview keeps unsaved state intact

---

## 🧱 Architecture Overview

### Entry Point
/index.php
- Handles routing
- Public site pages
- Preview token logic
- API endpoints

---

### Page Builder
/admin/page_builder.php
/public/assets/builder.js
/public/assets/builder.css


- `page_builder.php`
  - Editor shell
  - Toolbars
  - Left sidebar (Blocks, Effects)
  - Right panel (Inspector / Revisions)

- `builder.js`
  - Editor state management
  - Drag & drop
  - Inspector logic
  - Revisions UI
  - Preview / Save / Publish

---

### Rendering
/app/src/Services/Renderer.php

- Converts `builder_json` into safe HTML
- Sanitizes inline HTML
- Applies text styles & block styles
- Ensures links are safe and underlined

---

### Models

- Converts `builder_json` into safe HTML
- Sanitizes inline HTML
- Applies text styles & block styles
- Ensures links are safe and underlined

---

### Models
/app/src/Models/Page.php
/app/src/Models/Revision.php
/app/src/Models/Site.php


- Pages: draft / published state
- Revisions: snapshot storage + pruning
- Sites: grouping pages

---

### Public View


/public/views/site_page.php


- Renders public-facing page
- Shows preview banner when applicable
- Allows return to editor during preview

---

## 🔐 Security

- CSRF protection on all mutating API calls
- Sanitized HTML rendering
- Safe link handling (noopener, noreferrer)
- No inline scripts allowed in content

---

## 🚧 Known Work-in-Progress Areas

- Color palette UI final polish
- Page-level settings (font, width, padding)
- Theme presets (future)
- Mobile-specific layouts

---

## 🤝 Working With This Repo

When collaborating (e.g. with ChatGPT):
1. Reference **file paths explicitly**
2. Share **one file at a time**
3. Mention the **goal**, not just the bug

Example:
> “Here is `builder.js`. The text blocks show `undefined` in the preview cards.”

---
