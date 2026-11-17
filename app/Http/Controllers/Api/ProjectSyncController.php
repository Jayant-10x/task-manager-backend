<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Models\ProjectMember;
use App\Models\User;
use App\Services\Audit\AuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProjectSyncController extends Controller
{
    /**
     * Handle incoming project webhook synchronization from 10x-pms-production.
     *
     * Protected by X-Sync-Secret header check.
     */
    public function handleProjectSync(Request $request): JsonResponse
    {
        // 1. Verify Secret Header (timing-safe check)
        $configuredSecret = (string) config('services.project_sync.secret');
        $incomingSecret = (string) $request->header('X-Sync-Secret');

        if (empty($configuredSecret) || empty($incomingSecret) || ! hash_equals($configuredSecret, $incomingSecret)) {
            return response()->json([
                'status' => false,
                'message' => 'Unauthorized: Invalid or missing sync secret header.',
            ], 401);
        }

        // 2. Validate Inbound Payload
        $validated = $request->validate([
            'source_project_id' => 'nullable|integer',
            'project_code' => 'required|string|max:40',
            'name' => 'required|string|min:1|max:160',
            'description' => 'nullable|string|max:5000',
            'project_type' => 'nullable|string|max:100',
            'customer_name' => 'nullable|string|max:160',
            'value' => 'nullable|numeric|min:0',
            'cost' => 'nullable|numeric|min:0',
            'start_date' => 'nullable|date',
            'delivery_date' => 'nullable|date',
            'status' => 'nullable|string|max:60',
            'health' => 'nullable|string|max:60',
            'approval_status' => 'nullable|string|max:60',
            'coordinator_email' => 'nullable|email|max:160',
            'tech_person_email' => 'nullable|email|max:160',
            'sales_person_email' => 'nullable|email|max:160',
            'creator_email' => 'nullable|email|max:160',
        ]);

        // 3. Idempotency Guard (prevent duplicate projects from retry calls)
        $pmsCode = trim($validated['project_code']);
        $pmsRefTag = '[PMS Ref: ' . $pmsCode . ']';

        $existingProject = Project::where('pro_pms_code', $pmsCode)
            ->orWhere('pro_description', 'like', "%{$pmsRefTag}%")
            ->first();

        if ($existingProject) {
            $changed = false;
            if (empty($existingProject->pro_pms_code)) {
                $existingProject->pro_pms_code = $pmsCode;
                $changed = true;
            }
            if (! empty($validated['name']) && $existingProject->pro_name !== $validated['name']) {
                $existingProject->pro_name = $validated['name'];
                $changed = true;
            }
            if (isset($validated['value']) && (float) $validated['value'] > 0 && (float) $existingProject->pro_budget !== (float) $validated['value']) {
                $existingProject->pro_budget = (float) $validated['value'];
                $changed = true;
            }
            if (! empty($validated['start_date']) && (string) $existingProject->pro_start_date !== (string) $validated['start_date']) {
                $existingProject->pro_start_date = $validated['start_date'];
                $changed = true;
            }
            if (! empty($validated['delivery_date']) && (string) $existingProject->pro_due_date !== (string) $validated['delivery_date']) {
                $existingProject->pro_due_date = $validated['delivery_date'];
                $changed = true;
            }
            if ($changed) {
                $existingProject->save();
            }

            return response()->json([
                'status' => true,
                'message' => 'Project already exists in TMS (idempotent).',
                'project' => $existingProject,
            ], 200);
        }

        // 4. Map Status & Health
        $statusInput = trim($validated['status'] ?? 'Onboarding');
        $healthInput = trim($validated['health'] ?? 'On Track');

        $mappedStatus = match ($statusInput) {
            'Onboarding' => 'planning',
            'In Progress' => ($healthInput === 'At Risk') ? 'at_risk' : 'in_progress',
            'On Hold', 'Cancelled' => 'on_hold',
            'Delivered' => 'completed',
            default => 'planning',
        };

        // 5. Construct Description with PMS Context
        $metaHeaders = [$pmsRefTag];
        if (! empty($validated['customer_name'])) {
            $metaHeaders[] = 'Client: ' . trim($validated['customer_name']);
        }
        if (! empty($validated['project_type'])) {
            $metaHeaders[] = 'Type: ' . trim($validated['project_type']);
        }

        $headerText = implode(' | ', $metaHeaders);
        $rawDescription = trim($validated['description'] ?? '');
        $combinedDescription = $rawDescription !== ''
            ? "{$headerText}\n\n{$rawDescription}"
            : $headerText;

        $proDescription = mb_substr($combinedDescription, 0, 3000);
        $proName = mb_substr(trim($validated['name']), 0, 120);

        // 6. Monotonically Generate Unique pro_code (P-xx)
        $lastProject = Project::withTrashed()->orderBy('pro_id', 'desc')->first();
        $nextNumber = 1;
        if ($lastProject && preg_match('/P-(\d+)/', (string) $lastProject->pro_code, $matches)) {
            $nextNumber = ((int) $matches[1]) + 1;
        } elseif ($lastProject) {
            $nextNumber = $lastProject->pro_id + 1;
        }

        $proCode = 'P-' . str_pad((string) $nextNumber, 2, '0', STR_PAD_LEFT);
        while (Project::withTrashed()->where('pro_code', $proCode)->exists()) {
            $nextNumber++;
            $proCode = 'P-' . str_pad((string) $nextNumber, 2, '0', STR_PAD_LEFT);
        }

        // 7. Resolve Project Manager User (Coordinator -> Tech -> Creator -> Sales -> Admin Fallback)
        $candidateEmails = array_values(array_filter([
            $validated['coordinator_email'] ?? null,
            $validated['tech_person_email'] ?? null,
            $validated['creator_email'] ?? null,
            $validated['sales_person_email'] ?? null,
        ]));

        $managerUser = null;
        foreach ($candidateEmails as $candidateEmail) {
            $found = User::where('usr_email', $candidateEmail)->first();
            if ($found) {
                $managerUser = $found;
                break;
            }
        }

        if (! $managerUser) {
            $managerUser = User::where('usr_is_super_admin', 1)->first()
                ?? User::where('usr_role', 'admin')->first()
                ?? User::first();
        }

        if (! $managerUser) {
            return response()->json([
                'status' => false,
                'message' => 'No active user found in TMS to assign as project manager.',
            ], 422);
        }

        // 8. Create Project Record
        $project = new Project();
        $project->pro_code = $proCode;
        $project->pro_pms_code = $pmsCode;
        $project->pro_name = $proName;
        $project->pro_description = $proDescription;
        $project->pro_manager = $managerUser->usr_id;
        $project->pro_status = $mappedStatus;
        $project->pro_progress = ($statusInput === 'Delivered') ? 100 : 0;
        $project->pro_start_date = $validated['start_date'] ?? null;
        $project->pro_due_date = $validated['delivery_date'] ?? null;
        $project->pro_budget = $validated['value'] ?? 0.00;
        $project->pro_created_by = 'webhook_sync~#' . $managerUser->usr_id . '~#' . ($request->ip() ?? '127.0.0.1');
        $project->pro_created_on = now();
        $project->save();

        // 9. Attach Team Members
        if (! empty($candidateEmails)) {
            $matchedUserIds = User::whereIn('usr_email', $candidateEmails)->pluck('usr_id')->toArray();
            $matchedUserIds[] = $managerUser->usr_id;
            $uniqueUserIds = array_unique($matchedUserIds);

            $memberRecords = [];
            foreach ($uniqueUserIds as $usrId) {
                $memberRecords[] = [
                    'pmb_usr_id' => $usrId,
                    'pmb_pro_id' => $project->pro_id,
                ];
            }

            if (! empty($memberRecords)) {
                ProjectMember::insert($memberRecords);
            }
        }

        // 10. Audit Logging
        $logMembers = ProjectMember::query()
            ->with('member:usr_id,usr_name')
            ->where('pmb_pro_id', $project->pro_id)
            ->get()
            ->pluck('member.usr_name', 'member.usr_id')
            ->toArray();

        app(AuditService::class)->log(
            action: 'create',
            module: 'project',
            record: $project->pro_code,
            oldValues: [],
            newValues: [
                'name' => $project->pro_name,
                'owner' => $project->pro_manager,
                'status' => config('constants.PROJECT_STATUS')[$project->pro_status] ?? $project->pro_status,
                'members' => ! empty($logMembers) ? '[' . implode(', ', $logMembers) . ']' : '',
                'source' => '10X PMS Webhook Sync',
                'pms_code' => $validated['project_code'],
            ],
            description: 'Synced project ' . $project->pro_code . ' from 10X PMS (' . $validated['project_code'] . ')',
        );

        return response()->json([
            'status' => true,
            'message' => 'Project synced successfully.',
            'project' => $project,
        ], 201);
    }
}
