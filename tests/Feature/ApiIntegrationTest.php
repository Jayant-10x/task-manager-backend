<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class ApiIntegrationTest extends TestCase
{
    use DatabaseTransactions;

    protected ?string $token = null;
    protected ?User $adminUser = null;

    protected function setUp(): void
    {
        parent::setUp();

        // Resolve or create admin user
        $this->adminUser = User::query()->where('usr_email', 'admin@example.com')->first();
        if (!$this->adminUser) {
            $this->adminUser = User::create([
                'usr_name' => 'Super Admin',
                'usr_email' => 'admin@example.com',
                'usr_password' => Hash::make('Admin@123456'),
                'usr_role' => 'admin',
                'usr_is_super_admin' => 1,
                'usr_status' => 1,
            ]);
        } else {
            $this->adminUser->update([
                'usr_password' => Hash::make('Admin@123456'),
            ]);
        }
    }

    protected function getAuthHeaders(): array
    {
        if (!$this->token) {
            $this->token = $this->adminUser->createToken('test_suite_token')->plainTextToken;
        }
        return [
            'Authorization' => "Bearer {$this->token}",
            'Accept' => 'application/json',
        ];
    }

    public function test_health_check_returns_success(): void
    {
        $response = $this->getJson('/api/health');
        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'message' => 'Laravel API connected successfully',
            ]);
    }

    public function test_login_authenticates_and_returns_bearer_token(): void
    {
        $response = $this->postJson('/api/login', [
            'usr_email' => 'admin@example.com',
            'usr_password' => 'Admin@123456',
        ]);

        $response->assertStatus(200)
            ->assertJsonStructure([
                'access_token',
                'token_type',
                'user' => [
                    'usr_id',
                    'usr_name',
                    'usr_email',
                    'usr_role',
                ],
            ]);
    }

    public function test_inactive_user_cannot_login(): void
    {
        $inactiveUser = User::create([
            'usr_name' => 'Inactive User',
            'usr_email' => 'inactive_' . uniqid() . '@example.com',
            'usr_password' => Hash::make('Secret@123'),
            'usr_role' => 'employee',
            'usr_status' => 0,
        ]);

        $response = $this->postJson('/api/login', [
            'usr_email' => $inactiveUser->usr_email,
            'usr_password' => 'Secret@123',
        ]);

        $response->assertStatus(403)
            ->assertJson([
                'message' => 'Account is inactive.',
            ]);
    }

    public function test_inactive_user_token_is_rejected_and_revoked(): void
    {
        $inactiveUser = User::create([
            'usr_name' => 'Inactive User 2',
            'usr_email' => 'inactive2_' . uniqid() . '@example.com',
            'usr_password' => Hash::make('Secret@123'),
            'usr_role' => 'employee',
            'usr_status' => 0,
        ]);

        $token = $inactiveUser->createToken('test_inactive_token')->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/user');

        $response->assertStatus(403)
            ->assertJson([
                'message' => 'Account is inactive.',
            ]);

        // Token should be revoked
        $this->assertDatabaseMissing('personal_access_tokens', [
            'tokenable_id' => $inactiveUser->usr_id,
        ]);
    }

    public function test_profile_returns_authenticated_user_with_permissions(): void
    {
        $response = $this->withHeaders($this->getAuthHeaders())->getJson('/api/user');
        $response->assertStatus(200)
            ->assertJsonStructure([
                'user' => [
                    'usr_id',
                    'usr_name',
                    'usr_email',
                    'usr_role',
                ],
                'permissions',
            ]);
    }

    public function test_dashboard_returns_analytical_overview(): void
    {
        $response = $this->withHeaders($this->getAuthHeaders())->getJson('/api/dashboard');
        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    'banner',
                    'metrics',
                    'my_focus_list',
                    'upcoming_deadlines',
                    'team_workload',
                    'task_mix',
                    'recent_activities',
                ],
            ]);
    }

    public function test_task_crud_lifecycle_and_recycle_bin(): void
    {
        $headers = $this->getAuthHeaders();

        // 1. Create Task
        $createResponse = $this->withHeaders($headers)->postJson('/api/task/save', [
            'title' => 'Feature Test Task',
            'description' => 'Created via PHPUnit feature test',
            'category' => 'web_development',
            'priority' => 'high',
            'status' => 'to_do',
            'start_date' => '2026-11-04',
            'due_date' => '2026-11-30',
            'est_hours' => 8,
            'assignee_ids' => [$this->adminUser->usr_id],
        ]);
        $createResponse->assertStatus(201);
        $savedTask = $createResponse->json('savedTask');
        $this->assertNotNull($savedTask);
        $taskId = $savedTask['tsk_id'];

        // 2. Update Status
        $statusResponse = $this->withHeaders($headers)->putJson("/api/task/{$taskId}/status_update", [
            'status' => 'in_progress',
        ]);
        $statusResponse->assertStatus(200);

        // 3. View Task
        $viewResponse = $this->withHeaders($headers)->getJson("/api/task/{$taskId}/view");
        $viewResponse->assertStatus(200);
        $this->assertEquals('in_progress', $viewResponse->json('task_data.tsk_status'));

        // 4. Checklist Item Add & Toggle
        $addChecklist = $this->withHeaders($headers)->postJson("/api/checklist/{$taskId}/add", [
            'title' => 'PHPUnit Subtask',
        ]);
        $addChecklist->assertStatus(200);
        $subtaskId = $addChecklist->json('subtask.stk_id');

        $toggleChecklist = $this->withHeaders($headers)->putJson("/api/checklist/{$subtaskId}/toggle");
        $toggleChecklist->assertStatus(200);

        // 5. Delete Subtask
        $delSubtask = $this->withHeaders($headers)->deleteJson("/api/subtask/{$subtaskId}/delete");
        $delSubtask->assertStatus(200);

        // 6. Delete Task (soft delete -> recycle bin)
        $delTask = $this->withHeaders($headers)->deleteJson("/api/task/{$taskId}/delete");
        $delTask->assertStatus(200);
    }

    public function test_project_crud_lifecycle(): void
    {
        $headers = $this->getAuthHeaders();

        // Create
        $createRes = $this->withHeaders($headers)->postJson('/api/project/save', [
            'name' => 'PHPUnit Project',
            'description' => 'Feature testing project lifecycle',
            'manager' => $this->adminUser->usr_id,
            'status' => 'planning',
            'progress' => 0,
            'budget' => 25000,
            'start_date' => '2026-11-04',
            'due_date' => '2026-12-31',
            'project_members' => [$this->adminUser->usr_id],
        ]);
        $createRes->assertStatus(201);
        $projectId = $createRes->json('project.pro_id');

        // View
        $viewRes = $this->withHeaders($headers)->getJson("/api/project/{$projectId}/view");
        $viewRes->assertStatus(200);

        // Update
        $updateRes = $this->withHeaders($headers)->putJson("/api/project/{$projectId}/update", [
            'name' => 'PHPUnit Project (Updated)',
            'description' => 'Updated via test',
            'manager' => $this->adminUser->usr_id,
            'status' => 'in_progress',
            'progress' => 50,
            'budget' => 30000,
        ]);
        $updateRes->assertStatus(200);

        // Delete
        $delRes = $this->withHeaders($headers)->deleteJson("/api/project/{$projectId}/delete");
        $delRes->assertStatus(200);
    }

    public function test_sales_pipeline_crud_lifecycle(): void
    {
        $headers = $this->getAuthHeaders();

        // Create
        $createRes = $this->withHeaders($headers)->postJson('/api/sales-pipeline/save', [
            'deal_name' => 'PHPUnit Deal',
            'stage' => 'discovery',
            'account_client' => 'Global Media',
            'owner' => $this->adminUser->usr_id,
            'value' => 80000,
            'probability' => 30,
            'expected_close_date' => '2026-12-01',
        ]);
        $createRes->assertStatus(201);
        $dealId = $createRes->json('deal_id');

        // View
        $viewRes = $this->withHeaders($headers)->getJson("/api/sales-pipeline/{$dealId}");
        $viewRes->assertStatus(200);

        // Update
        $updateRes = $this->withHeaders($headers)->putJson("/api/sales-pipeline/{$dealId}/update", [
            'deal_name' => 'PHPUnit Deal (Negotiation)',
            'stage' => 'negotiable',
            'value' => 95000,
            'probability' => 60,
        ]);
        $updateRes->assertStatus(200);

        // Delete
        $delRes = $this->withHeaders($headers)->deleteJson("/api/sales-pipeline/{$dealId}/delete");
        $delRes->assertStatus(200);
    }

    public function test_reports_returns_correct_kpi_payload(): void
    {
        $response = $this->withHeaders($this->getAuthHeaders())->getJson('/api/reports');
        $response->assertStatus(200)
            ->assertJsonStructure([
                'ok',
                'data' => [
                    'totals' => [
                        'total',
                        'todo',
                        'inProgress',
                        'review',
                        'completed',
                        'cancelled',
                        'overdue',
                        'dueToday',
                        'completionRate',
                        'avgDays',
                    ],
                    'trend',
                    'people',
                    'projects',
                ],
            ]);
    }

    public function test_permissions_matrix_returns_complete_schema(): void
    {
        $response = $this->withHeaders($this->getAuthHeaders())->getJson('/api/permissions');
        $response->assertStatus(200)
            ->assertJsonStructure([
                'ok',
                'perms',
                'users',
                'ready',
            ]);
    }

    public function test_audit_logs_and_recycle_bin_endpoints(): void
    {
        $headers = $this->getAuthHeaders();

        // Audit Logs
        $auditRes = $this->withHeaders($headers)->getJson('/api/audit-logs');
        $auditRes->assertStatus(200)
            ->assertJsonStructure([
                'current_page',
                'data',
                'total',
            ]);

        // Recycle Bin
        $recycleRes = $this->withHeaders($headers)->getJson('/api/recycle-bin');
        $recycleRes->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'recycled_items',
                'meta' => [
                    'retention_days',
                    'can_purge',
                ],
            ]);
    }

    public function test_notifications_endpoint(): void
    {
        $response = $this->withHeaders($this->getAuthHeaders())->getJson('/api/notifications');
        $response->assertStatus(200)
            ->assertJsonStructure([
                'status',
                'notifications',
                'unread_count',
            ]);
    }

    public function test_non_admin_cannot_set_role_or_super_admin(): void
    {
        $employee = User::create([
            'usr_name' => 'Regression Employee',
            'usr_email' => 'reg-emp-' . uniqid() . '@example.com',
            'usr_password' => Hash::make('Emp@123456'),
            'usr_role' => 'employee',
            'usr_is_super_admin' => 0,
            'usr_status' => 1,
        ]);
        $empHeaders = [
            'Authorization' => 'Bearer ' . $employee->createToken('test')->plainTextToken,
            'Accept' => 'application/json',
        ];

        $this->withHeaders($empHeaders)
            ->putJson("/api/user/{$employee->usr_id}/role", ['role' => 'employee'])
            ->assertStatus(403);

        $this->withHeaders($empHeaders)
            ->putJson("/api/user/{$employee->usr_id}/super-admin", ['is_super_admin' => false])
            ->assertStatus(403);

        // Even non-super admin cannot toggle super-admin flag or demote self
        $manager = User::create([
            'usr_name' => 'Regression Manager',
            'usr_email' => 'reg-mgr-' . uniqid() . '@example.com',
            'usr_password' => Hash::make('Mgr@123456'),
            'usr_role' => 'manager',
            'usr_is_super_admin' => 0,
            'usr_status' => 1,
        ]);
        $mgrHeaders = [
            'Authorization' => 'Bearer ' . $manager->createToken('test')->plainTextToken,
            'Accept' => 'application/json',
        ];

        $this->app['auth']->forgetGuards();
        $this->withHeaders($mgrHeaders)
            ->putJson("/api/user/{$employee->usr_id}/role", ['role' => 'manager'])
            ->assertStatus(403);

        $this->app['auth']->forgetGuards();
        $this->withHeaders($this->getAuthHeaders())
            ->putJson("/api/user/{$this->adminUser->usr_id}/super-admin", ['is_super_admin' => false])
            ->assertStatus(403);
    }

    public function test_delete_routes_enforce_can_delete_permission(): void
    {
        $employee = User::create([
            'usr_name' => 'Delete Guard Employee',
            'usr_email' => 'del-emp-' . uniqid() . '@example.com',
            'usr_password' => Hash::make('Emp@123456'),
            'usr_role' => 'employee',
            'usr_is_super_admin' => 0,
            'usr_status' => 1,
        ]);

        \App\Models\UserModulePermission::where('ump_usr_id', $employee->usr_id)
            ->where('ump_module_slug', 'projects')
            ->update(['ump_can_view' => true, 'ump_can_add' => false, 'ump_can_edit' => false, 'ump_can_delete' => false]);

        \App\Models\UserModulePermission::where('ump_usr_id', $employee->usr_id)
            ->where('ump_module_slug', 'my_tasks')
            ->update(['ump_can_view' => true, 'ump_can_add' => true, 'ump_can_edit' => true, 'ump_can_delete' => false]);

        $empHeaders = [
            'Authorization' => 'Bearer ' . $employee->createToken('test')->plainTextToken,
            'Accept' => 'application/json',
        ];

        $this->withHeaders($empHeaders)->deleteJson('/api/project/99999/delete')->assertStatus(403);
        $this->withHeaders($empHeaders)->deleteJson('/api/task/99999/delete')->assertStatus(403);
    }

    public function test_employee_project_visibility_includes_shared_and_blocks_unshared(): void
    {
        $employee = User::create([
            'usr_name' => 'Visibility Employee',
            'usr_email' => 'vis-emp-' . uniqid() . '@example.com',
            'usr_password' => Hash::make('Emp@123456'),
            'usr_role' => 'employee',
            'usr_is_super_admin' => 0,
            'usr_status' => 1,
        ]);

        \App\Models\UserModulePermission::where('ump_usr_id', $employee->usr_id)
            ->where('ump_module_slug', 'projects')
            ->update(['ump_can_view' => true, 'ump_can_add' => false, 'ump_can_edit' => false, 'ump_can_delete' => false]);

        $sharedRes = $this->withHeaders($this->getAuthHeaders())->postJson('/api/project/save', [
            'name' => 'Shared Visibility Project ' . uniqid(),
            'description' => 'Project with employee member',
            'status' => 'in_progress',
            'manager' => $this->adminUser->usr_id,
            'project_members' => [$employee->usr_id],
        ]);
        $sharedRes->assertStatus(201);
        $sharedId = $sharedRes->json('project.pro_id');

        $privateRes = $this->withHeaders($this->getAuthHeaders())->postJson('/api/project/save', [
            'name' => 'Private Admin Project ' . uniqid(),
            'description' => 'Admin only',
            'status' => 'planning',
            'manager' => $this->adminUser->usr_id,
            'project_members' => [$this->adminUser->usr_id],
        ]);
        $privateRes->assertStatus(201);
        $privateId = $privateRes->json('project.pro_id');

        $empHeaders = [
            'Authorization' => 'Bearer ' . $employee->createToken('test')->plainTextToken,
            'Accept' => 'application/json',
        ];
        $this->app['auth']->forgetGuards();

        $listRes = $this->withHeaders($empHeaders)->getJson('/api/projects');
        $listRes->assertStatus(200);
        $visibleIds = collect($listRes->json('data'))->pluck('pro_id')->all();
        $this->assertContains($sharedId, $visibleIds);
        $this->assertNotContains($privateId, $visibleIds);

        $this->withHeaders($empHeaders)->getJson("/api/project/{$sharedId}/view")->assertStatus(200);
        $this->withHeaders($empHeaders)->getJson("/api/project/{$privateId}/view")->assertStatus(403);
    }

    public function test_unrelated_employee_cannot_mutate_another_users_task(): void
    {
        $employee = User::create([
            'usr_name' => 'Unrelated Employee',
            'usr_email' => 'unrel-emp-' . uniqid() . '@example.com',
            'usr_password' => Hash::make('Emp@123456'),
            'usr_role' => 'employee',
            'usr_is_super_admin' => 0,
            'usr_status' => 1,
        ]);

        \App\Models\UserModulePermission::where('ump_usr_id', $employee->usr_id)
            ->where('ump_module_slug', 'my_tasks')
            ->update(['ump_can_view' => true, 'ump_can_add' => true, 'ump_can_edit' => true, 'ump_can_delete' => false]);

        $taskRes = $this->withHeaders($this->getAuthHeaders())->postJson('/api/task/save', [
            'title' => 'Admin Exclusive Task',
            'description' => 'Assigned only to admin',
            'category' => 'web_development',
            'priority' => 'medium',
            'status' => 'to_do',
            'start_date' => '2026-11-10',
            'due_date' => '2026-11-20',
            'assignee_ids' => [$this->adminUser->usr_id],
        ]);
        $taskRes->assertStatus(201);
        $taskId = $taskRes->json('savedTask.tsk_id');

        $empHeaders = [
            'Authorization' => 'Bearer ' . $employee->createToken('test')->plainTextToken,
            'Accept' => 'application/json',
        ];
        $this->app['auth']->forgetGuards();

        $this->withHeaders($empHeaders)
            ->putJson("/api/task/{$taskId}/status_update", ['status' => 'completed'])
            ->assertStatus(403);
    }

    public function test_task_completed_at_set_on_complete_and_cleared_on_reopen(): void
    {
        $headers = $this->getAuthHeaders();
        $taskRes = $this->withHeaders($headers)->postJson('/api/task/save', [
            'title' => 'Completion Timestamp Task',
            'description' => 'Verify tsk_completed_at transitions',
            'category' => 'web_development',
            'priority' => 'high',
            'status' => 'in_progress',
            'start_date' => '2026-11-10',
            'due_date' => '2026-11-25',
            'assignee_ids' => [$this->adminUser->usr_id],
        ]);
        $taskRes->assertStatus(201);
        $taskId = $taskRes->json('savedTask.tsk_id');

        $this->withHeaders($headers)
            ->putJson("/api/task/{$taskId}/status_update", ['status' => 'completed'])
            ->assertStatus(200);

        $afterComplete = $this->withHeaders($headers)->getJson("/api/task/{$taskId}/view");
        $afterComplete->assertStatus(200);
        $this->assertNotNull($afterComplete->json('task_data.tsk_completed_at'));

        $this->withHeaders($headers)
            ->putJson("/api/task/{$taskId}/status_update", ['status' => 'in_progress'])
            ->assertStatus(200);

        $afterReopen = $this->withHeaders($headers)->getJson("/api/task/{$taskId}/view");
        $afterReopen->assertStatus(200);
        $this->assertNull($afterReopen->json('task_data.tsk_completed_at'));
    }

    public function test_audit_logs_filter_by_user_id_and_search_query(): void
    {
        $res = $this->withHeaders($this->getAuthHeaders())
            ->getJson("/api/audit-logs?user_id={$this->adminUser->usr_id}&q=Task");

        $res->assertStatus(200)
            ->assertJsonStructure([
                'current_page',
                'data',
                'total',
            ]);
    }

    public function test_task_comment_mentions_notify_mentioned_users_cleanly(): void
    {
        $assignee = User::create([
            'usr_name' => 'Task Assignee User',
            'usr_email' => 'cmt-ass-' . uniqid() . '@example.com',
            'usr_password' => Hash::make('Emp@123456'),
            'usr_role' => 'employee',
            'usr_is_super_admin' => 0,
            'usr_status' => 1,
        ]);
        $mentionedOutsideUser = User::create([
            'usr_name' => 'Outside Mentioned Manager',
            'usr_email' => 'cmt-men-' . uniqid() . '@example.com',
            'usr_password' => Hash::make('Mgr@123456'),
            'usr_role' => 'manager',
            'usr_is_super_admin' => 0,
            'usr_status' => 1,
        ]);

        $taskRes = $this->withHeaders($this->getAuthHeaders())->postJson('/api/task/save', [
            'title' => 'Mention Notification Task',
            'description' => 'Verify comment mentions',
            'category' => 'web_development',
            'priority' => 'medium',
            'status' => 'to_do',
            'start_date' => '2026-11-10',
            'due_date' => '2026-11-25',
            'assignee_ids' => [$assignee->usr_id],
        ]);
        $taskRes->assertStatus(201);
        $taskId = $taskRes->json('savedTask.tsk_id');
        $taskCode = $taskRes->json('savedTask.tsk_code');

        $commentBody = "Checking status with @{$mentionedOutsideUser->usr_name} and @{$assignee->usr_name}";
        $res = $this->withHeaders($this->getAuthHeaders())->postJson("/api/task/{$taskId}/comment", [
            'comment' => $commentBody,
            'mentions' => [$mentionedOutsideUser->usr_id, $assignee->usr_id],
        ]);

        $res->assertStatus(201)
            ->assertJsonPath('data.tsc_comment', $commentBody)
            ->assertJsonPath('data.tsc_mentions', [$mentionedOutsideUser->usr_id, $assignee->usr_id]);

        $this->assertEqualsCanonicalizing(
            [$mentionedOutsideUser->usr_name, $assignee->usr_name],
            $res->json('mentioned')
        );

        // Non-assignee mentioned user must receive a 'mention' notification without debug '(mention check)' text
        $mentionNotif = \App\Models\Notification::where('not_usr_id', $mentionedOutsideUser->usr_id)
            ->where('not_kind', 'mention')
            ->latest('not_id')
            ->first();
        $this->assertNotNull($mentionNotif);
        $this->assertStringContainsString($taskCode, $mentionNotif->not_title);
        $this->assertEquals($commentBody, $mentionNotif->not_body);
        $this->assertStringNotContainsString('(mention check)', $mentionNotif->not_body);

        // Mentioned assignee must get exactly 1 mention notification (no duplicate comment notification)
        $assigneeNotifs = \App\Models\Notification::where('not_usr_id', $assignee->usr_id)
            ->where('not_body', $commentBody)
            ->count();
        $this->assertEquals(1, $assigneeNotifs);
    }

    public function test_task_board_and_calendar_routes_enforce_own_module_permissions(): void
    {
        $employee = User::create([
            'usr_name' => 'Board Guard Employee',
            'usr_email' => 'board-guard-' . uniqid() . '@example.com',
            'usr_password' => Hash::make('Emp@123456'),
            'usr_role' => 'employee',
            'usr_is_super_admin' => 0,
            'usr_status' => 1,
        ]);

        // Employee retains my_tasks.can_view = 1, while task_board & calendar are revoked
        \App\Models\UserModulePermission::where('ump_usr_id', $employee->usr_id)
            ->where('ump_module_slug', 'my_tasks')
            ->update(['ump_can_view' => true]);
        \App\Models\UserModulePermission::where('ump_usr_id', $employee->usr_id)
            ->whereIn('ump_module_slug', ['task_board', 'calendar'])
            ->update([
                'ump_can_view' => false,
                'ump_can_add' => false,
                'ump_can_edit' => false,
                'ump_can_delete' => false,
            ]);

        $empHeaders = [
            'Authorization' => 'Bearer ' . $employee->createToken('test')->plainTextToken,
            'Accept' => 'application/json',
        ];

        $this->withHeaders($empHeaders)->getJson('/api/my-tasks')->assertStatus(200);
        $this->withHeaders($empHeaders)->getJson('/api/task-board')->assertStatus(403);
        $this->withHeaders($empHeaders)->getJson('/api/calendar-tasks')->assertStatus(403);
    }
}
