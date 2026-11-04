<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SubTask;
use App\Services\Audit\AuditService;
use App\Services\TaskActivityService;
use Illuminate\Http\Request;

class SubtaskController extends Controller {
    public function index(Request $request, $taskId = null, $userId = null, $called_for = 'inner') {
        $subTaskBaseQuery = Subtask::with('parentTask');
        if (!empty($taskId)) {
            $subTaskBaseQuery->where('stk_tsk_id', $taskId);
        }
        if (!empty($userId)) {
            $subTaskBaseQuery->whereHas('parentTask.assignees', function ($query) use ($userId) {
                $query->where('tsa_usr_id', $userId);
            });
        }

        $subTasks = $subTaskBaseQuery->get();
        if ($called_for == 'inner') {
            return $subTasks->toQuery();
        } else {
            return response()->json([$subTasks], 200);
        }
    }

    public function toggleSubtaskCheckList(Request $request, $subTaskId) {
        $subTask = SubTask::withTrashed()
            ->with('parentTask:tsk_id,tsk_title,tsk_code')
            ->find($subTaskId);

        // 1. Doesn't exist at all
        if (!$subTask) {
            return response()->json([
                'message' => 'Subtask does not exist.'
            ], 404);
        }

        // 2. Exists, but is soft-deleted
        if ($subTask->trashed()) {
            return response()->json([
                'message' => 'Cannot toggle checklist because this subtask has been deleted.'
            ], 400); // 400 Bad Request or 410 Gone
        }

        // 3. Capture original status before mutation
        $wasDone = (bool)$subTask->stk_done;

        // 4. Toggle and save
        $subTask->stk_done = !$wasDone;
        $subTask->save();

        // 5. Log accurate states
        app(AuditService::class)->log(
            action: 'checklist_toggle',
            module: 'task',
            record: $subTask->stk_tsk_id,
            oldValues: [
                'item' => $subTask->stk_title,
                'done' => ($wasDone ? 'true' : 'false'),
            ],
            newValues: [
                'item' => $subTask->stk_title,
                'done' => ($subTask->stk_done ? 'true' : 'false'),
            ],
            description: 'Ticked/unticked checklist item on ' . ($subTask->parentTask->tsk_code ?? 'Task #' . $subTask->stk_tsk_id)
        );

        return response()->json([
            'message' => 'Subtask toggled successfully',
            'stk_done' => $subTask->stk_done
        ]);
    }

    public function addSubtaskCheckList(Request $request, $taskId) {
        $request->validate([
            'title' => 'required|min:2|:max:200',
        ]);

        $subtask = SubTask::query()->create([
            'stk_tsk_id' => $taskId,
            'stk_title' => $request->title,
            'stk_updated_by' => setCreatedUpdatedBy(),
            'stk_updated_on' => now(),
        ]);

        if ($subtask) {
            // Log accurate states
            app(AuditService::class)->log(
                action: 'checklist_add',
                module: 'task',
                record: $subtask->stk_tsk_id,
                oldValues: [],
                newValues: [
                    'item' => $subtask->stk_title,
                ],
                description: 'Added checklist item on ' . $subtask->parentTask->tsk_code
            );

            (new TaskActivityService())->subtaskAdded($subtask->stk_tsk_id, get_logged_in_user_id(), $subtask->stk_title);

            return response()->json([
                'status' => true,
                'message' => 'Subtask added successfully',
                'subtask' => $subtask
            ]);
        }
        return response()->json([
            'message' => 'Something went wrong.'
        ], 500);
    }
}
