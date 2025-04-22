<?php

namespace Tests\Unit;

use App\Exceptions\PaymentException;
use App\Models\PromoCode;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WalletTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function it_can_add_funds()
    {
        $user = User::factory()->create();
        $wallet = Wallet::factory()->create([
            'user_id' => $user->id,
            'balance' => 0,
        ]);

        $log = $wallet->addFunds(100, 'deposit', 'test123');

        $this->assertEquals(100, $wallet->balance);
        $this->assertInstanceOf(WalletLog::class, $log);
        $this->assertEquals(100, $log->amount);
        $this->assertEquals('deposit', $log->type);
        $this->assertEquals('deposit', $log->source);
        $this->assertEquals('test123', $log->reference);
        $this->assertEquals(100, $log->balance_after);
    }

    /** @test */
    public function it_throws_exception_when_adding_negative_amount()
    {
        $this->expectException(PaymentException::class);

        $user = User::factory()->create();
        $wallet = Wallet::factory()->create([
            'user_id' => $user->id,
            'balance' => 0,
        ]);

        $wallet->addFunds(-50, 'deposit', 'test123');
    }

    /** @test */
    public function it_can_withdraw_funds()
    {
        $user = User::factory()->create();
        $wallet = Wallet::factory()->create([
            'user_id' => $user->id,
            'balance' => 100,
        ]);

        $log = $wallet->withdrawFunds(50, 'purchase', 'order123');

        $this->assertEquals(50, $wallet->balance);
        $this->assertInstanceOf(WalletLog::class, $log);
        $this->assertEquals(-50, $log->amount);
        $this->assertEquals('withdrawal', $log->type);
        $this->assertEquals('purchase', $log->source);
        $this->assertEquals('order123', $log->reference);
        $this->assertEquals(50, $log->balance_after);
    }

    /** @test */
    public function it_throws_exception_when_withdrawing_more_than_balance()
    {
        $this->expectException(PaymentException::class);

        $user = User::factory()->create();
        $wallet = Wallet::factory()->create([
            'user_id' => $user->id,
            'balance' => 50,
        ]);

        $wallet->withdrawFunds(100, 'purchase', 'order123');
    }

    /** @test */
    public function it_can_check_if_has_sufficient_funds()
    {
        $user = User::factory()->create();
        $wallet = Wallet::factory()->create([
            'user_id' => $user->id,
            'balance' => 100,
        ]);

        $this->assertTrue($wallet->hasSufficientFunds(50));
        $this->assertTrue($wallet->hasSufficientFunds(100));
        $this->assertFalse($wallet->hasSufficientFunds(101));
    }

    /** @test */
    public function it_can_apply_promo_code_to_wallet()
    {
        $user = User::factory()->create();
        $wallet = Wallet::factory()->create([
            'user_id' => $user->id,
            'balance' => 100,
        ]);

        $promoCode = PromoCode::factory()->create([
            'type' => 'amount',
            'value' => 50,
            'is_active' => true,
        ]);

        $log = $wallet->applyPromoCode($promoCode);

        $this->assertInstanceOf(WalletLog::class, $log);
        $this->assertEquals(150, $wallet->balance);
        $this->assertEquals(50, $log->amount);
        $this->assertEquals('deposit', $log->type);
        $this->assertEquals('promo_code', $log->source);
        $this->assertEquals($promoCode->code, $log->reference);
    }

    /** @test */
    public function it_returns_null_when_applying_non_amount_promo_code()
    {
        $user = User::factory()->create();
        $wallet = Wallet::factory()->create([
            'user_id' => $user->id,
            'balance' => 100,
        ]);

        $promoCode = PromoCode::factory()->create([
            'type' => 'percentage',
            'value' => 10,
            'is_active' => true,
        ]);

        $log = $wallet->applyPromoCode($promoCode);

        $this->assertNull($log);
        $this->assertEquals(100, $wallet->balance);
    }
}