import test from "node:test";
import assert from "node:assert/strict";
import { readFile } from "node:fs/promises";

async function source(path) {
  return readFile(new URL(`../${path}`, import.meta.url), "utf8");
}

test("le backend distingue modification de seuil et personnalisation du résultat", async () => {
  const [online, domains] = await Promise.all([
    source("api/v1/online.php"),
    source("api/v1/domains.php")
  ]);
  assert.match(online, /classifyOnlineD100Outcome\(mixed \$rawValue, mixed \$threshold = null, mixed \$modifier = 0, mixed \$resultModifier = 0\)/);
  assert.match(online, /\$comparedResult = \$raw \+ \$appliedResultModifier/);
  assert.match(online, /\$success = \$comparedResult <= \$effectiveThreshold/);
  assert.match(online, /\$modifierMode = \(\$arguments\['modifierMode'\] \?\? ''\) === 'result'/);
  assert.match(online, /\$formula = '1d100' \. \(\$resultModifier !== 0/);
  assert.match(online, /classifyOnlineD100Outcome\(\$rolled\['rawD100'\] \?\? null, \$threshold, \$modifier, \$resultModifier\)/);
  assert.match(domains, /'resultModifier'/);
  assert.match(domains, /\$outcome\['result'\]/);
});
