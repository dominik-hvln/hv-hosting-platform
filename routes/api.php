<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\WalletController;
use App\Http\Controllers\Api\HostingController;
use App\Http\Controllers\Api\ReferralController;
use App\Http\Controllers\Api\PromoCodeController;
use App\Http\Controllers\Api\HostingAccountController;
use App\Http\Controllers\Api\StatisticsController;
use App\Http\Controllers\Api\TwoFactorController;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "api" middleware group. Make something great!
|
*/

// Publiczne trasy
Route::prefix('v1')->group(function () {
    // Autentykacja
    Route::post('/register', [AuthController::class, 'register']);
    Route::post('/login', [AuthController::class, 'login']);
    Route::get('/email/verify/{id}/{hash}', [AuthController::class, 'verifyEmail'])->name('verification.verify');

    // Trasy dostępne po zalogowaniu
    Route::middleware('auth:sanctum')->group(function () {
        // Profil użytkownika
        Route::get('/me', [AuthController::class, 'me']);
        Route::post('/logout', [AuthController::class, 'logout']);
        Route::put('/profile', [AuthController::class, 'updateProfile']);
        Route::post('/password', [AuthController::class, 'changePassword']);
        Route::post('/email/verification-notification', [AuthController::class, 'resendVerificationEmail'])
            ->name('verification.send');

        // 2FA
        Route::post('/2fa/enable', [TwoFactorController::class, 'enable']);
        Route::post('/2fa/confirm', [TwoFactorController::class, 'confirm']);
        Route::post('/2fa/disable', [TwoFactorController::class, 'disable']);

        // Portfel
        Route::prefix('wallet')->group(function () {
            Route::get('/', [WalletController::class, 'index']);
            Route::get('/transactions', [WalletController::class, 'transactions']);
            Route::get('/transactions/{id}', [WalletController::class, 'transactionDetails']);
            Route::post('/add-funds', [WalletController::class, 'addFunds']);
            Route::post('/process-payment', [WalletController::class, 'processPayment'])->name('api.wallet.payment.callback');
            Route::post('/promo-code', [WalletController::class, 'applyPromoCode']);
        });

        // Hosting
        Route::prefix('hosting')->group(function () {
            // Plany
            Route::get('/plans', [HostingController::class, 'getPlans']);
            Route::get('/plans/{id}', [HostingController::class, 'getPlan']);

            // Zakup i zarządzanie
            Route::post('/purchase', [HostingController::class, 'purchase']);
            Route::post('/process-payment', [HostingController::class, 'processPayment'])->name('api.hosting.payment.callback');
            Route::get('/services', [HostingController::class, 'getServices']);
            Route::get('/services/{id}', [HostingController::class, 'getService']);
            Route::post('/services/{id}/renew', [HostingController::class, 'renewService']);
            Route::post('/services/{id}/renew/process-payment', [HostingController::class, 'processRenewalPayment'])
                ->name('api.hosting.renewal.callback');
            Route::put('/services/{id}/autoscaling', [HostingController::class, 'toggleAutoscaling']);
            Route::get('/services/{id}/scaling-logs', [HostingController::class, 'getScalingLogs']);
            Route::get('/services/{id}/resource-usage', [HostingController::class, 'getResourceUsage']);
        });

        // Konta hostingowe
        Route::prefix('account')->group(function () {
            Route::get('/{id}', [HostingAccountController::class, 'getAccount']);
            Route::get('/{id}/info', [HostingAccountController::class, 'getAccountInfo']);
            Route::put('/{id}/backup', [HostingAccountController::class, 'toggleBackup']);
            Route::post('/{id}/backup/create', [HostingAccountController::class, 'createBackup']);
            Route::post('/{id}/backup/{backupId}/restore', [HostingAccountController::class, 'restoreBackup']);
            Route::get('/{id}/backups', [HostingAccountController::class, 'getBackups']);
        });

        // System poleceń
        Route::prefix('referrals')->group(function () {
            Route::get('/code', [ReferralController::class, 'getCode']);
            Route::post('/regenerate-code', [ReferralController::class, 'regenerateCode']);
            Route::get('/', [ReferralController::class, 'getReferrals']);
            Route::get('/stats', [ReferralController::class, 'getStats']);
            Route::get('/program', [ReferralController::class, 'getProgramInfo']);
            Route::get('/{id}', [ReferralController::class, 'getReferral']);
        });

        // Kody promocyjne
        Route::prefix('promo-codes')->group(function () {
            Route::get('/', [PromoCodeController::class, 'index']);
            Route::get('/{code}', [PromoCodeController::class, 'getByCode']);
            Route::post('/validate', [PromoCodeController::class, 'validateCode']);
        });

        // Statystyki
        Route::prefix('statistics')->group(function () {
            Route::get('/resources', [StatisticsController::class, 'getResourceStats']);
            Route::get('/spending', [StatisticsController::class, 'getSpendingStats']);
            Route::get('/eco', [StatisticsController::class, 'getEcoStats']);
        });

        // AI i predykcje
        Route::prefix('ai')->group(function () {
            Route::get('/account/{id}/predict', [AIController::class, 'predictResources']);
            Route::get('/recommend-plan', [AIController::class, 'recommendPlan']);
            Route::get('/account/{id}/traffic-analysis', [AIController::class, 'analyzeTrafficPatterns']);
        });
    });
});