<?php

namespace Database\Factories;

use App\Models\HostingPlan;
use App\Models\PromoCode;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\PurchasedHosting>
 */
class PurchasedHostingFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $plan = HostingPlan::factory()->create();
        $startDate = now()->subDays(fake()->numberBetween(1, 180));
        $endDate = $startDate->copy()->addMonths(fake()->randomElement([1, 12]));
        $pricePaid = $endDate->diffInMonths($startDate) === 1 ? $plan->price_monthly : $plan->price_yearly;

        return [
            'user_id' => User::factory(),
            'hosting_plan_id' => $plan->id,
            'start_date' => $startDate,
            'end_date' => $endDate,
            'status' => fake()->randomElement(['active', 'expired', 'suspended']),
            'price_paid' => $pricePaid,
            'payment_method' => fake()->randomElement(['wallet', 'stripe', 'paynow', 'p24']),
            'payment_reference' => fake()->uuid(),
            'whmcs_service_id' => fake()->numberBetween(1000, 9999),
            'renewal_date' => $endDate,
            'is_auto_renew' => fake()->boolean(80),
            'is_autoscaling_enabled' => fake()->boolean(80),
            'promo_code_id' => null,
            'discount_amount' => 0,
        ];
    }

    /**
     * Active purchase.
     */
    public function active(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'active',
            'start_date' => now()->subDays(30),
            'end_date' => now()->addDays(fake()->numberBetween(30, 335)),
        ]);
    }

    /**
     * Expired purchase.
     */
    public function expired(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'expired',
            'start_date' => now()->subDays(60),
            'end_date' => now()->subDays(5),
        ]);
    }

    /**
     * Suspended purchase.
     */
    public function suspended(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'suspended',
            'start_date' => now()->subDays(45),
            'end_date' => now()->addDays(15),
        ]);
    }

    /**
     * Monthly purchase.
     */
    public function monthly(): static
    {
        return $this->state(function (array $attributes) {
            $plan = HostingPlan::find($attributes['hosting_plan_id']);
            $startDate = $attributes['start_date'] ?? now()->subDays(15);
            $endDate = (clone $startDate)->addMonth();

            return [
                'start_date' => $startDate,
                'end_date' => $endDate,
                'renewal_date' => $endDate,
                'price_paid' => $plan->price_monthly,
            ];
        });
    }

    /**
     * Yearly purchase.
     */
    public function yearly(): static
    {
        return $this->state(function (array $attributes) {
            $plan = HostingPlan::find($attributes['hosting_plan_id']);
            $startDate = $attributes['start_date'] ?? now()->subDays(15);
            $endDate = (clone $startDate)->addYear();

            return [
                'start_date' => $startDate,
                'end_date' => $endDate,
                'renewal_date' => $endDate,
                'price_paid' => $plan->price_yearly,
            ];
        });
    }

    /**
     * Purchase with promo code.
     */
    public function withPromoCode(): static
    {
        return $this->state(function (array $attributes) {
            $promoCode = PromoCode::factory()->create([
                'type' => 'percentage',
                'value' => 10,
            ]);

            $price = $attributes['price_paid'];
            $discount = $price * 0.1;

            return [
                'promo_code_id' => $promoCode->id,
                'discount_amount' => $discount,
                'price_paid' => $price - $discount,
            ];
        });
    }
}