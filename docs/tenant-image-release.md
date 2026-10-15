# Finance Hub Firefly image release

## Pinned provenance

The selected upstream release is Firefly III **6.7.9**, commit
`ba26768619842a4926655f315716f1b7788933d7` in
<https://github.com/firefly-iii/firefly-iii>. The derivative source repository is
<https://github.com/tonyawino/firefly-iii>. Its only runtime base is the official
`fireflyiii/core@sha256:a2b53539338d1cd04d341b229c3806cb178cf64c72459060e3ed34bec4167bd8`
image index. A base/upstream upgrade requires a separately reviewed allowlist
change and new tests; supplying labels with new values cannot authorize it.

The Dockerfile copies exactly the nine files in `OVERLAY_FILES` in
`scripts/verify-derivative-image.mjs`. It inherits official dependencies,
entrypoint, command and healthcheck. Its migration is automatically applied by
normal image startup; never invoke manual production migrations.

Release tags have the exact syntax `6.7.9-fh.N`, where N is a positive integer
without leading zeroes. Only `ghcr.io/tonyawino/firefly-iii:6.7.9-fh.N` is tagged;
there is no `latest`, upstream alias or staging tag. Deploy by the verified image
index digest from `release-manifest.json`. Registry administrators must restrict
package writes to the release workflow: GHCR itself does not provide a compare
and swap operation for tag creation. The workflow serializes publication and
checks absence twice; out-of-band writers can defeat that policy.

## Local validation

From a clean checkout with Node 22, PHP 8.5 and locked Composer dependencies:

```sh
node --test scripts/verify-derivative-image.test.mjs
php vendor/bin/phpunit -c phpunit.xml --no-coverage tests/integration/Services/FinanceHub/ProvisioningServiceTest.php tests/integration/Api/FinanceHub/ProvisioningControllerTest.php
node --input-type=module -e 'import {OVERLAY_FILES} from "./scripts/verify-derivative-image.mjs"; console.log(OVERLAY_FILES.join("\n"))' | while IFS= read -r file; do php -l "$file"; done
docker build --build-arg DERIVATIVE_COMMIT="$(git rev-parse HEAD)" -t fireflyiii-tenant-bootstrap:local .
node scripts/verify-derivative-image.mjs --local fireflyiii-tenant-bootstrap:local
bash scripts/test-finance-hub-bootstrap.sh
actionlint .github/workflows/finance-hub-image.yml
git diff --check
```

The Compose runner generates synthetic credentials and tenant UUIDs internally,
validates `docker compose config`, uses only the local Docker socket and dedicated
local image, publishes no ports, creates two isolated internal networks/databases,
and proves startup migration, bootstrap/replay/ack, cross-tenant denial and token
rotation. Its trap removes volumes/networks/containers and verifies cleanup. It
never loads deployment `.env` values. It needs the local derivative plus
`postgres:17-alpine` available to Docker. On PRs it is opt-in through the
`finance-hub-integration` label, or a manual workflow run with
`synthetic_integration=true`. The manual event cannot publish a release.

## Authored publication procedure

The workflow is authored for future use; implementation of this change does not
run it or publish an image. Review/merge the source through the normal PR flow,
then a release operator selects an unused N. Before creating a source tag, verify
that the Git tag, GitHub release and GHCR image version do not exist. An error,
permission failure, ambiguous response or duplicate release record is a failure,
not evidence of absence. Create a lightweight source tag at the reviewed commit;
never move, reuse or force-push it. The tag event runs the focused checks again.
No full upstream test suite is run by this workflow.

The tag event's source tag necessarily exists: the verifier requires the exact
`refs/tags/6.7.9-fh.N` lightweight ref to point to the derivative commit. It fails
if the corresponding GitHub release or image tag already exists. GitHub lookup
paginates every release, including drafts, and rejects duplicate tag records.
The registry lookup accepts OCI/Docker indexes and single-image manifests, so an
existing single-platform tag cannot appear absent through content negotiation.
Only JSON 404 errors with `MANIFEST_UNKNOWN` (missing tag) or `NAME_UNKNOWN`
(missing repository) establish absence. Registry authorization, rate limits,
malformed/HTML responses, other error codes and non-404 manifest responses fail closed. Authentication uses only the job's scoped `GITHUB_TOKEN`,
with `contents:write` and `packages:write`; there are no PATs or account-specific
repository secrets.

After source ancestry and the exact pinned Dockerfile are verified, BuildKit
builds linux/amd64 and linux/arm64 with maximum OCI provenance and SBOM
attestations. It uploads content-addressed blobs/index **by digest only** for
verification. No image version tag or GitHub release exists at this stage. Failed
verification can leave untagged registry content for registry retention cleanup;
it cannot create a version tag or release.

The verifier independently observes the registry index digest, inspects each
platform from that index, compares actual base layer prefixes against the pinned
official base, compares entrypoint/command/healthcheck, and inventories all files
under `/var/www/html`, including regular-file modes and symlink paths/targets.
Unchanged upstream symlinks are retained; added, removed or retargeted links outside
the exact overlay allowlist fail verification. Only that allowlist may differ. Overlay hashes
must equal source hashes on both platforms. This verifies base ancestry beyond
self-reported image labels. A manifest with a claimed index digest alone is
insufficient. Unknown platforms and duplicate platform descriptors fail.

The source attachment is an uncompressed `git archive` tar of the exact derivative
commit with the versioned directory prefix. The verifier regenerates that archive
from Git and compares its SHA-256. `release-manifest.json` links the upstream
commit, derivative commit, base index digest, overlay hashes, exact source archive
checksum and observed derivative image index digest. `release-evidence.json`
records independently collected layer/inventory/lookup evidence. Both accompany
`SHA256SUMS` and the source archive in the GitHub release. Retain the AGPL license
and make this corresponding source available to recipients.

Only after verification and another absence check does the workflow assign the
single version tag to that same index and verify its registry digest. It then
creates the GitHub release without asset clobbering. A failure after tag creation
requires investigation; rerunning fails closed on the occupied image tag. Never
delete/reuse a version to make a retry pass; choose a new reviewed version.

To reproduce the online pre-publication proof in the release job context, with
its short-lived job token and authenticated local Docker client:

```sh
node scripts/verify-derivative-image.mjs --preflight "$RELEASE_TAG"
node scripts/verify-derivative-image.mjs --release "$RELEASE_TAG" "dist/firefly-iii-$RELEASE_TAG.tar" "$IMAGE_DIGEST" dist
```

For offline review of already collected release evidence:

```sh
(cd dist && sha256sum --check SHA256SUMS)
node scripts/verify-derivative-image.mjs dist/release-manifest.json dist/release-evidence.json
docker buildx imagetools inspect ghcr.io/tonyawino/firefly-iii:6.7.9-fh.1
```

Compare the live index digest with the manifest. Offline evidence validates its
internal constraints; fresh registry/base inspection is required to independently
establish what the registry serves. The online pre-publication command refuses an
already published version and must not be used to overwrite it.
