<?php

namespace App\Http\Controllers\Api;

use App\Models\AuditLog;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AuditLogController extends Controller {
    public function index (Request $request): JsonResponse {
        $filters = $request->validate([
            'module' => ['sometimes', 'string', 'max:30'],
            'action' => ['sometimes', 'string', 'max:40'],
            'user_id' => ['sometimes', 'integer', 'min:1'],
            'record_id' => ['sometimes', 'string', 'max:40'],
            'from' => ['sometimes', 'date'],
            'to' => ['sometimes', 'date', 'after_or_equal:from'],
        ]);

        $logs = AuditLog::query()
            ->when(
                $filters['module'] ?? null,
                fn($q, $value) => $q->where('alg_module', $value)
            )
            ->when(
                $filters['action'] ?? null,
                fn($q, $value) => $q->where('alg_action', $value)
            )
            ->when(
                $filters['user_id'] ?? null,
                fn($q, $value) => $q->where('alg_user_id', $value)
            )
            ->when(
                $filters['record_id'] ?? null,
                fn($q, $value) => $q->where('alg_record_id', $value)
            )
            ->when(
                $filters['from'] ?? null,
                fn($q, $value) => $q->whereDate(
                    'alg_updated_on',
                    '>=',
                    $value
                )
            )
            ->when(
                $filters['to'] ?? null,
                fn($q, $value) => $q->whereDate(
                    'alg_updated_on',
                    '<=',
                    $value
                )
            )
            ->orderByDesc('alg_updated_on')->paginate(config('constants.PER_PAGE_ITEM_COUNT'));
        /*->paginate(25)
        ->withQueryString()*/

        return response()->json($logs);
    }
}
