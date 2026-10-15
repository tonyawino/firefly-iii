<?php

declare(strict_types=1);

namespace Tests\integration\Traits;

use FireflyIII\Enums\UserRoleEnum;
use FireflyIII\Models\Role;
use FireflyIII\Models\UserRole;
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
        Schema::create('user_groups', static function (Blueprint $table): void {
            $table->id();
            $table->timestamps();
            $table->softDeletes();
            $table->string('title')->unique();
        });
        Schema::create('user_roles', static function (Blueprint $table): void {
            $table->id();
            $table->timestamps();
            $table->softDeletes();
            $table->string('title')->unique();
        });
        Schema::create('group_memberships', static function (Blueprint $table): void {
            $table->id();
            $table->timestamps();
            $table->softDeletes();
            $table->unsignedInteger('user_id');
            $table->foreignId('user_group_id')->constrained('user_groups')->cascadeOnDelete();
            $table->foreignId('user_role_id')->constrained('user_roles')->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->unique(['user_id', 'user_group_id', 'user_role_id']);
        });
        (require base_path('database/migrations/2023_10_21_113213_add_currency_pivot_tables.php'))->up();
        UserRole::query()->create(['title' => UserRoleEnum::OWNER->value]);
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
