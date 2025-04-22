<?php

namespace App\Services;

use App\Models\HostingAccount;
use App\Models\ScalingLog;
use App\Models\User;
use Exception;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Database\Eloquent\Collection;

class AIService
{
    /**
     * Predict resource needs based on historical data.
     *
     * @param HostingAccount $account
     * @param int $daysToPredict
     * @return array|null
     */
    public function predictResourceNeeds(HostingAccount $account, int $daysToPredict = 30): ?array
    {
        try {
            // Get historical scaling logs
            $historicalData = $account->scalingLogs()
                ->orderBy('created_at', 'asc')
                ->get();

            if ($historicalData->count() < 3) {
                // Not enough data for prediction
                return [
                    'success' => false,
                    'message' => 'Not enough historical data for prediction',
                    'recommended_action' => 'Continue with standard autoscaling',
                ];
            }

            // Calculate average growth rates
            $ramGrowthRate = $this->calculateGrowthRate($historicalData, 'scaled_ram');
            $cpuGrowthRate = $this->calculateGrowthRate($historicalData, 'scaled_cpu');

            // Predict future needs
            $predictedRamIncrease = $ramGrowthRate * $daysToPredict;
            $predictedCpuIncrease = $cpuGrowthRate * $daysToPredict;

            // Current resource usage
            $usage = $account->getResourceUsage();

            // Prepare prediction
            $prediction = [
                'success' => true,
                'current_ram' => $account->current_ram,
                'current_cpu' => $account->current_cpu,
                'current_ram_usage' => $usage ? $usage['ram_usage'] : null,
                'current_cpu_usage' => $usage ? $usage['cpu_usage'] : null,
                'ram_growth_rate_per_day' => round($ramGrowthRate, 2),
                'cpu_growth_rate_per_day' => round($cpuGrowthRate, 2),
                'predicted_ram_increase' => round($predictedRamIncrease, 2),
                'predicted_cpu_increase' => round($predictedCpuIncrease, 2),
                'predicted_ram_in_30_days' => $account->current_ram + $predictedRamIncrease,
                'predicted_cpu_in_30_days' => $account->current_cpu + $predictedCpuIncrease,
                'confidence_score' => $this->calculateConfidenceScore($historicalData),
                'prediction_date' => now()->format('Y-m-d'),
            ];

            // Add recommendation
            $prediction['recommended_action'] = $this->generateRecommendation($account, $prediction);

            return $prediction;
        } catch (Exception $e) {
            Log::error('AI prediction error: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Calculate growth rate from historical data.
     *
     * @param Collection $historicalData
     * @param string $resourceField
     * @return float
     */
    protected function calculateGrowthRate(Collection $historicalData, string $resourceField): float
    {
        if ($historicalData->isEmpty()) {
            return 0.0;
        }

        $totalResource = $historicalData->sum($resourceField);
        $firstDate = $historicalData->first()->created_at;
        $lastDate = $historicalData->last()->created_at;

        $daysDiff = max(1, $firstDate->diffInDays($lastDate));

        return $totalResource / $daysDiff;
    }

    /**
     * Calculate confidence score for prediction.
     *
     * @param Collection $historicalData
     * @return float
     */
    protected function calculateConfidenceScore(Collection $historicalData): float
    {
        if ($historicalData->count() < 3) {
            return 0.3; // Low confidence with little data
        }

        if ($historicalData->count() >= 10) {
            return 0.9; // High confidence with substantial data
        }

        // Calculate variance in scaling (higher variance = lower confidence)
        $ramScalings = $historicalData->pluck('scaled_ram')->toArray();
        $cpuScalings = $historicalData->pluck('scaled_cpu')->toArray();

        $ramVariance = $this->calculateVariance($ramScalings);
        $cpuVariance = $this->calculateVariance($cpuScalings);

        // Normalized variance (0-1 scale, 0 = high variance, 1 = low variance)
        $normalizedRamVariance = max(0, min(1, 1 - ($ramVariance / 10000)));
        $normalizedCpuVariance = max(0, min(1, 1 - ($cpuVariance / 10000)));

        // Base confidence on data amount and variance
        $dataAmountFactor = min(0.8, $historicalData->count() / 10);
        $varianceFactor = ($normalizedRamVariance + $normalizedCpuVariance) / 2;

        return max(0.3, min(0.9, $dataAmountFactor + ($varianceFactor * 0.2)));
    }

    /**
     * Calculate variance of an array.
     *
     * @param array $values
     * @return float
     */
    protected function calculateVariance(array $values): float
    {
        $count = count($values);

        if ($count <= 1) {
            return 0;
        }

        $mean = array_sum($values) / $count;
        $variance = 0;

        foreach ($values as $value) {
            $variance += pow($value - $mean, 2);
        }

        return $variance / $count;
    }

    /**
     * Generate recommendation based on prediction.
     *
     * @param HostingAccount $account
     * @param array $prediction
     * @return string
     */
    protected function generateRecommendation(HostingAccount $account, array $prediction): string
    {
        $plan = $account->purchasedHosting->hostingPlan;
        $predictedRamIn30Days = $prediction['predicted_ram_in_30_days'];
        $predictedCpuIn30Days = $prediction['predicted_cpu_in_30_days'];

        // Check if we're approaching resource limits
        $ramLimitPercentage = ($predictedRamIn30Days / $plan->max_ram) * 100;
        $cpuLimitPercentage = ($predictedCpuIn30Days / $plan->max_cpu) * 100;

        if ($ramLimitPercentage > 90 || $cpuLimitPercentage > 90) {
            return "Consider upgrading to a higher tier plan within 30 days. Predicted resource usage will approach account limits.";
        }

        if ($ramLimitPercentage > 70 || $cpuLimitPercentage > 70) {
            return "Monitor resource usage closely. You may need to upgrade your plan in the next 1-2 months.";
        }

        // Check if current plan is oversized
        $currentRamUsage = $prediction['current_ram_usage'] ?? 0;
        $currentCpuUsage = $prediction['current_cpu_usage'] ?? 0;

        $ramUtilizationPercent = $currentRamUsage ? ($currentRamUsage / $account->current_ram) * 100 : 0;
        $cpuUtilizationPercent = $currentCpuUsage ? ($currentCpuUsage / $account->current_cpu) * 100 : 0;

        if ($ramUtilizationPercent < 30 && $cpuUtilizationPercent < 30 && $account->current_ram > $plan->ram) {
            return "Consider downscaling resources. Current utilization is low and you're using more resources than your base plan.";
        }

        return "Continue with standard autoscaling. Resource usage is within expected parameters.";
    }

    /**
     * Analyze optimal plan for user's needs.
     *
     * @param User $user
     * @return array|null
     */
    public function recommendOptimalPlan(User $user): ?array
    {
        try {
            // Get all user's hosting accounts
            $accounts = $user->hostingAccounts()->with('purchasedHosting.hostingPlan')->get();

            if ($accounts->isEmpty()) {
                return null;
            }

            $recommendations = [];

            foreach ($accounts as $account) {
                $plan = $account->purchasedHosting->hostingPlan;
                $usage = $account->getResourceUsage();

                if (!$usage) {
                    continue;
                }

                $ramUtilization = ($usage['ram_usage'] / $account->current_ram) * 100;
                $cpuUtilization = ($usage['cpu_usage'] / $account->current_cpu) * 100;

                $recommendation = [
                    'account_id' => $account->id,
                    'domain' => $account->domain,
                    'current_plan' => $plan->name,
                    'current_ram' => $account->current_ram,
                    'current_cpu' => $account->current_cpu,
                    'ram_utilization' => round($ramUtilization, 2),
                    'cpu_utilization' => round($cpuUtilization, 2),
                ];

                // Get scaling history
                $scalingCount = $account->scalingLogs()->count();
                $recommendation['scaling_count'] = $scalingCount;

                // Determine optimal plan recommendation
                if ($ramUtilization > 80 || $cpuUtilization > 80) {
                    $recommendation['recommendation'] = 'upgrade';
                    $recommendation['reason'] = 'High resource utilization. Upgrade recommended for better performance.';
                } elseif ($ramUtilization < 30 && $cpuUtilization < 30 && $account->current_ram > $plan->ram) {
                    $recommendation['recommendation'] = 'downgrade';
                    $recommendation['reason'] = 'Low resource utilization. Current resources are underutilized.';
                } else {
                    $recommendation['recommendation'] = 'adequate';
                    $recommendation['reason'] = 'Current plan matches resource usage patterns.';
                }

                // Calculate cost efficiency
                $monthlyScalingCost = $account->scalingLogs()
                    ->where('created_at', '>=', now()->subMonth())
                    ->sum('cost');

                $planMonthlyCost = $account->purchasedHosting->price_paid /
                    ($account->purchasedHosting->end_date->diffInDays($account->purchasedHosting->start_date) / 30);

                $totalMonthlyCost = $planMonthlyCost + $monthlyScalingCost;

                $recommendation['monthly_scaling_cost'] = $monthlyScalingCost;
                $recommendation['plan_monthly_cost'] = $planMonthlyCost;
                $recommendation['total_monthly_cost'] = $totalMonthlyCost;

                $recommendations[] = $recommendation;
            }

            return [
                'success' => true,
                'recommendations' => $recommendations,
                'analysis_date' => now()->format('Y-m-d'),
            ];
        } catch (Exception $e) {
            Log::error('Plan recommendation error: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Analyze traffic patterns for optimal resource allocation.
     *
     * @param HostingAccount $account
     * @param int $days
     * @return array|null
     */
    public function analyzeTrafficPatterns(HostingAccount $account, int $days = 30): ?array
    {
        try {
            // In a real implementation, this would use actual traffic data
            // For this example, we'll simulate traffic data
            $trafficData = $this->getSimulatedTrafficData($account, $days);

            // Analyze daily patterns
            $dailyPatterns = $this->analyzeDailyPatterns($trafficData);

            // Analyze weekly patterns
            $weeklyPatterns = $this->analyzeWeeklyPatterns($trafficData);

            // Generate recommendations
            $recommendations = $this->generateTrafficRecommendations($account, $dailyPatterns, $weeklyPatterns);

            return [
                'success' => true,
                'daily_patterns' => $dailyPatterns,
                'weekly_patterns' => $weeklyPatterns,
                'recommendations' => $recommendations,
                'analysis_date' => now()->format('Y-m-d'),
            ];
        } catch (Exception $e) {
            Log::error('Traffic pattern analysis error: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Get simulated traffic data (for demonstration).
     *
     * @param HostingAccount $account
     * @param int $days
     * @return array
     */
    protected function getSimulatedTrafficData(HostingAccount $account, int $days): array
    {
        $data = [];
        $startDate = now()->subDays($days);

        for ($i = 0; $i < $days; $i++) {
            $date = $startDate->copy()->addDays($i);
            $dayOfWeek = $date->dayOfWeek;
            $hourData = [];

            // Create traffic data for each hour
            for ($hour = 0; $hour < 24; $hour++) {
                // Base traffic varies by day of week (weekends lower)
                $baseTraffic = ($dayOfWeek === 0 || $dayOfWeek === 6) ?
                    rand(100, 500) : rand(500, 1000);

                // Traffic peaks during business hours on weekdays
                if ($dayOfWeek >= 1 && $dayOfWeek <= 5 && $hour >= 9 && $hour <= 17) {
                    $baseTraffic *= rand(12, 20) / 10; // 1.2x to 2x multiplier
                }

                // Some random variation
                $trafficVariation = $baseTraffic * (rand(80, 120) / 100);

                $hourData[$hour] = round($trafficVariation);
            }

            $data[$date->format('Y-m-d')] = $hourData;
        }

        return $data;
    }

    /**
     * Analyze daily traffic patterns.
     *
     * @param array $trafficData
     * @return array
     */
    protected function analyzeDailyPatterns(array $trafficData): array
    {
        $hourlyAverages = array_fill(0, 24, 0);
        $count = 0;

        foreach ($trafficData as $dayData) {
            for ($hour = 0; $hour < 24; $hour++) {
                $hourlyAverages[$hour] += $dayData[$hour] ?? 0;
            }
            $count++;
        }

        // Calculate averages
        if ($count > 0) {
            for ($hour = 0; $hour < 24; $hour++) {
                $hourlyAverages[$hour] = round($hourlyAverages[$hour] / $count);
            }
        }

        // Determine peak hours (hours with traffic > 80% of max)
        $maxTraffic = max($hourlyAverages);
        $peakHours = [];

        for ($hour = 0; $hour < 24; $hour++) {
            if ($hourlyAverages[$hour] >= $maxTraffic * 0.8) {
                $peakHours[] = $hour;
            }
        }

        // Determine low traffic hours (hours with traffic < 30% of max)
        $lowHours = [];

        for ($hour = 0; $hour < 24; $hour++) {
            if ($hourlyAverages[$hour] <= $maxTraffic * 0.3) {
                $lowHours[] = $hour;
            }
        }

        return [
            'hourly_averages' => $hourlyAverages,
            'peak_hours' => $peakHours,
            'low_traffic_hours' => $lowHours,
            'max_traffic' => $maxTraffic,
        ];
    }

    /**
     * Analyze weekly traffic patterns.
     *
     * @param array $trafficData
     * @return array
     */
    protected function analyzeWeeklyPatterns(array $trafficData): array
    {
        $dailyAverages = array_fill(0, 7, 0);
        $dailyCounts = array_fill(0, 7, 0);

        foreach ($trafficData as $dateStr => $hourData) {
            $date = \Carbon\Carbon::createFromFormat('Y-m-d', $dateStr);
            $dayOfWeek = $date->dayOfWeek;

            $dailyAverages[$dayOfWeek] += array_sum($hourData);
            $dailyCounts[$dayOfWeek]++;
        }

        // Calculate averages
        for ($day = 0; $day < 7; $day++) {
            $dailyAverages[$day] = $dailyCounts[$day] > 0 ?
                round($dailyAverages[$day] / $dailyCounts[$day]) : 0;
        }

        // Determine busiest and quietest days
        $maxTraffic = max($dailyAverages);
        $minTraffic = min($dailyAverages);

        $busiestDays = [];
        $quietestDays = [];

        for ($day = 0; $day < 7; $day++) {
            if ($dailyAverages[$day] >= $maxTraffic * 0.9) {
                $busiestDays[] = $day;
            }

            if ($dailyAverages[$day] <= $minTraffic * 1.2) {
                $quietestDays[] = $day;
            }
        }

        // Convert day numbers to names
        $dayNames = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
        $busiestDayNames = array_map(function($day) use ($dayNames) {
            return $dayNames[$day];
        }, $busiestDays);

        $quietestDayNames = array_map(function($day) use ($dayNames) {
            return $dayNames[$day];
        }, $quietestDays);

        return [
            'daily_averages' => [
                'Sunday' => $dailyAverages[0],
                'Monday' => $dailyAverages[1],
                'Tuesday' => $dailyAverages[2],
                'Wednesday' => $dailyAverages[3],
                'Thursday' => $dailyAverages[4],
                'Friday' => $dailyAverages[5],
                'Saturday' => $dailyAverages[6],
            ],
            'busiest_days' => $busiestDayNames,
            'quietest_days' => $quietestDayNames,
            'weekday_average' => round(array_sum(array_slice($dailyAverages, 1, 5)) / 5),
            'weekend_average' => round(($dailyAverages[0] + $dailyAverages[6]) / 2),
        ];
    }

    /**
     * Generate recommendations based on traffic patterns.
     *
     * @param HostingAccount $account
     * @param array $dailyPatterns
     * @param array $weeklyPatterns
     * @return array
     */
    protected function generateTrafficRecommendations(HostingAccount $account, array $dailyPatterns, array $weeklyPatterns): array
    {
        $recommendations = [];

        // Recommend scheduling resource-intensive tasks during low traffic
        if (!empty($dailyPatterns['low_traffic_hours'])) {
            $lowHours = $dailyPatterns['low_traffic_hours'];
            $lowHoursStr = implode(', ', array_map(function($hour) {
                return sprintf('%02d:00', $hour);
            }, $lowHours));

            $recommendations[] = "Schedule resource-intensive tasks (backups, updates, maintenance) during low traffic hours: {$lowHoursStr}.";
        }

        // Recommend preemptive scaling before peak hours
        if (!empty($dailyPatterns['peak_hours'])) {
            $earliestPeakHour = min($dailyPatterns['peak_hours']);
            $preScalingHour = ($earliestPeakHour - 1 + 24) % 24; // 1 hour before first peak

            $recommendations[] = "Consider preemptive scaling 1 hour before peak traffic (around {$preScalingHour}:00) to ensure optimal performance during high traffic periods.";
        }

        // Recommend downscaling during consistent low traffic periods
        if ($weeklyPatterns['weekend_average'] < $weeklyPatterns['weekday_average'] * 0.6) {
            $recommendations[] = "Consider temporary resource reduction during weekends when traffic is significantly lower than weekdays.";
        }

        // Recommend caching for consistent traffic patterns
        if (count($dailyPatterns['peak_hours']) >= 3) {
            $recommendations[] = "Implement content caching during peak hours to reduce server load and improve response times.";
        }

        // General traffic management recommendations
        $recommendations[] = "Configure autoscaling thresholds based on your traffic patterns: increase sensitivity during business hours and decrease during off-hours.";

        return $recommendations;
    }
}