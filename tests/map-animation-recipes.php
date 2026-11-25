<?php
declare(strict_types=1);

require_once __DIR__.'/../api/v1/online.php';
require_once __DIR__.'/../api/v1/domains.php';
require_once __DIR__.'/../api/v1/ability-assistant.php';
require_once __DIR__.'/../api/v1/health-overlays.php';
require_once __DIR__.'/../api/v1/image-studio.php';

final class MapRecipeResponse extends RuntimeException
{
    public function __construct(public int $status, public array $body, public array $headers) {}
}

function sendJson(int $status, array $body, bool $headOnly = false, array $headers = []): never
{
    throw new MapRecipeResponse($status, $headOnly ? [] : $body, $headers);
}

function sendError(int $status, string $message, string $code = ''): never
{
    sendJson($status, ['ok' => false, 'error' => $message, 'code' => $code]);
}

function requireMethod(string $actual, array $allowed): void
{
    if (!in_array($actual, $allowed, true)) sendError(405, 'Méthode refusée.', 'method_not_allowed');
}

function requireGmIdentity(PDO $connection): array
{
    $identity = $GLOBALS['mapRecipeIdentity'];
    if ($identity === null) sendError(401, 'Connexion requise.', 'authentication_required');
    if (!onlineIdentityIsGm($identity)) sendError(403, 'Session MJ requise.', 'gm_required');
    return $identity;
}

function mapRecipeCheck(bool $ok, string $message): void
{
    if (!$ok) throw new RuntimeException($message);
    $GLOBALS['mapRecipeChecks']++;
}

function routeMapRecipe(string $method, ?array $identity, string $etag = ''): MapRecipeResponse
{
    $GLOBALS['mapRecipeIdentity'] = $identity;
    $_SERVER['HTTP_IF_NONE_MATCH'] = $etag;
    $connection = new class extends PDO { public function __construct() {} };
    try {
        handleOnlineRoute($connection, [], '/api/v1/map-animation/recipes', $method, $method === 'HEAD');
    } catch (MapRecipeResponse $response) {
        return $response;
    }
    throw new RuntimeException('Route du catalogue absente.');
}

$GLOBALS['mapRecipeChecks'] = 0;
$gm = ['permanent_role' => 'gm', 'effective_mode' => 'gm'];
$player = ['permanent_role' => 'player', 'effective_mode' => 'player'];
$catalog = routeMapRecipe('GET', $gm);
mapRecipeCheck($catalog->status === 200 && $catalog->body['version'] === 1 && $catalog->body['ok'] === true,
    'Le MJ reçoit le catalogue versionné.');
mapRecipeCheck(preg_match('/^[a-f0-9]{64}$/D', $catalog->body['revision']) === 1 && array_is_list($catalog->body['recipes']),
    'Le catalogue expose une liste et une révision canonique.');
$etag = $catalog->headers['ETag'];
mapRecipeCheck($etag === 'W/"'.$catalog->body['revision'].'"' && $catalog->headers['Cache-Control'] === 'private, no-cache'
    && str_contains($catalog->headers['Vary'], 'X-Xar-Session'),
    'Le cache est privé et varie avec la session.');
mapRecipeCheck(routeMapRecipe('GET', null, $etag)->status === 401, 'Un lecteur anonyme ne peut même pas obtenir 304.');
mapRecipeCheck(routeMapRecipe('GET', $player, $etag)->status === 403, 'Un joueur ne reçoit pas le catalogue.');
mapRecipeCheck(routeMapRecipe('GET', [...$gm, 'effective_mode' => 'player'], $etag)->status === 403,
    'Le rôle permanent MJ ne suffit pas en mode Joueur.');
mapRecipeCheck(routeMapRecipe('GET', [...$player, 'effective_mode' => 'gm'])->status === 403,
    'Le mode effectif MJ seul ne suffit pas non plus.');
mapRecipeCheck(routeMapRecipe('POST', $gm)->status === 405, 'La route est en lecture seule.');
$head = routeMapRecipe('HEAD', $gm);
mapRecipeCheck($head->status === 200 && $head->body === [] && $head->headers['ETag'] === $etag,
    'HEAD expose la même révision sans charger le corps.');
$cached = routeMapRecipe('GET', $gm, '"past", '.$etag);
mapRecipeCheck($cached->status === 304 && $cached->body === [] && $cached->headers['ETag'] === $etag,
    'Le catalogue inchangé produit un 304 sans contenu.');
$changed = routeMapRecipe('GET', $gm, '"outdated"');
mapRecipeCheck($changed->status === 200 && $changed->body === $catalog->body,
    'Un ancien ETag récupère le catalogue actuel sans transaction ni modification.');

$layer = ['kind' => 'flame', 'intensity' => .5, 'speed' => .65, 'direction' => 270, 'fish' => .2, 'tint' => '#AA77FF'];
$entry = ['id' => 'test-foyer', 'label' => 'Foyer violet', 'aliases' => ['À la forge'], 'layers' => [$layer]];
$raw = ['version' => 1, 'recipes' => [$entry]];
$valid = applicationNormalizeMapAnimationRecipeCatalog($raw);
mapRecipeCheck(is_array($valid) && $valid['recipes'][0]['layers'][0]['tint'] === '#aa77ff'
    && $valid['recipes'][0]['layers'][0]['fish'] === 0, 'Le catalogue emploie les réglages du moteur existant.');
mapRecipeCheck(applicationNormalizeMapAnimationRecipeCatalog(['recipes' => [$entry], 'version' => 1]) === $valid,
    'L’ordre des clés source ne change pas la révision émise.');
$updated = applicationNormalizeMapAnimationRecipeCatalog([...$raw, 'guidance' => ['Sépare les foyers.']]);
mapRecipeCheck($updated['revision'] !== $valid['revision'], 'Une modification déclarative produit une nouvelle révision.');
mapRecipeCheck(applicationNormalizeMapAnimationRecipeCatalog(['version' => 1, 'recipes' => []])['recipes'] === [],
    'Un catalogue temporairement vide demeure valide.');
mapRecipeCheck(
    applicationNormalizeMapAnimationRecipeCatalog(['version' => 1, 'recipes' => [[...$entry, 'id' => '2-foyers',
        'label' => "\u{00a0}Foyer violet\u{00a0}", 'aliases' => ['foyer violet']]]])['recipes'][0]['label'] === 'Foyer violet',
    'Un identifiant peut commencer par un chiffre. Un alias peut reprendre le nom de sa propre recette.'
);

foreach ([
    [...$raw, 'version' => 2],
    [...$raw, 'instructions' => 'Contenu hors contrat'],
    [...$raw, 'guidance' => [str_repeat('a', 181)]],
    [...$raw, 'guidance' => array_fill(0, 5, 'Un conseil')],
    ['version' => 1, 'recipes' => array_fill(0, 17, $entry)],
    ['version' => 1, 'recipes' => [$entry, $entry]],
    ['version' => 1, 'recipes' => [$entry, [...$entry, 'id' => 'test-autre', 'aliases' => ['a la forge']]]],
    ['version' => 1, 'recipes' => [$entry, [...$entry, 'id' => 'test-autre', 'label' => 'A—la forge', 'aliases' => ['autre']]]],
    ['version' => 1, 'recipes' => [$entry, [...$entry, 'id' => 'test-autre', 'label' => 'Autre foyer', 'aliases' => ['Fôyer–Violet']]]],
    ['version' => 1, 'recipes' => [$entry, [...$entry, 'id' => 'test-autre', 'label' => 'fóyer violet', 'aliases' => ['autre foyer']]]],
    ['version' => 1, 'recipes' => [[...$entry, 'label' => '—']]],
    ['version' => 1, 'recipes' => [[...$entry, 'aliases' => ['!?']]]],
    ['version' => 1, 'recipes' => [[...$entry, 'aliases' => []]]],
    ['version' => 1, 'recipes' => [[...$entry, 'aliases' => ['À la forge', "À\nla forge"]]]],
    ['version' => 1, 'recipes' => [[...$entry, 'layers' => [$layer, $layer]]]],
    ['version' => 1, 'recipes' => [[...$entry, 'layers' => [[...$layer, 'kind' => 'script']]]]],
    ['version' => 1, 'recipes' => [[...$entry, 'layers' => [[...$layer, 'mask' => ['width' => 10]]]]]],
    ['version' => 1, 'recipes' => [[...$entry, 'layers' => [[...$layer, 'url' => 'https://example.invalid']]]]],
    ['version' => 1, 'recipes' => [[...$entry, 'layers' => [[...$layer, 'intensity' => '0.5']]]]],
    ['version' => 1, 'recipes' => [[...$entry, 'layers' => [[...$layer, 'cycleSeconds' => 1801]]]]],
] as $malformed) {
    mapRecipeCheck(applicationNormalizeMapAnimationRecipeCatalog($malformed) === null, 'Une valeur hors contrat est refusée.');
}

echo json_encode(['ok' => true, 'checks' => $GLOBALS['mapRecipeChecks']], JSON_THROW_ON_ERROR), PHP_EOL;
