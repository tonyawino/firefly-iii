<?php

declare(strict_types=1);

namespace FireflyIII\Models\FinanceHub;

use Illuminate\Database\Eloquent\Model;

final class ProvisioningState extends Model
{
    public $incrementing = false;

    protected $table = 'finance_hub_provisioning_state';

    protected $fillable = ['tenant_id', 'user_id', 'active_pat_id', 'bootstrap_operation_id'];

    protected function casts(): array
    {
        return ['id' => 'integer', 'user_id' => 'integer'];
    }
}
