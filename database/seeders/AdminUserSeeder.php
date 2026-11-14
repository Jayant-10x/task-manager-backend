<?php

namespace Database\Seeders;

use App\Models\User;
use App\Models\UserModulePermission;
use Illuminate\Database\Seeder;

class AdminUserSeeder extends Seeder {
    public function run(): void {
        $admin = User::firstOrCreate(
            ['usr_email' => 'admin@the10xagency.in'],
            [
                'usr_name' => 'Super Admin',
                'usr_password' => 'admin@1123',
                'usr_phone' => '1234567890',
                'usr_role' => 'admin',
                'usr_status' => true,
                'usr_is_super_admin' => true,
                'usr_created_by' => 'system_seeder~#~0~#~127.0.0',
            ]
        );

        $nestedModules = config('app_modules', []);
        $permissionRecords = [];

        foreach ($nestedModules as $group) {
            foreach ($group as $moduleSlug => $info) {
                if (!is_array($info)) {
                    continue;
                }

                $permissionRecords[] = [
                    'ump_usr_id' => $admin->usr_id,
                    'ump_module_slug' => $moduleSlug,
                    'ump_can_view' => true,
                    'ump_can_add' => true,
                    'ump_can_edit' => true,
                    'ump_can_delete' => true,
                    'ump_created_by' => 'system_seeder~#~0~#~127.0.0',
                    'ump_updated_on' => now(),
                ];
            }
        }

        if (!empty($permissionRecords)) {
            UserModulePermission::upsert(
                $permissionRecords,
                ['ump_usr_id', 'ump_module_slug'],
                ['ump_can_view', 'ump_can_add', 'ump_can_edit', 'ump_can_delete', 'ump_updated_on']
            );
        }

        $this->command->info("Admin permissions seeded for nested app_modules config!");
    }
}
