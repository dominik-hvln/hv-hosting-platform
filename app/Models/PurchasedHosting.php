<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class PurchasedHosting extends Model
{
    use HasFactory, SoftDeletes;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'user_id',
        'hosting_plan_id',
        'start_date',
        'end_date',
        'status',
        'price_paid',
        'payment_method',
        'payment_reference',
        'whmcs_service_id',
        'renewal_date',
        'is_auto_renew',
        'is_autoscaling_enabled',
        'promo_code_id',
        'discount_amount',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'start_date' => 'datetime',
        'end_date' => 'datetime',
        'renewal_date' => 'datetime',
        'price_paid' => 'decimal:2',
        'discount_amount' => 'decimal:2',
        'is_auto_renew' => 'boolean',
        'is_autoscaling_enabled' => 'boolean',
    ];

    /**
     * Get the user that owns the purchased hosting.
     */
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the hosting plan associated with this purchase.
     */
    public function hostingPlan()
    {
        return $this->belongsTo(HostingPlan::class);
    }

    /**
     * Get the promo code associated with this purchase.
     */
    public function promoCode()
    {
        return $this->belongsTo(PromoCode::class);
    }

    /**
     * Get the hosting account associated with this purchase.
     */
    public function hostingAccount()
    {
        return $this->hasOne(HostingAccount::class);
    }

    /**
     * Get all scaling logs for this purchase.
     */
    public function scalingLogs()
    {
        return $this->hasMany(ScalingLog::class);
    }

    /**
     * Check if the purchase is active.
     */
    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    /**
     * Check if the purchase is expired.
     */
    public function isExpired(): bool
    {
        return $this->status === 'expired';
    }

    /**
     * Check if the purchase is suspended.
     */
    public function isSuspended(): bool
    {
        return $this->status === 'suspended';
    }

    /**
     * Check if the purchase is about to expire.
     */
    public function isAboutToExpire(int $daysThreshold = 7): bool
    {
        if (!$this->end_date) {
            return false;
        }

        return $this->isActive() && now()->diffInDays($this->end_date) <= $daysThreshold;
    }

    /**
     * Mark as renewed.
     */
    public function renew(int $periodMonths = 1): bool
    {
        $this->start_date = now();
        $this->end_date = now()->addMonths($periodMonths);
        $this->status = 'active';
        $this->renewal_date = $this->end_date;

        return $this->save();
    }

    /**
     * Mark as expired.
     */
    public function markAsExpired(): bool
    {
        $this->status = 'expired';
        return $this->save();
    }

    /**
     * Mark as suspended.
     */
    public function markAsSuspended(): bool
    {
        $this->status = 'suspended';
        return $this->save();
    }

    /**
     * Mark as active.
     */
    public function markAsActive(): bool
    {
        $this->status = 'active';
        return $this->save();
    }

    /**
     * Scope for active purchases.
     */
    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    /**
     * Scope for expired purchases.
     */
    public function scopeExpired($query)
    {
        return $query->where('status', 'expired');
    }

    /**
     * Scope for suspended purchases.
     */
    public function scopeSuspended($query)
    {
        return $query->where('status', 'suspended');
    }

    /**
     * Scope for purchases about to expire.
     */
    public function scopeAboutToExpire($query, int $daysThreshold = 7)
    {
        return $query->where('status', 'active')
            ->where('end_date', '<=', now()->addDays($daysThreshold))
            ->where('end_date', '>', now());
    }

    /**
     * Scope for auto-renewable purchases.
     */
    public function scopeAutoRenewable($query)
    {
        return $query->where('is_auto_renew', true);
    }

    /**
     * Scope for purchases with autoscaling enabled.
     */
    public function scopeAutoscalingEnabled($query)
    {
        return $query->where('is_autoscaling_enabled', true);
    }
}