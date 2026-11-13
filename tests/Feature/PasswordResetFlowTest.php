<?php

namespace Tests\Feature;

use App\Models\PasswordResetRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class PasswordResetFlowTest extends TestCase
{
    use DatabaseTransactions;
    public function test_complete_password_reset_and_forced_change_flow(): void
    {
        // 1. Clean and create a super admin and a standard user
        User::withTrashed()->where('usr_email', 'admin_test_flow@example.com')->orWhere('usr_name', 'Admin Flow Tester')->forceDelete();
        $admin = User::firstOrCreate(
            ['usr_email' => 'admin_test_flow@example.com'],
            [
                'usr_name' => 'Admin Flow Tester',
                'usr_password' => 'admin123',
                'usr_phone' => '1112223334',
                'usr_role' => 'admin',
                'usr_status' => true,
                'usr_is_super_admin' => true,
                'usr_created_by' => 'system_test',
            ]
        );

        $initialPassword = 'InitialUserPass@123';
        $user = User::create([
            'usr_name' => 'Flow User ' . time(),
            'usr_email' => 'flow_user_' . time() . '@example.com',
            'usr_password' => $initialPassword,
            'usr_phone' => '9998887776',
            'usr_role' => 'employee',
            'usr_status' => true,
            'usr_is_super_admin' => false,
            'usr_created_by' => 'system_test',
            'usr_must_change' => 0,
        ]);

        // 2. Unauthenticated user submits Forgot Password request
        $forgotResponse = $this->postJson('/api/reset_password/through-mail', [
            'requested_mail' => $user->usr_email,
        ]);

        $forgotResponse->assertStatus(201)
            ->assertJson([
                'message' => 'Reset request successfully sent.',
                'sent' => true,
            ]);

        $resetRequestId = $forgotResponse->json('reset_request.prr_id');
        $this->assertNotNull($resetRequestId);

        // 3. Admin views pending reset requests
        $listResponse = $this->actingAs($admin, 'sanctum')->getJson('/api/reset-password/list');
        $listResponse->assertStatus(200)
            ->assertJsonStructure(['ok', 'pending', 'history']);

        // 4. Admin opens the reset request
        $openResponse = $this->actingAs($admin, 'sanctum')->getJson("/api/reset-password/reset-request-opened/{$resetRequestId}");
        $openResponse->assertStatus(200)
            ->assertJsonPath('status', true);

        // 5. Admin completes the reset request with a system-generated password
        $completeResponse = $this->actingAs($admin, 'sanctum')->postJson("/api/reset-password/reset-request-success/{$resetRequestId}", [
            'is_system_generated' => true,
            'is_manually_generated' => false,
        ]);

        $completeResponse->assertStatus(200)
            ->assertJsonPath('status', true);

        $temporaryPassword = $completeResponse->json('password_reset_data.temporary_password');
        $this->assertNotEmpty($temporaryPassword);

        // 6. User signs in with the temporary password -> MUST enforce forcePasswordReset
        $tempLoginResponse = $this->postJson('/api/login', [
            'usr_email' => $user->usr_email,
            'usr_password' => $temporaryPassword,
        ]);

        $tempLoginResponse->assertStatus(200)
            ->assertJsonPath('forcePasswordReset', true);

        $userToken = $tempLoginResponse->json('access_token');
        $this->assertNotEmpty($userToken);

        // 7. User forced password change validations (Acting as the user)
        $user = $user->fresh();
        $newPassword = 'NewSecretPassword@2026';

        // Password mismatch failure
        $mismatchResponse = $this->actingAs($user, 'sanctum')
            ->postJson('/api/reset-password', [
                'usr_password' => $temporaryPassword,
                'new_password' => $newPassword,
                'new_password_confirmation' => 'Mismatch123@Pass',
            ]);
        $mismatchResponse->assertStatus(422);

        // Wrong current password failure
        $wrongCurrentResponse = $this->actingAs($user, 'sanctum')
            ->postJson('/api/reset-password', [
                'usr_password' => 'WrongCurrentTemporaryPass',
                'new_password' => $newPassword,
                'new_password_confirmation' => $newPassword,
            ]);
        $wrongCurrentResponse->assertStatus(422);

        // Valid submission
        $validChangeResponse = $this->actingAs($user, 'sanctum')
            ->postJson('/api/reset-password', [
                'usr_password' => $temporaryPassword,
                'new_password' => $newPassword,
                'new_password_confirmation' => $newPassword,
            ]);
        $validChangeResponse->assertStatus(200)
            ->assertJsonPath('message', 'Password reset successfully.');

        $freshToken = $validChangeResponse->json('access_token');
        $this->assertNotEmpty($freshToken);

        // 8. User profile check confirms usr_must_change === 0
        $profileResponse = $this->actingAs($user->fresh(), 'sanctum')
            ->getJson('/api/user');
        $profileResponse->assertStatus(200)
            ->assertJsonPath('forcePasswordReset', false);

        // 9. Verify login with new password works and old temporary password is rejected
        $oldLoginResponse = $this->postJson('/api/login', [
            'usr_email' => $user->usr_email,
            'usr_password' => $temporaryPassword,
        ]);
        $oldLoginResponse->assertStatus(422);

        $newLoginResponse = $this->postJson('/api/login', [
            'usr_email' => $user->usr_email,
            'usr_password' => $newPassword,
        ]);
        $newLoginResponse->assertStatus(200)
            ->assertJsonPath('forcePasswordReset', false);

        // Cleanup
        $user->tokens()->delete();
        $user->delete();
        PasswordResetRequest::where('prr_id', $resetRequestId)->delete();
    }
}
