import assert from "node:assert/strict";
import { readFile } from "node:fs/promises";
import test from "node:test";

const root = new URL("../", import.meta.url);
const read = (name) => readFile(new URL(name, root), "utf8");

test("une ancienne instance backend ne peut jamais rabaisser l’autorité publiée", async () => {
  const [index, workflow] = await Promise.all([
    read("api/v1/index.php"),
    read(".github/workflows/backend-check.yml")
  ]);
  const release = index.slice(
    index.indexOf("function backendReleaseVersionStatus"),
    index.indexOf("function readJsonBody")
  );
  const statusPolicy = release.slice(0, release.indexOf("function requireBackendReleaseAuthority"));
  assert.ok(
    statusPolicy.indexOf("if (!$validVersion($candidateVersion))") < statusPolicy.indexOf("if (!is_array($state))"),
    "la version de l’instance doit être validée même lors de la première initialisation"
  );
  assert.match(release, /version_compare\(\$candidateVersion, \$authorityVersion\)/);
  assert.match(release, /\$comparison < 0[\s\S]*?return 'stale'/);
  assert.match(release, /\$status === 'stale'[\s\S]*?throw new RuntimeException\('stale_backend_instance'\)/);

  const synchronize = release.slice(release.indexOf("function synchronizeBackendRelease"));
  const lock = synchronize.indexOf("acquireMaintenanceLock");
  const guards = [...synchronize.matchAll(/requireBackendReleaseAuthority\(\$state\)/g)].map((match) => match.index);
  const write = synchronize.indexOf("'INSERT INTO backend_release_state '");
  const upgradeOnly = synchronize.indexOf("$releaseStatus === 'initialize' || $releaseStatus === 'upgrade'");
  assert.equal(guards.length, 2);
  assert.ok(guards[0] < lock && guards[1] > lock, "l’autorité doit être relue sous verrou");
  assert.ok(guards[1] < upgradeOnly && upgradeOnly < write, "l’écriture doit rester réservée à une progression");
  assert.match(index, /'stale_backend_instance' => 'stale_backend_instance'/);
  assert.match(index, /release authority rejected:.*\$runtimeCode/);
  assert.match(workflow, /php tests\/backend-release-anti-rollback\.php/);
});
