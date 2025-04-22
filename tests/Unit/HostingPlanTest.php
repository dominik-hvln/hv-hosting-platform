<?php

namespace Tests\Unit;

use App\Models\HostingPlan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HostingPlanTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function it_can_calculate_yearly_discount_percentage()
    {
        $plan = HostingPlan::factory()->create([
            'price_monthly' => 10,
            'price_yearly' => 100, // 10 * 12 = 120, so discount is ~16.67%
        ]);

        $discountPercent = $plan->getYearlyDiscountPercentAttribute();
        $this->assertEquals(16.67, round($discountPercent, 2));
    }

    /** @test */
    public function it_returns_zero_discount_when_monthly_price_is_zero()
    {
        $plan = HostingPlan::factory()->create([
            'price_monthly' => 0,
            'price_yearly' => 100,
        ]);

        $discountPercent = $plan->getYearlyDiscountPercentAttribute();
        $this->assertEquals(0, $discountPercent);
    }

    /** @test */
    public function it_can_check_if_plan_can_autoscale()
    {
        $plan1 = HostingPlan::factory()->create([
            'ram' => 1024,
            'cpu' => 100,
            'max_ram' => 2048,
            'max_cpu' => 200,
        ]);

        $plan2 = HostingPlan::factory()->create([
            'ram' => 1024,
            'cpu' => 100,
            'max_ram' => 1024,
            'max_cpu' => 100,
        ]);

        $this->assertTrue($plan1->canAutoscale());
        $this->assertFalse($plan2->canAutoscale());
    }

    /** @test */
    public function it_can_scope_to_active_plans()
    {
        HostingPlan::factory()->create([
            'is_active' => true,
            'name' => 'Active Plan',
        ]);

        HostingPlan::factory()->create([
            'is_active' => false,
            'name' => 'Inactive Plan',
        ]);

        $activePlans = HostingPlan::active()->get();

        $this->assertEquals(1, $activePlans->count());
        $this->assertEquals('Active Plan', $activePlans->first()->name);
    }

    /** @test */
    public function it_can_order_plans_by_sort_order()
    {
        HostingPlan::factory()->create([
            'name' => 'Plan C',
            'sort_order' => 3,
        ]);

        HostingPlan::factory()->create([
            'name' => 'Plan A',
            'sort_order' => 1,
        ]);

        HostingPlan::factory()->create([
            'name' => 'Plan B',
            'sort_order' => 2,
        ]);

        $orderedPlans = HostingPlan::ordered()->get();

        $this->assertEquals(3, $orderedPlans->count());
        $this->assertEquals('Plan A', $orderedPlans[0]->name);
        $this->assertEquals('Plan B', $orderedPlans[1]->name);
        $this->assertEquals('Plan C', $orderedPlans[2]->name);
    }
}