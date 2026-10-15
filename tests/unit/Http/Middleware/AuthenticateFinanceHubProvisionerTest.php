<?php

declare(strict_types=1);

namespace Tests\unit\Http\Middleware;

use FireflyIII\Http\Middleware\AuthenticateFinanceHubProvisioner;
use Illuminate\Config\Repository;
use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * @internal
 */
#[CoversNothing]
final class AuthenticateFinanceHubProvisionerTest extends TestCase
{
    private const string SECRET = 'synthetic-test-secret';

    #[DataProvider('disabledSecrets')]
    public function testUnconfiguredSecretHidesTheEndpoint(mixed $secret): void
    {
        $middleware = $this->middleware($secret);
        $request    = Request::create('/api/v1/finance-hub/provisioning/bootstrap', 'POST');
        $request->headers->set('Authorization', 'Bearer '.self::SECRET);

        $response = $middleware->handle($request, static function (): never {
            self::fail('A disabled endpoint must not invoke the downstream handler.');
        });

        self::assertSame(404, $response->getStatusCode());
        self::assertSame(['error' => 'not_found'], json_decode($response->getContent(), true, flags: JSON_THROW_ON_ERROR));
    }

    public static function disabledSecrets(): iterable
    {
        yield 'missing' => [null];
        yield 'empty' => [''];
        yield 'whitespace' => [" \t\n"];
        yield 'boolean' => [false];
        yield 'number' => [123];
        yield 'array' => [['invalid']];
    }

    #[DataProvider('invalidAuthorizationValues')]
    public function testInvalidAuthorizationDoesNotInvokeTheDownstreamHandler(?array $values): void
    {
        $request = Request::create('/api/v1/finance-hub/provisioning/bootstrap', 'POST');
        if (null !== $values) {
            $request->headers->set('Authorization', $values);
        }

        $response = $this->middleware(self::SECRET)->handle($request, static function (): never {
            self::fail('Invalid authorization must not invoke the downstream handler.');
        });

        self::assertSame(401, $response->getStatusCode());
        self::assertSame(['error' => 'unauthorized'], json_decode($response->getContent(), true, flags: JSON_THROW_ON_ERROR));
        self::assertStringNotContainsString(self::SECRET, $response->getContent());
        self::assertTrue($response->headers->hasCacheControlDirective('no-store'));
    }

    public static function invalidAuthorizationValues(): iterable
    {
        yield 'missing' => [null];
        yield 'empty header list' => [[]];
        yield 'empty header' => [['']];
        yield 'null header value' => [[null]];
        yield 'numeric header value' => [[123]];
        yield 'wrong secret' => [['Bearer incorrect-secret']];
        yield 'shorter secret' => [['Bearer synthetic-test-secre']];
        yield 'longer secret' => [['Bearer '.self::SECRET.'x']];
        yield 'missing credential' => [['Bearer ']];
        yield 'missing scheme' => [[self::SECRET]];
        yield 'wrong scheme' => [['Basic '.self::SECRET]];
        yield 'lowercase scheme' => [['bearer '.self::SECRET]];
        yield 'double space' => [['Bearer  '.self::SECRET]];
        yield 'tab separator' => [["Bearer\t".self::SECRET]];
        yield 'leading whitespace' => [[' Bearer '.self::SECRET]];
        yield 'trailing whitespace' => [['Bearer '.self::SECRET.' ']];
        yield 'trailing newline' => [['Bearer '.self::SECRET."\n"]];
        yield 'line injection' => [['Bearer '.self::SECRET."\r\nX-Other: value"]];
        yield 'merged credentials' => [['Bearer '.self::SECRET.', Bearer '.self::SECRET]];
        yield 'repeated credentials' => [['Bearer '.self::SECRET, 'Bearer '.self::SECRET]];
        yield 'invalid then valid' => [['Bearer wrong', 'Bearer '.self::SECRET]];
        yield 'valid then invalid' => [['Bearer '.self::SECRET, 'Bearer wrong']];
    }

    #[DataProvider('validSecrets')]
    public function testExactBearerSecretReachesTheDownstreamHandler(string $secret): void
    {
        $request = Request::create('/api/v1/finance-hub/provisioning/bootstrap', 'POST');
        $request->headers->set('Authorization', 'Bearer '.$secret);
        $expected = new Response('', 204);
        $calls    = 0;

        $response = $this->middleware($secret)->handle($request, static function (Request $received) use ($request, $expected, &$calls): Response {
            self::assertSame($request, $received);
            ++$calls;

            return $expected;
        });

        self::assertSame($expected, $response);
        self::assertSame(1, $calls);
    }

    public static function validSecrets(): iterable
    {
        yield 'plain token' => [self::SECRET];
        yield 'bearer token alphabet' => ['synthetic/abc+._~-123=='];
    }

    #[DataProvider('tenantIdentifiers')]
    public function testConfigurationValidatesTheTenantUuidShape(string $tenantId, ?string $expected): void
    {
        $configuration = $this->configuration($tenantId, self::SECRET);

        self::assertSame($expected, $configuration['tenant_id']);
        self::assertSame(self::SECRET, $configuration['provisioning_secret']);
    }

    public static function tenantIdentifiers(): iterable
    {
        yield 'empty' => ['', null];
        yield 'invalid' => ['not-a-uuid', null];
        yield 'environment boolean' => ['false', null];
        yield 'compact' => ['550e8400e29b41d4a716446655440000', null];
        yield 'trailing newline' => ["550e8400-e29b-41d4-a716-446655440000\n", null];
        yield 'valid' => ['550e8400-e29b-41d4-a716-446655440000', '550e8400-e29b-41d4-a716-446655440000'];
        yield 'uppercase' => ['550E8400-E29B-41D4-A716-446655440000', '550E8400-E29B-41D4-A716-446655440000'];
    }

    #[DataProvider('configuredSecrets')]
    public function testConfigurationDisablesAbsentOrNonStringSecrets(string $secret, ?string $expected): void
    {
        $configuration = $this->configuration('', $secret);

        self::assertSame($expected, $configuration['provisioning_secret']);
    }

    public static function configuredSecrets(): iterable
    {
        yield 'empty' => ['', null];
        yield 'whitespace' => [" \t\n", null];
        yield 'boolean false' => ['false', null];
        yield 'boolean true' => ['true', null];
        yield 'null' => ['null', null];
        yield 'configured' => [self::SECRET, self::SECRET];
    }

    private function configuration(string $tenantId, string $secret): array
    {
        $originalEnv    = $_ENV;
        $originalServer = $_SERVER;
        $_ENV['FIREFLY_TENANT_ID'] = $_SERVER['FIREFLY_TENANT_ID'] = $tenantId;
        $_ENV['FIREFLY_PROVISIONING_SECRET'] = $_SERVER['FIREFLY_PROVISIONING_SECRET'] = $secret;

        try {
            return require dirname(__DIR__, 4).'/config/finance-hub.php';
        } finally {
            $_ENV    = $originalEnv;
            $_SERVER = $originalServer;
        }
    }

    private function middleware(mixed $secret): AuthenticateFinanceHubProvisioner
    {
        return new AuthenticateFinanceHubProvisioner(new Repository([
            'finance-hub' => ['provisioning_secret' => $secret],
        ]));
    }
}
