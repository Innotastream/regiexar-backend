<?php
declare(strict_types=1);
require __DIR__ . '/atelier-test-bootstrap.php';
$fixtureRoot = (string) getenv('XAR_ATELIER_TEST_PRIVATE_DIRECTORY');
if ($fixtureRoot === '' || !is_dir($fixtureRoot) || !str_starts_with(basename($fixtureRoot), 'atelier-http-fixture-')) {
    throw new RuntimeException('Explicit temporary fixture storage required.');
}
putenv('XAR_REGIE_CONFIG=' . $fixtureRoot . DIRECTORY_SEPARATOR . 'config.php');
$method = (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET');
$path = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
try {
    if ($path === XAR_ATELIER_PREFIX . '/fixture/regie-auth') {
        $fixturePayload = atelierJsonBody();
        $fixtureAccount = authenticateAccount($db, $fixturePayload['username'] ?? '', $fixturePayload['password'] ?? '', 'gm');
        sendJson(200, ['ok' => true, 'id' => $fixtureAccount['id']]);
    }
    if ($path === XAR_ATELIER_PREFIX . '/fixture/stale-issue-session') {
        $oldIdentity = requireAtelierIdentity($db);
        $db->prepare('UPDATE atelier_users SET auth_revision=auth_revision+1 WHERE id=?')->execute([$oldIdentity['id']]);
        atelierIssueSession($db, $oldIdentity);
    }
    if ($path === XAR_ATELIER_PREFIX . '/fixture/stale-password-change') {
        $oldIdentity = requireAtelierIdentity($db);
        $db->prepare('UPDATE atelier_users SET password_verifier=?,auth_revision=auth_revision+1 WHERE id=?')
            ->execute([hashPassword('Fixture-Reset-Winner-2026'), $oldIdentity['id']]);
        changeAtelierPassword($db, $oldIdentity, ['currentPassword' => 'Fixture-Changed-Password-2026', 'newPassword' => 'Fixture-Stale-Overwrite-2026']);
    }
    if (preg_match('#^' . XAR_ATELIER_PREFIX . '/fixture/(regie-owner-media|regie-other-media|media-reference-count)/([A-Za-z0-9_-]{24})$#D', $path, $match) === 1) {
        if ($match[1] === 'media-reference-count') sendJson(200, ['references' => mediaDomainReferenceCount($db, $match[2])]);
        $fixtureIdentity = ['id' => $match[1] === 'regie-owner-media' ? 'fixture-regie-owner' : 'fixture-other-gm',
            'username' => $match[1] === 'regie-owner-media' ? 'Innota' : 'OtherGm',
            'permanent_role' => 'gm', 'effective_mode' => 'gm', 'can_administrate' => true];
        assertAtelierRegieMediaAccess($db, $fixtureIdentity, $match[2]);
        streamAtelierFile(mediaRecord($db, $match[2]), false);
    }
    handleAtelierRoute($db, [], $path, $method, $method === 'HEAD');
    sendError(404, 'Fixture route unknown.', 'not_found');
} catch (Throwable $error) {
    if ($db->inTransaction()) $db->rollBack();
    // Test-only error output; all fixture data is synthetic.
    sendJson(500, ['ok' => false, 'code' => 'fixture_exception', 'detail' => $error->getMessage()]);
}
