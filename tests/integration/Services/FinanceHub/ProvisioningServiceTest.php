<?php

declare(strict_types=1);

namespace Tests\integration\Services\FinanceHub;

use DomainException;
use FireflyIII\Enums\UserRoleEnum;
use FireflyIII\Exceptions\FireflyException;
use FireflyIII\Models\FinanceHub\ProvisioningOperation;
use FireflyIII\Models\FinanceHub\ProvisioningState;
use FireflyIII\Models\GroupMembership;
use FireflyIII\Models\UserGroup;
use FireflyIII\Models\UserRole;
use FireflyIII\Services\FinanceHub\ProvisioningService;
use FireflyIII\User;
use Illuminate\Database\QueryException;
use Illuminate\Database\Query\Grammars\SQLiteGrammar;
use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Laravel\Passport\ClientRepository;
use Laravel\Passport\Events\AccessTokenCreated;
use Laravel\Passport\Passport;
use Override;
use PDO;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\integration\CreatesApplication;
use Tests\integration\Traits\FinanceHubBootstrapDatabase;

/**
 * @internal
 */
#[CoversNothing]
final class ProvisioningServiceTest extends TestCase
{
    use CreatesApplication;
    use FinanceHubBootstrapDatabase;

    private const string TENANT_ID = '550e8400-e29b-41d4-a716-446655440000';
    private const string OTHER_TENANT_ID = '550e8400-e29b-41d4-a716-446655440001';

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        // Exercise only the derivative schema, without upstream seeds or migrations.
        config()->set('database.default', 'sqlite');
        config()->set('database.connections.sqlite.database', ':memory:');
        DB::purge('sqlite');
        config()->set('finance-hub.tenant_id', self::TENANT_ID);
        $this->setEncryptionKey();
        $migration = require base_path('database/migrations/2026_10_15_000000_create_finance_hub_provisioning_tables.php');
        $migration->up();
    }

    public function testMigrationSeedsOneUnboundStateRegardlessOfConfiguredTenant(): void
    {
        self::assertSame(1, ProvisioningState::query()->count());
        $state = ProvisioningState::query()->findOrFail(1);
        self::assertNull($state->tenant_id);
        self::assertNull($state->user_id);
        self::assertNull($state->active_pat_id);
        self::assertNull($state->bootstrap_operation_id);
    }

    #[DataProvider('migrationDrivers')]
    public function testSingletonUsesTransactionalEngineOnMysqlFamilyOnly(string $driver, bool $requiresEngine): void
    {
        $originalConnection = DB::getDefaultConnection();
        $originalSchema = Schema::getFacadeRoot();
        config()->set('database.connections.provisioning_sql_test', [
            'driver' => $driver,
            'database' => ':memory:',
            'prefix' => '',
            'engine' => 'InnoDB',
        ]);
        DB::setDefaultConnection('provisioning_sql_test');
        if ('mariadb' === $driver) {
            // MariaDB's UUID grammar reads version metadata even in pretend mode.
            DB::connection()->setPdo(new class extends PDO {
                public function __construct() {}

                public function getAttribute(int $attribute): mixed
                {
                    return '11.4.0-MariaDB';
                }
            });
        }
        Schema::swap(DB::connection()->getSchemaBuilder());
        try {
            $migration = require base_path('database/migrations/2026_10_15_000000_create_finance_hub_provisioning_tables.php');
            $queries = DB::pretend(static fn () => $migration->up());
        } finally {
            DB::setDefaultConnection($originalConnection);
            Schema::swap($originalSchema);
            DB::purge('provisioning_sql_test');
        }
        $create = $queries[0]['query'];
        self::assertStringStartsWith('CREATE TABLE ', $create);
        self::assertStringContainsString('finance_hub_provisioning_state', $create);
        if ($requiresEngine) {
            self::assertStringEndsWith(' ENGINE=InnoDB', $create);
        } else {
            self::assertStringNotContainsString('ENGINE=', $create);
        }
    }

    public static function migrationDrivers(): iterable
    {
        yield 'MySQL' => ['mysql', true];
        yield 'MariaDB' => ['mariadb', true];
        yield 'PostgreSQL' => ['pgsql', false];
        yield 'SQLite' => ['sqlite', false];
    }

    public function testDuplicateSingletonIsRejectedByTheDatabase(): void
    {
        $this->expectException(QueryException::class);
        DB::table('finance_hub_provisioning_state')->insert(['id' => 1]);
    }

    public function testAnotherStateIdIsRejectedEvenAfterDeletingTheSingleton(): void
    {
        ProvisioningState::query()->delete();
        $this->expectException(QueryException::class);
        DB::table('finance_hub_provisioning_state')->insert(['id' => 2]);
    }

    public function testDuplicateOperationKindAndKeyAreRejected(): void
    {
        $key = (string) Str::uuid();
        $this->operation($key);
        $this->expectException(QueryException::class);
        $this->operation($key);
    }

    public function testSameKeyCanIdentifyDifferentOperationKinds(): void
    {
        $key = (string) Str::uuid();
        $this->operation($key);
        $rotation = $this->operation($key, 'rotate');
        self::assertSame('rotate', $rotation->fresh()->kind);
        self::assertSame(2, ProvisioningOperation::query()->count());
        self::assertIsString($rotation->id);
    }

    public function testLockedStateRunsCallbackInsideTransactionAndCommitsItsResult(): void
    {
        // SQLite omits FOR UPDATE. Mark explicit row-lock requests in its grammar
        // so this test detects lockForUpdate() without pretending SQLite locks rows.
        $connection = DB::connection();
        $grammar = $connection->getQueryGrammar();
        $connection->setQueryGrammar(new class($connection) extends SQLiteGrammar {
            protected function compileLock($query, $value)
            {
                return true === $value ? '/* requested FOR UPDATE */' : '';
            }
        });
        $connection->enableQueryLog();
        try {
            $result = $this->service()->withLockedState(self::TENANT_ID, static function (ProvisioningState $state): string {
                self::assertGreaterThan(0, DB::transactionLevel());
                self::assertSame(1, $state->id);
                $state->tenant_id = self::TENANT_ID;
                $state->save();

                return 'committed';
            });
            $queries = $connection->getQueryLog();
        } finally {
            $connection->setQueryGrammar($grammar);
            $connection->disableQueryLog();
        }
        self::assertSame('committed', $result);
        self::assertStringContainsString('requested FOR UPDATE', $queries[0]['query']);
        self::assertSame([1], $queries[0]['bindings']);
        self::assertSame(self::TENANT_ID, ProvisioningState::query()->findOrFail(1)->tenant_id);
        self::assertSame(0, DB::transactionLevel());
    }

    public function testCallbackFailureRollsBackStateAndOperationsTogether(): void
    {
        try {
            $this->service()->withLockedState(self::TENANT_ID, function (ProvisioningState $state): never {
                $state->tenant_id = self::TENANT_ID;
                $state->save();
                $this->operation((string) Str::uuid());

                throw new RuntimeException('synthetic_failure');
            });
            self::fail('The transaction must fail.');
        } catch (RuntimeException $exception) {
            self::assertSame('synthetic_failure', $exception->getMessage());
        }
        self::assertNull(ProvisioningState::query()->findOrFail(1)->tenant_id);
        self::assertSame(0, ProvisioningOperation::query()->count());
        self::assertSame(0, DB::transactionLevel());
    }

    public function testRequestTenantMismatchIsRejectedBeforeDatabaseOrCallbackWork(): void
    {
        DB::connection()->enableQueryLog();
        try {
            $this->service()->withLockedState(self::OTHER_TENANT_ID, static function (): never {
                self::fail('Tenant mismatch must not invoke user/token work.');
            });
            self::fail('Tenant mismatch must fail.');
        } catch (DomainException $exception) {
            self::assertSame('tenant_mismatch', $exception->getMessage());
        }
        self::assertSame([], DB::connection()->getQueryLog());
        DB::connection()->disableQueryLog();
    }

    #[DataProvider('invalidConfiguredTenants')]
    public function testInvalidConfiguredTenantIsRejectedBeforeCallbackWork(mixed $tenantId): void
    {
        config()->set('finance-hub.tenant_id', $tenantId);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('provisioning_unavailable');
        $this->service()->withLockedState(self::TENANT_ID, static function (): never {
            self::fail('Missing tenant configuration must not invoke user/token work.');
        });
    }

    public static function invalidConfiguredTenants(): iterable
    {
        yield 'missing' => [null];
        yield 'empty' => [''];
        yield 'malformed' => ['not-a-uuid'];
        yield 'number' => [123];
        yield 'array' => [[]];
    }

    public function testConfiguredUuidCaseMustMatchExactlyBeforeCallbackWork(): void
    {
        config()->set('finance-hub.tenant_id', strtoupper(self::TENANT_ID));
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('tenant_mismatch');
        $this->service()->withLockedState(self::TENANT_ID, static function (): never {
            self::fail('Requests must exactly match the configured UUID.');
        });
    }

    public function testStoredUuidMayUseTheDatabaseCanonicalCase(): void
    {
        config()->set('finance-hub.tenant_id', strtoupper(self::TENANT_ID));
        ProvisioningState::query()->whereKey(1)->update(['tenant_id' => self::TENANT_ID]);
        $result = $this->service()->withLockedState(strtoupper(self::TENANT_ID), static fn (): string => 'same_tenant');
        self::assertSame('same_tenant', $result);
    }

    public function testPersistedTenantMismatchIsRejectedBeforeCallbackWork(): void
    {
        ProvisioningState::query()->whereKey(1)->update(['tenant_id' => self::OTHER_TENANT_ID]);
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('tenant_mismatch');
        $this->service()->withLockedState(self::TENANT_ID, static function (): never {
            self::fail('A bound tenant cannot be replaced.');
        });
    }

    public function testMissingSingletonFailsClosedWithoutRecreatingIt(): void
    {
        ProvisioningState::query()->delete();
        try {
            $this->service()->withLockedState(self::TENANT_ID, static function (): never {
                self::fail('Missing state must not invoke user/token work.');
            });
            self::fail('Missing state must fail closed.');
        } catch (RuntimeException $exception) {
            self::assertSame('provisioning_state_missing', $exception->getMessage());
        }
        self::assertSame(0, ProvisioningState::query()->count());
    }

    public function testEncryptedCredentialRoundTripsAndIsHiddenFromSerialization(): void
    {
        $credential = 'synthetic-personal-access-token';
        $encrypted = $this->service()->encryptCredential($credential);
        self::assertNotSame($credential, $encrypted);
        self::assertStringNotContainsString($credential, $encrypted);
        $operation = $this->operation((string) Str::uuid());
        $operation->encrypted_credential = $encrypted;
        $operation->save();
        self::assertSame($credential, $this->service()->decryptCredential($operation->fresh()->encrypted_credential));
        self::assertArrayNotHasKey('encrypted_credential', $operation->fresh()->toArray());
        self::assertSame($encrypted, DB::table('finance_hub_provisioning_operations')->value('encrypted_credential'));
    }

    public function testTamperedCiphertextFailsWithSanitizedTerminalError(): void
    {
        $encrypted = $this->service()->encryptCredential('synthetic-personal-access-token');
        $payload = json_decode(base64_decode($encrypted, true), true, flags: JSON_THROW_ON_ERROR);
        $payload['value'][0] = 'A' === $payload['value'][0] ? 'B' : 'A';
        $tampered = base64_encode(json_encode($payload, JSON_THROW_ON_ERROR));
        $this->assertCredentialUnavailable($tampered);
    }

    public function testWrongApplicationKeyFailsWithSanitizedTerminalError(): void
    {
        $encrypted = $this->service()->encryptCredential('synthetic-personal-access-token');
        $this->setEncryptionKey();
        $this->assertCredentialUnavailable($encrypted);
    }

    public function testMissingApplicationKeyFailsWithSanitizedTerminalError(): void
    {
        $encrypted = $this->service()->encryptCredential('synthetic-personal-access-token');
        config()->set('app.key', null);
        app()->forgetInstance('encrypter');
        Crypt::clearResolvedInstance('encrypter');
        $this->assertCredentialUnavailable($encrypted);
    }

    public function testMalformedCiphertextFailsWithSanitizedTerminalError(): void
    {
        $this->assertCredentialUnavailable('synthetic-personal-access-token');
    }

    public function testMigrationCanBeReversed(): void
    {
        $migration = require base_path('database/migrations/2026_10_15_000000_create_finance_hub_provisioning_tables.php');
        $migration->down();
        self::assertFalse(Schema::hasTable('finance_hub_provisioning_operations'));
        self::assertFalse(Schema::hasTable('finance_hub_provisioning_state'));
    }

    public function testBootstrapCreatesOneOwnerAndOneEncryptedReplayablePassportToken(): void
    {
        $this->createBootstrapSchema();
        Notification::fake();
        Mail::fake();
        $result = $this->service()->bootstrap(self::TENANT_ID, (string) Str::uuid());
        self::assertSame(['operation_id', 'state', 'credential'], array_keys($result));
        self::assertSame('prepared', $result['state']);
        self::assertSame(1, User::query()->count());
        $owner = User::query()->sole();
        self::assertFalse($owner->blocked);
        self::assertTrue($owner->hasRole('owner'));
        self::assertSame(1, UserGroup::query()->count());
        self::assertSame(1, GroupMembership::query()->count());
        self::assertGreaterThan(0, $owner->getAdministrationId());
        self::assertSame($owner->getAdministrationId(), UserGroup::query()->sole()->id);
        $membership = GroupMembership::query()->sole();
        self::assertSame($owner->id, $membership->user_id);
        self::assertSame($owner->getAdministrationId(), $membership->user_group_id);
        self::assertSame(UserRoleEnum::OWNER->value, $membership->userRole->title);
        self::assertStringEndsWith('@invalid', $owner->email);
        self::assertSame('bcrypt', Hash::info($owner->password)['algoName']);
        self::assertGreaterThanOrEqual(40, strlen($owner->remember_token));
        self::assertStringNotContainsString($owner->password, json_encode($result));
        $operation = ProvisioningOperation::query()->sole();
        $state = ProvisioningState::query()->findOrFail(1);
        self::assertSame(self::TENANT_ID, $state->tenant_id);
        self::assertSame($owner->id, $state->user_id);
        self::assertSame($operation->id, $state->bootstrap_operation_id);
        self::assertSame($operation->pat_id, $state->active_pat_id);
        self::assertSame(1, Passport::token()->newQuery()->count());
        self::assertSame($owner->id, (int) Passport::token()->newQuery()->sole()->user_id);
        self::assertNotSame($result['credential'], $operation->encrypted_credential);
        self::assertSame($result['credential'], $this->service()->decryptCredential($operation->encrypted_credential));
        Notification::assertNothingSent();
        Mail::assertNothingSent();
    }

    public function testLostBootstrapResponseReplaysExactOperationAndCredentialAcrossServiceInstances(): void
    {
        $this->createBootstrapSchema();
        $key = (string) Str::uuid();
        $first = $this->service()->bootstrap(self::TENANT_ID, $key);
        $second = app(ProvisioningService::class)->bootstrap(self::TENANT_ID, $key);
        self::assertSame($first, $second);
        self::assertSame(1, User::query()->count());
        self::assertSame(1, Passport::token()->newQuery()->count());
        self::assertSame(1, ProvisioningOperation::query()->count());
        self::assertSame(1, Passport::client()->newQuery()->count());
        self::assertSame(1, UserGroup::query()->count());
        self::assertSame(1, GroupMembership::query()->count());
    }

    public function testAnotherBootstrapKeyConflictsWithoutCreatingUserOrToken(): void
    {
        $this->createBootstrapSchema();
        $this->service()->bootstrap(self::TENANT_ID, (string) Str::uuid());
        $this->assertBootstrapConflict((string) Str::uuid(), 'bootstrap_conflict');
    }

    public function testUntrackedExistingUserIsNeverAdopted(): void
    {
        $this->createBootstrapSchema();
        User::query()->create(['email' => 'existing@invalid', 'password' => 'synthetic']);
        $this->assertBootstrapConflict((string) Str::uuid(), 'bootstrap_conflict', 0);
        self::assertSame(0, ProvisioningOperation::query()->count());
        self::assertNull(ProvisioningState::query()->findOrFail(1)->tenant_id);
        self::assertSame(0, Passport::client()->newQuery()->count());
    }

    public function testTamperedBootstrapReplayNeverCreatesAnotherCredential(): void
    {
        $this->createBootstrapSchema();
        $key = (string) Str::uuid();
        $this->service()->bootstrap(self::TENANT_ID, $key);
        ProvisioningOperation::query()->update(['encrypted_credential' => 'corrupted']);
        try {
            $this->service()->bootstrap(self::TENANT_ID, $key);
            self::fail('Corrupted replay must fail closed.');
        } catch (RuntimeException $exception) {
            self::assertSame('credential_unavailable', $exception->getMessage());
        }
        self::assertSame(1, User::query()->count());
        self::assertSame(1, Passport::token()->newQuery()->count());
    }

    public function testAcknowledgementClearsCiphertextAndRepeatedAckDoesNotWrite(): void
    {
        $this->createBootstrapSchema();
        $key = (string) Str::uuid();
        $result = $this->service()->bootstrap(self::TENANT_ID, $key);
        $expected = ['operation_id' => $result['operation_id'], 'state' => 'acknowledged'];
        self::assertSame($expected, $this->service()->acknowledge(self::TENANT_ID, $result['operation_id']));
        self::assertNull(ProvisioningOperation::query()->sole()->encrypted_credential);
        DB::connection()->enableQueryLog();
        self::assertSame($expected, $this->service()->acknowledge(self::TENANT_ID, $result['operation_id']));
        $queries = DB::connection()->getQueryLog();
        DB::connection()->disableQueryLog();
        self::assertSame([], array_values(array_filter($queries, static fn (array $query): bool => str_starts_with(strtolower($query['query']), 'update'))));
        self::assertSame($expected, $this->service()->bootstrap(self::TENANT_ID, $key));
        self::assertSame(1, Passport::token()->newQuery()->count());
    }

    public function testOperationWriteFailureRollsBackOwnerClientTokenAndState(): void
    {
        $this->createBootstrapSchema();
        ProvisioningOperation::creating(static function (): never {
            throw new RuntimeException('synthetic_write_failure');
        });
        try {
            $this->service()->bootstrap(self::TENANT_ID, (string) Str::uuid());
            self::fail('The transaction must roll back.');
        } catch (RuntimeException $exception) {
            self::assertSame('synthetic_write_failure', $exception->getMessage());
        } finally {
            ProvisioningOperation::flushEventListeners();
        }
        self::assertSame(0, User::query()->count());
        self::assertSame(0, UserGroup::query()->count());
        self::assertSame(0, GroupMembership::query()->count());
        self::assertSame(0, Passport::token()->newQuery()->count());
        self::assertSame(0, Passport::client()->newQuery()->count());
        self::assertSame(0, ProvisioningOperation::query()->count());
        self::assertNull(ProvisioningState::query()->findOrFail(1)->tenant_id);
    }

    public function testExistingPersonalAccessClientIsReused(): void
    {
        $this->createBootstrapSchema();
        app(ClientRepository::class)->createPersonalAccessGrantClient('Synthetic existing personal client', null);
        $this->service()->bootstrap(self::TENANT_ID, (string) Str::uuid());
        self::assertSame(1, Passport::client()->newQuery()->count());
    }

    public function testForeignOperationCannotBeReadOrAcknowledged(): void
    {
        $operation = $this->operation((string) Str::uuid());
        foreach (['status', 'acknowledge'] as $method) {
            try {
                $this->service()->{$method}(self::TENANT_ID, $operation->id);
                self::fail('Untracked operation must conflict.');
            } catch (DomainException $exception) {
                self::assertSame('operation_conflict', $exception->getMessage());
            }
        }
        self::assertSame('prepared', $operation->fresh()->state);
    }

    public function testTokenCreationNotificationsAreSuppressedOnlyInsideBootstrap(): void
    {
        $this->createBootstrapSchema();
        Notification::fake();
        $calls = 0;
        $listener = static function () use (&$calls): void { ++$calls; };
        app('events')->listen(AccessTokenCreated::class, $listener);
        $listeners = app('events')->getRawListeners()[AccessTokenCreated::class];
        $this->service()->bootstrap(self::TENANT_ID, (string) Str::uuid());
        self::assertSame(0, $calls);
        self::assertSame($listeners, app('events')->getRawListeners()[AccessTokenCreated::class]);
        app('events')->dispatch(new AccessTokenCreated('synthetic', '0', 'synthetic'));
        self::assertSame(1, $calls);
        Notification::assertNothingSent();
    }

    public function testPassportFailureRestoresNotificationListenersAndRollsBack(): void
    {
        $this->createBootstrapSchema();
        $listeners = app('events')->getRawListeners()[AccessTokenCreated::class] ?? [];
        $tokenModel = Passport::tokenModel();
        $tokenModel::creating(static function (): never {
            throw new RuntimeException('synthetic_passport_failure');
        });
        try {
            $this->service()->bootstrap(self::TENANT_ID, (string) Str::uuid());
            self::fail('Passport failure must roll back.');
        } catch (RuntimeException $exception) {
            self::assertSame('synthetic_passport_failure', $exception->getMessage());
        } finally {
            $tokenModel::flushEventListeners();
        }
        self::assertSame($listeners, app('events')->getRawListeners()[AccessTokenCreated::class] ?? []);
        self::assertSame(0, User::query()->count());
        self::assertSame(0, UserGroup::query()->count());
        self::assertSame(0, GroupMembership::query()->count());
        self::assertSame(0, Passport::token()->newQuery()->count());
        self::assertSame(0, Passport::client()->newQuery()->count());
    }

    #[DataProvider('inconsistentBootstrapRecords')]
    public function testInconsistentBootstrapRecordsFailWithoutCreatingAnything(string $mutation): void
    {
        $this->createBootstrapSchema();
        $key = (string) Str::uuid();
        $this->service()->bootstrap(self::TENANT_ID, $key);
        match ($mutation) {
            'missing user' => User::query()->delete(),
            'missing group' => UserGroup::query()->delete(),
            'missing membership' => GroupMembership::query()->delete(),
            'blocked user' => User::query()->update(['blocked' => true]),
            'missing role' => DB::table('role_user')->delete(),
            'missing token' => Passport::token()->newQuery()->delete(),
            'revoked token' => Passport::token()->newQuery()->update(['revoked' => true]),
            'revoked client' => Passport::client()->newQuery()->update(['revoked' => true]),
            'foreign token' => Passport::token()->newQuery()->update(['user_id' => 999]),
            'untracked user' => User::query()->create(['email' => 'untracked@invalid', 'password' => 'synthetic']),
        };
        $users = User::query()->count();
        $tokens = Passport::token()->newQuery()->count();
        try {
            $this->service()->bootstrap(self::TENANT_ID, $key);
            self::fail('Inconsistent recorded bootstrap must conflict.');
        } catch (DomainException $exception) {
            self::assertSame('bootstrap_conflict', $exception->getMessage());
        }
        self::assertSame($users, User::query()->count());
        self::assertSame($tokens, Passport::token()->newQuery()->count());
        self::assertSame(1, ProvisioningOperation::query()->count());
    }

    public static function inconsistentBootstrapRecords(): iterable
    {
        foreach (['missing user', 'missing group', 'missing membership', 'blocked user', 'missing role', 'missing token', 'revoked token', 'revoked client', 'foreign token', 'untracked user'] as $mutation) {
            yield $mutation => [$mutation];
        }
    }

    public function testAcknowledgementCannotUsePartiallyLostOwnerState(): void
    {
        $this->createBootstrapSchema();
        $result = $this->service()->bootstrap(self::TENANT_ID, (string) Str::uuid());
        ProvisioningState::query()->whereKey(1)->update(['user_id' => null]);
        try {
            $this->service()->acknowledge(self::TENANT_ID, $result['operation_id']);
            self::fail('Missing owner state must not allow acknowledgement.');
        } catch (DomainException $exception) {
            self::assertSame('operation_conflict', $exception->getMessage());
        }
        self::assertSame('prepared', ProvisioningOperation::query()->sole()->state);
        self::assertNotNull(ProvisioningOperation::query()->sole()->encrypted_credential);
    }

    public function testMissingGroupOwnerRoleRollsBackTheEntireBootstrap(): void
    {
        $this->createBootstrapSchema();
        UserRole::query()->delete();
        try {
            $this->service()->bootstrap(self::TENANT_ID, (string) Str::uuid());
            self::fail('A missing administration owner role must fail closed.');
        } catch (FireflyException) {
            // This is a deployment/schema failure, never a reason to create a PAT.
        }
        self::assertSame(0, User::query()->count());
        self::assertSame(0, UserGroup::query()->count());
        self::assertSame(0, GroupMembership::query()->count());
        self::assertSame(0, Passport::token()->newQuery()->count());
        self::assertSame(0, ProvisioningOperation::query()->count());
        self::assertNull(ProvisioningState::query()->findOrFail(1)->tenant_id);
    }

    public function testRotationWaitsForBootstrapAcknowledgementAndPreservesReplay(): void
    {
        $this->createBootstrapSchema();
        $bootstrapKey = (string) Str::uuid();
        $bootstrap = $this->service()->bootstrap(self::TENANT_ID, $bootstrapKey);
        $rotationKey = (string) Str::uuid();
        try {
            $this->service()->rotate(self::TENANT_ID, $rotationKey);
            self::fail('A pending bootstrap must remain replayable until acknowledgement.');
        } catch (DomainException $exception) {
            self::assertSame('rotation_conflict', $exception->getMessage());
        }
        self::assertSame($bootstrap, $this->service()->bootstrap(self::TENANT_ID, $bootstrapKey));
        self::assertSame(1, Passport::token()->newQuery()->count());
        self::assertSame(1, ProvisioningOperation::query()->count());
        $this->service()->acknowledge(self::TENANT_ID, $bootstrap['operation_id']);
        self::assertSame('prepared', $this->service()->rotate(self::TENANT_ID, $rotationKey)['state']);
    }

    public function testRotationReplaysThenConfirmsOnlyThePriorTokenAndAllowsAnotherRotation(): void
    {
        $this->createBootstrapSchema();
        $bootstrapKey = (string) Str::uuid();
        $bootstrap = $this->service()->bootstrap(self::TENANT_ID, $bootstrapKey);
        $this->service()->acknowledge(self::TENANT_ID, $bootstrap['operation_id']);
        $oldId = ProvisioningState::query()->findOrFail(1)->active_pat_id;
        $extra = Event::fakeFor(static fn () => User::query()->sole()->createToken('Synthetic unrelated PAT'));
        $key = (string) Str::uuid();
        $prepared = $this->service()->rotate(self::TENANT_ID, $key);
        self::assertSame('prepared', $prepared['state']);
        self::assertNotSame($bootstrap['credential'], $prepared['credential']);
        $operation = ProvisioningOperation::query()->findOrFail($prepared['operation_id']);
        self::assertSame($oldId, $operation->prior_pat_id);
        self::assertNotSame($oldId, $operation->pat_id);
        self::assertSame($oldId, ProvisioningState::query()->findOrFail(1)->active_pat_id);
        self::assertFalse(Passport::token()->newQuery()->findOrFail($oldId)->revoked);
        self::assertSame($prepared['credential'], $this->service()->decryptCredential($operation->encrypted_credential));
        self::assertNotSame($prepared['credential'], $operation->encrypted_credential);
        self::assertSame($prepared, app(ProvisioningService::class)->rotate(self::TENANT_ID, strtoupper($key)));
        self::assertSame(3, Passport::token()->newQuery()->count());
        $expected = ['operation_id' => $operation->id, 'state' => 'committed'];
        self::assertSame($expected, $this->service()->confirmRotation(self::TENANT_ID, $operation->id));
        self::assertTrue(Passport::token()->newQuery()->findOrFail($oldId)->revoked);
        self::assertFalse(Passport::token()->newQuery()->findOrFail($operation->pat_id)->revoked);
        self::assertFalse(Passport::token()->newQuery()->findOrFail($extra->accessTokenId)->revoked);
        self::assertSame($operation->pat_id, ProvisioningState::query()->findOrFail(1)->active_pat_id);
        self::assertNull($operation->fresh()->encrypted_credential);
        self::assertSame($expected, $this->service()->confirmRotation(self::TENANT_ID, $operation->id));
        self::assertSame($expected, $this->service()->rotate(self::TENANT_ID, $key));
        $next = $this->service()->rotate(self::TENANT_ID, (string) Str::uuid());
        $this->service()->confirmRotation(self::TENANT_ID, $next['operation_id']);
        self::assertSame($expected, $this->service()->confirmRotation(self::TENANT_ID, $operation->id));
        self::assertSame($expected, $this->service()->status(self::TENANT_ID, $operation->id));
        self::assertSame(['operation_id' => $bootstrap['operation_id'], 'state' => 'acknowledged'], $this->service()->bootstrap(self::TENANT_ID, $bootstrapKey));
    }

    public function testCommittedRotationsRemainRetryableAfterPassportPurgesRevokedTokens(): void
    {
        $this->createBootstrapSchema();
        foreach ([
            '2016_06_01_000001_create_oauth_auth_codes_table.php',
            '2016_06_01_000003_create_oauth_refresh_tokens_table.php',
            '2024_06_01_000001_create_oauth_device_codes_table.php',
        ] as $migration) {
            (require base_path('vendor/laravel/passport/database/migrations/'.$migration))->up();
        }
        $bootstrapKey = (string) Str::uuid();
        $bootstrap = $this->service()->bootstrap(self::TENANT_ID, $bootstrapKey);
        $acknowledged = $this->service()->acknowledge(self::TENANT_ID, $bootstrap['operation_id']);
        $rotationKey = (string) Str::uuid();
        $rotation = $this->service()->rotate(self::TENANT_ID, $rotationKey);
        $committed = $this->service()->confirmRotation(self::TENANT_ID, $rotation['operation_id']);
        $oldId = ProvisioningOperation::query()->findOrFail($rotation['operation_id'])->prior_pat_id;
        self::assertSame(0, Artisan::call('passport:purge', ['--revoked' => true]));
        self::assertNull(Passport::token()->newQuery()->find($oldId));
        self::assertSame(1, Passport::token()->newQuery()->count());
        self::assertSame($acknowledged, $this->service()->bootstrap(self::TENANT_ID, $bootstrapKey));
        self::assertSame($committed, $this->service()->status(self::TENANT_ID, $rotation['operation_id']));
        self::assertSame($committed, $this->service()->confirmRotation(self::TENANT_ID, $rotation['operation_id']));
        self::assertSame($committed, $this->service()->rotate(self::TENANT_ID, $rotationKey));
        $next = $this->service()->rotate(self::TENANT_ID, (string) Str::uuid());
        self::assertSame('committed', $this->service()->confirmRotation(self::TENANT_ID, $next['operation_id'])['state']);
        $activeId = ProvisioningState::query()->findOrFail(1)->active_pat_id;
        self::assertSame(0, Artisan::call('passport:purge', ['--revoked' => true]));
        self::assertSame($activeId, Passport::token()->newQuery()->sole()->id);
        self::assertSame($acknowledged, $this->service()->bootstrap(self::TENANT_ID, $bootstrapKey));
        self::assertSame($committed, $this->service()->confirmRotation(self::TENANT_ID, $rotation['operation_id']));
    }

    #[DataProvider('inconsistentHistoricalTokens')]
    public function testExistingHistoricalPatMustStillBeRevokedAndBelongToOwner(array $mutation): void
    {
        $this->createBootstrapSchema();
        $bootstrap = $this->service()->bootstrap(self::TENANT_ID, (string) Str::uuid());
        $this->service()->acknowledge(self::TENANT_ID, $bootstrap['operation_id']);
        $rotation = $this->service()->rotate(self::TENANT_ID, (string) Str::uuid());
        $this->service()->confirmRotation(self::TENANT_ID, $rotation['operation_id']);
        $operation = ProvisioningOperation::query()->findOrFail($rotation['operation_id']);
        Passport::token()->newQuery()->whereKey($operation->prior_pat_id)->update($mutation);
        foreach (['rotate', 'status', 'confirmRotation'] as $method) {
            try {
                $this->service()->{$method}(self::TENANT_ID, 'rotate' === $method ? (string) Str::uuid() : $operation->id);
                self::fail('An inconsistent historical PAT must fail closed.');
            } catch (DomainException $exception) {
                self::assertSame('operation_conflict', $exception->getMessage());
            }
        }
        self::assertSame($operation->pat_id, ProvisioningState::query()->findOrFail(1)->active_pat_id);
        self::assertSame(2, Passport::token()->newQuery()->count());
    }

    public static function inconsistentHistoricalTokens(): iterable
    {
        yield 'unrevoked historical token' => [['revoked' => false]];
        yield 'foreign historical token' => [['user_id' => 999]];
    }

    public function testDistinctRotationKeyConflictsWhilePrepared(): void
    {
        $this->createBootstrapSchema();
        $bootstrap = $this->service()->bootstrap(self::TENANT_ID, (string) Str::uuid());
        $this->service()->acknowledge(self::TENANT_ID, $bootstrap['operation_id']);
        $this->service()->rotate(self::TENANT_ID, (string) Str::uuid());
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('rotation_conflict');
        try {
            $this->service()->rotate(self::TENANT_ID, (string) Str::uuid());
        } finally {
            self::assertSame(2, Passport::token()->newQuery()->count());
            self::assertSame(2, ProvisioningOperation::query()->count());
        }
    }

    #[DataProvider('rotationFailures')]
    public function testUncertainRotationCannotReplayOrCommit(string $mutation, string $error): void
    {
        $this->createBootstrapSchema();
        $bootstrap = $this->service()->bootstrap(self::TENANT_ID, (string) Str::uuid());
        $this->service()->acknowledge(self::TENANT_ID, $bootstrap['operation_id']);
        $oldId = ProvisioningState::query()->findOrFail(1)->active_pat_id;
        $key = (string) Str::uuid();
        $result = $this->service()->rotate(self::TENANT_ID, $key);
        $operation = ProvisioningOperation::query()->findOrFail($result['operation_id']);
        match ($mutation) {
            'ciphertext' => $operation->update(['encrypted_credential' => 'corrupted']),
            'key' => $this->setEncryptionKey(),
            'revoked old' => Passport::token()->newQuery()->findOrFail($oldId)->revoke(),
            'missing old' => Passport::token()->newQuery()->whereKey($oldId)->delete(),
            'foreign old' => Passport::token()->newQuery()->whereKey($oldId)->update(['user_id' => 999]),
            'revoked new' => Passport::token()->newQuery()->whereKey($operation->pat_id)->update(['revoked' => true]),
            'foreign new' => Passport::token()->newQuery()->whereKey($operation->pat_id)->update(['user_id' => 999]),
            'foreign operation' => $operation->update(['prior_pat_id' => 'foreign-token']),
            'lost root' => ProvisioningOperation::query()->whereKey($bootstrap['operation_id'])->delete(),
            'lost membership' => GroupMembership::query()->delete(),
            'lost group role' => UserRole::query()->delete(),
        };
        foreach (['rotate', 'confirmRotation'] as $method) {
            try {
                $this->service()->{$method}(self::TENANT_ID, 'rotate' === $method ? $key : $operation->id);
                self::fail('Uncertain rotation must fail closed.');
            } catch (DomainException|RuntimeException $exception) {
                self::assertSame($error, $exception->getMessage());
            }
        }
        self::assertSame('prepared', $operation->fresh()->state);
        self::assertNotNull($operation->fresh()->encrypted_credential);
        self::assertSame($oldId, ProvisioningState::query()->findOrFail(1)->active_pat_id);
        if (!in_array($mutation, ['revoked old', 'missing old'], true)) {
            self::assertFalse(Passport::token()->newQuery()->findOrFail($oldId)->revoked);
        }
    }

    public static function rotationFailures(): iterable
    {
        foreach (['ciphertext', 'key', 'revoked old', 'missing old', 'foreign old', 'revoked new', 'foreign new', 'foreign operation', 'lost root', 'lost membership', 'lost group role'] as $mutation) {
            yield $mutation => [$mutation, in_array($mutation, ['ciphertext', 'key'], true) ? 'credential_unavailable' : 'operation_conflict'];
        }
    }

    public function testRotationPrepareAndConfirmFailuresRollBackAllChanges(): void
    {
        $this->createBootstrapSchema();
        $bootstrap = $this->service()->bootstrap(self::TENANT_ID, (string) Str::uuid());
        $this->service()->acknowledge(self::TENANT_ID, $bootstrap['operation_id']);
        $oldId = ProvisioningState::query()->findOrFail(1)->active_pat_id;
        ProvisioningOperation::creating(static function (): never { throw new RuntimeException('synthetic_write_failure'); });
        try {
            $this->service()->rotate(self::TENANT_ID, (string) Str::uuid());
            self::fail('Prepare must roll back.');
        } catch (RuntimeException $exception) {
            self::assertSame('synthetic_write_failure', $exception->getMessage());
        } finally {
            ProvisioningOperation::flushEventListeners();
        }
        self::assertSame(1, Passport::token()->newQuery()->count());
        $result = $this->service()->rotate(self::TENANT_ID, (string) Str::uuid());
        ProvisioningOperation::updating(static function (): never { throw new RuntimeException('synthetic_write_failure'); });
        try {
            $this->service()->confirmRotation(self::TENANT_ID, $result['operation_id']);
            self::fail('Confirm must roll back revocation and active state.');
        } catch (RuntimeException $exception) {
            self::assertSame('synthetic_write_failure', $exception->getMessage());
        } finally {
            ProvisioningOperation::flushEventListeners();
        }
        self::assertFalse(Passport::token()->newQuery()->findOrFail($oldId)->revoked);
        self::assertSame($oldId, ProvisioningState::query()->findOrFail(1)->active_pat_id);
        self::assertSame('prepared', ProvisioningOperation::query()->findOrFail($result['operation_id'])->state);
    }

    public function testRotationSuppressesNotificationsAndRestoresListenersEvenOnPassportFailure(): void
    {
        $this->createBootstrapSchema();
        $bootstrap = $this->service()->bootstrap(self::TENANT_ID, (string) Str::uuid());
        $this->service()->acknowledge(self::TENANT_ID, $bootstrap['operation_id']);
        Notification::fake();
        Mail::fake();
        $calls = 0;
        app('events')->listen(AccessTokenCreated::class, static function () use (&$calls): void { ++$calls; });
        $listeners = app('events')->getRawListeners()[AccessTokenCreated::class];
        $result = $this->service()->rotate(self::TENANT_ID, (string) Str::uuid());
        self::assertSame(0, $calls);
        self::assertSame($listeners, app('events')->getRawListeners()[AccessTokenCreated::class]);
        $this->service()->confirmRotation(self::TENANT_ID, $result['operation_id']);
        $model = Passport::tokenModel();
        $model::creating(static function (): never { throw new RuntimeException('synthetic_passport_failure'); });
        try {
            $this->service()->rotate(self::TENANT_ID, (string) Str::uuid());
            self::fail('Issuance must fail.');
        } catch (RuntimeException $exception) {
            self::assertSame('synthetic_passport_failure', $exception->getMessage());
        } finally {
            $model::flushEventListeners();
        }
        self::assertSame($listeners, app('events')->getRawListeners()[AccessTokenCreated::class]);
        app('events')->dispatch(new AccessTokenCreated('synthetic', '0', 'synthetic'));
        self::assertSame(1, $calls);
        Notification::assertNothingSent();
        Mail::assertNothingSent();
        self::assertSame(2, Passport::token()->newQuery()->count());
    }

    private function assertBootstrapConflict(string $key, string $code, int $tokens = 1): void
    {
        try {
            $this->service()->bootstrap(self::TENANT_ID, $key);
            self::fail('Bootstrap must conflict.');
        } catch (DomainException $exception) {
            self::assertSame($code, $exception->getMessage());
        }
        self::assertSame(1, User::query()->count());
        self::assertSame($tokens, Passport::token()->newQuery()->count());
    }

    private function service(): ProvisioningService
    {
        return app(ProvisioningService::class);
    }

    private function operation(string $key, string $kind = 'bootstrap'): ProvisioningOperation
    {
        return ProvisioningOperation::query()->create([
            'id' => (string) Str::uuid(),
            'kind' => $kind,
            'idempotency_key' => $key,
            'state' => 'prepared',
        ]);
    }

    private function setEncryptionKey(): void
    {
        config()->set('app.key', 'base64:'.base64_encode(random_bytes(32)));
        config()->set('app.previous_keys', []);
        app()->forgetInstance('encrypter');
        Crypt::clearResolvedInstance('encrypter');
    }

    private function assertCredentialUnavailable(string $ciphertext): void
    {
        try {
            $this->service()->decryptCredential($ciphertext);
            self::fail('Decryption must fail closed.');
        } catch (RuntimeException $exception) {
            self::assertSame('credential_unavailable', $exception->getMessage());
            self::assertNull($exception->getPrevious());
        }
    }
}
