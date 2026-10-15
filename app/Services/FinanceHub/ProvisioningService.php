<?php

declare(strict_types=1);

namespace FireflyIII\Services\FinanceHub;

use DomainException;
use FireflyIII\Console\Commands\Correction\CreatesGroupMemberships;
use FireflyIII\Enums\UserRoleEnum;
use FireflyIII\Models\FinanceHub\ProvisioningOperation;
use FireflyIII\Models\FinanceHub\ProvisioningState;
use FireflyIII\Repositories\User\UserRepositoryInterface;
use FireflyIII\User;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Laravel\Passport\ClientRepository;
use Laravel\Passport\Events\AccessTokenCreated;
use Laravel\Passport\Passport;
use Laravel\Passport\PersonalAccessTokenResult;
use Laravel\Passport\Token;
use Psr\Log\NullLogger;
use RuntimeException;
use SensitiveParameter;
use Throwable;

final class ProvisioningService
{
    /**
     * The callback may bind the tenant and mutate users/tokens only under this lock.
     *
     * @template T
     *
     * @param callable(ProvisioningState): T $callback
     *
     * @return T
     */
    public function withLockedState(string $tenantId, callable $callback): mixed
    {
        $this->assertTenant($tenantId);

        return DB::transaction(static function () use ($tenantId, $callback): mixed {
            $state = ProvisioningState::query()->whereKey(1)->lockForUpdate()->first();
            if (null === $state) {
                throw new RuntimeException('provisioning_state_missing');
            }
            // PostgreSQL normalizes UUID columns, even when config uses uppercase.
            if (null !== $state->tenant_id && strtolower($tenantId) !== strtolower($state->tenant_id)) {
                throw new DomainException('tenant_mismatch');
            }

            return $callback($state);
        });
    }

    /** Validate instance identity before acquiring any database connection. */
    public function assertTenant(string $tenantId): void
    {
        $configuredTenant = config('finance-hub.tenant_id');
        if (!is_string($configuredTenant)
            || 1 !== preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\z/i', $configuredTenant)) {
            throw new RuntimeException('provisioning_unavailable');
        }
        if ($tenantId !== $configuredTenant) {
            throw new DomainException('tenant_mismatch');
        }
    }

    /** @return array{operation_id: string, state: string, credential?: string} */
    public function bootstrap(string $tenantId, string $idempotencyKey): array
    {
        if (1 !== preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\z/i', $idempotencyKey)) {
            throw new DomainException('invalid_request');
        }
        $idempotencyKey = strtolower($idempotencyKey);

        return $this->withLockedState($tenantId, function (ProvisioningState $state) use ($tenantId, $idempotencyKey): array {
            if (null !== $state->bootstrap_operation_id) {
                $operation = $this->bootstrapOperation($state, $state->bootstrap_operation_id);
                if (strtolower($operation->idempotency_key) !== $idempotencyKey) {
                    throw new DomainException('bootstrap_conflict');
                }
                if ('acknowledged' === $operation->state) {
                    try {
                        $this->activeCredentialChain($state);
                    } catch (DomainException) {
                        throw new DomainException('bootstrap_conflict');
                    }
                } else {
                    $owner = $this->integrationOwner($state, 'bootstrap_conflict');
                    $this->activeToken($operation->pat_id, $owner, 'bootstrap_conflict');
                    if ($state->active_pat_id !== $operation->pat_id) {
                        throw new DomainException('bootstrap_conflict');
                    }
                }

                return $this->bootstrapResult($operation);
            }

            // Partial/lost state never authorizes recovery by creating more credentials.
            if (null !== $state->tenant_id || null !== $state->user_id || null !== $state->active_pat_id
                || ProvisioningOperation::query()->exists() || User::query()->exists()) {
                throw new DomainException('bootstrap_conflict');
            }

            $owner = $this->createIntegrationOwner();
            $owner->forceFill([
                'password' => Hash::make(bin2hex(random_bytes(32))),
                'remember_token' => bin2hex(random_bytes(32)),
            ])->save();
            if ($owner->blocked || !$owner->hasRole('owner')) {
                throw new RuntimeException('provisioning_failed');
            }
            // Match Firefly's administration setup without registration events
            // (those events also send signup and administrator notifications).
            CreatesGroupMemberships::createGroupMembership($owner);

            // Use the same Passport grant-client facility as Firefly's OAuth UI.
            $provider = config('auth.guards.api.provider');
            $client = Passport::client()->newQuery()->whereNull('owner_id')->where('revoked', false)
                ->where(static fn ($query) => $query->whereNull('provider')->orWhere('provider', $provider))
                ->get()->first(static fn ($client): bool => $client->hasGrantType('personal_access'));
            if (null === $client) {
                app(ClientRepository::class)->createPersonalAccessGrantClient('Firefly III Personal Access Grant Client', null);
            }
            $token = $this->createIntegrationToken($owner);
            $operation = ProvisioningOperation::query()->create([
                'id' => (string) Str::uuid(),
                'kind' => 'bootstrap',
                'idempotency_key' => $idempotencyKey,
                'state' => 'prepared',
                'pat_id' => $token->accessTokenId,
                'encrypted_credential' => $this->encryptCredential($token->accessToken),
            ]);
            $state->fill([
                'tenant_id' => $tenantId,
                'user_id' => $owner->id,
                'active_pat_id' => $operation->pat_id,
                'bootstrap_operation_id' => $operation->id,
            ])->save();

            return ['operation_id' => $operation->id, 'state' => $operation->state, 'credential' => $token->accessToken];
        });
    }

    /** @return array{operation_id: string, state: string} */
    public function status(string $tenantId, string $operationId): array
    {
        return $this->withLockedState($tenantId, function (ProvisioningState $state) use ($operationId): array {
            $operation = strtolower((string) $state->bootstrap_operation_id) === strtolower($operationId)
                ? $this->bootstrapOperation($state, $operationId)
                : $this->rotationOperation($state, $operationId);

            return ['operation_id' => $operation->id, 'state' => $operation->state];
        });
    }

    /** @return array{operation_id: string, state: string} */
    public function acknowledge(string $tenantId, string $operationId): array
    {
        return $this->withLockedState($tenantId, function (ProvisioningState $state) use ($operationId): array {
            $operation = $this->bootstrapOperation($state, $operationId);
            if ('prepared' === $operation->state) {
                $this->bootstrapResult($operation);
                $operation->fill(['state' => 'acknowledged', 'encrypted_credential' => null])->save();
            } elseif ('acknowledged' !== $operation->state || null !== $operation->encrypted_credential) {
                throw new DomainException('operation_conflict');
            }

            return ['operation_id' => $operation->id, 'state' => $operation->state];
        });
    }

    /** @return array{operation_id: string, state: string, credential?: string} */
    public function rotate(string $tenantId, string $idempotencyKey): array
    {
        if (1 !== preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\z/i', $idempotencyKey)) {
            throw new DomainException('invalid_request');
        }
        $idempotencyKey = strtolower($idempotencyKey);

        return $this->withLockedState($tenantId, function (ProvisioningState $state) use ($idempotencyKey): array {
            $this->activeCredentialChain($state);
            $operation = ProvisioningOperation::query()->where('kind', 'rotate')->where('idempotency_key', $idempotencyKey)->first();
            if (null !== $operation) {
                return $this->rotationResult($this->rotationOperation($state, $operation->id));
            }
            // Any unresolved rotation prevents issuing another replacement.
            if (ProvisioningOperation::query()->where('kind', 'rotate')->where('state', '!=', 'committed')->exists()) {
                throw new DomainException('rotation_conflict');
            }
            $token = $this->createIntegrationToken($this->integrationOwner($state));
            $operation = ProvisioningOperation::query()->create([
                'id' => (string) Str::uuid(),
                'kind' => 'rotate',
                'idempotency_key' => $idempotencyKey,
                'state' => 'prepared',
                'pat_id' => $token->accessTokenId,
                'prior_pat_id' => $state->active_pat_id,
                'encrypted_credential' => $this->encryptCredential($token->accessToken),
            ]);

            return ['operation_id' => $operation->id, 'state' => $operation->state, 'credential' => $token->accessToken];
        });
    }

    /** @return array{operation_id: string, state: string} */
    public function confirmRotation(string $tenantId, string $operationId): array
    {
        return $this->withLockedState($tenantId, function (ProvisioningState $state) use ($operationId): array {
            $operation = $this->rotationOperation($state, $operationId);
            if ('prepared' === $operation->state) {
                // Verify replay is recoverable before irreversibly selecting it.
                $this->rotationResult($operation);
                $old = $this->activeToken($operation->prior_pat_id, $this->integrationOwner($state));
                $old->revoke();
                $state->fill(['active_pat_id' => $operation->pat_id])->save();
                $operation->fill(['state' => 'committed', 'encrypted_credential' => null])->save();
            }

            return ['operation_id' => $operation->id, 'state' => $operation->state];
        });
    }

    private function integrationOwner(ProvisioningState $state, string $error = 'operation_conflict'): User
    {
        $owner = User::query()->find($state->user_id);
        if (null === $owner || $owner->blocked || !$owner->hasRole('owner')
            || null === $owner->userGroup || 1 !== User::query()->count()
            || !$owner->groupMemberships()->where('user_group_id', $owner->user_group_id)
                ->whereHas('userRole', static fn ($query) => $query->where('title', UserRoleEnum::OWNER->value))->exists()) {
            throw new DomainException($error);
        }

        return $owner;
    }

    private function activeToken(?string $id, User $owner, string $error = 'operation_conflict'): Token
    {
        $token = Passport::token()->newQuery()->whereKey($id)->lockForUpdate()->first();
        if (null === $token || $token->revoked || $token->expires_at?->isPast()
            || (int) $token->user_id !== $owner->id || null === $token->client
            || $token->client->revoked || !$token->client->hasGrantType('personal_access')) {
            throw new DomainException($error);
        }

        return $token;
    }

    /**
     * Prove that the current credential descends from this singleton's bootstrap.
     * Historical committed operations remain retryable after later rotations.
     *
     * @return list<string> Committed rotation identifiers in the active chain.
     */
    private function activeCredentialChain(ProvisioningState $state): array
    {
        $bootstrap = $this->bootstrapOperation($state, (string) $state->bootstrap_operation_id);
        if (!in_array($bootstrap->state, ['prepared', 'acknowledged'], true)
            || ('prepared' === $bootstrap->state) !== (null !== $bootstrap->encrypted_credential)) {
            throw new DomainException('operation_conflict');
        }
        $owner = $this->integrationOwner($state);
        $this->activeToken($state->active_pat_id, $owner);
        $cursor = $state->active_pat_id;
        $chain = [];
        while ($cursor !== $bootstrap->pat_id) {
            $matches = ProvisioningOperation::query()->where('kind', 'rotate')->where('state', 'committed')->where('pat_id', $cursor)->get();
            if (1 !== $matches->count()) {
                throw new DomainException('operation_conflict');
            }
            $operation = $matches->first();
            if (in_array($operation->id, $chain, true) || null !== $operation->encrypted_credential || null === $operation->prior_pat_id) {
                throw new DomainException('operation_conflict');
            }
            $prior = Passport::token()->newQuery()->whereKey($operation->prior_pat_id)->lockForUpdate()->first();
            if (null === $prior || !$prior->revoked || (int) $prior->user_id !== $owner->id) {
                throw new DomainException('operation_conflict');
            }
            $chain[] = $operation->id;
            $cursor = $operation->prior_pat_id;
        }

        return $chain;
    }

    private function rotationOperation(ProvisioningState $state, string $operationId): ProvisioningOperation
    {
        $chain = $this->activeCredentialChain($state);
        $operation = ProvisioningOperation::query()->find(strtolower($operationId));
        if (null === $operation || 'rotate' !== $operation->kind || null === $operation->pat_id || null === $operation->prior_pat_id
            || $operation->pat_id === $operation->prior_pat_id) {
            throw new DomainException('operation_conflict');
        }
        if ('committed' === $operation->state && in_array($operation->id, $chain, true) && null === $operation->encrypted_credential) {
            return $operation;
        }
        if ('prepared' !== $operation->state || $operation->prior_pat_id !== $state->active_pat_id
            || 1 !== ProvisioningOperation::query()->where('kind', 'rotate')->where('state', '!=', 'committed')->count()) {
            throw new DomainException('operation_conflict');
        }
        $this->activeToken($operation->pat_id, $this->integrationOwner($state));

        return $operation;
    }

    /** @return array{operation_id: string, state: string, credential?: string} */
    private function rotationResult(ProvisioningOperation $operation): array
    {
        $result = ['operation_id' => $operation->id, 'state' => $operation->state];
        if ('committed' === $operation->state && null === $operation->encrypted_credential) {
            return $result;
        }
        if ('prepared' !== $operation->state || null === $operation->encrypted_credential) {
            throw new DomainException('operation_conflict');
        }
        $result['credential'] = $this->decryptCredential($operation->encrypted_credential);

        return $result;
    }

    private function createIntegrationOwner(): User
    {
        // attachRole() catches database exceptions and logs their raw SQL itself.
        // Silence only this repository call; the controller returns a fixed error
        // and the missing owner role makes the surrounding transaction roll back.
        $logger = Log::getFacadeRoot();
        Log::swap(new NullLogger());
        try {
            return app(UserRepositoryInterface::class)->store([
                'email' => 'finance-hub-'.Str::uuid().'@invalid',
                'role' => 'owner',
            ]);
        } finally {
            Log::swap($logger);
        }
    }

    private function createIntegrationToken(User $owner): PersonalAccessTokenResult
    {
        // Firefly listens to this Passport event to send email/push notifications.
        // Suppress that event only for this non-interactive issuance and always
        // restore listeners, including when Passport fails inside the transaction.
        $events = app('events');
        $listeners = $events->getRawListeners()[AccessTokenCreated::class] ?? [];
        $events->forget(AccessTokenCreated::class);
        try {
            return $owner->createToken('Finance Hub integration');
        } finally {
            foreach ($listeners as $listener) {
                $events->listen(AccessTokenCreated::class, $listener);
            }
        }
    }

    private function bootstrapOperation(ProvisioningState $state, string $operationId): ProvisioningOperation
    {
        if (null === $state->tenant_id || null === $state->user_id || null === $state->active_pat_id
            || null === $state->bootstrap_operation_id
            || strtolower($state->bootstrap_operation_id) !== strtolower($operationId)) {
            throw new DomainException('operation_conflict');
        }
        $operation = ProvisioningOperation::query()->find($state->bootstrap_operation_id);
        if (null === $operation || 'bootstrap' !== $operation->kind || null === $operation->pat_id) {
            throw new DomainException('operation_conflict');
        }

        return $operation;
    }

    /** @return array{operation_id: string, state: string, credential?: string} */
    private function bootstrapResult(ProvisioningOperation $operation): array
    {
        $result = ['operation_id' => $operation->id, 'state' => $operation->state];
        if ('acknowledged' === $operation->state && null === $operation->encrypted_credential) {
            return $result;
        }
        if ('prepared' !== $operation->state || null === $operation->encrypted_credential) {
            throw new DomainException('operation_conflict');
        }
        $result['credential'] = $this->decryptCredential($operation->encrypted_credential);

        return $result;
    }

    public function encryptCredential(#[SensitiveParameter] string $credential): string
    {
        return Crypt::encryptString($credential);
    }

    public function decryptCredential(#[SensitiveParameter] string $ciphertext): string
    {
        try {
            return Crypt::decryptString($ciphertext);
        } catch (Throwable) {
            // Never attach the original exception or disclose credential material.
            throw new RuntimeException('credential_unavailable');
        }
    }
}
