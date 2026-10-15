<?php

declare(strict_types=1);

namespace FireflyIII\Services\FinanceHub;

use DomainException;
use FireflyIII\Models\FinanceHub\ProvisioningState;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use RuntimeException;
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
        $configuredTenant = config('finance-hub.tenant_id');
        if (!is_string($configuredTenant)
            || 1 !== preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\z/i', $configuredTenant)) {
            throw new RuntimeException('provisioning_unavailable');
        }
        if ($tenantId !== $configuredTenant) {
            throw new DomainException('tenant_mismatch');
        }

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

    public function encryptCredential(string $credential): string
    {
        return Crypt::encryptString($credential);
    }

    public function decryptCredential(string $ciphertext): string
    {
        try {
            return Crypt::decryptString($ciphertext);
        } catch (Throwable) {
            // Never attach the original exception or disclose credential material.
            throw new RuntimeException('credential_unavailable');
        }
    }
}
