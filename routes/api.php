<?php

use App\Http\Controllers\Api\AuditLogController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\ProjectController;
use App\Http\Controllers\Api\RecycleBinController;
use App\Http\Controllers\Api\ReportController;
use App\Http\Controllers\Api\SalesPipelineController;
use App\Http\Controllers\Api\SubtaskController;
use App\Http\Controllers\Api\TaskController;
use App\Http\Controllers\Api\UserPermissionController;
use Illuminate\Support\Facades\Route;

Route::get('/health', function () {
    return response()->json([
        'success' => true,
        'message' => 'Laravel API connected successfully',
    ]);
});
// Public routes
Route::post('/login', [AuthController::class, 'login']);
Route::post('/reset_password/through-mail', [AuthController::class, 'resetThroughEmail']);
Route::get('/reset-password/check', [AuthController::class, 'checkResetToken']);
Route::post('/reset-password/confirm', [AuthController::class, 'confirmPasswordReset']);
// Protected routes (Requires Bearer Token)
Route::middleware(['auth:sanctum', 'enforce.inactivity'])->group(function () {
    Route::get('/dashboard', [DashboardController::class, 'index'])->middleware('permission:dashboard,can_view');
    Route::post('/register', [AuthController::class, 'register'])->middleware('permission:members,can_add');
    Route::post('/create-user', [AuthController::class, 'createUser'])->middleware('permission:members,can_add');
    Route::put('/update-user/{usr_id}', [AuthController::class, 'updateUser'])->middleware('permission:members,can_edit');
    Route::delete('/user/{usr_id}/delete', [AuthController::class, 'destroy'])->middleware('permission:members,can_delete');
    Route::put('/user/{usr_id}/role', [AuthController::class, 'setRole']);
    Route::put('/user/{usr_id}/super-admin', [AuthController::class, 'setSuperAdmin']);
    Route::get('/user', [AuthController::class, 'profile']);
    Route::post('/user/avatar', [AuthController::class, 'uploadAvatar']);
    Route::delete('/user/avatar', [AuthController::class, 'removeAvatar']);
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/permissions', [UserPermissionController::class, 'index']);
    Route::post('/users/{user}/permissions', [UserPermissionController::class, 'store']);
    Route::post('/reset-password', [AuthController::class, 'resetPassword'])->name('password.reset');

    Route::get('/member-view/{member_id}', [DashboardController::class, 'teamMemberView'])->middleware('permission:members,can_view');
    //forgot password
    Route::prefix('reset-password')->middleware('permission:password_reset_request,can_view')->group(function () {
        Route::get('/list', [AuthController::class, 'listPasswordResetRequests']);
        Route::get('/reset-notified-list', [AuthController::class, 'getResetRequestsToNotify']);
        Route::get('/reset-request-notified/{prr_id}', [AuthController::class, 'resetPasswordNotified']);
        Route::get('/reset-request-opened/{prr_id}', [AuthController::class, 'resetPasswordRequestOpened'])->name('reset.request.opened');
        Route::post('/reset-request-reject/{prr_id}', [AuthController::class, 'rejectPasswordRequest']);
        Route::post('/reset-request-success/{prr_id}', [AuthController::class, 'resetPasswordCompleted']);
    });

    // Existing routes
    Route::get('/users/{user}/permissions', [UserPermissionController::class, 'show']);
    Route::put('/users/{user}/permissions', [UserPermissionController::class, 'update']);
    Route::post('/profile-reset-password', [AuthController::class, 'updateProfileCredentials'])
        ->name('password.reset');


    // Project Listing
    Route::get('/projects', [ProjectController::class, 'index'])->middleware('permission:projects,can_view');
    /*
     * permission:project(module_slug), can_add(permission)*/
    // Create Project
    Route::post('/project/save', [ProjectController::class, 'storeProject'])->middleware('permission:projects,can_add');
    Route::put('/project/{pro_id}/update', [ProjectController::class, 'updateProject'])->middleware('permission:projects,can_edit');
    Route::get('/project/{pro_id}/view', [ProjectController::class, 'viewProject'])->middleware('permission:projects,can_view');
    Route::delete('/project/{pro_id}/delete', [ProjectController::class, 'deleteProject'])->middleware('permission:projects,can_delete');

    // My Tasks
    Route::get('/task/{tsk_id}/view', [TaskController::class, 'viewTask'])->middleware('permission:my_tasks,can_view')->name('task.view');
    Route::get('/create-task', [TaskController::class, 'createTask']);
    Route::get('task/project-dropdown', [TaskController::class, 'getProjectsForSuggestion']);
    Route::get('users/assignable', [TaskController::class, 'getTaskAssigneeForSuggestion']);
    Route::post('/task/save', [TaskController::class, 'saveTask'])->middleware('permission:my_tasks,can_add');
    Route::get('/task/{task_id}/edit', [TaskController::class, 'editTask'])->middleware('permission:my_tasks,can_edit');
    Route::put('/task/{task_id}/update', [TaskController::class, 'updateTask'])->middleware('permission:my_tasks,can_edit');
    Route::delete('/task/{task_id}/delete', [TaskController::class, 'deleteTask'])->middleware('permission:my_tasks,can_delete');
    Route::delete('/subtask/{subtask_id}/delete', [TaskController::class, 'deleteSubTask'])->middleware('permission:my_tasks,can_delete');

    Route::put('/task/{task_id}/status_update', [TaskController::class, 'updateTaskStatus'])->middleware('permission:my_tasks,can_edit');
    Route::post('/task/{task_id}/comment', [TaskController::class, 'addTaskComment'])->middleware('permission:my_tasks,can_edit');
    Route::get('/chat-mentions', [TaskController::class, 'getMentionsUserForChat']);
    Route::post('/task/{task_id}/attachment', [TaskController::class, 'addTaskAttachment'])->middleware('permission:my_tasks,can_edit');
    Route::get('/attachment/download/{atc_id}', [TaskController::class, 'downloadTaskAttachment'])->middleware('permission:my_tasks,can_view');
    Route::delete('/attachment/delete/{atc_id}', [TaskController::class, 'deleteTaskAttachment'])->middleware('permission:my_tasks,can_edit');

    Route::get('/my-tasks', [TaskController::class, 'myTasks'])->middleware('permission:my_tasks,can_view');
    Route::get('/all-tasks', [TaskController::class, 'allTasks'])->middleware('permission:all_tasks,can_view');
    Route::get('/assigned-by-me', [TaskController::class, 'assignedByMe'])->middleware('permission:assigned_by_me,can_view');
    Route::get('/task-board', [TaskController::class, 'TaskBoard'])->middleware('permission:task_board,can_view');
    Route::get('/calendar-tasks', [TaskController::class, 'calendarTasks'])->middleware('permission:calendar,can_view');

    Route::get('/team-tasks', [TaskController::class, 'teamTasks'])->middleware('permission:team_tasks,can_view');
    Route::get('/members', [TaskController::class, 'teamMembers'])->middleware('permission:members,can_view');


    Route::get('/sales-pipeline', [SalesPipelineController::class, 'index'])->middleware('permission:sales_pipeline,can_view');
    Route::post('/sales-pipeline/save', [SalesPipelineController::class, 'saveDeal'])->middleware('permission:sales_pipeline,can_add');
    Route::put('/sales-pipeline/{deal_id}/update', [SalesPipelineController::class, 'updateDeal'])->middleware('permission:sales_pipeline,can_edit');
    Route::get('/sales-pipeline/{deal_id}', [SalesPipelineController::class, 'dealData'])->middleware('permission:sales_pipeline,can_view');
    Route::delete('/sales-pipeline/{deal_id}/delete', [SalesPipelineController::class, 'deleteDeal'])->middleware('permission:sales_pipeline,can_delete');

    Route::put('/checklist/{stk_id}/toggle', [SubtaskController::class, 'toggleSubtaskCheckList'])->middleware('permission:my_tasks,can_edit');
    Route::post('/checklist/{tsk_id}/add', [SubtaskController::class, 'addSubtaskCheckList'])->middleware('permission:my_tasks,can_edit');

    Route::prefix('recycle-bin')->group(function () {
        Route::get('/', [RecycleBinController::class, 'list'])->middleware('permission:recycle_bin,can_view');
        Route::post('/restore', [RecycleBinController::class, 'restore'])->middleware('permission:recycle_bin,can_add');
        Route::post('/purge', [RecycleBinController::class, 'purge'])->middleware('permission:recycle_bin,can_delete');
    });

    Route::get('/reports', [ReportController::class, 'index'])->middleware('permission:reports,can_view');
    Route::get('/audit-logs', [AuditLogController::class, 'index'])->middleware('permission:audit_log,can_view')->name('audit-logs.index');

    Route::get('notifications', [NotificationController::class, 'index']);
    Route::put('notification/{not_id}/read', [NotificationController::class, 'click']);
    Route::post('notifications/mark-all-read', [NotificationController::class, 'markAllAsRead']);
});
