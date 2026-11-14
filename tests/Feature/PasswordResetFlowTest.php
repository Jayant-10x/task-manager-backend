<?php

namespace Tests\Feature;

use App\Mail\ManagerPasswordResetAdminAlertMail;
use App\Mail\PasswordResetLinkMail;
use App\Models\PasswordResetRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
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

    public function test_admin_forgot_password_sends_email_with_token_and_does_not_create_reset_request(): void
    {
        Mail::fake();

        $admin = User::create([
            'usr_name' => 'Admin Auto ' . uniqid(),
            'usr_email' => 'admin-auto-' . uniqid() . '@example.com',
            'usr_password' => Hash::make('AdminPass@123'),
            'usr_role' => 'admin',
            'usr_is_super_admin' => 1,
            'usr_status' => 1,
        ]);

        $response = $this->postJson('/api/reset_password/through-mail', [
            'requested_mail' => $admin->usr_email,
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'sent' => true,
                'target' => 'email',
            ]);

        Mail::assertSent(PasswordResetLinkMail::class, function ($mail) use ($admin) {
            return $mail->hasTo($admin->usr_email);
        });

        $this->assertDatabaseMissing('password_reset_requests', [
            'prr_usr_email' => $admin->usr_email,
        ]);

        $admin->refresh();
        $this->assertNotEmpty($admin->usr_reset_hash);
        $this->assertEquals(0, $admin->usr_reset_used);

        // Verify check endpoint
        $checkRes = $this->getJson('/api/reset-password/check?reset=' . $admin->usr_reset_hash);
        $checkRes->assertStatus(200)
            ->assertJson([
                'ok' => true,
                'email' => $admin->usr_email,
            ]);

        // Complete password reset
        $confirmRes = $this->postJson('/api/reset-password/confirm', [
            'reset' => $admin->usr_reset_hash,
            'password' => 'NewAdminPass@999',
        ]);
        $confirmRes->assertStatus(200)->assertJson(['ok' => true]);

        // Verify login works with new password
        $loginRes = $this->postJson('/api/login', [
            'usr_email' => $admin->usr_email,
            'usr_password' => 'NewAdminPass@999',
        ]);
        $loginRes->assertStatus(200)->assertJsonStructure(['access_token']);

        // Token should now be invalid
        $secondCheck = $this->getJson('/api/reset-password/check?reset=' . $admin->usr_reset_hash);
        $this->assertContains($secondCheck->status(), [400, 404]);
    }

    public function test_manager_forgot_password_sends_email_to_manager_and_alerts_admins_with_dual_reset_capability(): void
    {
        Mail::fake();

        $admin = User::create([
            'usr_name' => 'Alert Admin ' . uniqid(),
            'usr_email' => 'alert-admin-' . uniqid() . '@example.com',
            'usr_password' => Hash::make('AdminPass@123'),
            'usr_role' => 'admin',
            'usr_is_super_admin' => 1,
            'usr_status' => 1,
        ]);

        $manager = User::create([
            'usr_name' => 'Test Manager ' . uniqid(),
            'usr_email' => 'mgr-test-' . uniqid() . '@example.com',
            'usr_password' => Hash::make('ManagerPass@123'),
            'usr_role' => 'manager',
            'usr_is_super_admin' => 0,
            'usr_status' => 1,
        ]);

        $response = $this->postJson('/api/reset_password/through-mail', [
            'requested_mail' => $manager->usr_email,
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'sent' => true,
                'target' => 'email',
            ]);

        // Reset email sent to manager
        Mail::assertSent(PasswordResetLinkMail::class, function ($mail) use ($manager) {
            return $mail->hasTo($manager->usr_email);
        });

        // Alert email sent to admin with CRM reset action
        Mail::assertSent(ManagerPasswordResetAdminAlertMail::class, function ($mail) use ($admin) {
            return $mail->hasTo($admin->usr_email);
        });

        // Reset request IS tracked so Admin can also reset it in CRM
        $this->assertDatabaseHas('password_reset_requests', [
            'prr_usr_email' => $manager->usr_email,
            'prr_status' => 'pending',
        ]);

        // Recorded in audit log
        $this->assertDatabaseHas('audit_logs', [
            'alg_action' => 'manager_password_reset_requested',
            'alg_module' => 'auth',
        ]);

        // Scenario A: Manager resets themselves via email link
        $manager->refresh();
        $token = $manager->usr_reset_hash;
        $this->assertNotEmpty($token);

        $confirmRes = $this->postJson('/api/reset-password/confirm', [
            'reset' => $token,
            'password' => 'NewManagerPass@888',
        ]);
        $confirmRes->assertStatus(200)->assertJson(['ok' => true]);

        // Pending request is marked completed
        $this->assertDatabaseHas('password_reset_requests', [
            'prr_usr_email' => $manager->usr_email,
            'prr_status' => 'reset_successfully',
            'prr_admin_action' => 'email_link',
        ]);

        // Manager can log in with new password
        $loginRes = $this->postJson('/api/login', [
            'usr_email' => $manager->usr_email,
            'usr_password' => 'NewManagerPass@888',
        ]);
        $loginRes->assertStatus(200)->assertJsonStructure(['access_token']);
    }

    public function test_admin_can_reset_manager_password_from_crm_and_invalidate_email_link(): void
    {
        Mail::fake();

        $admin = User::create([
            'usr_name' => 'Admin Actor ' . uniqid(),
            'usr_email' => 'admin-actor-' . uniqid() . '@example.com',
            'usr_password' => Hash::make('AdminPass@123'),
            'usr_role' => 'admin',
            'usr_is_super_admin' => 1,
            'usr_status' => 1,
        ]);

        $manager = User::create([
            'usr_name' => 'Manager Intercept ' . uniqid(),
            'usr_email' => 'mgr-intercept-' . uniqid() . '@example.com',
            'usr_password' => Hash::make('ManagerPass@123'),
            'usr_role' => 'manager',
            'usr_is_super_admin' => 0,
            'usr_status' => 1,
        ]);

        // Manager requests reset
        $response = $this->postJson('/api/reset_password/through-mail', [
            'requested_mail' => $manager->usr_email,
        ]);
        $response->assertStatus(200);

        $requestId = $response->json('reset_request.prr_id');
        $this->assertNotNull($requestId);

        // Admin intervenes and resets the password from CRM
        $completeRes = $this->actingAs($admin, 'sanctum')->postJson("/api/reset-password/reset-request-success/{$requestId}", [
            'is_system_generated' => true,
            'is_manually_generated' => false,
        ]);
        $completeRes->assertStatus(200)->assertJsonPath('status', true);
        $tempPassword = $completeRes->json('password_reset_data.temporary_password');
        $this->assertNotEmpty($tempPassword);

        // Manager's old email token link is now INVALIDATED
        $manager->refresh();
        $this->assertNull($manager->usr_reset_hash);

        // Manager logs in with temporary password and is forced to change password
        $tempLogin = $this->postJson('/api/login', [
            'usr_email' => $manager->usr_email,
            'usr_password' => $tempPassword,
        ]);
        $tempLogin->assertStatus(200)->assertJsonPath('forcePasswordReset', true);
    }
}
