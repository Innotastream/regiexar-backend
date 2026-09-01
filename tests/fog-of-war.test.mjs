import test from "node:test";
import assert from "node:assert/strict";
import { readFile } from "node:fs/promises";

async function source(path) {
  return readFile(new URL(`../${path}`, import.meta.url), "utf8");
}

test("le contrat backend borne les masques de brouillard par niveau", async () => {
  const domains = await source("api/v1/domains.php");
  assert.match(domains, /XAR_FOG_RASTER_MINIMUM = 32/);
  assert.match(domains, /XAR_FOG_RASTER_MAXIMUM = 256/);
  assert.match(domains, /function applicationActiveMapFogState/);
  assert.match(domains, /preg_match\('\/\^\[A-Za-z0-9_-\]\*\$\/D'/);
  assert.match(domains, /strlen\(\$decoded\) === \$expectedLength/);
  assert.match(domains, /validApplicationMapFogState\(\$payload\)/);
  assert.match(domains, /validApplicationMapFogState\(\$payload\['map'\]\)/);
  assert.match(domains, /invalid_map_fog/);
});

test("la projection joueur ne divulgue ni pion ni signal sous le brouillard", async () => {
  const online = await source("api/v1/online.php");
  const projection = online.slice(online.indexOf("function publicPlayerState"), online.indexOf("function requestedOnlineStateRevision"));
  assert.match(projection, /\$fog = applicationActiveMapFogState/);
  assert.match(projection, /!\$owned && applicationFogCoversPoint/);
  assert.match(projection, /unset\(\$map\['layers'\]\)/);
  assert.match(projection, /\$map\['fog'\] = \$fog/);
  assert.match(projection, /applicationFogCoversPoint\(\$fog, \$ping\['x'\]/);
  assert.match(projection, /'size' => \(float\) \(\$token\['size'\] \?\? 50\)/);
});
