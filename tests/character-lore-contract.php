<?php
declare(strict_types=1);
require_once __DIR__ . '/../api/v1/online.php';
require_once __DIR__ . '/../api/v1/domains.php';

function loreCheck(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
    echo 'PASS ' . $message . "\n";
}
$text = require __DIR__ . '/../api/v1/lore-catalog/ada-origin.php';
loreCheck(strlen($text) > 40000 && str_contains($text, '### La prison dorée'), 'The complete requested narrative and chapters are present.');
loreCheck(normalizeOnlineCharacterLore("  été\r\n\0suite  ") === "été\nsuite", 'Plain text and accents normalize without losing paragraphs.');
$oversizedRejected = false;
try { normalizeOnlineCharacterLore(str_repeat('é', 100001)); } catch (RuntimeException $error) { $oversizedRejected = $error->getMessage() === 'character_lore_too_large'; }
loreCheck($oversizedRejected, 'Oversized UTF-8 narratives are refused instead of truncated.');
$character = ['id' => 'fixture-ada', 'ownerPlayerId' => 'fixture-owner', 'name' => 'Ada', 'resources' => ['hp' => 13, 'mana' => 7],
    'fatigue' => ['current' => 12, 'max' => 150], 'stats' => ['force' => 20], 'secret' => ['notes' => 'fixture only'], '_updatedAt' => 42];
$records = ['character:fixture-ada' => ['payload' => $character, 'revision' => 17]];
$accounts = [['id' => 'fixture-owner', 'username' => 'ada', 'display_name' => 'Ada']];
$plan = planAdaOriginLoreImport($records, $accounts, $text);
loreCheck($plan['status'] === 'ready' && $plan['before'] === $character, 'The actual owner and unique character resolve without reassigning them.');
$expected = $character; $expected['lore'] = $text;
loreCheck($plan['after'] === $expected, 'Only lore changes; resources, stats, ownership and secrets stay byte-for-byte intact.');
loreCheck($records['character:fixture-ada']['payload'] === $character, 'Planning never mutates the source records.');
$duplicate = $records; $duplicate['character:second'] = ['payload' => array_replace($character, ['id' => 'second'])];
loreCheck(planAdaOriginLoreImport($duplicate, $accounts, $text)['status'] === 'character_ambiguous', 'Duplicate names never choose an arbitrary sheet.');
$wrong = $records; $wrong['character:fixture-ada']['payload']['ownerPlayerId'] = 'another-owner';
loreCheck(planAdaOriginLoreImport($wrong, $accounts, $text)['status'] === 'owner_mismatch', 'A mismatched actual owner blocks the import.');
$duplicates = [...$accounts, ['id' => 'second-owner', 'username' => 'ada', 'display_name' => 'Ada']];
loreCheck(planAdaOriginLoreImport($records, $duplicates, $text)['status'] === 'owner_missing_or_ambiguous', 'An ambiguous account blocks the import.');
$patched = playerCharacterPatch($character, ['lore' => $text, 'secret' => ['notes' => 'forbidden'], 'ownerPlayerId' => 'forbidden']);
loreCheck($patched['lore'] === $text && $patched['ownerPlayerId'] === 'fixture-owner' && $patched['secret']['notes'] === 'fixture only', 'Owner edits accept long lore while rejecting secret and ownership patches.');
$visible = visibleCharacter($patched);
loreCheck($visible['lore'] === $text && !isset($visible['secret']), 'The owner projection preserves the narrative and strips MJ secrets.');
$catalog = siteCharacterLoreCatalog();
loreCheck(array_column($catalog['imports'], 'character') === ['inho', 'hira', 'gohachu', 'krael', 'nedrezar', 'killgert'],
    'The current public site supplies exactly the six other character lores.');
foreach ($catalog['imports'] as $spec) {
    $source = array_replace($character, ['id' => 'fixture-' . $spec['character'], 'name' => $spec['names'][0], 'lore' => 'Ancien récit']);
    $key = 'character:' . $source['id'];
    $plan = planCharacterLoreImport([$key => ['payload' => $source]], $accounts, $spec['names'], $spec['text']);
    $expected = $source; $expected['lore'] = $spec['text'];
    loreCheck($plan['status'] === 'ready' && $plan['before'] === $source && $plan['after'] === $expected,
        'Site import changes only the lore of ' . $spec['character'] . ' and preserves its actual owner.');
}
$gohachu = $catalog['imports'][2]; $krael = $catalog['imports'][3];
loreCheck($gohachu['storyCount'] === 3 && $gohachu['chapterCount'] === 12 && str_contains($gohachu['text'], '# Saison 2'),
    'Gohachu includes origin, season one and season two without omissions.');
loreCheck($krael['storyCount'] === 2 && str_contains($krael['text'], 'https://xar-tsaroth.fr/media/personnages/lore/krael-chute.mp4'),
    'Krael includes season three and the canonical narrative video reference.');
$source = array_replace($character, ['id' => 'fixture-gohachu', 'name' => 'Gohachu Forgefer']);
$record = ['character:fixture-gohachu' => ['payload' => $source]];
loreCheck(planCharacterLoreImport($record, $accounts, $gohachu['names'], $gohachu['text'])['status'] === 'ready',
    'An explicitly declared full name resolves the same character.');
$record['character:duplicate'] = ['payload' => array_replace($source, ['id' => 'duplicate', 'name' => 'Gohachu'])];
loreCheck(planCharacterLoreImport($record, $accounts, $gohachu['names'], $gohachu['text'])['status'] === 'character_ambiguous',
    'Two exact name variants never choose a sheet arbitrarily.');
loreCheck(planCharacterLoreImport($records, $accounts, ['Absent'], 'Récit')['status'] === 'character_missing',
    'Missing characters never create placeholder sheets.');
$source = array_replace($character, ['ownerPlayerId' => null]);
loreCheck(planCharacterLoreImport(['character:fixture-ada' => ['payload' => $source]], [], ['Ada'], 'Récit')['status'] === 'ready',
    'An unassigned MJ sheet is updated without creating or assigning an account.');
echo "Character lore contracts passed.\n";
