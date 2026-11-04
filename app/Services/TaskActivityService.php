<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class TaskActivityService {
    /**
     * Record an activity against a task.
     */
    public function log (int $taskId, string $who, string $action, string $detail = ''
    ): void {
        DB::table('task_activities')->insert([
            'tct_tsk_id' => $taskId,
            'tct_who' => $who,
            'tct_action' => mb_substr($action, 0, 60),
            'tct_detail' => mb_substr($detail, 0, 300),
            'tct_updated_by' => setCreatedUpdatedBy(),
            'tct_updated_on' => now(),
        ]);
    }

    public function created (int $taskId, string $who): void {
        $this->log($taskId, $who, 'created', 'Task created');
    }

    public function statusChanged (int $taskId, string $who, string $status): void {
        $this->log($taskId, $who, 'status', 'Status changed to ' . $status);
    }

    public function titleChanged (int $taskId, string $who, string $title): void {
        $this->log($taskId, $who, 'edit', 'Title changed to "' . $title . '"'
        );
    }

    public function priorityChanged (int $taskId, string $who, string $priority): void {
        $this->log($taskId, $who, 'priority', 'Priority changed to ' . $priority
        );
    }

    public function dueDateChanged (int $taskId, string $who, ?string $dueDate): void {
        $this->log($taskId, $who, 'due', 'Due date changed to ' . ($dueDate ?: 'none')
        );
    }

    public function assigned (int $taskId, string $who, array $assignees): void {
        $this->log($taskId, $who, 'assigned', 'Assigned to ' . implode(', ', $assignees)
        );
    }

    public function subtaskAdded (int $taskId, string $who, string $title
    ): void {
        $this->log($taskId, $who, 'subtask', 'Added checklist item: ' . $title
        );
    }

    public function commentAdded (int $taskId, string $who): void {
        $this->log($taskId, $who, 'comment', 'Comment added');
    }

    public function fileUploaded (int $taskId, string $who, string $fileName
    ): void {
        $this->log($taskId, $who, 'file', 'Uploaded ' . $fileName
        );
    }

    public function fileRemoved (int $taskId, string $who, string $fileName
    ): void {
        $this->log($taskId, $who, 'file', 'Removed ' . $fileName
        );
    }
}
