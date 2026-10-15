# Finance Hub private provisioning

Each Finance Hub tenant has a dedicated Firefly instance and PostgreSQL database.
Only the Finance Hub backend/provisioner can reach its private Firefly network.
Tenants never receive Firefly URLs, accounts, credentials, or access to the Firefly
UI/API. A backend integration owner is created internally; no browser signup,
manual account creation, admin password, or user PAT is part of onboarding.

## Per-instance configuration

Set `FIREFLY_TENANT_ID` to the tenant UUID and `FIREFLY_PROVISIONING_SECRET` to an
independently generated high-entropy, bearer-safe secret. Keep it in the deployment
secret store; never reuse it across tenants or substitute the integration PAT.
Keep a stable, independently generated Laravel `APP_KEY` per instance, unique DB
credentials, private `APP_URL`, `DB_CONNECTION=pgsql`, and a separate upload volume.
Back up the database, `APP_KEY`, Passport signing keys and uploaded files together.
Set `APP_DEBUG=false`; disable external telemetry/update/rate services when unnecessary.
Missing/blank provisioning secret disables the private API (404). Missing/invalid
tenant configuration fails closed. The provisioning secret is not an OAuth token.

The derivative retains the official image's entrypoint, command and healthcheck.
Its additive migration runs automatically during normal startup. Never run manual
production migrations or alter provisioning tables to repair onboarding. First
startup must become healthy before provisioning requests begin.

## Private API

All routes below are relative to `/api/v1/finance-hub/provisioning` and require
`Authorization: Bearer <FIREFLY_PROVISIONING_SECRET>` over the private transport.
Use JSON bodies and `Accept: application/json`. All tenant IDs, idempotency keys
and operation IDs are UUIDs. Fields are exact: unknown/missing fields return 422.
These endpoints are sessionless, do not set browser cookies, emit `no-store`, and
do not permit cross-origin browser access. The private network remains necessary.

| Method | Path | Exact input | Result |
| --- | --- | --- | --- |
| POST | `/bootstrap` | `tenant_id`, `idempotency_key` | Prepared operation and integration `credential` |
| GET | `/operations/{operation_id}` | query `tenant_id` | Redacted operation state |
| POST | `/operations/{operation_id}/ack` | `tenant_id` | Acknowledged bootstrap, redacted |
| POST | `/credentials/rotate` | `tenant_id`, `idempotency_key` | Prepared replacement `credential` |
| POST | `/credentials/rotate/{operation_id}/confirm` | `tenant_id` | Committed replacement, redacted |

Persist the bootstrap idempotency UUID before the first request. Persist the
returned operation ID and credential in Finance Hub's encrypted credential store,
then prove the credential works against that instance's `/api/v1/about`. Only
then acknowledge. Before acknowledgement, retrying the same bootstrap key returns
the exact original credential from encrypted escrow. A different key conflicts.
Acknowledgement erases escrow; status, acknowledgement and subsequent bootstrap
replays contain no credential. Acknowledgement is idempotent. Never log request
headers, response credentials or escrow values.

## Rotation and recovery

Rotation requires acknowledged bootstrap. Persist a new rotation UUID before
preparing. Preparation creates one replacement and keeps the current PAT valid.
Same-key replay returns the same replacement while prepared; a second in-flight
rotation conflicts. Store and test the replacement, switch Finance Hub to use it,
then confirm using the operation ID. Confirmation revokes only the prior PAT,
marks the replacement active and erases replacement escrow atomically. Repeated
confirmation and committed rotation replay are redacted and idempotent. Keep the
provisioning secret available independently so a lost response can be retried.

If a request times out, reuse its original idempotency key; use operation status
when its ID is known. Do not invent a new UUID to recover a lost response. A
prepared credential can be recovered only with its same key while escrow exists.
If a bootstrap PAT is lost after acknowledgement, use the rotation protocol. If
confirmation was lost, retry confirmation; keep the already stored replacement.
If a prepared replacement is lost, replay that rotation before confirmation.

`APP_KEY` encrypts escrow. Losing or replacing it makes outstanding escrow
undecryptable (`credential_unavailable`, HTTP 409); the API must not mint a token
as a substitute. Restore the original key from the matching backup and replay
the original operation. Acknowledged/committed credentials have no escrow to
recover. Preserve Passport signing keys: changing them can invalidate existing
credentials even if provisioning metadata remains intact.

Tenant mismatch, partial pre-existing installation, missing/revoked PAT, deleted
owner, corrupted ledger, or broken rotation ancestry returns a conflict. Stop
retries that change state, inspect redacted metadata and reconcile against a
consistent backup. Do not delete users, tokens, ledger rows, reset the singleton,
change tenant UUID, or run bootstrap against an existing untracked database.
Restore the database and original secrets/keys together, or provision a new empty
instance through an explicitly reviewed data recovery process. Never test recovery
against production with synthetic scripts.
