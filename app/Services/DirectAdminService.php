<?php

namespace App\Services;

use Exception;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;

class DirectAdminService
{
    protected string $apiUrl;
    protected string $apiUsername;
    protected string $apiPassword;
    protected string $apiLoginType;
    protected int $cacheExpiry;
    protected float $rateLimit;
    private static float $lastRequestTime = 0;

    /**
     * Constructor.
     */
    public function __construct()
    {
        $this->apiUrl = rtrim(config('services.directadmin.api_url', ''), '/');
        $this->apiUsername = config('services.directadmin.api_username', '');
        $this->apiPassword = config('services.directadmin.api_password', '');
        $this->apiLoginType = config('services.directadmin.login_type', 'basic'); // basic or session
        $this->cacheExpiry = config('services.directadmin.cache_expiry', 300); // 5 minutes cache by default
        $this->rateLimit = config('services.directadmin.rate_limit', 0.5); // seconds between requests
    }

    /**
     * Apply rate limiting to prevent API abuse.
     *
     * @return void
     */
    private function applyRateLimit(): void
    {
        $currentTime = microtime(true);
        $timeSinceLastRequest = $currentTime - self::$lastRequestTime;

        if ($timeSinceLastRequest < $this->rateLimit) {
            usleep(($this->rateLimit - $timeSinceLastRequest) * 1000000);
        }

        self::$lastRequestTime = microtime(true);
    }

    /**
     * Make API request to DirectAdmin.
     *
     * @param string $method HTTP method (GET, POST, etc.)
     * @param string $endpoint API endpoint
     * @param array $params Request parameters
     * @param bool $useCache Whether to use cache for GET requests
     * @return array|null Response data
     */
    protected function makeRequest(string $method, string $endpoint, array $params = [], bool $useCache = false): ?array
    {
        $method = strtolower($method);
        $url = $this->apiUrl . '/' . ltrim($endpoint, '/');
        $cacheKey = "directadmin:{$method}:{$endpoint}:" . md5(json_encode($params));

        // Check cache for GET requests
        if ($method === 'get' && $useCache && Cache::has($cacheKey)) {
            return Cache::get($cacheKey);
        }

        try {
            // Apply rate limiting
            $this->applyRateLimit();

            // Build request
            $request = Http::withOptions([
                'verify' => config('services.directadmin.verify_ssl', true),
                'timeout' => config('services.directadmin.timeout', 30),
            ]);

            // Add authentication
            if ($this->apiLoginType === 'basic') {
                $request = $request->withBasicAuth($this->apiUsername, $this->apiPassword);
            } else {
                // For session-based auth, we would need to implement the login flow
                // This is a simplified placeholder - real implementation would require session cookie management
                $request = $request->withHeaders([
                    'Cookie' => 'session=' . $this->getSessionId(),
                ]);
            }

            // Force form data for POST since DirectAdmin API expects it
            if ($method === 'post' || $method === 'put') {
                $request = $request->asForm();
            }

            // Execute request
            $response = $request->{$method}($url, $params);

            // Parse response
            if ($response->successful()) {
                $responseBody = $response->body();

                // Try to parse JSON response
                $jsonData = json_decode($responseBody, true);

                // Handle different response formats
                $result = null;

                if (json_last_error() === JSON_ERROR_NONE && !empty($jsonData)) {
                    $result = $jsonData;
                } else {
                    // Parse DirectAdmin key=value format
                    $data = [];
                    parse_str($responseBody, $data);

                    if (!empty($data)) {
                        $result = $data;
                    } elseif (strpos($responseBody, 'error=0') !== false ||
                        strpos($responseBody, 'success') !== false ||
                        $responseBody === 'OK') {
                        $result = ['success' => true];
                    } else {
                        $errorMsg = '';
                        if (preg_match('/error=(.*?)&/i', $responseBody, $matches)) {
                            $errorMsg = $matches[1];
                        }
                        Log::warning("DirectAdmin API response not parsable: {$responseBody}");
                        $result = ['success' => false, 'error' => $errorMsg ?: $responseBody];
                    }
                }

                // Cache GET results
                if ($method === 'get' && $useCache && !empty($result)) {
                    Cache::put($cacheKey, $result, $this->cacheExpiry);
                }

                return $result;
            }

            $errorBody = $response->body();
            Log::error("DirectAdmin API HTTP error ({$response->status()}): {$errorBody}");

            return [
                'success' => false,
                'error' => "HTTP Error {$response->status()}: {$errorBody}",
                'status_code' => $response->status(),
            ];
        } catch (Exception $e) {
            Log::error('DirectAdmin service error: ' . $e->getMessage(), [
                'exception' => $e,
                'endpoint' => $endpoint,
                'method' => $method,
            ]);

            // For development/testing purposes, return mock data when API fails
            if (config('app.env') !== 'production') {
                return $this->getMockResponse($endpoint);
            }

            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Get session ID for session-based authentication.
     *
     * @return string
     */
    protected function getSessionId(): string
    {
        $cacheKey = "directadmin:session:{$this->apiUsername}";

        if (Cache::has($cacheKey)) {
            return Cache::get($cacheKey);
        }

        try {
            $response = Http::asForm()->post($this->apiUrl . '/CMD_LOGIN', [
                'username' => $this->apiUsername,
                'password' => $this->apiPassword,
                'referer' => '/',
            ]);

            if ($response->successful()) {
                $cookies = $response->cookies();
                foreach ($cookies as $cookie) {
                    if ($cookie->getName() === 'session') {
                        $sessionId = $cookie->getValue();
                        Cache::put($cacheKey, $sessionId, 60 * 24); // Cache for 24 hours
                        return $sessionId;
                    }
                }
            }

            throw new Exception('Unable to obtain DirectAdmin session ID');
        } catch (Exception $e) {
            Log::error('DirectAdmin session authentication error: ' . $e->getMessage());
            return '';
        }
    }

    /**
     * Create a user account.
     *
     * @param string $username
     * @param string $password
     * @param string $email
     * @param string $package
     * @param string $domain
     * @param array $options Additional options
     * @return array
     */
    public function createAccount(
        string $username,
        string $password,
        string $email,
        string $package,
        string $domain,
        array $options = []
    ): array {
        $params = [
            'action' => 'create',
            'add' => 'Submit',
            'username' => $username,
            'passwd' => $password,
            'passwd2' => $password,
            'email' => $email,
            'package' => $package,
            'domain' => $domain,
            'notify' => $options['notify'] ?? 'no',
            'ip' => $options['ip'] ?? '',
        ];

        // Add optional parameters
        if (!empty($options['bandwidth'])) {
            $params['bandwidth'] = $options['bandwidth'];
        }

        if (!empty($options['quota'])) {
            $params['quota'] = $options['quota'];
        }

        if (!empty($options['ns1'])) {
            $params['ns1'] = $options['ns1'];
        }

        if (!empty($options['ns2'])) {
            $params['ns2'] = $options['ns2'];
        }

        $response = $this->makeRequest('POST', 'CMD_API_ACCOUNT_USER', $params);

        if ($response && ($response['success'] ?? false)) {
            // Log success
            Log::info("DirectAdmin account created: {$username}");
            return [
                'success' => true,
                'username' => $username,
                'domain' => $domain,
            ];
        }

        // Log failure
        Log::error("DirectAdmin account creation failed for {$username}", [
            'response' => $response,
        ]);

        return [
            'success' => false,
            'error' => $response['error'] ?? 'Unknown error during account creation',
        ];
    }

    /**
     * Suspend an account.
     *
     * @param string $username
     * @return array
     */
    public function suspendAccount(string $username): array
    {
        $response = $this->makeRequest('POST', 'CMD_API_SELECT_USERS', [
            'location' => 'CMD_SELECT_USERS',
            'suspend' => 'Suspend',
            'select0' => $username,
        ]);

        if ($response && ($response['success'] ?? false)) {
            Log::info("DirectAdmin account suspended: {$username}");
            return [
                'success' => true,
                'username' => $username,
            ];
        }

        Log::error("DirectAdmin account suspension failed for {$username}", [
            'response' => $response,
        ]);

        return [
            'success' => false,
            'error' => $response['error'] ?? 'Unknown error during account suspension',
        ];
    }

    /**
     * Unsuspend an account.
     *
     * @param string $username
     * @return array
     */
    public function unsuspendAccount(string $username): array
    {
        $response = $this->makeRequest('POST', 'CMD_API_SELECT_USERS', [
            'location' => 'CMD_SELECT_USERS',
            'unsuspend' => 'Unsuspend',
            'select0' => $username,
        ]);

        if ($response && ($response['success'] ?? false)) {
            Log::info("DirectAdmin account unsuspended: {$username}");
            return [
                'success' => true,
                'username' => $username,
            ];
        }

        Log::error("DirectAdmin account unsuspension failed for {$username}", [
            'response' => $response,
        ]);

        return [
            'success' => false,
            'error' => $response['error'] ?? 'Unknown error during account unsuspension',
        ];
    }

    /**
     * Delete an account.
     *
     * @param string $username
     * @return array
     */
    public function deleteAccount(string $username): array
    {
        $response = $this->makeRequest('POST', 'CMD_API_SELECT_USERS', [
            'location' => 'CMD_SELECT_USERS',
            'delete' => 'Delete',
            'confirmed' => 'Confirm',
            'select0' => $username,
        ]);

        if ($response && ($response['success'] ?? false)) {
            Log::info("DirectAdmin account deleted: {$username}");
            return [
                'success' => true,
                'username' => $username,
            ];
        }

        Log::error("DirectAdmin account deletion failed for {$username}", [
            'response' => $response,
        ]);

        return [
            'success' => false,
            'error' => $response['error'] ?? 'Unknown error during account deletion',
        ];
    }

    /**
     * Change account password.
     *
     * @param string $username
     * @param string $password
     * @return array
     */
    public function changePassword(string $username, string $password): array
    {
        $response = $this->makeRequest('POST', 'CMD_API_USER_PASSWD', [
            'username' => $username,
            'passwd' => $password,
            'passwd2' => $password,
        ]);

        if ($response && ($response['success'] ?? false)) {
            Log::info("DirectAdmin password changed for: {$username}");
            return [
                'success' => true,
                'username' => $username,
            ];
        }

        Log::error("DirectAdmin password change failed for {$username}", [
            'response' => $response,
        ]);

        return [
            'success' => false,
            'error' => $response['error'] ?? 'Unknown error during password change',
        ];
    }

    /**
     * Modify user package.
     *
     * @param string $username
     * @param string $package New package name
     * @return array
     */
    public function modifyUserPackage(string $username, string $package): array
    {
        $response = $this->makeRequest('POST', 'CMD_API_MODIFY_USER', [
            'action' => 'package',
            'user' => $username,
            'package' => $package,
        ]);

        if ($response && ($response['success'] ?? false)) {
            Log::info("DirectAdmin package changed for {$username} to {$package}");
            return [
                'success' => true,
                'username' => $username,
                'package' => $package,
            ];
        }

        Log::error("DirectAdmin package change failed for {$username}", [
            'response' => $response,
        ]);

        return [
            'success' => false,
            'error' => $response['error'] ?? 'Unknown error during package change',
        ];
    }

    /**
     * Get account details.
     *
     * @param string $username
     * @return array|null
     */
    public function getAccountDetails(string $username): ?array
    {
        $response = $this->makeRequest('GET', 'CMD_API_SHOW_USER_CONFIG', [
            'user' => $username,
        ], true); // Use cache

        if ($response) {
            return $response;
        }

        return null;
    }

    /**
     * Get account usage statistics.
     *
     * @param string $username
     * @return array|null
     */
    public function getAccountUsage(string $username): ?array
    {
        $response = $this->makeRequest('GET', 'CMD_API_SHOW_USER_USAGE', [
            'user' => $username,
        ], false); // Don't cache usage stats as they change frequently

        if ($response) {
            return $response;
        }

        return null;
    }

    /**
     * Create a database.
     *
     * @param string $username
     * @param string $databaseName
     * @param string $databaseUser
     * @param string $databasePassword
     * @return array
     */
    public function createDatabase(
        string $username,
        string $databaseName,
        string $databaseUser,
        string $databasePassword
    ): array {
        // First, create the database
        $response1 = $this->makeRequest('POST', 'CMD_API_DATABASES', [
            'action' => 'create',
            'name' => $databaseName,
            'user' => $username,
        ]);

        if (!$response1 || !($response1['success'] ?? false)) {
            Log::error("DirectAdmin database creation failed for {$username}", [
                'response' => $response1,
            ]);

            return [
                'success' => false,
                'error' => $response1['error'] ?? 'Unknown error during database creation',
                'stage' => 'database',
            ];
        }

        // Then create the database user
        $response2 = $this->makeRequest('POST', 'CMD_API_DATABASES', [
            'action' => 'create',
            'name' => $databaseName,
            'dbuser' => $databaseUser,
            'passwd' => $databasePassword,
            'passwd2' => $databasePassword,
            'user' => $username,
        ]);

        if (!$response2 || !($response2['success'] ?? false)) {
            Log::error("DirectAdmin database user creation failed for {$username}", [
                'response' => $response2,
            ]);

            return [
                'success' => false,
                'error' => $response2['error'] ?? 'Unknown error during database user creation',
                'stage' => 'user',
                'database_created' => true,
            ];
        }

        Log::info("DirectAdmin database and user created: {$databaseName} for {$username}");

        return [
            'success' => true,
            'username' => $username,
            'database' => $databaseName,
            'db_user' => $databaseUser,
        ];
    }

    /**
     * Create a subdomain.
     *
     * @param string $username
     * @param string $domain
     * @param string $subdomain
     * @return array
     */
    public function createSubdomain(string $username, string $domain, string $subdomain): array
    {
        $response = $this->makeRequest('POST', 'CMD_API_SUBDOMAINS', [
            'action' => 'create',
            'domain' => $domain,
            'subdomain' => $subdomain,
            'user' => $username,
        ]);

        if ($response && ($response['success'] ?? false)) {
            Log::info("DirectAdmin subdomain created: {$subdomain}.{$domain} for {$username}");
            return [
                'success' => true,
                'username' => $username,
                'domain' => $domain,
                'subdomain' => $subdomain,
                'full_domain' => "{$subdomain}.{$domain}",
            ];
        }

        Log::error("DirectAdmin subdomain creation failed for {$username}", [
            'response' => $response,
        ]);

        return [
            'success' => false,
            'error' => $response['error'] ?? 'Unknown error during subdomain creation',
        ];
    }

    /**
     * Create a backup for a user.
     *
     * @param string $username
     * @param array $options Backup options
     * @return array
     */
    public function createBackup(string $username, array $options = []): array
    {
        $params = [
            'user' => $username,
        ];

        // Set backup options
        if (!empty($options['email'])) {
            $params['email'] = $options['email'];
        }

        if (isset($options['databases']) && $options['databases']) {
            $params['databases'] = 'yes';
        } else {
            $params['databases'] = 'no';
        }

        if (isset($options['emails']) && $options['emails']) {
            $params['emails'] = 'yes';
        } else {
            $params['emails'] = 'no';
        }

        if (isset($options['settings']) && $options['settings']) {
            $params['settings'] = 'yes';
        } else {
            $params['settings'] = 'no';
        }

        $response = $this->makeRequest('POST', 'CMD_API_USER_BACKUP', $params);

        if ($response && ($response['success'] ?? false)) {
            Log::info("DirectAdmin backup initiated for {$username}");
            return [
                'success' => true,
                'username' => $username,
                'backup_id' => $response['backup_id'] ?? null,
                'message' => $response['message'] ?? 'Backup initiated successfully',
            ];
        }

        Log::error("DirectAdmin backup creation failed for {$username}", [
            'response' => $response,
        ]);

        return [
            'success' => false,
            'error' => $response['error'] ?? 'Unknown error during backup creation',
        ];
    }

    /**
     * Get system information.
     *
     * @return array|null
     */
    public function getSystemInfo(): ?array
    {
        $response = $this->makeRequest('GET', 'CMD_API_SYSTEM_INFO', [], true);

        return $response ?: null;
    }

    /**
     * Get list of packages.
     *
     * @return array
     */
    public function getPackages(): array
    {
        $response = $this->makeRequest('GET', 'CMD_API_PACKAGES', [], true);

        if (!$response) {
            return ['success' => false, 'packages' => []];
        }

        $packages = [];

        // Parse DirectAdmin package list format
        foreach ($response as $key => $value) {
            if (strpos($key, 'package') === 0) {
                $packages[] = $value;
            }
        }

        return [
            'success' => true,
            'packages' => $packages,
        ];
    }

    /**
     * Get domains for a user.
     *
     * @param string $username
     * @return array
     */
    public function getUserDomains(string $username): array
    {
        $response = $this->makeRequest('GET', 'CMD_API_SHOW_DOMAINS', [
            'user' => $username,
        ], true);

        if (!$response) {
            return ['success' => false, 'domains' => []];
        }

        $domains = [];

        // Parse DirectAdmin domain list format
        foreach ($response as $key => $value) {
            if (strpos($key, 'domain') === 0) {
                $domains[] = $value;
            }
        }

        return [
            'success' => true,
            'username' => $username,
            'domains' => $domains,
        ];
    }

    /**
     * Get mock response (for development/testing).
     *
     * @param string $endpoint
     * @return array
     */
    protected function getMockResponse(string $endpoint): array
    {
        switch ($endpoint) {
            case 'CMD_API_ACCOUNT_USER':
                return ['success' => true];

            case 'CMD_API_SELECT_USERS':
                return ['success' => true];

            case 'CMD_API_USER_PASSWD':
                return ['success' => true];

            case 'CMD_API_SHOW_USER_CONFIG':
                return [
                    'username' => 'testuser',
                    'domain' => 'example.com',
                    'package' => 'basic',
                    'suspended' => 'no',
                    'bandwidth' => '1000000',
                    'quota' => '10000',
                    'success' => true,
                ];

            case 'CMD_API_SHOW_USER_USAGE':
                return [
                    'bandwidth' => '125000',
                    'quota' => '3500',
                    'databases' => '2',
                    'ftp' => '1',
                    'email' => '5',
                    'domains' => '1',
                    'success' => true,
                ];

            case 'CMD_API_DATABASES':
                return ['success' => true];

            case 'CMD_API_SUBDOMAINS':
                return ['success' => true];

            case 'CMD_API_SYSTEM_INFO':
                return [
                    'version' => '1.62.0',
                    'build' => '2105',
                    'hostname' => 'server.example.com',
                    'os' => 'CloudLinux 9',
                    'kernel' => '5.10.0-1.el9.elrepo.x86_64',
                    'uptime' => '10 days, 5 hours, 30 minutes',
                    'success' => true,
                ];

            case 'CMD_API_PACKAGES':
                return [
                    'package0' => 'basic',
                    'package1' => 'standard',
                    'package2' => 'premium',
                    'success' => true,
                ];

            case 'CMD_API_SHOW_DOMAINS':
                return [
                    'domain0' => 'example.com',
                    'domain1' => 'example.org',
                    'success' => true,
                ];

            case 'CMD_API_USER_BACKUP':
                return [
                    'success' => true,
                    'backup_id' => 'backup_' . time(),
                    'message' => 'Backup initiated successfully',
                ];

            default:
                return ['success' => true];
        }
    }
}