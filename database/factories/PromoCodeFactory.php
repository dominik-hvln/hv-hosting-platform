<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\PromoCode>
 */
class PromoCodeFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $type = fake()->randomElement(['percentage', 'amount']);
        $value = $type === 'percentage' ? fake()->numberBetween(5, 50) : fake()->randomFloat(2, 10, 100);

        return [
            'code' => strtoupper(Str::random(8)),
            'type' => $type,
            'value' => $value,
            'max_uses' => fake()->optional(70)->numberBetween(1, 100),
            'times_used' => 0,
            'valid_from' => now()->subDays(fake()->numberBetween(0, 30)),
            'valid_to' => now()->addDays(fake()->numberBetween(1, 90)),
            'is_active' => true,
            'description' => fake()->optional(80)->sentence(),
            'is_one_time' => fake()->boolean(30),
            'applies_to' => null,
            'min_purchase_amount' => fake()->optional(50)->randomFloat(2, 0, 50),
        ];
    }

    /**
     * Percentage discount.
     */
    public function percentage(int $percent = 10): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => 'percentage',
            'value' => $percent,
        ]);
    }

    /**
     * Fixed amount discount.
     */
    public function amount(float $amount = 50): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => 'amount',
            'value' => $amount,
        ]);
    }

    /**
     * Limited usage promo code.
     */
    public function limitedUse(int $maxUses = 10): static
    {
        return $this->state(fn (array $attributes) => [
            'max_uses' => $maxUses,
        ]);
    }

    /**
     * Expired promo code.
     */
    public function expired(): static
    {
        return $this->state(fn (array $attributes) => [
            'valid_from' => now()->subDays(60),
            'valid_to' => now()->subDays(10),
        ]);
    }

    /**
     * Future promo code (not valid yet).
     */
    public function future(): static
    {
        return $this->state(fn (array $attributes) => [
            'valid_from' => now()->addDays(10),
            'valid_to' => now()->addDays(40),
        ]);
    }

    /**
     * Inactive promo code.
     */
    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }

    /**
     * One-time use promo code.
     */
    public function oneTime(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_one_time' => true,
            'max_uses' => 1,
        ]);
    }

    /**
     * Specific plans promo code.
     */
    public function forPlans(array $planIds): static
    {
        return $this->state(fn (array $attributes) => [
            'applies_to' => $planIds,
        ]);
    }
}