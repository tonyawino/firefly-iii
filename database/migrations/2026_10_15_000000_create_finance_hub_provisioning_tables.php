<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // An inline CHECK works on SQLite as well as PostgreSQL and MySQL;
        // SQLite cannot add a CHECK constraint with ALTER TABLE afterwards.
        $table = DB::connection()->getQueryGrammar()->wrapTable('finance_hub_provisioning_state');
        DB::statement('CREATE TABLE '.$table.' (id INTEGER NOT NULL PRIMARY KEY CHECK (id = 1))');
        Schema::table('finance_hub_provisioning_state', static function (Blueprint $table): void {
            $table->uuid('tenant_id')->nullable();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('active_pat_id', 100)->nullable();
            $table->uuid('bootstrap_operation_id')->nullable();
            $table->timestamps();
        });

        Schema::create('finance_hub_provisioning_operations', static function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->enum('kind', ['bootstrap', 'rotate']);
            $table->uuid('idempotency_key');
            $table->enum('state', ['prepared', 'acknowledged', 'committed', 'failed']);
            $table->string('pat_id', 100)->nullable();
            $table->string('prior_pat_id', 100)->nullable();
            $table->text('encrypted_credential')->nullable();
            $table->timestamps();
            $table->unique(['kind', 'idempotency_key'], 'finance_hub_operation_kind_key_unique');
        });

        // Tenant identity is bound during bootstrap under the singleton lock.
        DB::table('finance_hub_provisioning_state')->insert(['id' => 1]);
    }

    public function down(): void
    {
        Schema::dropIfExists('finance_hub_provisioning_operations');
        Schema::dropIfExists('finance_hub_provisioning_state');
    }
};
