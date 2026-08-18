<?php

namespace App\Support\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Http\Resources\Json\ResourceCollection;
use Illuminate\Pagination\AbstractPaginator;

/**
 * One consistent JSON envelope for the whole API so the React client can rely
 * on a single shape: { data, message?, meta? }. Paginated responses carry meta.
 */
trait ApiResponse
{
    protected function ok(mixed $data = null, ?string $message = null, int $status = 200): JsonResponse
    {
        $payload = ['data' => $this->unwrap($data)];

        if ($meta = $this->metaFrom($data)) {
            $payload['meta'] = $meta;
        }

        if ($message !== null) {
            $payload['message'] = $message;
        }

        return response()->json($payload, $status);
    }

    protected function created(mixed $data = null, ?string $message = null): JsonResponse
    {
        return $this->ok($data, $message, 201);
    }

    protected function message(string $message, int $status = 200): JsonResponse
    {
        return response()->json(['message' => $message], $status);
    }

    private function unwrap(mixed $data): mixed
    {
        if ($data instanceof ResourceCollection) {
            return $data->resolve();
        }

        if ($data instanceof JsonResource) {
            return $data->resolve();
        }

        if ($data instanceof AbstractPaginator) {
            return array_values($data->items());
        }

        return $data;
    }

    private function metaFrom(mixed $data): ?array
    {
        $paginator = null;

        if ($data instanceof ResourceCollection && $data->resource instanceof AbstractPaginator) {
            $paginator = $data->resource;
        } elseif ($data instanceof AbstractPaginator) {
            $paginator = $data;
        }

        if (! $paginator) {
            return null;
        }

        return [
            'current_page' => $paginator->currentPage(),
            'last_page'    => $paginator->lastPage(),
            'per_page'     => $paginator->perPage(),
            'total'        => $paginator->total(),
            'from'         => $paginator->firstItem(),
            'to'           => $paginator->lastItem(),
        ];
    }
}
