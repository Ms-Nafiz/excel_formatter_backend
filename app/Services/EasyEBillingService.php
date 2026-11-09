<?php

namespace App\Services;

use GuzzleHttp\Cookie\FileCookieJar;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class EasyEBillingService
{
    protected string $baseUrl;
    protected ?string $email;
    protected ?string $password;
    protected string $cookieFilePath;

    public function __construct()
    {
        $this->baseUrl = rtrim(config('services.easyebilling.url', env('EASYE_BILLING_URL', 'https://catv.easyebilling.com')), '/');
        $this->email = config('services.easyebilling.email', env('EASYE_BILLING_EMAIL'));
        $this->password = config('services.easyebilling.password', env('EASYE_BILLING_PASSWORD'));
        $this->cookieFilePath = storage_path('app/easyebilling_cookies.json');
    }

    /**
     * Get the persistent cookie jar.
     */
    protected function getCookieJar(): FileCookieJar
    {
        $dir = dirname($this->cookieFilePath);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        return new FileCookieJar($this->cookieFilePath, true);
    }

    /**
     * Clear stored session and cookies.
     */
    public function clearSession(): void
    {
        if (file_exists($this->cookieFilePath)) {
            @unlink($this->cookieFilePath);
        }
        Cache::forget('easyebilling_csrf_token');
    }

    /**
     * Log in to EasyEBilling and store session cookies + CSRF token.
     */
    public function login(bool $force = false): string
    {
        $cachedToken = Cache::get('easyebilling_csrf_token');
        if (!$force && $cachedToken && file_exists($this->cookieFilePath) && filesize($this->cookieFilePath) > 10) {
            return $cachedToken;
        }

        $this->clearSession();
        $cookieJar = $this->getCookieJar();

        // Step 1: GET login page to obtain initial CSRF token and session cookie
        $loginPageResponse = Http::withOptions([
            'cookies' => $cookieJar,
            'verify' => false,
            'headers' => [
                'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
            ],
        ])->get("{$this->baseUrl}/login");

        if (!$loginPageResponse->successful()) {
            throw new \Exception("EasyEBilling login page unreachable. Status: " . $loginPageResponse->status());
        }

        $initialToken = $this->extractCsrfToken($loginPageResponse->body());
        if (!$initialToken) {
            throw new \Exception("Could not extract CSRF token from EasyEBilling login page.");
        }

        // Save session cookies received from login page
        $cookieJar->save($this->cookieFilePath);

        // Step 2: POST credentials to authenticate
        $authResponse = Http::withOptions([
            'cookies' => $cookieJar,
            'verify' => false,
            'headers' => [
                'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
                'Referer' => "{$this->baseUrl}/login",
            ],
        ])->asForm()->post("{$this->baseUrl}/login", [
            '_token' => $initialToken,
            'email' => $this->email,
            'password' => $this->password,
        ]);

        // Explicitly persist cookies after login
        $cookieJar->save($this->cookieFilePath);

        if ($authResponse->status() === 419) {
            throw new \Exception("EasyEBilling CSRF mismatch during login.");
        }

        $body = $authResponse->body();
        if (str_contains($body, 'These credentials do not match our records')) {
            throw new \Exception("Invalid EasyEBilling credentials. Please check your email and password in .env.");
        }

        // Step 3: Extract fresh token from the landing page
        $freshToken = $this->extractCsrfToken($body) ?: $initialToken;
        Cache::put('easyebilling_csrf_token', $freshToken, now()->addHours(6));

        Log::info("EasyEBilling: Successfully logged in.");
        return $freshToken;
    }

    /**
     * Search customer by their Display ID (e.g. CAVB1252) and extract `_id` (pk).
     */
    public function searchCustomer(string $cusId): ?array
    {
        $token = $this->login();
        $cookieJar = $this->getCookieJar();

        // Perform search with the given customer ID
        $result = $this->executeSearch($cusId, $token, $cookieJar);

        // If not found and cusId contains alphanumeric prefix (e.g. CAVB1252), try searching numeric part only (1252)
        if (!$result && preg_match('/[a-zA-Z]+(\d+)/', $cusId, $m)) {
            $numericId = $m[1];
            Log::info("EasyEBilling: Searching with numeric part '{$numericId}' for '{$cusId}'");
            $result = $this->executeSearch($numericId, $token, $cookieJar, $cusId);
        }

        return $result;
    }

    /**
     * Helper to execute GET /customer search request
     */
    protected function executeSearch(string $term, string $token, $cookieJar, ?string $originalTarget = null): ?array
    {
        $response = Http::withOptions([
            'cookies' => $cookieJar,
            'verify' => false,
            'headers' => [
                'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
                'X-Requested-With' => 'XMLHttpRequest',
                'Referer' => "{$this->baseUrl}/customer",
            ],
        ])->get("{$this->baseUrl}/customer", [
            'search' => $term,
            '_token' => $token,
        ]);

        $cookieJar->save($this->cookieFilePath);

        // If redirected to login or unauthorized, retry once with fresh login
        if ($response->status() === 419 || $response->status() === 401 || str_contains($response->body(), 'login')) {
            Log::warning("EasyEBilling session expired during search for {$term}. Re-authenticating...");
            $token = $this->login(true);
            $cookieJar = $this->getCookieJar();

            $response = Http::withOptions([
                'cookies' => $cookieJar,
                'verify' => false,
                'headers' => [
                    'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
                    'X-Requested-With' => 'XMLHttpRequest',
                ],
            ])->get("{$this->baseUrl}/customer", [
                'search' => $term,
                '_token' => $token,
            ]);
            $cookieJar->save($this->cookieFilePath);
        }

        Log::info("EasyEBilling search response for [{$term}]: Status {$response->status()}, Body: " . substr($response->body(), 0, 300));

        $customers = $response->json();
        if (!is_array($customers) || empty($customers)) {
            return null;
        }

        $targetToMatch = strtoupper(trim($originalTarget ?: $term));

        foreach ($customers as $item) {
            $info = $item['customer_info'] ?? '';
            if (preg_match('/^' . preg_quote($targetToMatch, '/') . '\b/i', trim($info)) ||
                preg_match('/\b' . preg_quote($targetToMatch, '/') . '\b/i', $info)) {
                return [
                    'pk' => $item['_id'] ?? null,
                    'customer_info' => $info,
                ];
            }
        }

        // If only 1 result returned, return it
        if (count($customers) === 1 && isset($customers[0]['_id'])) {
            return [
                'pk' => $customers[0]['_id'],
                'customer_info' => $customers[0]['customer_info'] ?? '',
            ];
        }

        return null;
    }

    /**
     * Update a single inline field on EasyEBilling.
     *
     * @param string $pk Customer internal MongoDB ObjectId
     * @param string $fieldName Field name: 'area', 'building', 'house_no', 'flat_no'
     * @param string $fieldValue The new value
     */
    public function updateCustomerField(string $pk, string $fieldName, string $fieldValue): bool
    {
        $token = $this->login();
        $cookieJar = $this->getCookieJar();

        $url = "{$this->baseUrl}/update_customer_info/{$pk}/1";

        $response = Http::withOptions([
            'cookies' => $cookieJar,
            'verify' => false,
            'headers' => [
                'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
                'X-Requested-With' => 'XMLHttpRequest',
            ],
        ])->asForm()->post($url, [
            '_token' => $token,
            'pk' => $pk,
            'name' => $fieldName,
            'value' => $fieldValue,
        ]);

        // Retry once on session expiration
        if ($response->status() === 419 || $response->status() === 401) {
            Log::warning("EasyEBilling session expired during update of {$fieldName}. Retrying...");
            $token = $this->login(true);
            $cookieJar = $this->getCookieJar();

            $response = Http::withOptions([
                'cookies' => $cookieJar,
                'verify' => false,
                'headers' => [
                    'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
                    'X-Requested-With' => 'XMLHttpRequest',
                ],
            ])->asForm()->post($url, [
                '_token' => $token,
                'pk' => $pk,
                'name' => $fieldName,
                'value' => $fieldValue,
            ]);
        }

        if ($response->successful()) {
            Log::info("EasyEBilling updated {$fieldName} to '{$fieldValue}' for PK: {$pk}");
            return true;
        }

        Log::error("EasyEBilling update failed for {$fieldName}: " . $response->body());
        return false;
    }

    /**
     * Remove an address field on EasyEBilling (e.g. building).
     *
     * @param string $pk Customer internal MongoDB ObjectId
     * @param string $fieldName Field name: 'building', etc.
     */
    public function removeAddressField(string $pk, string $fieldName = 'building'): bool
    {
        $token = $this->login();
        $cookieJar = $this->getCookieJar();

        $url = "{$this->baseUrl}/remove_address_field";

        $payload = [
            '_token' => $token,
            'customer_id' => $pk,
            'field' => $fieldName,
        ];

        $response = Http::withOptions([
            'cookies' => $cookieJar,
            'verify' => false,
            'headers' => [
                'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
                'X-Requested-With' => 'XMLHttpRequest',
            ],
        ])->asForm()->post($url, $payload);

        // Retry once on session expiration
        if ($response->status() === 419 || $response->status() === 401) {
            Log::warning("EasyEBilling session expired during removeAddressField ({$fieldName}). Retrying...");
            $token = $this->login(true);
            $cookieJar = $this->getCookieJar();
            $payload['_token'] = $token;

            $response = Http::withOptions([
                'cookies' => $cookieJar,
                'verify' => false,
                'headers' => [
                    'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
                    'X-Requested-With' => 'XMLHttpRequest',
                ],
            ])->asForm()->post($url, $payload);
        }

        if ($response->successful()) {
            Log::info("EasyEBilling removeAddressField success for {$fieldName} on PK {$pk}. Response: " . substr($response->body(), 0, 150));
            return true;
        }

        Log::error("EasyEBilling removeAddressField failed for {$fieldName} on PK {$pk}: " . $response->body());
        return false;
    }

    /**
     * Sync customer address fields to EasyEBilling.
     *
     * @param string $cusId Display customer ID (e.g. CAVB1252)
     * @param array $addressData Array containing keys: 'house', 'flat', 'building', 'area'
     * @param string|null $knownPk Optional known PK to avoid search lookup
     */
    public function syncAddress(string $cusId, array $addressData, ?string $knownPk = null): array
    {
        $pk = $knownPk;

        if (!$pk) {
            $customer = $this->searchCustomer($cusId);
            if (!$customer || empty($customer['pk'])) {
                return [
                    'success' => false,
                    'message' => "Customer {$cusId} not found in EasyEBilling.",
                    'updated_fields' => [],
                ];
            }
            $pk = $customer['pk'];
        }

        $removeBuilding = false;

        // If area and building are identical, do NOT update building (update area only) and remove old building
        if (!empty($addressData['area']) && !empty($addressData['building'])) {
            if (strcasecmp(trim((string)$addressData['area']), trim((string)$addressData['building'])) === 0) {
                unset($addressData['building']);
                $removeBuilding = true;
                Log::info("EasyEBilling: Area and Building are identical ('" . trim((string)$addressData['area']) . "'). Building will be removed.");
            }
        }

        // If building is explicitly provided as null or empty, remove it
        if (array_key_exists('building', $addressData) && empty(trim((string)($addressData['building'] ?? '')))) {
            $removeBuilding = true;
            unset($addressData['building']);
        }

        $removedFields = [];
        if ($removeBuilding) {
            $removed = $this->removeAddressField($pk, 'building');
            if ($removed) {
                $removedFields['building'] = '[REMOVED]';
            }
        }

        // Map local fields to EasyEBilling field names
        $fieldMap = [
            'house' => 'house_no',
            'flat' => 'flat_no',
            'building' => 'building',
            'area' => 'area',
        ];

        $updated = [];
        $failed = [];

        foreach ($addressData as $key => $val) {
            if (empty(trim((string)$val))) {
                continue;
            }

            $billingField = $fieldMap[$key] ?? $key;
            $ok = $this->updateCustomerField($pk, $billingField, trim((string)$val));

            if ($ok) {
                $updated[$billingField] = trim((string)$val);
            } else {
                $failed[$billingField] = trim((string)$val);
            }
        }

        $allOk = count($failed) === 0 && (count($updated) > 0 || count($removedFields) > 0);

        return [
            'success' => $allOk,
            'pk' => $pk,
            'message' => $allOk ? "Address successfully synced to EasyEBilling." : "Some fields could not be synced.",
            'updated_fields' => array_merge($updated, $removedFields),
            'failed_fields' => $failed,
        ];
    }

    /**
     * Sync a single CustomerRecordHistory audit log entry directly to EasyEBilling.
     *
     * @param \App\Models\CustomerRecordHistory $history
     * @return array
     */
    public function syncAuditHistoryRecord(\App\Models\CustomerRecordHistory $history): array
    {
        $customerId = trim((string)$history->customer_id);
        if (empty($customerId) || str_starts_with($customerId, 'ID #') || str_starts_with($customerId, 'Record #')) {
            $history->loadMissing('customerRecord');
            $customerId = trim((string)($history->customerRecord->customer_id ?? ''));
        }

        if (empty($customerId)) {
            $history->update([
                'billing_sync_status' => 'failed',
                'billing_sync_error' => 'Valid Customer ID is required to sync with EasyEBilling.',
            ]);
            return [
                'success' => false,
                'message' => 'Valid Customer ID is required to sync with EasyEBilling.',
            ];
        }

        // 1. Resolve EasyEBilling Primary Key (pk / _id)
        $knownPk = $history->billing_pk;
        if (!$knownPk && $history->customerRecord) {
            $knownPk = $history->customerRecord->billing_pk;
        }
        if (!$knownPk) {
            $knownPk = \App\Models\Customer::where('customer_id', $customerId)->value('billing_pk');
        }

        if (!$knownPk) {
            try {
                $found = $this->searchCustomer($customerId);
                if ($found && !empty($found['pk'])) {
                    $knownPk = $found['pk'];
                    if ($history->customerRecord) {
                        $history->customerRecord->update(['billing_pk' => $knownPk]);
                    }
                    \App\Models\Customer::where('customer_id', $customerId)->update(['billing_pk' => $knownPk]);
                }
            } catch (\Throwable $e) {
                $history->update([
                    'billing_sync_status' => 'failed',
                    'billing_sync_error' => 'EasyEBilling search error: ' . $e->getMessage(),
                ]);
                return [
                    'success' => false,
                    'message' => 'EasyEBilling search error: ' . $e->getMessage(),
                ];
            }
        }

        if (!$knownPk) {
            $msg = "Customer '{$customerId}' was not found in EasyEBilling.";
            $history->update([
                'billing_sync_status' => 'failed',
                'billing_sync_error' => $msg,
            ]);
            return [
                'success' => false,
                'message' => $msg,
            ];
        }

        $history->update(['billing_pk' => $knownPk]);

        // 2. Identify the field and execute the appropriate update
        $fieldName = trim((string)$history->field_name);
        $newVal = trim((string)$history->new_value);
        $fLower = strtolower($fieldName);

        try {
            $syncSuccess = false;
            $syncMessage = '';

            // Status: Active / Inactive / Temp / Free
            if ($fLower === 'status' || str_contains($fLower, 'status')) {
                $targetStatus = '1';
                if (preg_match('/inactive|disconnect|cancel|^0$/i', $newVal)) {
                    $targetStatus = '0';
                } elseif (preg_match('/temp|pause|^2$/i', $newVal)) {
                    $targetStatus = '2';
                } elseif (preg_match('/free|complimentary|^3$/i', $newVal)) {
                    $targetStatus = '3';
                }

                $syncSuccess = $this->updateCustomerStatus($knownPk, $targetStatus);
                $statusLabels = ['0' => 'Inactive (Disconnected)', '1' => 'Enable (Active)', '2' => 'Temporary Disabled', '3' => 'Free'];
                $syncMessage = $syncSuccess 
                    ? "Status successfully set to " . ($statusLabels[$targetStatus] ?? $targetStatus)
                    : "Failed to update status on EasyEBilling.";
            }
            // Area Name
            elseif ($fLower === 'area name' || $fLower === 'area' || str_contains($fLower, 'area')) {
                $syncSuccess = $this->updateCustomerField($knownPk, 'area', $newVal);
                $syncMessage = $syncSuccess ? "Area updated to '{$newVal}'" : "Failed to update area on EasyEBilling.";
            }
            // Building Name
            elseif ($fLower === 'building name' || $fLower === 'building' || str_contains($fLower, 'building')) {
                if (empty($newVal) || strcasecmp($newVal, 'n/a') === 0 || strcasecmp($newVal, 'none') === 0) {
                    $syncSuccess = $this->removeAddressField($knownPk, 'building');
                    $syncMessage = $syncSuccess ? "Building removed on EasyEBilling" : "Failed to remove building.";
                } else {
                    $syncSuccess = $this->updateCustomerField($knownPk, 'building', $newVal);
                    $syncMessage = $syncSuccess ? "Building updated to '{$newVal}'" : "Failed to update building on EasyEBilling.";
                }
            }
            // House No
            elseif ($fLower === 'house no' || $fLower === 'house_no' || str_contains($fLower, 'house')) {
                $syncSuccess = $this->updateCustomerField($knownPk, 'house_no', $newVal);
                $syncMessage = $syncSuccess ? "House No updated to '{$newVal}'" : "Failed to update house no on EasyEBilling.";
            }
            // Flat No
            elseif ($fLower === 'flat no' || $fLower === 'flat_no' || str_contains($fLower, 'flat')) {
                $syncSuccess = $this->updateCustomerField($knownPk, 'flat_no', $newVal);
                $syncMessage = $syncSuccess ? "Flat No updated to '{$newVal}'" : "Failed to update flat no on EasyEBilling.";
            }
            // Customer Name
            elseif ($fLower === 'customer name' || $fLower === 'full_name' || $fLower === 'name' || str_contains($fLower, 'name')) {
                $syncSuccess = $this->updateCustomerField($knownPk, 'name', $newVal);
                $syncMessage = $syncSuccess ? "Customer Name updated to '{$newVal}'" : "Failed to update name on EasyEBilling.";
            }
            // Monthly Rent / Bill
            elseif (str_contains($fLower, 'rent') || str_contains($fLower, 'bill') || str_contains($fLower, 'monthly')) {
                $billRes = $this->updateCustomerBill($knownPk, ['monthly_rent' => (float)$newVal], $customerId);
                $syncSuccess = !empty($billRes['success']);
                $syncMessage = $billRes['message'] ?? ($syncSuccess ? "Monthly rent updated to {$newVal}" : "Failed to update rent.");
            }
            // Discount
            elseif (str_contains($fLower, 'discount')) {
                $billRes = $this->updateCustomerBill($knownPk, ['discount' => (float)$newVal], $customerId);
                $syncSuccess = !empty($billRes['success']);
                $syncMessage = $billRes['message'] ?? ($syncSuccess ? "Discount updated to {$newVal}" : "Failed to update discount.");
            }
            // Previous Dues
            elseif (str_contains($fLower, 'due')) {
                $billRes = $this->updateCustomerBill($knownPk, ['due' => (float)$newVal], $customerId);
                $syncSuccess = !empty($billRes['success']);
                $syncMessage = $billRes['message'] ?? ($syncSuccess ? "Dues updated to {$newVal}" : "Failed to update dues.");
            }
            // Advance
            elseif (str_contains($fLower, 'advance')) {
                $billRes = $this->updateCustomerBill($knownPk, ['advance' => (float)$newVal], $customerId);
                $syncSuccess = !empty($billRes['success']);
                $syncMessage = $billRes['message'] ?? ($syncSuccess ? "Advance updated to {$newVal}" : "Failed to update advance.");
            }
            // Generic Fallback
            else {
                $syncSuccess = $this->updateCustomerField($knownPk, $fieldName, $newVal);
                $syncMessage = $syncSuccess ? "'{$fieldName}' updated to '{$newVal}'" : "Failed to update '{$fieldName}'.";
            }

            if ($syncSuccess) {
                $history->update([
                    'billing_sync_status' => 'synced',
                    'billing_synced_at' => now(),
                    'billing_sync_error' => null,
                    'billing_pk' => $knownPk,
                ]);

                return [
                    'success' => true,
                    'pk' => $knownPk,
                    'customer_id' => $customerId,
                    'field_name' => $fieldName,
                    'new_value' => $newVal,
                    'message' => $syncMessage,
                ];
            } else {
                $history->update([
                    'billing_sync_status' => 'failed',
                    'billing_sync_error' => $syncMessage,
                    'billing_pk' => $knownPk,
                ]);

                return [
                    'success' => false,
                    'pk' => $knownPk,
                    'customer_id' => $customerId,
                    'field_name' => $fieldName,
                    'message' => $syncMessage,
                ];
            }
        } catch (\Throwable $e) {
            $history->update([
                'billing_sync_status' => 'failed',
                'billing_sync_error' => $e->getMessage(),
                'billing_pk' => $knownPk,
            ]);

            return [
                'success' => false,
                'message' => 'Sync exception: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * Bulk sync an array of CustomerRecordHistory audit logs to EasyEBilling.
     *
     * @param array $historyIds
     * @return array
     */
    public function syncAuditHistoryRecordsBulk(array $historyIds): array
    {
        $histories = \App\Models\CustomerRecordHistory::whereIn('id', $historyIds)->get();
        $results = [
            'total' => count($histories),
            'synced' => 0,
            'failed' => 0,
            'items' => [],
        ];

        foreach ($histories as $history) {
            $res = $this->syncAuditHistoryRecord($history);
            if ($res['success']) {
                $results['synced']++;
            } else {
                $results['failed']++;
            }
            $results['items'][] = [
                'id' => $history->id,
                'customer_id' => $history->customer_id,
                'field_name' => $history->field_name,
                'success' => $res['success'],
                'message' => $res['message'],
            ];
        }

        return $results;
    }

    /**
     * Get the current status of a customer on EasyEBilling.
     *
     * @param string $pk Customer internal MongoDB ObjectId
     * @return string|null "0" for Inactive, "1" for Enable, "2" for Temp Disable, "3" for Free, or null if undetermined
     */
    public function getCustomerStatus(string $pk): ?string
    {
        $token = $this->login();
        $cookieJar = $this->getCookieJar();

        $response = Http::withOptions([
            'cookies' => $cookieJar,
            'verify' => false,
            'headers' => [
                'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
                'Referer' => "{$this->baseUrl}/customer",
            ],
        ])->asForm()->post("{$this->baseUrl}/customer", [
            '_token' => $token,
            'search' => $pk,
        ]);

        $cookieJar->save($this->cookieFilePath);

        // Retry once on session expiration or redirect
        if ($response->status() === 419 || $response->status() === 401 || str_contains($response->body(), 'login')) {
            Log::warning("EasyEBilling session expired during getCustomerStatus for PK: {$pk}. Re-authenticating...");
            $token = $this->login(true);
            $cookieJar = $this->getCookieJar();

            $response = Http::withOptions([
                'cookies' => $cookieJar,
                'verify' => false,
                'headers' => [
                    'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
                    'Referer' => "{$this->baseUrl}/customer",
                ],
            ])->asForm()->post("{$this->baseUrl}/customer", [
                '_token' => $token,
                'search' => $pk,
            ]);
            $cookieJar->save($this->cookieFilePath);
        }

        if (!$response->successful()) {
            Log::error("EasyEBilling getCustomerStatus failed for PK {$pk}: HTTP " . $response->status());
            return null;
        }

        $html = $response->body();
        if (preg_match('/data-name=["\']status["\'][^>]*data-value=["\'](\d+)["\']/i', $html, $m)) {
            return (string)$m[1];
        }
        if (preg_match('/data-value=["\'](\d+)["\'][^>]*data-name=["\']status["\']/i', $html, $m)) {
            return (string)$m[1];
        }

        return null;
    }

    /**
     * Update customer status on EasyEBilling (e.g. 0 = Inactive, 1 = Enable, 2 = Temp Disable, 3 = Free).
     *
     * @param string $pk Customer internal MongoDB ObjectId
     * @param string $statusValue "0" for Inactive, "1" for Enable, "2" for Temporary Disable, "3" for Free
     */
    public function updateCustomerStatus(string $pk, string $statusValue = "0"): bool
    {
        $token = $this->login();
        $cookieJar = $this->getCookieJar();

        $url = "{$this->baseUrl}/update_customer_status/{$pk}";

        $response = Http::withOptions([
            'cookies' => $cookieJar,
            'verify' => false,
            'headers' => [
                'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
                'X-Requested-With' => 'XMLHttpRequest',
                'Referer' => "{$this->baseUrl}/customer",
            ],
        ])->asForm()->post($url, [
            '_token' => $token,
            'pk' => $pk,
            'name' => 'status',
            'value' => (string)$statusValue,
        ]);

        // Retry once on session expiration or 419
        if ($response->status() === 419 || $response->status() === 401) {
            Log::warning("EasyEBilling session expired during status update for {$pk}. Retrying...");
            $token = $this->login(true);
            $cookieJar = $this->getCookieJar();

            $response = Http::withOptions([
                'cookies' => $cookieJar,
                'verify' => false,
                'headers' => [
                    'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
                    'X-Requested-With' => 'XMLHttpRequest',
                ],
            ])->asForm()->post($url, [
                '_token' => $token,
                'pk' => $pk,
                'name' => 'status',
                'value' => (string)$statusValue,
            ]);
        }

        $cookieJar->save($this->cookieFilePath);

        if ($response->successful()) {
            Log::info("EasyEBilling updated customer status to '{$statusValue}' for PK: {$pk}");
            return true;
        }

        Log::error("EasyEBilling status update failed for PK {$pk}: " . $response->body());
        return false;
    }

    /**
     * Disconnect a customer on EasyEBilling (sets status to '0' = Inactive).
     */
    public function disconnectCustomer(string $pk): array
    {
        $ok = $this->updateCustomerStatus($pk, "0");

        return [
            'success' => $ok,
            'pk' => $pk,
            'message' => $ok ? "Customer successfully marked as Disconnected (Inactive) in EasyEBilling." : "Could not disconnect customer in EasyEBilling.",
        ];
    }

    /**
     * Reconnect a customer on EasyEBilling (sets status to '1' = Enable).
     */
    public function reconnectCustomer(string $pk): array
    {
        $ok = $this->updateCustomerStatus($pk, "1");

        return [
            'success' => $ok,
            'pk' => $pk,
            'message' => $ok ? "Customer successfully marked as Reconnected (Enable) in EasyEBilling." : "Could not reconnect customer in EasyEBilling.",
        ];
    }

    /**
     * Fetch customer security deposit history directly from EasyEBilling.
     *
     * @param string $cusId Display customer ID or Mobile
     * @param string|null $knownPk Optional MongoDB ObjectId
     */
    public function getCustomerSecurityDeposit(string $cusId, ?string $knownPk = null): array
    {
        $pk = $knownPk;
        $localCust = null;
        $realCusId = trim($cusId);

        // If identifier is already a 24-character hexadecimal MongoDB ObjectId
        if (preg_match('/^[a-f\d]{24}$/i', $realCusId)) {
            $pk = $realCusId;
            $localCust = \App\Models\Customer::where('billing_pk', $pk)->first();
            if ($localCust) {
                $realCusId = $localCust->customer_id;
            }
        } else {
            $localCust = \App\Models\Customer::where('customer_id', $realCusId)->first();
            if (!$pk && $localCust && !empty($localCust->billing_pk)) {
                $pk = $localCust->billing_pk;
            }
        }

        if (!$pk) {
            $customer = $this->searchCustomer($realCusId);
            if (!$customer || empty($customer['pk'])) {
                return [
                    'success' => false,
                    'message' => "Customer '{$realCusId}' not found in EasyEBilling.",
                    'records' => [],
                    'total_deposit' => 0,
                    'total_return' => 0,
                    'current_balance' => 0,
                ];
            }
            $pk = $customer['pk'];

            if (!empty($localCust) && empty($localCust->billing_pk)) {
                $localCust->update(['billing_pk' => $pk]);
            }
        }

        $this->login();
        $cookieJar = $this->getCookieJar();

        $url = "{$this->baseUrl}/security-deposit/{$pk}";

        $response = Http::withOptions([
            'cookies' => $cookieJar,
            'verify' => false,
            'headers' => [
                'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
                'Referer' => "{$this->baseUrl}/customer",
            ],
        ])->get($url);

        $cookieJar->save($this->cookieFilePath);

        // Retry once on session expiration or redirect to login
        if ($response->status() === 419 || $response->status() === 401 || str_contains($response->body(), 'login')) {
            Log::warning("EasyEBilling session expired during security-deposit fetch for PK: {$pk}. Re-authenticating...");
            $this->login(true);
            $cookieJar = $this->getCookieJar();

            $response = Http::withOptions([
                'cookies' => $cookieJar,
                'verify' => false,
                'headers' => [
                    'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
                    'Referer' => "{$this->baseUrl}/customer",
                ],
            ])->get($url);
            $cookieJar->save($this->cookieFilePath);
        }

        // Self-Healing: if request failed (wrong or stale PK), search EasyEBilling by cus_id/mobile to resolve fresh PK and retry
        if (!$response->successful() || str_contains($response->body(), 'not found') || (!$this->isLoginPage($response) && !str_contains($response->body(), 'security_detail'))) {
            if ($realCusId && !preg_match('/^[a-f\d]{24}$/i', $realCusId)) {
                Log::warning("Security deposit request for PK {$pk} might be stale/invalid. Re-searching EasyEBilling for {$realCusId}...");
                $freshCustomer = $this->searchCustomer($realCusId);
                if (!$freshCustomer && $localCust && !empty($localCust->mobile)) {
                    $freshCustomer = $this->searchCustomer($localCust->mobile);
                }
                if ($freshCustomer && !empty($freshCustomer['pk']) && $freshCustomer['pk'] !== $pk) {
                    $pk = $freshCustomer['pk'];
                    if ($localCust) {
                        $localCust->update(['billing_pk' => $pk]);
                    }
                    $url = "{$this->baseUrl}/security-deposit/{$pk}";
                    $response = Http::withOptions([
                        'cookies' => $cookieJar,
                        'verify' => false,
                        'headers' => [
                            'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
                            'Referer' => "{$this->baseUrl}/customer",
                        ],
                    ])->get($url);
                    $cookieJar->save($this->cookieFilePath);
                }
            }
        }

        if (!$response->successful()) {
            return [
                'success' => false,
                'message' => "Failed to fetch security deposit page from EasyEBilling for '{$realCusId}' (Status {$response->status()}).",
                'records' => [],
                'total_deposit' => 0,
                'total_return' => 0,
                'current_balance' => 0,
            ];
        }

        return $this->parseSecurityDepositHtml($response->body(), $pk, $realCusId);
    }

    /**
     * Parse HTML response from /security-deposit/{pk} into structured data.
     */
    protected function parseSecurityDepositHtml(string $html, string $pk, string $cusId): array
    {
        $dom = new \DOMDocument();
        @$dom->loadHTML($html);
        $xpath = new \DOMXPath($dom);

        $records = [];
        $totalDeposit = 0;
        $totalReturn = 0;

        $rows = $xpath->query('//table[@id="security_detail"]/tbody/tr | //table[contains(@class, "table")]/tbody/tr');
        foreach ($rows as $row) {
            $cols = $xpath->query('.//td', $row);
            if ($cols->length >= 4) {
                $sl = trim($cols->item(0)->textContent);
                $depRaw = trim($cols->item(1)->textContent);
                $retRaw = trim($cols->item(2)->textContent);
                $remarks = $cols->length >= 5 ? trim($cols->item(3)->textContent) : '';
                $date = $cols->length >= 5 ? trim($cols->item(4)->textContent) : trim($cols->item(3)->textContent);

                $dep = (float)str_replace(',', '', $depRaw);
                $ret = (float)str_replace(',', '', $retRaw);

                $totalDeposit += $dep;
                $totalReturn += $ret;

                $records[] = [
                    'sl' => $sl ?: (count($records) + 1),
                    'deposit' => $dep,
                    'return' => $ret,
                    'remarks' => $remarks,
                    'date' => $date,
                ];
            }
        }

        $balance = $totalDeposit - $totalReturn;

        return [
            'success' => true,
            'pk' => $pk,
            'cus_id' => $cusId,
            'records' => $records,
            'total_deposit' => $totalDeposit,
            'total_return' => $totalReturn,
            'current_balance' => $balance,
            'fetched_at' => now()->toIso8601String(),
        ];
    }

    /**
     * Helper to extract CSRF token from HTML.
     */
    protected function extractCsrfToken(string $html): ?string
    {
        if (preg_match('/<input[^>]+name=["\']_token["\'][^>]+value=["\']([^"\']+)["\']/i', $html, $matches)) {
            return $matches[1];
        }
        if (preg_match('/<input[^>]+value=["\']([^"\']+)["\'][^>]+name=["\']_token["\']/i', $html, $matches)) {
            return $matches[1];
        }
        if (preg_match('/<meta[^>]+name=["\']csrf-token["\'][^>]+content=["\']([^"\']+)["\']/i', $html, $matches)) {
            return $matches[1];
        }
        return null;
    }

    /**
     * Fetch customer summary and bill payment details from EasyEBilling (/customer_summary).
     *
     * @param string $customerId Customer ID (e.g. CAVB9094) or MongoDB ObjectId PK
     * @return array
     */
    public function getCustomerSummary(string $customerId, ?string $knownPk = null): array
    {
        $pk = $knownPk;
        $cusId = trim($customerId);
        $localCust = null;

        // If it's already a 24-character hexadecimal MongoDB ObjectId
        if (preg_match('/^[a-f\d]{24}$/i', $cusId)) {
            $pk = $cusId;
            $localCust = \App\Models\Customer::where('billing_pk', $pk)->first();
            if ($localCust) {
                $cusId = $localCust->customer_id;
            }
        } else {
            $localCust = \App\Models\Customer::where('customer_id', $cusId)->first();
            if (!$pk && $localCust && !empty($localCust->billing_pk)) {
                $pk = $localCust->billing_pk;
            }
        }

        if (!$pk) {
            $customer = $this->searchCustomer($cusId);
            if (!$customer || empty($customer['pk'])) {
                return [
                    'success' => false,
                    'message' => "Customer '{$cusId}' not found in EasyEBilling.",
                    'customer_meta' => null,
                    'totals' => null,
                    'ledger' => [],
                ];
            }
            $pk = $customer['pk'];

            if (!empty($localCust) && empty($localCust->billing_pk)) {
                $localCust->update(['billing_pk' => $pk]);
            }
        }

        $token = $this->login();
        $cookieJar = $this->getCookieJar();

        $url = "{$this->baseUrl}/customer_summary";

        $response = Http::withOptions([
            'cookies' => $cookieJar,
            'verify' => false,
            'headers' => [
                'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
                'Referer' => "{$this->baseUrl}/customer_summary",
            ],
        ])->asForm()->post($url, [
            '_token' => $token,
            'search' => $pk,
            'selected_search' => '',
        ]);

        $cookieJar->save($this->cookieFilePath);

        // Retry once on session expiration or redirect to login
        if ($response->status() === 419 || $response->status() === 401 || str_contains($response->body(), 'login')) {
            Log::warning("EasyEBilling session expired during customer_summary fetch for PK: {$pk}. Re-authenticating...");
            $token = $this->login(true);
            $cookieJar = $this->getCookieJar();

            $response = Http::withOptions([
                'cookies' => $cookieJar,
                'verify' => false,
                'headers' => [
                    'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
                    'Referer' => "{$this->baseUrl}/customer_summary",
                ],
            ])->asForm()->post($url, [
                '_token' => $token,
                'search' => $pk,
                'selected_search' => '',
            ]);
            $cookieJar->save($this->cookieFilePath);
        }

        $parsed = $this->parseCustomerSummaryHtml($response->body(), $pk, $cusId);

        // Self-Healing: If parsed result has no customer meta or ledger (stale/wrong PK), re-search by cus_id/mobile
        if (empty($parsed['ledger']) && empty($parsed['customer_meta']['name'])) {
            if ($cusId && !preg_match('/^[a-f\d]{24}$/i', $cusId)) {
                Log::warning("Customer summary empty for PK {$pk}. Re-searching EasyEBilling for {$cusId}...");
                $freshCustomer = $this->searchCustomer($cusId);
                if (!$freshCustomer && $localCust && !empty($localCust->mobile)) {
                    $freshCustomer = $this->searchCustomer($localCust->mobile);
                }
                if ($freshCustomer && !empty($freshCustomer['pk']) && $freshCustomer['pk'] !== $pk) {
                    $pk = $freshCustomer['pk'];
                    if ($localCust) {
                        $localCust->update(['billing_pk' => $pk]);
                    }
                    $response = Http::withOptions([
                        'cookies' => $cookieJar,
                        'verify' => false,
                        'headers' => [
                            'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
                            'Referer' => "{$this->baseUrl}/customer_summary",
                        ],
                    ])->asForm()->post($url, [
                        '_token' => $token,
                        'search' => $pk,
                        'selected_search' => '',
                    ]);
                    $cookieJar->save($this->cookieFilePath);
                    $parsed = $this->parseCustomerSummaryHtml($response->body(), $pk, $cusId);
                }
            }
        }

        if (!$response->successful()) {
            return [
                'success' => false,
                'message' => "Failed to fetch customer summary from EasyEBilling (Status {$response->status()}).",
                'customer_meta' => null,
                'totals' => null,
                'ledger' => [],
            ];
        }

        return $parsed;
    }

    /**
     * Parse HTML response from /customer_summary into structured ledger and totals.
     */
    protected function parseCustomerSummaryHtml(string $html, string $pk, string $cusId): array
    {
        $dom = new \DOMDocument();
        @$dom->loadHTML($html);
        $xpath = new \DOMXPath($dom);

        // 1. Extract customer summary meta header text
        $metaText = '';
        $h2List = $xpath->query('//h2[contains(text(), "Customer summary")]');
        if ($h2List->length > 0) {
            $h2 = $h2List->item(0);
            $curr = $h2->nextSibling;
            while ($curr) {
                if ($curr->nodeName === 'div' && strpos($curr->getAttribute('class') ?? '', 'table-responsive') !== false) {
                    break;
                }
                $metaText .= $curr->textContent . " ";
                $curr = $curr->nextSibling;
            }
        }

        $metaText = trim(preg_replace('/\s+/', ' ', $metaText));

        $id = $cusId;
        $name = null;
        $status = null;
        $connectedBy = null;
        $mobile = null;
        $houseNo = null;
        $flatNo = null;

        if (preg_match('/ID\s*:\s*([^\s<]+)/i', $metaText, $m)) $id = trim($m[1]);
        if (preg_match('/Status\s*:\s*([^\s<]+)/i', $metaText, $m)) $status = trim($m[1]);
        if (preg_match('/Connected By\s*:\s*([^<]+?)(?:Date|$)/i', $metaText, $m)) $connectedBy = trim($m[1]);
        if (preg_match('/(?:88)?(01[3-9]\d{8})/i', $metaText, $mob)) $mobile = '0' . ltrim($mob[1], '0');
        if (preg_match('/House No\s*#?\s*([^,]+)/i', $metaText, $hm)) $houseNo = trim($hm[1]);
        if (preg_match('/Flat No\s*#?\s*([^,]+)/i', $metaText, $fm)) $flatNo = trim($fm[1]);

        if (preg_match('/^([^,<\n]+?)(?:House No|Flat No|ID\s*:)/i', $metaText, $nm)) {
            $name = trim($nm[1]);
        }

        // 2. Parse Table Rows
        $rows = $xpath->query('//table//tr');
        $ledger = [];
        $totalPaid = 0;
        $latestDue = 0;
        $latestMonthlyRent = 0;
        $paymentsCount = 0;
        $billsCount = 0;

        foreach ($rows as $row) {
            $ths = $xpath->query('.//th', $row);
            if ($ths->length > 0) continue; // skip table header

            $tds = $xpath->query('.//td', $row);
            if ($tds->length === 0) continue;

            $cols = [];
            foreach ($tds as $td) {
                $cols[] = trim(preg_replace('/\s+/', ' ', $td->textContent));
            }

            $fullRowText = implode(' ', $cols);
            if (stripos($fullRowText, 'Total Paid') !== false) {
                foreach ($cols as $c) {
                    $cleaned = str_replace(',', '', $c);
                    if (is_numeric($cleaned) && (float)$cleaned > 0) {
                        $totalPaid = (float)$cleaned;
                        break;
                    }
                }
                continue;
            }

            $date = $cols[0] ?? '';
            if (empty($date) && empty($cols[1])) continue;

            if (stripos($fullRowText, 'Collected By') !== false) {
                // Payment Row
                $collector = '';
                $paidAmount = 0;
                $totalDue = 0;
                $note = '';

                for ($i = 0; $i < count($cols); $i++) {
                    if (stripos($cols[$i], 'Collected By') !== false) {
                        $collector = trim(str_ireplace('Collected By :', '', $cols[$i]));
                        if (isset($cols[$i + 1])) $paidAmount = (float)str_replace(',', '', $cols[$i + 1]);
                        if (isset($cols[$i + 2])) $totalDue = (float)str_replace(',', '', $cols[$i + 2]);
                        if (isset($cols[$i + 3])) $note = $cols[$i + 3];
                        break;
                    }
                }

                $paymentsCount++;

                $ledger[] = [
                    'id' => count($ledger) + 1,
                    'type' => 'payment',
                    'date' => $date,
                    'collector' => $collector,
                    'paid_amount' => $paidAmount,
                    'total_due' => $totalDue,
                    'note' => $note,
                ];
            } else {
                // Bill Generation Row
                $rent = isset($cols[1]) ? (float)str_replace(',', '', $cols[1]) : 0;
                $additional = isset($cols[2]) ? (float)str_replace(',', '', $cols[2]) : 0;
                $discount = isset($cols[3]) ? (float)str_replace(',', '', $cols[3]) : 0;
                $advance = isset($cols[4]) ? (float)str_replace(',', '', $cols[4]) : 0;
                $sum = isset($cols[5]) ? (float)str_replace(',', '', $cols[5]) : 0;
                $vatPct = isset($cols[6]) ? (float)str_replace(',', '', $cols[6]) : 0;
                $sumWithVat = isset($cols[7]) ? (float)str_replace(',', '', $cols[7]) : 0;
                $prevDue = isset($cols[8]) ? (float)str_replace(',', '', $cols[8]) : 0;
                $due = isset($cols[9]) ? (float)str_replace(',', '', $cols[9]) : 0;

                if ($rent > 0) $latestMonthlyRent = $rent;
                $billsCount++;

                $ledger[] = [
                    'id' => count($ledger) + 1,
                    'type' => 'bill',
                    'date' => $date,
                    'monthly_rent' => $rent,
                    'additional' => $additional,
                    'discount' => $discount,
                    'advance' => $advance,
                    'sum' => $sum,
                    'vat_pct' => $vatPct,
                    'sum_with_vat' => $sumWithVat,
                    'previous_due' => $prevDue,
                    'due' => $due,
                    'note' => $cols[12] ?? '',
                ];
            }
        }

        // The latest transactions are at the bottom of the table on EasyEBilling
        $latestTx = !empty($ledger) ? end($ledger) : null;
        if ($latestTx) {
            $latestDue = $latestTx['type'] === 'payment' ? $latestTx['total_due'] : $latestTx['due'];
        }

        $latestPaymentTx = null;
        for ($i = count($ledger) - 1; $i >= 0; $i--) {
            if ($ledger[$i]['type'] === 'payment') {
                $latestPaymentTx = $ledger[$i];
                break;
            }
        }

        return [
            'success' => true,
            'pk' => $pk,
            'cus_id' => $id,
            'customer_meta' => [
                'id' => $id,
                'name' => $name,
                'status' => $status,
                'connected_by' => $connectedBy,
                'mobile' => $mobile,
                'house_no' => $houseNo,
                'flat_no' => $flatNo,
                'raw' => $metaText,
            ],
            'totals' => [
                'total_paid' => $totalPaid,
                'current_due' => $latestDue,
                'monthly_rent' => $latestMonthlyRent,
                'last_payment' => $latestPaymentTx,
                'total_transactions' => count($ledger),
                'payments_count' => $paymentsCount,
                'bills_count' => $billsCount,
            ],
            'ledger' => array_reverse($ledger),
            'fetched_at' => now()->toIso8601String(),
        ];
    }

    /**
     * Fetch recent customers within a date range from EasyEBilling (/recent-customer).
     *
     * @param string $fromDate 'd-m-Y' (e.g. 01-09-2026)
     * @param string $toDate 'd-m-Y' (e.g. 03-10-2026)
     * @return array
     */
    public function getRecentCustomers(string $fromDate, string $toDate): array
    {
        $token = $this->login();
        $cookieJar = $this->getCookieJar();

        $response = Http::withOptions([
            'cookies' => $cookieJar,
            'verify' => false,
            'connect_timeout' => 5,
            'timeout' => 30,
            'headers' => [
                'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
                'Referer' => "{$this->baseUrl}/recent-customer",
            ],
        ])->asForm()->post("{$this->baseUrl}/recent-customer", [
            '_token' => $token,
            'from' => $fromDate,
            'to' => $toDate,
        ]);

        if (!$response->successful()) {
            // If session expired or CSRF token issue, force re-login and retry once
            if ($response->status() === 419 || $response->status() === 302) {
                $token = $this->login(true);
                $cookieJar = $this->getCookieJar();
                $response = Http::withOptions([
                    'cookies' => $cookieJar,
                    'verify' => false,
                    'connect_timeout' => 5,
                    'timeout' => 30,
                    'headers' => [
                        'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
                        'Referer' => "{$this->baseUrl}/recent-customer",
                    ],
                ])->asForm()->post("{$this->baseUrl}/recent-customer", [
                    '_token' => $token,
                    'from' => $fromDate,
                    'to' => $toDate,
                ]);
            }

            if (!$response->successful()) {
                throw new \Exception("Failed to fetch recent customers from EasyEBilling. HTTP Status: " . $response->status());
            }
        }

        return $this->parseRecentCustomersHtml($response->body(), $fromDate, $toDate);
    }

    /**
     * Parse HTML response from /recent-customer into structured customer array.
     */
    public function parseRecentCustomersHtml(string $html, string $fromDate, string $toDate): array
    {
        $dom = new \DOMDocument();
        @$dom->loadHTML($html);
        $xpath = new \DOMXPath($dom);

        $masterAreas = \App\Models\Area::all();
        $masterSubAreas = class_exists(\App\Models\Building::class) ? \App\Models\Building::all() : collect();

        $rows = $xpath->query('//table//tbody//tr');
        $customers = [];
        $cusIds = [];

        foreach ($rows as $row) {
            $tds = $xpath->query('.//td', $row);
            if ($tds->length < 4) continue;

            $cusId = trim($tds->item(1)->textContent);
            $name = trim($tds->item(2)->textContent);
            $rawAddress = trim($tds->item(3)->textContent);
            $billingType = ($tds->length > 4 && $tds->item(4)) ? trim($tds->item(4)->textContent) : 'Post Paid';
            $monthlyRent = ($tds->length > 5 && $tds->item(5)) ? trim($tds->item(5)->textContent) : '0';
            $totalDue = ($tds->length > 14 && $tds->item(14)) ? trim($tds->item(14)->textContent) : '0';
            $entryDate = ($tds->length > 15 && $tds->item(15)) ? trim($tds->item(15)->textContent) : '';

            if (empty($cusId)) continue;

            // Parse address components
            $house = '';
            $flat = '';
            $building = '';
            $area = '';
            $mobile = '';
            $matchedAreaId = null;
            $matchedBuildingId = null;

            $parts = array_map('trim', explode(',', $rawAddress));
            $locationParts = [];

            foreach ($parts as $part) {
                if ($part === '') continue;
                $digits = preg_replace('/[^0-9]/', '', $part);

                if (preg_match('/^(?:88)?01\d{9}$/', $digits)) {
                    if (str_starts_with($digits, '8801') && strlen($digits) === 13) {
                        $mobile = substr($digits, 2);
                    } elseif (str_starts_with($digits, '880') && strlen($digits) === 13) {
                        $mobile = substr($digits, 2);
                    } else {
                        $mobile = $digits;
                    }
                    continue;
                }

                if (preg_match('/house\s*(?:no\s*#?|#|\/)?\s*(.+)/i', $part, $m)) {
                    $house = trim($m[1]);
                    continue;
                }

                if (preg_match('/flat\s*(?:no\s*#?|#|\/)?\s*(.+)/i', $part, $m)) {
                    $val = trim($m[1]);
                    $flat = ($val === '.' || $val === '') ? '.' : $val;
                    continue;
                }

                if (preg_match('/\d{5,}/', $part) || str_contains($part, '#')) {
                    continue;
                }
                if (in_array(strtolower($part), ['digital', 'analog', 'd', 'a', 'active', 'inactive'])) {
                    continue;
                }

                $locationParts[] = $part;
            }

            // Match Area
            foreach ($masterAreas as $ma) {
                $cleanMA = strtolower(trim(preg_replace('/\s*[-_]?\s*\d+$/i', '', $ma->area)));
                foreach ($locationParts as $p) {
                    $cleanP = strtolower(trim(preg_replace('/\s*[-_]?\s*\d+$/i', '', $p)));
                    if ($cleanP === $cleanMA || strcasecmp($p, $ma->area) === 0) {
                        $area = $ma->area;
                        $matchedAreaId = $ma->id;
                        break 2;
                    }
                }
            }

            // Match SubArea (Building)
            foreach ($masterSubAreas as $sa) {
                foreach ($locationParts as $p) {
                    if (strcasecmp($p, $sa->sub_area) === 0) {
                        $building = $sa->sub_area;
                        $matchedBuildingId = $sa->id;
                        if (!$matchedAreaId && $sa->area_id) {
                            $matchedAreaId = $sa->area_id;
                            if ($sa->area) $area = $sa->area->area;
                        }
                        break 2;
                    }
                }
            }

            if (!$area && count($locationParts) > 0) {
                $candidate = end($locationParts);
                if (!preg_match('/\d{5,}/', $candidate) && !str_contains($candidate, '#')) {
                    $area = trim(preg_replace('/\s*[-_]?\s*\d+$/i', '', $candidate));
                }
            }

            if (!$building && count($locationParts) > 0) {
                $candidate = reset($locationParts);
                if (!preg_match('/\d{5,}/', $candidate) && !str_contains($candidate, '#')) {
                    $building = $candidate;
                }
            }

            $connType = stripos($rawAddress, 'digital') !== false ? 'D' : 'A';

            $customers[] = [
                'cusId' => $cusId,
                'name' => $name,
                'mobile' => $mobile,
                'area' => $area,
                'building' => $building,
                'house' => $house,
                'flat' => $flat,
                'area_id' => $matchedAreaId,
                'building_id' => $matchedBuildingId,
                'connType' => $connType,
                'billingType' => $billingType,
                'monthlyRent' => $monthlyRent,
                'totalDue' => $totalDue,
                'entryDate' => $entryDate,
                'rawAddress' => $rawAddress,
                'isExisting' => false,
            ];
            $cusIds[] = $cusId;
        }

        // Check which customers already exist in our database
        $existingMap = [];
        if (!empty($cusIds)) {
            $existingCusIds = \App\Models\Customer::whereIn('customer_id', $cusIds)->pluck('customer_id')->toArray();
            $existingMap = array_fill_keys(array_map('strtoupper', $existingCusIds), true);
        }

        $newCount = 0;
        $existingCount = 0;

        foreach ($customers as &$c) {
            if (isset($existingMap[strtoupper($c['cusId'])])) {
                $c['isExisting'] = true;
                $existingCount++;
            } else {
                $c['isExisting'] = false;
                $newCount++;
            }
        }
        unset($c);

        return [
            'success' => true,
            'from' => $fromDate,
            'to' => $toDate,
            'total' => count($customers),
            'new_count' => $newCount,
            'existing_count' => $existingCount,
            'customers' => $customers,
        ];
    }

    /**
     * Fetch security deposit or return details list within a date range from EasyEBilling (/security_deposit_details_list).
     *
     * @param string $fromDate 'd-m-Y' (e.g. 01-09-2026)
     * @param string $toDate 'd-m-Y' (e.g. 30-09-2026)
     * @param string $type 'deposit' or 'return'
     * @return array
     */
    public function getSecurityDepositList(string $fromDate, string $toDate, string $type = 'deposit'): array
    {
        $token = $this->login();
        $cookieJar = $this->getCookieJar();

        $response = Http::withOptions([
            'cookies' => $cookieJar,
            'verify' => false,
            'connect_timeout' => 5,
            'timeout' => 35,
            'headers' => [
                'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
                'Referer' => "{$this->baseUrl}/security_deposit_details_list",
            ],
        ])->asForm()->post("{$this->baseUrl}/security_deposit_details_list", [
            '_token' => $token,
            'country_id' => 'BD',
            'type' => $type,
            'from' => $fromDate,
            'to' => $toDate,
            'submitted' => 'submitted',
        ]);

        if (!$response->successful()) {
            if ($response->status() === 419 || $response->status() === 302) {
                $token = $this->login(true);
                $cookieJar = $this->getCookieJar();
                $response = Http::withOptions([
                    'cookies' => $cookieJar,
                    'verify' => false,
                    'connect_timeout' => 5,
                    'timeout' => 35,
                    'headers' => [
                        'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
                        'Referer' => "{$this->baseUrl}/security_deposit_details_list",
                    ],
                ])->asForm()->post("{$this->baseUrl}/security_deposit_details_list", [
                    '_token' => $token,
                    'country_id' => 'BD',
                    'type' => $type,
                    'from' => $fromDate,
                    'to' => $toDate,
                    'submitted' => 'submitted',
                ]);
            }

            if (!$response->successful()) {
                throw new \Exception("Failed to fetch security deposit list from EasyEBilling. HTTP Status: " . $response->status());
            }
        }

        return $this->parseSecurityDepositListHtml($response->body(), $type, $fromDate, $toDate);
    }

    /**
     * Parse HTML response from /security_deposit_details_list.
     */
    protected function parseSecurityDepositListHtml(string $html, string $type, string $fromDate, string $toDate): array
    {
        $doc = new \DOMDocument();
        libxml_use_internal_errors(true);
        $doc->loadHTML($html);
        libxml_clear_errors();

        $xpath = new \DOMXPath($doc);
        $rows = $xpath->query('//table[@id="data-table-1000-row"]//tbody/tr');

        // Pre-cache customer map
        $allCustomers = \App\Models\Customer::select('id', 'customer_id', 'full_name', 'area_name')->get();
        $custMap = [];
        foreach ($allCustomers as $c) {
            $raw = (string)$c->customer_id;
            $custMap[$raw] = $c;
            $custMap[strtoupper($raw)] = $c;
            $custMap['CAVB' . $raw] = $c;
            if (str_starts_with(strtoupper($raw), 'CAVB')) {
                $custMap[substr($raw, 4)] = $c;
            }
        }

        $items = [];
        $matchedCount = 0;
        $notFoundCount = 0;
        $totalAmount = 0;

        foreach ($rows as $r) {
            $tdList = $xpath->query('./td', $r);
            if ($tdList->length < 5) continue;

            $rowHtml = $doc->saveHTML($r);

            $cusId = trim($tdList->item(0)->textContent);
            $name = trim($tdList->item(1)->textContent);
            $address = trim($tdList->item(2)->textContent);
            $mobile = trim($tdList->item(3)->textContent);
            $rent = trim($tdList->item(5)->textContent);
            $totalDue = trim($tdList->item(8)->textContent);
            $amountRaw = trim($tdList->item($tdList->length - 1)->textContent);
            $amount = floatval(preg_replace('/[^0-9.]/', '', $amountRaw));

            // Extract date from comment: <!-- <td style="white-space: nowrap;">08-Sep-26</td> -->
            $date = null;
            if (preg_match('/<!--\s*<td[^>]*>(.*?)<\/td>\s*-->/is', $rowHtml, $m)) {
                $commentDate = trim($m[1]);
                $ts = strtotime($commentDate);
                if ($ts !== false) {
                    $date = date('Y-m-d', $ts);
                }
            }
            if (!$date) {
                // Fallback to toDate or fromDate
                $tsTo = strtotime($toDate);
                $date = $tsTo !== false ? date('Y-m-d', $tsTo) : date('Y-m-d');
            }

            $matchedCust = $custMap[$cusId] ?? null;

            if ($matchedCust) {
                $matchedCount++;
            } else {
                $notFoundCount++;
            }

            $totalAmount += $amount;

            $items[] = [
                'cus_id' => $cusId,
                'name' => $matchedCust ? $matchedCust->name : $name,
                'billing_name' => $name,
                'mobile' => $matchedCust ? $matchedCust->mobile : $mobile,
                'area' => $matchedCust ? $matchedCust->area : '',
                'address' => $address,
                'customer_data_id' => $matchedCust ? $matchedCust->id : null,
                'amount' => $amount,
                'date' => $date,
                'type' => $type,
                'rent' => $rent,
                'total_due' => $totalDue,
                'customer_not_found' => !$matchedCust,
            ];
        }

        return [
            'success' => true,
            'type' => $type,
            'from' => $fromDate,
            'to' => $toDate,
            'total' => count($items),
            'matched_count' => $matchedCount,
            'not_found_count' => $notFoundCount,
            'total_amount' => $totalAmount,
            'deposits' => $items,
        ];
    }

    /**
     * Fetch customer update bill page/form from EasyEBilling (/update_bill).
     *
     * @param string $customerId Customer PK (_id) or display ID (CAVB1234)
     * @return array
     */
    public function getCustomerBill(string $customerId): array
    {
        $pk = null;
        $cusId = trim($customerId);

        // If it's already a 24-character hexadecimal MongoDB ObjectId
        if (preg_match('/^[a-f\d]{24}$/i', $cusId)) {
            $pk = $cusId;
            $localCust = \App\Models\Customer::where('billing_pk', $pk)->first();
            if ($localCust) {
                $cusId = $localCust->customer_id;
            }
        } else {
            $localCust = \App\Models\Customer::where('customer_id', $cusId)->first();
            if ($localCust && !empty($localCust->billing_pk)) {
                $pk = $localCust->billing_pk;
            }
        }

        if (!$pk) {
            $customer = $this->searchCustomer($cusId);
            if (!$customer || empty($customer['pk'])) {
                return [
                    'success' => false,
                    'message' => "Customer '{$cusId}' not found in EasyEBilling.",
                    'bill' => null,
                    'meta' => null,
                ];
            }
            $pk = $customer['pk'];

            if (!empty($localCust) && empty($localCust->billing_pk)) {
                $localCust->update(['billing_pk' => $pk]);
            }
        }

        $token = $this->login();
        $cookieJar = $this->getCookieJar();

        $url = "{$this->baseUrl}/update_bill";

        $response = Http::withOptions([
            'cookies' => $cookieJar,
            'verify' => false,
            'headers' => [
                'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
                'Referer' => "{$this->baseUrl}/update_bill",
            ],
        ])->asForm()->post($url, [
            '_token' => $token,
            'search' => $pk,
        ]);

        $cookieJar->save($this->cookieFilePath);

        // Retry once on session expiration or redirect to login
        if ($response->status() === 419 || $response->status() === 401 || str_contains($response->body(), 'login')) {
            Log::warning("EasyEBilling session expired during update_bill fetch for PK: {$pk}. Re-authenticating...");
            $token = $this->login(true);
            $cookieJar = $this->getCookieJar();

            $response = Http::withOptions([
                'cookies' => $cookieJar,
                'verify' => false,
                'headers' => [
                    'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
                    'Referer' => "{$this->baseUrl}/update_bill",
                ],
            ])->asForm()->post($url, [
                '_token' => $token,
                'search' => $pk,
            ]);
            $cookieJar->save($this->cookieFilePath);
        }

        if (!$response->successful()) {
            return [
                'success' => false,
                'message' => "Failed to fetch update bill from EasyEBilling (Status {$response->status()}).",
                'bill' => null,
                'meta' => null,
            ];
        }

        return $this->parseUpdateBillResponse($response->body(), $pk, $cusId);
    }

    /**
     * Parse HTML returned by EasyEBilling /update_bill page.
     */
    protected function parseUpdateBillResponse(string $html, string $pk, string $cusId): array
    {
        $doc = new \DOMDocument();
        @$doc->loadHTML($html);
        $xpath = new \DOMXPath($doc);

        // Find customer update form
        $form = null;
        foreach ($xpath->query('//form') as $f) {
            if ($f->getAttribute('action') === '/update_bill' && $xpath->query('.//input[@name="customer_id"]', $f)->length > 0) {
                $form = $f;
                break;
            }
        }

        if (!$form) {
            return [
                'success' => false,
                'message' => "Update bill form not found in EasyEBilling response. Customer might not have an active invoice or billing cycle.",
                'bill' => null,
                'meta' => null,
            ];
        }

        $getVal = function ($name) use ($xpath, $form) {
            $node = $xpath->query('.//input[@name="' . $name . '"]', $form)->item(0);
            return $node ? $node->getAttribute('value') : '';
        };

        $bill = [
            'customer_id'       => $getVal('customer_id') ?: $pk,
            'monthly_rent'      => (float)($getVal('monthly_rent') ?: 0),
            'due'               => (float)($getVal('due') ?: 0),
            'additional_charge' => (float)($getVal('additional_charge') ?: 0),
            'discount'          => (float)($getVal('discount') ?: 0),
            'advance'           => (float)($getVal('advance') ?: 0),
            'vat'               => (float)($getVal('vat') ?: 0),
            'total'             => (float)($getVal('total') ?: 0),
            'note'              => $getVal('note') ?: '',
        ];

        $meta = [
            'customer_code'    => $cusId,
            'customer_name'    => '',
            'customer_address' => '',
            'issue_date'       => '',
            'due_date'         => '',
            'qr_code'          => '',
        ];

        $parent = $form->parentNode;
        if ($parent) {
            $parentHtml = $doc->saveHTML($parent);

            $qrImg = $xpath->query('.//img[contains(@src, "/users_files/qr/")]', $parent)->item(0);
            if ($qrImg) {
                $meta['qr_code'] = $qrImg->getAttribute('src');
            }

            if (preg_match('/ID\s*:\s*([A-Za-z0-9_-]+)/i', $parentHtml, $m)) {
                $meta['customer_code'] = trim($m[1]);
            }

            if (preg_match('/Issue\s+Date\s*:\s*([^\s<]+)/i', $parentHtml, $m)) {
                $meta['issue_date'] = trim($m[1]);
            }

            if (preg_match('/Due\s+Date\s*:\s*([^\s<]+)/i', $parentHtml, $m)) {
                $meta['due_date'] = trim($m[1]);
            }

            $h4 = $xpath->query('.//h4', $parent)->item(0);
            if ($h4) {
                $meta['customer_name'] = trim($h4->textContent);
            }

            $spans = $xpath->query('.//span', $parent);
            foreach ($spans as $sp) {
                $text = trim($sp->textContent);
                if (str_contains($text, 'House') || str_contains($text, 'Flat') || str_contains($text, 'Koloni')) {
                    $meta['customer_address'] = $text;
                    break;
                }
            }
        }

        return [
            'success' => true,
            'pk'      => $pk,
            'cus_id'  => $cusId,
            'bill'    => $bill,
            'meta'    => $meta,
        ];
    }

    /**
     * Submit updated billing information to EasyEBilling (/update_bill).
     * If only partial fields are provided (e.g. only 'advance' or 'monthly_rent'),
     * existing cloud bill values are automatically preserved for untouched fields.
     *
     * @param string $pk Customer internal MongoDB ObjectId
     * @param array $billData Array of bill fields
     * @param string|null $customerId Optional display customer ID (e.g. CAVB1352)
     * @return array
     */
    public function updateCustomerBill(string $pk, array $billData, ?string $customerId = null): array
    {
        // 1. Check if any standard bill fields are missing to preserve existing cloud values
        $standardFields = ['monthly_rent', 'due', 'additional_charge', 'discount', 'advance', 'vat'];
        $hasMissingFields = false;
        foreach ($standardFields as $f) {
            if (!array_key_exists($f, $billData)) {
                $hasMissingFields = true;
                break;
            }
        }

        // 2. Pre-fetch current active bill from EasyEBilling so untouched fields are never overwritten with 0
        if ($hasMissingFields) {
            $lookupId = $customerId ?: $pk;
            $currentBill = null;
            try {
                $existingBillRes = $this->getCustomerBill($lookupId);
                if (!empty($existingBillRes['success']) && !empty($existingBillRes['bill'])) {
                    $currentBill = $existingBillRes['bill'];
                }
            } catch (\Throwable $e) {
                Log::warning("EasyEBilling: Could not pre-fetch current bill for {$lookupId}: " . $e->getMessage());
            }

            // Local fallback if EasyEBilling form fetch was not available
            $localRecord = null;
            if (!$currentBill && $customerId) {
                $localRecord = \App\Models\CustomerMonthlyBilling::where('customer_id', $customerId)->latest()->first();
            }

            foreach ($standardFields as $f) {
                if (!array_key_exists($f, $billData)) {
                    if ($currentBill && isset($currentBill[$f])) {
                        $billData[$f] = (float)$currentBill[$f];
                    } elseif ($localRecord) {
                        if ($f === 'monthly_rent') {
                            $billData[$f] = (float)($localRecord->monthly_rent ?? 0);
                        } elseif ($f === 'due') {
                            $billData[$f] = (float)($localRecord->previous_dues ?? 0);
                        } elseif ($f === 'advance') {
                            $billData[$f] = (float)($localRecord->advance ?? 0);
                        } elseif ($f === 'discount') {
                            $billData[$f] = (float)($localRecord->discount ?? 0);
                        } else {
                            $billData[$f] = 0;
                        }
                    } else {
                        $billData[$f] = 0;
                    }
                }
            }

            if (!array_key_exists('note', $billData)) {
                $billData['note'] = $currentBill['note'] ?? '';
            }
        }

        $token = $this->login();
        $cookieJar = $this->getCookieJar();
        $url = "{$this->baseUrl}/update_bill";

        $monthlyRent = isset($billData['monthly_rent']) ? (float)$billData['monthly_rent'] : 0;
        $due = isset($billData['due']) ? (float)$billData['due'] : 0;
        $additional = isset($billData['additional_charge']) ? (float)$billData['additional_charge'] : 0;
        $discount = isset($billData['discount']) ? (float)$billData['discount'] : 0;
        $advance = isset($billData['advance']) ? (float)$billData['advance'] : 0;
        $vat = isset($billData['vat']) ? (float)$billData['vat'] : 0;

        // Recalculate sum & total according to EasyEBilling formula
        $sum = isset($billData['sum']) ? (float)$billData['sum'] : ($monthlyRent + $additional - $discount - $advance);
        $vatAmount = $vat > 0 ? (($monthlyRent + $additional) * $vat / 100) : 0;
        $total = isset($billData['total']) ? (float)$billData['total'] : ($sum + $vatAmount + $due);
        $note = $billData['note'] ?? '';

        $payload = [
            '_token'            => $token,
            'customer_id'       => $pk,
            'monthly_rent'      => $monthlyRent,
            'due'               => $due,
            'additional_charge' => $additional,
            'discount'          => $discount,
            'advance'           => $advance,
            'sum'               => $sum,
            'vat'               => $vat,
            'total'             => $total,
            'note'              => $note,
        ];

        $response = Http::withOptions([
            'cookies' => $cookieJar,
            'verify' => false,
            'headers' => [
                'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
                'Referer' => "{$this->baseUrl}/update_bill",
            ],
        ])->asForm()->post($url, $payload);

        $cookieJar->save($this->cookieFilePath);

        // Retry on expired session
        if ($response->status() === 419 || $response->status() === 401 || str_contains($response->body(), 'login')) {
            Log::warning("EasyEBilling session expired during update_bill POST for PK: {$pk}. Re-authenticating...");
            $token = $this->login(true);
            $cookieJar = $this->getCookieJar();
            $payload['_token'] = $token;

            $response = Http::withOptions([
                'cookies' => $cookieJar,
                'verify' => false,
                'headers' => [
                    'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
                    'Referer' => "{$this->baseUrl}/update_bill",
                ],
            ])->asForm()->post($url, $payload);
            $cookieJar->save($this->cookieFilePath);
        }

        $body = $response->body();

        if (stripos($body, 'Updated Successfully') !== false || stripos($body, 'information Updated') !== false) {
            Log::info("EasyEBilling: Bill successfully updated for PK {$pk}. Total: {$total}");
            return [
                'success' => true,
                'message' => 'Bill information Updated Successfully in EasyEBilling.',
                'bill'    => $payload,
            ];
        }

        // If status is 200 and doesn't contain explicit failure
        if ($response->successful() && !str_contains($body, 'alert-danger')) {
            Log::info("EasyEBilling: Bill update POST returned 200 for PK {$pk}.");
            return [
                'success' => true,
                'message' => 'Bill information submitted successfully to EasyEBilling.',
                'bill'    => $payload,
            ];
        }

        // Extract any error message if present
        $doc = new \DOMDocument();
        @$doc->loadHTML($body);
        $xpath = new \DOMXPath($doc);
        $danger = $xpath->query('//*[contains(@class, "alert-danger")]');
        $errorMsg = $danger->length > 0 ? trim($danger->item(0)->textContent) : 'Failed to update bill in EasyEBilling.';

        return [
            'success' => false,
            'message' => $errorMsg,
        ];
    }

    /**
     * Determine if an HTTP response represents an unauthenticated redirect / login page.
     */
    protected function isLoginPage($response): bool
    {
        if (!$response) {
            return true;
        }

        if ($response->status() === 419 || $response->status() === 401) {
            return true;
        }

        $body = $response->body();
        // The real login form on EasyEBilling has input fields for email and password
        return str_contains($body, 'name="password"') && (str_contains($body, 'name="email"') || str_contains($body, 'E-Mail Address'));
    }

    /**
     * Extract display Customer ID (e.g. CAVB1252) from EasyEBilling customer_info string.
     */
    public static function extractCustomerId(?string $customerInfo): ?string
    {
        if (empty($customerInfo)) {
            return null;
        }
        $parts = explode(',', $customerInfo);
        $first = trim($parts[0]);
        if (preg_match('/^([A-Z0-9_-]+)/i', $first, $m)) {
            return trim($m[1]);
        }
        return $first ?: null;
    }

    /**
     * Search customer list on EasyEBilling and return array of raw results.
     */
    public function searchCustomerList(string $term): array
    {
        $term = trim($term);
        if ($term === '') {
            return [];
        }

        $token = $this->login();
        $cookieJar = $this->getCookieJar();

        $response = Http::withOptions([
            'cookies' => $cookieJar,
            'verify' => false,
            'headers' => [
                'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
                'X-Requested-With' => 'XMLHttpRequest',
                'Referer' => "{$this->baseUrl}/customer",
            ],
        ])->get("{$this->baseUrl}/customer", [
            'search' => $term,
            '_token' => $token,
        ]);

        $cookieJar->save($this->cookieFilePath);

        if ($response->status() === 419 || $response->status() === 401 || str_contains($response->body(), 'login')) {
            $token = $this->login(true);
            $cookieJar = $this->getCookieJar();
            $response = Http::withOptions([
                'cookies' => $cookieJar,
                'verify' => false,
                'headers' => [
                    'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
                    'X-Requested-With' => 'XMLHttpRequest',
                ],
            ])->get("{$this->baseUrl}/customer", [
                'search' => $term,
                '_token' => $token,
            ]);
            $cookieJar->save($this->cookieFilePath);
        }

        $json = $response->json();
        return is_array($json) ? $json : [];
    }

    /**
     * Register a new customer on EasyEBilling via /create_customer.
     */
    public function registerCustomer(array $data): array
    {
        $token = $this->login();
        $cookieJar = $this->getCookieJar();
        $url = "{$this->baseUrl}/create_customer";

        // Clean & format 13-digit Bangladesh mobile with 88 prefix
        $mobile = preg_replace('/[^\d]/', '', (string)($data['mobile'] ?? ''));
        if (str_starts_with($mobile, '8801') && strlen($mobile) >= 13) {
            // already starts with 8801
        } elseif (str_starts_with($mobile, '01')) {
            $mobile = '88' . $mobile;
        } elseif (str_starts_with($mobile, '1') && strlen($mobile) === 10) {
            $mobile = '880' . $mobile;
        } elseif (!str_starts_with($mobile, '88') && !empty($mobile)) {
            $mobile = '88' . $mobile;
        }

        // Snapshot existing customer PKs for this mobile BEFORE creating new customer
        $beforeList = $this->searchCustomerList($mobile);
        $beforePks = array_filter(array_column($beforeList, '_id'));

        $category = strtoupper(trim((string)($data['category'] ?? 'A')));
        $isDigital = $category === 'D' || !empty($data['digital_service']);
        $isAnalog = !$isDigital;

        $baseRent = $isDigital ? 450 : 250;
        $monthlyRent = isset($data['monthly_rent']) && is_numeric($data['monthly_rent']) 
            ? (float)$data['monthly_rent'] 
            : $baseRent;

        $due = isset($data['due']) && is_numeric($data['due']) ? (float)$data['due'] : 0;
        $additional = isset($data['additional_charge']) && is_numeric($data['additional_charge']) ? (float)$data['additional_charge'] : 0;
        $discount = isset($data['discount']) && is_numeric($data['discount']) ? (float)$data['discount'] : 0;
        $advance = isset($data['advance']) && is_numeric($data['advance']) ? (float)$data['advance'] : 0;
        $vat = isset($data['vat']) && is_numeric($data['vat']) ? (float)$data['vat'] : 0;
        $sum = isset($data['sum']) && is_numeric($data['sum']) ? (float)$data['sum'] : ($monthlyRent + $additional - $discount - $advance);
        $total = isset($data['total']) && is_numeric($data['total']) 
            ? (float)$data['total'] 
            : ($sum + ($vat > 0 ? (($monthlyRent + $additional) * $vat / 100) : 0) + $due);

        $connDateRaw = !empty($data['connection_date_catv']) ? $data['connection_date_catv'] : date('Y-m-d');
        $connTs = strtotime($connDateRaw);
        $connDate = $connTs ? date('d-m-Y', $connTs) : date('d-m-Y');
        $expireDate = $connTs ? date('d-m-Y', strtotime('+1 month', $connTs)) : date('d-m-Y', strtotime('+1 month'));

        $area = strtolower(trim((string)($data['area'] ?? '')));
        $building = strtolower(trim((string)($data['building'] ?? '')));
        $houseNo = trim((string)($data['house_no'] ?? $data['house'] ?? ''));
        $flatNo = trim((string)($data['flat_no'] ?? $data['flat'] ?? ''));
        $roadNo = trim((string)($data['road_no'] ?? $data['road'] ?? ''));

        $stb = trim((string)($data['stb'] ?? ''));
        if ($stb === '' && !empty($data['complain_id']) && $isDigital && class_exists(\App\Models\Stb::class)) {
            $assignedStbs = \App\Models\Stb::where('complain_id', $data['complain_id'])
                ->get()
                ->map(fn($s) => !empty($s->stb_number) ? trim($s->stb_number) : trim((string)$s->id))
                ->filter()
                ->values()
                ->toArray();
            if (!empty($assignedStbs)) {
                $stb = implode('#', $assignedStbs);
            }
        }

        $payload = [
            '_token'                    => $token,
            'user_name'                 => trim((string)($data['user_name'] ?? $data['name'] ?? '')),
            'contact_person'            => trim((string)($data['contact_person'] ?? '')),
            'email'                     => trim((string)($data['email'] ?? '')),
            'identification_no'         => trim((string)($data['identification_no'] ?? '')),
            'mobile'                    => $mobile,
            'phone'                     => trim((string)($data['phone'] ?? '')),
            'professional_detail'       => trim((string)($data['professional_detail'] ?? '')),
            'country'                   => '608d60807d5d0542482448f8',
            'area'                      => $area,
            'building'                  => $building,
            'house_no'                  => $houseNo,
            'flat_no'                   => $flatNo,
            'road_no'                   => $roadNo,
            'customer_type'             => $isDigital ? 'digital' : '',
            'has_child'                 => '',
            'district'                  => strtolower(trim((string)($data['district'] ?? ''))),
            'thana'                     => strtolower(trim((string)($data['thana'] ?? ''))),
            'collection_zone'           => trim((string)($data['collection_zone'] ?? '')),
            'floor'                     => trim((string)($data['floor'] ?? '')),
            'stb'                       => $stb,
            'analog_service'            => $isAnalog ? '1' : '0',
            'digital_service'           => $isDigital ? '1' : '0',
            'connection_date_catv'      => $connDate,
            'number_of_connection_catv' => '1',
            'box'                       => '',
            'channel_package'           => '',
            'set_top_box_price'         => '',
            'auto_temporary_disable'    => '0',
            'expire_date'               => $expireDate,
            'monthly_rent'              => (string)$monthlyRent,
            'due'                       => (string)$due,
            'additional_charge'         => (string)$additional,
            'discount'                  => (string)$discount,
            'advance'                   => (string)$advance,
            'sum'                       => (string)$sum,
            'vat'                       => (string)$vat,
            'total'                     => (string)$total,
            'billing_type'              => $data['billing_type'] ?? 'post_paid',
            'user_type'                 => $data['user_type'] ?? 'home',
            'description'               => trim((string)($data['description'] ?? '')),
            'note'                      => trim((string)($data['note'] ?? '')),
            'connected_by'              => $data['connected_by'] ?? '608d60817d5d054248244907',
            'security_deposit'          => (string)($data['security_deposit'] ?? ($isDigital ? '1000' : '500')),
            'requested_from'            => 'catv',
        ];

        // Send POST to /create_customer with allow_redirects => false to reliably detect 302 vs 200
        $guzzleClient = new \GuzzleHttp\Client([
            'cookies' => $cookieJar,
            'verify' => false,
            'allow_redirects' => false,
            'headers' => [
                'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
                'Referer' => "{$this->baseUrl}/create_customer",
            ],
        ]);

        $response = $guzzleClient->request('POST', $url, [
            'form_params' => $payload,
        ]);
        $cookieJar->save($this->cookieFilePath);

        $statusCode = $response->getStatusCode();
        $body = (string)$response->getBody();

        // Check if session expired / redirected to login
        if ($statusCode === 419 || $statusCode === 401 || ($statusCode === 200 && str_contains($body, 'name="password"') && str_contains($body, 'name="email"'))) {
            Log::warning("EasyEBilling session expired during create_customer. Re-authenticating...");
            $token = $this->login(true);
            $cookieJar = $this->getCookieJar();
            $payload['_token'] = $token;

            $guzzleClient = new \GuzzleHttp\Client([
                'cookies' => $cookieJar,
                'verify' => false,
                'allow_redirects' => false,
                'headers' => [
                    'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
                    'Referer' => "{$this->baseUrl}/create_customer",
                ],
            ]);
            $response = $guzzleClient->request('POST', $url, [
                'form_params' => $payload,
            ]);
            $cookieJar->save($this->cookieFilePath);
            $statusCode = $response->getStatusCode();
            $body = (string)$response->getBody();
        }

        // On failure, EasyEBilling returns HTTP 200 with error alerts
        if ($statusCode === 200) {
            $doc = new \DOMDocument();
            @$doc->loadHTML($body);
            $xpath = new \DOMXPath($doc);
            $danger = $xpath->query('//*[contains(@class, "alert-danger") or contains(@class, "help-block") or contains(@class, "text-danger") or contains(@class, "alert-warning")]');
            $errorMsg = null;
            if ($danger->length > 0) {
                $errorMsg = trim($danger->item(0)->textContent);
            }
            if ($errorMsg && !str_contains($errorMsg, 'logout')) {
                Log::error("EasyEBilling create_customer validation error: {$errorMsg}");
                return [
                    'success' => false,
                    'message' => $errorMsg,
                ];
            }
        }

        // Search EasyEBilling for newly created customer (MUST be a newly added record not in $beforePks)
        $afterList = $this->searchCustomerList($mobile);
        $createdCustomer = null;

        if (!empty($afterList)) {
            // Pick customer whose _id was NOT in $beforePks
            foreach ($afterList as $cust) {
                if (!in_array($cust['_id'] ?? '', $beforePks, true)) {
                    $createdCustomer = $cust;
                    break;
                }
            }
        }

        // Fallback search by username if mobile search didn't yield, but ONLY accept customer whose _id is not in $beforePks
        if (!$createdCustomer && !empty($payload['user_name'])) {
            $nameList = $this->searchCustomerList($payload['user_name']);
            if (!empty($nameList)) {
                foreach ($nameList as $cust) {
                    if (!in_array($cust['_id'] ?? '', $beforePks, true)) {
                        $createdCustomer = $cust;
                        break;
                    }
                }
            }
        }

        if (!$createdCustomer || empty($createdCustomer['_id'])) {
            return [
                'success' => false,
                'message' => 'Customer creation could not be verified in EasyEBilling. Please check EasyEBilling directly.',
            ];
        }

        $rawInfo = $createdCustomer['customer_info'] ?? '';
        $cusId = self::extractCustomerId($rawInfo);
        $pk = $createdCustomer['_id'] ?? null;

        Log::info("EasyEBilling customer registered successfully: CusID: {$cusId}, PK: {$pk}, Info: {$rawInfo}");

        return [
            'success' => true,
            'message' => "New customer {$cusId} registered successfully in EasyEBilling!",
            'customer' => $createdCustomer,
            'pk' => $pk,
            'cus_id' => $cusId,
        ];
    }
}


