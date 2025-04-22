<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Referral System Configuration
    |--------------------------------------------------------------------------
    |
    | This file contains the configuration for the referral system.
    |
    */

    // Fixed bonus amount for successful referrals (in PLN)
    'bonus_amount' => env('REFERRAL_BONUS_AMOUNT', 50),

    // Percentage bonus based on the first purchase value (in percent)
    'bonus_percent' => env('REFERRAL_BONUS_PERCENT', 5),

    // Minimum purchase amount to qualify for a referral bonus
    'min_purchase_amount' => 50,

    // Whether to require the referred user to verify their email
    'require_email_verification' => true,

    // Maximum number of referrals per user (0 for unlimited)
    'max_referrals_per_user' => 0,

    // Referral code length
    'code_length' => 8,

    // Referral reward expiry (in days, 0 for never)
    'reward_expiry_days' => 0,

    // Referral tracking cookie lifetime (in days)
    'cookie_lifetime' => 30,

    // Double-sided referral program (both referrer and referred get rewards)
    'double_sided' => false,

    // Bonus for referred user (if double_sided is true)
    'referred_bonus_amount' => 25,

    // Tiered referral program settings
    'tiered_program' => [
        'enabled' => false,
        'tiers' => [
            1 => ['min_referrals' => 0, 'bonus_amount' => 50, 'bonus_percent' => 5],
            2 => ['min_referrals' => 5, 'bonus_amount' => 75, 'bonus_percent' => 7.5],
            3 => ['min_referrals' => 15, 'bonus_amount' => 100, 'bonus_percent' => 10]
        ]
    ],
];