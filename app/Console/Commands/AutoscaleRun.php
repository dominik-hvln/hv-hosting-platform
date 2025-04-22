<?php

namespace App\Console\Commands;

use App\Services\AutoscalingService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class AutoscaleRun extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'autoscale:run {--dry-run : Check but do not apply scaling}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Run autoscaling for all eligible hosting accounts';

    /**
     * Execute the console command.
     *
     * @param AutoscalingService $autoscalingService
     * @return int
     */
    public function handle(AutoscalingService $autoscalingService): int
    {
        $this->info('Starting autoscaling...');

        // Check if system is in dry run mode
        $dryRun = $this->option('dry-run');

        if ($dryRun) {
            $this->comment('Running in DRY RUN mode - changes will not be applied');
        }

        // Check if autoscaling is enabled globally
        if (!$autoscalingService->isAutoscalingEnabled()) {
            $this->error('Autoscaling is disabled globally. Enable it first.');
            return 1;
        }

        $this->info('Checking accounts for scaling needs...');

        try {
            if ($dryRun) {
                // In dry run mode, we'll manually check accounts but not apply changes
                $accounts = \App\Models\HostingAccount::with(['purchasedHosting', 'purchasedHosting.hostingPlan', 'user'])
                    ->active()
                    ->autoscalingEnabled()
                    ->whereHas('purchasedHosting', function ($query) {
                        $query->where('is_autoscaling_enabled', true);
                    })
                    ->get();

                $accountsChecked = 0;
                $accountsNeedingScaling = 0;

                $this->info('Found ' . $accounts->count() . ' eligible accounts');
                $this->newLine();

                foreach ($accounts as $account) {
                    $accountsChecked++;

                    // Skip if purchased hosting or plan is missing
                    if (!$account->purchasedHosting || !$account->purchasedHosting->hostingPlan) {
                        continue;
                    }

                    $scaling = $autoscalingService->getScalingRecommendations($account);

                    if ($scaling['success'] && $scaling['scaling_needed']) {
                        $accountsNeedingScaling++;

                        $this->info('Account: ' . $account->username . ' (' . ($account->user->email ?? 'unknown') . ')');
                        $this->line('Current RAM: ' . $scaling['current_ram'] . 'MB, CPU: ' . $scaling['current_cpu'] . '%');
                        $this->line('Usage: RAM ' . $scaling['ram_usage_percent'] . '%, CPU ' . $scaling['cpu_usage_percent'] . '%');
                        $this->line('Recommended scaling: +' . $scaling['recommended_ram_scaling'] . 'MB RAM, +' . $scaling['recommended_cpu_scaling'] . '% CPU');
                        $this->line('Estimated cost: ' . $scaling['estimated_cost'] . ' PLN');
                        $this->newLine();
                    }
                }

                $this->info('DRY RUN summary:');
                $this->line('Accounts checked: ' . $accountsChecked);
                $this->line('Accounts needing scaling: ' . $accountsNeedingScaling);

                // Log the dry run
                Log::info("Autoscaling dry run completed. Checked: {$accountsChecked}, Needing scaling: {$accountsNeedingScaling}");

                return 0;
            } else {
                // Run actual autoscaling
                $result = $autoscalingService->runAutoscaling();

                if ($result['success']) {
                    $this->info('Autoscaling completed successfully!');
                    $this->line('Accounts checked: ' . $result['accounts_checked']);
                    $this->line('Accounts scaled: ' . $result['accounts_scaled']);

                    // Show scaling details if any
                    if (!empty($result['scaling_details'])) {
                        $this->newLine();
                        $this->info('Scaling details:');

                        foreach ($result['scaling_details'] as $detail) {
                            $this->line('Account: ' . $detail['username'] . ' (' . $detail['user_email'] . ')');
                            $this->line('Scaled: +' . $detail['scaled_ram'] . 'MB RAM, +' . $detail['scaled_cpu'] . '% CPU');
                            $this->line('Usage was: RAM ' . $detail['ram_usage_percent'] . '%, CPU ' . $detail['cpu_usage_percent'] . '%');
                            $this->newLine();
                        }
                    }

                    // Log the successful run
                    Log::info($result['message']);

                    return 0;
                } else {
                    $this->error('Autoscaling failed: ' . $result['message']);

                    // Log the error
                    Log::error('Autoscaling command failed: ' . $result['message']);

                    return 1;
                }
            }
        } catch (\Exception $e) {
            $this->error('Error during autoscaling: ' . $e->getMessage());

            // Log the exception
            Log::error('Autoscaling command exception: ' . $e->getMessage());

            return 1;
        }
    }
}