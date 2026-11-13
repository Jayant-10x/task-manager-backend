<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SalesPipeline;
use App\Services\Audit\AuditService;
use App\Services\RecycleBinService;
use Illuminate\Http\Request;

class SalesPipelineController extends Controller {
    protected RecycleBinService $recycleBinService;

    public function __construct (RecycleBinService $recycleBinService) {
        $this->recycleBinService = $recycleBinService;
    }

    public function index (Request $request) {
        $loggedInUserId = get_logged_in_user_id();

        // Get team member IDs
        $teamUserIds = get_users_children_in_depth($loggedInUserId, true);

        // 1. Build Base Query for User Permissions
        $baseQuery = SalesPipeline::query();
        if (!is_admin()) {
            $baseQuery->whereIn('ssp_owner_id', array_merge([$loggedInUserId], $teamUserIds));
        }

        // 2. Compute Summary Tab Counts for Header Badges
        $summary = [
            'all' => (clone $baseQuery)->count(),
            'open' => (clone $baseQuery)->whereNotIn('ssp_stage', ['won', 'lost'])->count(),
            'discovery' => (clone $baseQuery)->where('ssp_stage', 'discovery')->count(),
            'qualified' => (clone $baseQuery)->where('ssp_stage', 'qualified')->count(),
            'proposal' => (clone $baseQuery)->where('ssp_stage', 'proposal')->count(),
            'negotiable' => (clone $baseQuery)->where('ssp_stage', 'negotiable')->count(),
            'won' => (clone $baseQuery)->where('ssp_stage', 'won')->count(),
            'lost' => (clone $baseQuery)->where('ssp_stage', 'lost')->count(),
        ];

        // 3. Fetch Deals
        $deals = $baseQuery->with('dealOwner:usr_id,usr_name')
            ->orderByRaw("
            CASE ssp_stage
                WHEN 'qualified' THEN 1
                WHEN 'negotiable' THEN 2
                WHEN 'proposal' THEN 3
                WHEN 'discovery' THEN 4
                WHEN 'won' THEN 5
                WHEN 'lost' THEN 6
                ELSE 7
            END
        ")
            ->orderBy('ssp_created_on', 'desc')
            ->get();

        // 4. Filter loaded collections in memory using standard PHP / Collection methods
        $open_stages = ['discovery', 'qualified', 'proposal', 'negotiable'];

        $open_pipeline = $deals->filter(function ($deal) use ($open_stages) {
            return in_array($deal->ssp_stage, $open_stages);
        });

        $open_pipeline_sum = $open_pipeline->sum('ssp_value');

        $won_deals = $deals->filter(function ($deal) {
            return $deal->ssp_stage === 'won';
        });

        $lost_deals = $deals->filter(function ($deal) {
            return $deal->ssp_stage === 'lost';
        });

        // Fixed multiplication operator (*) for weighted calculation
        $weighted_value = $open_pipeline->sum(function ($deal) {
            return $deal->ssp_value * ($deal->ssp_probability / 100);
        });

        $won_count = $won_deals->count();
        $lost_count = $lost_deals->count();
        $total_closed = $won_count + $lost_count;

        $card_cost = [
            'open_pipeline' => [
                'main_value' => $open_pipeline_sum,
                'supporting_value' => $open_pipeline->count() . ' open deals',
            ],
            'weighted' => [
                'main_value' => round($weighted_value, 2),
                'supporting_value' => 'Value x Probability',
            ],
            'won' => [
                'main_value' => $won_deals->sum('ssp_value'),
                'supporting_value' => $won_count . ' deals closed',
            ],
            'win_rate' => [
                'main_value' => $total_closed > 0 ? round(($won_count / $total_closed) * 100) : 0,
                'supporting_value' => $won_count . ' won, ' . $lost_count . ' lost',
            ],
        ];

        return response()->json([
            'status' => true,
            'message' => 'All Deals.',
            'summary' => $summary,
            'data' => $deals,
            'card_cost' => $card_cost,
        ]);
    }

    public function saveDeal (Request $request) {
        $validated = $request->validate([
            'deal_name' => 'required|string|min:2|max:120',
            'account_client' => 'nullable|string|max:120',
            'owner' => 'nullable|exists:users,usr_id',
            'stage' => 'required|in:' . implode(',', array_keys(config('constants.DEAL_STAGES'))),
            'value' => 'sometimes|nullable|numeric',
            'probability' => 'sometimes|nullable|numeric|min:0|max:100',
            'expected_close_date' => 'sometimes|nullable|date',
        ]);

        try {
            $deal = new SalesPipeline();

            // Generate the next deal code
            $lastDeal = SalesPipeline::query()->orderByDesc('ssp_id')->first();

            $nextNumber = $lastDeal ? ((int)str_replace('D-', '', $lastDeal->ssp_code)) + 1 : 1;

            $dealCode = 'D-' . str_pad($nextNumber, 2, '0', STR_PAD_LEFT);

            // Required fields
            $deal->ssp_name = $validated['deal_name'];
            $deal->ssp_code = $dealCode;
            $deal->ssp_stage = $validated['stage'];

            // Nullable fields
            $deal->ssp_account = $validated['account_client'] ?? null;
            $deal->ssp_owner_id = $validated['owner'] ?? null;

            // Optional fields:
            // Leave omitted columns unassigned so database defaults apply.
            if (array_key_exists('value', $validated)) {
                $deal->ssp_value = $validated['value'];
            }

            if (array_key_exists('probability', $validated)) {
                $deal->ssp_probability = $validated['probability'];
            }

            if (array_key_exists('expected_close_date', $validated)) {
                $deal->ssp_close_date = $validated['expected_close_date'];
            }

            // Audit fields
            $deal->ssp_created_by = setCreatedUpdatedBy();
            $deal->ssp_created_on = now();

            // Save the deal
            $isDealSaved = $deal->save();


            if ($isDealSaved) {
                // Exclude audit metadata from the business change history
                $changes = $deal->getRawOriginal();
                unset(
                    $changes['ssp_created_by'],
                    $changes['ssp_created_on'],
                    $changes['ssp_deleted_on']
                );
                app(AuditService::class)->log(
                    action: 'created',
                    module: 'deals',
                    record: $deal->ssp_id,
                    oldValues: [],
                    newValues: $changes,
                    description: 'Deal created.'
                );
                return response()->json([
                    'success' => true,
                    'message' => 'Deal saved successfully.',
                    'deal_id' => $deal->ssp_id,
                ], 201);
            }

            return response()->json([
                'success' => false,
                'message' => 'Something went wrong. Please try again later.',
            ], 500);

        } catch (\Throwable $e) {
            report($e);

            return response()->json([
                'success' => false,
                'message' => 'Something went wrong. Please try again later.',
            ], 500);
        }
    }

    protected function findDealByAnyId($dealId)
    {
        if (empty($dealId)) {
            return null;
        }

        $numericId = is_numeric($dealId) ? (int)$dealId : (int)str_replace(['D-', 'SSP-'], '', (string)$dealId);

        return SalesPipeline::where(function ($q) use ($dealId, $numericId) {
            $q->where('ssp_id', $numericId)
              ->orWhere('ssp_code', (string)$dealId)
              ->orWhere('ssp_code', 'D-' . (string)$dealId)
              ->orWhere('ssp_code', 'D-' . str_pad((string)$numericId, 2, '0', STR_PAD_LEFT));
        })->first();
    }

    public function updateDeal (Request $request, $id) {
        logger($request->all());
        $validated = $request->validate([
            'deal_name' => 'sometimes|required|string|min:2|max:120',
            'account_client' => 'sometimes|nullable|string|max:120',
            'owner' => 'sometimes|nullable|exists:users,usr_id',
            'stage' => 'sometimes|required|in:' . implode(',', array_keys(config('constants.DEAL_STAGES'))),
            'value' => 'sometimes|nullable|numeric',
            'probability' => 'sometimes|nullable|numeric|min:0|max:100',
            'expected_close_date' => 'sometimes|nullable|date',
        ]);

        try {
            // Find the existing deal
            $deal = $this->findDealByAnyId($id);

            if (!$deal) {
                return response()->json([
                    'success' => false,
                    'message' => 'Deal not found.',
                ], 404);
            }

            // Capture original values BEFORE changing the model
            $originalValues = $deal->getRawOriginal();

            // Update only fields provided in the request
            if (array_key_exists('deal_name', $validated)) {
                $deal->ssp_name = $validated['deal_name'];
            }

            if (array_key_exists('account_client', $validated)) {
                $deal->ssp_account = $validated['account_client'];
            }

            if (array_key_exists('owner', $validated)) {
                $deal->ssp_owner_id = $validated['owner'];
            }

            if (array_key_exists('stage', $validated)) {
                $deal->ssp_stage = $validated['stage'];
            }

            if (array_key_exists('value', $validated)) {
                $deal->ssp_value = $validated['value'];
            }

            if (array_key_exists('probability', $validated)) {
                $deal->ssp_probability = $validated['probability'];
            }

            if (array_key_exists('expected_close_date', $validated)) {
                $deal->ssp_close_date = $validated['expected_close_date'];
            }

            // Update audit fields
            $deal->ssp_updated_by = setCreatedUpdatedBy();
            $deal->ssp_updated_on = now();

            $isDealSaved = $deal->save();

            if ($isDealSaved) {
                // Get the fields actually changed by this save
                $changes = $deal->getChanges();

                // Exclude audit metadata from the business change history
                unset(
                    $changes['ssp_updated_by'],
                    $changes['ssp_updated_on'],
                    $changes['ssp_created_by'],
                    $changes['ssp_created_on'],
                    $changes['ssp_deleted_on']
                );

                $oldValues = [];
                $newValues = [];

                foreach ($changes as $field => $newValue) {
                    $oldValue = $originalValues[$field] ?? null;

                    // Record only genuine value changes
                    if ($oldValue != $newValue) {
                        $oldValues[$field] = $oldValue;
                        $newValues[$field] = $newValue;
                    }
                }

                if (!empty($changes) && !empty($newValues)) {
                    app(AuditService::class)->log(
                        action: 'updated',
                        module: 'deals',
                        record: $deal->ssp_id,
                        oldValues: $oldValues,
                        newValues: $newValues,
                        description: 'Deal updated.'
                    );
                }

                return response()->json([
                    'success' => true,
                    'message' => 'Deal updated successfully.',
                    'deal_id' => $deal->ssp_id,
                ], 200);
            }

            return response()->json([
                'success' => false,
                'message' => 'Something went wrong. Please try again later.',
            ], 500);
        } catch (\Throwable $e) {
            report($e);

            return response()->json([
                'success' => false,
                'message' => 'Something went wrong. Please try again later.',
            ], 500);
        }
    }

    public function dealData ($deal_id) {
        // Find the existing deal
        $deal = $this->findDealByAnyId($deal_id);

        if (!$deal) {
            return response()->json([
                'success' => false,
                'message' => 'Deal not found.',
            ], 404);
        }

        $deal->loadMissing('dealOwner:usr_id,usr_name,usr_designation');

        return response()->json([
            'success' => true,
            'deal' => $deal,
        ]);
    }

    public function deleteDeal (Request $request, $deal_id): \Illuminate\Http\JsonResponse {
        $user = $request->user();

        // 1. Find the existing deal FIRST
        $deal = $this->findDealByAnyId($deal_id);

        if (!$deal) {
            return response()->json([
                'success' => false,
                'message' => 'Deal not found.',
            ], 404);
        }

        // 2. Capture clean array representation and audit values
        $dealData = $deal->toArray();
        $oldValues = $deal->getRawOriginal();

        unset(
            $oldValues['ssp_updated_by'],
            $oldValues['ssp_updated_on'],
            $oldValues['ssp_created_by'],
            $oldValues['ssp_created_on'],
            $oldValues['ssp_deleted_on']
        );

        // 3. Delegate snapshotting & deletion to RecycleBinService
        $binId = $this->recycleBinService->recycleDeal(
            $user,
            $dealData,
            $request->input('reason', 'Pipeline closed/removed')
        );

        // 4. Log Audit Trail
        app(AuditService::class)->log(
            action: 'delete',
            module: 'deals',
            record: $deal->ssp_id,
            oldValues: $oldValues,
            newValues: [
                'recycle_bin_id' => $binId
            ],
            description: 'Moved deal ' . $deal->ssp_code . ' - ' . $deal->ssp_name . ' to Recycle Bin.'
        );

        // 5. Return JSON response
        return response()->json([
            'success' => true,
            'message' => 'Deal moved to Recycle Bin successfully.',
            'deal' => $dealData,
            'recycle_bin_id' => $binId,
        ], 200);
    }
}
