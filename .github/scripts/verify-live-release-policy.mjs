#!/usr/bin/env node

const requestedVersion = String(process.argv[2] || "").trim();
const origin = String(process.argv[3] || "https://regie-xar-tsaroth.fr").replace(/\/+$/, "");

if (!/^\d+\.\d+\.\d+$/.test(requestedVersion)) {
  console.error("Usage: node scripts/verify-live-release-policy.mjs <version> [origine]");
  process.exit(2);
}

if (!/^https:\/\/[^/]+$/i.test(origin)) {
  console.error("L’origine de contrôle doit être une origine HTTPS sans chemin.");
  process.exit(2);
}

const [major, minor, patch] = requestedVersion.split(".").map(Number);
const previousVersion = patch > 0
  ? `${major}.${minor}.${patch - 1}`
  : "0.0.0";
const futureVersion = `${major}.${minor}.${patch + 1}`;

async function readJson(pathname, clientVersion = "") {
  const headers = { Accept: "application/json" };
  if (clientVersion) headers["X-Xar-Client-Version"] = clientVersion;
  const response = await fetch(`${origin}${pathname}`, {
    method: "GET",
    headers,
    cache: "no-store",
    redirect: "error",
    signal: AbortSignal.timeout(20_000)
  });
  const text = await response.text();
  let body = null;
  try {
    body = text ? JSON.parse(text) : null;
  } catch {
    throw new Error(`${pathname} a répondu ${response.status} avec un corps non JSON.`);
  }
  return { status: response.status, body };
}

function requireValue(condition, message) {
  if (!condition) throw new Error(message);
}

try {
  const health = await readJson("/api/v1/health");
  requireValue(health.status === 200, `/health répond ${health.status} au lieu de 200.`);
  const policy = health.body?.clientPolicy;
  requireValue(health.body?.status === "ok", "/health n’annonce pas status=ok.");
  requireValue(policy?.enforce === true, "/health n’impose pas la politique cliente.");
  requireValue(policy?.exactVersion === true, "/health n’impose pas une version exacte.");
  requireValue(policy?.minimumVersion === requestedVersion, `minimumVersion=${policy?.minimumVersion ?? "absent"}, attendu ${requestedVersion}.`);
  requireValue(policy?.latestVersion === requestedVersion, `latestVersion=${policy?.latestVersion ?? "absent"}, attendu ${requestedVersion}.`);
  const buildPrefix = `client-${requestedVersion.replaceAll(".", "-")}-`;
  requireValue(String(health.body?.build || "").startsWith(buildPrefix), `build=${health.body?.build ?? "absent"}, préfixe attendu ${buildPrefix}.`);

  const probes = [
    { label: "ancienne", version: previousVersion, expectedStatus: 426, expectedCode: "client_update_required" },
    { label: "exacte", version: requestedVersion, expectedStatus: 401, expectedCode: "authentication_required" },
    { label: "future", version: futureVersion, expectedStatus: 426, expectedCode: "client_update_required" }
  ];
  for (const probe of probes) {
    const result = await readJson("/api/v1/auth/me", probe.version);
    requireValue(
      result.status === probe.expectedStatus,
      `${probe.label} ${probe.version}: HTTP ${result.status}, attendu ${probe.expectedStatus}.`
    );
    requireValue(
      result.body?.code === probe.expectedCode,
      `${probe.label} ${probe.version}: code=${result.body?.code ?? "absent"}, attendu ${probe.expectedCode}.`
    );
  }

  console.log(
    `Politique publique vérifiée pour ${requestedVersion}: `
    + `${previousVersion}=426, ${requestedVersion}=401, ${futureVersion}=426.`
  );
} catch (error) {
  console.error(`Remise MSIX refusée : ${error instanceof Error ? error.message : String(error)}`);
  process.exit(1);
}
