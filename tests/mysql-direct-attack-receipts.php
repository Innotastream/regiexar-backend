<?php
declare(strict_types=1);

if (getenv('XAR_TEST_MYSQL_ALLOW_LOCAL') !== '1') {
    echo "MySQL direct attack receipt test skipped: explicit loopback opt-in required.\n";
    exit(0);
}
$dsn = (string) getenv('XAR_TEST_MYSQL_DSN');
if (preg_match('/^mysql:host=127\.0\.0\.1;port=([1-9][0-9]{3,4});charset=utf8mb4$/D', $dsn, $port) !== 1
    || (int) $port[1] > 65535) throw new RuntimeException('Explicit loopback DSN required.');

define('XAR_SPECIAL_FIXTURE_ONLY', true);
require __DIR__ . '/tactical-special-effects.php';

function directDb(?string $schema = null): PDO
{
    $db = new PDO((string) getenv('XAR_TEST_MYSQL_DSN'), (string) (getenv('XAR_TEST_MYSQL_USER') ?: 'root'),
        (string) getenv('XAR_TEST_MYSQL_PASSWORD'), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false]);
    if ($schema !== null) $db->exec('USE `' . $schema . '`');
    return $db;
}

function directCommand(PDO $db, string $command, array $payload, bool $gm = false, string $id = 'account-player'): TestResponse
{
    $GLOBALS['testIdentity'] = ['id'=>$id, 'display_name'=>'Recette SQL', 'effective_mode'=>$gm?'gm':'player', 'permanent_role'=>$gm?'gm':'player'];
    $GLOBALS['testBody'] = ['command'=>$command, 'payload'=>$payload];
    try { commandOnlineState($db, []); }
    catch (TestResponse $response) { return $response; }
}

function directCheck(bool $condition, string $label): void
{
    if (!$condition) throw new RuntimeException($label);
    echo "PASS $label\n";
}

$fixture = specialFixture();
$sourceKey = 'token:scene-one:token-player';
$targetKey = 'token:scene-one:token-monster';
$characterKey = 'character:character-player';
$fixture->put($sourceKey, [...$fixture->payload($sourceKey), 'x'=>45]);
$fixture->put($characterKey, [...$fixture->payload($characterKey), 'weaponText'=>'Pattes / 2d6+4',
    'weaponAttacks'=>[['id'=>'legs','name'=>'Pattes','formula'=>'2d6+4','naturalWeapon'=>true,'damageType'=>'physical']]]);
$arguments = ['requestId'=>'direct-proof-seed-0000','sceneId'=>'scene-one','sourceTokenId'=>'token-player',
    'targetTokenId'=>'token-monster','attackKind'=>'weapon','attackId'=>'legs','statId'=>'weapon-skill','weaponSkill'=>0,'opposed'=>false];
$seedResult = runCommand($fixture, 'token.attack', $arguments);
directCheck($seedResult->status === 200, 'Une vraie attaque produit le reçu utilisé dans la migration.');
if (($seedResult->body['attack']['status'] ?? '') === 'pending') {
    $closed = runCommand($fixture, 'token.attack.resolve', ['attackId'=>$seedResult->body['attack']['id'],'decision'=>'reject'], true, 'account-gm');
    directCheck($closed->status === 200, 'Le résultat remarquable est terminé par le vrai gestionnaire.');
}
$seed = $fixture->payload('activity')['attackReceipts'][0];
$now = applicationResourceReceiptClock();
$legacy = [];
for ($index = 0; $index < XAR_ATTACK_RECEIPT_MAXIMUM; $index++) {
    $id = sprintf('direct-legacy-proof-%04d', $index);
    $legacy[] = [...$seed, 'requestId'=>$id, 'expiresAt'=>$now+600000,
        'attack'=>[...$seed['attack'], 'id'=>'attack-' . $id, 'requestId'=>$id]];
}
$firstArguments = [...$arguments, 'requestId'=>$legacy[0]['requestId']];
$newArguments = [...$arguments, 'requestId'=>'direct-new-after-capacity'];
$activity = [...$fixture->payload('activity'), 'attackReceipts'=>$legacy];
$admin = directDb();
$schema = 'regie_attacks_' . bin2hex(random_bytes(8));
$db = null;
try {
    $admin->exec('CREATE DATABASE `' . $schema . '` CHARACTER SET utf8mb4');
    $db = directDb($schema);
    $db->exec('CREATE TABLE schema_migrations (version INT PRIMARY KEY,name VARCHAR(200),checksum CHAR(64)) ENGINE=InnoDB');
    $db->exec('CREATE TABLE application_domain_clock (singleton_id INT PRIMARY KEY,global_revision BIGINT,state_schema_version INT,domain_schema_version INT,legacy_revision BIGINT NULL,initialized_at DATETIME(3)) ENGINE=InnoDB');
    $db->exec('CREATE TABLE application_domains (domain_key VARCHAR(200) PRIMARY KEY,schema_version INT,revision BIGINT,payload JSON,updated_by_account_id VARCHAR(128) NULL,updated_at DATETIME(3)) ENGINE=InnoDB');
    $db->exec('CREATE TABLE application_domain_history (history_id BIGINT AUTO_INCREMENT PRIMARY KEY,domain_key VARCHAR(200),domain_revision BIGINT,global_revision BIGINT,operation VARCHAR(10),payload JSON,changed_by_account_id VARCHAR(128) NULL,created_at DATETIME(3) DEFAULT CURRENT_TIMESTAMP(3)) ENGINE=InnoDB');
    $db->exec('CREATE TABLE application_domain_changes (global_revision BIGINT,domain_key VARCHAR(200),domain_revision BIGINT,operation VARCHAR(10),PRIMARY KEY(global_revision,domain_key)) ENGINE=InnoDB');
    $db->exec('INSERT INTO application_domain_clock VALUES(1,1,' . XAR_SESSION_SCHEMA_VERSION . ',1,NULL,UTC_TIMESTAMP(3))');
    foreach ($fixture->domains as $key => $record) {
        upsertApplicationDomain($db, $key, $key==='activity'?$activity:$record['payload'], 1, null);
    }
    $blocked = directCommand($db, 'token.attack', $newArguments);
    directCheck($blocked->status === 429 && $blocked->body['code'] === 'attack_receipt_capacity_reached',
        'L’attaque 1 025 est refusée avec 1 024 résultats directs valides dans le domaine.');

    migrateApplicationDirectAttackReceiptLedger($db);
    $observer = directDb($schema);
    applicationDirectAttackLedgerEnabled($observer, true);
    $synchronized = applicationDomainPayload(applicationDomainRecords($observer, ['activity']), 'activity');
    $archivedCount = (int) $observer->query('SELECT COUNT(*) FROM application_direct_attack_receipts')->fetchColumn();
    directCheck($archivedCount === 1024
        && $synchronized['attackReceipts'] === [] && $synchronized['playerActions'] === $activity['playerActions'],
        'L’archive garde les 1 024 reçus et la synchronisation contient toujours les lignes de journal. '
        . json_encode(['archives'=>$archivedCount,'inline'=>count($synchronized['attackReceipts']),
            'journal'=>$synchronized['playerActions'] === $activity['playerActions']]));
    $admitted = directCommand($db, 'token.attack', $newArguments);
    directCheck($admitted->status === 200 && $admitted->body['attack']['id'] !== $seed['attack']['id'],
        'La même attaque 1 025 aboutit avec une nouvelle référence.');
    $revision = domainClockRecord($observer)['globalRevision'];
    $replay = directCommand($observer, 'token.attack', $firstArguments);
    $other = directCommand($observer, 'token.attack', $firstArguments, false, 'account-other');
    $altered = directCommand($observer, 'token.attack', [...$firstArguments,'weaponSkill'=>10]);
    directCheck($replay->status === 200 && $replay->body['deduplicated'] === true
        && $replay->body['attack']['id'] === $legacy[0]['attack']['id']
        && $other->status === 403 && $altered->status === 409
        && domainClockRecord($observer)['globalRevision'] === $revision,
        'Une autre connexion rejoue le même résultat, refuse un autre auteur ou une autre demande, à révision inchangée.');

    $opposed = null;
    for ($index = 0; $index < 20; $index++) {
        $response = directCommand($db, 'token.attack', [...$arguments,
            'requestId'=>'direct-opposed-attack-' . $index, 'weaponSkill'=>100, 'opposed'=>true]);
        if (($response->body['attack']['status'] ?? '') === 'awaiting-opposition') {
            $opposed = $response->body['attack'];
            break;
        }
    }
    directCheck(is_array($opposed) && applicationReadDirectAttackReceipt($observer, $opposed['requestId'], $now) === null,
        'Une attaque réellement en attente reste visible dans les décisions de combat.');
    $defense = ['attackId'=>$opposed['id'], 'requestId'=>'direct-opposition-proof', 'decision'=>'skip'];
    $settled = directCommand($db, 'token.attack.oppose', $defense, true, 'account-gm');
    $archived = applicationReadDirectAttackReceipt($observer, $defense['requestId'], $now, $opposed['id']);
    directCheck($settled->status === 200 && ($archived['attack']['status'] ?? '') === 'applied'
        && applicationReadDirectAttackReceipt($observer, $opposed['requestId'], $now) === $archived,
        'L’opposition terminée est retrouvée par ses deux références.');
    $retriedDefense = directCommand($observer, 'token.attack.oppose', $defense, true, 'account-gm');
    $wrongDefender = directCommand($observer, 'token.attack.oppose', $defense, true, 'account-other');
    directCheck($retriedDefense->status === 200 && $retriedDefense->body['deduplicated'] === true && $wrongDefender->status === 403,
        'Une opposition archivée se rejoue uniquement pour l’intervenant qui l’a résolue.');
    $beforeUndo = applicationDomainPayload(applicationDomainRecords($observer, ['activity',$targetKey]), 'activity');
    $actions = array_values(array_filter($beforeUndo['combatActions'],
        static fn(array $action): bool=>in_array('attack:' . $opposed['id'], $action['aliases'], true)));
    $actionId = $actions[0]['actionIds'][0] ?? '';
    $damageUndo = directCommand($db, 'action.undo', ['actionId'=>$actionId, 'scope'=>'target-damage',
        'tokenId'=>'token-monster', 'requestId'=>'direct-damage-undo-01'], true, 'account-gm');
    $fixed = applicationReadDirectAttackReceipt($observer, $opposed['requestId'], $now);
    $afterPartial = applicationDomainPayload(applicationDomainRecords($observer, [$targetKey]), $targetKey);
    directCheck($damageUndo->status === 200 && (float)$afterPartial['hp'] === (float)$archived['attack']['previousHp']
        && ($fixed['attack']['damageUndone'] ?? false) === true,
        'L’annulation de ses dégâts rend les PV au bon destinataire et marque le reçu privé. '
        . json_encode(['status'=>$damageUndo->status,'error'=>$damageUndo->body['error']??null,
            'hp'=>$afterPartial['hp']??null,'expectedHp'=>$archived['attack']['previousHp']??null,'marked'=>$fixed['attack']['damageUndone']??false]));
    $fullUndo = directCommand($db, 'action.undo', ['actionId'=>$actionId, 'scope'=>'action',
        'requestId'=>'direct-complete-undo-01'], true, 'account-gm');
    $cancelled = applicationReadDirectAttackReceipt($observer, $opposed['requestId'], $now);
    directCheck($fullUndo->status === 200 && ($cancelled['attack']['actionUndone'] ?? false) === true
        && ($cancelled['attack']['status'] ?? '') === 'cancelled',
        'L’annulation complète conserve aussi un résultat de rejeu annulé. '
        . json_encode(['status'=>$fullUndo->status,'error'=>$fullUndo->body['error']??null,
            'attackStatus'=>$cancelled['attack']['status']??null,'marked'=>$cancelled['attack']['actionUndone']??false]));

    $stale = ['attackReceipts'=>[[...$archived, 'attack'=>[...$opposed,'status'=>'pending']]],
        'pendingAttacks'=>[[...$opposed,'status'=>'pending']]];
    $clean = applicationProtectPrivateDirectAttackReceipts($observer, $stale, []);
    directCheck($clean['attackReceipts'] === [] && $clean['pendingAttacks'] === [],
        'Une ancienne sauvegarde ne remet pas une attaque terminée dans les validations.');

    $group = [];
    foreach (['area-parent','area-child','echo-parent','echo-child','execution-member','active-pending'] as $suffix) {
        $id = 'group-' . $suffix;
        $group[] = [...$seed, 'requestId'=>$id, 'attack'=>[...$seed['attack'],'requestId'=>$id,'id'=>'attack-' . $id]];
    }
    $group[0]['attack']['areaGate'] = ['kind'=>'defense-selection'];
    $group[0]['areaAttackIds'] = [$group[1]['attack']['id']];
    $group[2]['attack']['echoAttacks'] = [['id'=>$group[3]['attack']['id']]];
    $group[3]['attack']['echoParentId'] = $group[2]['attack']['id'];
    $group[4]['attack']['complexExecutionId'] = 'execution-allocated-proof';
    $group[5]['attack']['status'] = 'awaiting-opposition';
    $db->beginTransaction();
    $retained = applicationArchiveIndependentAttacks($db, ['attackReceipts'=>$group,'pendingAttacks'=>[$group[5]['attack']],
        'abilityExecutions'=>[['id'=>'execution-allocated-proof','status'=>'active']]], $now);
    $db->rollBack();
    directCheck($retained['attackReceipts'] === $group, 'Zone avec enfant, famille d’échos, exécution allouée et opposition restent intégralement en ligne.');

    echo "Local MySQL direct attack receipt integration passed.\n";
} finally {
    if ($db?->inTransaction()) $db->rollBack();
    $admin->exec('DROP DATABASE IF EXISTS `' . $schema . '`');
}
