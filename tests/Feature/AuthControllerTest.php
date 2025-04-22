<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AuthControllerTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function it_can_register_a_user()
    {
        $response = $this->postJson('/api/v1/register', [
            'name' => 'John Doe',
            'email' => 'john@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'is_marketing_consent' => true,
        ]);

        $response->assertStatus(201)
            ->assertJsonStructure([
                'success',
                'message',
                'user',
                'token',
            ]);

        $this->assertDatabaseHas('users', [
            'name' => 'John Doe',
            'email' => 'john@example.com',
            'is_marketing_consent' => 1,
        ]);

        $this->assertDatabaseHas('wallets', [
            'user_id' => $response->json('user.id'),
            'balance' => 0,
        ]);
    }

    /** @test */
    public function it_validates_registration_data()
    {
        $response = $this->postJson('/api/v1/register', [
            'name' => 'Jo', // Too short
            'email' => 'not-an-email',
            'password' => 'short',
            'password_confirmation' => 'not-matching',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['name', 'email', 'password']);
    }

    /** @test */
    public function it_can_login_a_user()
    {
        $user = User::factory()->create([
            'email' => 'john@example.com',
            'password' => bcrypt('password123'),
        ]);

        $response = $this->postJson('/api/v1/login', [
            'email' => 'john@example.com',
            'password' => 'password123',
        ]);

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'message',
                'user',
                'token',
            ]);
    }

    /** @test */
    public function it_rejects_invalid_login_credentials()
    {
        $user = User::factory()->create([
            'email' => 'john@example.com',
            'password' => bcrypt('password123'),
        ]);

        $response = $this->postJson('/api/v1/login', [
            'email' => 'john@example.com',
            'password' => 'wrong-password',
        ]);

        $response->assertStatus(401)
            ->assertJson([
                'success' => false,
                'message' => 'Invalid credentials',
            ]);
    }

    /** @test */
    public function it_can_logout_a_user()
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/v1/logout');

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'message' => 'Logged out successfully',
            ]);
    }

    /** @test */
    public function it_can_get_authenticated_user_details()
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $response = $this->getJson('/api/v1/me');

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'user' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                ],
            ]);
    }

    /** @test */
    public function it_can_update_user_profile()
    {
        $user = User::factory()->create([
            'name' => 'Original Name',
            'phone' => '123456789',
        ]);

        Sanctum::actingAs($user);

        $response = $this->putJson('/api/v1/profile', [
            'name' => 'Updated Name',
            'phone' => '987654321',
            'is_eco_mode' => true,
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'message' => 'Profile updated successfully',
                'user' => [
                    'name' => 'Updated Name',
                    'phone' => '987654321',
                    'is_eco_mode' => true,
                ],
            ]);

        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'name' => 'Updated Name',
            'phone' => '987654321',
            'is_eco_mode' => 1,
        ]);
    }

    /** @test */
    public function it_can_change_user_password()
    {
        $user = User::factory()->create([
            'password' => bcrypt('old-password'),
        ]);

        Sanctum::actingAs($user);

        $response = $this->postJson('/api/v1/password', [
            'current_password' => 'old-password',
            'password' => 'new-password123',
            'password_confirmation' => 'new-password123',
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'message' => 'Password changed successfully',
            ])
            ->assertJsonStructure([
                'token',
            ]);

        // Test login with new password
        $loginResponse = $this->postJson('/api/v1/login', [
            'email' => $user->email,
            'password' => 'new-password123',
        ]);

        $loginResponse->assertStatus(200)
            ->assertJson([
                'success' => true,
                'message' => 'Login successful',
            ]);
    }

    /** @test */
    public function it_rejects_incorrect_current_password()
    {
        $user = User::factory()->create([
            'password' => bcrypt('correct-password'),
        ]);

        Sanctum::actingAs($user);

        $response = $this->postJson('/api/v1/password', [
            'current_password' => 'wrong-password',
            'password' => 'new-password123',
            'password_confirmation' => 'new-password123',
        ]);

        $response->assertStatus(401)
            ->assertJson([
                'success' => false,
                'message' => 'Current password is incorrect',
            ]);
    }
}