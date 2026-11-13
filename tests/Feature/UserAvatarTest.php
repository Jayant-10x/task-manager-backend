<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class UserAvatarTest extends TestCase
{
    use DatabaseTransactions;
    private function createTestUser(string $namePrefix = 'AvatarUser'): User
    {
        $unique = uniqid();
        return User::create([
            'usr_name' => "{$namePrefix} {$unique}",
            'usr_email' => "avatar_{$unique}@example.com",
            'usr_password' => 'TestPass@123',
            'usr_phone' => '1234567890',
            'usr_role' => 'employee',
            'usr_status' => true,
            'usr_is_super_admin' => false,
            'usr_created_by' => 'test_suite',
            'usr_must_change' => 0,
        ]);
    }

    public function test_unauthenticated_user_cannot_upload_or_delete_avatar(): void
    {
        $this->postJson('/api/user/avatar', ['data' => 'invalid'])
            ->assertStatus(401);

        $this->deleteJson('/api/user/avatar')
            ->assertStatus(401);
    }

    public function test_user_can_upload_avatar_via_base64_data_url(): void
    {
        $user = $this->createTestUser('Base64User');
        $token = $user->createToken('test_token')->plainTextToken;

        // 1x1 transparent PNG as base64
        $base64Png = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==';

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/user/avatar', [
                'name' => 'photo.png',
                'mime' => 'image/png',
                'data' => $base64Png,
            ]);

        $response->assertStatus(200)
            ->assertJson([
                'status' => true,
                'message' => 'Profile photo updated successfully.',
            ])
            ->assertJsonStructure([
                'status',
                'message',
                'avatar',
                'user' => ['usr_id', 'usr_avatar'],
            ]);

        $avatarUrl = $response->json('avatar');
        $this->assertNotEmpty($avatarUrl);
        $this->assertStringContainsString('uploads/avatars/avatar_' . $user->usr_id, $avatarUrl);

        // Verify in database
        $user->refresh();
        $this->assertEquals($avatarUrl, $user->usr_avatar);

        // Verify profile endpoint reflects the avatar
        $profileRes = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/user');
        $profileRes->assertStatus(200)
            ->assertJsonPath('user.usr_avatar', $avatarUrl);
    }

    public function test_user_can_upload_avatar_via_multipart_file(): void
    {
        $user = $this->createTestUser('FileUser');
        $token = $user->createToken('test_token')->plainTextToken;

        $file = UploadedFile::fake()->image('test_avatar.jpg', 200, 200);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->post('/api/user/avatar', [
                'avatar' => $file,
            ]);

        $response->assertStatus(200)
            ->assertJson([
                'status' => true,
                'message' => 'Profile photo updated successfully.',
            ]);

        $avatarUrl = $response->json('avatar');
        $this->assertNotEmpty($avatarUrl);

        $user->refresh();
        $this->assertEquals($avatarUrl, $user->usr_avatar);
    }

    public function test_user_can_remove_avatar(): void
    {
        $user = $this->createTestUser('RemoveUser');
        $token = $user->createToken('test_token')->plainTextToken;

        // First upload an avatar
        $base64Png = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==';
        $uploadRes = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/user/avatar', [
                'data' => $base64Png,
            ]);
        $uploadRes->assertStatus(200);

        $user->refresh();
        $this->assertNotNull($user->usr_avatar);

        // Now remove avatar
        $deleteRes = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->deleteJson('/api/user/avatar');

        $deleteRes->assertStatus(200)
            ->assertJson([
                'status' => true,
                'message' => 'Profile photo removed successfully.',
                'avatar' => null,
            ]);

        $user->refresh();
        $this->assertNull($user->usr_avatar);

        // Profile should also reflect null avatar
        $profileRes = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/user');
        $profileRes->assertStatus(200)
            ->assertJsonPath('user.usr_avatar', null);
    }

    public function test_avatar_cleans_up_previous_file_on_replacement(): void
    {
        $user = $this->createTestUser('ReplaceUser');
        $token = $user->createToken('test_token')->plainTextToken;

        $base64Png = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==';
        $firstUpload = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/user/avatar', ['data' => $base64Png]);
        $firstUrl = $firstUpload->json('avatar');

        $firstDiskPath = public_path(ltrim(parse_url($firstUrl, PHP_URL_PATH), '/\\'));
        $this->assertFileExists($firstDiskPath);

        // Wait 1 second to ensure different timestamp in filename
        sleep(1);

        // Upload new avatar
        $secondUpload = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/user/avatar', ['data' => $base64Png]);
        $secondUrl = $secondUpload->json('avatar');

        $secondDiskPath = public_path(ltrim(parse_url($secondUrl, PHP_URL_PATH), '/\\'));
        $this->assertFileExists($secondDiskPath);

        // Old file should be deleted
        $this->assertFileDoesNotExist($firstDiskPath);

        // Clean up
        @unlink($secondDiskPath);
    }
}
