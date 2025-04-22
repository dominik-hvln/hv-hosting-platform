<?php

namespace App\Models;

use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable implements MustVerifyEmail
{
    use HasApiTokens, HasFactory, Notifiable, HasRoles;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'phone',
        'address',
        'city',
        'postal_code',
        'country',
        'company_name',
        'tax_id',
        'is_marketing_consent',
        'is_eco_mode',
        'two_factor_secret',
        'two_factor_recovery_codes',
        'two_factor_confirmed_at',
        'referral_code',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'password',
        'remember_token',
        'two_factor_secret',
        'two_factor_recovery_codes',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'email_verified_at' => 'datetime',
        'password' => 'hashed',
        'two_factor_confirmed_at' => 'datetime',
        'is_marketing_consent' => 'boolean',
        'is_eco_mode' => 'boolean',
    ];

    /**
     * Get the wallet associated with this user.
     */
    public function wallet()
    {
        return $this->hasOne(Wallet::class);
    }

    /**
     * Get the purchased hostings for this user.
     */
    public function purchasedHostings()
    {
        return $this->hasMany(PurchasedHosting::class);
    }

    /**
     * Get the hosting accounts for this user.
     */
    public function hostingAccounts()
    {
        return $this->hasMany(HostingAccount::class);
    }

    /**
     * Get the referrals created by this user.
     */
    public function referrals()
    {
        return $this->hasMany(Referral::class, 'referrer_id');
    }

    /**
     * Get the referrer of this user.
     */
    public function referrer()
    {
        return $this->belongsTo(User::class, 'referred_by');
    }

    /**
     * Check if user has 2FA enabled.
     */
    public function hasTwoFactorEnabled(): bool
    {
        return $this->two_factor_confirmed_at !== null;
    }

    /**
     * Check if user has verified email.
     */
    public function hasVerifiedEmail(): bool
    {
        return $this->email_verified_at !== null;
    }

    /**
     * Get active hosting accounts.
     */
    public function activeHostingAccounts()
    {
        return $this->hostingAccounts()->where('status', 'active');
    }

    /**
     * Generate and set a new referral code.
     */
    public function generateReferralCode(): string
    {
        $code = strtoupper(substr(md5($this->id . $this->email . time()), 0, 8));
        $this->update(['referral_code' => $code]);
        return $code;
    }
}