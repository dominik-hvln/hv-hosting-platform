<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\HostingPlan;
use App\Models\PurchasedHosting;
use App\Models\PromoCode;
use App\Services\DirectAdminService;
use App\Services\CloudLinuxService;
use App\Services\WhmcsService;
use App\Services\PaymentGatewayService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class HostingController extends Controller
{
    protected DirectAdminService $directAdminService;
    protected CloudLinuxService $cloudLinuxService;
    protected WhmcsService $whmcsService;
    protected PaymentGatewayService $paymentGatewayService;

    /**
     * Constructor.
     */
    public function __construct(
        DirectAdminService $directAdminService,
        CloudLinuxService $cloudLinuxService,
        WhmcsService $whmcsService,
        PaymentGatewayService $paymentGatewayService
    ) {
        $this->directAdminService = $directAdminService;
        $this->cloudLinuxService = $cloudLinuxService;
        $this->whmcsService = $whmcsService;
        $this->paymentGatewayService = $paymentGatewayService;
    }

    /**
     * Get available hosting plans.
     *
     * @return JsonResponse
     */
    public function getPlans(): JsonResponse
    {
        $plans = HostingPlan::active()->ordered()->get();

        return response()->json([
            'success' => true,
            'plans' => $plans,
        ]);
    }

    /**
     * Get hosting plan details.
     *
     * @param int $id
     * @return JsonResponse
     */
    public function getPlan(int $id): JsonResponse
    {
        $plan = HostingPlan::active()->find($id);

        if (!$plan) {
            return response()->json([
                'success' => false,
                'message' => 'Plan not found',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'plan' => $plan,
        ]);
    }

    /**
     * Purchase a hosting plan.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function purchase(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'plan_id' => 'required|integer|exists:hosting_plans,id',
            'domain' => 'required|string|max:255',
            'period' => 'required|string|in:monthly,yearly',
            'payment_method' => 'required|string|in:wallet,stripe,paynow,p24',
            'promo_code' => 'nullable|string|exists:promo_codes,code',
            'is_autoscaling_enabled' => 'boolean',
            'return_url' => 'nullable|url',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors(),
            ], 422);
        }

        $user = $request->user();
        $plan = HostingPlan::active()->find($request->plan_id);

        if (!$plan) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid plan',
            ], 404);
        }

        // Calculate price based on period
        $price = $request->period === 'yearly' ? $plan->price_yearly : $plan->price_monthly;
        $periodMonths = $request->period === 'yearly' ? 12 : 1;

        // Apply promo code if provided
        $discount = 0;
        $promoCode = null;
        if ($request->promo_code) {
            $promoCode = PromoCode::where('code', $request->promo_code)->first();
            if ($promoCode && $promoCode->isValid() && $promoCode->appliesToPlan($plan)) {
                $discount = $promoCode->calculateDiscount($price);
                $price -= $discount;
            }
        }

        // If payment method is wallet, check if user has sufficient funds
        if ($request->payment_method === 'wallet') {
            if (!$user->wallet || !$user->wallet->hasSufficientFunds($price)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Insufficient funds in wallet',
                ], 400);
            }

            // Deduct from wallet
            $transaction = $user->wallet->withdrawFunds(
                $price,
                'hosting_purchase',
                'Purchase of ' . $plan->name . ' plan for ' . $request->domain
            );

            // Create purchased hosting
            $purchased = $this->createPurchasedHosting(
                $user,
                $plan,
                $request->domain,
                $periodMonths,
                $price,
                'wallet',
                $transaction->id,
                $promoCode,
                $discount,
                $request->is_autoscaling_enabled ?? true
            );

            return response()->json([
                'success' => true,
                'message' => 'Plan purchased successfully',
                'purchased' => $purchased,
            ]);
        } else {
            // Create payment session for external payment
            $paymentSession = $this->paymentGatewayService->createPaymentSession(
                $price,
                'Purchase of ' . $plan->name . ' plan for ' . $request->domain,
                [
                    'user_id' => $user->id,
                    'plan_id' => $plan->id,
                    'domain' => $request->domain,
                    'period' => $request->period,
                    'is_autoscaling_enabled' => $request->is_autoscaling_enabled ?? true,
                    'promo_code' => $request->promo_code,
                ],
                $request->return_url ?? route('api.hosting.payment.callback')
            );

            if (!$paymentSession) {
                return response()->json([
                    'success' => false,
                    'message' => 'Failed to create payment session',
                ], 500);
            }

            return response()->json([
                'success' => true,
                'payment' => $paymentSession,
            ]);
        }
    }

    /**
     * Process payment callback for hosting plan purchase.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function processPayment(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'payment_id' => 'required|string',
            'plan_id' => 'required|integer',
            'domain' => 'required|string',
            'period' => 'required|string|in:monthly,yearly',
            'is_autoscaling_enabled' => 'boolean',
            'promo_code' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors(),
            ], 422);
        }

        // Verify payment
        $paymentVerified = $this->paymentGatewayService->verifyPayment($request->payment_id);

        if (!$paymentVerified) {
            return response()->json([
                'success' => false,
                'message' => 'Payment verification failed',
            ], 400);
        }

        $user = $request->user();
        $plan = HostingPlan::active()->find($request->plan_id);

        if (!$plan) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid plan',
            ], 404);
        }

        // Calculate price and period
        $price = $request->period === 'yearly' ? $plan->price_yearly : $plan->price_monthly;
        $periodMonths = $request->period === 'yearly' ? 12 : 1;

        // Apply promo code if provided
        $discount = 0;
        $promoCode = null;
        if ($request->promo_code) {
            $promoCode = PromoCode::where('code', $request->promo_code)->first();
            if ($promoCode && $promoCode->isValid() && $promoCode->appliesToPlan($plan)) {
                $discount = $promoCode->calculateDiscount($price);
                $price -= $discount;
            }
        }

        // Create purchased hosting
        $purchased = $this->createPurchasedHosting(
            $user,
            $plan,
            $request->domain,
            $periodMonths,
            $price,
            $request->payment_method,
            $request->payment_id,
            $promoCode,
            $discount,
            $request->is_autoscaling_enabled ?? true
        );

        return response()->json([
            'success' => true,
            'message' => 'Payment processed and plan purchased successfully',
            'purchased' => $purchased,
        ]);
    }

    /**
     * Create purchased hosting and associated account.
     *
     * @param \App\Models\User $user
     * @param \App\Models\HostingPlan $plan
     * @param string $domain
     * @param int $periodMonths
     * @param float $price
     * @param string $paymentMethod
     * @param string $paymentReference
     * @param \App\Models\PromoCode|null $promoCode
     * @param float $discount
     * @param bool $isAutoscalingEnabled
     * @return \App\Models\PurchasedHosting
     */
    protected function createPurchasedHosting(
        $user,
        $plan,
        string $domain,
        int $periodMonths,
        float $price,
        string $paymentMethod,
        string $paymentReference,
        ?PromoCode $promoCode = null,
        float $discount = 0,
        bool $isAutoscalingEnabled = true
    ): PurchasedHosting {
        // Create purchased hosting
        $purchased = PurchasedHosting::create([
            'user_id' => $user->id,
            'hosting_plan_id' => $plan->id,
            'start_date' => now(),
            'end_date' => now()->addMonths($periodMonths),
            'renewal_date' => now()->addMonths($periodMonths),
            'status' => 'pending',
            'price_paid' => $price,
            'payment_method' => $paymentMethod,
            'payment_reference' => $paymentReference,
            'is_auto_renew' => true,
            'is_autoscaling_enabled' => $isAutoscalingEnabled,
            'promo_code_id' => $promoCode ? $promoCode->id : null,
            'discount_amount' => $discount,
        ]);

        // Increment promo code usage if applied
        if ($promoCode) {
            $promoCode->incrementUsage();
        }

        // Create WHMCS service if integration is enabled
        if ($plan->whmcs_product_id && config('services.whmcs.enabled', true)) {
            // Create or get WHMCS client
            $whmcsClientId = $user->whmcs_client_id;
            if (!$whmcsClientId) {
                $whmcsClientId = $this->whmcsService->createClient([
                    'name' => $user->name,
                    'email' => $user->email,
                    'address' => $user->address,
                    'city' => $user->city,
                    'postal_code' => $user->postal_code,
                    'country' => $user->country,
                    'phone' => $user->phone,
                    'company_name' => $user->company_name,
                    'tax_id' => $user->tax_id,
                ]);

                // Update user with WHMCS client ID
                if ($whmcsClientId) {
                    $user->update(['whmcs_client_id' => $whmcsClientId]);
                }
            }

            // Create WHMCS order
            if ($whmcsClientId) {
                $orderId = $this->whmcsService->createOrder(
                    $whmcsClientId,
                    $plan->whmcs_product_id,
                    $domain,
                    $periodMonths === 12 ? 'yearly' : 'monthly',
                    [
                        'CPU' => $plan->cpu,
                        'RAM' => $plan->ram,
                        'Storage' => $plan->storage,
                        'Bandwidth' => $plan->bandwidth,
                    ]
                );

                if ($orderId) {
                    // Accept the order
                    $this->whmcsService->acceptOrder($orderId);

                    // Get service ID (this is simplified, in reality you would need to query for the service ID)
                    $service = $this->whmcsService->getService($orderId);
                    if ($service) {
                        $purchased->update(['whmcs_service_id' => $service['id']]);
                    }
                }
            }
        }

        // Generate username for hosting account
        $username = $this->generateUsername($user, $domain);

        // Create hosting account
        $hostingAccount = $purchased->hostingAccount()->create([
            'user_id' => $user->id,
            'username' => $username,
            'domain' => $domain,
            'status' => 'pending',
            'current_ram' => $plan->ram,
            'current_cpu' => $plan->cpu,
            'current_storage' => $plan->storage,
            'current_bandwidth' => $plan->bandwidth,
            'is_autoscaling_enabled' => $isAutoscalingEnabled,
            'auto_backup_enabled' => true,
        ]);

        // Initialize CloudLinux account
        $cloudlinuxId = $this->cloudLinuxService->createUser(
            $username,
            $plan->ram,
            $plan->cpu
        );

        if ($cloudlinuxId) {
            $hostingAccount->update(['cloudlinux_id' => $cloudlinuxId]);
        }

        // Create DirectAdmin account
        $password = Str::random(12);
        $directAdminCreated = $this->directAdminService->createAccount(
            $username,
            $password,
            $user->email,
            'Basic', // Package name in DirectAdmin
            $domain
        );

        if ($directAdminCreated) {
            $hostingAccount->update([
                'directadmin_username' => $username,
                'status' => 'active',
            ]);
            $purchased->update(['status' => 'active']);
        }

        // Process referral if applicable
        if ($user->referred_by) {
            $referral = $user->referrer->referrals()
                ->where('referred_id', $user->id)
                ->first();

            if ($referral && !$referral->isRewarded()) {
                $referral->purchased_hosting_id = $purchased->id;
                $referral->bonus_amount = $referral->calculateBonus($purchased);
                $referral->save();
                $referral->applyBonus();
            }
        }

        return $purchased;
    }

    /**
     * Generate a username for hosting account.
     *
     * @param \App\Models\User $user
     * @param string $domain
     * @return string
     */
    protected function generateUsername($user, string $domain): string
    {
        // Extract first part of domain
        $domainParts = explode('.', $domain);
        $domainBase = $domainParts[0];

        // Sanitize and limit length
        $base = preg_replace('/[^a-z0-9]/', '', strtolower($domainBase));
        $base = substr($base, 0, 8);

        // Add random suffix
        $suffix = substr(md5(time() . $user->id), 0, 4);

        return $base . $suffix;
    }

    /**
     * Get user's purchased hosting services.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function getServices(Request $request): JsonResponse
    {
        $user = $request->user();
        $services = $user->purchasedHostings()
            ->with(['hostingPlan', 'hostingAccount'])
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json([
            'success' => true,
            'services' => $services,
        ]);
    }

    /**
     * Get details of a specific service.
     *
     * @param Request $request
     * @param int $id
     * @return JsonResponse
     */
    public function getService(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $service = $user->purchasedHostings()
            ->with(['hostingPlan', 'hostingAccount', 'scalingLogs'])
            ->find($id);

        if (!$service) {
            return response()->json([
                'success' => false,
                'message' => 'Service not found',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'service' => $service,
        ]);
    }

    /**
     * Renew a service.
     *
     * @param Request $request
     * @param int $id
     * @return JsonResponse
     */
    public function renewService(Request $request, int $id): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'period' => 'required|string|in:monthly,yearly',
            'payment_method' => 'required|string|in:wallet,stripe,paynow,p24',
            'promo_code' => 'nullable|string|exists:promo_codes,code',
            'return_url' => 'nullable|url',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors(),
            ], 422);
        }

        $user = $request->user();
        $service = $user->purchasedHostings()
            ->with(['hostingPlan'])
            ->find($id);

        if (!$service) {
            return response()->json([
                'success' => false,
                'message' => 'Service not found',
            ], 404);
        }

        $plan = $service->hostingPlan;

        // Calculate price based on period
        $price = $request->period === 'yearly' ? $plan->price_yearly : $plan->price_monthly;
        $periodMonths = $request->period === 'yearly' ? 12 : 1;

        // Apply promo code if provided
        $discount = 0;
        $promoCode = null;
        if ($request->promo_code) {
            $promoCode = PromoCode::where('code', $request->promo_code)->first();
            if ($promoCode && $promoCode->isValid() && $promoCode->appliesToPlan($plan)) {
                $discount = $promoCode->calculateDiscount($price);
                $price -= $discount;
            }
        }

        // If payment method is wallet, check if user has sufficient funds
        if ($request->payment_method === 'wallet') {
            if (!$user->wallet || !$user->wallet->hasSufficientFunds($price)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Insufficient funds in wallet',
                ], 400);
            }

            // Deduct from wallet
            $transaction = $user->wallet->withdrawFunds(
                $price,
                'hosting_renewal',
                'Renewal of ' . $plan->name . ' plan for ' . $service->hostingAccount->domain
            );

            // Renew service
            $service->renew($periodMonths);
            $service->update([
                'price_paid' => $price,
                'payment_method' => 'wallet',
                'payment_reference' => 'wallet_transaction_' . $transaction->id,
                'promo_code_id' => $promoCode ? $promoCode->id : null,
                'discount_amount' => $discount,
            ]);

            // Increment promo code usage if applied
            if ($promoCode) {
                $promoCode->incrementUsage();
            }

            return response()->json([
                'success' => true,
                'message' => 'Service renewed successfully',
                'service' => $service,
            ]);
        } else {
            // Create payment session for external payment
            $paymentSession = $this->paymentGatewayService->createPaymentSession(
                $price,
                'Renewal of ' . $plan->name . ' plan for ' . $service->hostingAccount->domain,
                [
                    'user_id' => $user->id,
                    'service_id' => $service->id,
                    'period' => $request->period,
                    'promo_code' => $request->promo_code,
                ],
                $request->return_url ?? route('api.hosting.renewal.callback')
            );

            if (!$paymentSession) {
                return response()->json([
                    'success' => false,
                    'message' => 'Failed to create payment session',
                ], 500);
            }

            return response()->json([
                'success' => true,
                'payment' => $paymentSession,
            ]);
        }
    }

    /**
     * Process renewal payment callback.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function processRenewalPayment(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'payment_id' => 'required|string',
            'service_id' => 'required|integer',
            'period' => 'required|string|in:monthly,yearly',
            'promo_code' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors(),
            ], 422);
        }

        // Verify payment
        $paymentVerified = $this->paymentGatewayService->verifyPayment($request->payment_id);

        if (!$paymentVerified) {
            return response()->json([
                'success' => false,
                'message' => 'Payment verification failed',
            ], 400);
        }

        $user = $request->user();
        $service = $user->purchasedHostings()
            ->with(['hostingPlan'])
            ->find($request->service_id);

        if (!$service) {
            return response()->json([
                'success' => false,
                'message' => 'Service not found',
            ], 404);
        }

        $plan = $service->hostingPlan;

        // Calculate price based on period
        $price = $request->period === 'yearly' ? $plan->price_yearly : $plan->price_monthly;
        $periodMonths = $request->period === 'yearly' ? 12 : 1;

        // Apply promo code if provided
        $discount = 0;
        $promoCode = null;
        if ($request->promo_code) {
            $promoCode = PromoCode::where('code', $request->promo_code)->first();
            if ($promoCode && $promoCode->isValid() && $promoCode->appliesToPlan($plan)) {
                $discount = $promoCode->calculateDiscount($price);
                $price -= $discount;
            }
        }

        // Renew service
        $service->renew($periodMonths);
        $service->update([
            'price_paid' => $price,
            'payment_method' => $request->payment_method,
            'payment_reference' => $request->payment_id,
            'promo_code_id' => $promoCode ? $promoCode->id : null,
            'discount_amount' => $discount,
        ]);

        // Increment promo code usage if applied
        if ($promoCode) {
            $promoCode->incrementUsage();
        }

        return response()->json([
            'success' => true,
            'message' => 'Payment processed and service renewed successfully',
            'service' => $service,
        ]);
    }

    /**
     * Toggle autoscaling for a service.
     *
     * @param Request $request
     * @param int $id
     * @return JsonResponse
     */
    public function toggleAutoscaling(Request $request, int $id): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'enabled' => 'required|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors(),
            ], 422);
        }

        $user = $request->user();
        $service = $user->purchasedHostings()->find($id);

        if (!$service) {
            return response()->json([
                'success' => false,
                'message' => 'Service not found',
            ], 404);
        }

        $hostingAccount = $service->hostingAccount;

        if (!$hostingAccount) {
            return response()->json([
                'success' => false,
                'message' => 'Hosting account not found',
            ], 404);
        }

        $service->update(['is_autoscaling_enabled' => $request->enabled]);
        $hostingAccount->update(['is_autoscaling_enabled' => $request->enabled]);

        return response()->json([
            'success' => true,
            'message' => 'Autoscaling ' . ($request->enabled ? 'enabled' : 'disabled') . ' successfully',
            'service' => $service->refresh(),
        ]);
    }

    /**
     * Get scaling logs for a service.
     *
     * @param Request $request
     * @param int $id
     * @return JsonResponse
     */
    public function getScalingLogs(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $service = $user->purchasedHostings()->find($id);

        if (!$service) {
            return response()->json([
                'success' => false,
                'message' => 'Service not found',
            ], 404);
        }

        $logs = $service->scalingLogs()
            ->orderBy('created_at', 'desc')
            ->paginate(15);

        return response()->json([
            'success' => true,
            'logs' => $logs,
        ]);
    }

    /**
     * Get resource usage for a service.
     *
     * @param Request $request
     * @param int $id
     * @return JsonResponse
     */
    public function getResourceUsage(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $service = $user->purchasedHostings()->find($id);

        if (!$service) {
            return response()->json([
                'success' => false,
                'message' => 'Service not found',
            ], 404);
        }

        $hostingAccount = $service->hostingAccount;

        if (!$hostingAccount) {
            return response()->json([
                'success' => false,
                'message' => 'Hosting account not found',
            ], 404);
        }

        $usage = $hostingAccount->getResourceUsage();

        if (!$usage) {
            return response()->json([
                'success' => false,
                'message' => 'Could not retrieve resource usage',
            ], 500);
        }

        return response()->json([
            'success' => true,
            'usage' => $usage,
            'resources' => [
                'ram' => $hostingAccount->current_ram,
                'cpu' => $hostingAccount->current_cpu,
                'storage' => $hostingAccount->current_storage,
                'bandwidth' => $hostingAccount->current_bandwidth,
            ],
        ]);
    }
}