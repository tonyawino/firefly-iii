import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import { createHash } from 'node:crypto';
import { mkdirSync, readFileSync, writeFileSync } from 'node:fs';
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

const RELEASE_PLATFORMS = ['linux/amd64', 'linux/arm64'];
const REPOSITORY = 'tonyawino/firefly-iii';
const IMAGE = `ghcr.io/${REPOSITORY}`;
const digestPattern = /^sha256:[0-9a-f]{64}$/;
const sha256 = value => createHash('sha256').update(value).digest('hex');
const run = (command, args) => execFileSync(command, args, { encoding: 'utf8', maxBuffer: 32 * 1024 * 1024 }).trim();

export function validateReleaseTag(tag) {
  assert.ok(typeof tag === 'string' && /^6\.7\.9-fh\.[1-9][0-9]*$/.test(tag) && !tag.includes('\n'), 'invalid release tag syntax');
}

function verifyOverlays(overlays, evidence) {
  assert.deepEqual(Object.keys(overlays).sort(), [...OVERLAY_FILES].sort(), 'overlay manifest files differ');
  assert.deepEqual(Object.keys(evidence.overlayHashes).sort(), [...OVERLAY_FILES].sort(), 'overlay image files differ');
  for (const path of OVERLAY_FILES) {
    assert.match(overlays[path], /^[0-9a-f]{64}$/, `invalid overlay hash: ${path}`);
    assert.equal(overlays[path], evidence.overlayHashes[path], `overlay hash mismatch: ${path}`);
  }
  assert.ok(Array.isArray(evidence.changedImageFiles), 'image source inventory is required');
  for (const path of evidence.changedImageFiles) {
    assert.ok(OVERLAY_FILES.includes(path), `non-approved source file: ${path}`);
  }
}

function verifyBaseLayers(evidence) {
  assert.equal(evidence.baseDigest, BASE_DIGEST, 'unapproved observed base digest');
  assert.ok(Array.isArray(evidence.baseLayers) && evidence.baseLayers.length > 0, 'base layer provenance required');
  assert.ok(Array.isArray(evidence.imageLayers), 'image layer provenance required');
  for (const layer of evidence.baseLayers) assert.match(layer, digestPattern, 'invalid base layer provenance');
  assert.deepEqual(evidence.imageLayers.slice(0, evidence.baseLayers.length), evidence.baseLayers, 'base layer provenance mismatch');
}

export function verifyDerivativeImage(manifest, evidence) {
  for (const field of ['release_tag', 'upstream_commit', 'base_digest', 'derivative_commit', 'overlays', 'image_digest', 'source_archive_sha256']) {
    assert.ok(manifest?.[field], `missing manifest field: ${field}`);
  }
  validateReleaseTag(manifest.release_tag);
  assert.equal(manifest.base_digest, BASE_DIGEST, 'unapproved base digest');
  assert.equal(manifest.upstream_commit, UPSTREAM_COMMIT, 'unapproved upstream source commit');
  assert.match(manifest.derivative_commit, /^[0-9a-f]{40}$/, 'invalid source commit');
  assert.equal(manifest.derivative_commit, evidence.sourceCommit, 'derivative source commit mismatch');
  assert.equal(evidence.upstreamIsAncestor, true, 'selected upstream ancestry is required');
  assert.match(manifest.image_digest, digestPattern, 'invalid image digest');
  assert.equal(manifest.image_digest, evidence.imageIndexDigest, 'image index digest mismatch');
  assert.match(manifest.source_archive_sha256, /^[0-9a-f]{64}$/, 'invalid source archive checksum');
  assert.equal(manifest.source_archive_sha256, evidence.sourceArchiveSha256, 'source archive checksum mismatch');
  assert.ok(Array.isArray(evidence.existingReleaseTags)
    && evidence.existingReleaseTags.every(tag => typeof tag === 'string')
    && new Set(evidence.existingReleaseTags).size === evidence.existingReleaseTags.length,
  'release tag lookup must be conclusive and contain no duplicates');
  assert.ok(!evidence.existingReleaseTags.includes(manifest.release_tag), 'release tag already exists');
  assert.equal(evidence.imageTagAbsent, true, 'image tag must be conclusively absent');
  verifyOverlays(manifest.overlays, evidence);
  assert.deepEqual(Object.keys(evidence.platforms).sort(), RELEASE_PLATFORMS, 'release platforms must be exact');
  for (const platform of RELEASE_PLATFORMS) {
    verifyBaseLayers(evidence.platforms[platform]);
    verifyOverlays(manifest.overlays, evidence.platforms[platform]);
  }
  return true;
}

// An injected requester keeps the HTTP status and pagination policy testable offline.
// The source tag is the *trigger*, so it must exist once and point directly to HEAD.
// The corresponding GitHub release and container tag must both be absent.
export async function checkReleaseAvailability(tag, commit, request) {
  validateReleaseTag(tag);
  const existingReleaseTags = [];
  for (let page = 1; ; page++) {
    assert.ok(page <= 1000, 'release lookup pagination is indeterminate');
    const response = await request(`https://api.github.com/repos/${REPOSITORY}/releases?per_page=100&page=${page}`);
    assert.equal(response.status, 200, 'release lookup must be conclusive');
    const releases = await response.json();
    assert.ok(Array.isArray(releases), 'invalid release lookup');
    for (const release of releases) {
      assert.ok(typeof release.tag_name === 'string', 'invalid release tag lookup');
      assert.ok(!existingReleaseTags.includes(release.tag_name), 'duplicate release tag lookup');
      existingReleaseTags.push(release.tag_name);
    }
    if (releases.length < 100) break;
  }
  assert.ok(!existingReleaseTags.includes(tag), 'release tag already exists');
  const tagResponse = await request(`https://api.github.com/repos/${REPOSITORY}/git/ref/tags/${tag}`);
  assert.equal(tagResponse.status, 200, 'source tag lookup must be conclusive');
  const ref = await tagResponse.json();
  assert.equal(ref.ref, `refs/tags/${tag}`, 'source tag mismatch');
  assert.equal(ref.object?.type, 'commit', 'release requires a lightweight source tag');
  assert.equal(ref.object?.sha, commit, 'source tag commit mismatch');
  const imageResponse = await request(`https://ghcr.io/v2/${REPOSITORY}/manifests/${tag}`);
  assert.equal(imageResponse.status, 404, 'image tag must be conclusively absent');
  return { existingReleaseTags, imageTagAbsent: true };
}

async function registryRequester() {
  assert.ok(process.env.GH_TOKEN && process.env.GITHUB_ACTOR, 'workflow token and actor are required');
  const tokenResponse = await fetch(`https://ghcr.io/token?service=ghcr.io&scope=repository:${REPOSITORY}:pull`, {
    headers: { Authorization: `Basic ${Buffer.from(`${process.env.GITHUB_ACTOR}:${process.env.GH_TOKEN}`).toString('base64')}` },
    signal: AbortSignal.timeout(30000), redirect: 'error',
  });
  assert.equal(tokenResponse.status, 200, 'registry authorization is indeterminate');
  const { token } = await tokenResponse.json();
  assert.ok(typeof token === 'string' && token.length > 0, 'missing registry lookup token');
  return url => fetch(url, { headers: {
    Authorization: `Bearer ${url.startsWith('https://api.github.com/') ? process.env.GH_TOKEN : token}`,
    Accept: url.startsWith('https://api.github.com/') ? 'application/vnd.github+json' : 'application/vnd.oci.image.index.v1+json, application/vnd.docker.distribution.manifest.list.v2+json',
  }, signal: AbortSignal.timeout(30000), redirect: 'error' });
}

export function verifyUpstreamAncestry(sourceCommit) {
  run('git', ['merge-base', '--is-ancestor', UPSTREAM_COMMIT, sourceCommit]);
  return true;
}

export function verifySourceArchive(tag, sourceCommit, archive) {
  validateReleaseTag(tag);
  const expectedArchive = execFileSync('git', ['archive', '--format=tar', `--prefix=firefly-iii-${tag}/`, sourceCommit], { maxBuffer: 256 * 1024 * 1024 });
  assert.equal(sha256(archive), sha256(expectedArchive), 'source archive does not match exact derivative commit');
  return sha256(expectedArchive);
}

function verifySource(tag) {
  validateReleaseTag(tag);
  const sourceCommit = run('git', ['rev-parse', 'HEAD']);
  verifyUpstreamAncestry(sourceCommit);
  assert.equal(run('git', ['status', '--porcelain', '--untracked-files=no']), '', 'release requires clean tracked source');
  const from = readFileSync('Dockerfile', 'utf8').split('\n').filter(line => /^FROM\s/i.test(line));
  assert.deepEqual(from, [`FROM fireflyiii/core@${BASE_DIGEST}`], 'Dockerfile must use only the pinned base');
  return sourceCommit;
}

function inspectImage(ref, platform, docker = 'docker') {
  run(docker, ['pull', '--platform', platform, ref]);
  return JSON.parse(run(docker, ['image', 'inspect', ref]))[0];
}

function inventory(ref, platform, docker = 'docker') {
  const result = run(docker, ['run', '--rm', '--platform', platform, '--network', 'none', '--entrypoint', 'sh', ref, '-c',
    'cd /var/www/html && find . -type f -exec sha256sum {} +']);
  return new Map(result.split(/\r?\n/).map(line => [line.slice(68), line.slice(0, 64)]));
}

function imageEvidence(base, derivative, baseFiles, imageFiles) {
  assert.deepEqual(derivative.Config.Entrypoint, base.Config.Entrypoint, 'upstream entrypoint changed');
  assert.deepEqual(derivative.Config.Cmd, base.Config.Cmd, 'upstream command changed');
  assert.deepEqual(derivative.Config.Healthcheck, base.Config.Healthcheck, 'upstream healthcheck changed');
  assert.ok(derivative.Config.Env.includes('FIREFLY_III_PATH=/var/www/html'), 'upstream application path changed');
  return {
    baseDigest: BASE_DIGEST, baseLayers: base.RootFS.Layers, imageLayers: derivative.RootFS.Layers,
    overlayHashes: Object.fromEntries(OVERLAY_FILES.map(path => [path, imageFiles.get(path)])),
    changedImageFiles: [...new Set([...baseFiles.keys(), ...imageFiles.keys()])].filter(path => baseFiles.get(path) !== imageFiles.get(path)),
  };
}

export async function verifyReleaseImage(tag, archivePath, expectedDigest, outputDirectory) {
  const sourceCommit = verifySource(tag);
  assert.match(expectedDigest, digestPattern, 'invalid expected image index digest');
  const availability = await checkReleaseAvailability(tag, sourceCommit, await registryRequester());
  const ref = `${IMAGE}@${expectedDigest}`;
  const observed = run('docker', ['buildx', 'imagetools', 'inspect', ref]);
  const imageIndexDigest = observed.match(/^Digest:\s+(sha256:[0-9a-f]{64})\s*$/m)?.[1];
  assert.equal(imageIndexDigest, expectedDigest, 'registry image index digest mismatch');
  const index = JSON.parse(run('docker', ['buildx', 'imagetools', 'inspect', '--raw', ref]));
  const platforms = {};
  for (const descriptor of index.manifests) {
    if (descriptor.platform?.os === 'unknown' && descriptor.platform?.architecture === 'unknown') {
      assert.equal(descriptor.annotations?.['vnd.docker.reference.type'], 'attestation-manifest', 'unrecognized auxiliary manifest');
      continue;
    }
    const platform = `${descriptor.platform?.os}/${descriptor.platform?.architecture}`;
    assert.ok(RELEASE_PLATFORMS.includes(platform) && !platforms[platform], 'unexpected or duplicate release platforms');
    const baseRef = `fireflyiii/core@${BASE_DIGEST}`;
    const childRef = `${IMAGE}@${descriptor.digest}`;
    const base = inspectImage(baseRef, platform);
    const derivative = inspectImage(childRef, platform);
    assert.equal(derivative.Config.Labels['org.opencontainers.image.revision'], sourceCommit, 'source commit label mismatch');
    platforms[platform] = imageEvidence(base, derivative, inventory(baseRef, platform), inventory(childRef, platform));
  }
  const overlays = Object.fromEntries(OVERLAY_FILES.map(path => [path, sha256(readFileSync(path))]));
  // Compare the provided archive with a freshly generated archive of HEAD, not just a caller claim.
  const archive = readFileSync(archivePath);
  const sourceArchiveSha256 = verifySourceArchive(tag, sourceCommit, archive);
  const manifest = { release_tag: tag, upstream_commit: UPSTREAM_COMMIT, base_digest: BASE_DIGEST,
    derivative_commit: sourceCommit, overlays, image_digest: expectedDigest, source_archive_sha256: sha256(archive) };
  const evidence = { sourceCommit, upstreamIsAncestor: true, sourceArchiveSha256, imageIndexDigest,
    ...availability, ...platforms['linux/amd64'], platforms };
  verifyDerivativeImage(manifest, evidence);
  mkdirSync(outputDirectory, { recursive: true });
  writeFileSync(`${outputDirectory}/release-manifest.json`, `${JSON.stringify(manifest, null, 2)}\n`);
  writeFileSync(`${outputDirectory}/release-evidence.json`, `${JSON.stringify(evidence, null, 2)}\n`);
  return manifest;
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
  const overlays = Object.fromEntries(OVERLAY_FILES.map(path => [path, createHash('sha256').update(readFileSync(path)).digest('hex')]));
  const evidence = imageEvidence(base, derivative, baseFiles, imageFiles);
  verifyBaseLayers(evidence);
  verifyOverlays(overlays, evidence);
  return { image: image, image_id: derivative.Id, derivative_commit: sourceCommit, overlay_files: OVERLAY_FILES.length };
}

if (process.argv[1] === fileURLToPath(import.meta.url)) {
  try {
    if (process.argv[2] === '--local') {
      console.log(JSON.stringify(verifyLocalImage(process.argv[3], process.env.DOCKER_BIN || 'docker')));
    } else if (process.argv[2] === '--preflight') {
      const commit = verifySource(process.argv[3]);
      await checkReleaseAvailability(process.argv[3], commit, await registryRequester());
      console.log('Release source and absence checks verified');
    } else if (process.argv[2] === '--release') {
      assert.equal(process.argv.length, 7, 'usage: --release TAG SOURCE.tar IMAGE_INDEX_DIGEST OUTPUT_DIRECTORY');
      await verifyReleaseImage(...process.argv.slice(3));
      console.log('Release image and source verified');
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
