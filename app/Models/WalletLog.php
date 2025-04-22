<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class WalletLog extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'wallet_id',
        'amount',
        'type',
        'source',
        'reference',
        'balance_after',
        'description',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'amount' => 'decimal:2',
        'balance_after' => 'decimal:2',
    ];

    /**
     * Get the wallet that owns the log.
     */
    public function wallet()
    {
        return $this->belongsTo(Wallet::class);
    }

    /**
     * Scope for deposit transactions.
     */
    public function scopeDeposits($query)
    {
        return $query->where('type', 'deposit');
    }

    /**
     * Scope for withdrawal transactions.
     */
    public function scopeWithdrawals($query)
    {
        return $query->where('type', 'withdrawal');
    }

    /**
     * Scope for transactions by source.
     */
    public function scopeBySource($query, string $source)
    {
        return $query->where('source', $source);
    }

    /**
     * Scope for transactions with positive amounts.
     */
    public function scopePositive($query)
    {
        return $query->where('amount', '>', 0);
    }

    /**
     * Scope for transactions with negative amounts.
     */
    public function scopeNegative($query)
    {
        return $query->where('amount', '<', 0);
    }

    /**
     * Get the user that owns the wallet log.
     */
    public function user()
    {
        return $this->wallet->user();
    }

    /**
     * Format the amount with currency symbol.
     */
    public function getFormattedAmountAttribute()
    {
        $currency = $this->wallet->currency ?? 'PLN';
        $symbol = $this->getCurrencySymbol($currency);

        return sprintf('%s %s', number_format($this->amount, 2), $symbol);
    }

    /**
     * Get the currency symbol.
     */
    protected function getCurrencySymbol(string $currency): string
    {
        $symbols = [
            'PLN' => 'zł',
            'USD' => '$',
            'EUR' => '€',
            'GBP' => '£',
        ];

        return $symbols[$currency] ?? $currency;
    }
}