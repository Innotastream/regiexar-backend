<?php

declare(strict_types=1);

$host = strtolower((string) ($_SERVER['HTTP_HOST'] ?? ''));
$host = preg_replace('/:\d+$/', '', trim($host)) ?? '';
if ($host !== 'regie-xar-tsaroth.fr') {
    http_response_code(421);
    exit;
}

$nonce = rtrim(strtr(base64_encode(random_bytes(18)), '+/', '-_'), '=');
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');
header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
header('X-Robots-Tag: noindex, nofollow, noarchive');
header(
    "Content-Security-Policy: default-src 'none'; "
    . "style-src 'nonce-{$nonce}'; script-src 'nonce-{$nonce}'; "
    . "connect-src 'self'; form-action 'none'; base-uri 'none'; frame-ancestors 'none'"
);
?>
<!doctype html>
<html lang="fr">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Initialisation — Xar Tsaroth Régie</title>
  <style nonce="<?= htmlspecialchars($nonce, ENT_QUOTES, 'UTF-8') ?>">
    :root { color-scheme: dark; font-family: Inter, system-ui, sans-serif; }
    * { box-sizing: border-box; }
    body { margin: 0; min-height: 100vh; display: grid; place-items: center; padding: 24px; background: #11131a; color: #f2ecdf; }
    main { width: min(100%, 520px); padding: 30px; border: 1px solid #74613b; border-radius: 18px; background: #1a1d27; box-shadow: 0 24px 70px #0008; }
    h1 { margin: 0 0 8px; font-family: Georgia, serif; color: #e4bd69; font-size: 1.8rem; }
    p { color: #bdb7aa; line-height: 1.5; }
    form { display: grid; gap: 15px; margin-top: 24px; }
    label { display: grid; gap: 7px; color: #ded7ca; font-weight: 650; }
    input { width: 100%; border: 1px solid #464c5c; border-radius: 9px; padding: 12px 13px; background: #10121a; color: #fff; font: inherit; }
    input:focus { outline: 2px solid #d7a84e; outline-offset: 2px; }
    button { margin-top: 6px; border: 0; border-radius: 10px; padding: 13px 16px; background: #c8933f; color: #17120a; font: inherit; font-weight: 800; cursor: pointer; }
    button:disabled { opacity: .55; cursor: wait; }
    #status { min-height: 24px; margin-bottom: 0; color: #efbb67; }
    #status.success { color: #86d6a3; }
    .hidden { display: none; }
  </style>
</head>
<body>
  <main>
    <h1>Premier compte maître de jeu</h1>
    <p>Cette opération est unique. Le code d’initialisation expire rapidement et le mot de passe est transformé côté serveur avant son stockage.</p>
    <form id="bootstrapForm" autocomplete="off">
      <label>Identifiant
        <input id="username" name="username" minlength="3" maxlength="64" pattern="[A-Za-zÀ-ÖØ-öø-ÿ0-9][A-Za-zÀ-ÖØ-öø-ÿ0-9._-]{2,63}" autocomplete="username" required>
      </label>
      <label>Nom affiché
        <input id="displayName" name="displayName" maxlength="96" required>
      </label>
      <label>Mot de passe
        <input id="password" name="password" type="password" minlength="10" maxlength="256" autocomplete="new-password" required>
      </label>
      <label>Confirmer le mot de passe
        <input id="passwordConfirm" name="passwordConfirm" type="password" minlength="10" maxlength="256" autocomplete="new-password" required>
      </label>
      <button type="submit">Créer le compte MJ</button>
    </form>
    <p id="status" role="status" aria-live="polite"></p>
  </main>
  <script nonce="<?= htmlspecialchars($nonce, ENT_QUOTES, 'UTF-8') ?>">
    (() => {
      const parameters = new URLSearchParams(location.hash.slice(1));
      const bootstrapToken = parameters.get('code') || location.hash.slice(1);
      history.replaceState(null, '', location.pathname);
      const form = document.querySelector('#bootstrapForm');
      const status = document.querySelector('#status');
      const button = form.querySelector('button[type="submit"]');
      if (!/^[A-Za-z0-9_-]{43}$/.test(bootstrapToken)) {
        form.classList.add('hidden');
        status.textContent = 'Lien d’initialisation absent ou expiré.';
        return;
      }
      form.addEventListener('submit', async (event) => {
        event.preventDefault();
        status.className = '';
        status.textContent = '';
        if (form.password.value !== form.passwordConfirm.value) {
          status.textContent = 'Les deux mots de passe ne correspondent pas.';
          return;
        }
        button.disabled = true;
        try {
          const response = await fetch('/api/v1/auth/bootstrap', {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
              bootstrapToken,
              username: form.username.value,
              displayName: form.displayName.value,
              password: form.password.value
            })
          });
          const payload = await response.json().catch(() => ({}));
          if (!response.ok || payload.ok !== true) {
            throw new Error(payload.error || 'Initialisation impossible.');
          }
          form.reset();
          form.classList.add('hidden');
          status.className = 'success';
          status.textContent = 'Compte MJ créé. L’initialisation est maintenant verrouillée.';
        } catch (error) {
          status.textContent = error instanceof Error ? error.message : 'Initialisation impossible.';
          button.disabled = false;
        }
      });
    })();
  </script>
</body>
</html>

