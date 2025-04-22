<?php

namespace App\Services;

use Exception;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class PaymentGatewayService
{
    protected string $gatewayType;

    /**
     * Constructor.
     */
    public function __construct()
    {
        $this->gatewayType = config('services.payment.gateway_type', 'stripe');
    }

    /**
     * Create a payment session.
     *
     * @param float $amount
     * @param string $description
     * @param array $metadata
     * @param string $returnUrl
     * @return array|null
     */
    public function createPaymentSession(
        float $amount,
        string $description,
        array $metadata = [],
        string $returnUrl = ''
    ): ?array {
        try {
            switch ($this->gatewayType) {
                case 'stripe':
                    return $this->createStripePaymentSession($amount, $description, $metadata, $returnUrl);
                case 'paynow':
                    return $this->createPaynowPaymentSession($amount, $description, $metadata, $returnUrl);
                case 'p24':
                    return $this->createP24PaymentSession($amount, $description, $metadata, $returnUrl);
                default:
                    Log::error('Unsupported payment gateway type: ' . $this->gatewayType);
                    return null;
            }
        } catch (Exception $e) {
            Log::error('Payment gateway error: ' . $e->getMessage());

            // For development/testing purposes, return mock data when API fails
            if (config('app.env') !== 'production') {
                return $this->getMockPaymentSession();
            }

            return null;
        }
    }

    /**
     * Verify a payment.
     *
     * @param string $paymentId
     * @return bool
     */
    public function verifyPayment(string $paymentId): bool
    {
        try {
            switch ($this->gatewayType) {
                case 'stripe':
                    return $this->verifyStripePayment($paymentId);
                case 'paynow':
                    return $this->verifyPaynowPayment($paymentId);
                case 'p24':
                    return $this->verifyP24Payment($paymentId);
                default:
                    Log::error('Unsupported payment gateway type: ' . $this->gatewayType);
                    return false;
            }
        } catch (Exception $e) {
            Log::error('Payment gateway verification error: ' . $e->getMessage());

            // For development/testing purposes, return true when API fails
            if (config('app.env') !== 'production') {
                return true;
            }

            return false;
        }
    }

    /**
     * Create a Stripe payment session.
     *
     * @param float $amount
     * @param string $description
     * @param array $metadata
     * @param string $returnUrl
     * @return array|null
     */
    protected function createStripePaymentSession(
        float $amount,
        string $description,
        array $metadata = [],
        string $returnUrl = ''
    ): ?array {
        $stripeKey = config('services.stripe.secret');

        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . $stripeKey,
            'Content-Type' => 'application/x-www-form-urlencoded',
        ])->post('https://api.stripe.com/v1/checkout/sessions', [
            'payment_method_types' => ['card'],
            'line_items' => [[
                'price_data' => [
                    'currency' => 'pln',
                    'product_data' => [
                        'name' => $description,
                    ],
                    'unit_amount' => (int) ($amount * 100), // Convert to cents
                ],
                'quantity' => 1,
            ]],
            'mode' => 'payment',
            'success_url' => $returnUrl . '?session_id={CHECKOUT_SESSION_ID}',
            'cancel_url' => $returnUrl . '?cancel=true',
            'metadata' => $metadata,
        ]);

        if ($response->successful()) {
            $data = $response->json();

            return [
                'payment_id' => $data['id'],
                'payment_url' => $data['url'],
                'gateway' => 'stripe',
            ];
        }

        Log::error('Stripe API error: ' . $response->body());
        return null;
    }

    /**
     * Create a Paynow payment session.
     *
     * @param float $amount
     * @param string $description
     * @param array $metadata
     * @param string $returnUrl
     * @return array|null
     */
    protected function createPaynowPaymentSession(
        float $amount,
        string $description,
        array $metadata = [],
        string $returnUrl = ''
    ): ?array {
        $apiKey = config('services.paynow.api_key');
        $apiSignature = config('services.paynow.api_signature');
        $externalId = (string) Str::uuid();

        $data = [
            'amount' => (int) ($amount * 100), // Convert to grosze
            'currency' => 'PLN',
            'externalId' => $externalId,
            'description' => $description,
            'buyer' => [
                'email' => $metadata['email'] ?? null,
            ],
            'continueUrl' => $returnUrl,
        ];

        $response = Http::withHeaders([
            'Content-Type' => 'application/json',
            'Api-Key' => $apiKey,
            'Signature' => hash('sha256', $apiKey . $externalId . $data['amount'] . $data['currency'] . $apiSignature),
        ])->post('https://api.paynow.pl/v1/payments', $data);

        if ($response->successful()) {
            $responseData = $response->json();

            return [
                'payment_id' => $responseData['paymentId'],
                'payment_url' => $responseData['redirectUrl'],
                'gateway' => 'paynow',
            ];
        }

        Log::error('Paynow API error: ' . $response->body());
        return null;
    }

    /**
     * Create a P24 payment session.
     *
     * @param float $amount
     * @param string $description
     * @param array $metadata
     * @param string $returnUrl
     * @return array|null
     */
    protected function createP24PaymentSession(
        float $amount,
        string $description,
        array $metadata = [],
        string $returnUrl = ''
    ): ?array {
        $merchantId = config('services.p24.merchant_id');
        $posId = config('services.p24.pos_id');
        $crc = config('services.p24.crc');
        $sessionId = (string) Str::uuid();

        $data = [
            'merchantId' => $merchantId,
            'posId' => $posId,
            'sessionId' => $sessionId,
            'amount' => (int) ($amount * 100), // Convert to grosze
            'currency' => 'PLN',
            'description' => $description,
            'email' => $metadata['email'] ?? 'customer@example.com',
            'country' => 'PL',
            'language' => 'pl',
            'urlReturn' => $returnUrl,
            'urlStatus' => route('api.payments.p24.notify'),
        ];

        $data['sign'] = hash('sha384', json_encode($data) . $crc);

        $response = Http::withHeaders([
            'Content-Type' => 'application/json',
        ])->post('https://secure.przelewy24.pl/api/v1/transaction/register', $data);

        if ($response->successful()) {
            $responseData = $response->json();

            return [
                'payment_id' => $sessionId,
                'payment_url' => 'https://secure.przelewy24.pl/trnRequest/' . $responseData['token'],
                'gateway' => 'p24',
                'token' => $responseData['token'],
            ];
        }

        Log::error('P24 API error: ' . $response->body());
        return null;
    }

    /**
     * Verify a Stripe payment.
     *
     * @param string $paymentId
     * @return bool
     */
    protected function verifyStripePayment(string $paymentId): bool
    {
        $stripeKey = config('services.stripe.secret');

        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . $stripeKey,
            'Content-Type' => 'application/x-www-form-urlencoded',
        ])->get('https://api.stripe.com/v1/checkout/sessions/' . $paymentId);

        if ($response->successful()) {
            $data = $response->json();
            return $data['payment_status'] === 'paid';
        }

        return false;
    }

    /**
     * Verify a Paynow payment.
     *
     * @param string $paymentId
     * @return bool
     */
    protected function verifyPaynowPayment(string $paymentId): bool
    {
        $apiKey = config('services.paynow.api_key');
        $apiSignature = config('services.paynow.api_signature');

        $response = Http::withHeaders([
            'Content-Type' => 'application/json',
            'Api-Key' => $apiKey,
            'Signature' => hash('sha256', $apiKey . $paymentId . $apiSignature),
        ])->get('https://api.paynow.pl/v1/payments/' . $paymentId);

        if ($response->successful()) {
            $data = $response->json();
            return $data['status'] === 'CONFIRMED';
        }

        return false;
    }

    /**
     * Verify a P24 payment.
     *
     * @param string $paymentId
     * @return bool
     */
    protected function verifyP24Payment(string $paymentId): bool
    {
        // P24 sends notification to the callback URL
        // This is just a fallback
        $merchantId = config('services.p24.merchant_id');
        $posId = config('services.p24.pos_id');
        $crc = config('services.p24.crc');

        $data = [
            'merchantId' => $merchantId,
            'posId' => $posId,
            'sessionId' => $paymentId,
        ];

        $data['sign'] = hash('sha384', json_encode($data) . $crc);

        $response = Http::withHeaders([
            'Content-Type' => 'application/json',
        ])->get('https://secure.przelewy24.pl/api/v1/transaction/verify', $data);

        if ($response->successful()) {
            $responseData = $response->json();
            return $responseData['status'] === 'success';
        }

        return false;
    }

    /**
     * Get mock payment session (for development/testing).
     *
     * @return array
     */
    protected function getMockPaymentSession(): array
    {
        $paymentId = (string) Str::uuid();

        return [
            'payment_id' => $paymentId,
            'payment_url' => 'https://example.com/mock-payment/' . $paymentId,
            'gateway' => $this->gatewayType . '-mock',
        ];
    }
}