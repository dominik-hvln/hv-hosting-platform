<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\HostingPlan>
 */
class HostingPlanFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $ram = fake()->randomElement([512, 1024, 2048, 4096, 8192]);
        $cpu = fake()->randomElement([50, 100, 200, 400, 800]);
        $storage = fake()->randomElement([5120, 10240, 20480, 51200, 102400]);
        $bandwidth = fake()->randomElement([102400, 204800, 512000, 1024000, 2048000]);

        $priceMonthly = ($ram / 1024) * 10 + ($cpu / 100) * 15;
        $priceYearly = $priceMonthly * 10; // 2 months free for yearly plan

        return [
            'name' => 'Hosting ' . ($ram / 1024) . 'GB',
            'description' => fake()->paragraph(),
            'ram' => $ram,
            'cpu' => $cpu,
            'storage' => $storage,
            'bandwidth' => $bandwidth,
            'price_monthly' => $priceMonthly,
            'price_yearly' => $priceYearly,
            'setup_fee' => 0,
            'is_active' => true,
            'sort_order' => fake()->numberBetween(1, 10),
            'features' => json_encode([
                'SSD Storage',
                'Free SSL',
                'Daily Backups',
                '24/7 Support',
                'Unlimited Databases',
                'Unlimited Email Accounts',
            ]),
            'max_ram' => $ram * 2,
            'max_cpu' => $cpu * 2,
            'whmcs_product_id' => fake()->numberBetween(1, 100),
        ];
    }

    /**
     * Basic plan.
     */
    public function basic(): static
    {
        return $this->state(fn (array $attributes) => [
            'name' => 'Basic Hosting',
            'ram' => 1024,
            'cpu' => 100,
            'storage' => 10240,
            'bandwidth' => 102400,
            'price_monthly' => 10,
            'price_yearly' => 100,
            'max_ram' => 2048,
            'max_cpu' => 200,
            'sort_order' => 1,
        ]);
    }

    /**
     * Advanced plan.
     */
    public function advanced(): static
    {
        return $this->state(fn (array $attributes) => [
            'name' => 'Advanced Hosting',
            'ram' => 2048,
            'cpu' => 200,
            'storage' => 20480,
            'bandwidth' => 204800,
            'price_monthly' => 20,
            'price_yearly' => 200,
            'max_ram' => 4096,
            'max_cpu' => 400,
            'sort_order' => 2,
        ]);
    }

    /**
     * Premium plan.
     */
    public function premium(): static
    {
        return $this->state(fn (array $attributes) => [
            'name' => 'Premium Hosting',
            'ram' => 4096,
            'cpu' => 400,
            'storage' => 51200,
            'bandwidth' => 512000,
            'price_monthly' => 40,
            'price_yearly' => 400,
            'max_ram' => 8192,
            'max_cpu' => 800,
            'sort_order' => 3,
        ]);
    }
}