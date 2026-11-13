<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Attachment;
use App\Models\Project;
use App\Models\SubTask;
use App\Models\Task;
use App\Models\TaskAssignee;
use App\Models\TaskComment;
use App\Models\User;
use App\Services\Audit\AuditService;
use App\Services\RecycleBinService;
use App\Services\TaskActivityService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class TaskController extends Controller {

    protected RecycleBinService $recycleBinService;

    public function __construct (RecycleBinService $recycleBinService) {
        $this->recycleBinService = $recycleBinService;
    }

    public function myTasks (Request $request) {
        $loggedInUserId = get_logged_in_user_id();
        $all_my_tasks = Task::with([
            'assignedBy:usr_id,usr_name,usr_avatar,usr_designation,usr_role',
            'assignees.assignedUser:usr_id,usr_name,usr_avatar,usr_designation,usr_role',
            'subtasks:stk_tsk_id,stk_id,stk_title',
            'project:pro_id,pro_code,pro_name'
        ])
            ->withCount('comments')
            ->whereHas('assignees', function ($query) use ($loggedInUserId) {
                $query->where('tsa_usr_id', $loggedInUserId);
            })->orderBy('tsk_created_on')->get();

        $projectIds = $all_my_tasks->pluck('tsk_pro_id')->unique()->filter()->values();

        $projects = Project::query()
            ->whereIn('pro_id', $projectIds)
            ->pluck('pro_name', 'pro_id')
            ->toArray();
        return response()->json([
            'all_my_tasks' => $all_my_tasks,
            'my_including_projects' => $projects
        ]);
    }

    public function assignedByMe (Request $request) {
        $loggedInUserChildren = get_users_children_in_depth(get_logged_in_user_id(), true);

        $task_assigned_by_me = Task::with([
            'assignedBy:usr_id,usr_name,usr_avatar,usr_designation,usr_role',
            'assignees.assignedUser:usr_id,usr_name,usr_avatar,usr_designation,usr_role',
            'subtasks:stk_tsk_id,stk_id,stk_title',
            'project:pro_id,pro_code,pro_name'
        ])->withCount('comments')->where('tasks.tsk_assigned_by', '=', get_logged_in_user_id())->orWhereIn('tasks.tsk_assigned_by', array_merge($loggedInUserChildren, [get_logged_in_user_id()]))->get();
        $projectIds = $task_assigned_by_me->pluck('tsk_pro_id')->unique()->filter()->values();

        $projects = Project::query()
            ->whereIn('pro_id', $projectIds)
            ->pluck('pro_name', 'pro_id')
            ->toArray();

        return response()->json([
            'assigned_by_me' => $task_assigned_by_me,
            'assigned_projects' => $projects,
        ]);
    }


    public function taskBoard (Request $request) {
        $loggedInUserId = get_logged_in_user_id();

        $loggedInUserChildren = get_users_children_in_depth($loggedInUserId, true);
        $taskQuery = Task::query()->with([
            'assignedBy:usr_id,usr_name,usr_avatar,usr_designation,usr_role',
            'assignees.assignedUser:usr_id,usr_name,usr_avatar,usr_designation,usr_role',
            'subtasks:stk_tsk_id,stk_id,stk_title',
        ]);

        if (!is_admin()) {
            $taskQuery->where(function ($query) use ($loggedInUserId, $loggedInUserChildren) {
                $query->where('tasks.tsk_assigned_by', $loggedInUserId)->orWhereIn('tasks.tsk_assigned_by', $loggedInUserChildren);
            })->orWhereHas('assignees', function ($query) use ($loggedInUserId, $loggedInUserChildren
            ) {
                $query->where('tsa_usr_id', $loggedInUserId)->orWhereIn('tsa_usr_id', $loggedInUserChildren);
            });
        }

        $allTasks = $taskQuery->orderBy('tsk_created_on')->get()->unique('tsk_id')->values();

        // Get project names for projects referenced by these tasks.
        $projectIds = $allTasks->pluck('tsk_pro_id')->filter()->unique()->values();
        $projects = Project::query()->whereIn('pro_id', $projectIds)->pluck('pro_name', 'pro_id');
        logger($allTasks);
        return response()->json([
            'tasks' => $allTasks,
            'projects' => $projects,
        ]);
    }

    public function calendarTasks (Request $request) {
        $loggedInUserId = get_logged_in_user_id();
        $loggedInUserChildren = get_users_children_in_depth($loggedInUserId, true);

        $taskQuery = Task::query()->whereNotNull('tsk_start_date')->whereNotNull('tsk_due_date')->select('tasks.*')
            ->selectRaw('CASE WHEN tsk_due_date < CURDATE() THEN TRUE ELSE FALSE END AS is_delayed');

        // Apply user access restrictions for non-admins.
        if (!is_admin()) {
            $taskQuery->where(function ($query) use ($loggedInUserId, $loggedInUserChildren) {
                $userIds = array_unique(array_merge([$loggedInUserId], $loggedInUserChildren));
                $query->whereIn('tsk_assigned_by', $userIds)
                    ->orWhereHas('assignees', function ($query) use ($loggedInUserId, $loggedInUserChildren) {
                        $query->where('tsa_usr_id', $loggedInUserId)
                            ->orWhereIn('tsa_usr_id', $loggedInUserChildren);
                    });
            });
        }

        $tasks = $taskQuery->orderBy('tsk_due_date')->orderBy('tsk_id')->get();

        // Group the returned collection by due date.
        $dateGroupedTasks = $tasks->groupBy(function ($task) {
            return $task->tsk_due_date;
        });

        return response()->json($dateGroupedTasks);
    }

    public function createTask () {
        return response()->json([
            'assignees' => $this->getTaskAssigneeForSuggestion(),
            'projects' => $this->getProjectsForSuggestion()
        ]);
    }

    public function saveTask (Request $request) {
        $validated = $request->validate([
            'title' => 'required|min:2|max:200',
            'description' => 'nullable|min:2|max:3000',
            'pro_name' => 'nullable|string|min:2|max:120',
            'category' => 'required|in:' . implode(',', array_keys(config('constants.TASK_CATEGORY'))),
            'custom_remark' => 'required_if:category,custom_remark|nullable|string|max:200',
            'priority' => 'required|in:' . implode(',', array_keys(config('constants.TASK_PRIORITY'))),
            'status' => 'required|in:' . implode(',', array_keys(config('constants.TASK_STATUS'))),
            'start_date' => 'nullable|date',
            'due_date' => 'nullable|date',
            'est_hours' => 'nullable|numeric',
            //            'spent_hours' => 'nullable|numeric',
            'tags' => 'nullable|min:2|max:250',
            //            'progress' => 'required|min:0|max:100',
            'assignee_ids' => 'required|array|',
            'assignee_ids.*' => 'exists:users,usr_id',
            'checklist' => 'nullable|array',
            'checklist.*.title' => 'nullable|string|min:2|max:200',
            'checklist.*.done' => 'nullable',
        ]);
        $task = null;
        DB::transaction(function () use ($validated, &$task) {
            $current_date = date(config('constants.DB_DATE_TIME_FORMAT'));
            $lastTask = Task::orderBy('tsk_id', 'desc')->first();

            $nextNumber = $lastTask
                ? ((int)str_replace('TK-', '', $lastTask->tsk_code)) + 1
                : 1;

            $task_code = 'TK-' . str_pad($nextNumber, 2, '0', STR_PAD_LEFT);

            $task = new Task();
            $task->tsk_code = $task_code;
            $task->tsk_title = $validated['title'];
            $task->tsk_description = $validated['description'] ?? null;
            $task->tsk_pro_id = !empty($validated['pro_name']) ? $this->getOrCreateProjectForTask($validated['pro_name']) : null;
            $task->tsk_category = $validated['category'];
            $task->tsk_priority = $validated['priority'];
            $task->tsk_status = $validated['status'];
            $task->tsk_start_date = $validated['start_date'] ?? null;
            $task->tsk_due_date = $validated['due_date'] ?? null;
            $task->tsk_est_hours = $validated['est_hours'] ?? 0.0;
            $task->tsk_spent_hours = $validated['spent_hours'] ?? 0.0;
            $task->tsk_tags = $validated['tags'] ?? '';
            $task->tsk_progress = $validated['progress'] ?? 0;
            $task->tsk_assigned_by = get_logged_in_user_id();
            $task->tsk_created_by = setCreatedUpdatedBy();
            $task->tsk_created_on = $current_date;
            $is_task_saved = $task->save();

            if ($is_task_saved) {
                $assignees = $checklists = [];
                foreach ($validated['assignee_ids'] as $assignee) {
                    $assignees[] = [
                        'tsa_tsk_id' => $task->tsk_id,
                        'tsa_usr_id' => $assignee
                    ];
                }

                TaskAssignee::insert($assignees);

                if (!empty($validated['checklist'])) {
                    foreach ($validated['checklist'] as $checklist) {
                        $checklists[] = [
                            'stk_title' => $checklist['title'],
                            'stk_tsk_id' => $task->tsk_id,
                            'stk_done' => $checklist['done'],
                            'stk_updated_by' => setCreatedUpdatedBy(),
                            'stk_updated_on' => $current_date,
                        ];
                    }
                    SubTask::insert($checklists);
                }
            }
            $changes = $task->getRawOriginal();
            unset(
                $changes['tsk_created_by'],
                $changes['tsk_created_on'],
                $changes['tsk_deleted_on']
            );

            (new TaskActivityService())->created($task->tsk_id, get_logged_in_user_id());

            app(AuditService::class)->log(
                action: 'created',
                module: 'task',
                record: $task->tsk_id,
                oldValues: [],
                newValues: $changes,
                description: 'Task created.'
            );
        });

        $notification_data = [
            'kind' => 'task_assigned',
            'title' => 'Task assigned',
            'body' => 'New task, ' . $task->tsk_title . ' (' . $task->tsk_id . ') assigned to you.',
            'actor' => get_logged_in_user_name(),
            'redirect_to' => route('task.view', ['tsk_id' => $task->tsk_id]),
            'extra_params' => [
                'tsk_id' => $task->tsk_id,
                'tsk_title' => $task->tsk_title,
                'tsk_code' => $task->tsk_code,
            ]
        ];
        foreach ($validated['assignee_ids'] as $assignee) {
            if ($assignee == get_logged_in_user_id())
                continue;
            $notification_data['receiver_id'] = $assignee;
            sendNotification($notification_data);
        }

        return response()->json([
            'message' => 'Task created successfully.',
            'savedTask' => $task,
        ], 201);
    }

    protected function findTaskByAnyId($taskId, $withTrashed = false)
    {
        if (empty($taskId)) {
            return null;
        }

        $numericId = is_numeric($taskId) ? (int)$taskId : (int)str_replace('TK-', '', (string)$taskId);

        $query = $withTrashed ? Task::withTrashed() : Task::query();

        return $query->where(function ($q) use ($taskId, $numericId) {
            $q->where('tsk_id', $numericId)
              ->orWhere('tsk_code', (string)$taskId)
              ->orWhere('tsk_code', 'TK-' . (string)$taskId)
              ->orWhere('tsk_code', 'TK-' . str_pad((string)$numericId, 2, '0', STR_PAD_LEFT));
        })->first();
    }

    public function editTask ($task_id) {
        if (!empty($task_id)) {
            $task = $this->findTaskByAnyId($task_id);
            if (!empty($task)) {
                $task_data = Task::with(['assignees', 'subtasks:stk_id,stk_title,stk_tsk_id'])->where('tsk_id', '=', $task->tsk_id)->first();
                return response()->json([
                    'message' => 'Task found.',
                    'task_data' => $task_data,
                ], 200);
            } else {
                return response()->json([
                    'message' => 'Task not found.',
                ], 404);
            }
        } else {
            return response()->json([
                'message' => 'Something went wrong.',
            ], 500);
        }
    }

    public function viewTask ($task_id) {
        if (!empty($task_id)) {
            $task = $this->findTaskByAnyId($task_id);
            if (!empty($task)) {
                $task_data = Task::with([
                    'assignedBy:usr_id,usr_name,usr_avatar,usr_designation,usr_role',
                    'assignees.assignedUser:usr_name,usr_designation,usr_role,usr_id,usr_avatar',
                    'subtasks',
                    'comments.commentAuthor:usr_id,usr_name,usr_avatar,usr_designation,usr_role',
                    'attachments:atc_id,atc_tsk_id,atc_file_name,atc_stored_as,atc_size_kb,atc_created_at',
                    'activities.activist:usr_id,usr_name,usr_avatar,usr_designation,usr_role',
                    'project:pro_id,pro_code,pro_name'
                ])->where('tsk_id', '=', $task->tsk_id)->first();
                return response()->json([
                    'message' => 'Task found.',
                    'task_data' => $task_data,
                ], 200);
            } else {
                return response()->json([
                    'message' => 'Task not found.',
                ], 404);
            }
        } else {
            return response()->json([
                'message' => 'Something went wrong.',
            ], 500);
        }
    }


    protected function canUserMutateTask($user, Task $task): bool
    {
        if (!$user) return false;
        if ($user->usr_is_super_admin || $user->usr_role === 'admin') return true;
        $teamIds = array_unique(array_merge([$user->usr_id], get_users_children_in_depth($user->usr_id, true) ?? []));
        if (in_array((int)$task->tsk_assigned_by, array_map('intval', $teamIds), true)) {
            return true;
        }
        return $task->assignees()
            ->whereIn('tsa_usr_id', $teamIds)
            ->whereNull('tsa_deleted_on')
            ->exists();
    }

    public function updateTask (Request $request, $task_id) {
        logger($request->all());

        $validated = $request->validate([
            'title' => 'required|min:2|max:200',
            'description' => 'nullable|min:2|max:3000',
            'pro_name' => 'nullable|string|min:2|max:120',
            'category' => 'required|in:' . implode(',', array_keys(config('constants.TASK_CATEGORY'))),
            'priority' => 'required|in:' . implode(',', array_keys(config('constants.TASK_PRIORITY'))),
            'status' => 'required|in:' . implode(',', array_keys(config('constants.TASK_STATUS'))),
            'start_date' => 'nullable|date',
            'due_date' => 'nullable|date',
            'est_hours' => 'nullable|numeric',
            'spent_hours' => 'nullable|numeric',
            'tags' => 'nullable|min:1|max:250',
            // 'progress' => 'required|min:0|max:100',
            'assignee_ids' => 'required|array|min:1',
            'assignee_ids.*' => 'exists:users,usr_id',

            'exist_checklist' => 'sometimes|array',
            'exist_checklist.*' => 'array',
            'exist_checklist.*.title' => 'required|string|min:2|max:200',
            'exist_checklist.*.done' => 'required|boolean',

            'checklist' => 'nullable|array',
            'checklist.*' => 'required|array',
            'checklist.*.title' => 'required|string|min:2|max:200',
            'checklist.*.done' => 'required|boolean',
        ]);

        $taskExist = $this->findTaskByAnyId($task_id);

        if (!$taskExist) {
            return response()->json([
                'message' => 'Task not found.',
            ], 404);
        }

        if (!$this->canUserMutateTask($request->user(), $taskExist)) {
            return response()->json([
                'message' => 'Forbidden. You can only modify tasks assigned to or created by you or your team.',
            ], 403);
        }

        $taskExist->loadMissing([
            'assignees',
            'subtasks:stk_id,stk_tsk_id,stk_title',
        ]);

        // Capture original values BEFORE changing the model
        $originalValues = $taskExist->getRawOriginal();

        $oldAssignees = TaskAssignee::query()
            ->where('tsa_tsk_id', $taskExist->tsk_id)
            ->pluck('tsa_usr_id')
            ->toArray();

        DB::transaction(function () use ($validated, $task_id, $request, $taskExist) {

            $current_date = date(config('constants.DB_DATE_TIME_FORMAT'));

            // Update main task
            $taskExist->fill([
                'tsk_title' => $validated['title'],
                'tsk_description' => $validated['description'] ?? null,
                'tsk_pro_id' => !empty($validated['pro_name']) ? $this->getOrCreateProjectForTask($validated['pro_name']) : null,
                'tsk_category' => $validated['category'],
                'tsk_priority' => $validated['priority'],
                'tsk_status' => $validated['status'],
                'tsk_start_date' => $validated['start_date'] ?? null,
                'tsk_due_date' => $validated['due_date'] ?? null,
                'tsk_est_hours' => $validated['est_hours'] ?? 0.0,
                'tsk_spent_hours' => $validated['spent_hours'] ?? 0.0,
                'tsk_tags' => $validated['tags'] ?? null,
                'tsk_completed_at' => $validated['status'] === 'completed'
                    ? ($taskExist->tsk_completed_at ?? now())
                    : null,
                // 'tsk_progress' => $validated['progress'] ?? 0,
                'tsk_updated_by' => setCreatedUpdatedBy(),
                'tsk_updated_on' => $current_date,
            ]);

            $taskExist->save();

            // 1. Force hard delete existing assignees (including soft-deleted ones if present)
            TaskAssignee::withTrashed()
                ->where('tsa_tsk_id', $taskExist->tsk_id)
                ->forceDelete();

            // 2. Prepare new assignees
            $assignees = [];

            foreach ($validated['assignee_ids'] as $assigneeId) {
                $assignees[] = [
                    'tsa_tsk_id' => $taskExist->tsk_id,
                    'tsa_usr_id' => $assigneeId,
                ];
            }

            // 3. Batch insert new assignees
            if (!empty($assignees)) {
                TaskAssignee::insert($assignees);
            }

            /*
 |--------------------------------------------------------------------------
 | Checklist management
 |--------------------------------------------------------------------------
 */

            $existingChecklist = $validated['exist_checklist'] ?? [];

            // IDs must belong to this task before they can be updated or retained.
            $remainingChecklistIds = [];

            foreach ($existingChecklist as $subtaskId => $item) {
                $subtask = SubTask::query()
                    ->where('stk_tsk_id', $taskExist->tsk_id)
                    ->where('stk_id', $subtaskId)
                    ->first();

                if (!$subtask) {
                    continue;
                }

                $title = trim($item['title']);

                $subtask->update([
                    'stk_title' => $title,
                    'stk_done' => (bool)$item['done'],
                    'stk_updated_by' => setCreatedUpdatedBy(),
                    'stk_updated_on' => $current_date,
                ]);

                $remainingChecklistIds[] = (int)$subtaskId;
            }

            // Delete existing items omitted from the submitted list.
            $deleteQuery = SubTask::query()
                ->where('stk_tsk_id', $taskExist->tsk_id);

            if (!empty($remainingChecklistIds)) {
                $deleteQuery->whereNotIn('stk_id', $remainingChecklistIds);
            }

            $deleteQuery->delete();

            // Insert new items.
            $newChecklist = $validated['checklist'] ?? [];
            $checklists = [];

            foreach ($newChecklist as $item) {
                $title = trim((string)$item['title']);

                if ($title === '') {
                    continue;
                }

                $checklists[] = [
                    'stk_title' => $title,
                    'stk_tsk_id' => $taskExist->tsk_id,
                    'stk_done' => (bool)$item['done'],
                    'stk_updated_by' => setCreatedUpdatedBy(),
                    'stk_updated_on' => $current_date,
                ];
            }

            if (!empty($checklists)) {
                SubTask::insert($checklists);
            }
        });

        // Get the fields actually changed by this save
        $changes = $taskExist->getChanges();

        // Exclude audit metadata from the business change history
        unset(
            $changes['tsk_updated_by'],
            $changes['tsk_updated_on'],
            $changes['tsk_created_by'],
            $changes['tsk_created_on'],
            $changes['tsk_deleted_on']
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

        // Check individual field changes safely
        if (array_key_exists('tsk_status', $newValues)) {
            (new TaskActivityService())->statusChanged(
                $taskExist->tsk_id,
                get_logged_in_user_id(),
                config('constants.TASK_STATUS')[$newValues['tsk_status']] ?? $newValues['tsk_status']
            );
        }

        if (array_key_exists('tsk_title', $newValues)) {
            (new TaskActivityService())->titleChanged(
                $taskExist->tsk_id,
                get_logged_in_user_id(),
                $newValues['tsk_title']
            );
        }

        if (array_key_exists('tsk_priority', $newValues)) {
            (new TaskActivityService())->priorityChanged(
                $taskExist->tsk_id,
                get_logged_in_user_id(),
                $newValues['tsk_priority']
            );
        }

        if (array_key_exists('tsk_due_date', $newValues)) {
            (new TaskActivityService())->dueDateChanged(
                $taskExist->tsk_id,
                get_logged_in_user_id(),
                $newValues['tsk_due_date']
            );
        }

        // Check assignees change
        $oldAssigneesList = array_map('intval', $oldAssignees);
        sort($oldAssigneesList);

        $newAssigneesList = array_map('intval', $validated['assignee_ids']);
        sort($newAssigneesList);

        if ($oldAssigneesList !== $newAssigneesList) {

            $assignees = [];

            foreach ($newAssigneesList as $newAssignee) {
                $assignees[] = get_user_data_by_id($newAssignee)->usr_name;
            }

            (new TaskActivityService())->assigned(
                $taskExist->tsk_id,
                get_logged_in_user_id(),
                $assignees
            );

            // Track assignees inside audit values as well
            $oldValues['assignees'] = $oldAssigneesList;
            $newValues['assignees'] = $newAssigneesList;
        }

        // (new TaskActivityService())->log($taskExist->tsk_id, get_logged_in_user_id(), 'updated', 'Task updated.');

        app(AuditService::class)->log(
            action: 'update',
            module: 'task',
            record: $taskExist->tsk_id,
            oldValues: $oldValues,
            newValues: $newValues,
            description: 'Task updated.'
        );

        if ($oldAssigneesList !== $newAssigneesList) {

            $notification_data = [
                'kind' => 'task_assigned',
                'title' => 'Task assigned',
                'body' => 'New task, ' . $taskExist->tsk_title . ' (' . ($taskExist->tsk_code ?? $taskExist->tsk_id) . ') assigned to you.',
                'actor' => get_logged_in_user_name(),
                'redirect_to' => route('task.view', ['tsk_id' => $taskExist->tsk_id]),
                'extra_params' => [
                    'tsk_id' => $taskExist->tsk_id,
                    'tsk_title' => $taskExist->tsk_title,
                    'tsk_code' => $taskExist->tsk_code,
                ],
            ];

            foreach ($newAssigneesList as $assignee) {

                if ($assignee == get_logged_in_user_id()) {
                    continue;
                }

                $notification_data['receiver_id'] = $assignee;

                sendNotification($notification_data);
            }
        }

        if (array_key_exists('tsk_status', $newValues)) {

            $notification_data = [
                'kind' => 'task_status',
                'title' => 'Task status changed',
                'body' => $taskExist->tsk_code . ' - ' . $taskExist->tsk_name . ' moved to ' . config('constants.TASK_STATUS')[$taskExist->tsk_status],
                'actor' => get_logged_in_user_name(),
                'redirect_to' => route('task.view', ['tsk_id' => $taskExist->tsk_id]),
                'extra_params' => [
                    'tsk_id' => $taskExist->tsk_id,
                    'tsk_title' => $taskExist->tsk_title,
                    'tsk_code' => $taskExist->tsk_code,
                    'tsk_status' => $taskExist->tsk_status,
                ],
            ];

            $task_assignees = $taskExist->assignees()->get()->toArray();

            if (!empty($task_assignees)) {

                foreach ($task_assignees as $user) {

                    if ($user['tsa_usr_id'] == get_logged_in_user_id()) {
                        continue;
                    }

                    $notification_data['receiver_id'] = $user['tsa_usr_id'];

                    sendNotification($notification_data);
                }
            }
        }

        return response()->json([
            'message' => 'Task updated successfully.',
            'updatedTask' => $taskExist,
        ], 200);
    }


    public function deleteTask (Request $request, $task_id) {
        $user = $request->user();

        // 1. Fetch task with relations before deletion
        $task = $this->findTaskByAnyId($task_id);

        if (!$task) {
            return response()->json([
                'status' => false,
                'message' => 'Task not found.',
            ], 404);
        }

        if (!$this->canUserMutateTask($user, $task)) {
            return response()->json([
                'status' => false,
                'message' => 'Forbidden. You do not have permission to delete this task.',
            ], 403);
        }

        // Capture task representation before deletion for audit and response payload
        $taskData = $task->toArray();

        // 2. Delegate snapshotting, cascading deletes, and DB transaction to RecycleBinService
        $binId = $this->recycleBinService->recycleTask(
            $user,
            $taskData,
            $request->input('reason', 'Task requested deletion')
        );

        // 3. Log Audit Trail
        app(AuditService::class)->log(
            action: 'delete',
            module: 'task',
            record: $task->tsk_id,
            oldValues: [
                'title' => $task->tsk_title ?? $task->tsk_name,
                'status' => $task->tsk_status,
            ],
            newValues: [
                'recycle_bin_id' => $binId,
            ],
            description: 'Moved Task ' . $task->tsk_code . ' - ' . ($task->tsk_title ?? $task->tsk_name) . ' to Recycle Bin.'
        );

        // 4. Return API Response
        return response()->json([
            'status' => true,
            'message' => 'Task deleted successfully.',
            'task' => $taskData,
            'recycle_bin_id' => $binId,
        ], 200);
    }

    public function deleteSubTask ($subtask_id) {
        $sub_task = SubTask::find($subtask_id);

        if (!$sub_task) {
            return response()->json([
                'message' => 'Sub Task not found.',
            ], 404);
        }

        DB::transaction(function () use ($sub_task) {
            $sub_task->delete();
        });

        return response()->json([
            'message' => 'Sub Task deleted successfully.',
        ], 200);
    }

    public function allTasks (Request $request) {
        $loggedInUserId = get_logged_in_user_id();

        $loggedInUserChildren = get_users_children_in_depth($loggedInUserId, true);
        $taskQuery = Task::query()->with([
            'assignedBy:usr_id,usr_name,usr_avatar,usr_designation,usr_role',
            'assignees.assignedUser:usr_id,usr_name,usr_avatar,usr_designation,usr_role',
            'subtasks:stk_tsk_id,stk_id,stk_title',
            'project:pro_id,pro_code,pro_name'
        ])->withCount('comments');
        if (!is_admin()) {
            $taskQuery->where(function ($query) use ($loggedInUserId, $loggedInUserChildren) {
                $query->where('tasks.tsk_assigned_by', $loggedInUserId)
                    ->orWhereIn('tasks.tsk_assigned_by', $loggedInUserChildren);
            })->orWhereHas('assignees', function ($query) use ($loggedInUserId, $loggedInUserChildren) {
                $query->where('tsa_usr_id', $loggedInUserId)
                    ->orWhereIn('tsa_usr_id', $loggedInUserChildren);
            });
        }
        $all_tasks = $taskQuery->orderBy('tsk_created_on')->get()->unique('tsk_id');
        return response()->json($all_tasks);
    }

    public function teamTasks (Request $request) {
        $loggedInUserId = get_logged_in_user_id();

        // 1. Get team members based on the logged-in user's role.
        if (is_admin()) {
            // Super Admin: include all users.
            $teamUsers = User::query()->get();
        } else {
            // Other users: include only their team members.
            $teamUserIds = get_users_children_in_depth($loggedInUserId, true);

            $teamUsers = User::whereIn('usr_id', $teamUserIds)->get();
        }

        // Use the selected users' IDs to retrieve their assigned tasks.
        $teamUserIds = $teamUsers->pluck('usr_id')->all();

        // 2. Retrieve tasks assigned to these users.
        $tasks = Task::with('assignees')
            ->whereHas('assignees', function ($query) use ($teamUserIds) {
                $query->whereIn('tsa_usr_id', $teamUserIds);
            })
            ->orderBy('tsk_created_on', 'desc')
            ->get();

        $today = Carbon::today();
        $result = [];

        // 3. Group tasks by team member.
        foreach ($teamUsers as $user) {
            $userId = $user->usr_id;

            // Get tasks assigned specifically to this user.
            $userTasks = $tasks->filter(function ($task) use ($userId) {
                return $task->assignees->contains('tsa_usr_id', $userId);
            });

            // Determine whether a task is overdue.
            $isTaskOverdue = function ($task) use ($today) {
                return !in_array($task->tsk_status, ['completed', 'cancelled'], true)
                    && !empty($task->tsk_due_date)
                    && Carbon::parse($task->tsk_due_date)->lt($today);
            };

            // Put overdue tasks first.
            $overdueTasks = $userTasks->filter($isTaskOverdue);
            $otherTasks = $userTasks->reject($isTaskOverdue);

            $sortedTasks = $overdueTasks->merge($otherTasks);

            // 4. Format the task list for React.
            $formattedTasks = [];

            foreach ($sortedTasks as $task) {
                $formattedTasks[$task->tsk_id] = [
                    'tsk_name' => $task->tsk_title,
                    'tsk_code' => $task->tsk_code,
                    'tsk_due_date' => $task->tsk_due_date,
                    'tsk_status' => $task->tsk_status,
                    'is_delayed' => $isTaskOverdue($task),
                ];
            }

            // 5. Calculate task statistics.
            $openTasksCount = $userTasks->whereIn('tsk_status', [
                'to_do',
                'in_progress',
                'on_hold',
                'review',
                'in_review',
            ])->count();

            $progressTasksCount = $userTasks
                ->where('tsk_status', 'in_progress')
                ->count();

            $completedTasksCount = $userTasks
                ->where('tsk_status', 'completed')
                ->count();

            // 6. Build the response for this user.
            $result[$userId] = [
                'usr_name' => $user->usr_name,
                'usr_avatar' => $user->usr_avatar,
                'usr_designation' => $user->usr_role == 'admin' ? 'Admin' : get_dash_on_empty($user->usr_designation),
                'tasks' => $formattedTasks,
                'open_tasks' => $openTasksCount,
                'progress_tasks' => $progressTasksCount,
                'completed_tasks' => $completedTasksCount,
            ];
        }

        // 7. Return the response in the existing React-compatible format.
        return response()->json($result);
    }

    public function teamMembers (Request $request) {
        $loggedInUserId = get_logged_in_user_id();

        /*
         * 1. Determine which users to display.
         * Super Admin: all users.
         * Other users: only users in their hierarchy.
         */
        if (is_admin()) {
            $teamUsers = User::query()->get();
        } else {
            $teamUserIds = get_users_children_in_depth($loggedInUserId, true);

            $teamUsers = User::query()
                ->whereIn('usr_id', $teamUserIds)
                ->get();
        }

        $teamUserIds = $teamUsers->pluck('usr_id')->all();

        /*
         * 2. Fetch tasks assigned to these users.
         * Exclude deleted tasks and deleted assignments if your
         * relationships do not already exclude them.
         */
        $tasks = Task::with(['assignees'])
            ->whereHas('assignees', function ($query) use ($teamUserIds) {
                $query->whereIn('tsa_usr_id', $teamUserIds)
                    ->whereNull('tsa_deleted_on');
            })
            ->get();

        $result = [];

        /*
         * 3. Build the existing response structure for each user.
         */
        foreach ($teamUsers as $user) {
            $userId = $user->usr_id;

            $userTasks = $tasks->filter(function ($task) use ($userId) {
                return $task->assignees->contains(function ($assignee) use ($userId) {
                    return (string)$assignee->tsa_usr_id === (string)$userId
                        && $assignee->tsa_deleted_on === null;
                });
            });

            $openTasksCount = $userTasks->whereIn('tsk_status', [
                'to_do',
                'in_progress',
                'in_review',
                'on_hold',
            ])->count();

            $completedTasksCount = $userTasks
                ->where('tsk_status', 'completed')
                ->count();

            $effortHours = $userTasks->sum('tsk_est_hours');

            $result[$userId] = [
                'member_name' => $user->usr_name,
                'member_designation' => get_dash_on_empty($user->usr_designation),
                'member_status' => $user->usr_status,
                'member_role' => $user->usr_role,
                'member_email' => get_dash_on_empty($user->usr_email),
                'member_phone' => get_dash_on_empty($user->usr_phone),
                'member_place' => get_dash_on_empty($user->usr_city),
                'member_dept' => $user->usr_dept,
                'member_custom_dept' => $user->usr_custom_dept,
                'usr_dept' => $user->usr_dept,
                'usr_custom_dept' => $user->usr_custom_dept,
                'usr_reporting_to' => $user->usr_reporting_to,
                'usr_joined' => $user->usr_joined,
                'usr_is_super_admin' => (bool)$user->usr_is_super_admin,
                'member_avatar' => $user->usr_avatar,
                'usr_avatar' => $user->usr_avatar,

                'task_statistics' => [
                    'open_tasks' => $openTasksCount,
                    'completed_tasks' => $completedTasksCount,
                    'effort_hrs' => $effortHours,
                ],
            ];
        }

        return response()->json($result);
    }

    public function updateTaskStatus (Request $request, $taskId) {
        $request->validate([
            'status' => 'required|in:' . implode(',', array_keys(config('constants.TASK_STATUS'))),
        ]);

        $task = $this->findTaskByAnyId($taskId, true);

        // 1. Doesn't exist at all
        if (!$task) {
            return response()->json([
                'message' => 'Task does not exist.'
            ], 404);
        }

        // 2. Exists, but is soft-deleted
        if ($task->trashed()) {
            return response()->json([
                'message' => 'Cannot update status because this task has been deleted.'
            ], 400); // 400 Bad Request or 410 Gone
        }

        if (!$this->canUserMutateTask($request->user(), $task)) {
            return response()->json([
                'message' => 'Forbidden. You can only change status of tasks assigned to or created by you or your team.'
            ], 403);
        }

        //old status
        $oldStatus = $task->tsk_status;

        $task->tsk_status = $request->status;
        $task->tsk_completed_at = ($request->status === 'completed')
            ? ($task->tsk_completed_at ?? now())
            : null;
        $task->save();

        (new TaskActivityService())->statusChanged($task->tsk_id, get_logged_in_user_id(), config('constants.TASK_STATUS')[$request->status]);

        //Log accurate states
        app(AuditService::class)->log(
            action: 'status',
            module: 'task',
            record: $task->tsk_id,
            oldValues: [
                'status' => $oldStatus,
            ],
            newValues: [
                'status' => $task->tsk_status,
            ],
            description: $task->tsk_code . ' moved to ' . config('constants.TASK_STATUS')[$task->tsk_status],
        );
        $notification_data = [
            'kind' => 'task_status',
            'title' => 'Task status changed',
            'body' => $task->tsk_code . ' - ' . $task->tsk_title . ' moved to ' . config('constants.TASK_STATUS')[$task->tsk_status],
            'actor' => get_logged_in_user_name(),
            'redirect_to' => route('task.view', ['tsk_id' => $task->tsk_id]),
            'extra_params' => [
                'tsk_id' => $task->tsk_id,
                'tsk_title' => $task->tsk_title,
                'tsk_code' => $task->tsk_code,
                'tsk_status' => $task->tsk_status,
            ]
        ];
        $task_assignees = $task->assignees()->get()->toArray();
        if (!empty($task_assignees)) {
            foreach ($task_assignees as $user) {
                if ($user['tsa_usr_id'] == get_logged_in_user_id())
                    continue;
                $notification_data['receiver_id'] = $user['tsa_usr_id'];
                sendNotification($notification_data);
            }
        }
        return response()->json([
            'message' => 'Task status has been updated.',
            'updated_status' => $task->tsk_status
        ]);
    }

    public function addTaskComment (Request $request, $taskId) {
        $request->validate([
            'comment' => 'required|string|min:1|max:3500',
            'mentions' => 'nullable|array',
            'mentions.*' => 'integer|exists:users,usr_id',
        ]);

        // Normalize deduplicated mentioned user IDs
        $rawMentions = $request->filled('mentions') ? (array) $request->input('mentions') : [];
        $mentionIds = array_values(array_unique(array_filter(array_map('intval', $rawMentions), fn ($id) => $id > 0)));
        $mentions = !empty($mentionIds) ? $mentionIds : null;

        $task = $this->findTaskByAnyId($taskId);
        if (!$task) {
            return response()->json([
                'status' => false,
                'message' => 'Task not found.'
            ], 404);
        }
        $realTaskId = $task->tsk_id;
        $authorId = (int) get_logged_in_user_id();

        $comment = TaskComment::create([
            'tsc_tsk_id' => $realTaskId,
            'tsc_author' => $authorId,
            'tsc_comment' => $request->comment,
            'tsc_mentions' => $mentions,
            'tsc_updated_by' => setCreatedUpdatedBy(),
            'tsc_updated_on' => now(),
        ]);
        $comment->setRelation('commentedTask', $task);

        (new TaskActivityService())->commentAdded($realTaskId, $authorId);
        // Log accurate states
        app(AuditService::class)->log(
            action: 'comment',
            module: 'task',
            record: $comment->commentedTask->tsk_code,
            oldValues: [],
            newValues: [
                'comment' => $request->comment,
                'mentions' => $mentionIds,
            ],
            description: 'Commented on ' . $comment->commentedTask->tsk_code,
        );

        $actorName = get_logged_in_user_name();
        $extraParams = [
            'tsk_id' => $comment->commentedTask->tsk_id,
            'tsk_title' => $comment->commentedTask->tsk_title,
            'tsk_code' => $comment->commentedTask->tsk_code,
        ];
        $redirectUrl = route('task.view', ['tsk_id' => $comment->commentedTask->tsk_id]);

        // 1. Notify mentioned users first (excluding the comment author)
        $notifiedUserIds = [$authorId];
        $mentionedNames = [];
        $mentionTargetIds = array_values(array_diff($mentionIds, [$authorId]));
        if (!empty($mentionTargetIds)) {
            $mentionedUsers = User::query()
                ->whereIn('usr_id', $mentionTargetIds)
                ->get(['usr_id', 'usr_name']);
            foreach ($mentionedUsers as $mentionedUser) {
                $mentionedNames[] = $mentionedUser->usr_name;
                $notifiedUserIds[] = (int) $mentionedUser->usr_id;
                sendNotification([
                    'receiver_id' => $mentionedUser->usr_id,
                    'kind' => 'mention',
                    'title' => $actorName . ' mentioned you on ' . $comment->commentedTask->tsk_code,
                    'body' => $request->comment,
                    'actor' => $actorName,
                    'redirect_to' => $redirectUrl,
                    'extra_params' => $extraParams,
                ]);
            }
        }

        // 2. Notify remaining task assignees (excluding author & already-notified mentioned users)
        $notification_data = [
            'kind' => 'comment',
            'title' => 'New comment on ' . $comment->commentedTask->tsk_code,
            'body' => $request->comment,
            'actor' => $actorName,
            'redirect_to' => $redirectUrl,
            'extra_params' => $extraParams,
        ];
        $task_assignees = $comment->commentedTask->assignees()->get()->toArray();
        if (!empty($task_assignees)) {
            foreach ($task_assignees as $user) {
                $assigneeId = (int) $user['tsa_usr_id'];
                if (in_array($assigneeId, $notifiedUserIds, true)) {
                    continue;
                }
                $notifiedUserIds[] = $assigneeId;
                $notification_data['receiver_id'] = $assigneeId;
                sendNotification($notification_data);
            }
        }

        return response()->json([
            'message' => 'Comment added successfully',
            'data' => $comment->load('commentAuthor:usr_id,usr_name,usr_avatar,usr_designation,usr_role'),
            'mentioned' => $mentionedNames,
        ], 201);
    }

    public function addTaskAttachment (Request $request, $taskId) {
        // 1. Validate multiple attachments.
        $validated = $request->validate([
            'attachments' => ['required', 'array', 'min:1'],
            'attachments.*' => [
                'required',
                'file',
                'max:' . (1024 * MAX_FILE_UPLOAD_MB_SIZE),
                'mimes:jpg,jpeg,png,pdf,mp4,mov,webm',
            ],
        ]);

        // 2. Find the task.
        $task = $this->findTaskByAnyId($taskId);

        if (!$task) {
            return response()->json([
                'message' => 'Task does not exist.',
            ], 404);
        }

        $files = $request->file('attachments', []);
        $allowedExtensions = [
            'jpg', 'jpeg', 'png', 'pdf', 'mp4', 'mov', 'webm',
        ];

        $uploadedAttachments = [];
        $storedPaths = [];

        try {
            foreach ($files as $file) {
                // 3. Sanitize the original filename.
                $originalName = basename(
                    str_replace('\\', '/', $file->getClientOriginalName())
                );

                $originalName = preg_replace(
                    '/[^A-Za-z0-9._ -]/',
                    '_',
                    $originalName
                );

                $originalName = substr($originalName, 0, 200);

                if (
                    $originalName === '' ||
                    $originalName === '.' ||
                    $originalName === '..'
                ) {
                    $originalName = 'attachment';
                }

                // 4. Check the extension.
                $extension = strtolower(
                    $file->getClientOriginalExtension()
                );

                if (!in_array($extension, $allowedExtensions, true)) {
                    throw \Illuminate\Validation\ValidationException::withMessages([
                        'attachments' => [
                            "The file {$originalName} has an unsupported extension.",
                        ],
                    ]);
                }

                // Capture metadata before moving the file.
                $mimeType = $file->getMimeType();
                $fileSizeKb = (int)ceil($file->getSize() / 1024);

                // 5. Generate a server-controlled filename.
                $storedName = bin2hex(random_bytes(16)) . '.' . $extension;

                // 6. Store the file on the private disk.
                $storedPath = $file->storeAs(
                    'task-attachments',
                    $storedName,
                    'private'
                );

                if (!$storedPath) {
                    throw new \RuntimeException(
                        "Unable to store attachment: {$originalName}"
                    );
                }

                $storedPaths[] = $storedPath;

                // 7. Create the attachment record.
                $attachment = DB::transaction(function () use (
                    $task,
                    $request,
                    $originalName,
                    $storedName,
                    $mimeType,
                    $fileSizeKb
                ) {
                    return Attachment::create([
                        'atc_tsk_id' => $task->tsk_id,
                        'atc_pro_id' => $task->tsk_pro_id ?? null,
                        'atc_file_name' => $originalName,
                        'atc_stored_as' => $storedName,
                        'atc_mime' => $mimeType,
                        'atc_size_kb' => $fileSizeKb,
                        'atc_uploaded_by' => $request->user()->usr_id,
                        'atc_created_at' => now(),
                        'atc_storage' => 'local',
                        'atc_drive_file_id' => null,
                        'atc_drive_url' => null,
                        'atc_deleted_on' => null,
                    ]);
                });

                $uploadedAttachments[] = $attachment;

                // 8. Audit each uploaded file.
                app(AuditService::class)->log(
                    action: 'file_upload',
                    module: 'attachment',
                    record: $attachment->atc_id,
                    oldValues: [],
                    newValues: [
                        'task' => $task->tsk_title,
                        'file' => $originalName,
                        'mime' => $mimeType,
                        'size_kb' => $fileSizeKb,
                        'storage' => 'local',
                        'drive_file_id' => null,
                    ],
                    description: 'Uploaded ' . $originalName .
                    ' to ' . $task->tsk_code
                );
            }

            // 9. Notify task assignees once per uploaded file.
            $taskAssignees = $task->assignees()->get();
            $loggedInUserId = get_logged_in_user_id();

            foreach ($uploadedAttachments as $attachment) {
                $notificationData = [
                    'kind' => 'task_file',
                    'title' => 'New file added to ' . $task->tsk_code,
                    'body' => 'File: ' . $attachment->atc_file_name,
                    'actor' => get_logged_in_user_name(),
                    'redirect_to' => route('task.view', [
                        'tsk_id' => $task->tsk_id,
                    ]),
                    'extra_params' => [
                        'tsk_id' => $task->tsk_id,
                        'tsk_title' => $task->tsk_title,
                        'tsk_code' => $task->tsk_code,
                        'atc_id' => $attachment->atc_id,
                    ],
                ];

                foreach ($taskAssignees as $user) {
                    if ((int)$user->tsa_usr_id === (int)$loggedInUserId) {
                        continue;
                    }

                    $notificationData['receiver_id'] = $user->tsa_usr_id;

                    sendNotification($notificationData);
                }
            }
        } catch (\Throwable $e) {
            // Remove stored files if any part of the operation fails.
            foreach ($storedPaths as $storedPath) {
                Storage::disk('private')->delete($storedPath);
            }

            // Remove records created during this request as well.
            if (!empty($uploadedAttachments)) {
                Attachment::whereIn(
                    'atc_id',
                    collect($uploadedAttachments)
                        ->pluck('atc_id')
                        ->all()
                )->delete();
            }

            throw $e;
        }

        return response()->json([
            'message' => count($uploadedAttachments) .
                ' attachment(s) uploaded successfully.',
            'attachments' => $uploadedAttachments,
        ], 201);
    }

    public function downloadTaskAttachment ($attachmentId) {
        $attachment = Attachment::query()
            ->where('atc_id', $attachmentId)
            ->whereNull('atc_deleted_on')
            ->firstOrFail();

        $path = storage_path(
            'app/private/task-attachments/' . $attachment->atc_stored_as
        );

        abort_unless(is_file($path), 404, 'Attachment file not found.');

        // Add your task/project authorization check before serving the file.
        return response()->download(
            $path,
            $attachment->atc_file_name,
            ['Content-Type' => $attachment->atc_mime]
        );
    }

    public function deleteTaskAttachment (Request $request, $attachmentId) {
        $attachment = Attachment::query()->with('task:tsk_id,tsk_title,tsk_code')->where('atc_id', $attachmentId)->first();
        $user = $request->user();
        if (empty($attachment)) {
            return response()->json([
                'message' => 'Attachment does not exist.'
            ], 404);
        }
        $binId = $this->recycleBinService->recycleAttachment($user, $attachment->toArray());

        app(AuditService::class)->log(
            action: 'file_delete',
            module: 'attachment',
            record: $attachment->atc_id,
            oldValues: [
                'task' => $attachment->task->tsk_title . ' - ' . $attachment->task->tsk_code,
                'file' => $attachment->atc_file_name,
                'mime' => $attachment->atc_mime,
                'size_kb' => $attachment->atc_size_kb,
                'drive_file_id' => 'drive / local',
            ],
            newValues: [
                'recycle_bin_id' => $binId,
            ],
            description: 'Moved File ' . $attachment->atc_file_name . ' to the Recycle Bin'
        );

        app(AuditService::class)->log(
            action: 'file_deleted',
            module: 'security',
            record: 'attachment: ' . $attachment->atc_id,
            oldValues: [],
            newValues: [
                'task' => $attachment->task->tsk_code,
            ],
            description: 'Security: file deleted'
        );
        return response()->json([
            'message' => 'Attachment deleted successfully.',
        ], 200);
    }

    private function getOrCreateProjectForTask ($requested_pro_name) {
        $trimmed_pro_name = trim($requested_pro_name);
        if (empty($trimmed_pro_name)) {
            return null;
        }
        $existProId = Project::query()
            ->where('pro_name', $trimmed_pro_name)
            ->orWhere('pro_code', $trimmed_pro_name)
            ->orWhere('pro_name', 'LIKE', "%{$trimmed_pro_name}%")
            ->pluck('pro_id')
            ->first();
        if (empty($existProId)) {
            $lastProject = Project::orderBy('pro_id', 'desc')->first();

            $nextNumber = $lastProject
                ? ((int)str_replace('P-', '', $lastProject->pro_code)) + 1
                : 1;

            $pro_code = 'P-' . str_pad($nextNumber, 2, '0', STR_PAD_LEFT);

            $projectObj = new Project();
            $projectObj->pro_name = $trimmed_pro_name;
            $projectObj->pro_code = $pro_code;
            $projectObj->pro_manager = get_logged_in_user_id();
            $projectObj->pro_created_by = setCreatedUpdatedBy();
            $projectObj->pro_created_on = date(config('constants.DB_DATE_TIME_FORMAT'));
            $projectObj->save();

            $existProId = $projectObj->pro_id;
        }

        return $existProId;
    }

    public function getMentionsUserForChat () {
        $mentions = User::query()->where('usr_status', '=', 1)->get(['users.usr_name', 'users.usr_id'])->mapWithKeys(function ($user) {
            return [$user['usr_id'] => '@' . str_replace(' ', '_', $user['usr_name'])];
        })->toArray();
        return response()->json($mentions);
    }

    public function getProjectsForSuggestion () {
        $projects = Project::query()->pluck('projects.pro_name', 'projects.pro_id')->toArray();
        return response()->json($projects);
    }

    public function getTaskAssigneeForSuggestion () {
        $assigned_user = User::query()->pluck('usr_name', 'usr_id')->toArray();
        return response()->json($assigned_user);
    }
}
