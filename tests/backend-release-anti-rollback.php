<?php

declare(strict_types=1);

$source = file_get_contents(__DIR__ . '/../api/v1/index.php');
if (!is_string($source)) {
    throw new RuntimeException('Source API absente.');
}

$constantStart = strpos($source, 'const XAR_API_HOST');
$constantEnd = strpos($source, 'date_default_timezone_set(');
$policyStart = strpos($source, 'function backendReleaseVersionStatus(');
$policyEnd = strpos($source, 'function synchronizeBackendRelease(', $policyStart);
if ($constantStart === false || $constantEnd === false || $policyStart === false || $policyEnd === false) {
    throw new RuntimeException('Bornes de la politique de génération introuvables.');
}
eval(substr($source, $constantStart, $constantEnd - $constantStart));
eval(substr($source, $policyStart, $policyEnd - $policyStart));

function checkReleasePolicy(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
    $GLOBALS['releaseChecks'] = ($GLOBALS['releaseChecks'] ?? 0) + 1;
}

checkReleasePolicy(backendReleaseVersionStatus(null, '0.15.19') === 'initialize', 'Une table absente peut être initialisée.');
checkReleasePolicy(backendReleaseVersionStatus(['backend_version' => '0.15.19'], '0.15.19') === 'current', 'La génération courante reste stable.');
checkReleasePolicy(backendReleaseVersionStatus(['backend_version' => '0.15.18'], '0.15.19') === 'upgrade', 'Une version strictement supérieure peut avancer.');
checkReleasePolicy(backendReleaseVersionStatus(['backend_version' => '0.15.19'], '0.15.18') === 'stale', 'Une ancienne instance ne peut pas rabaisser 0.15.19.');
checkReleasePolicy(backendReleaseVersionStatus(['backend_version' => '0.15.10'], '0.15.9') === 'stale', 'La comparaison est sémantique et non lexicale.');
checkReleasePolicy(backendReleaseVersionStatus(['backend_version' => '1.0.0'], '0.99.99') === 'stale', 'Le verrou couvre aussi les changements majeurs.');
checkReleasePolicy(backendReleaseVersionStatus(null, 'version-invalide') === 'invalid', 'Même une première instance invalide échoue fermée.');
checkReleasePolicy(backendReleaseVersionStatus(['backend_version' => '0.15.19-dev'], '0.15.20') === 'invalid', 'Une autorité non canonique échoue fermée.');
checkReleasePolicy(backendReleaseVersionStatus(['backend_version' => '0.15.19'], '00.15.20') === 'invalid', 'Une instance non canonique échoue fermée.');

$currentParts = array_map('intval', explode('.', XAR_BACKEND_VERSION));
$higherVersion = $currentParts[0] . '.' . $currentParts[1] . '.' . ($currentParts[2] + 1);
try {
    requireBackendReleaseAuthority(['backend_version' => $higherVersion]);
    throw new RuntimeException('Une instance obsolète a été admise.');
} catch (RuntimeException $error) {
    checkReleasePolicy($error->getMessage() === 'stale_backend_instance', 'Le refus obsolète porte un code dédié.');
}
try {
    requireBackendReleaseAuthority(['backend_version' => 'version-invalide']);
    throw new RuntimeException('Une autorité invalide a été admise.');
} catch (RuntimeException $error) {
    checkReleasePolicy($error->getMessage() === 'backend_release_version_invalid', 'Une autorité invalide échoue fermée.');
}

$syncStart = strpos($source, 'function synchronizeBackendRelease(');
$syncEnd = strpos($source, 'function readJsonBody(', $syncStart);
if ($syncStart === false || $syncEnd === false) {
    throw new RuntimeException('Synchronisation de génération introuvable.');
}
$sync = substr($source, $syncStart, $syncEnd - $syncStart);
$lock = strpos($sync, 'acquireMaintenanceLock(');
$guards = [];
$offset = 0;
while (($guard = strpos($sync, 'requireBackendReleaseAuthority($state)', $offset)) !== false) {
    $guards[] = $guard;
    $offset = $guard + 1;
}
$releaseWrite = strpos($sync, "'INSERT INTO backend_release_state '");
$upgradeGuard = strpos($sync, "if (\$releaseStatus === 'initialize' || \$releaseStatus === 'upgrade')");
checkReleasePolicy(count($guards) === 2, 'L’autorité est vérifiée avant et après le verrou.');
checkReleasePolicy($lock !== false && $guards[0] < $lock && $guards[1] > $lock, 'Une instance devenue obsolète pendant la course est refusée.');
checkReleasePolicy($releaseWrite !== false && $upgradeGuard !== false && $guards[1] < $upgradeGuard && $upgradeGuard < $releaseWrite, 'Toute écriture de génération exige une initialisation ou une progression.');
checkReleasePolicy(str_contains($source, "'stale_backend_instance' => 'stale_backend_instance'"), 'Le refus obsolète est exposé comme indisponibilité explicite.');
checkReleasePolicy(str_contains($source, "release authority rejected: ' . \$runtimeCode"), 'Le journal distingue un refus d’autorité d’une panne de base de données.');

echo 'Anti-rollback backend PHP : ' . $GLOBALS['releaseChecks'] . " contrôles réussis.\n";
