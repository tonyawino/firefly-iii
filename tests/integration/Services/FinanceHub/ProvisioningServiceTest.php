<?php

declare(strict_types=1);

namespace Tests\integration\Services\FinanceHub;

use DomainException;
use FireflyIII\Models\FinanceHub\ProvisioningOperation;
use FireflyIII\Models\FinanceHub\ProvisioningState;
use FireflyIII\Services\FinanceHub\ProvisioningService;
use Illuminate\Database\Query\Grammars\SQLiteGrammar;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Override;
use PDO;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\integration\CreatesApplication;

/**
 * @internal
 */
#[CoversNothing]
final class ProvisioningServiceTest extends TestCase
{
    use CreatesApplication;

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
