<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\HostingAccount;
use App\Services\AIService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class AIController extends Controller
{
    protected AIService $aiService;

    /**
     * Constructor.
     */
    public function __construct(AIService $aiService)
    {
        $this->aiService = $aiService;
    }

    /**
     * Get resource prediction for a hosting account.
     *
     * @param Request $request
     * @param int $id
     * @return JsonResponse
     */
    public function predictResources(Request $request, int $id): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'days' => 'nullable|integer|min:1|max:90',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors(),
            ], 422);
        }

        $user = $request->user();
        $account = $user->hostingAccounts()->find($id);

        if (!$account) {
            return response()->json([
                'success' => false,
                'message' => 'Hosting account not found',
            ], 404);
        }

        $days = $request->input('days', 30);
        $prediction = $this->aiService->predictResourceNeeds($account, $days);

        if (!$prediction) {
            return response()->json([
                'success' => false,
                'message' => 'Unable to generate prediction',
            ], 500);
        }

        return response()->json([
            'success' => true,
            'prediction' => $prediction,
        ]);
    }

    /**
     * Get optimal plan recommendation.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function recommendPlan(Request $request): JsonResponse
    {
        $user = $request->user();
        $recommendation = $this->aiService->recommendOptimalPlan($user);

        if (!$recommendation) {
            return response()->json([
                'success' => false,
                'message' => 'Unable to generate recommendation or no hosting accounts found',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'recommendation' => $recommendation,
        ]);
    }

    /**
     * Analyze traffic patterns for a hosting account.
     *
     * @param Request $request
     * @param int $id
     * @return JsonResponse
     */
    public function analyzeTrafficPatterns(Request $request, int $id): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'days' => 'nullable|integer|min:1|max:90',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors(),
            ], 422);
        }

        $user = $request->user();
        $account = $user->hostingAccounts()->find($id);

        if (!$account) {
            return response()->json([
                'success' => false,
                'message' => 'Hosting account not found',
            ], 404);
        }

        $days = $request->input('days', 30);
        $analysis = $this->aiService->analyzeTrafficPatterns($account, $days);

        if (!$analysis) {
            return response()->json([
                'success' => false,
                'message' => 'Unable to analyze traffic patterns',
            ], 500);
        }

        return response()->json([
            'success' => true,
            'analysis' => $analysis,
        ]);
    }
}