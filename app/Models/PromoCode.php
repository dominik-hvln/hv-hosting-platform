<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class PromoCode extends Model
{
    use HasFactory, SoftDeletes;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'code',
        'type',
        'value',
        'max_uses',
        'times_used',
        'valid_from',
        'valid_to',
        'is_active',
        'description',
        'is_one_time',
        'applies_to',
        'min_purchase_amount',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'value' => 'decimal:2',
        'max_uses' => 'integer',
        'times_used' => 'integer',
        'valid_from' => 'datetime',
        'valid_to' => 'datetime',
        'is_active' => 'boolean',
        'is_one_time' => 'boolean',
        'applies_to' => 'array',
        'min_purchase_amount' => 'decimal:2',
    ];

    /**
     * Get the purchases that used this promo code.
     */
    public function purchasedHostings()
    {
        return $this->hasMany(PurchasedHosting::class);
    }

    /**
     * Check if the promo code is valid.
     */
    public function isValid(): bool
    {
        // Check if active
        if (!$this->is_active) {
            return false;
        }

        // Check usage count
        if ($this->max_uses > 0 && $this->times_used >= $this->max_uses) {
            return false;
        }

        // Check date range
        $now = now();
        if ($this->valid_from && $now < $this->valid_from) {
            return false;
        }

        if ($this->valid_to && $now > $this->valid_to) {
            return false;
        }

        return true;
    }

    /**
     * Increment the times used counter.
     */
    public function incrementUsage(): bool
    {
        $this->times_used += 1;
        return $this->save();
    }

    /**
     * Check if the promo code applies to a specific plan.
     */
    public function appliesToPlan(HostingPlan $plan): bool
    {
        if (empty($this->applies_to)) {
            return true;
        }

        return in_array($plan->id, $this->applies_to);
    }

    /**
     * Calculate discount for a given amount.
     */
    public function calculateDiscount(float $amount): float
    {
        if ($amount < $this->min_purchase_amount) {
            return 0;
        }

        if ($this->type === 'percentage') {
            return round(($this->value / 100) * $amount, 2);
        }

        if ($this->type === 'amount') {
            return min($this->value, $amount);
        }

        return 0;
    }

    /**
     * Generate a random promo code.
     */
    public static function generateCode(int $length = 8): string
    {
        $chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
        $code = '';

        for ($i = 0; $i < $length; $i++) {
            $code .= $chars[rand(0, strlen($chars) - 1)];
        }

        return $code;
    }

    /**
     * Scope for active promo codes.
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope for valid promo codes.
     */
    public function scopeValid($query)
    {
        $now = now();

        return $query->where('is_active', true)
            ->where(function ($q) {
                $q->whereNull('max_uses')
                    ->orWhereRaw('times_used < max_uses');
            })
            ->where(function ($q) use ($now) {
                $q->whereNull('valid_from')
                    ->orWhere('valid_from', '<=', $now);
            })
            ->where(function ($q) use ($now) {
                $q->whereNull('valid_to')
                    ->orWhere('valid_to', '>=', $now);
            });
    }

    /**
     * Scope for percentage type promo codes.
     */
    public function scopePercentage($query)
    {
        return $query->where('type', 'percentage');
    }

    /**
     * Scope for amount type promo codes.
     */
    public function scopeAmount($query)
    {
        return $query->where('type', 'amount');
    }
}