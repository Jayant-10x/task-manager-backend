<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardController extends Controller {
    public function index (Request $request): JsonResponse {
        $loggedInUserId = get_logged_in_user_id();
        $loggedInUserChildren = get_users_children_in_depth($loggedInUserId, true, 3);
        $isAdmin = is_admin();
        $hasChildren = !empty($loggedInUserChildren);

        // Determine effective dashboard mode:
        // 'employee' mode applies ONLY if the user is NOT an admin AND has NO children.
        $isEmployee = !$isAdmin && !$hasChildren;
        $isManager = !$isAdmin && $hasChildren;

        $today = Carbon::today()->toDateString();
        $startOfWeek = Carbon::now()->startOfWeek();
        $endOfWeek = Carbon::now()->endOfWeek();

        // ------------------------------------------------------------------
        // 1. BASE SCOPE QUERY FOR TASKS
        // ------------------------------------------------------------------
        $baseTaskQuery = Task::query();

        if ($isManager) {
            $baseTaskQuery->where(function ($query) use ($loggedInUserId, $loggedInUserChildren) {
                $query->whereIn('tasks.tsk_assigned_by', array_merge([$loggedInUserId], $loggedInUserChildren))
                    ->orWhereHas('assignees', function ($q) use ($loggedInUserId, $loggedInUserChildren) {
                        $q->whereIn('tsa_usr_id', array_merge([$loggedInUserId], $loggedInUserChildren))
                            ->whereNull('tsa_deleted_on');
                    });
            });
        } elseif (!$isAdmin) {
            // Employee sees tasks assigned directly to them
            $baseTaskQuery->whereHas('assignees', function ($q) use ($loggedInUserId) {
                $q->where('tsa_usr_id', $loggedInUserId)
                    ->whereNull('tsa_deleted_on');
            });
        }

        // ------------------------------------------------------------------
        // 2. METRIC COUNTS
        // ------------------------------------------------------------------
        $openTasksCount = (clone $baseTaskQuery)
            ->whereNotIn('tsk_status', ['completed', 'cancelled'])
            ->count();

        $overdueCount = (clone $baseTaskQuery)
            ->whereNotIn('tsk_status', ['completed', 'cancelled'])
            ->where('tsk_due_date', '<', $today)
            ->count();

        $dueTodayCount = (clone $baseTaskQuery)
            ->whereNotIn('tsk_status', ['completed', 'cancelled'])
            ->where('tsk_due_date', '=', $today)
            ->count();

        $inReviewCount = (clone $baseTaskQuery)
            ->where('tsk_status', 'in_review')
            ->count();

        $doneThisWeekCount = (clone $baseTaskQuery)
            ->where('tsk_status', 'completed')
            ->whereBetween('tsk_completed_at', [$startOfWeek, $endOfWeek])
            ->count();

        // User Completion Stats
        $myTasksQuery = Task::whereHas('assignees', function ($q) use ($loggedInUserId) {
            $q->where('tsa_usr_id', $loggedInUserId)->whereNull('tsa_deleted_on');
        });

        $myOpenTasksCount = (clone $myTasksQuery)->whereNotIn('tsk_status', ['completed', 'cancelled'])->count();
        $totalMyTasks = (clone $myTasksQuery)->count();
        $completedMyTasks = (clone $myTasksQuery)->where('tsk_status', 'completed')->count();

        $completionPercentage = $totalMyTasks > 0 ? round(($completedMyTasks / $totalMyTasks) * 100) : 0;
        $activeProjectsCount = Project::where('pro_status', 'in_progress')->count();

        // ------------------------------------------------------------------
        // 3. MY FOCUS LIST (Max 5 Records - Logged-in User Only)
        // ------------------------------------------------------------------
        $myFocusList = Task::with([
            'project:pro_id,pro_code,pro_name',
            'assignees.assignedUser:usr_id,usr_name',
            'subtasks:stk_id,stk_tsk_id,stk_title,stk_done'
        ])
            ->withCount('comments')
            ->whereHas('assignees', function ($q) use ($loggedInUserId) {
                $q->where('tsa_usr_id', $loggedInUserId)->whereNull('tsa_deleted_on');
            })
            ->whereNotIn('tsk_status', ['completed', 'cancelled'])
            ->orderByRaw("CASE WHEN tsk_due_date IS NULL THEN 1 ELSE 0 END, tsk_due_date ASC")
            ->orderByRaw("FIELD(tsk_priority, 'urgent', 'high', 'medium', 'low')")
            ->limit(5)
            ->get();

        // ------------------------------------------------------------------
        // 4. UPCOMING DEADLINES (Max 5 Records)
        // ------------------------------------------------------------------
        $upcomingDeadlines = (clone $baseTaskQuery)
            ->with(['assignees.assignedUser:usr_id,usr_name'])
            ->whereNotIn('tsk_status', ['completed', 'cancelled'])
            ->whereNotNull('tsk_due_date')
            ->where('tsk_due_date', '>=', $today)
            ->orderBy('tsk_due_date', 'asc')
            ->limit(5)
            ->get();

        // ------------------------------------------------------------------
        // 5. RIGHT SIDEBAR CONTENT: TEAM WORKLOAD vs MY TASK MIX
        // ------------------------------------------------------------------
        $teamWorkload = [];
        $taskMix = [];

        if ($isAdmin || $isManager) {
            // ADMIN / MANAGER VIEW: Render Team Workload
            $teamWorkload = User::select('usr_id', 'usr_name')
                ->whereHas('taskAssignees.whichAssignedTask', function ($q) use ($isAdmin, $isManager, $loggedInUserId, $loggedInUserChildren) {
                    $q->whereNotIn('tasks.tsk_status', ['completed', 'cancelled']);
                    if ($isManager) {
                        $q->whereIn('tasks.tsk_assigned_by', array_merge([$loggedInUserId], $loggedInUserChildren));
                    }
                })
                ->withCount(['taskAssignees as open_tasks_count' => function ($q) use ($isAdmin, $isManager, $loggedInUserId, $loggedInUserChildren) {
                    $q->whereNull('task_assignees.tsa_deleted_on')
                        ->whereHas('whichAssignedTask', function ($tq) use ($isAdmin, $isManager, $loggedInUserId, $loggedInUserChildren) {
                            $tq->whereNotIn('tasks.tsk_status', ['completed', 'cancelled']);
                            if ($isManager) {
                                $tq->whereIn('tasks.tsk_assigned_by', array_merge([$loggedInUserId], $loggedInUserChildren));
                            }
                        });
                }])
                ->orderByDesc('open_tasks_count')
                ->limit(6)
                ->get();
        } else {
            // EMPLOYEE VIEW ONLY (No Children & Not Admin): Render Task Mix Breakdown
            $statuses = ['to_do', 'in_progress', 'on_hold', 'in_review', 'completed', 'cancelled'];
            $taskMix = [];
            foreach ($statuses as $st) {
                $taskMix[$st] = Task::whereHas('assignees', function ($q) use ($loggedInUserId) {
                    $q->where('tsa_usr_id', $loggedInUserId)->whereNull('tsa_deleted_on');
                })->where('tsk_status', $st)->count();
            }
        }

        // ------------------------------------------------------------------
        // 6. RECENT ACTIVITY
        // ------------------------------------------------------------------
        $auditLogQuery = AuditLog::query()->orderByDesc('alg_id')->limit(10);

        if ($isManager) {
            $auditLogQuery->whereIn('alg_usr_id', array_merge([$loggedInUserId], $loggedInUserChildren));
        } elseif (!$isAdmin) {
            $auditLogQuery->where('alg_usr_id', $loggedInUserId);
        }

        $recentActivities = $auditLogQuery->get();

        return response()->json([
            'status' => true,
            'message' => 'Dashboard data fetched successfully.',
            'data' => [
                'banner' => [
                    'user_name' => auth()->user()->usr_name ?? 'User',
                    'my_open_tasks' => $myOpenTasksCount,
                    'workspace_open_tasks' => $openTasksCount,
                    'active_projects' => $activeProjectsCount,
                    'completion_percentage' => $completionPercentage,
                ],
                'metrics' => [
                    'open_tasks' => $openTasksCount,
                    'overdue' => $overdueCount,
                    'due_today' => $dueTodayCount,
                    'in_review' => $inReviewCount,
                    'done_this_week' => $doneThisWeekCount,
                ],
                'my_focus_list' => $myFocusList,
                'upcoming_deadlines' => $upcomingDeadlines,
                'team_workload' => $teamWorkload,
                'task_mix' => $taskMix,
                'recent_activities' => $recentActivities,
                'user_role' => $isAdmin ? 'admin' : ($isManager ? 'manager' : 'employee'),
                'has_children' => $hasChildren,
            ],
        ]);
    }

    public function teamMemberView (Request $request, $memberId): JsonResponse {
        // 1. Fetch Member Profile Information
        $user = User::where('usr_id', $memberId)
            ->whereNull('usr_deleted_on')
            ->firstOrFail();

        $today = Carbon::today()->toDateString();

        // Base query for tasks assigned to this member
        $assignedTasksQuery = Task::whereHas('assignees', function ($q) use ($memberId) {
            $q->where('tsa_usr_id', $memberId)->whereNull('tsa_deleted_on');
        });

        // 2. Metrics Calculation
        $openTasksCount = (clone $assignedTasksQuery)
            ->whereNotIn('tsk_status', ['completed', 'cancelled'])
            ->count();

        $completedCount = (clone $assignedTasksQuery)
            ->where('tsk_status', 'completed')
            ->count();

        $overdueCount = (clone $assignedTasksQuery)
            ->whereNotIn('tsk_status', ['completed', 'cancelled'])
            ->where('tsk_due_date', '<', $today)
            ->count();

        $totalEstHours = (clone $assignedTasksQuery)->sum('tsk_est_hours') ?? 0;

        // 3. Workload Breakdown (Task Mix)
        $workload = [
            'open' => (clone $assignedTasksQuery)->whereIn('tsk_status', ['to_do', 'in_progress'])->count(),
            'review' => (clone $assignedTasksQuery)->where('tsk_status', 'in_review')->count(),
            'on_hold' => (clone $assignedTasksQuery)->where('tsk_status', 'on_hold')->count(),
            'overdue' => $overdueCount,
            'completed' => $completedCount,
        ];

        // 4. Assigned Tasks List with Subtasks & Relations
        $assignedTasks = (clone $assignedTasksQuery)
            ->with([
                'project:pro_id,pro_code,pro_name',
                'assignees.assignedUser:usr_id,usr_name',
                'subtasks:stk_id,stk_tsk_id,stk_title,stk_done,stk_completed_at'
            ])
            ->withCount(['comments', 'subtasks as checklist_total'])
            ->withCount(['subtasks as checklist_done' => function ($q) {
                $q->whereNotNull('stk_completed_at')->orWhere('stk_done', 1);
            }])
            ->orderByRaw("FIELD(tsk_status, 'in_progress', 'to_do', 'in_review', 'on_hold', 'completed', 'cancelled')")
            ->orderByDesc('tsk_id')
            ->get();

        // 5. Projects Breakdown
        $projects = Project::select('pro_id', 'pro_code', 'pro_name', 'pro_status')
            ->whereHas('tasks.assignees', function ($q) use ($memberId) {
                $q->where('tsa_usr_id', $memberId)->whereNull('tsa_deleted_on');
            })
            ->withCount(['tasks as tasks_assigned_count' => function ($q) use ($memberId) {
                $q->whereHas('assignees', function ($aq) use ($memberId) {
                    $aq->where('tsa_usr_id', $memberId)->whereNull('tsa_deleted_on');
                });
            }])
            ->get();

        return response()->json([
            'status' => true,
            'message' => 'Member details retrieved successfully.',
            'data' => [
                'user' => [
                    'usr_id' => $user->usr_id,
                    'usr_name' => $user->usr_name,
                    'usr_email' => $user->usr_email,
                    'usr_phone' => $user->usr_phone ?? '-',
                    'usr_role' => $user->usr_role ?? 'Employee',
                    'usr_designation' => $user->usr_designation ?? 'Full Stack Developer',
                    'usr_dept' => $user->usr_dept ?? 'Development',
                    'usr_city' => $user->usr_city ?? 'kanpur',
                    'usr_status' => $user->usr_status ? 'Active' : 'Inactive',
                    'usr_online' => (bool)$user->usr_online,
                    'usr_avatar' => $user->usr_avatar,
                    'employee_id' => 'U-' . $user->usr_id,
                    'joined_date' => $user->usr_joined ? Carbon::parse($user->usr_joined)->format('d M Y') : '-',
                    'last_seen' => $user->usr_last_seen ? Carbon::parse($user->usr_last_seen)->format('d M, h:i a') : '-',
                ],
                'metrics' => [
                    'open_tasks' => $openTasksCount,
                    'completed' => $completedCount,
                    'overdue' => $overdueCount,
                    'total_effort_hours' => $totalEstHours,
                ],
                'workload' => $workload,
                'assigned_tasks' => $assignedTasks,
                'projects' => $projects,
            ],
        ]);
    }
}
