<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PromoCode;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class PromoCodeController extends Controller
{
    /**
     * Get all active promo codes.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function index(Request $request): JsonResponse
    {
        // For regular users, only show public promo codes
        $user = $request->user();
        $promoCodes = PromoCode::active()->valid()->get();

        return response()->json([
            'success' => true,
            'promo_codes' => $promoCodes,
        ]);
    }

    /**
     * Get promo code by code.
     *
     * @param Request $request
     * @param string $code
     * @return JsonResponse
     */
    public function getByCode(Request $request, string $code): JsonResponse
    {
        $promoCode = PromoCode::where('code', $code)->first();

        if (!$promoCode) {
            return response()->json([
                'success' => false,
                'message' => 'Promo code not found',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'promo_code' => $promoCode,
        ]);
    }

    /**
     * Validate a promo code.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function validateCode(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'code' => 'required|string',
            'plan_id' => 'nullable|integer|exists:hosting_plans,id',
            'amount' => 'nullable|numeric|min:0',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors(),
            ], 422);
        }

        $promoCode = PromoCode::where('code', $request->code)->first();

        if (!$promoCode) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid promo code',
            ], 404);
        }

        if (!$promoCode->isValid()) {
            return response()->json([
                'success' => false,
                'message' => 'Promo code is no longer valid',
                'reasons' => [
                    'is_active' => $promoCode->is_active,
                    'max_uses_exceeded' => $promoCode->max_uses > 0 && $promoCode->times_used >= $promoCode->max_uses,
                    'expired' => $promoCode->valid_to && now() > $promoCode->valid_to,
                    'not_started' => $promoCode->valid_from && now() < $promoCode->valid_from,
                ],
            ], 400);
        }

        // Check if plan_id is valid for this promo code
        if ($request->plan_id && !empty($promoCode->applies_to) && !in_array($request->plan_id, $promoCode->applies_to)) {
            return response()->json([
                'success' => false,
                'message' => 'Promo code is not valid for this plan',
            ], 400);
        }

        // Check minimum purchase amount
        if ($request->amount && $promoCode->min_purchase_amount > 0 && $request->amount < $promoCode->min_purchase_amount) {
            return response()->json([
                'success' => false,
                'message' => 'Order amount does not meet minimum requirement for this promo code',
                'min_purchase_amount' => $promoCode->min_purchase_amount,
            ], 400);
        }

        // Calculate discount
        $discount = 0;
        if ($request->amount) {
            $discount = $promoCode->calculateDiscount($request->amount);
        }

        return response()->json([
            'success' => true,
            'message' => 'Promo code is valid',
            'promo_code' => $promoCode,
            'discount' => $discount,
            'final_amount' => $request->amount ? $request->amount - $discount : null,
        ]);
    }
}