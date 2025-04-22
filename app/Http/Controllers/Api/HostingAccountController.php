<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\HostingAccount;
use App\Services\DirectAdminService;
use App\Services\BackupService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Log;

class HostingAccountController extends Controller
{
    protected DirectAdminService $directAdminService;
    protected BackupService $backupService;

    /**
     * Constructor.
     */
    public function __construct(DirectAdminService $directAdminService, BackupService $backupService)
    {
        $this->directAdminService = $directAdminService;
        $this->backupService = $backupService;
    }

    /**
     * Get hosting account.
     *
     * @param Request $request
     * @param int $id
     * @return JsonResponse
     */
    public function getAccount(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $account = $user->hostingAccounts()->with('purchasedHosting.hostingPlan')->find($id);

        if (!$account) {
            return response()->json([
                'success' => false,
                'message' => 'Hosting account not found',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'account' => $account,
        ]);
    }

    /**
     * Get hosting account information from DirectAdmin.
     *
     * @param Request $request
     * @param int $id
     * @return JsonResponse
     */
    public function getAccountInfo(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $account = $user->hostingAccounts()->find($id);

        if (!$account) {
            return response()->json([
                'success' => false,
                'message' => 'Hosting account not found',
            ], 404);
        }

        if (!$account->directadmin_username) {
            return response()->json([
                'success' => false,
                'message' => 'DirectAdmin account not configured',
            ], 400);
        }

        try {
            $accountInfo = $this->directAdminService->getAccountDetails($account->directadmin_username);
            $accountUsage = $this->directAdminService->getAccountUsage($account->directadmin_username);

            if (!$accountInfo || !$accountUsage) {
                return response()->json([
                    'success' => false,
                    'message' => 'Failed to retrieve account information',
                ], 500);
            }

            return response()->json([
                'success' => true,
                'account_info' => $accountInfo,
                'account_usage' => $accountUsage,
            ]);
        } catch (\Exception $e) {
            Log::error('Error retrieving account info: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Error retrieving account information',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Toggle auto backup for hosting account.
     *
     * @param Request $request
     * @param int $id
     * @return JsonResponse
     */
    public function toggleBackup(Request $request, int $id): JsonResponse
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
        $account = $user->hostingAccounts()->find($id);

        if (!$account) {
            return response()->json([
                'success' => false,
                'message' => 'Hosting account not found',
            ], 404);
        }

        $account->update(['auto_backup_enabled' => $request->enabled]);

        return response()->json([
            'success' => true,
            'message' => 'Auto backup ' . ($request->enabled ? 'enabled' : 'disabled') . ' successfully',
            'account' => $account->fresh(),
        ]);
    }

    /**
     * Create a backup for hosting account.
     *
     * @param Request $request
     * @param int $id
     * @return JsonResponse
     */
    public function createBackup(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $account = $user->hostingAccounts()->find($id);

        if (!$account) {
            return response()->json([
                'success' => false,
                'message' => 'Hosting account not found',
            ], 404);
        }

        if (!$account->directadmin_username) {
            return response()->json([
                'success' => false,
                'message' => 'DirectAdmin account not configured',
            ], 400);
        }

        try {
            $backup = $this->backupService->createBackup($account);

            if (!$backup) {
                return response()->json([
                    'success' => false,
                    'message' => 'Failed to create backup',
                ], 500);
            }

            return response()->json([
                'success' => true,
                'message' => 'Backup created successfully',
                'backup' => $backup,
            ]);
        } catch (\Exception $e) {
            Log::error('Error creating backup: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Error creating backup',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Restore a backup for hosting account.
     *
     * @param Request $request
     * @param int $id
     * @param string $backupId
     * @return JsonResponse
     */
    public function restoreBackup(Request $request, int $id, string $backupId): JsonResponse
    {
        $user = $request->user();
        $account = $user->hostingAccounts()->find($id);

        if (!$account) {
            return response()->json([
                'success' => false,
                'message' => 'Hosting account not found',
            ], 404);
        }

        if (!$account->directadmin_username) {
            return response()->json([
                'success' => false,
                'message' => 'DirectAdmin account not configured',
            ], 400);
        }

        try {
            $restored = $this->backupService->restoreBackup($account, $backupId);

            if (!$restored) {
                return response()->json([
                    'success' => false,
                    'message' => 'Failed to restore backup',
                ], 500);
            }

            return response()->json([
                'success' => true,
                'message' => 'Backup restored successfully',
            ]);
        } catch (\Exception $e) {
            Log::error('Error restoring backup: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Error restoring backup',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get backups for hosting account.
     *
     * @param Request $request
     * @param int $id
     * @return JsonResponse
     */
    public function getBackups(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $account = $user->hostingAccounts()->find($id);

        if (!$account) {
            return response()->json([
                'success' => false,
                'message' => 'Hosting account not found',
            ], 404);
        }

        if (!$account->directadmin_username) {
            return response()->json([
                'success' => false,
                'message' => 'DirectAdmin account not configured',
            ], 400);
        }

        try {
            $backups = $this->backupService->getBackups($account);

            return response()->json([
                'success' => true,
                'backups' => $backups,
            ]);
        } catch (\Exception $e) {
            Log::error('Error retrieving backups: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Error retrieving backups',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}