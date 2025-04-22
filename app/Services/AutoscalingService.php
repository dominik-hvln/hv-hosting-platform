<?php

namespace App\Services;

use App\Events\ResourcesScaled;
use App\Models\HostingAccount;
use App\Models\ScalingLog;
use Exception;
use Illuminate\Support\Facades\Log;

class AutoscalingService
{
    protected CloudLinuxService $cloudLinuxService;
    protected WhmcsService $whmcsService;

    /**
     * Constructor.
     */
    public function __construct(CloudLinuxService $cloudLinuxService, WhmcsService $whmcsService)
    {
        $this->cloudLinuxService = $cloudLinuxService;
        $this->whmcsService = $whmcsService;
    }

    /**
     * Run autoscaling for all eligible accounts.
     *
     * @return array
     */
    public function runAutoscaling(): array
    {
        if (!config('autoscaling.enabled', true)) {
            return [
                'success' => false,
                'message' => 'Autoscaling is disabled globally',
                'accounts_checked' => 0,
                'accounts_scaled' => 0,
            ];
        }

        $accountsChecked = 0;
        $accountsScaled = 0;
        $scalingDetails = [];

        try {
            // Get all active hosting accounts with autoscaling enabled
            $accounts = HostingAccount::with(['purchasedHosting', 'purchasedHosting.hostingPlan', 'user'])
                ->active()
                ->autoscalingEnabled()
                ->whereHas('purchasedHosting', function ($query) {
                    $query->where('is_autoscaling_enabled', true);
                })
                ->get();

            foreach ($accounts as $account) {
                $accountsChecked++;

                // Skip if purchased hosting or plan is missing
                if (!$account->purchasedHosting || !$account->purchasedHosting->hostingPlan) {
                    continue;
                }

                // Check if scaling is needed
                $scalingCheck = $account->needsScaling();

                if ($scalingCheck['needs_scaling']) {
                    $scalingResult = $this->scaleAccount($account, $scalingCheck['scale_ram'], $scalingCheck['scale_cpu']);

                    if ($scalingResult['success']) {
                        $accountsScaled++;
                        $scalingDetails[] = [
                            'account_id' => $account->id,
                            'username' => $account->username,
                            'user_email' => $account->user->email ?? 'unknown',
                            'scaled_ram' => $scalingCheck['scale_ram'],
                            'scaled_cpu' => $scalingCheck['scale_cpu'],
                            'ram_usage_percent' => $scalingCheck['ram_usage_percent'],
                            'cpu_usage_percent' => $scalingCheck['cpu_usage_percent'],
                        ];
                    }
                }
            }

            return [
                'success' => true,
                'message' => "Autoscaling completed successfully. Checked: {$accountsChecked}, Scaled: {$accountsScaled}",
                'accounts_checked' => $accountsChecked,
                'accounts_scaled' => $accountsScaled,
                'scaling_details' => $scalingDetails,
            ];
        } catch (Exception $e) {
            Log::error('Autoscaling error: ' . $e->getMessage());

            return [
                'success' => false,
                'message' => 'Autoscaling error: ' . $e->getMessage(),
                'accounts_checked' => $accountsChecked,
                'accounts_scaled' => $accountsScaled,
            ];
        }
    }

    /**
     * Scale an individual account.
     *
     * @param HostingAccount $account
     * @param int $scaleRam
     * @param int $scaleCpu
     * @return array
     */
    public function scaleAccount(HostingAccount $account, int $scaleRam = 0, int $scaleCpu = 0): array
    {
        if ($scaleRam <= 0 && $scaleCpu <= 0) {
            return [
                'success' => false,
                'message' => 'No scaling needed',
            ];
        }

        try {
            // Get user and hosting plan
            $user = $account->user;
            $purchasedHosting = $account->purchasedHosting;
            $hostingPlan = $purchasedHosting->hostingPlan;

            // Check if we're within allowed maximum resources
            $newRam = $account->current_ram + $scaleRam;
            $newCpu = $account->current_cpu + $scaleCpu;

            if ($newRam > $hostingPlan->max_ram) {
                $scaleRam = max(0, $hostingPlan->max_ram - $account->current_ram);
            }

            if ($newCpu > $hostingPlan->max_cpu) {
                $scaleCpu = max(0, $hostingPlan->max_cpu - $account->current_cpu);
            }

            // If no scaling is possible after limits check, return
            if ($scaleRam <= 0 && $scaleCpu <= 0) {
                return [
                    'success' => false,
                    'message' => 'Maximum resources reached, no further scaling possible',
                ];
            }

            // Calculate cost of scaling
            $cost = ScalingLog::calculateCost($scaleRam, $scaleCpu);

            // Apply scaling
            $scalingLog = $account->scaleResources($scaleRam, $scaleCpu);
            $scalingLog->cost = $cost;
            $scalingLog->save();

            // Handle payment for scaling
            if ($cost > 0) {
                $paymentSuccess = $this->handleScalingPayment($user, $purchasedHosting, $scalingLog, $cost);
                $scalingLog->payment_status = $paymentSuccess ? 'paid' : 'pending';
                $scalingLog->save();
            }

            // Sync with WHMCS
            if ($purchasedHosting->whmcs_service_id) {
                $this->whmcsService->syncService($purchasedHosting->whmcs_service_id, [
                    'cpu' => $account->current_cpu,
                    'ram' => $account->current_ram,
                ]);
            }

            // Fire scaling event
            event(new ResourcesScaled($account, $scalingLog));

            return [
                'success' => true,
                'message' => 'Resources scaled successfully',
                'scaling_log_id' => $scalingLog->id,
                'scaled_ram' => $scaleRam,
                'scaled_cpu' => $scaleCpu,
                'cost' => $cost,
            ];
        } catch (Exception $e) {
            Log::error('Account scaling error: ' . $e->getMessage());

            return [
                'success' => false,
                'message' => 'Scaling error: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * Handle payment for scaling.
     *
     * @param \App\Models\User $user
     * @param \App\Models\PurchasedHosting $purchasedHosting
     * @param \App\Models\ScalingLog $scalingLog
     * @param float $cost
     * @return bool
     */
    protected function handleScalingPayment($user, $purchasedHosting, $scalingLog, float $cost): bool
    {
        try {
            // First try to charge the wallet if user has one
            if ($user->wallet && $user->wallet->hasSufficientFunds($cost)) {
                $transaction = $user->wallet->withdrawFunds(
                    $cost,
                    'autoscaling',
                    'Scaling resources: ' . $scalingLog->id
                );

                $scalingLog->payment_reference = 'wallet_transaction_' . $transaction->id;
                return true;
            }

            // If wallet payment is not possible, try to charge through WHMCS
            if ($purchasedHosting->whmcs_service_id && $user->whmcs_client_id) {
                $invoiceId = $this->whmcsService->addCharge(
                    $user->whmcs_client_id,
                    $cost,
                    'Autoscaling resources: ' . $scalingLog->scaled_ram . 'MB RAM, ' . $scalingLog->scaled_cpu . '% CPU'
                );

                if ($invoiceId) {
                    $scalingLog->payment_reference = 'whmcs_invoice_' . $invoiceId;
                    return true;
                }
            }

            return false;
        } catch (Exception $e) {
            Log::error('Scaling payment error: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Get scaling recommendations for an account.
     *
     * @param HostingAccount $account
     * @return array
     */
    public function getScalingRecommendations(HostingAccount $account): array
    {
        try {
            $usage = $account->getResourceUsage();

            if (!$usage) {
                return [
                    'success' => false,
                    'message' => 'Could not retrieve resource usage',
                ];
            }

            $purchasedHosting = $account->purchasedHosting;
            $hostingPlan = $purchasedHosting->hostingPlan;

            // Calculate RAM usage percentage
            $ramUsagePercent = ($usage['ram_usage'] / $account->current_ram) * 100;
            $cpuUsagePercent = ($usage['cpu_usage'] / $account->current_cpu) * 100;

            // Determine recommended scaling
            $scaleRam = 0;
            $scaleCpu = 0;
            $scaling_needed = false;

            if ($ramUsagePercent >= config('autoscaling.ram_threshold', 80) && $account->current_ram < $hostingPlan->max_ram) {
                $scaleRam = config('autoscaling.ram_step', 256);
                $scaling_needed = true;
            }

            if ($cpuUsagePercent >= config('autoscaling.cpu_threshold', 50) && $account->current_cpu < $hostingPlan->max_cpu) {
                $scaleCpu = config('autoscaling.cpu_step', 50);
                $scaling_needed = true;
            }

            // Calculate the cost of recommended scaling
            $cost = ScalingLog::calculateCost($scaleRam, $scaleCpu);

            return [
                'success' => true,
                'scaling_needed' => $scaling_needed,
                'current_ram' => $account->current_ram,
                'current_cpu' => $account->current_cpu,
                'max_ram' => $hostingPlan->max_ram,
                'max_cpu' => $hostingPlan->max_cpu,
                'ram_usage' => $usage['ram_usage'],
                'cpu_usage' => $usage['cpu_usage'],
                'ram_usage_percent' => round($ramUsagePercent, 2),
                'cpu_usage_percent' => round($cpuUsagePercent, 2),
                'recommended_ram_scaling' => $scaleRam,
                'recommended_cpu_scaling' => $scaleCpu,
                'estimated_cost' => $cost,
            ];
        } catch (Exception $e) {
            Log::error('Error getting scaling recommendations: ' . $e->getMessage());

            return [
                'success' => false,
                'message' => 'Error getting scaling recommendations: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * Check if autoscaling is enabled globally.
     *
     * @return bool
     */
    public function isAutoscalingEnabled(): bool
    {
        return config('autoscaling.enabled', true);
    }

    /**
     * Enable autoscaling globally.
     *
     * @return bool
     */
    public function enableAutoscaling(): bool
    {
        try {
            config(['autoscaling.enabled' => true]);
            return true;
        } catch (Exception $e) {
            Log::error('Failed to enable autoscaling: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Disable autoscaling globally.
     *
     * @return bool
     */
    public function disableAutoscaling(): bool
    {
        try {
            config(['autoscaling.enabled' => false]);
            return true;
        } catch (Exception $e) {
            Log::error('Failed to disable autoscaling: ' . $e->getMessage());
            return false;
        }
    }
}