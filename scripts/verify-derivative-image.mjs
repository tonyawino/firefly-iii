import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import { createHash } from 'node:crypto';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';

export const BASE_DIGEST = 'sha256:a2b53539338d1cd04d341b229c3806cb178cf64c72459060e3ed34bec4167bd8';
export const UPSTREAM_COMMIT = 'ba26768619842a4926655f315716f1b7788933d7';
export const OVERLAY_FILES = Object.freeze([
  'routes/api.php', 'config/finance-hub.php', 'bootstrap/app.php',
  'app/Http/Middleware/AuthenticateFinanceHubProvisioner.php',
  'app/Http/Controllers/FinanceHub/ProvisioningController.php',
  'app/Services/FinanceHub/ProvisioningService.php',
  'app/Models/FinanceHub/ProvisioningOperation.php',
  'app/Models/FinanceHub/ProvisioningState.php',
  'database/migrations/2026_10_15_000000_create_finance_hub_provisioning_tables.php',
]);

export function verifyDerivativeImage(manifest, evidence) {
  for (const field of ['release_tag', 'upstream_commit', 'base_digest', 'derivative_commit', 'overlays', 'image_digest']) {
    assert.ok(manifest?.[field], `missing manifest field: ${field}`);
  }
  assert.equal(manifest.base_digest, BASE_DIGEST, 'unapproved base digest');
  assert.equal(manifest.upstream_commit, UPSTREAM_COMMIT, 'unapproved upstream source commit');
  assert.match(manifest.derivative_commit, /^[0-9a-f]{40}$/, 'invalid source commit');
  assert.equal(manifest.derivative_commit, evidence.sourceCommit, 'derivative source commit mismatch');
  assert.match(manifest.image_digest, /^sha256:[0-9a-f]{64}$/, 'invalid image digest');
  assert.ok(Array.isArray(evidence.existingReleaseTags), 'release tag lookup must be conclusive');
  assert.ok(!evidence.existingReleaseTags.includes(manifest.release_tag), 'release tag already exists');
  assert.deepEqual(Object.keys(manifest.overlays).sort(), [...OVERLAY_FILES].sort(), 'overlay manifest files differ');
  assert.deepEqual(Object.keys(evidence.overlayHashes).sort(), [...OVERLAY_FILES].sort(), 'overlay image files differ');
  for (const path of OVERLAY_FILES) {
    assert.match(manifest.overlays[path], /^[0-9a-f]{64}$/, `invalid overlay hash: ${path}`);
    assert.equal(manifest.overlays[path], evidence.overlayHashes[path], `overlay hash mismatch: ${path}`);
  }
  assert.ok(Array.isArray(evidence.changedImageFiles), 'image source inventory is required');
  for (const path of evidence.changedImageFiles) {
    assert.ok(OVERLAY_FILES.includes(path), `non-approved source file: ${path}`);
  }
  return true;
}

// Inspect local images without executing their normal application entrypoints.
// Release verification can supply independently collected JSON evidence instead.
export function verifyLocalImage(image, docker = 'docker') {
  assert.equal(image, 'fireflyiii-tenant-bootstrap:local', 'only the disposable local image is permitted');
  const run = args => execFileSync(docker, args, { encoding: 'utf8', maxBuffer: 32 * 1024 * 1024 }).trim();
  const inspect = ref => JSON.parse(run(['image', 'inspect', ref]))[0];
  const base = inspect(`fireflyiii/core@${BASE_DIGEST}`);
  const derivative = inspect(image);
  const sourceCommit = execFileSync('git', ['rev-parse', 'HEAD'], { encoding: 'utf8' }).trim();
  execFileSync('git', ['diff', '--exit-code', 'HEAD', '--', ...OVERLAY_FILES], { stdio: 'pipe' });
  assert.deepEqual(derivative.Config.Entrypoint, base.Config.Entrypoint, 'upstream entrypoint changed');
  assert.deepEqual(derivative.Config.Cmd, base.Config.Cmd, 'upstream command changed');
  assert.deepEqual(derivative.Config.Healthcheck, base.Config.Healthcheck, 'upstream healthcheck changed');
  assert.ok(derivative.Config.Env.includes('FIREFLY_III_PATH=/var/www/html'), 'upstream application path changed');
  assert.equal(derivative.Config.Labels['org.opencontainers.image.revision'], sourceCommit, 'source commit label mismatch');
  assert.equal(derivative.Config.Labels['org.opencontainers.image.base.digest'], BASE_DIGEST, 'base digest label mismatch');
  assert.equal(derivative.Config.Labels['org.opencontainers.image.source'], 'https://github.com/tonyawino/firefly-iii', 'source repository label mismatch');
  const inventory = ref => {
    const result = run(['run', '--rm', '--network', 'none', '--entrypoint', 'sh', ref, '-c',
      'cd /var/www/html && find . -type f -exec sha256sum {} +']);
    return new Map(result.split(/\r?\n/).map(line => [line.slice(68), line.slice(0, 64)]));
  };
  const baseFiles = inventory(`fireflyiii/core@${BASE_DIGEST}`);
  const imageFiles = inventory(image);
  const changedImageFiles = [...new Set([...baseFiles.keys(), ...imageFiles.keys()])]
    .filter(path => baseFiles.get(path) !== imageFiles.get(path));
  const overlays = Object.fromEntries(OVERLAY_FILES.map(path => [path, createHash('sha256').update(readFileSync(path)).digest('hex')]));
  const manifest = {
    release_tag: 'local-proof', upstream_commit: UPSTREAM_COMMIT, base_digest: BASE_DIGEST,
    derivative_commit: sourceCommit, overlays, image_digest: derivative.Id,
  };
  verifyDerivativeImage(manifest, {
    sourceCommit, overlayHashes: Object.fromEntries(OVERLAY_FILES.map(path => [path, imageFiles.get(path)])),
    changedImageFiles, existingReleaseTags: [],
  });
  return { image: image, image_id: derivative.Id, derivative_commit: sourceCommit, overlay_files: OVERLAY_FILES.length };
}

if (process.argv[1] === fileURLToPath(import.meta.url)) {
  try {
    if (process.argv[2] === '--local') {
      console.log(JSON.stringify(verifyLocalImage(process.argv[3], process.env.DOCKER_BIN || 'docker')));
    } else {
      assert.equal(process.argv.length, 4, 'usage: node scripts/verify-derivative-image.mjs MANIFEST.json EVIDENCE.json | --local fireflyiii-tenant-bootstrap:local');
      verifyDerivativeImage(JSON.parse(readFileSync(process.argv[2])), JSON.parse(readFileSync(process.argv[3])));
      console.log('Derivative image evidence verified');
    }
  } catch (error) {
    console.error(error.message);
    process.exitCode = 1;
  }
}
