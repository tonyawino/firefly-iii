#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."

# Only the dedicated local tag and generated synthetic identities are accepted.
export FH_TEST_IMAGE="${FH_TEST_IMAGE:-fireflyiii-tenant-bootstrap:local}"
[[ "$FH_TEST_IMAGE" == fireflyiii-tenant-bootstrap:local ]] || { echo 'Refusing non-local image' >&2; exit 1; }
[[ "${FH_TEST_MODE:-synthetic}" == synthetic ]] || { echo 'Refusing non-synthetic test settings' >&2; exit 1; }
if command -v docker.exe >/dev/null 2>&1; then
  docker_bin=docker.exe
else
  docker_bin=docker
fi
# Inspect only local context metadata before any daemon operation. Pin that
# validated context for every command, overriding inherited host/context settings.
docker_context="$("$docker_bin" context show | tr -d '\r')"
endpoint="$("$docker_bin" context inspect "$docker_context" --format '{{.Endpoints.docker.Host}}' | tr -d '\r')"
for candidate in "${DOCKER_HOST:-$endpoint}" "$endpoint"; do
  case "$candidate" in
    unix:///*|npipe:////./pipe/*) ;;
    *) echo 'Refusing a non-local Docker endpoint' >&2; exit 1 ;;
  esac
done
docker_local() { "$docker_bin" --context "$docker_context" "$@"; }
export COMPOSE_DISABLE_ENV_FILE=1
unset COMPOSE_FILE COMPOSE_PROFILES COMPOSE_ENV_FILES
project="fh-bootstrap-test-$(node -e 'process.stdout.write(require("node:crypto").randomBytes(10).toString("hex"))')"
readonly project
compose_file=docker/compose.finance-hub-bootstrap-test.yaml
compose() { docker_local compose -p "$project" -f "$compose_file" "$@"; }
cleanup() {
  local result=$?
  trap - EXIT
  compose down -v --remove-orphans || result=1
  local resource remaining
  local -a list_args
  for resource in container network volume; do
    list_args=(ls -q)
    [[ "$resource" != container ]] || list_args=(ls -aq)
    if ! remaining="$(docker_local "$resource" "${list_args[@]}" --filter "label=com.docker.compose.project=$project")"; then
      echo "Could not verify $resource cleanup for $project" >&2
      result=1
    elif [[ -n "$remaining" ]]; then
      echo "Disposable project still has $resource resources: $project" >&2
      result=1
    fi
  done
  [[ "$result" != 0 ]] || echo "Cleanup verified for $project"
  exit "$result"
}

# Never load .env or accept credentials/hosts from a caller.
export FH_TEST_DB_PASSWORD_A="synthetic-$(node -e 'process.stdout.write(require("node:crypto").randomBytes(32).toString("hex"))')"
export FH_TEST_DB_PASSWORD_B="synthetic-$(node -e 'process.stdout.write(require("node:crypto").randomBytes(32).toString("hex"))')"
export FH_TEST_SECRET_A="synthetic-$(node -e 'process.stdout.write(require("node:crypto").randomBytes(32).toString("hex"))')"
export FH_TEST_SECRET_B="synthetic-$(node -e 'process.stdout.write(require("node:crypto").randomBytes(32).toString("hex"))')"
export FH_TEST_APP_KEY_A="base64:$(node -e 'process.stdout.write(require("node:crypto").randomBytes(32).toString("base64"))')"
export FH_TEST_APP_KEY_B="base64:$(node -e 'process.stdout.write(require("node:crypto").randomBytes(32).toString("base64"))')"
export FH_TEST_TENANT_A="$(node -e 'process.stdout.write(require("node:crypto").randomUUID())')"
export FH_TEST_TENANT_B="$(node -e 'process.stdout.write(require("node:crypto").randomUUID())')"

# WSL does not forward arbitrary Linux variables to Windows docker.exe.
if [[ "$docker_bin" == docker.exe ]]; then
  export WSLENV="${WSLENV:+$WSLENV:}COMPOSE_DISABLE_ENV_FILE:FH_TEST_IMAGE:FH_TEST_DB_PASSWORD_A:FH_TEST_DB_PASSWORD_B:FH_TEST_SECRET_A:FH_TEST_SECRET_B:FH_TEST_APP_KEY_A:FH_TEST_APP_KEY_B:FH_TEST_TENANT_A:FH_TEST_TENANT_B"
fi
trap cleanup EXIT
trap 'exit 130' INT
trap 'exit 143' TERM

compose config --quiet
# Validate the fully resolved configuration before creating a single resource.
compose --profile proof config --format json | node --input-type=module -e '
import assert from "node:assert/strict";
let data = ""; for await (const chunk of process.stdin) data += chunk;
const config = JSON.parse(data);
for (const service of Object.values(config.services)) {
  assert.ok(["fireflyiii-tenant-bootstrap:local", "postgres:17-alpine"].includes(service.image), "non-local test image");
  assert.ok(!service.ports?.length, "published ports are forbidden");
  assert.ok(!service.network_mode && !service.privileged, "unsafe container configuration");
  for (const mount of service.volumes ?? []) assert.equal(mount.type, "volume", "host mounts are forbidden");
}
for (const network of Object.values(config.networks)) assert.ok(network.internal && !network.external);
for (const volume of Object.values(config.volumes)) assert.ok(!volume.external);
for (const id of ["a", "b"]) {
  const env = config.services[`firefly-${id}`].environment;
  assert.equal(env.APP_ENV, "production");
  assert.equal(env.APP_URL, `http://firefly-${id}:8080`);
  assert.equal(env.DB_HOST, `postgres-${id}`);
  assert.equal(env.DB_DATABASE, "synthetic_firefly");
  assert.equal(env.DB_USERNAME, "synthetic_firefly");
  assert.ok(env.DB_PASSWORD.startsWith("synthetic-") && env.FIREFLY_PROVISIONING_SECRET.startsWith("synthetic-"));
}
'
echo "Starting disposable project $project"
compose up -d --wait --wait-timeout 360 firefly-a firefly-b
# Neither service has a published binding, and both networks prevent external egress.
for service in firefly-a firefly-b postgres-a postgres-b; do
  docker_local inspect --format '{{json .HostConfig.PortBindings}}' "$(compose ps -q "$service")" | node -e '
let s=""; process.stdin.on("data", x => s+=x).on("end", () => {
  if (Object.keys(JSON.parse(s) ?? {}).length) process.exit(1);
});'
done
for tenant in a b; do
  # Do not invoke migrate/upgrade: the inherited startup must have applied it.
  compose exec -T "postgres-$tenant" psql -U synthetic_firefly -d synthetic_firefly -tAc \
    "SELECT count(*) FROM migrations WHERE migration = '2026_10_15_000000_create_finance_hub_provisioning_tables'" | tr -d '\r' | node -e '
let s=""; process.stdin.on("data", x => s+=x).on("end", () => { if(s.trim()!=="1") process.exit(1); });'
done
echo 'PASS: normal entrypoint applied the derivative migration in both PostgreSQL databases'
compose run --rm --no-deps -T test-client <<'PHP'
<?php
declare(strict_types=1);

function ensure(bool $condition, string $message): void {
    if (!$condition) { throw new RuntimeException($message); }
}
function uuid(): string {
    $hex = bin2hex(random_bytes(16));
    return substr($hex, 0, 8).'-'.substr($hex, 8, 4).'-4'.substr($hex, 13, 3).'-a'.substr($hex, 17, 3).'-'.substr($hex, 20);
}
function request(string $host, string $path, ?string $secret = null, ?array $payload = null): array {
    // Exact host allowlist, no redirects/proxies, internal Docker networks only.
    ensure(in_array($host, ['firefly-a', 'firefly-b'], true), 'Blocked non-synthetic hostname');
    ensure(str_starts_with($path, '/api/v1/'), 'Blocked non-API path');
    $curl = curl_init('http://'.$host.':8080'.$path);
    $headers = ['Accept: application/json', 'Content-Type: application/json', 'Origin: https://synthetic.invalid'];
    if (null !== $secret) { $headers[] = 'Authorization: Bearer '.$secret; }
    curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20,
        CURLOPT_FOLLOWLOCATION => false, CURLOPT_PROXY => '', CURLOPT_HTTPHEADER => $headers, CURLOPT_HEADER => true]);
    if (null !== $payload) {
        curl_setopt($curl, CURLOPT_POST, true);
        curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($payload, JSON_THROW_ON_ERROR));
    }
    $raw = curl_exec($curl);
    ensure(is_string($raw), 'Synthetic request failed');
    $status = curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    $headerSize = curl_getinfo($curl, CURLINFO_HEADER_SIZE);
    $responseHeaders = strtolower(substr($raw, 0, $headerSize));
    $body = json_decode(substr($raw, $headerSize), true, flags: JSON_THROW_ON_ERROR);
    if (str_contains($path, '/provisioning/')) {
        ensure(str_contains($responseHeaders, 'no-store'), 'Provisioning response must not be cached');
        ensure(!str_contains($responseHeaders, 'access-control-allow-origin:'), 'Provisioning must not be browser accessible');
        ensure(!str_contains($responseHeaders, 'set-cookie:'), 'Provisioning must not create browser sessions');
    }
    return [$status, $body];
}
function expect(int $status, array $response, string $message): array {
    ensure($status === $response[0], $message.' (HTTP '.$response[0].')');
    return $response[1];
}
$prefix = '/api/v1/finance-hub/provisioning';
$tenantA = getenv('FH_TEST_TENANT_A'); $tenantB = getenv('FH_TEST_TENANT_B');
$secretA = getenv('FH_TEST_SECRET_A'); $secretB = getenv('FH_TEST_SECRET_B');
$payloadA = ['tenant_id' => $tenantA, 'idempotency_key' => uuid()];
$payloadB = ['tenant_id' => $tenantB, 'idempotency_key' => uuid()];
foreach (['firefly-a', 'firefly-b'] as $host) {
    expect(401, request($host, $prefix.'/bootstrap', payload: $payloadA), 'Public bootstrap denied');
}
expect(401, request('firefly-a', $prefix.'/bootstrap', $secretB, $payloadB), 'Tenant B secret denied on A');
expect(409, request('firefly-a', $prefix.'/bootstrap', $secretA, $payloadB), 'Tenant B UUID denied on A');
expect(401, request('firefly-b', $prefix.'/bootstrap', $secretA, $payloadA), 'Tenant A secret denied on B');
expect(409, request('firefly-b', $prefix.'/bootstrap', $secretB, $payloadA), 'Tenant A UUID denied on B');
echo "PASS: unauthenticated requests and crossed tenant identities are denied\n";
$a = expect(200, request('firefly-a', $prefix.'/bootstrap', $secretA, $payloadA), 'Bootstrap A');
$b = expect(200, request('firefly-b', $prefix.'/bootstrap', $secretB, $payloadB), 'Bootstrap B');
ensure(isset($a['credential'], $b['credential']) && $a['credential'] !== $b['credential'], 'Distinct tenant credentials');
ensure($a === expect(200, request('firefly-a', $prefix.'/bootstrap', $secretA, $payloadA), 'Replay A'), 'Same-key replay must recover exact token');
ensure($b === expect(200, request('firefly-b', $prefix.'/bootstrap', $secretB, $payloadB), 'Replay B'), 'Same-key B replay');
expect(200, request('firefly-a', '/api/v1/about', $a['credential']), 'A credential works');
expect(200, request('firefly-b', '/api/v1/about', $b['credential']), 'B credential works');
expect(401, request('firefly-b', '/api/v1/about', $a['credential']), 'A credential isolated from B');
expect(401, request('firefly-a', '/api/v1/about', $b['credential']), 'B credential isolated from A');
expect(409, request('firefly-b', $prefix.'/operations/'.$a['operation_id'].'?tenant_id='.$tenantB, $secretB), 'A operation isolated from B');
$status = expect(200, request('firefly-a', $prefix.'/operations/'.$a['operation_id'].'?tenant_id='.$tenantA, $secretA), 'Redacted status');
ensure(!array_key_exists('credential', $status), 'Status never returns credential');
echo "PASS: both bootstraps, exact same-key replay, token authentication, and tenant isolation\n";
$rotationPayload = ['tenant_id' => $tenantA, 'idempotency_key' => uuid()];
expect(409, request('firefly-a', $prefix.'/credentials/rotate', $secretA, $rotationPayload), 'Rotation requires acknowledgement');
$ack = expect(200, request('firefly-a', $prefix.'/operations/'.$a['operation_id'].'/ack', $secretA, ['tenant_id' => $tenantA]), 'Acknowledge bootstrap');
ensure(!array_key_exists('credential', $ack), 'Acknowledgement redacted');
$rotation = expect(200, request('firefly-a', $prefix.'/credentials/rotate', $secretA, $rotationPayload), 'Prepare rotation');
ensure($rotation === expect(200, request('firefly-a', $prefix.'/credentials/rotate', $secretA, $rotationPayload), 'Replay rotation'), 'Exact replacement replay');
ensure($rotation['credential'] !== $a['credential'], 'Replacement differs');
expect(200, request('firefly-a', '/api/v1/about', $a['credential']), 'Old token retained before confirm');
expect(200, request('firefly-a', '/api/v1/about', $rotation['credential']), 'Replacement valid before confirm');
$confirmPath = $prefix.'/credentials/rotate/'.$rotation['operation_id'].'/confirm';
$committed = expect(200, request('firefly-a', $confirmPath, $secretA, ['tenant_id' => $tenantA]), 'Confirm rotation');
ensure($committed['state'] === 'committed' && !array_key_exists('credential', $committed), 'Confirmed response redacted');
ensure($committed === expect(200, request('firefly-a', $confirmPath, $secretA, ['tenant_id' => $tenantA]), 'Replay confirmation'), 'Confirmation idempotent');
ensure($committed === expect(200, request('firefly-a', $prefix.'/credentials/rotate', $secretA, $rotationPayload), 'Replay committed rotation'), 'Committed replay redacted');
expect(401, request('firefly-a', '/api/v1/about', $a['credential']), 'Old token revoked after confirm');
expect(200, request('firefly-a', '/api/v1/about', $rotation['credential']), 'Replacement remains valid');
expect(200, request('firefly-b', '/api/v1/about', $b['credential']), 'Tenant B remains valid');
echo "PASS: rotation preserves old token until confirmation, revokes only old token, and redacts replays\n";
PHP
