import assert from "node:assert/strict";
import { readFile } from "node:fs/promises";
import test from "node:test";

const root = new URL("../", import.meta.url);
const read = (relative) => readFile(new URL(relative, root), "utf8");

function phpBlocks(source) {
  return [...source.matchAll(/<\?php([\s\S]*?)(?:\?>|$)/g)].map((match) => match[1]).join("\n");
}

function balancedPhpDelimiters(source) {
  const code = phpBlocks(source);
  const stack = [];
  const pairs = new Map([[")", "("], ["]", "["], ["}", "{"]]);
  let quote = "";
  let lineComment = false;
  let blockComment = false;
  for (let index = 0; index < code.length; index += 1) {
    const character = code[index];
    const next = code[index + 1] ?? "";
    if (lineComment) {
      if (character === "\n") lineComment = false;
      continue;
    }
    if (blockComment) {
      if (character === "*" && next === "/") {
        blockComment = false;
        index += 1;
      }
      continue;
    }
    if (quote) {
      if (character === "\\") {
        index += 1;
      } else if (character === quote) {
        quote = "";
      }
      continue;
    }
    if (character === "/" && next === "/" || character === "#") {
      lineComment = true;
      if (character === "/") index += 1;
      continue;
    }
    if (character === "/" && next === "*") {
      blockComment = true;
      index += 1;
      continue;
    }
    if (character === "'" || character === '"') {
      quote = character;
      continue;
    }
    if ("([{ ".includes(character) && character !== " ") stack.push(character);
    if (pairs.has(character) && stack.pop() !== pairs.get(character)) return false;
  }
  return !quote && !blockComment && stack.length === 0;
}

test("les sources PHP ont des délimiteurs structurels équilibrés", async () => {
  for (const file of ["api/v1/index.php", "api/v1/online.php", "api/v1/domains.php", "api/v1/image-studio.php", "index.php", "initialisation.php", "recuperation.php", "studio.php"]) {
    assert.equal(balancedPhpDelimiters(await read(file)), true, file);
  }
});

test("le backend 0.8.0 installe le studio et conserve la migration révisionnée 1.15", async () => {
  const [index, domains, manifest] = await Promise.all([read("api/v1/index.php"), read("api/v1/domains.php"), read("manifest.json")]);
  assert.match(index, /XAR_BACKEND_VERSION = '0\.8\.0'/);
  assert.match(index, /revisioned_domains_and_media_retention/);
  assert.match(index, /private_codex_image_studio/);
  assert.match(index, /image_studio_conversations/);
  assert.match(index, /image_studio_messages/);
  assert.match(index, /image_reference_catalog/);
  assert.match(index, /application_domain_clock/);
  assert.match(domains, /XAR_SESSION_SCHEMA_VERSION = 11/);
  assert.match(domains, /legacyStateToDomains/);
  assert.equal(JSON.parse(manifest).databaseSchemaVersion, 8);
  assert.equal(JSON.parse(manifest).imageStudioMinimumApplicationVersion, "2.0.0");
});

test("le studio sépare les secrets Codex, les propriétaires et l’audit administrateur", async () => {
  const [studio, online, index] = await Promise.all([
    read("api/v1/image-studio.php"), read("api/v1/online.php"), read("api/v1/index.php")
  ]);
  assert.match(studio, /xar_studio_session/);
  assert.match(studio, /Path=\/api\/v1\/image-studio/);
  assert.match(studio, /limitedGallerySession/);
  assert.match(studio, /conversation_forbidden/);
  assert.match(studio, /message_forbidden/);
  assert.match(studio, /media_forbidden/);
  assert.match(studio, /function imageStudioMediaUsedByCatalog/);
  assert.match(studio, /\$catalogued \|\| imageStudioMediaUsedByCurrentDomain/);
  assert.match(studio, /mediaDomainReferenceCount[\s\S]*?!imageStudioMediaUsedByCatalog/);
  assert.match(studio, /historyRetainedForAdministrator/);
  assert.match(studio, /owner_hidden_at/);
  assert.match(studio, /generation_already_active/);
  assert.match(studio, /INTERVAL 30 MINUTE/);
  assert.match(studio, /qualityRequested[^\n]+high/);
  assert.match(studio, /qualityApplied[^\n]+null/);
  assert.match(studio, /media_content_type/);
  assert.match(studio, /XAR_IMAGE_STUDIO_MAX_REFERENCES = 5/);
  assert.match(studio, /'subjectId' => 'site-' \. \$characterId/);
  assert.match(studio, /function xarTsarothReferenceViewRank/);
  assert.match(studio, /str_contains\(\$file, '_front'\)[\s\S]*?return 0/);
  assert.match(studio, /usort\(\$files,[\s\S]*?xarTsarothReferenceViewRank/);
  assert.equal((studio.match(/\['eiko(?:-perso)?', 'Eiko'/g) ?? []).length, 1);
  assert.doesNotMatch(studio, /OPENAI_API_KEY|auth\.json|ChatGPT.*token|codex.*token/i);
  assert.match(online, /imageStudioMediaOwner/);
  assert.match(online, /assertImageStudioMediaAccess/);
  assert.match(index, /!str_starts_with\(\$route, '\/api\/v1\/image-studio'\)/);
});

test("la galerie web reste MJ, privée et explicite sur la conservation", async () => {
  const [page, rules, privacy] = await Promise.all([read("studio.php"), read(".htaccess"), read("confidentialite.html")]);
  assert.match(rules, /\^studio\/\?\$/);
  assert.match(page, /Content-Security-Policy/);
  assert.match(page, /form-action 'none'/);
  assert.match(page, /Connexion MJ/);
  assert.match(page, /scope=all/);
  assert.match(page, /journal restera accessible à l’administrateur/);
  assert.match(page, /id="removeDialog"/);
  assert.match(page, /new Map\(\[\["image\/jpeg", "jpg"\], \["image\/webp", "webp"\]\]\)/);
  assert.doesNotMatch(page, /\balert\s*\(|\bconfirm\s*\(/);
  assert.match(privacy, /Studio d’images/);
  assert.match(privacy, /aucun jeton Codex/);
  assert.match(privacy, /30 jours/);
});

test("l’ancien état global est en lecture seule et les commandes sont ciblées", async () => {
  const online = await read("api/v1/online.php");
  const command = online.slice(online.indexOf("function commandOnlineState"), online.indexOf("function openOnlineConnection"));
  assert.match(online, /domain_client_required/);
  assert.doesNotMatch(command, /applicationStateRecord|domainApplicationStateRecord|writeDomainsFromStateInTransaction/);
  assert.match(command, /onlineTokenDomainKey/);
  assert.match(command, /applicationCharacterTokenDomainRecords/);
  assert.match(command, /persistDomainChangesInTransaction/);
  const administrativeDeletion = command.slice(
    command.indexOf("$command === 'admin.character.delete'"),
    command.indexOf("$result['character']", command.indexOf("$command === 'admin.character.delete'")) + 500
  );
  assert.match(administrativeDeletion, /applicationCharacterTokenDomainRecords/);
  assert.match(administrativeDeletion, /token-index:/);
  assert.match(administrativeDeletion, /initiative:/);
  assert.match(administrativeDeletion, /presentation:/);
  assert.match(administrativeDeletion, /detached-combat/);
  assert.match(administrativeDeletion, /actionTimerTombstones/);
  assert.match(administrativeDeletion, /character_owner_changed/);
  assert.match(command, /\$command === 'character\.delete' && !\$isGm/);
  assert.match(command, /\$ownerPlayerId = \$selfDelete[\s\S]*?\? \$accountId/);
  assert.match(command, /\$isGm && !in_array\(\$command, \['ensure-player', 'admin\.character\.delete'\], true\)/);
  assert.match(command, /player_mode_required/);
  const timerDelete = command.slice(command.indexOf("$command === 'timer.update'"), command.indexOf("$command === 'character.delete'"));
  assert.match(timerDelete, /actionTimerTombstones/);
  assert.match(timerDelete, /deletedTimerId/);
});

test("le rapprochement automatique répare aussi un compte déjà présent en double", async () => {
  const online = await read("api/v1/online.php");
  const command = online.slice(online.indexOf("function commandOnlineState"), online.indexOf("function openOnlineConnection"));
  const ensurePlayer = command.slice(command.indexOf("$command === 'ensure-player'"), command.indexOf("$command === 'preferences.update'"));
  assert.match(online, /function rosterRepairAliases[\s\S]*?\$username === 'innota' \? \['inho'\] : \[\]/);
  assert.match(ensurePlayer, /\$accountIndex = findEntryIndex\(\$players, \$accountId\)/);
  assert.match(ensurePlayer, /\$aliases = \$accountIndex >= 0 \? rosterRepairAliases\(\$identity\) : rosterAliases\(\$identity\)/);
  assert.match(ensurePlayer, /\$rosterChanged = false/);
  assert.match(ensurePlayer, /if \(\$pendingIndex >= 0\)[\s\S]*?array_splice\(\$players, \$pendingIndex, 1\)/);
  assert.match(ensurePlayer, /\$oldId[\s\S]*?\$payload\['ownerPlayerId'\] = \$accountId/);
  assert.match(ensurePlayer, /str_starts_with\(\$key, 'presentation:'\) \|\| \$key === 'detached-combat'/);
  assert.match(ensurePlayer, /\$activity\['actionTimers'\]\[\$index\]\['ownerPlayerId'\] = \$accountId/);
});

test("SSE et vue joueur évitent la reconstruction de toutes les scènes", async () => {
  const [online, domains] = await Promise.all([read("api/v1/online.php"), read("api/v1/domains.php")]);
  const events = online.slice(online.indexOf("function onlineEvents"), online.indexOf("function privateMediaDirectory"));
  assert.doesNotMatch(events, /domainApplicationStateRecord|domainsToApplicationState/);
  assert.match(events, /global_revision FROM application_domain_clock/);
  assert.match(domains, /function playerApplicationStateRecord/);
  assert.match(domains, /\$hasActiveScene \? \[\$sceneId\] : \[\]/);
});

test("les vues administratives chargent les personnages sans reconstruire l’état global", async () => {
  const domains = await read("api/v1/domains.php");
  const reader = domains.slice(domains.indexOf("function readApplicationDomains"), domains.indexOf("function patchApplicationDomains"));
  assert.match(domains, /function applicationDomainRecordsByPrefix/);
  assert.match(domains, /'character:'/);
  assert.match(reader, /applicationDomainRecordsByPrefix\(\$connection, \$prefix\)/);
  assert.match(reader, /invalid_domain_selection/);
});

test("les secrets ont une clé indépendante et les médias une rétention", async () => {
  const [online, domains, index] = await Promise.all([read("api/v1/online.php"), read("api/v1/domains.php"), read("api/v1/index.php")]);
  assert.match(online, /settingsEncryptionKey/);
  assert.match(online, /previousSettingsEncryptionKeys/);
  assert.match(online, /settings_encryption_key_required/);
  assert.match(index, /pending_delete_at/);
  assert.match(online, /maximumRetainedBytes/);
  assert.match(online, /xar-regie-media-quota/);
  assert.match(online, /storedMediaMatchesContentType/);
  assert.match(online, /media_signature_mismatch/);
  assert.match(online, /media_upload_incomplete/);
  assert.match(online, /\$size !== \$declared/);
  assert.match(online, /getimagesize/);
  assert.match(online, /\$record\['pending_delete_at'\] !== null/);
  assert.match(domains, /INTERVAL 30 DAY/);
  assert.match(domains, /reactivateDomainMedia/);
  assert.match(domains, /validApplicationDomainIdentifierList/);
  assert.match(domains, /invalid_scene_index_domain/);
  assert.match(domains, /invalid_roster_domain/);
});

test("les domaines bornent aussi les structures imbriquées et les registres secondaires", async () => {
  const [domains, online] = await Promise.all([read("api/v1/domains.php"), read("api/v1/online.php")]);
  assert.match(domains, /validApplicationDomainShape\(\$payload\)/);
  assert.match(domains, /playerPreferences[^\n]+1000/);
  assert.match(domains, /playerTombstones[^\n]+2000/);
  assert.match(domains, /characterTombstones[^\n]+2000/);
  assert.match(domains, /validApplicationCharacterDomain\(\$payload\)/);
  assert.match(domains, /\$payload\['map'\]\['tokens'\][^\n]+2000/);
  assert.match(online, /\$current\['characterSchemaVersion'\] = 2/);
  assert.doesNotMatch(online, /'characterSchema', 'characterSchemaVersion',/);
  assert.match(online, /player_limit/);
  assert.match(online, /character_limit/);
  assert.match(online, /timer_limit/);
});

test("plusieurs MJ sont sérialisés par transaction sans verrou de session global", async () => {
  const [domains, online, index] = await Promise.all([
    read("api/v1/domains.php"), read("api/v1/online.php"), read("api/v1/index.php")
  ]);
  const patch = domains.slice(domains.indexOf("function patchApplicationDomains"), domains.indexOf("function readDomainHistory"));
  assert.match(patch, /beginTransaction\(\)/);
  assert.match(patch, /domainClockRecord\(\$connection, true\)/);
  assert.match(patch, /expectedRevision/);
  assert.match(patch, /domain_revision_conflict/);
  assert.match(patch, /rollBack\(\)/);
  assert.match(online, /GROUP BY a\.id, a\.display_name, s\.effective_mode/);
  assert.match(index, /UNIQUE KEY uq_auth_sessions_account \(account_id\)/);
  assert.doesNotMatch(index, /UNIQUE KEY[^\n]+effective_mode/);
});

test("les fichiers de contrôle ne sont pas servis par Apache", async () => {
  const rules = await read(".htaccess");
  assert.match(rules, /tests\|\\\.github|tests\|\.github|tests\|\\\\\.github/);
  assert.match(rules, /\|md/);
});
