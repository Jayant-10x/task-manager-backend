<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Project;
use App\Models\ProjectMember;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class ProjectSyncTest extends TestCase
{
    use DatabaseTransactions;

    protected string $secret = 'test_project_sync_secret_xyz';
    protected ?User $adminUser = null;
    protected ?User $coordinatorUser = null;

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('services.project_sync.secret', $this->secret);

        $this->adminUser = User::firstOrCreate(
            ['usr_email' => 'info@the10xagency.in'],
            [
                'usr_name' => 'System Admin Test',
                'usr_password' => Hash::make('password123'),
                'usr_role' => 'admin',
                'usr_is_super_admin' => 1,
                'usr_status' => 1,
            ]
        );

        $this->coordinatorUser = User::firstOrCreate(
            ['usr_email' => 'coordinator_sync_test@the10xagency.in'],
            [
                'usr_name' => 'Sync Test Coordinator',
                'usr_password' => Hash::make('password123'),
                'usr_role' => 'manager',
                'usr_is_super_admin' => 0,
                'usr_status' => 1,
            ]
        );
    }

    public function test_sync_rejects_missing_secret_header(): void
    {
        $response = $this->postJson('/api/webhooks/projects', [
            'project_code' => 'PJT-TEST-001',
            'name' => 'Unauthorized Test Project',
        ]);

        $response->assertStatus(401);
        $response->assertJson([
            'status' => false,
            'message' => 'Unauthorized: Invalid or missing sync secret header.',
        ]);
    }

    public function test_sync_rejects_invalid_secret_header(): void
    {
        $response = $this->withHeaders([
            'X-Sync-Secret' => 'wrong_secret',
        ])->postJson('/api/webhooks/projects', [
            'project_code' => 'PJT-TEST-001',
            'name' => 'Unauthorized Test Project',
        ]);

        $response->assertStatus(401);
    }

    public function test_sync_validates_required_fields(): void
    {
        $response = $this->withHeaders([
            'X-Sync-Secret' => $this->secret,
        ])->postJson('/api/webhooks/projects', []);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['name', 'project_code']);
    }

    public function test_sync_creates_project_successfully_and_resolves_manager(): void
    {
        $uniqueCode = 'PJT-UNIT-' . uniqid();
        $payload = [
            'project_code' => $uniqueCode,
            'name' => 'E-Commerce Redesign 2026',
            'description' => 'Redesigning portal and checkout flow.',
            'project_type' => 'Web Development',
            'customer_name' => 'Apex Retailers',
            'value' => 125000.50,
            'cost' => 45000.00,
            'start_date' => '2026-12-01',
            'delivery_date' => '2027-01-31',
            'status' => 'In Progress',
            'health' => 'At Risk',
            'approval_status' => 'Approved',
            'coordinator_email' => $this->coordinatorUser->usr_email,
        ];

        $response = $this->withHeaders([
            'X-Sync-Secret' => $this->secret,
        ])->postJson('/api/webhooks/projects', $payload);

        $response->assertStatus(201);
        $response->assertJsonPath('status', true);
        $response->assertJsonPath('project.pro_name', 'E-Commerce Redesign 2026');
        $response->assertJsonPath('project.pro_status', 'at_risk');
        $response->assertJsonPath('project.pro_manager', $this->coordinatorUser->usr_id);

        $createdProject = Project::where('pro_description', 'like', "%[PMS Ref: {$uniqueCode}]%")->first();
        $this->assertNotNull($createdProject);
        $this->assertEquals($this->coordinatorUser->usr_id, $createdProject->pro_manager);
        $this->assertEquals('at_risk', $createdProject->pro_status);
        $this->assertEquals(125000.50, (float) $createdProject->pro_budget);

        // Check project member
        $this->assertTrue(
            ProjectMember::where('pmb_pro_id', $createdProject->pro_id)
                ->where('pmb_usr_id', $this->coordinatorUser->usr_id)
                ->exists()
        );

        // Check audit log
        $this->assertTrue(
            AuditLog::where('alg_module', 'project')
                ->where('alg_record_id', $createdProject->pro_code)
                ->where('alg_action', 'create')
                ->exists()
        );
    }

    public function test_sync_is_idempotent_on_repeated_calls(): void
    {
        $uniqueCode = 'PJT-IDEMP-' . uniqid();
        $payload = [
            'project_code' => $uniqueCode,
            'name' => 'Idempotent Sync Project',
            'coordinator_email' => $this->coordinatorUser->usr_email,
        ];

        // Call 1: Creation
        $response1 = $this->withHeaders([
            'X-Sync-Secret' => $this->secret,
        ])->postJson('/api/webhooks/projects', $payload);
        $response1->assertStatus(201);

        $countBefore = Project::where('pro_description', 'like', "%[PMS Ref: {$uniqueCode}]%")->count();
        $this->assertEquals(1, $countBefore);

        // Call 2: Duplicate
        $response2 = $this->withHeaders([
            'X-Sync-Secret' => $this->secret,
        ])->postJson('/api/webhooks/projects', $payload);
        $response2->assertStatus(200);
        $response2->assertJsonPath('status', true);
        $response2->assertJsonPath('message', 'Project already exists in TMS (idempotent).');

        $countAfter = Project::where('pro_description', 'like', "%[PMS Ref: {$uniqueCode}]%")->count();
        $this->assertEquals(1, $countAfter);
    }

    public function test_sync_falls_back_to_admin_when_coordinator_email_unknown(): void
    {
        $uniqueCode = 'PJT-FALLBACK-' . uniqid();
        $payload = [
            'project_code' => $uniqueCode,
            'name' => 'Fallback Manager Project',
            'coordinator_email' => 'non_existent_coordinator@the10xagency.in',
        ];

        $response = $this->withHeaders([
            'X-Sync-Secret' => $this->secret,
        ])->postJson('/api/webhooks/projects', $payload);

        $response->assertStatus(201);
        $createdProject = Project::where('pro_description', 'like', "%[PMS Ref: {$uniqueCode}]%")->first();
        $this->assertNotNull($createdProject);

        $adminIds = User::where('usr_is_super_admin', 1)
            ->orWhere('usr_role', 'admin')
            ->pluck('usr_id')
            ->toArray();

        $this->assertContains($createdProject->pro_manager, $adminIds);
    }
}
