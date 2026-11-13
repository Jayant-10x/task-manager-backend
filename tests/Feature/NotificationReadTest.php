<?php

namespace Tests\Feature;

use App\Models\Notification;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class NotificationReadTest extends TestCase
{
    use DatabaseTransactions;
    private function createNotificationTestUser(): User
    {
        $unique = uniqid();
        return User::create([
            'usr_name' => "NotifUser {$unique}",
            'usr_email' => "notif_{$unique}@example.com",
            'usr_password' => 'TestPass@123',
            'usr_phone' => '1234567890',
            'usr_role' => 'employee',
            'usr_status' => true,
            'usr_is_super_admin' => false,
            'usr_created_by' => 'test_suite',
            'usr_must_change' => 0,
        ]);
    }

    public function test_notifications_unread_count_and_read_status(): void
    {
        $user = $this->createNotificationTestUser();
        $token = $user->createToken('notif_token')->plainTextToken;

        // Create 3 unread notifications for this user
        $n1 = Notification::create([
            'not_usr_id' => $user->usr_id,
            'not_kind' => 'task',
            'not_title' => 'Task Test 1',
            'not_body' => 'Body test 1',
            'not_is_read' => false,
            'not_actor' => 'System',
        ]);

        $n2 = Notification::create([
            'not_usr_id' => $user->usr_id,
            'not_kind' => 'task',
            'not_title' => 'Task Test 2',
            'not_body' => 'Body test 2',
            'not_is_read' => false,
            'not_actor' => 'System',
        ]);

        $n3 = Notification::create([
            'not_usr_id' => $user->usr_id,
            'not_kind' => 'task',
            'not_title' => 'Task Test 3',
            'not_body' => 'Body test 3',
            'not_is_read' => true, // already read
            'not_actor' => 'System',
        ]);

        // 1. Initial GET /api/notifications should return unread_count = 2 (not 3!)
        $indexRes = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/notifications');

        $indexRes->assertStatus(200)
            ->assertJson([
                'status' => 'success',
                'unread_count' => 2,
            ]);

        // 2. Click / read single notification ($n1)
        $clickRes = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->putJson("/api/notification/{$n1->not_id}/read");

        $clickRes->assertStatus(200)
            ->assertJson([
                'status' => 'success',
                'message' => 'Notification processed.',
            ]);

        $n1->refresh();
        $this->assertTrue((bool)$n1->not_is_read);

        // 3. Check unread count should now be 1
        $indexRes2 = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/notifications');
        $indexRes2->assertStatus(200)
            ->assertJson([
                'status' => 'success',
                'unread_count' => 1,
            ]);

        // 4. Mark all as read
        $markAllRes = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/notifications/mark-all-read');

        $markAllRes->assertStatus(200)
            ->assertJson([
                'status' => 'success',
                'message' => 'All notifications marked as read.',
            ]);

        $n2->refresh();
        $this->assertTrue((bool)$n2->not_is_read);

        // 5. Final check: unread_count must be 0
        $indexRes3 = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/notifications');
        $indexRes3->assertStatus(200)
            ->assertJson([
                'status' => 'success',
                'unread_count' => 0,
            ]);
    }
}
