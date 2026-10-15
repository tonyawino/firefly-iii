<?php

declare(strict_types=1);

namespace FireflyIII\Models\FinanceHub;

use Illuminate\Database\Eloquent\Model;

final class ProvisioningOperation extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $table = 'finance_hub_provisioning_operations';

    protected $fillable = ['id', 'kind', 'idempotency_key', 'state', 'pat_id', 'prior_pat_id', 'encrypted_credential'];

    protected $hidden = ['encrypted_credential'];
}
