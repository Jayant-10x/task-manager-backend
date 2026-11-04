<?php
return [
    'DB_DATE_TIME_FORMAT' => 'Y-m-d H:i:s',
    'DB_DATE_FORMAT_VIEW' => 'd-m-Y',
    'PER_PAGE_ITEM_COUNT' => 20,

    'TABLE_PREFIX' => 'tms_',
    'ROLES' => [
        'admin' => 'Admin',
        'manager' => 'Manager',
        'employee' => 'Employee',
    ],

    'DEFAULT_DEPTH' => 3,

    'PROJECT_STATUS' => [
        'planning' => 'Planning',
        'in_progress' => 'In progress',
        'at_risk' => 'At Risk',
        'completed' => 'Completed',
        'on_hold' => 'On Hold',
    ],

    'TASK_STATUS' => [
        'to_do' => 'To Do',
        'in_progress' => 'In progress',
        'on_hold' => 'On Hold',
        'in_review' => 'In Review',
        'completed' => 'Completed',
        'cancelled' => 'Cancelled',
    ],

    'TASK_PRIORITY' => [
        'low' => 'Low',
        'medium' => 'Medium',
        'high' => 'High',
        'urgent' => 'Urgent',
    ],

    'TASK_CATEGORY' => [
        'web_development' => 'Web Development',
        'smm' => 'SMM',
        'e_commerce' => 'E-Commerce',
        'video_editing' => 'Video Editing /Graphic',
        'human_resource' => 'Human Resource',
        'seo' => 'SEO',
        'custom_remark' => 'Custom Remark',
    ],

    'DEAL_STAGES' => [
        'discovery' => 'Discovery',
        'qualified' => 'Qualified',
        'proposal' => 'Proposal',
        'negotiable' => 'Negotiable',
        'won' => 'Won',
        'lost' => 'Lost',
    ],

    'PASSWORD_RESET_STATUS' => [
        'pending' => 'Pending',
        'reset_successfully' => 'Reset Successfully',
        'reject' => 'Reject',
    ],

    'PASSWORD_RESET_PRIORITY' => [
        'low' => 'Low',
        'medium' => 'Medium',
        'high' => 'High',
    ],

    'PASSWORD_RESET_ADMIN_ACTION' => [
        'notified' => 'Notified',
        'opened' => 'Opened',
        'rejected' => 'Rejected',
        'resets' => 'Resets',
    ]
];
