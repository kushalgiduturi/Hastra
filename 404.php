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
<meta name="description" content="This page could not be found on Hastra. It may have moved or never existed. Go back to the home page or open the free security and study Labs.">
<meta name="theme-color" content="#050505">
<link rel="icon" type="image/svg+xml" href="<?= $b ?>assets/images/hastra-logo.svg">
<link rel="alternate icon" href="<?= $b ?>favicon.ico">
<style>
  /* Spatial UI, standalone: this page loads no scripts and no shared CSS (it
     must render even when the app is down), so its tokens are inline. One
     depth-2 glass card over a calm gradient; light theme follows the system. */
  :root {
    --text: #ffffff; --text-2: rgba(255, 255, 255, .76); --text-3: rgba(255, 255, 255, .6);
    --accent: #e50914; --accent-text: #ff4655; --hover: rgba(255, 255, 255, .07); --press: rgba(255, 255, 255, .11);
    --fill: rgba(10, 11, 16, .64); --edge: rgba(255, 255, 255, .14);
    --shadow: 0 24px 60px -18px rgba(0, 0, 0, .65), 0 2px 6px rgba(0, 0, 0, .35);
    --world: radial-gradient(ellipse 70% 55% at 30% 20%, rgba(229, 9, 20, .16), transparent 62%),
             radial-gradient(ellipse 60% 50% at 85% 90%, rgba(120, 20, 16, .14), transparent 60%), #06070a;
    color-scheme: dark;
  }
  @media (prefers-color-scheme: light) {
    :root {
      --text: #000000; --text-2: #2b3442; --text-3: #3d4756;
      --accent: #2563eb; --accent-text: #1d4ed8; --hover: rgba(15, 23, 42, .05); --press: rgba(15, 23, 42, .09);
      --fill: rgba(255, 255, 255, .74); --edge: rgba(255, 255, 255, .95);
      --shadow: 0 24px 60px -20px rgba(15, 23, 42, .28), 0 2px 6px rgba(15, 23, 42, .08);
      --world: radial-gradient(ellipse 70% 55% at 30% 20%, rgba(37, 99, 235, .14), transparent 62%),
               radial-gradient(ellipse 60% 50% at 85% 90%, rgba(254, 215, 102, .25), transparent 60%), #eef1f5;
      color-scheme: light;
    }
  }
  *, *::before, *::after { box-sizing: border-box; }
  html, body { margin: 0; }
  body {
    min-height: 100vh; display: grid; place-items: center; padding: 24px; overflow-x: hidden; color: var(--text);
    font-family: "Onest", system-ui, -apple-system, "Segoe UI", Roboto, sans-serif; background: var(--world);
  }
  .stage {
    width: 100%; max-width: 520px; padding: clamp(28px, 6vw, 48px); text-align: center; border-radius: 28px;
    background: var(--fill); -webkit-backdrop-filter: blur(18px) saturate(1.35); backdrop-filter: blur(18px) saturate(1.35);
    box-shadow: inset 0 1px 0 var(--edge), var(--shadow);
  }
  .code { margin: 0 0 10px; font-family: inherit; font-weight: 650; font-size: clamp(72px, 20vw, 128px); line-height: .95; letter-spacing: -.03em; color: var(--text-3); font-variant-numeric: tabular-nums; }
  h1 { margin: 0 0 10px; font-size: clamp(22px, 5vw, 28px); font-weight: 650; letter-spacing: -.015em; text-wrap: balance; }
  p { margin: 0 auto 26px; max-width: 40ch; font-size: 15px; line-height: 1.6; color: var(--text-2); }
  .actions { display: flex; flex-wrap: wrap; gap: 8px; justify-content: center; }
  .btn {
    display: inline-flex; align-items: center; justify-content: center; min-height: 44px; padding: 0 20px; border-radius: 999px;
    font-family: inherit; font-weight: 600; font-size: 14px; line-height: 1; text-decoration: none; transition: background .2s, transform .28s cubic-bezier(.16, 1, .3, 1);
  }
  .btn--primary { background: var(--accent); color: #fff; box-shadow: inset 0 1px 0 rgba(255, 255, 255, .28), 0 6px 16px -6px rgba(0, 0, 0, .5); }
  .btn--primary:hover { background: color-mix(in srgb, var(--accent) 88%, #fff); transform: translateY(-1px); }
  .btn--ghost { background: var(--hover); color: var(--text); box-shadow: inset 0 1px 0 var(--edge); }
  .btn--ghost:hover { background: var(--press); }
  .btn:active { transform: scale(.98); }
  .btn:focus-visible, .links a:focus-visible { outline: 2px solid var(--accent-text); outline-offset: 3px; }
  .links { margin: 22px 0 0; font-size: 13px; color: var(--text-3); }
  .links a { color: var(--text-2); text-underline-offset: 3px; }
  .links a:hover { color: var(--text); }
  @media (prefers-reduced-motion: reduce) { .btn { transition: none; } }
  @media (prefers-reduced-transparency: reduce) { .stage { -webkit-backdrop-filter: none; backdrop-filter: none; background: color-mix(in srgb, var(--fill) 100%, #000 0%); } }
</style>
<link rel="stylesheet" href="<?= $b ?>assets/css/fonts.css">
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
