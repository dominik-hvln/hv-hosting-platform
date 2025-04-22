<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ScalingLog extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'hosting_account_id',
        'purchased_hosting_id',
        'previous_ram',
        'previous_cpu',
        'new_ram',
        'new_cpu',
        'scaled_ram',
        'scaled_cpu',
        'reason',
        'cost',
        'payment_reference',
        'payment_status',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'previous_ram' => 'integer',
        'previous_cpu' => 'integer',
        'new_ram' => 'integer',
        'new_cpu' => 'integer',
        'scaled_ram' => 'integer',
        'scaled_cpu' => 'integer',
        'cost' => 'decimal:2',
    ];

    /**
     * Get the hosting account associated with this scaling log.
     */
    public function hostingAccount()
    {
        return $this->belongsTo(HostingAccount::class);
    }

    /**
     * Get the purchased hosting associated with this scaling log.
     */
    public function purchasedHosting()
    {
        return $this->belongsTo(PurchasedHosting::class);
    }

    /**
     * Get the user associated with this scaling log.
     */
    public function user()
    {
        return $this->hostingAccount->user();
    }

    /**
     * Check if payment was successful.
     */
    public function isPaymentSuccessful(): bool
    {
        return $this->payment_status === 'paid';
    }

    /**
     * Calculate the cost of scaling.
     */
    public static function calculateCost(int $ramMB, int $cpuPercent): float
    {
        // Example calculation: 1 PLN per 100MB RAM and 1 PLN per 50% CPU
        $ramCost = $ramMB * 0.01;
        $cpuCost = $cpuPercent * 0.02;

        return round($ramCost + $cpuCost, 2);
    }

    /**
     * Mark as paid.
     */
    public function markAsPaid(string $reference = null): bool
    {
        $this->payment_status = 'paid';

        if ($reference) {
            $this->payment_reference = $reference;
        }

        return $this->save();
    }

    /**
     * Mark as pending.
     */
    public function markAsPending(): bool
    {
        $this->payment_status = 'pending';
        return $this->save();
    }

    /**
     * Mark as failed.
     */
    public function markAsFailed(): bool
    {
        $this->payment_status = 'failed';
        return $this->save();
    }

    /**
     * Scope for paid logs.
     */
    public function scopePaid($query)
    {
        return $query->where('payment_status', 'paid');
    }

    /**
     * Scope for pending logs.
     */
    public function scopePending($query)
    {
        return $query->where('payment_status', 'pending');
    }

    /**
     * Scope for failed logs.
     */
    public function scopeFailed($query)
    {
        return $query->where('payment_status', 'failed');
    }

    /**
     * Scope for logs by reason.
     */
    public function scopeByReason($query, string $reason)
    {
        return $query->where('reason', $reason);
    }

    /**
     * Get the RAM scaling difference in percent.
     */
    public function getRamScalingPercentAttribute()
    {
        if ($this->previous_ram <= 0) {
            return 0;
        }

        return round(($this->scaled_ram / $this->previous_ram) * 100, 2);
    }

    /**
     * Get the CPU scaling difference in percent.
     */
    public function getCpuScalingPercentAttribute()
    {
        if ($this->previous_cpu <= 0) {
            return 0;
        }

        return round(($this->scaled_cpu / $this->previous_cpu) * 100, 2);
    }
}