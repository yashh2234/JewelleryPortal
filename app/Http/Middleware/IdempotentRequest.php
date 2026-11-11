<?php

namespace App\Http\Middleware;

use App\Support\Tenancy\TenantContext;
use Closure;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * Honours the Idempotency-Key header on writes, so a double tap on a slow connection
 * (or an offline queue replay) cannot post the same gold issue twice.
 *
 *  - First request with a key: runs, and its response is stored.
 *  - Same key + same request again: the stored response is replayed.
 *  - Same key + different request: 422.  Still running: 409.
 *  - 5xx responses are not stored, so the client can retry.
 */
class IdempotentRequest
{
    private const WRITE_METHODS = ['POST', 'PUT', 'PATCH', 'DELETE'];

    public function __construct(private TenantContext $context) {}

    public function handle(Request $request, Closure $next): Response
    {
        $key = $request->header('Idempotency-Key');

        if (! $key || ! in_array($request->method(), self::WRITE_METHODS, true)) {
            return $next($request);
        }

        if (strlen($key) > 100) {
            return response()->json(['message' => 'Idempotency-Key is too long (max 100).'], 422);
        }

        $scope = [
            'tenant_id' => $this->context->id() ?? 0,
            'actor' => $this->actor($request),
            'key' => $key,
        ];
        $hash = $this->hash($request);

        try {
            $id = DB::table('idempotency_keys')->insertGetId($scope + [
                'method' => $request->method(),
                'path' => $request->path(),
                'request_hash' => $hash,
                'status' => 'processing',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } catch (UniqueConstraintViolationException) {
            return $this->replay(DB::table('idempotency_keys')->where($scope)->first(), $hash);
        }

        try {
            $response = $next($request);
        } catch (\Throwable $e) {
            DB::table('idempotency_keys')->where('id', $id)->delete();
            throw $e;
        }

        if ($response->getStatusCode() >= 500 || ! $response instanceof JsonResponse) {
            DB::table('idempotency_keys')->where('id', $id)->delete();

            return $response;
        }

        DB::table('idempotency_keys')->where('id', $id)->update([
            'status' => 'done',
            'response_status' => $response->getStatusCode(),
            'response_body' => $response->getContent(),
            'updated_at' => now(),
        ]);

        return $response;
    }

    private function replay(?object $stored, string $hash): Response
    {
        if (! $stored) {
            return response()->json(['message' => 'Please retry.'], 409);
        }

        if (! hash_equals($stored->request_hash, $hash)) {
            return response()->json(['message' => 'Idempotency-Key was already used for a different request.'], 422);
        }

        if ($stored->status !== 'done') {
            return response()->json(['message' => 'The original request is still being processed.'], 409);
        }

        return response($stored->response_body, $stored->response_status, [
            'Content-Type' => 'application/json',
            'Idempotent-Replayed' => 'true',
        ]);
    }

    private function actor(Request $request): string
    {
        foreach (['staff', 'retailer', 'kaarigar'] as $guard) {
            if ($id = auth()->guard($guard)->id()) {
                return "{$guard}:{$id}";
            }
        }

        return 'guest:'.$request->ip();
    }

    private function hash(Request $request): string
    {
        $files = collect($request->allFiles())->flatten()->map(
            fn (UploadedFile $f) => [$f->getClientOriginalName(), $f->getSize(), hash_file('sha256', $f->getRealPath())]
        )->all();

        $input = $request->except(array_keys($request->allFiles()));
        ksort($input);

        return hash('sha256', json_encode([$request->method(), $request->path(), $input, $files]));
    }
}
