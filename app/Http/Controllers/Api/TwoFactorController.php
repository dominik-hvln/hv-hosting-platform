<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use PragmaRX\Google2FA\Google2FA;
use Illuminate\Support\Facades\Crypt;

class TwoFactorController extends Controller
{
    protected $google2fa;

    public function __construct()
    {
        $this->google2fa = new Google2FA();
    }

    public function enable(Request $request)
    {
        $user = $request->user();

        $secret = $this->google2fa->generateSecretKey();
        $user->two_factor_secret = Crypt::encrypt($secret);
        $user->two_factor_confirmed_at = null;
        $user->save();

        $qrImage = $this->google2fa->getQRCodeInline(
            config('app.name'),
            $user->email,
            $secret
        );

        return response()->json([
            'message' => '2FA włączone tymczasowo',
            'secret' => $secret,
            'qr' => $qrImage,
        ]);
    }

    public function confirm(Request $request)
    {
        $user = $request->user();
        $secret = Crypt::decrypt($user->two_factor_secret);

        $valid = $this->google2fa->verifyKey($secret, $request->input('code'));

        if (!$valid) {
            return response()->json(['error' => 'Nieprawidłowy kod'], 403);
        }

        $user->two_factor_confirmed_at = now();
        $user->two_factor_recovery_codes = json_encode(
            collect(range(1, 5))->map(fn () => Str::random(10))->toArray()
        );
        $user->save();

        return response()->json(['message' => '2FA potwierdzone']);
    }

    public function disable(Request $request)
    {
        $user = $request->user();
        $user->two_factor_secret = null;
        $user->two_factor_confirmed_at = null;
        $user->two_factor_recovery_codes = null;
        $user->save();

        return response()->json(['message' => '2FA wyłączone']);
    }
}
