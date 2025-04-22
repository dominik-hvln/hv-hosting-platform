<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Exceptions\PaymentException;

class Wallet extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'user_id',
        'balance',
        'currency',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'balance' => 'decimal:2',
    ];

    /**
     * Get the user that owns the wallet.
     */
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the wallet logs for this wallet.
     */
    public function logs()
    {
        return $this->hasMany(WalletLog::class);
    }

    /**
     * Add funds to wallet.
     *
     * @param float $amount
     * @param string $source
     * @param string|null $reference
     * @return WalletLog
     */
    public function addFunds(float $amount, string $source, ?string $reference = null): WalletLog
    {
        if ($amount <= 0) {
            throw new PaymentException('Kwota doładowania musi być większa od zera.');
        }

        $this->balance += $amount;
        $this->save();

        return $this->logs()->create([
            'amount' => $amount,
            'type' => 'deposit',
            'source' => $source,
            'reference' => $reference,
            'balance_after' => $this->balance,
        ]);
    }

    /**
     * Withdraw funds from wallet.
     *
     * @param float $amount
     * @param string $reason
     * @param string|null $reference
     * @return WalletLog
     * @throws PaymentException
     */
    public function withdrawFunds(float $amount, string $reason, ?string $reference = null): WalletLog
    {
        if ($amount <= 0) {
            throw new PaymentException('Kwota wypłaty musi być większa od zera.');
        }

        if ($this->balance < $amount) {
            throw new PaymentException('Niewystarczające środki na koncie.');
        }

        $this->balance -= $amount;
        $this->save();

        return $this->logs()->create([
            'amount' => -$amount,
            'type' => 'withdrawal',
            'source' => $reason,
            'reference' => $reference,
            'balance_after' => $this->balance,
        ]);
    }

    /**
     * Check if wallet has sufficient funds.
     *
     * @param float $amount
     * @return bool
     */
    public function hasSufficientFunds(float $amount): bool
    {
        return $this->balance >= $amount;
    }

    /**
     * Apply a promo code to the wallet.
     *
     * @param PromoCode $promoCode
     * @return WalletLog|null
     */
    public function applyPromoCode(PromoCode $promoCode): ?WalletLog
    {
        if ($promoCode->type === 'amount' && $promoCode->value > 0) {
            return $this->addFunds(
                $promoCode->value,
                'promo_code',
                $promoCode->code
            );
        }

        return null;
    }
}