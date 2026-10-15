<?php

declare(strict_types=1);

namespace FireflyIII\Http\Middleware;

use Closure;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

final class AuthenticateFinanceHubProvisioner
{
    public function __construct(private readonly Repository $config) {}

    public function handle(Request $request, Closure $next): Response
    {
        $secret = $this->config->get('finance-hub.provisioning_secret');

        if (!is_string($secret) || '' === trim($secret)) {
            return new JsonResponse(['error' => 'not_found'], 404, ['Cache-Control' => 'no-store']);
        }

        $authorization = $request->headers->all('authorization');
        if (1 !== count($authorization)
            || !is_string($authorization[0])
            || 1 !== preg_match('/\ABearer ([A-Za-z0-9._~+\/-]+=*)\z/', $authorization[0], $matches)
            || !hash_equals($secret, $matches[1])) {
            return new JsonResponse(['error' => 'unauthorized'], 401, ['Cache-Control' => 'no-store']);
        }

        return $next($request);
    }
}
