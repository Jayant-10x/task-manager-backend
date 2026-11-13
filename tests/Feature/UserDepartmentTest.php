<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class UserDepartmentTest extends TestCase
{
    use DatabaseTransactions;
    private ?User $admin = null;
    private array $cleanupUserIds = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::where('usr_email', 'admin@example.com')->first();
        if ($this->admin) {
            Sanctum::actingAs($this->admin);
        }
    }

    protected function tearDown(): void
    {
        if (!empty($this->cleanupUserIds)) {
            User::whereIn('usr_id', $this->cleanupUserIds)->forceDelete();
        }
        parent::tearDown();
    }

    public function test_can_create_user_with_standard_department(): void
    {
        $response = $this->postJson('/api/create-user', [
            'usr_name' => 'Standard Dept User',
            'usr_email' => 'standard_dept@example.com',
            'usr_role' => 'employee',
            'usr_department' => 'Development',
            'usr_status' => 1,
            'usr_password' => 'Pass12345!',
        ]);

        $response->assertStatus(200);
        $userId = $response->json('user.usr_id');
        $this->cleanupUserIds[] = $userId;

        $user = User::find($userId);
        $this->assertNotNull($user);
        $this->assertEquals('Development', $user->usr_dept);
        $this->assertNull($user->usr_custom_dept);
    }

    public function test_can_create_user_with_custom_department(): void
    {
        $response = $this->postJson('/api/create-user', [
            'usr_name' => 'Custom Dept User',
            'usr_email' => 'custom_dept@example.com',
            'usr_role' => 'employee',
            'usr_department' => 'Custom / remark',
            'usr_custom_dept' => 'AI Engineering',
            'usr_status' => 1,
            'usr_password' => 'Pass12345!',
        ]);

        $response->assertStatus(200);
        $userId = $response->json('user.usr_id');
        $this->cleanupUserIds[] = $userId;

        $user = User::find($userId);
        $this->assertNotNull($user);
        $this->assertEquals('AI Engineering', $user->usr_dept);
        $this->assertEquals('AI Engineering', $user->usr_custom_dept);
    }

    public function test_can_update_user_department_to_custom(): void
    {
        $user = User::create([
            'usr_name' => 'Update Dept User',
            'usr_email' => 'update_dept@example.com',
            'usr_role' => 'employee',
            'usr_dept' => 'SEO',
            'usr_status' => true,
            'usr_password' => Hash::make('Pass12345!'),
        ]);
        $this->cleanupUserIds[] = $user->usr_id;

        $response = $this->putJson('/api/update-user/' . $user->usr_id, [
            'usr_name' => 'Update Dept User',
            'usr_email' => 'update_dept@example.com',
            'usr_role' => 'employee',
            'usr_dept' => 'Custom / remark',
            'usr_custom_dept' => 'Growth Automation',
            'usr_status' => 1,
        ]);

        $response->assertStatus(200);
        $user->refresh();
        $this->assertEquals('Growth Automation', $user->usr_dept);
        $this->assertEquals('Growth Automation', $user->usr_custom_dept);
    }

    public function test_can_update_user_department_from_custom_to_standard(): void
    {
        $user = User::create([
            'usr_name' => 'Custom To Standard User',
            'usr_email' => 'custom_to_std@example.com',
            'usr_role' => 'employee',
            'usr_dept' => 'Data Science',
            'usr_custom_dept' => 'Data Science',
            'usr_status' => true,
            'usr_password' => Hash::make('Pass12345!'),
        ]);
        $this->cleanupUserIds[] = $user->usr_id;

        $response = $this->putJson('/api/update-user/' . $user->usr_id, [
            'usr_name' => 'Custom To Standard User',
            'usr_email' => 'custom_to_std@example.com',
            'usr_role' => 'employee',
            'usr_dept' => 'Accounts',
            'usr_status' => 1,
        ]);

        $response->assertStatus(200);
        $user->refresh();
        $this->assertEquals('Accounts', $user->usr_dept);
        $this->assertNull($user->usr_custom_dept);
    }
}
