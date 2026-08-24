<?php

declare(strict_types=1);

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');
header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; base-uri 'none'; frame-ancestors 'none'; form-action 'self'");

?><!doctype html>
<html lang="fr">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex, nofollow">
  <title>Xar Tsaroth — Régie du Seuil</title>
  <style>
    :root { color-scheme: dark; font-family: system-ui, sans-serif; background: #0d0d12; color: #eee8da; }
    body { min-height: 100vh; margin: 0; display: grid; place-items: center; }
    main { max-width: 42rem; padding: 2rem; text-align: center; }
    h1 { color: #d8ad54; font-size: clamp(1.6rem, 4vw, 2.5rem); }
    p { color: #bcb6aa; line-height: 1.6; }
  </style>
</head>
<body>
  <main>
    <h1>Xar Tsaroth — Régie du Seuil</h1>
    <p>Le service central de la Régie est en ligne.</p>
    <p><a href="/studio" style="color:#d8ad54">Ouvrir ma collection d’images MJ</a></p>
  </main>
</body>
</html>
