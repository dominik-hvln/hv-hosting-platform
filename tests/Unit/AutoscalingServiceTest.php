<?php

namespace Tests\Unit;

use App\Models\HostingAccount;
use App\Models\HostingPlan;
use App\Models\PurchasedHosting;
use App\Models\ScalingLog;
use App\Models\User;
use App\Models\Wallet;
use App\Services\AutoscalingService;
use App\Services\CloudLinuxService;
use App\Services\WhmcsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class AutoscalingServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /** @test */
    public function it_can_scale_account_resources()
    {
        // Create mock services
        $cloudLinuxService = Mockery::mock(CloudLinuxService::class);
        $cloudLinuxService->shouldReceive('updateResources')->once()->andReturn(true);

        $whmcsService = Mockery::mock(WhmcsService::class);
        $whmcsService->shouldReceive('syncService')->once()->andReturn(true);

        // Create the autoscaling service with mocked dependencies
        $autoscalingService = new AutoscalingService($cloudLinuxService, $whmcsService);

        // Create test data
        $user = User::factory()->create();
        $wallet = Wallet::factory()->create(['user_id' => $user->id, 'balance' => 100]);

        $plan = HostingPlan::factory()->create([
            'ram' => 1024,
            'cpu' => 100,
            'max_ram' => 2048,
            'max_cpu' => 200,
        ]);

        $purchased = PurchasedHosting::factory()->create([
            'user_id' => $user->id,
            'hosting_plan_id' => $plan->id,
            'status' => 'active',
            'is_autoscaling_enabled' => true,
        ]);

        $account = HostingAccount::factory()->create([
            'user_id' => $user->id,
            'purchased_hosting_id' => $purchased->id,
            'current_ram' => 1024,
            'current_cpu' => 100,
            'status' => 'active',
            'is_autoscaling_enabled' => true,
            'cloudlinux_id' => 'test123',
        ]);

        // Test scaling the account
        $result = $autoscalingService->scaleAccount($account, 256, 50);

        // Assertions
        $this->assertTrue($result['success']);
        $this->assertArrayHasKey('scaling_log_id', $result);

        // Verify the account was updated
        $account->refresh();
        $this->assertEquals(1280, $account->current_ram); // 1024 + 256
        $this->assertEquals(150, $account->current_cpu); // 100 + 50

        // Verify scaling log was created
        $scalingLog = ScalingLog::find($result['scaling_log_id']);
        $this->assertNotNull($scalingLog);
        $this->assertEquals(1024, $scalingLog->previous_ram);
        $this->assertEquals(100, $scalingLog->previous_cpu);
        $this->assertEquals(1280, $scalingLog->new_ram);
        $this->assertEquals(150, $scalingLog->new_cpu);
        $this->assertEquals(256, $scalingLog->scaled_ram);
        $this->assertEquals(50, $scalingLog->scaled_cpu);
        $this->assertEquals('autoscaling', $scalingLog->reason);
        $this->assertNotNull($scalingLog->cost);
    }

    /** @test */
    public function it_respects_resource_maximums_when_scaling()
    {
        // Create mock services
        $cloudLinuxService = Mockery::mock(CloudLinuxService::class);
        $cloudLinuxService->shouldReceive('updateResources')->once()->andReturn(true);

        $whmcsService = Mockery::mock(WhmcsService::class);
        $whmcsService->shouldReceive('syncService')->once()->andReturn(true);

        // Create the autoscaling service with mocked dependencies
        $autoscalingService = new AutoscalingService($cloudLinuxService, $whmcsService);

        // Create test data
        $user = User::factory()->create();
        $wallet = Wallet::factory()->create(['user_id' => $user->id, 'balance' => 100]);

        $plan = HostingPlan::factory()->create([
            'ram' => 1024,
            'cpu' => 100,
            'max_ram' => 1280, // Only 256 more ram available
            'max_cpu' => 150,  // Only 50 more cpu available
        ]);

        $purchased = PurchasedHosting::factory()->create([
            'user_id' => $user->id,
            'hosting_plan_id' => $plan->id,
            'status' => 'active',
            'is_autoscaling_enabled' => true,
        ]);

        $account = HostingAccount::factory()->create([
            'user_id' => $user->id,
            'purchased_hosting_id' => $purchased->id,
            'current_ram' => 1024,
            'current_cpu' => 100,
            'status' => 'active',
            'is_autoscaling_enabled' => true,
            'cloudlinux_id' => 'test123',
        ]);

        // Try to scale beyond maximum
        $result = $autoscalingService->scaleAccount($account, 512, 100);

        // Assertions
        $this->assertTrue($result['success']);

        // Verify the account was updated to maximum allowed values
        $account->refresh();
        $this->assertEquals(1280, $account->current_ram); // Max is 1280
        $this->assertEquals(150, $account->current_cpu);  // Max is 150
    }

    /** @test */
    public function it_handles_payment_for_scaling_via_wallet()
    {
        // Create mock services
        $cloudLinuxService = Mockery::mock(CloudLinuxService::class);
        $cloudLinuxService->shouldReceive('updateResources')->once()->andReturn(true);

        $whmcsService = Mockery::mock(WhmcsService::class);
        $whmcsService->shouldReceive('syncService')->once()->andReturn(true);

        // Create the autoscaling service with mocked dependencies
        $autoscalingService = new AutoscalingService($cloudLinuxService, $whmcsService);

        // Create test data
        $user = User::factory()->create();
        $wallet = Wallet::factory()->create(['user_id' => $user->id, 'balance' => 100]);

        $plan = HostingPlan::factory()->create([
            'ram' => 1024,
            'cpu' => 100,
            'max_ram' => 2048,
            'max_cpu' => 200,
        ]);

        $purchased = PurchasedHosting::factory()->create([
            'user_id' => $user->id,
            'hosting_plan_id' => $plan->id,
            'status' => 'active',
            'is_autoscaling_enabled' => true,
        ]);

        $account = HostingAccount::factory()->create([
            'user_id' => $user->id,
            'purchased_hosting_id' => $purchased->id,
            'current_ram' => 1024,
            'current_cpu' => 100,
            'status' => 'active',
            'is_autoscaling_enabled' => true,
            'cloudlinux_id' => 'test123',
        ]);

        // Initial wallet balance
        $initialBalance = $wallet->balance;

        // Test scaling the account
        $result = $autoscalingService->scaleAccount($account, 256, 50);

        // Assertions
        $this->assertTrue($result['success']);

        // Verify wallet was charged
        $wallet->refresh();
        $this->assertLessThan($initialBalance, $wallet->balance);

        // Verify wallet transaction
        $walletLog = $wallet->logs()->latest()->first();
        $this->assertNotNull($walletLog);
        $this->assertLessThan(0, $walletLog->amount); // Negative amount (withdrawal)
        $this->assertEquals('autoscaling', $walletLog->source);

        // Verify scaling log payment status
        $scalingLog = ScalingLog::find($result['scaling_log_id']);
        $this->assertEquals('paid', $scalingLog->payment_status);
        $this->assertStringContainsString('wallet_transaction', $scalingLog->payment_reference);
    }

    /** @test */
    public function it_gets_scaling_recommendations()
    {
        // Create mock service
        $cloudLinuxService = Mockery::mock(CloudLinuxService::class);
        $cloudLinuxService->shouldReceive('getResourceUsage')->once()->andReturn([
            'ram_usage' => 900, // 900 of 1024 MB (87.9%)
            'cpu_usage' => 60,  // 60 of 100% (60%)
        ]);

        $whmcsService = Mockery::mock(WhmcsService::class);

        // Create the autoscaling service
        $autoscalingService = new AutoscalingService($cloudLinuxService, $whmcsService);

        // Create test data
        $user = User::factory()->create();

        $plan = HostingPlan::factory()->create([
            'ram' => 1024,
            'cpu' => 100,
            'max_ram' => 2048,
            'max_cpu' => 200,
        ]);

        $purchased = PurchasedHosting::factory()->create([
            'user_id' => $user->id,
            'hosting_plan_id' => $plan->id,
        ]);

        $account = HostingAccount::factory()->create([
            'user_id' => $user->id,
            'purchased_hosting_id' => $purchased->id,
            'current_ram' => 1024,
            'current_cpu' => 100,
            'cloudlinux_id' => 'test123',
        ]);

        // Configure autoscaling thresholds
        config(['autoscaling.ram_threshold' => 80]);
        config(['autoscaling.cpu_threshold' => 50]);
        config(['autoscaling.ram_step' => 256]);
        config(['autoscaling.cpu_step' => 50]);

        // Get recommendations
        $recommendations = $autoscalingService->getScalingRecommendations($account);

        // Assertions
        $this->assertTrue($recommendations['success']);
        $this->assertTrue($recommendations['scaling_needed']);
        $this->assertEquals(256, $recommendations['recommended_ram_scaling']);
        $this->assertEquals(50, $recommendations['recommended_cpu_scaling']);
        $this->assertGreaterThan(0, $recommendations['estimated_cost']);
    }
}