<?php

namespace Database\Factories;

use App\Models\HostingAccount;
use App\Models\PurchasedHosting;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\ScalingLog>
 */
class ScalingLogFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $hostingAccount = HostingAccount::factory()->create();
        $previousRam = $hostingAccount->current_ram - fake()->randomElement([128, 256, 512]);
        $previousCpu = $hostingAccount->current_cpu - fake()->randomElement([25, 50, 100]);
        $scaledRam = $hostingAccount->current_ram - $previousRam;
        $scaledCpu = $hostingAccount->current_cpu - $previousCpu;
        $cost = ($scaledRam / 100) + ($scaledCpu / 25); // Simple cost calculation

        return [
            'hosting_account_id' => $hostingAccount->id,
            'purchased_hosting_id' => $hostingAccount->purchased_hosting_id,
            'previous_ram' => $previousRam,
            'previous_cpu' => $previousCpu,
            'new_ram' => $hostingAccount->current_ram,
            'new_cpu' => $hostingAccount->current_cpu,
            'scaled_ram' => $scaledRam,
            'scaled_cpu' => $scaledCpu,
            'reason' => fake()->randomElement(['autoscaling', 'manual', 'scheduled']),
            'cost' => $cost,
            'payment_reference' => fake()->optional(70)->uuid(),
            'payment_status' => fake()->randomElement(['paid', 'pending', 'failed']),
        ];
    }

    /**
     * Autoscaling log.
     */
    public function autoscaling(): static
    {
        return $this->state(fn (array $attributes) => [
            'reason' => 'autoscaling',
        ]);
    }

    /**
     * Manual scaling log.
     */
    public function manual(): static
    {
        return $this->state(fn (array $attributes) => [
            'reason' => 'manual',
        ]);
    }

    /**
     * Paid scaling log.
     */
    public function paid(): static
    {
        return $this->state(fn (array $attributes) => [
            'payment_status' => 'paid',
            'payment_reference' => 'wallet_transaction_' . fake()->numberBetween(1000, 9999),
        ]);
    }

    /**
     * Pending payment scaling log.
     */
    public function pending(): static
    {
        return $this->state(fn (array $attributes) => [
            'payment_status' => 'pending',
        ]);
    }

    /**
     * Failed payment scaling log.
     */
    public function failed(): static
    {
        return $this->state(fn (array $attributes) => [
            'payment_status' => 'failed',
        ]);
    }

    /**
     * Specific scaling amount.
     */
    public function withScalingAmount(int $ramAmount, int $cpuAmount): static
    {
        return $this->state(function (array $attributes) use ($ramAmount, $cpuAmount) {
            $previousRam = $attributes['new_ram'] - $ramAmount;
            $previousCpu = $attributes['new_cpu'] - $cpuAmount;
            $cost = ($ramAmount / 100) + ($cpuAmount / 25);

            return [
                'previous_ram' => $previousRam,
                'previous_cpu' => $previousCpu,
                'scaled_ram' => $ramAmount,
                'scaled_cpu' => $cpuAmount,
                'cost' => $cost,
            ];
        });
    }
}