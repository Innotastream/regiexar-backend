<?php
declare(strict_types=1);

$nonce = rtrim(strtr(base64_encode(random_bytes(18)), '+/', '-_'), '=');
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');
header("Content-Security-Policy: default-src 'none'; base-uri 'none'; frame-ancestors 'none'; form-action 'none'; connect-src 'self'; style-src 'nonce-{$nonce}'; script-src 'nonce-{$nonce}'");
?><!doctype html>
<html lang="fr">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex, nofollow">
  <title>Première activation — Atelier Xar Tsaroth</title>
  <style nonce="<?= htmlspecialchars($nonce, ENT_QUOTES, 'UTF-8') ?>">
    :root { color-scheme:dark; font-family:Segoe UI,system-ui,sans-serif; background:#100d18; color:#f2ece2; }
    * { box-sizing:border-box; } body { margin:0; min-height:100vh; background:radial-gradient(ellipse at top,#34243f,transparent 65%),#100d18; }
    main { width:min(580px,calc(100% - 36px)); margin:64px auto; }
    .eyebrow { color:#d7ae6c; letter-spacing:.18em; font-size:12px; text-transform:uppercase; }
    h1 { font-size:32px; margin:12px 0; } p { color:#c7bfcd; line-height:1.6; }
    section { padding:24px; margin:24px 0; border:1px solid #4a3d53; border-radius:16px; background:#1a1523; }
    h2 { font-size:20px; margin:0 0 12px; } label { display:block; margin-top:16px; font-size:14px; }
    input { width:100%; margin-top:7px; border:1px solid #66556f; border-radius:8px; padding:12px; background:#100d18; color:#fff; font:inherit; }
    button { width:100%; margin-top:22px; border:0; border-radius:8px; padding:13px; background:#d4ae70; color:#211827; font:600 15px Segoe UI,system-ui,sans-serif; cursor:pointer; }
    button:disabled { opacity:.5; cursor:wait; } input:focus-visible,button:focus-visible { outline:3px solid #d7ae6c; outline-offset:3px; }
    .hidden { display:none; } #status { min-height:48px; white-space:pre-line; } .error { color:#ffb3b3; } .success { color:#a9e2bb; }
    small { display:block; line-height:1.5; color:#b9adc3; margin-top:16px; }
  </style>
</head>
<body><main>
  <div class="eyebrow">Xar Tsaroth · Atelier connecté</div>
  <h1>Active ton Atelier</h1>
  <p>Cette page sert une seule fois, pour créer ton compte administrateur. Les comptes de l’Atelier sont indépendants de ceux de la Régie.</p>
  <p id="status" role="status" aria-live="polite">Vérification de l’activation…</p>
  <section id="verify" class="hidden">
    <h2>1. Vérifie que tu es le propriétaire</h2>
    <p>Connecte-toi avec ton compte propriétaire de la Régie. Cette vérification autorise la première activation.</p>
    <form id="owner-form">
      <label for="owner-name">Identifiant Régie</label><input id="owner-name" autocomplete="username" required maxlength="80">
      <label for="owner-password">Mot de passe Régie</label><input id="owner-password" type="password" autocomplete="current-password" required maxlength="256">
      <button type="submit">Vérifier mon compte</button>
    </form>
  </section>
  <section id="create" class="hidden">
    <h2>2. Crée ton compte Atelier</h2>
    <p>Choisis les identifiants que tu utiliseras dans le logiciel.</p>
    <form id="setup-form">
      <label for="atelier-name">Identifiant Atelier</label><input id="atelier-name" autocomplete="username" required minlength="3" maxlength="64" pattern="[A-Za-z0-9_.-]+">
      <label for="display-name">Nom affiché</label><input id="display-name" autocomplete="nickname" required maxlength="80">
      <label for="new-password">Mot de passe Atelier</label><input id="new-password" type="password" autocomplete="new-password" required minlength="12" maxlength="256">
      <label for="repeat-password">Confirme le mot de passe</label><input id="repeat-password" type="password" autocomplete="new-password" required minlength="12" maxlength="256">
      <small>Au moins 12 caractères. Conserve ce mot de passe dans ton gestionnaire habituel.</small>
      <button type="submit">Créer mon compte administrateur</button>
    </form>
  </section>
  <small>Les mots de passe sont envoyés au serveur par connexion chiffrée. Aucun mot de passe n’est enregistré dans cette page, dans son adresse ou dans le stockage du navigateur.</small>
</main>
<script nonce="<?= htmlspecialchars($nonce, ENT_QUOTES, 'UTF-8') ?>">
'use strict';
const studio = '/api/v1/image-studio';
const atelier = studio + '/atelier';
const status = document.getElementById('status');
function message(text, kind = '') { status.textContent = text; status.className = kind; }
function show(id, visible) { document.getElementById(id).classList.toggle('hidden', !visible); }
async function request(path, data) {
  const response = await fetch(path, {method:data ? 'POST' : 'GET', credentials:'same-origin', cache:'no-store',
    headers:data ? {'Content-Type':'application/json'} : {}, body:data ? JSON.stringify(data) : undefined});
  const result = await response.json().catch(() => ({}));
  if (!response.ok || result.ok === false) throw new Error(result.message || result.error?.message || 'La vérification a échoué. Vérifie tes identifiants ou réessaie plus tard.');
  return result;
}
function busy(form, value) { form.querySelector('button').disabled = value; }
async function check() {
  const state = await request(atelier + '/auth/setup');
  if (!state.setupRequired) {
    show('verify',false); show('create',false);
    message('L’Atelier est déjà activé. Ouvre le logiciel et connecte-toi avec ton compte Atelier.', 'success');
    return;
  }
  show('verify',true);
  message('La première activation est disponible.');
}
document.getElementById('owner-form').addEventListener('submit', async event => {
  event.preventDefault(); const form = event.currentTarget; busy(form,true);
  let password = document.getElementById('owner-password').value;
  document.getElementById('owner-password').value = '';
  try {
    await request(studio + '/auth/login', {username:document.getElementById('owner-name').value.trim(),password});
    message('Compte vérifié. Choisis maintenant tes identifiants Atelier.');
    show('verify',false); show('create',true); document.getElementById('atelier-name').focus();
  } catch (error) { message(error.message,'error'); }
  finally { password = ''; busy(form,false); }
});
document.getElementById('setup-form').addEventListener('submit', async event => {
  event.preventDefault(); const form = event.currentTarget;
  let password = document.getElementById('new-password').value;
  if (password !== document.getElementById('repeat-password').value) { message('Les deux mots de passe Atelier ne correspondent pas.','error'); return; }
  busy(form,true); document.getElementById('new-password').value = ''; document.getElementById('repeat-password').value = '';
  try {
    await request(atelier + '/auth/setup', {username:document.getElementById('atelier-name').value.trim(),displayName:document.getElementById('display-name').value.trim(),password});
    show('create',false); message('Ton compte administrateur est créé. Ouvre l’Atelier connecté et connecte-toi avec tes nouveaux identifiants. Tu pourras ensuite créer les comptes de tes utilisateurs.', 'success');
  } catch (error) { message(error.message,'error'); }
  finally { password = ''; busy(form,false); }
});
check().catch(error => message(error.message,'error'));
</script></body></html>
