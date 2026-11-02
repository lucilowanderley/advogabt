<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ApiToken;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/** Base da API REST v1: paginação, filtros comuns e respostas no mesmo formato. */
abstract class ApiController extends Controller
{
    protected function paginated(Request $request, Builder $query, callable $map): JsonResponse
    {
        if ($since = $this->date($request, 'updated_since')) {
            $query->where($query->getModel()->getTable().'.updated_at', '>=', $since);
        }
        $perPage = min(100, max(1, (int) $request->query('per_page', 25)));
        $page = $query->paginate($perPage)->withQueryString();

        return response()->json([
            'data' => collect($page->items())->map($map)->values(),
            'meta' => [
                'current_page' => $page->currentPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
                'last_page' => $page->lastPage(),
            ],
            'links' => ['next' => $page->nextPageUrl(), 'prev' => $page->previousPageUrl()],
        ]);
    }

    protected function item(array $data, int $status = 200): JsonResponse
    {
        return response()->json(['data' => $data], $status);
    }

    protected function date(Request $request, string $key): ?Carbon
    {
        $value = $request->query($key);
        if (! is_string($value) || $value === '') {
            return null;
        }
        try {
            return Carbon::parse($value);
        } catch (\Throwable) {
            abort(response()->json(['message' => "Data inválida em \"{$key}\". Use AAAA-MM-DD ou ISO 8601."], 422));
        }
    }

    protected function token(Request $request): ?ApiToken
    {
        return $request->attributes->get('api_token');
    }

    protected function authorId(Request $request): ?int
    {
        return $this->token($request)?->user_id;
    }
}
