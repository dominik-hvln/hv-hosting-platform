<?php

namespace App\Services;

use Exception;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class CloudLinuxService
{
    protected string $apiUrl;
    protected string $apiKey;
    protected string $serverId;

    /**
     * Constructor.
     */
    public function __construct()
    {
        $this->apiUrl = config('services.cloudlinux.api_url');
        $this->apiKey = config('services.cloudlinux.api_key');
        $this->serverId = config('services.cloudlinux.server_id');
    }

    /**
     * Get resource usage for a specific user.
     *
     * @param string $cloudlinuxId
     * @return array|null
     */
    public function getResourceUsage(string $cloudlinuxId): ?array
    {
        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $this->apiKey,
                'Content-Type' => 'application/json',
            ])->get("{$this->apiUrl}/servers/{$this->serverId}/users/{$cloudlinuxId}/usage");

            if ($response->successful()) {
                $data = $response->json();

                return [
                    'cpu_usage' => $data['cpu_usage'] ?? 0,
                    'ram_usage' => $data['ram_usage'] ?? 0,
                    'io_usage' => $data['io_usage'] ?? 0,
                    'iops_usage' => $data['iops_usage'] ?? 0,
                    'ep_usage' => $data['ep_usage'] ?? 0,
                    'nproc_usage' => $data['nproc_usage'] ?? 0,
                ];
            }

            Log::error('CloudLinux API error: ' . $response->body());
            return null;
        } catch (Exception $e) {
            Log::error('CloudLinux service error: ' . $e->getMessage());

            // For development/testing purposes, return mock data when API fails
            if (config('app.env') !== 'production') {
                return $this->getMockResourceUsage();
            }

            return null;
        }
    }

    /**
     * Update resources for a specific user.
     *
     * @param string $cloudlinuxId
     * @param int $ram
     * @param int $cpu
     * @return bool
     */
    public function updateResources(string $cloudlinuxId, int $ram, int $cpu): bool
    {
        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $this->apiKey,
                'Content-Type' => 'application/json',
            ])->put("{$this->apiUrl}/servers/{$this->serverId}/users/{$cloudlinuxId}/limits", [
                'cpu' => $cpu,
                'pmem' => $ram,
            ]);

            return $response->successful();
        } catch (Exception $e) {
            Log::error('CloudLinux service error when updating resources: ' . $e->getMessage());

            // For development/testing purposes, return true
            if (config('app.env') !== 'production') {
                return true;
            }

            return false;
        }
    }

    /**
     * Get a list of users on the server.
     *
     * @return array|null
     */
    public function getUsers(): ?array
    {
        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $this->apiKey,
                'Content-Type' => 'application/json',
            ])->get("{$this->apiUrl}/servers/{$this->serverId}/users");

            if ($response->successful()) {
                return $response->json('users') ?? [];
            }

            Log::error('CloudLinux API error when getting users: ' . $response->body());
            return null;
        } catch (Exception $e) {
            Log::error('CloudLinux service error when getting users: ' . $e->getMessage());

            // For development/testing purposes, return mock data
            if (config('app.env') !== 'production') {
                return $this->getMockUsers();
            }

            return null;
        }
    }

    /**
     * Create a new user on the server.
     *
     * @param string $username
     * @param int $ram
     * @param int $cpu
     * @return string|null CloudLinux ID
     */
    public function createUser(string $username, int $ram, int $cpu): ?string
    {
        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $this->apiKey,
                'Content-Type' => 'application/json',
            ])->post("{$this->apiUrl}/servers/{$this->serverId}/users", [
                'username' => $username,
                'cpu' => $cpu,
                'pmem' => $ram,
            ]);

            if ($response->successful()) {
                return $response->json('id');
            }

            Log::error('CloudLinux API error when creating user: ' . $response->body());
            return null;
        } catch (Exception $e) {
            Log::error('CloudLinux service error when creating user: ' . $e->getMessage());

            // For development/testing purposes, return mock ID
            if (config('app.env') !== 'production') {
                return 'mock-' . substr(md5($username), 0, 10);
            }

            return null;
        }
    }

    /**
     * Delete a user from the server.
     *
     * @param string $cloudlinuxId
     * @return bool
     */
    public function deleteUser(string $cloudlinuxId): bool
    {
        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $this->apiKey,
                'Content-Type' => 'application/json',
            ])->delete("{$this->apiUrl}/servers/{$this->serverId}/users/{$cloudlinuxId}");

            return $response->successful();
        } catch (Exception $e) {
            Log::error('CloudLinux service error when deleting user: ' . $e->getMessage());

            // For development/testing purposes, return true
            if (config('app.env') !== 'production') {
                return true;
            }

            return false;
        }
    }

    /**
     * Get mock resource usage (for development/testing).
     *
     * @return array
     */
    protected function getMockResourceUsage(): array
    {
        return [
            'cpu_usage' => rand(10, 90),
            'ram_usage' => rand(128, 1024),
            'io_usage' => rand(10, 90),
            'iops_usage' => rand(10, 90),
            'ep_usage' => rand(10, 90),
            'nproc_usage' => rand(10, 50),
        ];
    }

    /**
     * Get mock users (for development/testing).
     *
     * @return array
     */
    protected function getMockUsers(): array
    {
        return [
            [
                'id' => 'mock-123456',
                'username' => 'user1',
                'cpu' => 100,
                'pmem' => 1024,
            ],
            [
                'id' => 'mock-234567',
                'username' => 'user2',
                'cpu' => 200,
                'pmem' => 2048,
            ],
        ];
    }
}