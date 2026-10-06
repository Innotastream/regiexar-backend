<?php
// The real public projection shares competencies without granting authority.
$db = fixture();
$character = $db->payload('character:character-player');
$character['skills'] = "Observation\nSurvie";
$character['specialSkills'] = 'Lecture des traces';
$character['passives'] = 'Veille attentive';
$character['inventory'] = 'Inventaire privé';
$character['lore'] = 'Lore privé';
$character['secret'] = ['notes' => 'Note privée du MJ'];
$db->put('character:character-player', $character);
$shared = publicPlayerState([
    'characters' => [$character], 'activeSceneId' => 'scene-one',
    'map' => ['tokens' => [$db->payload('token:scene-one:token-player')]],
    'initiative' => ['active' => false],
], ['id' => 'account-other', 'display_name' => 'Autre joueur'], []);
$summary = $shared['party'][0];
requireTactical($summary['skills'] === "Observation\nSurvie" && $summary['specialSkills'] === 'Lecture des traces' && $summary['passives'] === 'Veille attentive', 'Other players can read the character competencies and passives.');
requireTactical(($summary['abilities'][0]['name'] ?? '') === 'Frappe test' && $shared['myCharacters'] === [], 'Other players receive ability definitions without receiving an owned character.');
foreach (['inventory', 'lore', 'secret', 'resources', 'publicNotes'] as $field) requireTactical(!array_key_exists($field, $summary), 'The competencies summary does not expose ' . $field . '.');
$revision = $db->revision;
$forbidden = runCommand($db, 'token.roll', ['requestId' => 'shared-abilities-foreign-0001', 'sceneId' => 'scene-one', 'tokenId' => 'token-player', 'kind' => 'ability', 'abilityId' => 'ability-one', 'visibility' => 'public'], false, 'account-other');
requireTactical($forbidden->status === 403 && $db->revision === $revision, 'Reading a shared ability never grants permission to launch it.');
$allowed = runCommand($db, 'token.roll', ['requestId' => 'shared-abilities-gm-0001', 'sceneId' => 'scene-one', 'tokenId' => 'token-player', 'kind' => 'ability', 'abilityId' => 'ability-one', 'visibility' => 'public'], true, 'account-gm');
requireTactical($allowed->status === 200, 'The GM keeps permission to launch another character ability: ' . $allowed->getMessage());
