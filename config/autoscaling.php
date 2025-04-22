<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Autoscaling Configuration
    |--------------------------------------------------------------------------
    |
    | This file contains the configuration for the autoscaling system.
    |
    */

    // Global enable/disable switch for autoscaling
    'enabled' => env('AUTOSCALING_ENABLED', true),

    // Resource thresholds for triggering autoscaling (in percent)
    'cpu_threshold' => env('AUTOSCALING_CPU_THRESHOLD', 50),
    'ram_threshold' => env('AUTOSCALING_RAM_THRESHOLD', 80),

    // Step values for autoscaling increments
    'cpu_step' => env('AUTOSCALING_CPU_STEP', 50),   // CPU in percentage points
    'ram_step' => env('AUTOSCALING_RAM_STEP', 256),  // RAM in MB

    // Maximum allowed resources (per account)
    'max_cpu' => env('AUTOSCALING_MAX_CPU', 800),    // CPU in percentage points
    'max_ram' => env('AUTOSCALING_MAX_RAM', 8192),   // RAM in MB

    // Cost calculation settings
    'cost_per_cpu' => 0.02,  // Cost per 1% CPU
    'cost_per_ram' => 0.01,  // Cost per 1MB RAM

    // Autoscaling frequency limits (in minutes)
    'min_time_between_scales' => 60,  // Minimum time between subsequent autoscalings

    // Notification settings
    'notify_user' => true,  // Whether to notify users about autoscaling
    'notify_admin' => true, // Whether to notify administrators about autoscaling

    // Monitoring settings
    'monitoring_interval' => 5,  // How often to monitor resources (in minutes)
    'averaging_period' => 15,    // Period over which to average resource usage (in minutes)

    // Scaling down settings
    'can_scale_down' => false,       // Whether resources can be automatically scaled down
    'scale_down_threshold' => 20,    // Usage percentage below which to scale down
    'scale_down_delay' => 1440,      // Delay before scaling down (in minutes, default 24h)
];