import test from "node:test";
import assert from "node:assert/strict";
import { readFile } from "node:fs/promises";

async function source(path) {
  return readFile(new URL(`../${path}`, import.meta.url), "utf8");
}

test("le contrat backend borne les masques de brume, murs et vision par niveau", async () => {
  const domains = await source("api/v1/domains.php");
  assert.match(domains, /XAR_FOG_RASTER_MINIMUM = 32/);
  assert.match(domains, /XAR_FOG_RASTER_MAXIMUM = 256/);
  assert.match(domains, /function applicationActiveMapFogState/);
  assert.match(domains, /function applicationActiveMapOcclusionState/);
  assert.match(domains, /function applicationResolveWallCollision/);
  assert.match(domains, /function applicationComputeVisionMask/);
  assert.match(domains, /preg_match\('\/\^\[A-Za-z0-9_-\]\*\$\/D'/);
  assert.match(domains, /strlen\(\$decoded\) === \$expectedLength/);
  assert.match(domains, /validApplicationMapFogState\(\$payload\)/);
  assert.match(domains, /validApplicationMapFogState\(\$payload\['map'\]\)/);
  assert.match(domains, /invalid_map_fog/);
});

test("la projection joueur ne divulgue ni pion ni signal sous la brume ou hors vision", async () => {
  const online = await source("api/v1/online.php");
  const projection = online.slice(online.indexOf("function publicPlayerState"), online.indexOf("function requestedOnlineStateRevision"));
  assert.match(projection, /\$fog = applicationActiveMapFogState/);
  assert.match(projection, /\$occlusion = applicationActiveMapOcclusionState/);
  assert.match(projection, /\$visionMask = applicationComputeVisionMask/);
  assert.match(projection, /\$pointIsHidden = static fn/);
  assert.match(projection, /!\$owned && \$pointIsHidden/);
  assert.match(projection, /unset\(\$map\['layers'\]\)/);
  assert.match(projection, /unset\(\$map\['walls'\]\)/);
  assert.match(projection, /\$map\['fog'\] = \$fog/);
  assert.match(projection, /\$map\['visionMask'\] = \$visionMask/);
  assert.match(projection, /\|\| \$pointIsHidden\(\$ping\['x'\]/);
  assert.match(projection, /'size' => \(float\) \(\$token\['size'\] \?\? 50\)/);
});

test("la commande de déplacement recalcule tout le trajet avec le mur autoritaire", async () => {
  const online = await source("api/v1/online.php");
  const movement = online.slice(online.indexOf("} elseif ($command === 'token.move')"), online.indexOf("} elseif ($command === 'token.resource.adjust')"));
  assert.match(movement, /\$mapKey = 'map:' \. \$sceneId/);
  assert.match(movement, /applicationActiveMapOcclusionState\(\$map\)/);
  assert.match(movement, /applicationResolveWallCollision/);
  assert.match(movement, /\$token\['x'\] = \$resolved\['x'\]/);
  assert.match(movement, /\$result\['blockedByWall'\] = \$resolved\['blocked'\]/);
});
