<?php
declare(strict_types=1);
require_once __DIR__ . '/../api/v1/domains.php';
require_once __DIR__ . '/../api/v1/online.php';
require_once __DIR__ . '/../api/v1/image-studio.php';

function mediaCheck(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
final class MediaResponse extends RuntimeException {
    public function __construct(public int $status, public string $errorCode) { parent::__construct($errorCode); }
}
function sendError(int $status, string $message, string $code = ''): never { throw new MediaResponse($status, $code); }
$player = ['id' => 'player-one', 'display_name' => 'Joueur', 'permanent_role' => 'gm', 'effective_mode' => 'player', 'can_administrate' => true];
$id = str_repeat('p', 24); $secret = str_repeat('s', 24); $portrait = str_repeat('c', 24); $audio = str_repeat('a', 24);
$state = ['schemaVersion' => XAR_SESSION_SCHEMA_VERSION, 'activeSceneId' => 'scene-one', 'activeScene' => ['id' => 'scene-one'],
    'characters' => [['id' => 'character-one', 'ownerPlayerId' => 'player-one', 'portrait' => '/media/' . $portrait]],
    'map' => ['image' => '/media/' . $id, 'vision' => ['enabled' => false], 'tokens' => [['id' => 'secret-token', 'hidden' => true, 'image' => '/media/' . $secret]]],
    'scenes' => [['id' => 'prepared', 'map' => ['image' => '/media/' . $secret]]], 'tracks' => [['url' => '/media/' . $audio]], 'initiative' => []];
foreach ([$id, $portrait, $audio] as $visible) mediaCheck(onlineMediaVisibleInPlayerState($state, $player, $visible), 'Visible map, own portrait and public audio remain accessible.');
mediaCheck(!onlineMediaVisibleInPlayerState($state, $player, $secret), 'An administrator in Player mode cannot load hidden or prepared images.');
$draft = ['id' => $id, 'stored_name' => onlineMediaStorageName($id, '.png', $player), 'uploaded_by_account_id' => $player['id']];
mediaCheck($draft['stored_name'] === $id . '.player.png', 'The authenticated effective Player mode, including permanent GM accounts, produces the marker.');
mediaCheck(onlineMediaStorageName($id, '.png', [...$player, 'effective_mode' => 'gm']) === $id . '.png', 'GM uploads never produce a Player preview marker.');
mediaCheck(onlineMediaStorageName($id, '.mp3', $player) === $id . '.player.mp3', 'Completion-sound drafts carry the same effective-mode provenance.');
mediaCheck(onlineMediaIsPlayerUpload($draft, $player), 'An own Player upload remains previewable before save and after reconnection.');
mediaCheck(!onlineMediaIsPlayerUpload([...$draft, 'stored_name' => $id . '.png'], $player), 'A historical or new GM upload cannot use the Player draft exception.');
mediaCheck(!onlineMediaIsPlayerUpload([...$draft, 'uploaded_by_account_id' => 'other'], $player), 'An upload marker never substitutes for owner identity.');
mediaCheck(!onlineMediaIsPlayerUpload([...$draft, 'stored_name' => '../' . $id . '.player.png'], $player), 'Only the exact server-generated marker is accepted.');

final class MediaStatement extends PDOStatement {
    private array $rows = [];
    public function __construct(private MediaConnection $db, private string $sql) {}
    public function execute(?array $params = null): bool { $this->rows = $this->db->run($this->sql, $params ?? []); return true; }
    public function fetch(int $mode = PDO::FETCH_DEFAULT, int $cursorOrientation = PDO::FETCH_ORI_NEXT, int $cursorOffset = 0): mixed { return array_shift($this->rows) ?? false; }
    public function fetchColumn(int $column = 0): mixed { $row = $this->fetch(); return is_array($row) ? array_values($row)[$column] : false; }
    public function fetchAll(int $mode = PDO::FETCH_DEFAULT, mixed ...$args): array { return $this->rows; }
    public function rowCount(): int { return 1; }
}
final class MediaConnection extends PDO {
    public bool $transaction = false; public bool $clockLocked = false; public bool $mediaLocked = false;
    public bool $attachAtLock = false; public int $references = 0; public ?array $record; public ?array $owner = null;
    public array $domains = [];
    public function __construct(string $id) { $this->record = ['id' => $id, 'stored_name' => $id . '.player.png', 'pending_delete_at' => null, 'public_slug' => null]; }
    public function beginTransaction(): bool { mediaCheck(!$this->transaction, 'No nested media transaction.'); $this->transaction = true; return true; }
    public function commit(): bool { $this->transaction = false; $this->clockLocked = $this->mediaLocked = false; return true; }
    public function rollBack(): bool { return $this->commit(); }
    public function inTransaction(): bool { return $this->transaction; }
    public function prepare(string $query, array $options = []): PDOStatement|false { return new MediaStatement($this, $query); }
    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false { $statement = $this->prepare($query); $statement->execute(); return $statement; }
    public function run(string $sql, array $params): array {
        if (str_starts_with($sql, 'SELECT domain_key, schema_version')) {
            $rows = [];
            foreach ($this->domains as $key => $payload) {
                if ($params !== [] && !in_array($key, $params, true)) continue;
                $rows[] = ['domain_key' => $key, 'schema_version' => XAR_DOMAIN_SCHEMA_VERSION, 'revision' => 1, 'payload' => $payload, 'updated_at' => '2026-09-24'];
            }
            return $rows;
        }
        if (str_contains($sql, 'FROM application_domain_clock')) {
            if (str_contains($sql, 'FOR UPDATE')) { mediaCheck($this->transaction, 'The clock lock must be transactional.'); $this->clockLocked = true; if ($this->attachAtLock) $this->references++; }
            return [['global_revision' => 1, 'state_schema_version' => XAR_SESSION_SCHEMA_VERSION, 'domain_schema_version' => XAR_DOMAIN_SCHEMA_VERSION, 'legacy_revision' => null, 'initialized_at' => 'done']];
        }
        if (str_contains($sql, 'FROM media_objects WHERE id')) {
            if (str_contains($sql, 'FOR UPDATE')) { mediaCheck($this->clockLocked, 'Media retirement follows the domain lock order.'); $this->mediaLocked = true; }
            return $this->record === null ? [] : [$this->record];
        }
        if (str_contains($sql, 'SELECT m.author_account_id')) return $this->owner === null ? [] : [$this->owner];
        if (str_contains($sql, 'SELECT COUNT(*)')) {
            mediaCheck($this->mediaLocked && $this->clockLocked, 'Reference checks happen after both locks.');
            return [['count' => str_contains($sql, 'FROM application_domains') ? $this->references : 0]];
        }
        if (str_starts_with($sql, 'UPDATE media_objects SET pending_delete_at')) {
            mediaCheck($this->mediaLocked && $this->clockLocked && $this->references === 0, 'No media can retire while referenced.');
            $this->record['pending_delete_at'] = 'now'; $this->record['public_slug'] = null; return [];
        }
        throw new RuntimeException('Unexpected media SQL: ' . $sql);
    }
}
$db = new MediaConnection($id); $storedName = $db->record['stored_name'];
mediaCheck(scheduleUnusedOnlineMediaDeletion($db, $id) === 'scheduled', 'Unused media is retained.');
mediaCheck($db->record['stored_name'] === $storedName, 'Retention preserves the Player upload marker and physical filename.');
mediaCheck(scheduleUnusedOnlineMediaDeletion($db, $id) === 'retained', 'Retry does not extend retention indefinitely.');
$db = new MediaConnection($id); $db->attachAtLock = true;
mediaCheck(scheduleUnusedOnlineMediaDeletion($db, $id) === 'referenced' && $db->record['pending_delete_at'] === null, 'A concurrent attachment arriving before the lock wins over retirement.');
$db = new MediaConnection($id); $db->record['public_slug'] = str_repeat('s', 22);
mediaCheck(scheduleUnusedOnlineMediaDeletion($db, $id) === 'published' && $db->record['pending_delete_at'] === null, 'Cleanup preserves deliberately published media.');
mediaCheck(scheduleUnusedOnlineMediaDeletion($db, $id, true) === 'scheduled', 'Explicit authorized unpublication can retire an unused published image.');
$db = new MediaConnection($id); $db->owner = ['author_account_id' => 'other'];
mediaCheck(scheduleUnusedOnlineMediaDeletion($db, $id, false, ['id' => 'gm', 'effective_mode' => 'gm', 'permanent_role' => 'gm']) === 'forbidden', 'A GM cannot delete another author’s private Studio image.');
$draftDb = new MediaConnection($id); $draftDb->record['uploaded_by_account_id'] = $player['id'];
requireOnlinePlayerMediaAccess($draftDb, $player, $id);
assertImageStudioMediaAccess($draftDb, $player, $id);
mediaCheck(!$draftDb->transaction, 'Draft preview in either route requires no transaction or persisted character reference.');
$privateDb = new MediaConnection($secret); $privateDb->record['stored_name'] = $secret . '.png';
$privateDb->record['uploaded_by_account_id'] = $player['id'];
$privateDb->owner = ['author_account_id' => $player['id'], 'owner_hidden_at' => null];
$privateDb->domains = ['table' => ['activeSceneId' => 'scene-one'], 'scene:scene-one' => ['id' => 'scene-one', 'name' => 'Visible'],
    'roster' => ['characterOrder' => ['character-one']], 'character:character-one' => $state['characters'][0],
    'map:scene-one' => $state['map'], 'token-index:scene-one' => ['order' => []], 'initiative:scene-one' => []];
foreach (['requireOnlinePlayerMediaAccess', 'assertImageStudioMediaAccess'] as $routeGuard) {
    try { $routeGuard($privateDb, $player, $secret); throw new RuntimeException('Private GM asset exposed by ' . $routeGuard); }
    catch (MediaResponse $response) { mediaCheck($response->status === 404, 'Player access cannot use a permanent admin flag or Studio ownership.'); }
}
echo "Media projection, Player draft provenance, retention and concurrent attachments are protected.\n";
