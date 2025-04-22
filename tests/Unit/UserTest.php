<?php

namespace Tests\Unit;

use App\Models\User;
use App\Models\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function it_can_create_a_user()
    {
        $user = User::factory()->create([
            'name' => 'John Doe',
            'email' => 'john@example.com',
        ]);

        $this->assertDatabaseHas('users', [
            'name' => 'John Doe',
            'email' => 'john@example.com',
        ]);

        $this->assertInstanceOf(User::class, $user);
    }

    /** @test */
    public function it_can_generate_referral_code()
    {
        $user = User::factory()->create();
        $referralCode = $user->generateReferralCode();

        $this->assertNotNull($referralCode);
        $this->assertEquals($referralCode, $user->referral_code);
        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'referral_code' => $referralCode,
        ]);
    }

    /** @test */
    public function it_has_wallet_relationship()
    {
        $user = User::factory()->create();
        $wallet = Wallet::factory()->create([
            'user_id' => $user->id,
            'balance' => 100.50,
            'currency' => 'PLN',
        ]);

        $this->assertInstanceOf(Wallet::class, $user->wallet);
        $this->assertEquals(100.50, $user->wallet->balance);
        $this->assertEquals('PLN', $user->wallet->currency);
    }

    /** @test */
    public function it_can_check_if_two_factor_is_enabled()
    {
        $user = User::factory()->create([
            'two_factor_confirmed_at' => null,
        ]);

        $this->assertFalse($user->hasTwoFactorEnabled());

        $user->update([
            'two_factor_confirmed_at' => now(),
        ]);

        $this->assertTrue($user->hasTwoFactorEnabled());
    }

    /** @test */
    public function it_can_check_if_email_is_verified()
    {
        $user = User::factory()->create([
            'email_verified_at' => null,
        ]);

        $this->assertFalse($user->hasVerifiedEmail());

        $user->update([
            'email_verified_at' => now(),
        ]);

        $this->assertTrue($user->hasVerifiedEmail());
    }

    /** @test */
    public function it_has_proper_relationships()
    {
        $user = User::factory()->create();

        $this->assertInstanceOf(\Illuminate\Database\Eloquent\Collection::class, $user->purchasedHostings);
        $this->assertInstanceOf(\Illuminate\Database\Eloquent\Collection::class, $user->hostingAccounts);
        $this->assertInstanceOf(\Illuminate\Database\Eloquent\Collection::class, $user->referrals);
    }
}