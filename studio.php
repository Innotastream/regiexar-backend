<?php

declare(strict_types=1);

$nonce = rtrim(strtr(base64_encode(random_bytes(18)), '+/', '-_'), '=');
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');
header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
header(
    "Content-Security-Policy: default-src 'none'; base-uri 'none'; frame-ancestors 'none'; "
    . "form-action 'none'; img-src 'self' blob:; connect-src 'self'; "
    . "style-src 'nonce-{$nonce}'; script-src 'nonce-{$nonce}'"
);

?><!doctype html>
<html lang="fr">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex, nofollow">
  <meta name="description" content="Collection privée des images générées par les MJ de Xar Tsaroth.">
  <title>Studio d’images — Xar Tsaroth Régie</title>
  <style nonce="<?= htmlspecialchars($nonce, ENT_QUOTES, 'UTF-8') ?>">
    :root { color-scheme: dark; font-family: Inter, ui-sans-serif, system-ui, sans-serif; background: #070611; color: #eee8da; }
    * { box-sizing: border-box; }
    body { min-height: 100vh; margin: 0; background: radial-gradient(circle at 16% 0%, #21102f 0, transparent 34rem), #070611; }
    button, input { font: inherit; }
    button { cursor: pointer; }
    .hidden { display: none !important; }
    .shell { width: min(1180px, calc(100% - 32px)); margin: 0 auto; padding: 28px 0 64px; }
    header { display: flex; align-items: center; justify-content: space-between; gap: 18px; margin-bottom: 28px; }
    .brand small { display: block; color: #a98bc9; font-size: 11px; font-weight: 800; letter-spacing: .17em; text-transform: uppercase; }
    h1 { margin: 5px 0 0; color: #ead9b5; font-family: Georgia, serif; font-size: clamp(25px, 4vw, 40px); font-weight: 500; }
    .panel { border: 1px solid rgba(203, 170, 111, .22); border-radius: 16px; background: rgba(14, 10, 25, .84); box-shadow: 0 24px 70px rgba(0, 0, 0, .32); }
    .login { width: min(460px, 100%); margin: 9vh auto 0; padding: 28px; }
    .login h2 { margin: 0 0 7px; font-family: Georgia, serif; font-size: 25px; }
    .muted { color: #aaa2b2; line-height: 1.55; }
    label { display: grid; gap: 7px; margin-top: 15px; color: #c7bdca; font-size: 12px; font-weight: 700; }
    input { width: 100%; min-height: 44px; border: 1px solid rgba(177, 146, 207, .3); border-radius: 9px; padding: 0 12px; background: #0b0915; color: #f5f0e7; }
    input:focus { outline: 3px solid rgba(155, 124, 236, .15); border-color: #a988da; }
    .button { min-height: 40px; border: 1px solid rgba(199, 165, 103, .35); border-radius: 9px; padding: 0 15px; background: rgba(122, 89, 40, .24); color: #ead9b5; font-weight: 800; }
    .button.primary { background: linear-gradient(135deg, #8f6533, #6c3e62); color: #fff8e9; }
    .button.ghost { border-color: rgba(166, 140, 201, .25); background: rgba(53, 38, 77, .24); color: #c7b8da; }
    .button.danger { border-color: rgba(210, 88, 106, .33); background: rgba(103, 30, 45, .24); color: #efb5be; }
    .login .button { width: 100%; margin-top: 19px; }
    .error { min-height: 20px; margin: 10px 0 0; color: #ef9aa7; font-size: 12px; }
    .toolbar { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 12px; margin-bottom: 18px; padding: 14px 16px; }
    .identity { display: grid; gap: 3px; }
    .identity strong { color: #f0e2c5; }
    .identity small { color: #92899c; }
    .toolbar-actions { display: flex; flex-wrap: wrap; gap: 8px; }
    .notice { margin: 0 0 20px; padding: 12px 14px; border: 1px solid rgba(141, 111, 196, .22); border-radius: 10px; background: rgba(63, 40, 93, .18); color: #beb3cb; font-size: 12px; line-height: 1.5; }
    .page-message { min-height: 20px; margin: -8px 0 14px; color: #bdb2c7; font-size: 12px; }
    .page-message.error { color: #ef9aa7; }
    .gallery { display: grid; grid-template-columns: repeat(auto-fill, minmax(260px, 1fr)); gap: 16px; }
    .card { min-width: 0; overflow: hidden; border: 1px solid rgba(194, 164, 105, .18); border-radius: 13px; background: rgba(12, 10, 21, .91); }
    .card img { width: 100%; aspect-ratio: 16 / 10; display: block; object-fit: cover; background: #05050b; }
    .card-copy { display: grid; gap: 8px; padding: 14px; }
    .card h2 { margin: 0; overflow: hidden; color: #eadab9; font-family: Georgia, serif; font-size: 17px; font-weight: 500; text-overflow: ellipsis; white-space: nowrap; }
    .meta { color: #9e94a8; font-size: 11px; }
    .prompt { display: -webkit-box; min-height: 39px; margin: 0; overflow: hidden; color: #c8c0ca; font-size: 12px; line-height: 1.5; -webkit-box-orient: vertical; -webkit-line-clamp: 2; }
    .card-actions { display: flex; flex-wrap: wrap; gap: 7px; }
    .card-actions .button { min-height: 34px; padding: 0 10px; font-size: 11px; }
    .empty { grid-column: 1 / -1; padding: 56px 24px; text-align: center; }
    .empty strong { display: block; margin-bottom: 7px; color: #d8c7a7; font-family: Georgia, serif; font-size: 21px; }
    .history-layout { display: grid; grid-template-columns: minmax(210px, 280px) minmax(0, 1fr); gap: 16px; }
    .history-conversations, .history-messages { min-height: 420px; padding: 12px; }
    .history-conversations { display: grid; align-content: start; gap: 7px; }
    .history-conversation-row { display: grid; grid-template-columns: minmax(0, 1fr) auto; align-items: stretch; gap: 6px; }
    .history-conversation { display: grid; gap: 3px; width: 100%; padding: 10px; border: 1px solid rgba(166, 140, 201, .2); border-radius: 9px; background: rgba(35, 23, 51, .35); color: #d4c8dd; text-align: left; }
    .history-conversation.selected { border-color: rgba(215, 183, 112, .55); background: rgba(105, 72, 52, .26); }
    .history-conversation small { color: #8e8498; font-size: 10px; }
    .history-messages { display: grid; align-content: start; gap: 10px; }
    .history-entry { padding: 12px; border: 1px solid rgba(177, 146, 207, .17); border-radius: 10px; background: rgba(10, 8, 18, .7); }
    .history-entry header { margin: 0 0 8px; color: #9f93aa; font-size: 10px; }
    .history-entry p { margin: 0; color: #d4ccd6; font-size: 12px; line-height: 1.5; white-space: pre-wrap; }
    .history-entry img { width: min(100%, 520px); max-height: 360px; margin-top: 10px; object-fit: contain; border-radius: 8px; background: #030308; }
    .audit-badge { display: inline-block; margin-left: 7px; padding: 2px 6px; border-radius: 999px; background: rgba(171, 63, 82, .2); color: #e6aab4; }
    .history-delete { min-width: 42px; padding: 0 9px; }
    dialog { width: min(460px, calc(100% - 28px)); border: 1px solid rgba(203, 170, 111, .34); border-radius: 15px; padding: 0; background: #100b1b; color: #eee8da; box-shadow: 0 28px 90px rgba(0, 0, 0, .72); }
    dialog::backdrop { background: rgba(2, 2, 8, .76); backdrop-filter: blur(4px); }
    dialog form { display: grid; gap: 12px; padding: 22px; }
    dialog h2, dialog p { margin: 0; }
    dialog h2 { color: #ead9b5; font-family: Georgia, serif; font-weight: 500; }
    .dialog-actions { display: flex; justify-content: flex-end; gap: 8px; margin-top: 5px; }
    @media (max-width: 640px) { .shell { width: min(100% - 20px, 1180px); padding-top: 18px; } header { align-items: flex-start; } .toolbar { align-items: flex-start; } .history-layout { grid-template-columns: 1fr; } .history-conversations { min-height: 0; } }
  </style>
</head>
<body>
  <main class="shell">
    <header>
      <div class="brand"><small>Xar Tsaroth · Régie du Seuil</small><h1>Studio d’images</h1></div>
      <a class="button ghost" href="/confidentialite">Confidentialité</a>
    </header>

    <section id="loginPanel" class="panel login hidden" aria-labelledby="loginTitle">
      <h2 id="loginTitle">Connexion MJ</h2>
      <p class="muted">Cette connexion limitée ouvre uniquement votre collection d’images. Elle ne ferme pas votre session dans l’application Régie.</p>
      <form id="loginForm">
        <label>Identifiant MJ<input id="username" name="username" autocomplete="username" minlength="3" maxlength="64" required></label>
        <label>Mot de passe<input id="password" name="password" type="password" autocomplete="current-password" minlength="10" maxlength="256" required></label>
        <button class="button primary" type="submit">Ouvrir ma collection</button>
        <p id="loginError" class="error" role="alert"></p>
      </form>
    </section>

    <section id="studioPanel" class="hidden">
      <div class="panel toolbar">
        <div class="identity"><strong id="identityName"></strong><small id="identityRole">Collection privée MJ</small></div>
        <div class="toolbar-actions">
          <button id="historyButton" class="button ghost hidden" type="button">Journal administrateur</button>
          <button id="scopeButton" class="button ghost hidden" type="button">Voir toutes les collections</button>
          <button id="refreshButton" class="button ghost" type="button">Actualiser</button>
          <button id="logoutButton" class="button" type="button">Se déconnecter</button>
        </div>
      </div>
      <p id="pageMessage" class="page-message" role="status" aria-live="polite"></p>
      <p class="notice">« Fermer l’aperçu » libère seulement de la place sur cet écran. « Retirer » masque l’image de la collection. L’administrateur peut aussi supprimer définitivement une discussion et son journal.</p>
      <div id="gallery" class="gallery" aria-live="polite"></div>
      <section id="historyPanel" class="hidden" aria-label="Journal administrateur des conversations">
        <p class="notice">Journal de sécurité complet : conversations ouvertes ou fermées, demandes réussies, échouées ou retirées. Cet historique est réservé à Innota administrateur.</p>
        <div class="history-layout">
          <aside id="historyConversations" class="panel history-conversations"></aside>
          <div id="historyMessages" class="panel history-messages" aria-live="polite"></div>
        </div>
      </section>
    </section>

    <dialog id="removeDialog" aria-labelledby="removeDialogTitle" aria-describedby="removeDialogMessage">
      <form method="dialog">
        <small>Collection privée</small>
        <h2 id="removeDialogTitle">Retirer cette image ?</h2>
        <p id="removeDialogMessage" class="muted">Elle disparaîtra de votre collection. Le journal restera accessible à l’administrateur.</p>
        <div class="dialog-actions"><button class="button ghost" type="submit" value="cancel">Annuler</button><button class="button danger" type="submit" value="confirm">Retirer l’image</button></div>
      </form>
    </dialog>

    <dialog id="deleteConversationDialog" aria-labelledby="deleteConversationDialogTitle" aria-describedby="deleteConversationDialogMessage">
      <form method="dialog">
        <small>Administration</small>
        <h2 id="deleteConversationDialogTitle">Supprimer définitivement cette discussion ?</h2>
        <p id="deleteConversationDialogMessage" class="muted"></p>
        <p class="error">La conversation, ses demandes et son journal seront effacés sans possibilité de restauration. Les médias déjà publiés ou utilisés restent protégés.</p>
        <div class="dialog-actions"><button class="button ghost" type="submit" value="cancel">Annuler</button><button class="button danger" type="submit" value="confirm">Supprimer définitivement</button></div>
      </form>
    </dialog>
  </main>

  <script nonce="<?= htmlspecialchars($nonce, ENT_QUOTES, 'UTF-8') ?>">
    const apiRoot = "/api/v1/image-studio";
    const loginPanel = document.querySelector("#loginPanel");
    const studioPanel = document.querySelector("#studioPanel");
    const gallery = document.querySelector("#gallery");
    const scopeButton = document.querySelector("#scopeButton");
    const historyButton = document.querySelector("#historyButton");
    const historyPanel = document.querySelector("#historyPanel");
    const historyConversations = document.querySelector("#historyConversations");
    const historyMessages = document.querySelector("#historyMessages");
    const pageMessage = document.querySelector("#pageMessage");
    const removeDialog = document.querySelector("#removeDialog");
    const deleteConversationDialog = document.querySelector("#deleteConversationDialog");
    let identity = null;
    let allCollections = false;
    let historyVisible = false;
    let selectedHistoryConversationId = "";

    function notify(message = "", error = false) {
      pageMessage.textContent = message;
      pageMessage.classList.toggle("error", error);
    }

    function confirmRemoval() {
      return new Promise((resolve) => {
        const closed = () => resolve(removeDialog.returnValue === "confirm");
        removeDialog.addEventListener("close", closed, { once: true });
        removeDialog.showModal();
      });
    }

    function confirmPermanentConversationDeletion(conversation) {
      document.querySelector("#deleteConversationDialogMessage").textContent = `« ${conversation.title} » · ${conversation.messageCount || 0} demande(s).`;
      return new Promise((resolve) => {
        const closed = () => resolve(deleteConversationDialog.returnValue === "confirm");
        deleteConversationDialog.addEventListener("close", closed, { once: true });
        deleteConversationDialog.showModal();
      });
    }

    async function request(path, options = {}) {
      const response = await fetch(`${apiRoot}${path}`, {
        method: options.method || "GET",
        credentials: "same-origin",
        headers: options.body ? { "Content-Type": "application/json" } : {},
        body: options.body ? JSON.stringify(options.body) : undefined
      });
      const payload = await response.json().catch(() => ({}));
      if (!response.ok) throw Object.assign(new Error(payload.error || "Demande refusée."), { status: response.status, code: payload.code || "request_failed" });
      return payload;
    }

    function showLogin() {
      identity = null;
      historyVisible = false;
      historyButton.textContent = "Journal administrateur";
      historyPanel.classList.add("hidden");
      gallery.classList.remove("hidden");
      loginPanel.classList.remove("hidden");
      studioPanel.classList.add("hidden");
      document.querySelector("#password").value = "";
      notify();
    }

    function showStudio(account) {
      identity = account;
      loginPanel.classList.add("hidden");
      studioPanel.classList.remove("hidden");
      document.querySelector("#identityName").textContent = account.displayName || account.username;
      document.querySelector("#identityRole").textContent = account.canAdministrate ? "MJ administrateur" : "Collection privée MJ";
      scopeButton.classList.toggle("hidden", !account.canAdministrate);
      historyButton.classList.toggle("hidden", !account.canAdministrate);
      notify();
    }

    function element(name, className = "", text = "") {
      const node = document.createElement(name);
      if (className) node.className = className;
      if (text) node.textContent = text;
      return node;
    }

    function imageCard(item) {
      const card = element("article", "card");
      const image = element("img");
      image.src = item.imageUrl;
      image.alt = item.prompt ? `Image : ${item.prompt.slice(0, 120)}` : "Image générée dans le studio Xar Tsaroth";
      image.loading = "lazy";
      const copy = element("div", "card-copy");
      copy.append(element("h2", "", item.conversationTitle || "Vision de Xar Tsaroth"));
      const owner = item.author?.displayName || item.author?.username || "MJ";
      const date = item.completedAt ? new Date(item.completedAt.replace(" ", "T") + "Z").toLocaleString("fr-FR") : "";
      copy.append(element("div", "meta", `${owner} · ${date}`));
      copy.append(element("p", "prompt", item.prompt || "Sans description"));
      const actions = element("div", "card-actions");
      const closePreview = element("button", "button ghost", "Fermer l’aperçu");
      closePreview.type = "button";
      closePreview.addEventListener("click", () => {
        card.remove();
        notify("Aperçu fermé. L’image reste dans votre collection et réapparaîtra à la prochaine actualisation.");
      });
      actions.append(closePreview);
      const download = element("button", "button ghost", "Télécharger");
      download.type = "button";
      download.addEventListener("click", async () => {
        download.disabled = true;
        try {
          const response = await fetch(item.imageUrl, { credentials: "same-origin" });
          if (!response.ok) throw new Error("Téléchargement refusé.");
          const blob = await response.blob();
          const url = URL.createObjectURL(blob);
          const anchor = document.createElement("a");
          anchor.href = url;
          const extension = new Map([["image/jpeg", "jpg"], ["image/webp", "webp"]]).get(blob.type) || "png";
          anchor.download = `xar-tsaroth-${item.id}.${extension}`;
          anchor.click();
          setTimeout(() => URL.revokeObjectURL(url), 1000);
          notify("Image téléchargée.");
        } catch (error) { notify(error.message, true); }
        finally { download.disabled = false; }
      });
      actions.append(download);
      if (item.shareUrl) {
        const shared = element("a", "button ghost", "Page publique");
        shared.href = item.shareUrl;
        shared.target = "_blank";
        shared.rel = "noopener noreferrer";
        actions.append(shared);
      }
      if (item.author?.id === identity?.id) {
        const remove = element("button", "button danger", "Retirer");
        remove.type = "button";
        remove.addEventListener("click", async () => {
          if (!await confirmRemoval()) return;
          remove.disabled = true;
          try {
            await request(`/messages/${encodeURIComponent(item.id)}`, { method: "DELETE" });
            notify("Image retirée de votre collection. Le journal administrateur est conservé.");
            await loadGallery();
          } catch (error) { notify(error.message, true); remove.disabled = false; }
        });
        actions.append(remove);
      }
      copy.append(actions);
      card.append(image, copy);
      return card;
    }

    async function loadGallery() {
      gallery.replaceChildren(element("div", "panel empty", "Chargement de la collection…"));
      try {
        const payload = await request(`/gallery${allCollections ? "?scope=all" : ""}`);
        gallery.replaceChildren();
        if (!payload.images.length) {
          const empty = element("div", "panel empty");
          empty.append(element("strong", "", "Aucune image dans cette collection"), element("span", "muted", "Les générations ajoutées depuis la Régie apparaîtront ici."));
          gallery.append(empty);
          return;
        }
        gallery.append(...payload.images.map(imageCard));
      } catch (error) {
        if (error.status === 401) return showLogin();
        gallery.replaceChildren(element("div", "panel empty", error.message));
      }
    }

    function renderHistoryMessages(payload) {
      historyMessages.replaceChildren();
      const messages = payload.messages || [];
      if (!messages.length) {
        historyMessages.append(element("div", "empty", "Cette conversation ne contient aucune demande."));
        return;
      }
      for (const item of messages) {
        const entry = element("article", "history-entry");
        const heading = element("header", "", `${item.operation || "generate"} · ${item.status || "inconnu"} · ${item.createdAt || ""}`);
        if (item.hidden) heading.append(element("span", "audit-badge", "retirée par le MJ"));
        entry.append(heading, element("p", "", item.prompt || "Sans description"));
        if (item.imageUrl) {
          const image = element("img");
          image.src = item.imageUrl;
          image.alt = "Résultat conservé dans le journal du Studio";
          image.loading = "lazy";
          entry.append(image);
        }
        if (item.error?.message) entry.append(element("p", "error", item.error.message));
        historyMessages.append(entry);
      }
    }

    async function loadHistoryMessages(conversationId) {
      selectedHistoryConversationId = conversationId;
      for (const button of historyConversations.querySelectorAll("button")) button.classList.toggle("selected", button.dataset.id === conversationId);
      historyMessages.replaceChildren(element("div", "empty", "Chargement du journal…"));
      try { renderHistoryMessages(await request(`/conversations/${encodeURIComponent(conversationId)}/messages?audit=1`)); }
      catch (error) { historyMessages.replaceChildren(element("div", "empty", error.message)); }
    }

    async function loadHistory() {
      historyConversations.replaceChildren(element("div", "empty", "Chargement…"));
      historyMessages.replaceChildren(element("div", "empty", "Choisissez une conversation."));
      try {
        const payload = await request("/conversations?scope=all&archived=1");
        historyConversations.replaceChildren();
        if (!payload.conversations.length) {
          historyConversations.append(element("div", "empty", "Aucune conversation enregistrée."));
          return;
        }
        for (const conversation of payload.conversations) {
          const row = element("div", "history-conversation-row");
          const button = element("button", "history-conversation");
          button.type = "button";
          button.dataset.id = conversation.id;
          button.append(element("strong", "", conversation.title), element("small", "", `${conversation.owner?.displayName || conversation.owner?.username || "MJ"} · ${conversation.messageCount || 0} demande(s)${conversation.archived ? " · fermée" : ""}`));
          button.addEventListener("click", () => loadHistoryMessages(conversation.id));
          const remove = element("button", "button danger history-delete", "×");
          remove.type = "button";
          remove.title = `Supprimer définitivement ${conversation.title}`;
          remove.setAttribute("aria-label", remove.title);
          remove.addEventListener("click", async () => {
            if (!await confirmPermanentConversationDeletion(conversation)) return;
            remove.disabled = true;
            try {
              await request(`/conversations/${encodeURIComponent(conversation.id)}`, { method: "DELETE" });
              if (selectedHistoryConversationId === conversation.id) selectedHistoryConversationId = "";
              notify("Discussion et journal supprimés définitivement.");
              await loadHistory();
            } catch (error) { notify(error.message, true); remove.disabled = false; }
          });
          row.append(button, remove);
          historyConversations.append(row);
        }
        const target = payload.conversations.some((entry) => entry.id === selectedHistoryConversationId) ? selectedHistoryConversationId : payload.conversations[0].id;
        await loadHistoryMessages(target);
      } catch (error) {
        historyConversations.replaceChildren(element("div", "empty", error.message));
      }
    }

    document.querySelector("#loginForm").addEventListener("submit", async (event) => {
      event.preventDefault();
      const error = document.querySelector("#loginError");
      error.textContent = "";
      const submit = event.currentTarget.querySelector("button");
      submit.disabled = true;
      try {
        const payload = await request("/auth/login", { method: "POST", body: { username: document.querySelector("#username").value, password: document.querySelector("#password").value } });
        showStudio(payload.account);
        await loadGallery();
      } catch (failure) { error.textContent = failure.message; }
      finally { submit.disabled = false; }
    });
    document.querySelector("#refreshButton").addEventListener("click", () => historyVisible ? loadHistory() : loadGallery());
    historyButton.addEventListener("click", async () => {
      historyVisible = !historyVisible;
      historyButton.textContent = historyVisible ? "Revenir à la galerie" : "Journal administrateur";
      gallery.classList.toggle("hidden", historyVisible);
      scopeButton.classList.toggle("hidden", historyVisible || !identity?.canAdministrate);
      historyPanel.classList.toggle("hidden", !historyVisible);
      if (historyVisible) await loadHistory();
    });
    scopeButton.addEventListener("click", async () => {
      allCollections = !allCollections;
      scopeButton.textContent = allCollections ? "Voir ma collection" : "Voir toutes les collections";
      await loadGallery();
    });
    document.querySelector("#logoutButton").addEventListener("click", async () => {
      try { await request("/auth/logout", { method: "POST" }); } catch {}
      showLogin();
    });

    request("/auth/me").then(async (payload) => { showStudio(payload.account); await loadGallery(); }).catch(showLogin);
  </script>
</body>
</html>
