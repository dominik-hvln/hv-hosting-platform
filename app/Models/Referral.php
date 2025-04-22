<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Referral extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'referrer_id',
        'referred_id',
        'code',
        'status',
        'bonus_amount',
        'bonus_percent',
        'rewarded_at',
        'purchased_hosting_id',
        'notes',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'bonus_amount' => 'decimal:2',
        'bonus_percent' => 'decimal:2',
        'rewarded_at' => 'datetime',
    ];

    /**
     * Get the referrer (user who referred).
     */
    public function referrer()
    {
        return $this->belongsTo(User::class, 'referrer_id');
    }

    /**
     * Get the referred user.
     */
    public function referred()
    {
        return $this->belongsTo(User::class, 'referred_id');
    }

    /**
     * Get the purchased hosting associated with this referral.
     */
    public function purchasedHosting()
    {
        return $this->belongsTo(PurchasedHosting::class);
    }

    /**
     * Check if the referral is rewarded.
     */
    public function isRewarded(): bool
    {
        return $this->status === 'rewarded' && $this->rewarded_at !== null;
    }

    /**
     * Mark as rewarded.
     */
    public function markAsRewarded(): bool
    {
        $this->status = 'rewarded';
        $this->rewarded_at = now();
        return $this->save();
    }

    /**
     * Mark as pending.
     */
    public function markAsPending(): bool
    {
        $this->status = 'pending';
        $this->rewarded_at = null;
        return $this->save();
    }

    /**
     * Mark as cancelled.
     */
    public function markAsCancelled(): bool
    {
        $this->status = 'cancelled';
        return $this->save();
    }

    /**
     * Apply bonus to the referrer's wallet.
     */
    public function applyBonus(): bool
    {
        if ($this->isRewarded() || !$this->referrer || !$this->referrer->wallet) {
            return false;
        }

        // Add bonus to referrer's wallet
        $this->referrer->wallet->addFunds(
            $this->bonus_amount,
            'referral',
            'Referral bonus for ' . ($this->referred->name ?? 'unknown')
        );

        // Mark as rewarded
        return $this->markAsRewarded();
    }

    /**
     * Calculate bonus amount based on purchase.
     */
    public function calculateBonus(PurchasedHosting $purchase): float
    {
        $bonusAmount = 0;

        // Calculate fixed bonus amount
        if ($this->bonus_amount > 0) {
            $bonusAmount = $this->bonus_amount;
        }

        // Calculate percentage-based bonus
        if ($this->bonus_percent > 0 && $purchase) {
            $percentBonus = ($purchase->price_paid * $this->bonus_percent) / 100;
            $bonusAmount += $percentBonus;
        }

        return round($bonusAmount, 2);
    }

    /**
     * Process a new referral.
     */
    public static function processReferral(User $referrer, User $referred, string $code): self
    {
        // Create new referral record
        $referral = self::create([
            'referrer_id' => $referrer->id,
            'referred_id' => $referred->id,
            'code' => $code,
            'status' => 'pending',
            'bonus_amount' => config('referral.bonus_amount', 50),
            'bonus_percent' => config('referral.bonus_percent', 5),
        ]);

        // Associate referred user with referrer
        $referred->update(['referred_by' => $referrer->id]);

        return $referral;
    }

    /**
     * Scope for rewarded referrals.
     */
    public function scopeRewarded($query)
    {
        return $query->where('status', 'rewarded');
    }

    /**
     * Scope for pending referrals.
     */
    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    /**
     * Scope for cancelled referrals.
     */
    public function scopeCancelled($query)
    {
        return $query->where('status', 'cancelled');
    }

    /**
     * Scope for referrals by a specific referrer.
     */
    public function scopeByReferrer($query, int $referrerId)
    {
        return $query->where('referrer_id', $referrerId);
    }

    /**
     * Get total bonus amount for a referrer.
     */
    public static function getTotalBonusForReferrer(int $referrerId): float
    {
        return self::where('referrer_id', $referrerId)
            ->where('status', 'rewarded')
            ->sum('bonus_amount');
    }
}