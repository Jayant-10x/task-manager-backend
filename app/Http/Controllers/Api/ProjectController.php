<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Models\ProjectMember;
use App\Models\SubTask;
use App\Services\Audit\AuditService;
use App\Services\RecycleBinService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ProjectController extends Controller {

    protected RecycleBinService $recycleBinService;

    public function __construct (RecycleBinService $recycleBinService) {
        $this->recycleBinService = $recycleBinService;
    }

    public function index (Request $request) {
        $loggedInUserId = get_logged_in_user_id();

        // 1. Build Base Query for User Permissions
        $baseQuery = Project::query();
        if (!is_admin()) {
            $teamIds = array_unique(array_merge([$loggedInUserId], get_users_children_in_depth($loggedInUserId, true) ?? []));
            $baseQuery->where(function ($q) use ($teamIds) {
                $q->whereIn('pro_manager', $teamIds)
                  ->orWhereHas('projectMembers', function ($mq) use ($teamIds) {
                      $mq->whereIn('pmb_usr_id', $teamIds);
                  })
                  ->orWhereHas('tasks.assignees', function ($tq) use ($teamIds) {
                      $tq->whereIn('tsa_usr_id', $teamIds)->whereNull('tsa_deleted_on');
                  });
            });
        }

        // 2. Compute Summary Tab Counts for Header Badges
        $summary = [
            'all' => (clone $baseQuery)->count(),
            'active' => (clone $baseQuery)->whereIn('pro_status', ['in_progress', 'planning'])->count(),
            'at_risk' => (clone $baseQuery)->where('pro_status', 'at_risk')->count(),
            'completed' => (clone $baseQuery)->where('pro_status', 'completed')->count(),
        ];

        // 3. Eager Load Relations and Counts for Project Cards
        $today = Carbon::today();

        $projects = $baseQuery
            ->with([
                'projectManager:usr_id,usr_name,usr_avatar,usr_designation,usr_role',
                'projectMembers.member:usr_id,usr_name,usr_avatar,usr_designation,usr_role',
            ])
            ->withCount([
                // Count open tasks (to_do, in_progress, on_hold, review)
                'tasks as open_tasks_count' => function ($query) {
                    $query->whereIn('tsk_status', ['to_do', 'in_progress', 'on_hold', 'in_review']);
                },
                // Count overdue tasks (not completed/canceled and past due date)
                'tasks as overdue_tasks_count' => function ($query) use ($today) {
                    $query->whereNotIn('tsk_status', ['completed', 'cancelled'])
                        ->whereNotNull('tsk_due_date')
                        ->where('tsk_due_date', '<', $today);
                },
                // Count total subtasks across project tasks
                'tasks as total_subtasks_count' => function ($query) {
                    $query->whereHas('subtasks');
                },
            ])
            ->orderBy('pro_created_on', 'desc')
            ->get();

        // 4. Map & Format Project Data
        $formattedProjects = $projects->map(function ($project) {
            // Collect unique assignees from project members
            $assignees = $project->projectMembers
                ->pluck('member')
                ->filter()
                ->unique('usr_id')
                ->values()
                ->map(function ($user) {
                    $names = explode(' ', trim($user->usr_name));
                    $initials = count($names) >= 2
                        ? strtoupper(substr($names[0], 0, 1) . substr(end($names), 0, 1))
                        : strtoupper(substr($names[0], 0, 2));

                    return [
                        'usr_id' => $user->usr_id,
                        'usr_name' => $user->usr_name,
                        'avatar_initials' => $initials,
                    ];
                });

            // Compute done subtasks count directly from subtasks relation (excluding soft-deleted tasks/subtasks)
            $doneSubtasksCount = SubTask::whereHas('parentTask', function ($q) use ($project) {
                $q->where('tsk_pro_id', $project->pro_id);
            })->where('stk_done', 1)->count();

            $totalSubtasksCount = SubTask::whereHas('parentTask', function ($q) use ($project) {
                $q->where('tsk_pro_id', $project->pro_id);
            })->count();

            return [
                'pro_id' => $project->pro_id,
                'pro_code' => $project->pro_code ?? ('P-' . str_pad($project->pro_id, 2, '0', STR_PAD_LEFT)),
                'pro_title' => $project->pro_name,
                'pro_description' => $project->pro_description,
                'pro_status' => $project->pro_status,
                'pro_status_label' => ucwords(str_replace('_', ' ', $project->pro_status)),
                'progress_percentage' => (int)($project->pro_progress ?? 0),
                'open_tasks_count' => $project->open_tasks_count,
                'done_subtasks_count' => $doneSubtasksCount,
                'total_subtasks_count' => $totalSubtasksCount,
                'overdue_tasks_count' => $project->overdue_tasks_count,
                'start_date' => $project->pro_start_date ?? null,
                'due_date' => $project->pro_due_date ?? null,
                'pro_budget' => $project->pro_budget ?? 0.00,
                'pro_updated_on' => $project->pro_updated_on ?? $project->pro_created_on ?? null,
                'pro_created_on' => $project->pro_created_on ?? null,
                'project_manager' => $project->projectManager ? [
                    'usr_id' => $project->projectManager->usr_id,
                    'usr_name' => $project->projectManager->usr_name,
                ] : null,
                'assignees' => $assignees,
            ];
        });

        return response()->json([
            'status' => true,
            'message' => 'All Projects Found.',
            'summary' => $summary,
            'data' => $formattedProjects,
        ]);
    }

    protected function findProjectByAnyId($proId)
    {
        if (empty($proId)) {
            return null;
        }

        $numericId = is_numeric($proId) ? (int)$proId : (int)str_replace('P-', '', (string)$proId);

        return Project::where(function ($q) use ($proId, $numericId) {
            $q->where('pro_id', $numericId)
              ->orWhere('pro_code', (string)$proId)
              ->orWhere('pro_code', 'P-' . (string)$proId)
              ->orWhere('pro_code', 'P-' . str_pad((string)$numericId, 2, '0', STR_PAD_LEFT));
        })->first();
    }

    public function viewProject ($pro_id) {
        $projectFound = $this->findProjectByAnyId($pro_id);
        if (!$projectFound) {
            return response()->json([
                'status' => false,
                'message' => 'Project Not Found.',
            ], 404);
        }

        if (!is_admin()) {
            $loggedInUserId = get_logged_in_user_id();
            $teamIds = array_unique(array_merge([$loggedInUserId], get_users_children_in_depth($loggedInUserId, true) ?? []));
            $hasAccess = in_array((int)$projectFound->pro_manager, array_map('intval', $teamIds), true)
                || $projectFound->projectMembers()->whereIn('pmb_usr_id', $teamIds)->exists()
                || $projectFound->tasks()->whereHas('assignees', fn($q) => $q->whereIn('tsa_usr_id', $teamIds)->whereNull('tsa_deleted_on'))->exists();
            if (!$hasAccess) {
                return response()->json([
                    'status' => false,
                    'message' => 'Forbidden. You do not have access to this project.',
                ], 403);
            }
        }

        $numericProId = $projectFound->pro_id;
        $project_data = Project::query()
            ->with([
                'projectManager:usr_id,usr_name,usr_avatar,usr_designation,usr_role',
                'tasks' => function ($query) {
                    $query->select('tsk_id', 'tsk_code', 'tsk_title', 'tsk_tags', 'tsk_priority', 'tsk_status', 'tsk_due_date', 'tsk_pro_id', 'tsk_category', 'tsk_assigned_by')
                        ->with(['project:pro_id,pro_code,pro_name', 'assignedBy:usr_id,usr_name,usr_avatar,usr_designation,usr_role', 'subtasks:stk_tsk_id,stk_id,stk_title,stk_done'])
                        ->withCount([
                            'subtasks as total_subtask',
                            'subtasks as done_subtask' => function ($subQuery) {
                                $subQuery->where('stk_done', 1);
                            }
                        ]);
                },
                'tasks.assignees.assignedUser:usr_id,usr_name,usr_avatar,usr_designation,usr_role',
                'projectMembers' => function ($query) {
                    $query->select('pmb_pro_id', 'pmb_usr_id');
                },

                'projectMembers.member' => function ($query) {
                    $query->select('usr_id', 'usr_name', 'usr_designation', 'usr_avatar', 'usr_role')
                        ->withCount('userOpenTasks');
                },
            ])
            ->where('pro_id', $numericProId)
            ->first();

        if ($project_data) {
            $today = Carbon::today();

            // Map through tasks to append 'is_delayed', then key by 'tsk_id'
            $keyedTasks = $project_data->tasks->map(function ($task) use ($today) {
                $isDelayed = false;

                // Check if due date exists, status is not completed, and due date is past today
                if (!empty($task->tsk_due_date) && $task->tsk_status !== 'completed') {
                    $isDelayed = Carbon::parse($task->tsk_due_date)->lt($today);
                }

                $task->is_delayed = $isDelayed;

                return $task;
            })->keyBy('tsk_id');

            // Replace tasks relation with the transformed collection
            $project_data->setRelation('tasks', $keyedTasks);
        }
        // Total project subtasks aggregated in PHP:
        $totalTasks = count($project_data->tasks);
        $doneTasks = $project_data->tasks->filter(function ($task) {
            return $task->tsk_status == 'completed';
        })->count();

        $openTasks = $project_data->tasks->filter(function ($task) {
            return in_array($task->tsk_status, ['to_do', 'in_progress', 'on_hold', 'in_review']);
        })->count();

        $dueTasks = $project_data->tasks->filter(function ($task) {
            return $task->is_delayed == true;
        })->count();

        //status_wise statistics
        $to_do = $project_data->tasks->filter(function ($task) {
            return ($task->tsk_status == 'to_do');
        })->count();
        $in_progress = $project_data->tasks->filter(function ($task) {
            return ($task->tsk_status == 'in_progress');
        })->count();
        $on_hold = $project_data->tasks->filter(function ($task) {
            return ($task->tsk_status == 'on_hold');
        })->count();
        $in_review = $project_data->tasks->filter(function ($task) {
            return ($task->tsk_status == 'in_review');
        })->count();
        $completed = $project_data->tasks->filter(function ($task) {
            return ($task->tsk_status == 'completed');
        })->count();
        $cancelled = $project_data->tasks->filter(function ($task) {
            return ($task->tsk_status == 'cancelled');
        })->count();
        $status_breakdown_count = [
            'to_do' => $to_do,
            'in_progress' => $in_progress,
            'on_hold' => $on_hold,
            'in_review' => $in_review,
            'completed' => $completed,
            'cancelled' => $cancelled,
        ];

        if (!empty($project_data)) {
            $by_task_percentage = 0;
            if ($totalTasks > 0) {
                $by_task_percentage = $doneTasks / $totalTasks * 100;
            }
            return response()->json([
                'status' => true,
                'message' => 'View Project.',
                'data' => [
                    'project_data' => $project_data,
                    'total_tasks' => $totalTasks,
                    'done_tasks' => $doneTasks,
                    'open_tasks' => $openTasks,
                    'due_tasks' => $dueTasks,
                    'by_tasks' => $by_task_percentage,
                    'status_breakdown' => $status_breakdown_count,
                ],
            ], 200
            );
        } else {
            return response()->json([
                'status' => false,
                'message' => 'Project Not Found.',
            ], 404);
        }
    }

    public function storeProject (Request $request) {
        $request->validate([
            'name' => 'required|string|min:3|max:120',
            'description' => 'nullable|min:3|max:3000',
            'manager' => 'required|exists:users,usr_id',
            'status' => 'required|in:' . implode(',', array_keys(config('constants.PROJECT_STATUS'))),
            'progress' => 'nullable|numeric|min:0|max:100',
            'start_date' => 'nullable|date',
            'due_date' => 'nullable|date',
            'budget' => 'nullable|decimal:0,2|min:0',
            'project_members' => 'nullable|array',
            'project_members.*' => 'exists:users,usr_id',
        ]);

        $lastProject = Project::orderBy('pro_id', 'desc')->first();

        $nextNumber = $lastProject
            ? ((int)str_replace('P-', '', $lastProject->pro_code)) + 1
            : 1;

        $pro_code = 'P-' . str_pad($nextNumber, 2, '0', STR_PAD_LEFT);

        $projectObj = new Project();
        $projectObj->pro_code = $pro_code;
        $projectObj->pro_name = $request->input('name');
        $projectObj->pro_description = $request->input('description') ?? null;
        $projectObj->pro_manager = $request->input('manager');
        $projectObj->pro_status = $request->input('status');
        $projectObj->pro_progress = $request->input('progress') ?? 0;
        $projectObj->pro_start_date = $request->input('start_date') ?? null;
        $projectObj->pro_due_date = $request->input('due_date') ?? null;
        $projectObj->pro_budget = $request->input('budget') ?? 0;
        $projectObj->pro_created_by = setCreatedUpdatedBy();
        $projectObj->pro_created_on = now();

        $isProjectSaved = $projectObj->save();

        if ($isProjectSaved) {
            $members = [];
            // project team member store
            foreach ($request->input('project_members', []) ?? [] as $member) {
                $members [] = [
                    'pmb_usr_id' => $member,
                    'pmb_pro_id' => $projectObj->pro_id,
                ];
            }
            if (!empty($members)) {
                ProjectMember::insert($members);
            }

            $log_members = ProjectMember::query()->with('member:usr_id,usr_name')->where('pmb_pro_id', $projectObj->pro_id)->get()->pluck('member.usr_name', 'member.usr_id')->toArray();

            //Log accurate states
            app(AuditService::class)->log(
                action: 'create',
                module: 'project',
                record: $projectObj->pro_code,
                oldValues: [],
                newValues: [
                    'name' => $projectObj->pro_name,
                    'owner' => $projectObj->pro_manager,
                    'status' => config('constants.PROJECT_STATUS')[$projectObj->pro_status],
                    'members' => !empty($log_members) ? '[' . implode(', ', $log_members) . ']' : '',
                ],
                description: 'Created project ' . $projectObj->pro_code . ' - ' . $projectObj->pro_name,
            );

            return response()->json([
                'status' => true,
                'message' => 'Project created successfully.',
                'project' => $projectObj
            ], 201);
        } else {
            return response()->json(['message' => 'Something went wrong.'], 500);
        }
    }

    public function updateProject (Request $request, $pro_id) {
        $projectExist = $this->findProjectByAnyId($pro_id);

        if (!$projectExist) {
            return response()->json(['message' => 'Project not found.'], 404);
        }

        $numericProId = $projectExist->pro_id;
        $request->validate([
            'name' => 'required|string|min:3|max:120',
            'description' => 'nullable|min:3|max:3000',
            'manager' => 'required|exists:users,usr_id',
            'status' => 'required|in:' . implode(',', array_keys(config('constants.PROJECT_STATUS'))),
            'progress' => 'nullable|numeric|min:0|max:100',
            'start_date' => 'nullable|date',
            'due_date' => 'nullable|date',
            'budget' => 'nullable|decimal:0,2|min:0',
            'project_members' => 'nullable|array',
            'project_members.*' => 'exists:users,usr_id',
        ]);

        return DB::transaction(function () use ($request, $projectExist, $numericProId) {
            // 1. Get existing member IDs and Name Map BEFORE deletion
            $existingMemberIds = ProjectMember::where('pmb_pro_id', $numericProId)
                ->pluck('pmb_usr_id')
                ->toArray();

            $oldMemberNames = ProjectMember::with('member:usr_id,usr_name')
                ->where('pmb_pro_id', $numericProId)
                ->get()
                ->pluck('member.usr_name', 'member.usr_id')
                ->toArray();

            $originalValues = $projectExist->getRawOriginal();

            // 2. Update project instance
            $projectExist->pro_name = $request->input('name');
            $projectExist->pro_description = $request->input('description') ?? null;
            $projectExist->pro_manager = $request->input('manager');
            $projectExist->pro_status = $request->input('status');
            $projectExist->pro_progress = $request->input('progress') ?? 0;
            $projectExist->pro_start_date = $request->input('start_date') ?? null;
            $projectExist->pro_due_date = $request->input('due_date') ?? null;
            $projectExist->pro_budget = $request->input('budget') ?? 0;
            $projectExist->pro_updated_by = setCreatedUpdatedBy();
            $projectExist->pro_updated_on = now();

            $projectExist->save();

            // 3. Sync members
            ProjectMember::where('pmb_pro_id', $numericProId)->forceDelete();

            $newMemberIds = $request->input('project_members', []);

            $members = [];
            if (!empty($newMemberIds)) {
                foreach ($newMemberIds as $member) {
                    $members[] = [
                        'pmb_usr_id' => $member,
                        'pmb_pro_id' => $projectExist->pro_id,
                    ];
                }

                if (!empty($members)) {
                    ProjectMember::insert($members);
                }
            }

            // 4. Fetch new member names
            $newMemberNames = ProjectMember::with('member:usr_id,usr_name')
                ->where('pmb_pro_id', $projectExist->pro_id)
                ->get()
                ->pluck('member.usr_name', 'member.usr_id')
                ->toArray();

            // 5. Calculate scalar attribute changes
            $changes = $projectExist->getChanges();
            unset(
                $changes['pro_updated_by'],
                $changes['pro_updated_on'],
                $changes['pro_created_by'],
                $changes['pro_created_on']
            );

            $oldValues = [];
            $newValues = [];

            foreach ($changes as $field => $newValue) {
                $oldValue = $originalValues[$field] ?? null;
                if ($oldValue != $newValue) {
                    $oldValues[$field] = $oldValue;
                    $newValues[$field] = $newValue;
                }
            }

            // 6. Compare old and new member lists
            sort($existingMemberIds);
            sort($newMemberIds);

            if ($existingMemberIds !== $newMemberIds) {
                $oldValues['members'] = !empty($oldMemberNames) ? '[' . implode(', ', $oldMemberNames) . ']' : '[]';
                $newValues['members'] = !empty($newMemberNames) ? '[' . implode(', ', $newMemberNames) . ']' : '[]';
            }

            // 7. Log Audit
            app(AuditService::class)->log(
                action: 'update',
                module: 'project',
                record: $projectExist->pro_code,
                oldValues: $oldValues,
                newValues: $newValues,
                description: 'Updated project ' . $projectExist->pro_code . ' - ' . $projectExist->pro_name
            );

            return response()->json([
                'status' => true,
                'message' => 'Project updated successfully.',
                'project' => $projectExist
            ], 200);
        });
    }

    public function deleteProject (Request $request, $pro_id) {
        $user = $request->user();

        // 1. Fetch the project model
        $project = $this->findProjectByAnyId($pro_id);

        if (!$project) {
            return response()->json([
                'status' => false,
                'message' => 'Project not found.',
            ], 404);
        }

        // 2. Delegate snapshotting, cascading deletes, and database transaction to RecycleBinService
        $binId = $this->recycleBinService->recycleProject(
            $user,
            $project->toArray(),
            $request->input('reason', 'Project requested deletion')
        );

        // 3. Log Audit Trail
        app(AuditService::class)->log(
            action: 'delete',
            module: 'project',
            record: $project->pro_code,
            oldValues: [
                'name' => $project->pro_name,
                'status' => config('constants.PROJECT_STATUS')[$project->pro_status] ?? $project->pro_status,
            ],
            newValues: [
                'recycle_bin_id' => $binId,
            ],
            description: 'Moved project ' . $project->pro_code . ' - ' . $project->pro_name . ' to the Recycle Bin.'
        );

        // 4. Return API Response
        return response()->json([
            'status' => true,
            'message' => 'Project deleted successfully.',
            'recycle_bin_id' => $binId,
        ], 200);
    }
}
