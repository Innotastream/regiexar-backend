<?php

declare(strict_types=1);

// Execute the real release constants and policy gate, without routing or SQL.
$source = file_get_contents(__DIR__ . '/../api/v1/index.php');
if (!is_string($source)) throw new RuntimeException('Source API absente.');
$constantsStart = strpos($source, 'const XAR_API_HOST');
$constantsEnd = strpos($source, "date_default_timezone_set(");
$policyStart = strpos($source, 'function clientPolicy(');
$policyEnd = strpos($source, 'function databaseConnection(');
if ($constantsStart === false || $constantsEnd === false || $policyStart === false || $policyEnd === false) {
    throw new RuntimeException('Bornes des fonctions de production introuvables.');
}
eval(substr($source, $constantsStart, $constantsEnd - $constantsStart));
eval(substr($source, $policyStart, $policyEnd - $policyStart));

final class PolicyResponse extends RuntimeException
{
    public function __construct(public int $status, public array $body) { parent::__construct('HTTP ' . $status); }
}
final class PolicyConnection extends PDO { public function __construct() {} }
function sendJson(int $status, array $body): never { throw new PolicyResponse($status, $body); }
function sendError(int $status, string $message, string $code = ''): never { sendJson($status, ['error' => $message, 'code' => $code]); }
function requestSessionToken(): string { return ''; }
function checkPolicy(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
    $GLOBALS['checks'] = ($GLOBALS['checks'] ?? 0) + 1;
}

$policy = clientPolicy(['client' => ['enforce' => false, 'minimumVersion' => '1.0.0', 'latestVersion' => '99.0.0']]);
checkPolicy($policy['enforce'] === true && $policy['exactVersion'] === true, 'La connexion impose la version courante exacte.');
checkPolicy($policy['allowedVersions'] === ['3.4.10'], 'Seule la version 3.4.10 est admise.');
checkPolicy($policy['minimumVersion'] === '3.4.10' && $policy['latestVersion'] === '3.4.10', 'La politique annonce exclusivement 3.4.10.');
checkPolicy($policy['storeId'] === '9N5N5M67N704', 'Le Store reste inchangé.');
$manifest = json_decode(file_get_contents(__DIR__ . '/../manifest.json'), true, 512, JSON_THROW_ON_ERROR);
checkPolicy($manifest['allowedApplicationVersions'] === $policy['allowedVersions'], 'Le manifeste et la politique concordent.');
checkPolicy($manifest['announcedApplicationVersion'] === $policy['latestVersion'] && $manifest['backendVersion'] === XAR_BACKEND_VERSION, 'Les versions annoncées concordent.');
$connection = new PolicyConnection();
foreach (['/share/' . str_repeat('a', 22), '/share/' . str_repeat('a', 22) . '/image'] as $publicRoute) {
    checkPolicy(!routeRequiresSupportedClient($publicRoute), 'Les images volontairement publiées restent accessibles aux navigateurs sans en-tête client.');
}
foreach (['/api/v1/media/' . str_repeat('a', 24), '/api/v1/shared-media', '/api/v1/auth/login',
    '/share/' . str_repeat('a', 21), '/share/' . str_repeat('a', 22) . '/private', '/share/' . str_repeat('a', 22) . '/image/more'] as $privateRoute) {
    checkPolicy(routeRequiresSupportedClient($privateRoute), 'Aucune route privée ou suffixe inconnu ne bénéficie de l’exception de publication.');
}
foreach (['gm', 'player'] as $mode) {
    $_SERVER['REQUEST_URI'] = '/api/v1/auth/login';
    $_SERVER['REQUEST_METHOD'] = 'POST';
    foreach (['3.4.10'] as $version) {
        $_SERVER['HTTP_X_XAR_CLIENT_VERSION'] = $version;
        requireSupportedClient($connection, []);
        checkPolicy(true, "$mode $version atteint la vérification des identifiants.");
    }
    foreach (['', '3.1.13', '3.2.9', '3.2.10', '3.2.11', '3.2.12', '3.2.13', '3.2.14', '3.2.15', '3.2.16', '3.2.17', '3.2.18', '3.2.19', '3.3.0', '3.3.1', '3.3.2', '3.3.3', '3.3.4', '3.3.5', '3.3.6', '3.3.7', '3.3.8', '3.3.9', '3.3.10', '3.3.11', '3.3.12', '3.4.0', '3.4.1', '3.4.2', '3.4.3', '3.4.4', '3.4.5', '3.4.6', '3.4.7', '3.4.8', '3.4.9', '3.4.11', '99.0.0', '3.4.0-beta', '3.4.0+local', '03.4.0', '3.3.11,3.4.0'] as $version) {
        $_SERVER['HTTP_X_XAR_CLIENT_VERSION'] = $version;
        try { requireSupportedClient($connection, []); throw new RuntimeException("Version admise à tort : $version"); }
        catch (PolicyResponse $response) {
            checkPolicy($response->status === 426, "$mode $version doit être refusé.");
            checkPolicy($response->body['allowedVersions'] === ['3.4.10']
                && $response->body['latestVersion'] === '3.4.10'
                && $response->body['exactVersion'] === true
                && $response->body['code'] === 'client_update_required', 'Le refus décrit la mise à jour requise sans bloquer la santé publique.');
        }
    }
}
echo 'Politique cliente PHP : ' . $GLOBALS['checks'] . " contrôles réussis.\n";
