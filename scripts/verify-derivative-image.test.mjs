import assert from 'node:assert/strict';
import { test } from 'node:test';

import { verifyDerivativeImage } from './verify-derivative-image.mjs';
const base = 'sha256:a2b53539338d1cd04d341b229c3806cb178cf64c72459060e3ed34bec4167bd8';
const commit = 'a'.repeat(40);
const paths = [
  'routes/api.php', 'config/finance-hub.php', 'bootstrap/app.php',
  'app/Http/Middleware/AuthenticateFinanceHubProvisioner.php',
  'app/Http/Controllers/FinanceHub/ProvisioningController.php',
  'app/Services/FinanceHub/ProvisioningService.php',
  'app/Models/FinanceHub/ProvisioningOperation.php',
  'app/Models/FinanceHub/ProvisioningState.php',
  'database/migrations/2026_10_15_000000_create_finance_hub_provisioning_tables.php',
];
function fixture() {
  const overlays = Object.fromEntries(paths.map(path => [path, 'b'.repeat(64)]));
  return {
    manifest: {
      release_tag: '6.7.9-fh.1', upstream_commit: 'ba26768619842a4926655f315716f1b7788933d7',
      base_digest: base, derivative_commit: commit, overlays,
      image_digest: `sha256:${'c'.repeat(64)}`,
    },
    evidence: { sourceCommit: commit, overlayHashes: { ...overlays }, changedImageFiles: [...paths], existingReleaseTags: [] },
  };
}
test('accepts the approved base digest and exact derivative source commit', () => {
  const { manifest, evidence } = fixture();
  assert.equal(verifyDerivativeImage(manifest, evidence), true);
});
test('rejects an unapproved base digest', () => {
  const { manifest, evidence } = fixture();
  manifest.base_digest = `sha256:${'d'.repeat(64)}`;
  assert.throws(() => verifyDerivativeImage(manifest, evidence), /base digest/i);
});
test('rejects a derivative source commit that differs from the build', () => {
  const { manifest, evidence } = fixture();
  evidence.sourceCommit = 'd'.repeat(40);
  assert.throws(() => verifyDerivativeImage(manifest, evidence), /source commit/i);
});
test('rejects a duplicate release tag', () => {
  const { manifest, evidence } = fixture();
  evidence.existingReleaseTags = [manifest.release_tag];
  assert.throws(() => verifyDerivativeImage(manifest, evidence), /release tag.*exists/i);
});
test('rejects each missing required manifest field', () => {
  for (const field of Object.keys(fixture().manifest)) {
    const { manifest, evidence } = fixture();
    delete manifest[field];
    assert.throws(() => verifyDerivativeImage(manifest, evidence), /missing manifest field/i, field);
  }
});
test('rejects an overlay hash that differs from the image contents', () => {
  const { manifest, evidence } = fixture();
  evidence.overlayHashes['routes/api.php'] = 'd'.repeat(64);
  assert.throws(() => verifyDerivativeImage(manifest, evidence), /overlay hash/i);
});
test('rejects an image containing a non-approved source file', () => {
  const { manifest, evidence } = fixture();
  evidence.changedImageFiles.push('app/Http/Controllers/BackdoorController.php');
  assert.throws(() => verifyDerivativeImage(manifest, evidence), /non-approved source file/i);
});
test('rejects a missing approved overlay even if the manifest and image agree', () => {
  const { manifest, evidence } = fixture();
  delete manifest.overlays['routes/api.php'];
  delete evidence.overlayHashes['routes/api.php'];
  assert.throws(() => verifyDerivativeImage(manifest, evidence), /overlay.*files/i);
});
