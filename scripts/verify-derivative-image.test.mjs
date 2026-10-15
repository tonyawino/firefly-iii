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
      image_digest: `sha256:${'c'.repeat(64)}`, source_archive_sha256: 'e'.repeat(64),
    },
    evidence: {
      sourceCommit: commit, overlayHashes: { ...overlays }, changedImageFiles: [...paths], existingReleaseTags: [],
      upstreamIsAncestor: true, sourceArchiveSha256: 'e'.repeat(64), imageIndexDigest: `sha256:${'c'.repeat(64)}`,
      imageTagAbsent: true,
      platforms: Object.fromEntries(['linux/amd64', 'linux/arm64'].map(platform => [platform, {
        baseDigest: base, baseLayers: [`sha256:${'f'.repeat(64)}`],
        imageLayers: [`sha256:${'f'.repeat(64)}`, `sha256:${'b'.repeat(64)}`],
        overlayHashes: { ...overlays }, changedImageFiles: [...paths],
      }])),
    },
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

test('rejects malformed or unsupported release tags', () => {
  for (const tag of ['local-proof', '6.7.9-fh.0', '6.7.9-fh.01', '6.7.8-fh.1', '6.7.9-fh.1\n', 'latest']) {
    const { manifest, evidence } = fixture();
    manifest.release_tag = tag;
    assert.throws(() => verifyDerivativeImage(manifest, evidence), /release tag syntax/i);
  }
});
test('requires independently observed image index digest', () => {
  const { manifest, evidence } = fixture();
  evidence.imageIndexDigest = `sha256:${'d'.repeat(64)}`;
  assert.throws(() => verifyDerivativeImage(manifest, evidence), /image index digest/i);
});
test('requires selected upstream ancestry', () => {
  const { manifest, evidence } = fixture();
  evidence.upstreamIsAncestor = false;
  assert.throws(() => verifyDerivativeImage(manifest, evidence), /upstream ancestry/i);
});
test('requires the exact source archive checksum', () => {
  const { manifest, evidence } = fixture();
  evidence.sourceArchiveSha256 = 'd'.repeat(64);
  assert.throws(() => verifyDerivativeImage(manifest, evidence), /source archive checksum/i);
});
test('checks actual base layer ancestry for both release platforms', () => {
  for (const platform of ['linux/amd64', 'linux/arm64']) {
    const { manifest, evidence } = fixture();
    evidence.platforms[platform].imageLayers[0] = `sha256:${'d'.repeat(64)}`;
    assert.throws(() => verifyDerivativeImage(manifest, evidence), /base layer provenance/i);
  }
});
test('rejects missing, duplicate and extra release platforms', () => {
  for (const platforms of [{}, { 'linux/amd64': {} }, { ...fixture().evidence.platforms, 'linux/386': {} }]) {
    const { manifest, evidence } = fixture();
    evidence.platforms = platforms;
    assert.throws(() => verifyDerivativeImage(manifest, evidence), /release platforms/i);
  }
});
test('fails closed for duplicate or indeterminate release/tag lookups', () => {
  for (const value of [undefined, null, ['other', 'other'], [null], 'not-an-array']) {
    const { manifest, evidence } = fixture();
    evidence.existingReleaseTags = value;
    assert.throws(() => verifyDerivativeImage(manifest, evidence), /release tag lookup/i);
  }
});
test('rejects an occupied image version tag', () => {
  const { manifest, evidence } = fixture();
  evidence.imageTagAbsent = false;
  assert.throws(() => verifyDerivativeImage(manifest, evidence), /image tag.*absent/i);
});
test('requires matching platform inventory and overlay hashes', () => {
  const { manifest, evidence } = fixture();
  evidence.platforms['linux/arm64'].overlayHashes['routes/api.php'] = 'd'.repeat(64);
  assert.throws(() => verifyDerivativeImage(manifest, evidence), /overlay hash/i);
});

test('GitHub lookup permits only the exact triggering lightweight tag and no releases', async () => {
  const { checkReleaseAvailability } = await import('./verify-derivative-image.mjs');
  const responses = [
    new Response('[]', { status: 200 }),
    Response.json({ ref: 'refs/tags/6.7.9-fh.1', object: { type: 'commit', sha: commit } }),
    Response.json({ errors: [{ code: 'MANIFEST_UNKNOWN', message: 'manifest unknown' }] }, { status: 404 }),
  ];
  assert.deepEqual(await checkReleaseAvailability('6.7.9-fh.1', commit, async () => responses.shift()), {
    existingReleaseTags: [], imageTagAbsent: true,
  });
});
test('GitHub/registry lookups reject duplicate, occupied, malformed and indeterminate responses', async () => {
  const { checkReleaseAvailability } = await import('./verify-derivative-image.mjs');
  for (const response of [
    Response.json([{ tag_name: 'x' }, { tag_name: 'x' }]),
    Response.json([{ tag_name: '6.7.9-fh.1' }]),
    Response.json({ message: 'unknown' }),
    new Response('', { status: 403 }), new Response('', { status: 429 }), new Response('', { status: 500 }),
  ]) {
    await assert.rejects(() => checkReleaseAvailability('6.7.9-fh.1', commit, async () => response));
  }
  for (const tagResponse of [new Response('', { status: 404 }), Response.json({ ref: 'refs/tags/6.7.9-fh.1', object: { type: 'commit', sha: 'd'.repeat(40) } })]) {
    const responses = [Response.json([]), tagResponse];
    await assert.rejects(() => checkReleaseAvailability('6.7.9-fh.1', commit, async () => responses.shift()));
  }
  for (const status of [200, 401, 403, 429, 500]) {
    const responses = [Response.json([]), Response.json({ ref: 'refs/tags/6.7.9-fh.1', object: { type: 'commit', sha: commit } }), new Response('', { status })];
    await assert.rejects(() => checkReleaseAvailability('6.7.9-fh.1', commit, async () => responses.shift()));
  }
});

test('rejects a changed source archive against a freshly generated Git archive', async () => {
  const { verifySourceArchive } = await import('./verify-derivative-image.mjs');
  const { execFileSync } = await import('node:child_process');
  const source = execFileSync('git', ['rev-parse', 'HEAD'], { encoding: 'utf8' }).trim();
  const archive = execFileSync('git', ['archive', '--format=tar', '--prefix=firefly-iii-6.7.9-fh.1/', source], { maxBuffer: 256 * 1024 * 1024 });
  assert.match(verifySourceArchive('6.7.9-fh.1', source, archive), /^[0-9a-f]{64}$/);
  const altered = Buffer.from(archive);
  altered[0] ^= 1;
  assert.throws(() => verifySourceArchive('6.7.9-fh.1', source, altered), /exact derivative commit/i);
});
test('checks Git ancestry against the pinned upstream commit', async () => {
  const { verifyUpstreamAncestry, UPSTREAM_COMMIT } = await import('./verify-derivative-image.mjs');
  assert.equal(verifyUpstreamAncestry('HEAD'), true);
  assert.throws(() => verifyUpstreamAncestry(`${UPSTREAM_COMMIT}^`));
});
test('follows release pagination and detects duplicates across pages', async () => {
  const { checkReleaseAvailability } = await import('./verify-derivative-image.mjs');
  const firstPage = Array.from({ length: 100 }, (_, i) => ({ tag_name: `old-${i}` }));
  const pages = [Response.json(firstPage), Response.json([{ tag_name: 'old-0' }])];
  await assert.rejects(() => checkReleaseAvailability('6.7.9-fh.1', commit, async () => pages.shift()), /duplicate release tag lookup/i);
});


test('registry lookup accepts existing OCI and Docker single image manifests', async () => {
  const { checkReleaseAvailability, registryRequester } = await import('./verify-derivative-image.mjs');
  for (const mediaType of ['application/vnd.oci.image.manifest.v1+json', 'application/vnd.docker.distribution.manifest.v2+json']) {
    const request = await registryRequester({ token: 'synthetic-token', actor: 'synthetic-actor', request: async (url, options) => {
      if (url.startsWith('https://ghcr.io/token?')) return Response.json({ token: 'synthetic-registry-token' });
      if (url.includes('/releases?')) return Response.json([]);
      if (url.includes('/git/ref/')) return Response.json({ ref: 'refs/tags/6.7.9-fh.1', object: { type: 'commit', sha: commit } });
      // Reproduce GHCR content negotiation: an excluded single manifest looks missing.
      return options.headers.Accept.split(', ').includes(mediaType)
        ? Response.json({ schemaVersion: 2, mediaType })
        : Response.json({ errors: [{ code: 'MANIFEST_UNKNOWN', message: 'manifest unknown' }] }, { status: 404 });
    } });
    await assert.rejects(() => checkReleaseAvailability('6.7.9-fh.1', commit, request), /image tag must be conclusively absent/i);
  }
});

test('only structured manifest or repository absence proves an image tag is absent', async () => {
  const { checkReleaseAvailability } = await import('./verify-derivative-image.mjs');
  for (const code of ['MANIFEST_UNKNOWN', 'NAME_UNKNOWN']) {
    const responses = [Response.json([]), Response.json({ ref: 'refs/tags/6.7.9-fh.1', object: { type: 'commit', sha: commit } }),
      Response.json({ errors: [{ code, message: 'not found' }] }, { status: 404 })];
    assert.equal((await checkReleaseAvailability('6.7.9-fh.1', commit, async () => responses.shift())).imageTagAbsent, true);
  }
});

test('arbitrary, malformed and other registry 404 responses fail closed', async () => {
  const { checkReleaseAvailability } = await import('./verify-derivative-image.mjs');
  for (const response of [
    new Response('', { status: 404 }),
    new Response('<html>Not found</html>', { status: 404, headers: { 'Content-Type': 'text/html' } }),
    new Response('{', { status: 404, headers: { 'Content-Type': 'application/json' } }),
    Response.json({ message: 'Not Found' }, { status: 404 }),
    Response.json({ errors: [] }, { status: 404 }),
    Response.json({ errors: [{ code: 'DENIED' }] }, { status: 404 }),
    Response.json({ errors: [{ code: 'MANIFEST_UNKNOWN' }, { code: 'UNAUTHORIZED' }] }, { status: 404 }),
    Response.json({ errors: [null] }, { status: 404 }),
    Response.json({ errors: [{ code: 'MANIFEST_UNKNOWN', message: {} }] }, { status: 404 }),
  ]) {
    const responses = [Response.json([]), Response.json({ ref: 'refs/tags/6.7.9-fh.1', object: { type: 'commit', sha: commit } }), response];
    await assert.rejects(() => checkReleaseAvailability('6.7.9-fh.1', commit, async () => responses.shift()));
  }
});

test('changed or added symlink entries cannot evade the exact overlay allowlist', async () => {
  const { compareImageInventories } = await import('./verify-derivative-image.mjs');
  for (const baseEntry of [undefined, { type: 'symlink', target: '../safe.php', mode: 511 }]) {
    const { manifest, evidence } = fixture();
    const baseFiles = new Map(Object.entries(manifest.overlays).map(([path, hash]) => [path, { type: 'file', sha256: hash, mode: 420 }]));
    if (baseEntry) baseFiles.set('public/debug.php', baseEntry);
    const imageFiles = new Map(baseFiles);
    imageFiles.set('public/debug.php', { type: 'symlink', target: '../unsafe.php', mode: 511 });
    Object.assign(evidence, compareImageInventories(baseFiles, imageFiles));
    assert.throws(() => verifyDerivativeImage(manifest, evidence), /non-approved source file: public\/debug.php/i);
  }
});

test('unchanged legitimate symlinks are accepted and regular file mode changes are inventoried', async () => {
  const { compareImageInventories } = await import('./verify-derivative-image.mjs');
  const { manifest, evidence } = fixture();
  const baseFiles = new Map(Object.entries(manifest.overlays).map(([path, hash]) => [path, { type: 'file', sha256: hash, mode: 420 }]));
  baseFiles.set('public/storage', { type: 'symlink', target: '../storage/app/public', mode: 511 });
  baseFiles.set('public/index.php', { type: 'file', sha256: 'f'.repeat(64), mode: 420 });
  const imageFiles = new Map(baseFiles);
  Object.assign(evidence, compareImageInventories(baseFiles, imageFiles));
  assert.equal(verifyDerivativeImage(manifest, evidence), true);
  imageFiles.set('public/index.php', { ...imageFiles.get('public/index.php'), mode: 493 });
  Object.assign(evidence, compareImageInventories(baseFiles, imageFiles));
  assert.throws(() => verifyDerivativeImage(manifest, evidence), /non-approved source file: public\/index.php/i);
});
