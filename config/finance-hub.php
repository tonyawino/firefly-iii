<?php

declare(strict_types=1);

$tenantId = env('FIREFLY_TENANT_ID');
$secret   = env('FIREFLY_PROVISIONING_SECRET');

return [
    'tenant_id'           => is_string($tenantId) && 1 === preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\z/i', $tenantId)
        ? $tenantId
        : null,
    'provisioning_secret' => is_string($secret) && '' !== trim($secret) ? $secret : null,
];
