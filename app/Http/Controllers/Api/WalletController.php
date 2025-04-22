<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\PaymentException;
use App\Http\Controllers\Controller;
use App\Models\PromoCode;
use App\Models\WalletLog;
use App\Services\PaymentGatewayService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class WalletController extends Controller
{
    protected PaymentGatewayService $paymentGatewayService;

    /**
     * Constructor.
     */
    public function __construct(PaymentGatewayService $paymentGatewayService)
    {
        $this->paymentGatewayService = $paymentGatewayService;
    }

    /**
     * Get wallet details.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $wallet = $user->wallet;

        if (!$wallet) {
            return response()->json([
                'success' => false,
                'message' => 'Wallet not found',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'wallet' => $wallet,
        ]);
    }

    /**
     * Get wallet transaction history.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function transactions(Request $request): JsonResponse
    {
        $user = $request->user();
        $wallet = $user->wallet;

        if (!$wallet) {
            return response()->json([
                'success' => false,
                'message' => 'Wallet not found',
            ], 404);
        }

        $validator = Validator::make($request->all(), [
            'type' => 'nullable|string|in:deposit,withdrawal,all',
            'source' => 'nullable|string',
            'page' => 'nullable|integer|min:1',
            'per_page' => 'nullable|integer|min:1|max:100',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors(),
            ], 422);
        }

        $query = $wallet->logs();

        // Filter by type
        if ($request->type && $request->type !== 'all') {
            $query->where('type', $request->type);
        }

        // Filter by source
        if ($request->source) {
            $query->where('source', $request->source);
        }

        // Paginate results
        $perPage = $request->per_page ?? 15;
        $transactions = $query->orderBy('created_at', 'desc')->paginate($perPage);

        return response()->json([
            'success' => true,
            'transactions' => $transactions,
        ]);
    }

    /**
     * Add funds to wallet.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function addFunds(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'amount' => 'required|numeric|min:10|max:10000',
            'payment_method' => 'required|string|in:stripe,paynow,p24',
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
        $wallet = $user->wallet;

        if (!$wallet) {
            return response()->json([
                'success' => false,
                'message' => 'Wallet not found',
            ], 404);
        }

        // Create payment session
        $paymentSession = $this->paymentGatewayService->createPaymentSession(
            $request->amount,
            'Add funds to wallet',
            [
                'user_id' => $user->id,
                'wallet_id' => $wallet->id,
                'email' => $user->email,
            ],
            $request->return_url ?? route('api.wallet.payment.callback')
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

    /**
     * Process payment callback.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function processPayment(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'payment_id' => 'required|string',
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

        // Get payment details from metadata (in a real scenario, you would store payment details in a database)
        // For this example, we'll assume the payment is valid and add funds to the wallet
        $user = $request->user();
        $wallet = $user->wallet;

        if (!$wallet) {
            return response()->json([
                'success' => false,
                'message' => 'Wallet not found',
            ], 404);
        }

        try {
            // Add funds to wallet
            $transaction = $wallet->addFunds(
                $request->amount,
                $request->payment_method,
                $request->payment_id
            );

            return response()->json([
                'success' => true,
                'message' => 'Payment processed successfully',
                'transaction' => $transaction,
            ]);
        } catch (PaymentException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 400);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'An error occurred while processing the payment',
            ], 500);
        }
    }

    /**
     * Apply promo code to wallet.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function applyPromoCode(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'code' => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors(),
            ], 422);
        }

        $user = $request->user();
        $wallet = $user->wallet;

        if (!$wallet) {
            return response()->json([
                'success' => false,
                'message' => 'Wallet not found',
            ], 404);
        }

        // Find promo code
        $promoCode = PromoCode::where('code', $request->code)->first();

        if (!$promoCode) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid promo code',
            ], 404);
        }

        // Check if promo code is valid
        if (!$promoCode->isValid()) {
            return response()->json([
                'success' => false,
                'message' => 'Promo code is no longer valid',
            ], 400);
        }

        try {
            // Apply promo code to wallet
            $transaction = $wallet->applyPromoCode($promoCode);

            if (!$transaction) {
                return response()->json([
                    'success' => false,
                    'message' => 'Promo code cannot be applied to wallet',
                ], 400);
            }

            // Increment promo code usage
            $promoCode->incrementUsage();

            return response()->json([
                'success' => true,
                'message' => 'Promo code applied successfully',
                'transaction' => $transaction,
            ]);
        } catch (PaymentException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 400);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'An error occurred while applying the promo code',
            ], 500);
        }
    }

    /**
     * Get transaction details.
     *
     * @param Request $request
     * @param int $id
     * @return JsonResponse
     */
    public function transactionDetails(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $wallet = $user->wallet;

        if (!$wallet) {
            return response()->json([
                'success' => false,
                'message' => 'Wallet not found',
            ], 404);
        }

        $transaction = WalletLog::where('id', $id)
            ->where('wallet_id', $wallet->id)
            ->first();

        if (!$transaction) {
            return response()->json([
                'success' => false,
                'message' => 'Transaction not found',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'transaction' => $transaction,
        ]);
    }
}