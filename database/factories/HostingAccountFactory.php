<?php

namespace Database\Factories;

use App\Models\PurchasedHosting;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\HostingAccount>
 */
class HostingAccountFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $domain = fake()->domainName();
        $username = strtolower(Str::random(8));
        $purchasedHosting = PurchasedHosting::factory()->create();
        $plan = $purchasedHosting->hostingPlan;

        return [
            'user_id' => $purchasedHosting->user_id,
            'purchased_hosting_id' => $purchasedHosting->id,
            'username' => $username,
            'domain' => $domain,
            'server_id' => fake()->numberBetween(1, 10),
            'status' => $purchasedHosting->status,
            'current_ram' => $plan->ram,
            'current_cpu' => $plan->cpu,
            'current_storage' => $plan->storage,
            'current_bandwidth' => $plan->bandwidth,
            'cloudlinux_id' => 'cl_' . Str::random(10),
            'directadmin_username' => $username,
            'is_autoscaling_enabled' => $purchasedHosting->is_autoscaling_enabled,
            'auto_backup_enabled' => fake()->boolean(70),
            'last_login_at' => fake()->optional(70)->dateTimeThisMonth(),
            'is_suspended' => $purchasedHosting->status === 'suspended',
            'suspension_reason' => $purchasedHosting->status === 'suspended' ? fake()->randomElement(['Payment overdue', 'Abuse', 'Terms violation']) : null,
        ];
    }

    /**
     * Active account.
     */
    public function active(): static
    {
        return $this->state(function (array $attributes) {
            $purchasedHosting = PurchasedHosting::find($attributes['purchased_hosting_id']);
            if ($purchasedHosting) {
                $purchasedHosting->update(['status' => 'active']);
            }

            return [
                'status' => 'active',
                'is_suspended' => false,
                'suspension_reason' => null,
            ];
        });
    }

    /**
     * Suspended account.
     */
    public function suspended(string $reason = 'Payment overdue'): static
    {
        return $this->state(function (array $attributes) use ($reason) {
            $purchasedHosting = PurchasedHosting::find($attributes['purchased_hosting_id']);
            if ($purchasedHosting) {
                $purchasedHosting->update(['status' => 'suspended']);
            }

            return [
                'status' => 'suspended',
                'is_suspended' => true,
                'suspension_reason' => $reason,
            ];
        });
    }

    /**
     * Account with autoscaling enabled.
     */
    public function withAutoscaling(): static
    {
        return $this->state(function (array $attributes) {
            $purchasedHosting = PurchasedHosting::find($attributes['purchased_hosting_id']);
            if ($purchasedHosting) {
                $purchasedHosting->update(['is_autoscaling_enabled' => true]);
            }

            return [
                'is_autoscaling_enabled' => true,
            ];
        });
    }

    /**
     * Account with scaled resources.
     */
    public function withScaledResources(int $additionalRam = 256, int $additionalCpu = 50): static
    {
        return $this->state(function (array $attributes) use ($additionalRam, $additionalCpu) {
            $purchasedHosting = PurchasedHosting::find($attributes['purchased_hosting_id']);
            $plan = $purchasedHosting->hostingPlan;

            return [
                'current_ram' => $plan->ram + $additionalRam,
                'current_cpu' => $plan->cpu + $additionalCpu,
            ];
        });
    }
}