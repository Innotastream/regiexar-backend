<?php
declare(strict_types=1);
require_once __DIR__ . '/../api/v1/online.php';
require_once __DIR__ . '/../api/v1/domains.php';

function loreCheck(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
    echo 'PASS ' . $message . "\n";
}
$text = require __DIR__ . '/../api/v1/data/ada-origin.php';
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
echo "Character lore contracts passed.\n";
