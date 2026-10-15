<?php

declare(strict_types=1);

namespace Tests\integration\Api\FinanceHub;

use FireflyIII\Http\Middleware\AuthenticateFinanceHubProvisioner;
use FireflyIII\Models\FinanceHub\ProvisioningOperation;
use FireflyIII\Models\FinanceHub\ProvisioningState;
use FireflyIII\Services\FinanceHub\ProvisioningService;
use FireflyIII\User;
use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Http\Exceptions\PostTooLargeException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Laravel\Passport\Passport;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use Override;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\integration\CreatesApplication;
use Tests\integration\Traits\FinanceHubBootstrapDatabase;

#[CoversNothing]
final class ProvisioningControllerTest extends TestCase
{
    use CreatesApplication;
    use FinanceHubBootstrapDatabase;

    private const string TENANT = '550e8400-e29b-41d4-a716-446655440000';
    private const string SECRET = 'synthetic-private-provisioning-secret';
    private const string PREFIX = '/api/v1/finance-hub/provisioning';

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();
        config()->set('database.default', 'sqlite');
        config()->set('database.connections.sqlite.database', ':memory:');
        DB::purge('sqlite');
        config()->set('finance-hub.tenant_id', self::TENANT);
        config()->set('finance-hub.provisioning_secret', self::SECRET);
        config()->set('app.key', 'base64:'.base64_encode(random_bytes(32)));
        app()->forgetInstance('encrypter');
        Crypt::clearResolvedInstance('encrypter');
        (require base_path('database/migrations/2026_10_15_000000_create_finance_hub_provisioning_tables.php'))->up();
        $this->createBootstrapSchema();
    }

    public function testBootstrapReplayStatusAndAckUseRealRouterAndRedactCredentials(): void
    {
        $payload = $this->payload();
        $first = $this->postJson(self::PREFIX.'/bootstrap', $payload, $this->headers());
        $first->assertOk()->assertJsonStructure(['operation_id', 'state', 'credential']);
        self::assertStringContainsString('no-store', $first->headers->get('Cache-Control'));
        self::assertFalse($first->headers->has('Set-Cookie'));
        $retry = $this->postJson(self::PREFIX.'/bootstrap', $payload, $this->headers());
        $retry->assertExactJson($first->json());
        $operation = $first->json('operation_id');
        $status = $this->getJson(self::PREFIX.'/operations/'.$operation.'?tenant_id='.self::TENANT, $this->headers());
        $status->assertOk()->assertExactJson(['operation_id' => $operation, 'state' => 'prepared']);
        $ack = $this->postJson(self::PREFIX.'/operations/'.$operation.'/ack', ['tenant_id' => self::TENANT], $this->headers());
        $ack->assertOk()->assertExactJson(['operation_id' => $operation, 'state' => 'acknowledged']);
        $this->postJson(self::PREFIX.'/operations/'.$operation.'/ack', ['tenant_id' => self::TENANT], $this->headers())->assertExactJson($ack->json());
        $this->postJson(self::PREFIX.'/bootstrap', $payload, $this->headers())->assertExactJson($ack->json());
        self::assertNull(ProvisioningOperation::query()->sole()->encrypted_credential);
        self::assertSame(1, User::query()->count());
        self::assertSame(1, Passport::token()->newQuery()->count());
    }

    public function testReturnedCredentialAuthenticatesReadOnlyFireflyApi(): void
    {
        // Only this proof needs the normal system currency used by AboutController.
        DB::table('transaction_currencies')->insert(['code' => 'EUR', 'name' => 'Euro', 'symbol' => '€']);
        $credential = $this->postJson(self::PREFIX.'/bootstrap', $this->payload(), $this->headers())->assertOk()->json('credential');
        $this->getJson('/api/v1/about', ['Authorization' => 'Bearer '.$credential])->assertOk()->assertJsonStructure(['data' => ['version', 'driver']]);
    }

    #[DataProvider('unauthorizedRequests')]
    public function testAuthenticationRejectsBeforeDatabaseWork(?string $configured, ?string $authorization, int $status): void
    {
        config()->set('finance-hub.provisioning_secret', $configured);
        DB::connection()->enableQueryLog();
        $this->postJson(self::PREFIX.'/bootstrap', $this->payload(), null === $authorization ? [] : ['Authorization' => $authorization])->assertStatus($status);
        self::assertSame([], DB::connection()->getQueryLog());
        DB::connection()->disableQueryLog();
    }

    public static function unauthorizedRequests(): iterable
    {
        yield 'disabled' => [null, 'Bearer '.self::SECRET, 404];
        yield 'missing' => [self::SECRET, null, 401];
        yield 'wrong' => [self::SECRET, 'Bearer wrong', 401];
        yield 'malformed' => [self::SECRET, 'Basic '.self::SECRET, 401];
    }

    #[DataProvider('invalidPayloads')]
    public function testStrictValidationRejectsBeforeDatabaseWork(array $payload): void
    {
        DB::connection()->enableQueryLog();
        $this->postJson(self::PREFIX.'/bootstrap', $payload, $this->headers())->assertStatus(422)->assertExactJson(['error' => 'invalid_request']);
        self::assertSame([], DB::connection()->getQueryLog());
        DB::connection()->disableQueryLog();
    }

    public static function invalidPayloads(): iterable
    {
        $valid = ['tenant_id' => self::TENANT, 'idempotency_key' => self::TENANT];
        yield 'missing' => [[]];
        yield 'bad tenant' => [array_replace($valid, ['tenant_id' => 'invalid'])];
        yield 'bad key' => [array_replace($valid, ['idempotency_key' => 'invalid'])];
        yield 'array key' => [array_replace($valid, ['idempotency_key' => []])];
        yield 'email injection' => [$valid + ['email' => 'real@example.com']];
        yield 'role injection' => [$valid + ['role' => 'owner']];
        yield 'null extra field' => [$valid + ['host' => null]];
    }

    public function testWrongTenantIsRejectedBeforeDatabaseWork(): void
    {
        DB::connection()->enableQueryLog();
        $this->postJson(self::PREFIX.'/bootstrap', array_replace($this->payload(), ['tenant_id' => '550e8400-e29b-41d4-a716-446655440001']), $this->headers())
            ->assertStatus(409)->assertExactJson(['error' => 'tenant_mismatch']);
        self::assertSame([], DB::connection()->getQueryLog());
        DB::connection()->disableQueryLog();
    }

    public function testDifferentKeyAndCorruptedCiphertextProduceSanitizedConflicts(): void
    {
        $payload = $this->payload();
        $this->postJson(self::PREFIX.'/bootstrap', $payload, $this->headers())->assertOk();
        $this->postJson(self::PREFIX.'/bootstrap', $this->payload(), $this->headers())->assertStatus(409)->assertExactJson(['error' => 'bootstrap_conflict']);
        ProvisioningOperation::query()->update(['encrypted_credential' => 'corrupted']);
        $this->postJson(self::PREFIX.'/bootstrap', $payload, $this->headers())->assertStatus(409)->assertExactJson(['error' => 'credential_unavailable']);
        self::assertSame(1, User::query()->count());
        self::assertSame(1, Passport::token()->newQuery()->count());
    }

    public function testUnknownOperationsAndInvalidOperationIdsCannotExposeData(): void
    {
        $this->getJson(self::PREFIX.'/operations/'.Str::uuid().'?tenant_id='.self::TENANT, $this->headers())->assertStatus(409)->assertExactJson(['error' => 'operation_conflict']);
        $this->getJson(self::PREFIX.'/operations/not-a-uuid?tenant_id='.self::TENANT, $this->headers())->assertStatus(422);
        $this->postJson(self::PREFIX.'/operations/'.Str::uuid().'/ack', ['tenant_id' => self::TENANT], $this->headers())->assertStatus(409);
    }

    public function testProvisioningRoutesExcludeUserAuthSessionAndCors(): void
    {
        foreach ([['POST', '/bootstrap'], ['GET', '/operations/'.Str::uuid()], ['POST', '/operations/'.Str::uuid().'/ack']] as [$method, $path]) {
            $route = app('router')->getRoutes()->match(Request::create(self::PREFIX.$path, $method));
            self::assertSame([AuthenticateFinanceHubProvisioner::class], app('router')->gatherRouteMiddleware($route));
        }
        $this->postJson(self::PREFIX.'/bootstrap/extra', $this->payload(), $this->headers())->assertNotFound();
    }

    public function testProvisioningRequestsAndPreflightNeverReceiveCorsHeaders(): void
    {
        $response = $this->postJson(self::PREFIX.'/bootstrap', $this->payload(), $this->headers() + ['Origin' => 'https://untrusted.invalid']);
        $response->assertOk();
        self::assertFalse($response->headers->has('Access-Control-Allow-Origin'));
        $preflight = $this->call('OPTIONS', self::PREFIX.'/bootstrap', server: [
            'HTTP_ORIGIN' => 'https://untrusted.invalid',
            'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'POST',
            'HTTP_ACCESS_CONTROL_REQUEST_HEADERS' => 'Authorization,Content-Type',
        ]);
        self::assertFalse($preflight->headers->has('Access-Control-Allow-Origin'));
        self::assertFalse($preflight->headers->has('Access-Control-Allow-Headers'));
    }

    public function testNormalRequestSizeLimitRemainsEnabledBeforeDatabaseWork(): void
    {
        DB::connection()->enableQueryLog();
        $this->withoutExceptionHandling();
        $this->expectException(PostTooLargeException::class);
        try {
            $this->call('POST', self::PREFIX.'/bootstrap', server: [
                'CONTENT_LENGTH' => PHP_INT_MAX,
                'CONTENT_TYPE' => 'application/json',
                'HTTP_ACCEPT' => 'application/json',
                'HTTP_AUTHORIZATION' => 'Bearer '.self::SECRET,
            ], content: json_encode($this->payload()));
        } finally {
            self::assertSame([], DB::connection()->getQueryLog());
            DB::connection()->disableQueryLog();
        }
    }

    public function testCredentialsAndUnexpectedExceptionContextNeverReachLogsOrErrors(): void
    {
        $handler = new TestHandler();
        Log::swap(new Logger('provisioning-test', [$handler]));
        $payload = $this->payload();
        $result = $this->postJson(self::PREFIX.'/bootstrap', $payload, $this->headers())->assertOk();
        $credential = $result->json('credential');
        $this->getJson(self::PREFIX.'/operations/'.$result->json('operation_id').'?tenant_id='.self::TENANT, $this->headers())->assertOk();
        $this->app->bind(ProvisioningService::class, static function () use ($credential): never {
            throw new \RuntimeException('synthetic SQL exception '.$credential.' '.self::SECRET);
        });
        $failure = $this->postJson(self::PREFIX.'/bootstrap', $payload, $this->headers());
        $failure->assertStatus(500)->assertExactJson(['error' => 'provisioning_failed']);
        $logs = json_encode($handler->getRecords());
        self::assertStringNotContainsString($credential, $logs);
        self::assertStringNotContainsString(self::SECRET, $logs);
        self::assertStringNotContainsString('Authorization', $logs);
    }

    private function payload(): array
    {
        return ['tenant_id' => self::TENANT, 'idempotency_key' => (string) Str::uuid()];
    }

    private function headers(): array
    {
        return ['Authorization' => 'Bearer '.self::SECRET];
    }
}
