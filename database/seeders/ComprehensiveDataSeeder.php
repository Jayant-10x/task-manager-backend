<?php

namespace Database\Seeders;

use App\Models\Notification;
use App\Models\Project;
use App\Models\ProjectMember;
use App\Models\SalesPipeline;
use App\Models\Subtask;
use App\Models\Task;
use App\Models\TaskAssignee;
use App\Models\TaskComment;
use App\Models\User;
use App\Models\UserModulePermission;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class ComprehensiveDataSeeder extends Seeder
{
    public function run(): void
    {
        $creatorTag = 'seeder~#~0~#~127.0.0';

        // 1. Create Core Users for each User Type
        $admin = User::updateOrCreate(
            ['usr_email' => 'admin@example.com'],
            [
                'usr_name' => 'Super Admin',
                'usr_password' => 'admin123',
                'usr_phone' => '1234567890',
                'usr_role' => 'admin',
                'usr_dept' => 'Executive',
                'usr_designation' => 'Principal Architect',
                'usr_reporting_to' => null,
                'usr_status' => true,
                'usr_online' => true,
                'usr_is_super_admin' => true,
                'usr_created_by' => $creatorTag,
            ]
        );

        $manager = User::updateOrCreate(
            ['usr_email' => 'manager@example.com'],
            [
                'usr_name' => 'Marcus Manager',
                'usr_password' => 'manager123',
                'usr_phone' => '9876543210',
                'usr_role' => 'manager',
                'usr_dept' => 'Development',
                'usr_designation' => 'Engineering Lead',
                'usr_reporting_to' => $admin->usr_id,
                'usr_status' => true,
                'usr_online' => false,
                'usr_is_super_admin' => false,
                'usr_created_by' => $creatorTag,
            ]
        );

        $employee = User::updateOrCreate(
            ['usr_email' => 'employee@example.com'],
            [
                'usr_name' => 'Emma Employee',
                'usr_password' => 'employee123',
                'usr_phone' => '5551234567',
                'usr_role' => 'employee',
                'usr_dept' => 'Development',
                'usr_designation' => 'Frontend Developer',
                'usr_reporting_to' => $manager->usr_id,
                'usr_status' => true,
                'usr_online' => false,
                'usr_is_super_admin' => false,
                'usr_created_by' => $creatorTag,
            ]
        );

        // 2. Seed Module Permissions for Each User Type
        $nestedModules = config('app_modules', []);
        $permissionRecords = [];

        foreach ($nestedModules as $groupSlug => $group) {
            foreach ($group as $moduleSlug => $info) {
                if (!is_array($info)) {
                    continue;
                }

                // Super Admin Permissions (Full unrestricted access)
                $permissionRecords[] = [
                    'ump_usr_id' => $admin->usr_id,
                    'ump_module_slug' => $moduleSlug,
                    'ump_can_view' => true,
                    'ump_can_add' => true,
                    'ump_can_edit' => true,
                    'ump_can_delete' => true,
                    'ump_created_by' => $creatorTag,
                    'ump_updated_on' => now(),
                ];

                // Manager Permissions
                $managerCanView = in_array($groupSlug, ['workspace', 'team', 'business', 'account']);
                $managerCanAdd = in_array($groupSlug, ['workspace', 'team', 'business', 'account']);
                $managerCanEdit = in_array($groupSlug, ['workspace', 'team', 'business', 'account']);
                $managerCanDelete = ($groupSlug === 'workspace'); // Managers can delete tasks, but not entire projects/members/audit

                $permissionRecords[] = [
                    'ump_usr_id' => $manager->usr_id,
                    'ump_module_slug' => $moduleSlug,
                    'ump_can_view' => $managerCanView,
                    'ump_can_add' => $managerCanAdd,
                    'ump_can_edit' => $managerCanEdit,
                    'ump_can_delete' => $managerCanDelete,
                    'ump_created_by' => $creatorTag,
                    'ump_updated_on' => now(),
                ];

                // Employee Permissions
                $empCanView = false;
                $empCanAdd = false;
                $empCanEdit = false;

                if (in_array($moduleSlug, ['dashboard', 'my_tasks', 'task_board', 'calendar', 'notifications', 'settings'])) {
                    $empCanView = true;
                }
                if (in_array($moduleSlug, ['members', 'projects'])) {
                    $empCanView = true; // Read-only view
                }
                if (in_array($moduleSlug, ['my_tasks', 'task_board'])) {
                    $empCanAdd = true;
                    $empCanEdit = true;
                }

                $permissionRecords[] = [
                    'ump_usr_id' => $employee->usr_id,
                    'ump_module_slug' => $moduleSlug,
                    'ump_can_view' => $empCanView,
                    'ump_can_add' => $empCanAdd,
                    'ump_can_edit' => $empCanEdit,
                    'ump_can_delete' => false,
                    'ump_created_by' => $creatorTag,
                    'ump_updated_on' => now(),
                ];
            }
        }

        UserModulePermission::upsert(
            $permissionRecords,
            ['ump_usr_id', 'ump_module_slug'],
            ['ump_can_view', 'ump_can_add', 'ump_can_edit', 'ump_can_delete', 'ump_updated_on']
        );

        // 3. Projects
        $p1 = Project::updateOrCreate(
            ['pro_code' => 'P-01'],
            [
                'pro_name' => 'Enterprise CRM Platform',
                'pro_description' => 'Complete enterprise task management system and CRM overhaul.',
                'pro_manager' => $manager->usr_id,
                'pro_status' => 'in_progress',
                'pro_progress' => 65,
                'pro_start_date' => Carbon::now()->subDays(15)->toDateString(),
                'pro_due_date' => Carbon::now()->addDays(20)->toDateString(),
                'pro_budget' => 250000,
                'pro_created_by' => $creatorTag,
            ]
        );

        $p2 = Project::updateOrCreate(
            ['pro_code' => 'P-02'],
            [
                'pro_name' => 'Mobile Application UI',
                'pro_description' => 'Cross-platform mobile companion application design and build.',
                'pro_manager' => $admin->usr_id,
                'pro_status' => 'planning',
                'pro_progress' => 20,
                'pro_start_date' => Carbon::now()->subDays(5)->toDateString(),
                'pro_due_date' => Carbon::now()->addDays(35)->toDateString(),
                'pro_budget' => 120000,
                'pro_created_by' => $creatorTag,
            ]
        );

        $p3 = Project::updateOrCreate(
            ['pro_code' => 'P-03'],
            [
                'pro_name' => 'Cloud Infrastructure & CI/CD',
                'pro_description' => 'High-availability deployment architecture and containerization.',
                'pro_manager' => $manager->usr_id,
                'pro_status' => 'completed',
                'pro_progress' => 100,
                'pro_start_date' => Carbon::now()->subDays(40)->toDateString(),
                'pro_due_date' => Carbon::now()->subDays(2)->toDateString(),
                'pro_budget' => 80000,
                'pro_created_by' => $creatorTag,
            ]
        );

        // Project Members
        ProjectMember::firstOrCreate(['pmb_pro_id' => $p1->pro_id, 'pmb_usr_id' => $admin->usr_id]);
        ProjectMember::firstOrCreate(['pmb_pro_id' => $p1->pro_id, 'pmb_usr_id' => $manager->usr_id]);
        ProjectMember::firstOrCreate(['pmb_pro_id' => $p1->pro_id, 'pmb_usr_id' => $employee->usr_id]);

        ProjectMember::firstOrCreate(['pmb_pro_id' => $p2->pro_id, 'pmb_usr_id' => $admin->usr_id]);
        ProjectMember::firstOrCreate(['pmb_pro_id' => $p2->pro_id, 'pmb_usr_id' => $employee->usr_id]);

        ProjectMember::firstOrCreate(['pmb_pro_id' => $p3->pro_id, 'pmb_usr_id' => $manager->usr_id]);

        // 4. Tasks
        $t1 = Task::updateOrCreate(
            ['tsk_code' => 'TK-01'],
            [
                'tsk_pro_id' => $p1->pro_id,
                'tsk_title' => 'Implement Real-time Polling & Notification Sync',
                'tsk_description' => 'Ensure background polling refreshes unread count without session kickback.',
                'tsk_status' => 'in_progress',
                'tsk_category' => 'web_development',
                'tsk_priority' => 'urgent',
                'tsk_assigned_by' => $admin->usr_id,
                'tsk_start_date' => Carbon::now()->subDays(3)->toDateString(),
                'tsk_due_date' => Carbon::now()->addDays(2)->toDateString(),
                'tsk_est_hours' => 16,
                'tsk_spent_hours' => 10,
                'tsk_progress' => 60,
                'tsk_tags' => 'backend,frontend,polling',
                'tsk_created_by' => $creatorTag,
            ]
        );
        TaskAssignee::firstOrCreate(['tsa_tsk_id' => $t1->tsk_id, 'tsa_usr_id' => $employee->usr_id]);
        TaskAssignee::firstOrCreate(['tsa_tsk_id' => $t1->tsk_id, 'tsa_usr_id' => $manager->usr_id]);

        $t2 = Task::updateOrCreate(
            ['tsk_code' => 'TK-02'],
            [
                'tsk_pro_id' => $p1->pro_id,
                'tsk_title' => 'Design Dashboard Analytics & Metric Widgets',
                'tsk_description' => 'Build high-performance counters and dynamic SVG graphs for team output.',
                'tsk_status' => 'to_do',
                'tsk_category' => 'web_development',
                'tsk_priority' => 'high',
                'tsk_assigned_by' => $manager->usr_id,
                'tsk_start_date' => Carbon::now()->toDateString(),
                'tsk_due_date' => Carbon::now()->addDays(5)->toDateString(),
                'tsk_est_hours' => 12,
                'tsk_spent_hours' => 0,
                'tsk_progress' => 0,
                'tsk_tags' => 'frontend,ui,charts',
                'tsk_created_by' => $creatorTag,
            ]
        );
        TaskAssignee::firstOrCreate(['tsa_tsk_id' => $t2->tsk_id, 'tsa_usr_id' => $employee->usr_id]);

        $t3 = Task::updateOrCreate(
            ['tsk_code' => 'TK-03'],
            [
                'tsk_pro_id' => $p1->pro_id,
                'tsk_title' => 'Review Security & RBAC Permission Boundaries',
                'tsk_description' => 'Verify middleware blocks unauthorized access for employee and manager roles.',
                'tsk_status' => 'in_review',
                'tsk_category' => 'custom_remark',
                'tsk_priority' => 'urgent',
                'tsk_assigned_by' => $admin->usr_id,
                'tsk_start_date' => Carbon::now()->subDays(2)->toDateString(),
                'tsk_due_date' => Carbon::now()->addDays(1)->toDateString(),
                'tsk_est_hours' => 8,
                'tsk_spent_hours' => 7,
                'tsk_progress' => 90,
                'tsk_tags' => 'security,audit,rbac',
                'tsk_created_by' => $creatorTag,
            ]
        );
        TaskAssignee::firstOrCreate(['tsa_tsk_id' => $t3->tsk_id, 'tsa_usr_id' => $manager->usr_id]);
        TaskAssignee::firstOrCreate(['tsa_tsk_id' => $t3->tsk_id, 'tsa_usr_id' => $admin->usr_id]);

        $t4 = Task::updateOrCreate(
            ['tsk_code' => 'TK-04'],
            [
                'tsk_pro_id' => $p2->pro_id,
                'tsk_title' => 'Video Walkthrough of Task Management Suite',
                'tsk_description' => 'Produce a 2-minute feature tour showcasing kanban cards and report filters.',
                'tsk_status' => 'to_do',
                'tsk_category' => 'video_editing',
                'tsk_priority' => 'medium',
                'tsk_assigned_by' => $admin->usr_id,
                'tsk_start_date' => Carbon::now()->addDays(1)->toDateString(),
                'tsk_due_date' => Carbon::now()->addDays(10)->toDateString(),
                'tsk_est_hours' => 20,
                'tsk_spent_hours' => 2,
                'tsk_progress' => 10,
                'tsk_tags' => 'marketing,demo',
                'tsk_created_by' => $creatorTag,
            ]
        );
        TaskAssignee::firstOrCreate(['tsa_tsk_id' => $t4->tsk_id, 'tsa_usr_id' => $employee->usr_id]);

        $t5 = Task::updateOrCreate(
            ['tsk_code' => 'TK-05'],
            [
                'tsk_pro_id' => $p3->pro_id,
                'tsk_title' => 'Setup Automated Healthcheck & Database Backups',
                'tsk_description' => 'Configure cron scripts for daily MySQL snapshot backups.',
                'tsk_status' => 'completed',
                'tsk_category' => 'web_development',
                'tsk_priority' => 'low',
                'tsk_assigned_by' => $manager->usr_id,
                'tsk_start_date' => Carbon::now()->subDays(10)->toDateString(),
                'tsk_due_date' => Carbon::now()->subDays(1)->toDateString(),
                'tsk_est_hours' => 6,
                'tsk_spent_hours' => 6,
                'tsk_progress' => 100,
                'tsk_completed_at' => Carbon::now()->subDays(1)->toDateTimeString(),
                'tsk_tags' => 'devops,backup',
                'tsk_created_by' => $creatorTag,
            ]
        );
        TaskAssignee::firstOrCreate(['tsa_tsk_id' => $t5->tsk_id, 'tsa_usr_id' => $manager->usr_id]);

        // 5. Subtasks
        Subtask::updateOrCreate(
            ['stk_tsk_id' => $t1->tsk_id, 'stk_title' => 'Configure push notification fallbacks'],
            ['stk_done' => true, 'stk_sort_order' => 1]
        );
        Subtask::updateOrCreate(
            ['stk_tsk_id' => $t1->tsk_id, 'stk_title' => 'Verify unread counter decrements on read'],
            ['stk_done' => false, 'stk_sort_order' => 2]
        );

        Subtask::updateOrCreate(
            ['stk_tsk_id' => $t2->tsk_id, 'stk_title' => 'Design responsive layout for charts'],
            ['stk_done' => false, 'stk_sort_order' => 1]
        );

        // 6. Comments
        TaskComment::firstOrCreate(
            ['tsc_tsk_id' => $t1->tsk_id, 'tsc_author' => $manager->usr_id],
            [
                'tsc_comment' => 'Integration with backend /api/notifications is verified and functional.',
                'tsc_mentions' => [$employee->usr_id],
            ]
        );
        TaskComment::firstOrCreate(
            ['tsc_tsk_id' => $t1->tsk_id, 'tsc_author' => $employee->usr_id],
            [
                'tsc_comment' => 'Received the updates, frontend state updates dynamically.',
                'tsc_mentions' => [$manager->usr_id],
            ]
        );

        // 7. Sales Pipeline (Deals)
        SalesPipeline::updateOrCreate(
            ['ssp_code' => 'D-01'],
            [
                'ssp_name' => 'Enterprise License Agreement',
                'ssp_account' => 'Acme Corporation',
                'ssp_owner_id' => $manager->usr_id,
                'ssp_stage' => 'negotiable',
                'ssp_value' => 180000,
                'ssp_probability' => 80,
                'ssp_close_date' => Carbon::now()->addDays(15)->toDateString(),
                'ssp_created_by' => $creatorTag,
            ]
        );

        SalesPipeline::updateOrCreate(
            ['ssp_code' => 'D-02'],
            [
                'ssp_name' => 'Cloud Workspace Annual Plan',
                'ssp_account' => 'Global Logistics Inc',
                'ssp_owner_id' => $admin->usr_id,
                'ssp_stage' => 'proposal',
                'ssp_value' => 75000,
                'ssp_probability' => 60,
                'ssp_close_date' => Carbon::now()->addDays(25)->toDateString(),
                'ssp_created_by' => $creatorTag,
            ]
        );

        SalesPipeline::updateOrCreate(
            ['ssp_code' => 'D-03'],
            [
                'ssp_name' => 'Mobile App Support Contract',
                'ssp_account' => 'Nexus Retail Tech',
                'ssp_owner_id' => $manager->usr_id,
                'ssp_stage' => 'won',
                'ssp_value' => 95000,
                'ssp_probability' => 100,
                'ssp_close_date' => Carbon::now()->subDays(5)->toDateString(),
                'ssp_created_by' => $creatorTag,
            ]
        );

        // 8. Notifications
        Notification::updateOrCreate(
            ['not_usr_id' => $admin->usr_id, 'not_title' => 'System Backup Completed'],
            [
                'not_kind' => 'system',
                'not_body' => 'Daily automated database snapshot succeeded.',
                'not_meta_info' => json_encode(['level' => 'info']),
                'not_actor' => 'System',
                'not_is_read' => false,
            ]
        );

        Notification::updateOrCreate(
            ['not_usr_id' => $manager->usr_id, 'not_title' => 'New Task Assigned: TK-03'],
            [
                'not_kind' => 'task',
                'not_body' => 'Super Admin assigned you to task TK-03.',
                'not_meta_info' => json_encode(['task_code' => 'TK-03']),
                'not_actor' => 'Super Admin',
                'not_is_read' => false,
            ]
        );

        Notification::updateOrCreate(
            ['not_usr_id' => $employee->usr_id, 'not_title' => 'New Task Assigned: TK-01'],
            [
                'not_kind' => 'task',
                'not_body' => 'You were assigned to task TK-01: Real-time Polling.',
                'not_meta_info' => json_encode(['task_code' => 'TK-01']),
                'not_actor' => 'Marcus Manager',
                'not_is_read' => false,
            ]
        );
    }
}
