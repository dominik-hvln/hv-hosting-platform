<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Referral;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ReferralController extends Controller
{
    /**
     * Get user's referral code.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function getCode(Request $request): JsonResponse
    {
        $user = $request->user();

        // Generate referral code if not already generated
        if (!$user->referral_code) {
            $user->generateReferralCode();
            $user->refresh();
        }

        return response()->json([
            'success' => true,
            'referral_code' => $user->referral_code,
            'referral_url' => config('app.url') . '/register?ref=' . $user->referral_code,
        ]);
    }

    /**
     * Get referrals made by the user.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function getReferrals(Request $request): JsonResponse
    {
        $user = $request->user();
        $referrals = $user->referrals()->with('referred')->get();

        // Calculate total bonus
        $totalBonus = Referral::getTotalBonusForReferrer($user->id);

        return response()->json([
            'success' => true,
            'referrals' => $referrals,
            'total_bonus' => $totalBonus,
            'referral_count' => $referrals->count(),
        ]);
    }

    /**
     * Get referrals statistics.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function getStats(Request $request): JsonResponse
    {
        $user = $request->user();

        // Get referrals by status
        $pending = $user->referrals()->pending()->count();
        $rewarded = $user->referrals()->rewarded()->count();
        $cancelled = $user->referrals()->cancelled()->count();

        // Total bonus amount
        $totalBonus = Referral::getTotalBonusForReferrer($user->id);

        // Recent referrals
        $recentReferrals = $user->referrals()
            ->with('referred')
            ->orderBy('created_at', 'desc')
            ->limit(5)
            ->get();

        return response()->json([
            'success' => true,
            'stats' => [
                'total' => $pending + $rewarded + $cancelled,
                'pending' => $pending,
                'rewarded' => $rewarded,
                'cancelled' => $cancelled,
                'total_bonus' => $totalBonus,
            ],
            'recent_referrals' => $recentReferrals,
        ]);
    }

    /**
     * Regenerate referral code.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function regenerateCode(Request $request): JsonResponse
    {
        $user = $request->user();

        // Generate new referral code
        $code = $user->generateReferralCode();

        return response()->json([
            'success' => true,
            'message' => 'Referral code regenerated successfully',
            'referral_code' => $code,
            'referral_url' => config('app.url') . '/register?ref=' . $code,
        ]);
    }

    /**
     * Get referral program information.
     *
     * @return JsonResponse
     */
    public function getProgramInfo(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'program' => [
                'bonus_amount' => config('referral.bonus_amount', 50),
                'bonus_percent' => config('referral.bonus_percent', 5),
                'description' => 'Zaproś znajomych i odbierz bonus za każdego użytkownika, który zarejestruje się z Twojego polecenia i zakupi plan hostingowy',
                'terms' => [
                    'Bonus jest przyznawany, gdy polecona osoba zarejestruje się i zakupi dowolny plan hostingowy',
                    'Bonus jest dodawany do portfela automatycznie po potwierdzeniu płatności za plan',
                    'Możesz polecić nieograniczoną liczbę osób',
                    'Administrator zastrzega sobie prawo do modyfikacji warunków programu poleceń',
                ],
            ],
        ]);
    }

    /**
     * Get referral by ID.
     *
     * @param Request $request
     * @param int $id
     * @return JsonResponse
     */
    public function getReferral(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $referral = $user->referrals()->with('referred', 'purchasedHosting')->find($id);

        if (!$referral) {
            return response()->json([
                'success' => false,
                'message' => 'Referral not found',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'referral' => $referral,
        ]);
    }
}