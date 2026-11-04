<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskAssignee;
use Carbon\Carbon;
use Illuminate\Http\Request;

class ReportController extends Controller {
    public function index (Request $request) {
        $loggedInUserId = get_logged_in_user_id();

        // Get team member IDs
        $teamUserIds = get_users_children_in_depth($loggedInUserId, true);

        // 1. Check permission.
        /*if (!$user || !$user->hasModuleAccess('reports', 'view')) {
            return response()->json([
                'ok' => false,
                'message' => 'Only Admins and Managers can see reports.',
                'error_code' => 'permission_denied',
                'need' => 'view_reports',
            ], 403);
        }*/

        $today = Carbon::today();

        // 2. Reusable task count.
        // Eloquent automatically excludes soft-deleted tasks.
        $count = function (array $conditions = []) use ($today): int {
            $query = Task::query();

            foreach ($conditions as $column => $value) {
                if ($column === 'overdue') {
                    $query->whereNotIn('tsk_status', [
                        'completed',
                        'cancelled',
                    ])
                        ->whereNotNull('tsk_due_date')
                        ->whereDate('tsk_due_date', '<', $today);
                } elseif ($column === 'due_today') {
                    $query->whereNotIn('tsk_status', ['completed', 'cancelled',])
                        ->whereDate('tsk_due_date', '=', $today);
                } else {
                    $query->where($column, $value);
                }
            }
            return (int)$query->count();
        };

        $total = $count();
        $completed = $count(['tsk_status' => 'completed',]);

        $cancelled = $count(['tsk_status' => 'cancelled',]);
        $base = $total - $cancelled;
        // 3. Weekly completion trend: current week + previous 7 weeks.
        $trend = [];

        $thisMonday = Carbon::today()->startOfWeek(Carbon::MONDAY);
        for ($i = 7; $i >= 0; $i--) {
            $from = $thisMonday->copy()->subWeeks($i);
            $to = $from->copy()->addDays(6);

            $value = Task::query()
                ->whereNotNull('tsk_completed_at')
                ->whereBetween('tsk_completed_at', [
                    $from->copy()->startOfDay(),
                    $to->copy()->endOfDay(),
                ])
                ->count();

            $trend[] = [
                'label' => $from->format('d M'),
                'value' => (int)$value,
            ];
        }

        // 4. Employee performance.
        // The assignee relationship resolves the user's name through users.
        $peopleTaskQuery = TaskAssignee::query()
            ->join('tasks as t', 't.tsk_id', '=', 'task_assignees.tsa_tsk_id')
            ->join('users as u', 'u.usr_id', '=', 'task_assignees.tsa_usr_id')
            ->whereNull('t.tsk_deleted_on')
            ->whereNull('task_assignees.tsa_deleted_on');

        if (!is_admin()) {
            $peopleTaskQuery->whereIn(
                'u.usr_id',
                array_unique(array_merge(
                    [$loggedInUserId],
                    $teamUserIds ?? []
                ))
            );
        }

        $people = $peopleTaskQuery
            ->selectRaw("
        u.usr_id AS user_id,
        u.usr_name AS name,
        SUM(
            CASE
                WHEN t.tsk_status NOT IN ('completed', 'cancelled')
                THEN 1 ELSE 0
            END
        ) AS open,
        SUM(
            CASE
                WHEN t.tsk_status = 'completed'
                THEN 1 ELSE 0
            END
        ) AS done,
        SUM(
            CASE
                WHEN t.tsk_status NOT IN ('completed', 'cancelled')
                 AND t.tsk_due_date IS NOT NULL
                 AND t.tsk_due_date < ?
                THEN 1 ELSE 0
            END
        ) AS overdue,
        COALESCE(SUM(t.tsk_est_hours), 0) AS hours
    ", [$today->toDateString()])
            ->groupBy('u.usr_id', 'u.usr_name')
            ->orderByDesc('open')
            ->orderByDesc('done')
            ->limit(50)
            ->get()
            ->map(fn($row) => [
                'id' => (int)$row->user_id,
                'name' => $row->name,
                'open' => (int)$row->open,
                'done' => (int)$row->done,
                'overdue' => (int)$row->overdue,
                'hours' => (float)$row->hours,
            ])
            ->values();

        // 5. Project performance.
        // Eloquent respects Project and Task soft deletes for relationship queries.
        $projects = Project::query()
            ->withCount([
                'tasks as total',
                'tasks as done' => function ($query) {
                    $query->where('tsk_status', 'completed');
                },
                'tasks as overdue' => function ($query) use ($today) {
                    $query->whereNotIn('tsk_status', [
                        'completed',
                        'cancelled',
                    ])
                        ->whereNotNull('tsk_due_date')
                        ->whereDate('tsk_due_date', '<', $today);
                },
            ])
            ->orderBy('pro_name')
            ->get()
            ->map(function (Project $project) {
                $total = (int)$project->total;
                $done = (int)$project->done;

                return [
                    'id' => $project->pro_id,
                    'code' => $project->pro_code,
                    'name' => $project->pro_name,
                    'reported' => (int)$project->pro_progress,
                    'status' => $project->pro_status,
                    'total' => $total,
                    'done' => $done,
                    'overdue' => (int)$project->overdue,
                    'taskBased' => $total > 0
                        ? (int)round(($done / $total) * 100)
                        : 0,
                ];
            })
            ->values();

        // 6. Average completion time in days.
        $avg = Task::query()
            ->whereNotNull('tsk_completed_at')
            ->selectRaw('AVG(DATEDIFF(tsk_completed_at, tsk_created_on)) AS avg_days')
            ->value('avg_days');

        // 7. Return the original React-compatible structure.
        return response()->json([
            'ok' => true,
            'data' => [
                'totals' => [
                    'total' => $total,
                    'todo' => $count([
                        'tsk_status' => 'to_do',
                    ]),
                    'inProgress' => $count([
                        'tsk_status' => 'in_progress',
                    ]),
                    'review' => $count([
                        'tsk_status' => 'in_review',
                    ]),
                    'onHold' => $count([
                        'tsk_status' => 'on_hold',
                    ]),
                    'completed' => $completed,
                    'cancelled' => $cancelled,
                    'overdue' => $count(['overdue' => true]),
                    'dueToday' => $count(['due_today' => true]),
                    'completionRate' => $base > 0
                        ? (int)round(($completed / $base) * 100)
                        : 0,
                    'avgDays' => round((float)($avg ?? 0), 1),
                ],
                'trend' => $trend,
                'people' => $people,
                'projects' => $projects,
            ],
        ], 200);
    }
}
