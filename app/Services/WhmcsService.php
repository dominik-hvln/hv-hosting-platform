<?php

namespace App\Services;

use Exception;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class WhmcsService
{
    protected string $apiUrl;
    protected string $apiIdentifier;
    protected string $apiSecret;

    /**
     * Constructor.
     */
    public function __construct()
    {
        $this->apiUrl = config('services.whmcs.api_url');
        $this->apiIdentifier = config('services.whmcs.api_identifier');
        $this->apiSecret = config('services.whmcs.api_secret');
    }

    /**
     * Make API request to WHMCS.
     *
     * @param string $action
     * @param array $params
     * @return array|null
     */
    protected function makeRequest(string $action, array $params = []): ?array
    {
        try {
            $postParams = array_merge([
                'identifier' => $this->apiIdentifier,
                'secret' => $this->apiSecret,
                'action' => $action,
                'responsetype' => 'json',
            ], $params);

            $response = Http::asForm()->post($this->apiUrl, $postParams);

            if ($response->successful()) {
                $data = $response->json();

                if (isset($data['result']) && $data['result'] === 'success') {
                    return $data;
                }

                Log::error('WHMCS API error: ' . json_encode($data));
                return null;
            }

            Log::error('WHMCS API HTTP error: ' . $response->body());
            return null;
        } catch (Exception $e) {
            Log::error('WHMCS service error: ' . $e->getMessage());

            // For development/testing purposes, return mock data when API fails
            if (config('app.env') !== 'production') {
                return $this->getMockResponse($action, $params);
            }

            return null;
        }
    }

    /**
     * Get client details.
     *
     * @param string $email
     * @return array|null
     */
    public function getClientByEmail(string $email): ?array
    {
        $response = $this->makeRequest('GetClients', [
            'search' => $email,
        ]);

        if ($response && isset($response['clients']['client'][0])) {
            return $response['clients']['client'][0];
        }

        return null;
    }

    /**
     * Get client details by ID.
     *
     * @param int $clientId
     * @return array|null
     */
    public function getClient(int $clientId): ?array
    {
        $response = $this->makeRequest('GetClientsDetails', [
            'clientid' => $clientId,
        ]);

        if ($response && isset($response['client'])) {
            return $response['client'];
        }

        return null;
    }

    /**
     * Create a client.
     *
     * @param array $userData
     * @return int|null Client ID
     */
    public function createClient(array $userData): ?int
    {
        $response = $this->makeRequest('AddClient', [
            'firstname' => $userData['name'] ?? '',
            'lastname' => $userData['name'] ?? '',
            'email' => $userData['email'],
            'address1' => $userData['address'] ?? '',
            'city' => $userData['city'] ?? '',
            'state' => $userData['state'] ?? '',
            'postcode' => $userData['postal_code'] ?? '',
            'country' => $userData['country'] ?? 'PL',
            'phonenumber' => $userData['phone'] ?? '',
            'companyname' => $userData['company_name'] ?? '',
            'tax_id' => $userData['tax_id'] ?? '',
        ]);

        if ($response && isset($response['clientid'])) {
            return (int) $response['clientid'];
        }

        return null;
    }

    /**
     * Get product details.
     *
     * @param int $productId
     * @return array|null
     */
    public function getProductDetails(int $productId): ?array
    {
        $response = $this->makeRequest('GetProducts', [
            'pid' => $productId,
        ]);

        if ($response && isset($response['products']['product'][0])) {
            return $response['products']['product'][0];
        }

        return null;
    }

    /**
     * Get all products.
     *
     * @return array
     */
    public function getProducts(): array
    {
        $response = $this->makeRequest('GetProducts');

        if ($response && isset($response['products']['product'])) {
            return $response['products']['product'];
        }

        return [];
    }

    /**
     * Create an order.
     *
     * @param int $clientId
     * @param int $productId
     * @param string $domain
     * @param string $billingCycle
     * @param array $customFields
     * @return int|null Order ID
     */
    public function createOrder(
        int $clientId,
        int $productId,
        string $domain,
        string $billingCycle = 'monthly',
        array $customFields = []
    ): ?int {
        $response = $this->makeRequest('AddOrder', [
            'clientid' => $clientId,
            'pid' => [$productId],
            'domain' => [$domain],
            'billingcycle' => [$billingCycle],
            'customfields' => [$customFields],
            'paymentmethod' => 'banktransfer',
        ]);

        if ($response && isset($response['orderid'])) {
            return (int) $response['orderid'];
        }

        return null;
    }

    /**
     * Accept an order.
     *
     * @param int $orderId
     * @return bool
     */
    public function acceptOrder(int $orderId): bool
    {
        $response = $this->makeRequest('AcceptOrder', [
            'orderid' => $orderId,
        ]);

        return $response && isset($response['result']) && $response['result'] === 'success';
    }

    /**
     * Get service details.
     *
     * @param int $serviceId
     * @return array|null
     */
    public function getService(int $serviceId): ?array
    {
        $response = $this->makeRequest('GetClientsProducts', [
            'serviceid' => $serviceId,
        ]);

        if ($response && isset($response['products']['product'][0])) {
            return $response['products']['product'][0];
        }

        return null;
    }

    /**
     * Update service custom fields.
     *
     * @param int $serviceId
     * @param array $customFields
     * @return bool
     */
    public function updateServiceCustomFields(int $serviceId, array $customFields): bool
    {
        $response = $this->makeRequest('UpdateClientProduct', [
            'serviceid' => $serviceId,
            'customfields' => $customFields,
        ]);

        return $response && isset($response['result']) && $response['result'] === 'success';
    }

    /**
     * Add a charge to client.
     *
     * @param int $clientId
     * @param float $amount
     * @param string $description
     * @return int|null Invoice ID
     */
    public function addCharge(int $clientId, float $amount, string $description): ?int
    {
        $response = $this->makeRequest('CreateInvoice', [
            'userid' => $clientId,
            'itemdescription' => [$description],
            'itemamount' => [$amount],
            'itemtaxed' => [1],
            'autoapplycredit' => true,
        ]);

        if ($response && isset($response['invoiceid'])) {
            return (int) $response['invoiceid'];
        }

        return null;
    }

    /**
     * Sync a service with WHMCS.
     *
     * @param int $serviceId
     * @param array $data
     * @return bool
     */
    public function syncService(int $serviceId, array $data): bool
    {
        $customFields = [];

        if (isset($data['cpu'])) {
            $customFields[] = [
                'name' => 'CPU',
                'value' => $data['cpu'],
            ];
        }

        if (isset($data['ram'])) {
            $customFields[] = [
                'name' => 'RAM',
                'value' => $data['ram'],
            ];
        }

        if (isset($data['storage'])) {
            $customFields[] = [
                'name' => 'Storage',
                'value' => $data['storage'],
            ];
        }

        if (isset($data['bandwidth'])) {
            $customFields[] = [
                'name' => 'Bandwidth',
                'value' => $data['bandwidth'],
            ];
        }

        if (isset($data['domain'])) {
            $response = $this->makeRequest('UpdateClientProduct', [
                'serviceid' => $serviceId,
                'domain' => $data['domain'],
            ]);

            if (!$response || !isset($response['result']) || $response['result'] !== 'success') {
                return false;
            }
        }

        if (!empty($customFields)) {
            return $this->updateServiceCustomFields($serviceId, $customFields);
        }

        return true;
    }

    /**
     * Get mock response (for development/testing).
     *
     * @param string $action
     * @param array $params
     * @return array
     */
    protected function getMockResponse(string $action, array $params = []): array
    {
        switch ($action) {
            case 'GetClients':
                return [
                    'result' => 'success',
                    'clients' => [
                        'client' => [
                            [
                                'id' => 123,
                                'firstname' => 'Jan',
                                'lastname' => 'Kowalski',
                                'email' => $params['search'] ?? 'test@example.com',
                            ],
                        ],
                    ],
                ];

            case 'GetClientsDetails':
                return [
                    'result' => 'success',
                    'client' => [
                        'id' => $params['clientid'] ?? 123,
                        'firstname' => 'Jan',
                        'lastname' => 'Kowalski',
                        'email' => 'test@example.com',
                    ],
                ];

            case 'AddClient':
                return [
                    'result' => 'success',
                    'clientid' => rand(1000, 9999),
                ];

            case 'GetProducts':
                return [
                    'result' => 'success',
                    'products' => [
                        'product' => [
                            [
                                'pid' => $params['pid'] ?? 1,
                                'name' => 'Basic Hosting',
                                'description' => 'Basic hosting package',
                                'pricing' => [
                                    'monthly' => 10.00,
                                    'yearly' => 100.00,
                                ],
                            ],
                        ],
                    ],
                ];

            case 'AddOrder':
                return [
                    'result' => 'success',
                    'orderid' => rand(1000, 9999),
                ];

            case 'AcceptOrder':
                return [
                    'result' => 'success',
                ];

            case 'GetClientsProducts':
                return [
                    'result' => 'success',
                    'products' => [
                        'product' => [
                            [
                                'id' => $params['serviceid'] ?? 1,
                                'domain' => 'example.com',
                                'status' => 'Active',
                                'regdate' => date('Y-m-d'),
                                'nextduedate' => date('Y-m-d', strtotime('+1 month')),
                            ],
                        ],
                    ],
                ];

            case 'UpdateClientProduct':
                return [
                    'result' => 'success',
                ];

            case 'CreateInvoice':
                return [
                    'result' => 'success',
                    'invoiceid' => rand(1000, 9999),
                ];

            default:
                return [
                    'result' => 'success',
                ];
        }
    }
}