<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ScalingLog;
use App\Models\WalletLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class StatisticsController extends Controller
{
    /**
     * Get resource usage statistics.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function getResourceStats(Request $request): JsonResponse
    {
        $user = $request->user();

        // Get active hosting accounts
        $hostingAccounts = $user->hostingAccounts()
            ->with('purchasedHosting.hostingPlan')
            ->where('status', 'active')
            ->get();

        // Collect current resource allocations
        $totalRam = $hostingAccounts->sum('current_ram');
        $totalCpu = $hostingAccounts->sum('current_cpu');
        $totalStorage = $hostingAccounts->sum('current_storage');
        $totalBandwidth = $hostingAccounts->sum('current_bandwidth');

        // Get scaling history
        $scalingHistory = ScalingLog::whereIn('hosting_account_id', $hostingAccounts->pluck('id'))
            ->select(
                DB::raw('DATE(created_at) as date'),
                DB::raw('SUM(scaled_ram) as ram'),
                DB::raw('SUM(scaled_cpu) as cpu'),
                DB::raw('SUM(cost) as cost')
            )
            ->groupBy('date')
            ->orderBy('date', 'asc')
            ->get();

        // Get resource usage growth
        $resourceGrowth = [
            'ram' => $scalingHistory->sum('ram'),
            'cpu' => $scalingHistory->sum('cpu'),
            'cost' => $scalingHistory->sum('cost'),
        ];

        // Get average monthly scaling
        $monthlyScaling = ScalingLog::whereIn('hosting_account_id', $hostingAccounts->pluck('id'))
            ->select(
                DB::raw('YEAR(created_at) as year'),
                DB::raw('MONTH(created_at) as month'),
                DB::raw('SUM(scaled_ram) as ram'),
                DB::raw('SUM(scaled_cpu) as cpu'),
                DB::raw('SUM(cost) as cost'),
                DB::raw('COUNT(*) as count')
            )
            ->groupBy('year', 'month')
            ->orderBy('year', 'desc')
            ->orderBy('month', 'desc')
            ->limit(12)
            ->get();

        // Format monthly data for chart
        $monthlyData = $monthlyScaling->map(function ($item) {
            return [
                'date' => $item->year . '-' . str_pad($item->month, 2, '0', STR_PAD_LEFT),
                'ram' => $item->ram,
                'cpu' => $item->cpu,
                'cost' => $item->cost,
                'count' => $item->count,
            ];
        })->sortBy('date')->values();

        // Get resource distribution by account
        $accountDistribution = $hostingAccounts->map(function ($account) {
            return [
                'id' => $account->id,
                'username' => $account->username,
                'domain' => $account->domain,
                'ram' => $account->current_ram,
                'cpu' => $account->current_cpu,
                'storage' => $account->current_storage,
                'plan' => $account->purchasedHosting->hostingPlan->name ?? 'Unknown',
            ];
        });

        return response()->json([
            'success' => true,
            'current_resources' => [
                'ram' => $totalRam,
                'cpu' => $totalCpu,
                'storage' => $totalStorage,
                'bandwidth' => $totalBandwidth,
            ],
            'resource_growth' => $resourceGrowth,
            'monthly_scaling' => $monthlyData,
            'account_distribution' => $accountDistribution,
            'active_accounts' => $hostingAccounts->count(),
        ]);
    }

    /**
     * Get spending statistics.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function getSpendingStats(Request $request): JsonResponse
    {
        $user = $request->user();
        $wallet = $user->wallet;

        if (!$wallet) {
            return response()->json([
                'success' => false,
                'message' => 'Wallet not found',
            ], 404);
        }

        // Get monthly spending
        $monthlySpending = WalletLog::where('wallet_id', $wallet->id)
            ->where('amount', '<', 0)
            ->select(
                DB::raw('YEAR(created_at) as year'),
                DB::raw('MONTH(created_at) as month'),
                DB::raw('SUM(ABS(amount)) as total'),
                DB::raw('COUNT(*) as count')
            )
            ->groupBy('year', 'month')
            ->orderBy('year', 'desc')
            ->orderBy('month', 'desc')
            ->limit(12)
            ->get();

        // Format monthly data for chart
        $monthlyData = $monthlySpending->map(function ($item) {
            return [
                'date' => $item->year . '-' . str_pad($item->month, 2, '0', STR_PAD_LEFT),
                'total' => $item->total,
                'count' => $item->count,
            ];
        })->sortBy('date')->values();

        // Get spending by category
        $spendingByCategory = WalletLog::where('wallet_id', $wallet->id)
            ->where('amount', '<', 0)
            ->select('source', DB::raw('SUM(ABS(amount)) as total'))
            ->groupBy('source')
            ->orderBy('total', 'desc')
            ->get();

        // Get recent transactions
        $recentTransactions = WalletLog::where('wallet_id', $wallet->id)
            ->orderBy('created_at', 'desc')
            ->limit(10)
            ->get();

        // Calculate total spending, deposits, and balance history
        $totalSpending = WalletLog::where('wallet_id', $wallet->id)
            ->where('amount', '<', 0)
            ->sum(DB::raw('ABS(amount)'));

        $totalDeposits = WalletLog::where('wallet_id', $wallet->id)
            ->where('amount', '>', 0)
            ->sum('amount');

        $balanceHistory = WalletLog::where('wallet_id', $wallet->id)
            ->select(
                DB::raw('DATE(created_at) as date'),
                DB::raw('MAX(balance_after) as balance')
            )
            ->groupBy('date')
            ->orderBy('date', 'asc')
            ->get();

        return response()->json([
            'success' => true,
            'current_balance' => $wallet->balance,
            'total_spending' => $totalSpending,
            'total_deposits' => $totalDeposits,
            'monthly_spending' => $monthlyData,
            'spending_by_category' => $spendingByCategory,
            'recent_transactions' => $recentTransactions,
            'balance_history' => $balanceHistory,
        ]);
    }

    /**
     * Get ECO mode statistics.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function getEcoStats(Request $request): JsonResponse
    {
        $user = $request->user();

        // Check if ECO mode is enabled
        $ecoEnabled = $user->is_eco_mode;

        // Get hosting accounts
        $hostingAccounts = $user->hostingAccounts()
            ->with('purchasedHosting.hostingPlan')
            ->where('status', 'active')
            ->get();

        // If ECO mode is enabled, calculate savings
        $savings = [
            'power' => 0,
            'co2' => 0,
            'trees_equivalent' => 0,
            'cost' => 0,
        ];

        if ($ecoEnabled) {
            // Calculate power savings based on RAM and CPU usage
            // These are simplified estimates for demonstration purposes
            $totalRam = $hostingAccounts->sum('current_ram'); // in MB
            $totalCpu = $hostingAccounts->sum('current_cpu'); // in percentage points

            // Estimated power consumption in kWh
            // Assuming 1GB RAM = 0.02 kWh, 100% CPU = 0.05 kWh per day
            $ramPower = ($totalRam / 1024) * 0.02 * 30; // monthly power for RAM
            $cpuPower = ($totalCpu / 100) * 0.05 * 30; // monthly power for CPU

            // ECO mode savings (assumed 20% reduction)
            $powerSavings = ($ramPower + $cpuPower) * 0.2;

            // CO2 emissions (0.5 kg CO2 per kWh)
            $co2Savings = $powerSavings * 0.5;

            // Trees equivalent (1 tree absorbs about 22 kg CO2 per year)
            $treesEquivalent = $co2Savings / (22 / 12); // monthly equivalent

            // Cost savings (assuming $0.15 per kWh)
            $costSavings = $powerSavings * 0.15;

            $savings = [
                'power' => round($powerSavings, 2), // kWh
                'co2' => round($co2Savings, 2), // kg
                'trees_equivalent' => round($treesEquivalent, 2),
                'cost' => round($costSavings, 2), // $
            ];
        }

        // Dark mode usage statistics (placeholder for front-end integration)
        $darkModeStats = [
            'enabled' => $ecoEnabled,
            'battery_savings' => $ecoEnabled ? 'Up to 15% longer battery life on OLED screens' : null,
            'eye_strain_reduction' => $ecoEnabled ? 'Reduced eye strain in low-light environments' : null,
        ];

        // Community impact
        $communityImpact = [
            'users_with_eco' => DB::table('users')->where('is_eco_mode', true)->count(),
            'total_power_saved' => $ecoEnabled ? round(rand(1000, 5000) * 0.1, 2) : 0, // Placeholder value
            'total_co2_reduced' => $ecoEnabled ? round(rand(500, 2500) * 0.1, 2) : 0, // Placeholder value
        ];

        return response()->json([
            'success' => true,
            'eco_mode' => [
                'enabled' => $ecoEnabled,
                'savings' => $savings,
                'dark_mode' => $darkModeStats,
                'community_impact' => $communityImpact,
            ],
            'recommendations' => [
                'Use automatic scaling to optimize resource usage',
                'Schedule tasks during off-peak hours',
                'Consider yearly plans for better resource efficiency',
                'Optimize website images and assets',
                'Use a CDN to reduce server load',
            ],
        ]);
    }
}