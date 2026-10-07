<?php
// Apache's ErrorDocument for 404 (see .htaccess). Deliberately standalone: no
// database, no session, no scripts, so it renders even when the rest of the
// app is down. Links are built from where this file is mounted, so they are
// right in production ("/") and on a dev machine ("/Hastra/").
http_response_code(404);
header('Cache-Control: no-store');
$base = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/404.php')), '/') . '/';
$b = htmlspecialchars($base, ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>404 · Page not found · Hastra</title>
<meta name="robots" content="noindex, nofollow">
<meta name="theme-color" content="#050505">
<link rel="icon" type="image/svg+xml" href="<?= $b ?>assets/images/hastra-logo.svg">
<link rel="alternate icon" href="<?= $b ?>favicon.ico">
<style>
  *, *::before, *::after { box-sizing: border-box; }
  html, body { margin: 0; }
  body {
    min-height: 100vh; display: grid; place-items: center; padding: 24px; overflow-x: hidden; color: #f3f4f6;
    font-family: "Inter", "Segoe UI", system-ui, -apple-system, sans-serif;
    background:
      radial-gradient(ellipse 70% 55% at 30% 20%, rgba(229, 9, 20, .20), transparent 62%),
      radial-gradient(ellipse 60% 50% at 85% 90%, rgba(255, 90, 60, .10), transparent 60%),
      linear-gradient(rgba(255, 255, 255, .035) 1px, transparent 1px) 0 0 / 48px 48px,
      linear-gradient(90deg, rgba(255, 255, 255, .035) 1px, transparent 1px) 0 0 / 48px 48px,
      #050505;
  }
  /* boxless: the words sit straight on the grid, nothing wraps them */
  .stage { width: 100%; max-width: 560px; text-align: center; text-shadow: 0 2px 22px rgba(5, 5, 5, .9); }
  .code {
    margin: 0 0 6px; font: 900 clamp(88px, 26vw, 170px)/.9 ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; letter-spacing: .04em;
    color: transparent; -webkit-text-stroke: 2px #ff5a3c; text-shadow: 0 0 60px rgba(229, 9, 20, .45);
  }
  h1 { margin: 0 0 10px; font-size: clamp(22px, 5.5vw, 30px); font-weight: 800; letter-spacing: -.01em; }
  p { margin: 0 auto 26px; max-width: 40ch; font-size: 14.5px; line-height: 1.65; color: rgba(243, 244, 246, .68); }
  .actions { display: flex; flex-wrap: wrap; gap: 12px; justify-content: center; }
  .btn {
    display: inline-flex; align-items: center; justify-content: center; min-height: 48px; padding: 0 22px; border-radius: 12px;
    font: 700 12px/1 ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; letter-spacing: .12em; text-transform: uppercase;
    text-decoration: none; color: #fff; transition: transform .15s, background .15s, border-color .15s;
  }
  .btn--primary { background: #e50914; box-shadow: 0 10px 28px rgba(229, 9, 20, .35); }
  .btn--primary:hover { background: #c20812; }
  .btn--ghost { min-height: 48px; padding: 0 6px; color: rgba(243, 244, 246, .9); box-shadow: none; background: none;
    text-decoration: underline; text-decoration-color: rgba(229, 9, 20, .6); text-underline-offset: 8px; text-decoration-thickness: 2px; }
  .btn--ghost:hover { text-decoration-color: #ff5a3c; color: #fff; }
  .btn:active { transform: translateY(1px); }
  .btn:focus-visible { outline: 2px solid #fff; outline-offset: 3px; }
  .links { margin-top: 22px; font-size: 13px; color: rgba(243, 244, 246, .5); }
  .links a { color: rgba(243, 244, 246, .85); }
  @media (prefers-reduced-motion: reduce) { .btn { transition: none; } }
</style>
</head>
<body>
  <main class="stage">
    <div class="code" aria-hidden="true">404</div>
    <h1>This page is not in the sanctuary</h1>
    <p>The address you followed does not exist, or it has moved. Head back to the main hall, or open the free Labs.</p>
    <div class="actions">
      <a class="btn btn--primary" href="<?= $b ?>">Return home</a>
      <a class="btn btn--ghost" href="<?= $b ?>labs/">Open Hastra Labs</a>
    </div>
    <p class="links"><a href="<?= $b ?>signin">Sign in</a> &middot; <a href="<?= $b ?>signup">Create an account</a></p>
  </main>
</body>
</html>
