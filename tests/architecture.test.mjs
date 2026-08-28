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
  for (const file of ["api/v1/index.php", "api/v1/online.php", "api/v1/domains.php", "api/v1/image-studio.php", "api/v1/health-overlays.php", "index.php", "initialisation.php", "recuperation.php", "studio.php"]) {
    assert.equal(balancedPhpDelimiters(await read(file)), true, file);
  }
});

test("le backend 0.12.12 conserve la file Codex partagée et la migration révisionnée 1.15", async () => {
  const [index, domains, manifest] = await Promise.all([read("api/v1/index.php"), read("api/v1/domains.php"), read("manifest.json")]);
  assert.match(index, /XAR_BACKEND_VERSION = '0\.12\.12'/);
  assert.match(index, /revisioned_domains_and_media_retention/);
  assert.match(index, /private_codex_image_studio/);
  assert.match(index, /shared_regie_codex_queue/);
  assert.match(index, /stable_character_health_overlays/);
  assert.match(index, /image_studio_conversations/);
  assert.match(index, /image_studio_messages/);
  assert.match(index, /image_studio_regie_service/);
  assert.match(index, /image_reference_catalog/);
  assert.match(index, /application_domain_clock/);
  assert.match(domains, /XAR_SESSION_SCHEMA_VERSION = 11/);
  assert.match(domains, /legacyStateToDomains/);
  assert.equal(JSON.parse(manifest).backendVersion, "0.12.12");
  assert.equal(JSON.parse(manifest).announcedApplicationVersion, "2.5.2");
  assert.equal(JSON.parse(manifest).databaseSchemaVersion, 11);
  assert.equal(JSON.parse(manifest).imageStudioMinimumApplicationVersion, "2.1.0");
});

test("les calques PV sont publics en lecture seule, stables et gérés uniquement par le MJ", async () => {
  const [index, online, overlays, rules] = await Promise.all([
    read("api/v1/index.php"),
    read("api/v1/online.php"),
    read("api/v1/health-overlays.php"),
    read(".htaccess")
  ]);
  assert.match(index, /character_health_overlays/);
  assert.match(index, /uq_character_health_overlays_slug/);
  assert.match(index, /handlePublicHealthOverlayRoute\(\$connection, \$route, \$method, \$headOnly\)[\s\S]*?requireSupportedClient/);
  assert.match(overlays, /requireGmIdentity\(\$connection\)/);
  assert.match(overlays, /\['ensure', 'regenerate'\]/);
  assert.match(overlays, /if \(!is_array\(\$record\)\)[\s\S]*?randomToken\(\)/);
  assert.match(overlays, /elseif \(\$action === 'regenerate'\)[\s\S]*?public_slug = :public_slug/);
  assert.match(overlays, /'name' =>[\s\S]*?'hp' =>[\s\S]*?'maxHp' =>[\s\S]*?'effect' =>/);
  assert.doesNotMatch(overlays, /portrait|conditions|secret|ownerPlayerId' =>/);
  assert.match(overlays, /frame-ancestors 'none'/);
  assert.match(rules, /\^health\/\[A-Za-z0-9_-\]\{43\}/);
  assert.match(online, /'playerControlled' => \$allied/);
});

test("les jets sans token restent propriétaires et Chance force un seul d100 brut", async () => {
  const online = await read("api/v1/online.php");
  const command = online.slice(online.indexOf("} elseif ($command === 'token.roll')"), online.indexOf("} elseif ($command === 'roll')"));
  assert.match(command, /\$characterId = trim/);
  assert.match(command, /'character:' \. \$characterId/);
  assert.match(command, /\(\$character\['ownerPlayerId'\] \?\? null\) !== \$accountId/);
  assert.match(command, /synchronizeOnlineCharacterToken\(\[\], \$character\)/);
  assert.match(command, /\['luck', 'stat', 'hit', 'initiative', 'damage', 'ability', 'custom'\]/);
  assert.match(command, /\$kind === 'luck' \? 'normal' : normalizeOnlineRollMode/);
  assert.match(command, /\$kind === 'luck'[\s\S]*?\$label = 'Chance'[\s\S]*?\$formula = '1d100'/);
  assert.match(command, /\$tokenKey !== '' && \$kind === 'initiative'/);
});

test("le déplacement hors tour exige une autorisation MJ temporaire et le registre reste privé", async () => {
  const [online, domains] = await Promise.all([read("api/v1/online.php"), read("api/v1/domains.php")]);
  const command = online.slice(online.indexOf("} elseif ($command === 'token.move')"), online.indexOf("} elseif ($command === 'ping')"));
  assert.match(command, /\$movementOverrides = is_array\(\$initiative\['movementOverrides'\]/);
  assert.match(command, /\$activeId !== \(\$token\['id'\] \?\? null\) && !\$movementOverride/);
  assert.match(online, /'temporaryMovementAllowed' => \$temporaryMovementAllowed/);
  assert.match(online, /'controllable' => \$owned && !\$paused && \(!\$active \|\|[\s\S]*?\$temporaryMovementAllowed\)/);
  assert.match(online, /unset\(\$initiative\['movementOverrides'\]\)/);
  assert.match(domains, /function validApplicationMovementOverrides/);
  assert.match(domains, /\$allowed !== true/);
});

test("seule la version MSIX annoncée peut utiliser l’API", async () => {
  const index = await read("api/v1/index.php");
  assert.match(index, /XAR_RELEASE_ANNOUNCEMENT_VERSION = '2\.5\.2'/);
  const policy = index.slice(index.indexOf("function clientPolicy"), index.indexOf("function drainingBackendSession"));
  const enforcement = index.slice(index.indexOf("function requireSupportedClient"), index.indexOf("function databaseConnection"));
  assert.match(policy, /'enforce' => true/);
  assert.match(policy, /'exactVersion' => true/);
  assert.match(policy, /'minimumVersion' => \$announcedVersion/);
  assert.match(policy, /'latestVersion' => \$announcedVersion/);
  assert.doesNotMatch(policy, /\$configuration\['client'\]|XAR_CLIENT_/);
  assert.match(enforcement, /hash_equals\(\(string\) \$policy\['latestVersion'\], \$provided\)/);
  assert.match(enforcement, /drainingBackendSession\(\$connection\)/);
  assert.match(enforcement, /sendJson\(426/);
  assert.match(enforcement, /'exactVersion' => true/);
  assert.doesNotMatch(enforcement, /version_compare/);
});

test("un déploiement backend demande la sauvegarde puis révoque les anciennes sessions", async () => {
  const [index, online] = await Promise.all([read("api/v1/index.php"), read("api/v1/online.php")]);
  assert.match(index, /XAR_BACKEND_SESSION_DRAIN_SECONDS = 30/);
  assert.match(index, /ALTER TABLE auth_sessions ADD COLUMN backend_version/);
  assert.match(index, /CREATE TABLE IF NOT EXISTS backend_release_state/);
  assert.match(index, /backend_release_session_drain/);
  assert.match(index, /INSERT INTO auth_sessions[\s\S]*?backend_version[\s\S]*?:backend_version/);
  assert.match(index, /function synchronizeBackendRelease[\s\S]*?takeover_requested_at = UTC_TIMESTAMP\(3\)/);
  assert.match(index, /function synchronizeBackendRelease[\s\S]*?DELETE FROM auth_sessions WHERE backend_version <> :backend_version/);
  assert.match(index, /ensureCurrentSchema\(\$connection\);\s+synchronizeBackendRelease\(\$connection\);/);
  assert.match(online, /backendSessionNeedsHandoff\(\$identity\)[\s\S]*?'session-takeover'[\s\S]*?'backend-update'/);
});

test("le statut Discord MJ expose seulement configuré et activé sans renvoyer les webhooks", async () => {
  const online = await read("api/v1/online.php");
  assert.match(online, /function readOnlineDiscordStatus[\s\S]+?requireGmIdentity\(\$connection\)[\s\S]+?readOnlineSettings[\s\S]+?\['ok' => true, 'discord' => \$settings\['discord'\]\]/);
  assert.match(online, /if \(\$route === '\/api\/v1\/integrations\/discord'\)[\s\S]+?\['GET', 'HEAD', 'POST'\]/);
  assert.match(online, /'configured' => isset\(\$secrets\['discord'\]\[\$target\]\)/);
  assert.doesNotMatch(online, /\['ok' => true, 'discord' => \$secrets\['discord'\]\]/);
});

test("les lectures techniques de domaines peuvent exclure la présence sans changer le défaut", async () => {
  const domains = await read("api/v1/domains.php");
  assert.match(domains, /\$includePresence = \(string\) \(\$_GET\['presence'\] \?\? '1'\) !== '0'/);
  assert.match(domains, /\.\.\.\(\$includePresence \? \['presence' => liveOnlinePresence\(\$connection\)\] : \[\]\)/);
});

test("le Compte de la Régie est une file sérialisée, pausable et sans identité matérielle", async () => {
  const [studio, index, online] = await Promise.all([
    read("api/v1/image-studio.php"),
    read("api/v1/index.php"),
    read("api/v1/online.php")
  ]);
  assert.match(studio, /function requireRegieCodexOwner/);
  assert.match(studio, /strcasecmp[\s\S]*?'Innota'/);
  assert.match(studio, /regie_codex_owner_required/);
  assert.match(studio, /\/image-studio\/regie\/status/);
  assert.match(studio, /\/image-studio\/regie\/access/);
  assert.match(studio, /\/image-studio\/regie\/worker\/heartbeat/);
  assert.match(studio, /\/image-studio\/regie\/jobs\/claim/);
  assert.match(studio, /pause refuse les nouvelles demandes et les nouvelles prises de travail/);
  assert.match(studio, /worker_lease_expires_at/);
  assert.match(studio, /XAR_IMAGE_STUDIO_WORKER_MAX_ATTEMPTS = 2/);
  assert.match(studio, /uploaded_by_account_id = :author_account_id/);
  assert.match(studio, /function assertImageStudioReferenceMediaAccess/);
  assert.match(studio, /function cleanImageStudioMultilineText/);
  assert.match(studio, /str_replace\(\["\\r\\n", "\\r"\], "\\n"/);
  assert.match(studio, /cleanImageStudioMultilineText\([\s\S]*?'invalid_prompt'/);
  assert.doesNotMatch(studio, /\$prompt = cleanText\(/);
  assert.match(studio, /own_shared_active_count/);
  assert.match(studio, /cancelled_by_author/);
  assert.match(studio, /\['xar-tsaroth\.fr', 'www\.xar-tsaroth\.fr'\]/);
  assert.match(studio, /\$sourceUrl = 'https:\/\/www\.xar-tsaroth\.fr'/);
  assert.match(index, /uq_image_studio_messages_request/);
  assert.match(index, /idx_image_studio_messages_regie_queue/);
  assert.match(index, /ENUM\('local', 'regie'\)/);
  assert.match(online, /JSON_CONTAINS\(references_json, JSON_OBJECT\('mediaId', :reference_media_id\), '\$'\)/);
  assert.doesNotMatch(`${studio}\n${index}`, /OPENAI_API_KEY|auth\.json|machine[_-]?id|device[_-]?id|hardware[_-]?id/i);
});

test("la pause du Compte de la Régie ne bloque jamais les générations personnelles", async () => {
  const studio = await read("api/v1/image-studio.php");
  const localBranch = studio.match(/if \(\$executionMode === 'regie'\) \{[\s\S]*?\$stale =/u)?.[0] ?? "";
  assert.match(localBranch, /regie_codex_paused/);
  assert.match(studio, /AND execution_mode = :execution_mode AND status IN \('queued', 'generating'\)/);
  assert.match(studio, /':execution_mode' => \$executionMode/);
  assert.match(studio, /\$executionMode === 'regie'[\s\S]*?Une demande utilise déjà le Compte de la Régie/);
  assert.match(studio, /Une génération personnelle est déjà en cours/);
  assert.doesNotMatch(studio, /WHERE author_account_id = :account_id AND status IN \('queued', 'generating'\)/);
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
  assert.match(studio, /\$traits\[\] = 'Sans casque'/);
  assert.match(studio, /\$traits\[\] = 'Sans armes'/);
  assert.match(studio, /\$traits\[\] = 'Avec armes'/);
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
  assert.match(page, /Fermer l’aperçu/);
  assert.match(page, /id="deleteConversationDialog"/);
  assert.match(page, /Supprimer définitivement/);
  assert.match(page, /method: "DELETE"/);
  assert.match(page, /new Map\(\[\["image\/jpeg", "jpg"\], \["image\/webp", "webp"\]\]\)/);
  assert.doesNotMatch(page, /\balert\s*\(|\bconfirm\s*\(/);
  assert.match(privacy, /Studio d’images/);
  assert.match(privacy, /aucun jeton Codex/);
  assert.match(privacy, /30 jours/);
});

test("l’administrateur peut effacer définitivement une discussion inactive", async () => {
  const studio = await read("api/v1/image-studio.php");
  const deletion = studio.slice(
    studio.indexOf("function permanentlyDeleteImageStudioConversation"),
    studio.indexOf("function normalizedImageStudioReferences")
  );
  assert.match(deletion, /can_administrate/);
  assert.match(deletion, /administrator_required/);
  assert.match(deletion, /status IN \('queued', 'generating'\)/);
  assert.match(deletion, /conversation_generation_active/);
  assert.match(deletion, /SET parent_message_id = NULL/);
  assert.match(deletion, /DELETE FROM image_studio_messages/);
  assert.match(deletion, /DELETE FROM image_studio_conversations/);
  assert.match(deletion, /historyRetainedForAdministrator' => false/);
  assert.match(deletion, /mediaDomainReferenceCount/);
  assert.match(deletion, /imageStudioMediaUsedByCatalog/);
  assert.match(studio, /requireMethod\(\$method, \['PATCH', 'DELETE'\]\)/);
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
  assert.match(command, /\$isGm && !in_array\(\$command, \['ensure-player', 'admin\.character\.delete', 'token\.resource\.adjust'\], true\)/);
  assert.match(command, /player_mode_required/);
  const timerDelete = command.slice(command.indexOf("$command === 'timer.update'"), command.indexOf("$command === 'character.delete'"));
  assert.match(timerDelete, /actionTimerTombstones/);
  assert.match(timerDelete, /deletedTimerId/);
});

test("les PV et le mana d’un token sont ajustés atomiquement sans élargir les données publiques", async () => {
  const [online, domains] = await Promise.all([read("api/v1/online.php"), read("api/v1/domains.php")]);
  const command = online.slice(online.indexOf("$command === 'token.resource.adjust'"), online.indexOf("$command === 'ping'"));
  assert.match(command, /\$isGm \? trim\(\(string\) \(\$arguments\['sceneId'\]/);
  assert.match(command, /\!\$isGm && \(\(\$token\['controllerPlayerId'\]/);
  assert.match(command, /\(\$token\['hidden'\] \?\? false\) === true/);
  assert.match(command, /\$current = max\(0, min\(\$maximum, \$previous \+ \$requestedDelta\)\)/);
  assert.match(command, /applicationCharacterTokenDomainRecords\(\$connection, \$characterId\)/);
  assert.match(command, /queueOnlineDomainUpsert\(\$pending, \$records, \$characterKey, \$character\)/);
  assert.match(command, /'resourcePulse' => \$pulse/);
  assert.match(online, /'notes', 'resourcePulse'/);
  assert.match(domains, /array_key_exists\('resourcePulse', \$payload\)/);
  assert.match(domains, /\['hp', 'mana'\]/);
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
  assert.match(online, /function createPrivateSettingsEncryptionKey/);
  assert.match(online, /settings-encryption\.key/);
  assert.match(online, /random_bytes\(32\)/);
  assert.match(online, /chmod\(\$path, 0600\)/);
  assert.match(online, /str_starts_with\(\$privatePrefix, \$documentPrefix\)/);
  assert.match(online, /XAR_REGIE_SETTINGS_ENCRYPTION_KEY/);
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
  assert.match(domains, /function validApplicationTokenDomain/);
  assert.match(domains, /function validApplicationRollDomain/);
  assert.match(domains, /validApplicationRollList\(\$payload\['rolls'\]/);
  assert.match(domains, /validApplicationTokenList\(\$payload\['tokenLibrary'\]/);
  assert.match(domains, /validApplicationTokenDomain\(\$payload\)/);
  assert.match(domains, /function validApplicationMediaFolders/);
  assert.match(domains, /function validApplicationAudioTracks/);
  assert.match(domains, /\(\$folderChannels\[\(string\) \$folderId\] \?\? null\) !== \$channel/);
  assert.match(domains, /\$payload\['map'\]\['tokens'\][^\n]+2000/);
  assert.match(online, /\$current\['characterSchemaVersion'\] = 3/);
  assert.match(online, /normalizeOnlineAbilities/);
  assert.match(online, /'hitThreshold'/);
  assert.match(online, /\['stat', 'hit'\]/);
  assert.match(online, /\$formula = '1d100';/);
  assert.match(online, /classifyOnlineD100Outcome\(\$rolled\['rawD100'\]/);
  assert.match(online, /\[1, 11, 22, 33, 44\]/);
  assert.match(online, /\[10, 66, 77, 88, 99\]/);
  assert.match(online, /function onlineRollFormulaWithMode/);
  assert.match(online, /normalizeOnlineRollMode/);
  assert.match(online, /onlineOutcomeDesirability/);
  assert.match(online, /'attempts'/);
  assert.match(domains, /\['normal', 'advantage', 'disadvantage'\]/);
  assert.match(domains, /'bonuses' => 1000/);
  assert.match(domains, /'penalties' => 1000/);
  assert.doesNotMatch(online, /\$formula = '1d100' \. \(\$value/);
  assert.match(online, /tryPostOnlineDiscordText/);
  assert.match(online, /\['roll', 'token\.roll'\]/);
  assert.match(domains, /validApplicationAbilities/);
  assert.match(domains, /'hitThreshold'/);
  assert.doesNotMatch(online, /'characterSchema', 'characterSchemaVersion',/);
  assert.match(online, /player_limit/);
  assert.match(online, /character_limit/);
  assert.match(online, /timer_limit/);
});

test("la projection joueur partage les statuts, réserve les stats aux alliés et mémorise le personnage actif", async () => {
  const online = await read("api/v1/online.php");
  const projection = online.slice(online.indexOf("function publicPlayerState"), online.indexOf("function readOnlineState"));
  assert.match(projection, /\$allied = is_string\(\$token\['controllerPlayerId'\]/);
  assert.match(projection, /'condition' => substr/);
  assert.match(projection, /\$details = \$allied \|\|/);
  assert.match(projection, /'bonuses', 'penalties'/);
  assert.match(projection, /'activeCharacterId'/);
  const preferences = online.slice(online.indexOf("\$command === 'preferences.update'"), online.indexOf("\$command === 'character.create'"));
  assert.match(preferences, /character_forbidden/);
  assert.match(preferences, /activeCharacterId/);
});

test("la projection joueur borne les événements éphémères à la scène visible", async () => {
  const online = await read("api/v1/online.php");
  const projection = online.slice(online.indexOf("function publicPlayerState"), online.indexOf("function readOnlineState"));
  assert.match(projection, /\$visibleSceneId/);
  const timers = projection.slice(projection.indexOf("$visibleActionTimers"), projection.indexOf("$visibleMapPings"));
  const pings = projection.slice(projection.indexOf("$visibleMapPings"), projection.indexOf("return ["));
  assert.match(timers, /\$timer\['sceneId'\][\s\S]*?\$visibleSceneId/);
  assert.match(timers, /'ownedByYou'\s*=>\s*\$owned/);
  assert.match(pings, /\$ping\['sceneId'\][\s\S]*?\$visibleSceneId/);
  assert.match(pings, /\$ping\['expiresAt'\]/);
  assert.match(projection, /'actionTimers'\s*=>\s*\$visibleActionTimers/);
  assert.match(projection, /'mapPings'\s*=>\s*\$visibleMapPings/);
});

test("la réutilisation d’un minuteur reste liée au combat et à sa scène", async () => {
  const online = await read("api/v1/online.php");
  const timerCommands = online.slice(
    online.indexOf("$command === 'timer.update'"),
    online.indexOf("$command === 'character.delete'")
  );
  assert.match(timerCommands, /\$command === 'timer\.update'[\s\S]*?\$initiative\['active'\]/);
  assert.match(timerCommands, /\$command === 'timer\.update'[\s\S]*?\$timers\[\$index\]\['sceneId'\][\s\S]*?\$sceneId/);
});

test("les variantes de cadre sont bornées et restent publiques sans élargir les détails tactiques", async () => {
  const [domains, online] = await Promise.all([read("api/v1/domains.php"), read("api/v1/online.php")]);
  assert.match(domains, /frameVariant/);
  assert.match(domains, /\['player', 'creature', 'elite', 'boss', 'apostle'\]/);
  assert.match(online, /function normalizeOnlineTokenFrameVariant/);
  assert.match(online, /\$playerControlled \? 'player' : 'creature'/);
  const projection = online.slice(online.indexOf("function publicPlayerState"), online.indexOf("function readOnlineState"));
  assert.match(projection, /'frameVariant' => normalizeOnlineTokenFrameVariant/);
  assert.match(projection, /\$details = \$allied \|\|/);
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

test("le workflow backend épingle l’action de lecture du dépôt", async () => {
  const workflow = await read(".github/workflows/backend-check.yml");
  assert.match(workflow, /actions\/checkout@08c6903cd8c0fde910a37f88322edcfb5dd907a8/);
  assert.doesNotMatch(workflow, /actions\/checkout@v5/);
});
