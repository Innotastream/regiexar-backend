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
checkPolicy($policy['enforce'] === true && $policy['exactVersion'] === true, 'La connexion exige la version courante exacte.');
checkPolicy($policy['allowedVersions'] === ['3.2.12'], 'Seule la version courante est admise.');
checkPolicy($policy['minimumVersion'] === '3.2.12' && $policy['latestVersion'] === '3.2.12', 'La politique annonce la version à installer.');
checkPolicy($policy['storeId'] === '9N5N5M67N704', 'Le Store reste inchangé.');
$manifest = json_decode(file_get_contents(__DIR__ . '/../manifest.json'), true, 512, JSON_THROW_ON_ERROR);
checkPolicy($manifest['allowedApplicationVersions'] === $policy['allowedVersions'], 'Le manifeste et la politique concordent.');
checkPolicy($manifest['announcedApplicationVersion'] === $policy['latestVersion'] && $manifest['backendVersion'] === XAR_BACKEND_VERSION, 'Les versions annoncées concordent.');
$connection = new PolicyConnection();
foreach (['gm', 'player'] as $mode) {
    $_SERVER['REQUEST_URI'] = '/api/v1/auth/login';
    $_SERVER['REQUEST_METHOD'] = 'POST';
    foreach (['3.2.12'] as $version) {
        $_SERVER['HTTP_X_XAR_CLIENT_VERSION'] = $version;
        requireSupportedClient($connection, []);
        checkPolicy(true, "$mode $version atteint la vérification des identifiants.");
    }
    foreach (['', '3.1.13', '3.2.9', '3.2.10', '3.2.11', '3.2.13', '3.3.0', '99.0.0', '3.2.12-beta', '3.2.12+local', '03.2.12', '3.2.11,3.2.12'] as $version) {
        $_SERVER['HTTP_X_XAR_CLIENT_VERSION'] = $version;
        try { requireSupportedClient($connection, []); throw new RuntimeException("Version admise à tort : $version"); }
        catch (PolicyResponse $response) {
            checkPolicy($response->status === 426, "$mode $version doit être refusé.");
            checkPolicy($response->body['allowedVersions'] === ['3.2.12']
                && $response->body['latestVersion'] === '3.2.12'
                && $response->body['exactVersion'] === true
                && $response->body['code'] === 'client_update_required', 'Le refus décrit la mise à jour requise sans bloquer la santé publique.');
        }
    }
}
echo 'Politique cliente PHP : ' . $GLOBALS['checks'] . " contrôles réussis.\n";
