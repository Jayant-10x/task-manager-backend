<?php

return [
    'workspace' => [
        'parent_label' => 'WORKSPACE',
        'dashboard' => [
            'label' => 'Dashboard',
            'icon' => 'dash',
            'in_sidebar' => true,
            'route' => 'dashboard',
            'description' => 'Overview of your work'
        ],
        'my_tasks' => [
            'label' => 'My Tasks',
            'icon' => 'mytask',
            'in_sidebar' => true,
            'route' => 'my-tasks',
            'description' => 'Tasks assigned to you'
        ],
        'assigned_by_me' => [
            'label' => 'Assigned By Me',
            'icon' => 'out',
            'in_sidebar' => true,
            'route' => 'assigned-by-me',
            'description' => 'Tasks you created'
        ],
        'task_board' => [
            'label' => 'Task Board',
            'icon' => 'board',
            'in_sidebar' => true,
            'route' => 'task-board',
            'description' => 'Drag cards to change status'
        ],
        'calendar' => [
            'label' => 'Calendar',
            'icon' => 'calendar',
            'in_sidebar' => true,
            'route' => 'calendar',
            'description' => 'Tasks by due date'
        ],
    ],
    'team' => [
        'parent_label' => 'TEAM',
        'all_tasks' => [
            'label' => 'All Tasks',
            'icon' => 'list',
            'in_sidebar' => true,
            'route' => 'all-tasks',
            'description' => 'Every task in the workspace'
        ],
        'team_tasks' => [
            'label' => 'Team Tasks',
            'icon' => 'team',
            'in_sidebar' => true,
            'route' => 'team-tasks',
            'description' => 'Open work per person'
        ],
        'members' => [
            'label' => 'Members',
            'icon' => 'team',
            'in_sidebar' => true,
            'route' => 'members',
            'description' => 'People in the workspace'
        ],
    ],
    'business' => [
        'parent_label' => 'BUSINESS',
        'projects' => [
            'label' => 'Projects',
            'icon' => 'project',
            'in_sidebar' => true,
            'route' => 'projects',
            'description' => 'Client and internal projects'
        ],
        'sales_pipeline' => [
            'label' => 'Sales Pipeline',
            'icon' => 'deal',
            'in_sidebar' => true,
            'route' => 'sales-pipeline',
            'description' => 'Open opportunities and closed deals'
        ],
        'reports' => [
            'label' => 'Reports',
            'icon' => 'report',
            'in_sidebar' => true,
            'route' => 'reports',
            'description' => 'Team performance and project health'
        ],
    ],
    'admin' => [
        'parent_label' => 'ADMIN',
        'password_reset_request' => [
            'label' => 'Password Resets',
            'icon' => 'key', // solar:eye-outline
            'in_sidebar' => true,
            'route' => 'password-reset-requests',
            'description' => 'Forgot-password requests waiting for an Admin'
        ],
        'audit_log' => [
            'label' => 'Audit Log',
            'icon' => 'eye', // solar:eye-outline
            'in_sidebar' => true,
            'route' => 'audit-logs',
            'description' => 'Who changed what, and when'
        ],
        'recycle_bin' => [
            'label' => 'Recycle Bin',
            'icon' => 'del',
            'in_sidebar' => true,
            'route' => 'recycle-bin',
            'description' => 'Deleted items are kept here for 30 days'
        ],
    ],
    'account' => [
        'parent_label' => 'ACCOUNT',
        'notifications' => [
            'label' => 'Notifications',
            'icon' => 'bell', //ri:notification-4-line
            'in_sidebar' => true,
            'route' => 'notifications',
            'description' => 'Updates on your tasks'
        ],
        'settings' => [
            'label' => 'Settings',
            'icon' => 'gear',
            'in_sidebar' => true,
            'route' => 'settings',
            'description' => 'Your profile, password and session'
        ],
    ],
];
