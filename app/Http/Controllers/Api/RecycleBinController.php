<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\RecycleBin;
use App\Services\RecycleBinService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Throwable;

class RecycleBinController extends Controller {
    protected RecycleBinService $recycleBinService;

    public function __construct (RecycleBinService $recycleBinService) {
        $this->recycleBinService = $recycleBinService;
    }

    /**
     * List recycle bin entries.
     * GET /api/recycle-bin/list
     */
    public function list (Request $request): JsonResponse {
        $user = $request->user();

        $status = $request->input('status', 'all');
        $module = $request->input('module', '');

        $query = RecycleBin::query()
            ->with(['deletedBy:usr_id,usr_name']);

        if ($status !== 'all') {
            $query->where('rbs_restore_status', $status);
        }

        if (
            !empty($module) &&
            array_key_exists($module, RecycleBinService::MODULES)
        ) {
            $query->where('rbs_module', $module);
        }

        $rows = $query->select([
            'rbs_id as id',
            'rbs_module as module',
            'rbs_original_id as original_id',
            'rbs_label as label',
            'rbs_file_count as file_count',
            'rbs_deleted_by_name as deleted_by_name',
            'rbs_deletion_reason as deletion_reason',
            'rbs_restore_status as restore_status',
            'rbs_purge_error as purge_error',
            'rbs_deleted_at as deleted_at',
            'rbs_permanent_delete_at as purge_at',
            'rbs_restored_at as restored_at',
            'rbs_restored_by as restored_by',
            'rbs_purged_at as purged_at',
            'rbs_purged_by as purged_by',
        ])
            ->orderByDesc('rbs_id')
            ->limit(300)
            ->get();

        // Format each item for direct React rendering.
        $items = $rows->map(function ($row) use ($user) {
            $status = $row->restore_status;

            $isSuperAdmin = (bool)($user->usr_is_super_admin ?? false);

            return [
                'id' => $row->id,
                'item' => [
                    'name' => $row->label,
                    'type' => $row->module,
                    'original_id' => $row->original_id,
                    'file_count' => (int)($row->file_count ?? 0),
                ],
                'deleted_by' => [
                    'id' => $row->deletedBy?->usr_id,
                    'name' => $row->deleted_by_name
                        ?? $row->deletedBy?->usr_name,
                ],
                'deleted_at' => $row->deleted_at,
                'status' => [
                    'value' => $status,
                    'label' => match ($status) {
                        'restored' => 'Restored',
                        'purged', 'permanently_deleted' => 'Deleted forever',
                        default => 'In the bin',
                    },
                ],
                'timestamps' => [
                    'purge_at' => $row->purge_at,
                    'restored_at' => $row->restored_at,
                    'purged_at' => $row->purged_at,
                ],
                'actions' => [
                    'can_restore' => $status === 'deleted',
                    'can_purge' => $isSuperAdmin
                        && $status === 'deleted',
                ],
                'details' => [
                    'deletion_reason' => $row->deletion_reason,
                    'purge_error' => $row->purge_error,
                    'restored_by' => $row->restored_by,
                    'purged_by' => $row->purged_by,
                ],
            ];
        });

        // Status counts for the tab badges.
        $counts = RecycleBin::query()
            ->selectRaw("
            COUNT(*) as all_count,
            SUM(CASE WHEN rbs_restore_status = 'deleted' THEN 1 ELSE 0 END) as in_bin,
            SUM(CASE WHEN rbs_restore_status = 'restored' THEN 1 ELSE 0 END) as restored,
            SUM(CASE WHEN rbs_restore_status IN ('purged', 'permanently_deleted') THEN 1 ELSE 0 END) as permanently_deleted
        ")
            ->first();

        return response()->json([
            'success' => true,
            'recycled_items' => $items,
            'meta' => [
                'retention_days' => RecycleBinService::RECYCLE_DAYS,
                'can_purge' => (bool)($user->usr_is_super_admin ?? false),
                'limit' => 300,
                'filters' => [
                    'status' => $status,
                    'module' => $module ?: 'all',
                ],
                'counts' => [
                    'all' => (int)($counts->all_count ?? 0),
                    'in_bin' => (int)($counts->in_bin ?? 0),
                    'restored' => (int)($counts->restored ?? 0),
                    'permanently_deleted' => (int)(
                        $counts->permanently_deleted ?? 0
                    ),
                ],
                'modules' => RecycleBinService::MODULES
            ],
        ], 200);
    }

    /**
     * Restore an item from the recycle bin.
     * POST /api/recycle-bin/restore
     */
    public function restore (Request $request): JsonResponse {
        $user = $request->user();

        /*if (!($user->usr_is_super_admin ?? false) && !$user->can('recycle_restore')) {
            return response()->json([
                'message' => 'You do not have permission to restore items.',
                'error_code' => 'permission_denied',
                'required_permission' => 'recycle_restore',
            ], 403);
        }*/

        $request->validate([
            'id' => 'required|integer|exists:recycle_bins,rbs_id',
        ]);

        $bin = RecycleBin::find($request->input('id'));

        if ($bin->rbs_restore_status !== 'deleted') {
            return response()->json([
                'message' => 'This item was already ' . $bin->rbs_restore_status . '.',
            ], 422);
        }

        try {
            $msg = $this->recycleBinService->restore($bin);
        } catch (Throwable $e) {
            return response()->json([
                'message' => $e->getMessage(),
            ], 422);
        }

        $bin->update([
            'rbs_restore_status' => 'restored',
            'rbs_restored_at' => now(),
            'rbs_restored_by' => $user->usr_id,
        ]);

        return response()->json([
            'message' => $msg,
            'data' => [
                'rbs_id' => $bin->rbs_id,
                'rbs_module' => $bin->rbs_module,
                'rbs_restore_status' => $bin->rbs_restore_status,
                'rbs_restored_at' => $bin->rbs_restored_at,
                'rbs_restored_by' => $bin->rbs_restored_by,
            ],
        ], 200);
    }

    /**
     * Permanently purge an item and its associated stored files.
     * POST /api/recycle-bin/purge
     */
    public function purge (Request $request): JsonResponse {
        $user = $request->user();

        /*if (!($user->usr_is_super_admin ?? false)) {
            return response()->json([
                'message' => 'Only the Super Admin can permanently delete items.',
                'error_code' => 'permission_denied',
                'required_permission' => 'super_admin',
            ], 403);
        }*/

        $request->validate([
            'id' => 'required|integer|exists:recycle_bins,rbs_id',
        ]);

        $bin = RecycleBin::find($request->input('id'));

        if ($bin->rbs_restore_status !== 'deleted') {
            return response()->json([
                'message' => 'This item is no longer in the Recycle Bin.',
            ], 422);
        }

        try {
            $this->recycleBinService->purge($bin, $user->usr_id);
        } catch (Throwable $e) {
            $bin->update([
                'rbs_purge_error' => Str::limit($e->getMessage(), 500, ''),
                'rbs_purge_attempts' => $bin->rbs_purge_attempts + 1,
            ]);

            return response()->json([
                'message' => 'Could not delete the stored files right now, so the item was retained. Please try again later.',
            ], 500);
        }

        return response()->json([
            'message' => 'Item permanently deleted successfully.',
        ], 200);
    }
}
