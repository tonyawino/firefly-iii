<?php

declare(strict_types=1);

namespace Tests\integration\Traits;

use FireflyIII\Models\Role;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

trait FinanceHubBootstrapDatabase
{
    private function createBootstrapSchema(): void
    {
        foreach ([
            'database/migrations/2016_06_16_000000_create_support_tables.php',
            'database/migrations/2016_06_16_000001_create_users_table.php',
            'vendor/laravel/passport/database/migrations/2016_06_01_000004_create_oauth_clients_table.php',
            'vendor/laravel/passport/database/migrations/2016_06_01_000002_create_oauth_access_tokens_table.php',
        ] as $file) {
            (require base_path($file))->up();
        }
        Schema::table('users', static function (Blueprint $table): void {
            $table->unsignedInteger('user_group_id')->nullable();
        });
        Schema::create('role_user', static function (Blueprint $table): void {
            $table->unsignedInteger('user_id');
            $table->unsignedInteger('role_id');
            $table->primary(['user_id', 'role_id']);
        });
        Schema::create('preferences', static function (Blueprint $table): void {
            $table->increments('id');
            $table->unsignedInteger('user_id');
            $table->unsignedInteger('user_group_id')->nullable();
            $table->string('name');
            $table->text('data');
            $table->timestamps();
        });
        Role::query()->create(['name' => 'owner', 'display_name' => 'Owner', 'description' => 'Synthetic owner role']);

        // Real Passport signing and verification, with synthetic keys kept in memory.
        $key = openssl_pkey_new(['private_key_bits' => 2048]);
        openssl_pkey_export($key, $privateKey);
        config()->set('passport.private_key', $privateKey);
        config()->set('passport.public_key', openssl_pkey_get_details($key)['key']);
        config()->set('cache.default', 'array');
        config()->set('session.driver', 'array');
    }
}
