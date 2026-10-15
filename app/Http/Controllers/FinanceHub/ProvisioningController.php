<?php

declare(strict_types=1);

namespace FireflyIII\Http\Controllers\FinanceHub;

use Closure;
use DomainException;
use FireflyIII\Services\FinanceHub\ProvisioningService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use RuntimeException;
use Throwable;

// The browser base controller performs configuration/session work in its constructor.
final class ProvisioningController extends Controller
{
    public function bootstrap(Request $request): JsonResponse
    {
        return $this->respond(
            $request, ['tenant_id', 'idempotency_key'], null,
            static fn (ProvisioningService $service, array $input): array => $service->bootstrap($input['tenant_id'], $input['idempotency_key'])
        );
    }

    public function status(Request $request, string $operationId): JsonResponse
    {
        return $this->respond(
            $request, ['tenant_id'], $operationId,
            static fn (ProvisioningService $service, array $input): array => $service->status($input['tenant_id'], $operationId)
        );
    }

    public function acknowledge(Request $request, string $operationId): JsonResponse
    {
        return $this->respond(
            $request, ['tenant_id'], $operationId,
            static fn (ProvisioningService $service, array $input): array => $service->acknowledge($input['tenant_id'], $operationId)
        );
    }

    public function rotate(Request $request): JsonResponse
    {
        return $this->respond(
            $request, ['tenant_id', 'idempotency_key'], null,
            static fn (ProvisioningService $service, array $input): array => $service->rotate($input['tenant_id'], $input['idempotency_key'])
        );
    }

    public function confirmRotation(Request $request, string $operationId): JsonResponse
    {
        return $this->respond(
            $request, ['tenant_id'], $operationId,
            static fn (ProvisioningService $service, array $input): array => $service->confirmRotation($input['tenant_id'], $operationId)
        );
    }

    private function respond(Request $request, array $fields, ?string $operationId, Closure $action): JsonResponse
    {
        try {
            $input = $request->all();
            if ([] !== array_diff(array_keys($input), $fields) || [] !== array_diff($fields, array_keys($input))) {
                return $this->json(['error' => 'invalid_request'], 422);
            }
            foreach ($fields as $field) {
                if (!$this->isUuid($input[$field])) {
                    return $this->json(['error' => 'invalid_request'], 422);
                }
            }
            if (null !== $operationId && !$this->isUuid($operationId)) {
                return $this->json(['error' => 'invalid_request'], 422);
            }

            return $this->json($action(app(ProvisioningService::class), $input));
        } catch (DomainException $exception) {
            $code = $exception->getMessage();
            if (in_array($code, ['tenant_mismatch', 'bootstrap_conflict', 'operation_conflict', 'rotation_conflict'], true)) {
                return $this->json(['error' => $code], 409);
            }
        } catch (RuntimeException $exception) {
            if ('credential_unavailable' === $exception->getMessage()) {
                return $this->json(['error' => 'credential_unavailable'], 409);
            }
        } catch (Throwable) {
            // Never pass request/SQL/Passport exception context to Firefly's handler.
        }

        return $this->json(['error' => 'provisioning_failed'], 500);
    }

    private function isUuid(mixed $value): bool
    {
        return is_string($value) && 1 === preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\z/i', $value);
    }

    private function json(array $data, int $status = 200): JsonResponse
    {
        return response()->json($data, $status, ['Cache-Control' => 'no-store']);
    }
}
