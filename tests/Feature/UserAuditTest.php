<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class UserAuditTest extends TestCase {
    use DatabaseTransactions;

    protected User $admin;

    protected function setUp(): void {
        parent::setUp();

        $this->admin = User::firstOrCreate(
            ['usr_email' => 'admin_audit_test@the10xagency.in'],
            [
                'usr_name' => 'Admin Audit Test',
                'usr_password' => Hash::make('password123'),
                'usr_role' => 'admin',
                'usr_is_super_admin' => 1,
                'usr_status' => 1,
            ]
        );
    }

    public function test_user_creation_creates_single_audit_log_with_full_details(): void {
        Sanctum::actingAs($this->admin);

        $uniqueName = 'Karan Sharma ' . uniqid();
        $uniqueEmail = 'karan.' . uniqid() . '@example.com';

        $response = $this->postJson('/api/create-user', [
            'usr_name' => $uniqueName,
            'usr_email' => $uniqueEmail,
            'usr_phone' => '9876543210',
            'usr_role' => 'employee',
            'usr_designation' => 'Backend Developer',
            'usr_dept' => 'web_development',
            'usr_city' => 'Mumbai',
            'usr_status' => 1,
        ]);

        $response->assertStatus(200);

        // Assert exactly ONE audit log created
        $newAuditLogs = AuditLog::where('alg_module', 'user')
            ->where('alg_action', 'create')
            ->where('alg_description', 'like', "%{$uniqueName}%")
            ->get();

        $this->assertCount(1, $newAuditLogs, 'Expected exactly one audit log for user creation.');

        $log = $newAuditLogs->first();
        $this->assertNotNull($log);

        $newValues = json_decode($log->alg_new_values, true);
        $this->assertIsArray($newValues);
        $this->assertEquals($uniqueName, $newValues['name']);
        $this->assertEquals($uniqueEmail, $newValues['email']);
        $this->assertEquals('employee', $newValues['role']);
        $this->assertEquals('Active', $newValues['status']);
        $this->assertEquals('Created user: ' . $uniqueName, $log->alg_description);
    }

    public function test_audit_logs_endpoint_returns_pagination_and_metadata(): void {
        Sanctum::actingAs($this->admin);

        $response = $this->getJson('/api/audit-logs?page=1&per_page=10');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'current_page',
                'data',
                'total',
                'per_page',
                'last_page',
                'modules',
                'actions',
            ]);

        $data = $response->json();
        $this->assertEquals(1, $data['current_page']);
        $this->assertNotEmpty($data['modules']);
        $this->assertNotEmpty($data['actions']);
    }
}
