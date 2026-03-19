<?php
// Header partial for Skills for Study (skills-for-study)
?>
<header class="site-header" style="padding:16px 20px;border-bottom:1px solid rgba(0,0,0,.08);display:flex;align-items:center;justify-content:space-between;gap:12px">
  <div style="font-weight:700;font-size:18px">Skills for Study</div>
  <nav aria-label="Primary">
    <ul style="display:flex;gap:14px;list-style:none;padding:0;margin:0;">
      <li><a href="/s/skills-for-study/home">Home</a></li>
      <li><a href="/s/skills-for-study/about">About</a></li>
      <li><a href="/s/skills-for-study/contact">Contact</a></li>
    </ul>
  </nav>
  <form action="/s/skills-for-study/search" method="get" role="search" style="display:flex;gap:8px;align-items:center">
    <label class="sr-only" for="site-search">Search</label>
    <input id="site-search" name="q" type="search" placeholder="Search Skills for Study" style="padding:8px 10px;border:1px solid #cbd5e1;border-radius:8px;min-width:180px">
    <button type="submit" style="padding:8px 12px;border-radius:8px;border:1px solid #cbd5e1;background:#2563eb;color:#fff;cursor:pointer">Search</button>
  </form>
</header>