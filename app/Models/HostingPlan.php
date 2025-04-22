<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class HostingPlan extends Model
{
    use HasFactory, SoftDeletes;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
        'description',
        'ram',
        'cpu',
        'storage',
        'bandwidth',
        'price_monthly',
        'price_yearly',
        'setup_fee',
        'is_active',
        'sort_order',
        'features',
        'max_ram',
        'max_cpu',
        'whmcs_product_id',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'ram' => 'integer',
        'cpu' => 'integer',
        'storage' => 'integer',
        'bandwidth' => 'integer',
        'price_monthly' => 'decimal:2',
        'price_yearly' => 'decimal:2',
        'setup_fee' => 'decimal:2',
        'is_active' => 'boolean',
        'sort_order' => 'integer',
        'features' => 'array',
        'max_ram' => 'integer',
        'max_cpu' => 'integer',
    ];

    /**
     * Get all purchased hostings for this plan.
     */
    public function purchasedHostings()
    {
        return $this->hasMany(PurchasedHosting::class);
    }

    /**
     * Get active plans.
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Get plans ordered by sort_order.
     */
    public function scopeOrdered($query)
    {
        return $query->orderBy('sort_order');
    }

    /**
     * Calculate the yearly discount percentage.
     */
    public function getYearlyDiscountPercentAttribute()
    {
        $monthlyTotal = $this->price_monthly * 12;
        $yearlyTotal = $this->price_yearly;
        
        if ($monthlyTotal <= 0) {
            return 0;
        }
        
        $discount = (($monthlyTotal - $yearlyTotal) / $monthlyTotal) * 100;
        return round($discount, 2);
    }

    /**
     * Check if the plan can be autoscaled.
     */
    public function canAutoscale(): bool
    {
        return $this->max_ram > $this->ram || $this->max_cpu > $this->cpu;
    }

    /**
     * Get WHMCS product details.
     */
    public function getWhmcsDetails()
    {
        if (!$this->whmcs_product_id) {
            return null;
        }

        // Integration with WHMCS service
        return app(WhmcsService::class)->getProductDetails($this->whmcs_product_id);
    }
}