<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\CustomerMonthlyBilling;
use App\Models\CustomerRecordHistory;
use App\Services\EasyEBillingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class EasyEBillingController extends Controller
{
    /**
     * Test connection & authentication to EasyEBilling system
     */
    public function testConnection(EasyEBillingService $billingService)
    {
        try {
            $token = $billingService->login(true);
            return response()->json([
                'success' => true,
                'status' => 'connected',
                'message' => 'Connected successfully to EasyEBilling system!',
                'token_sample' => substr($token, 0, 10) . '...',
            ], 200);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'status' => 'disconnected',
                'message' => 'EasyEBilling connection failed: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Sync a single audit history log directly to EasyEBilling
     */
    public function syncAuditLog(Request $request, $id, EasyEBillingService $billingService)
    {
        $log = CustomerRecordHistory::with(['customerRecord', 'processedFile'])->findOrFail($id);

        try {
            $result = $billingService->syncAuditHistoryRecord($log);

            return response()->json([
                'success' => $result['success'],
                'message' => $result['message'],
                'log' => $log->fresh(),
            ], $result['success'] ? 200 : 422);
        } catch (\Throwable $e) {
            $log->update([
                'billing_sync_status' => 'failed',
                'billing_sync_error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Sync error: ' . $e->getMessage(),
                'log' => $log->fresh(),
            ], 500);
        }
    }

    /**
     * Bulk sync multiple audit history logs to EasyEBilling
     */
    public function syncAuditBulk(Request $request, EasyEBillingService $billingService)
    {
        $ids = $request->input('ids', []);
        $syncAllPending = $request->boolean('all_pending', false);
        $month = $request->input('month');
        $filterBy = $request->input('filter_by', 'update_month');
        $field = $request->input('field', 'all');

        if ($syncAllPending) {
            $query = CustomerRecordHistory::where('billing_sync_status', '!=', 'synced');

            if (!empty($month) && $month !== 'all') {
                if ($filterBy === 'billing_month') {
                    $query->whereHas('customerRecord', function ($q) use ($month) {
                        $q->where('billing_month', $month);
                    });
                } else {
                    if (preg_match('/^\d{4}-\d{2}$/', $month)) {
                        $query->whereRaw("DATE_FORMAT(created_at, '%Y-%m') = ?", [$month]);
                    } else {
                        $query->whereRaw("DATE_FORMAT(created_at, '%M %Y') = ?", [$month]);
                    }
                }
            }

            if (!empty($field) && $field !== 'all') {
                $query->where('field_name', $field);
            }

            // Cap at 200 per bulk operation to prevent PHP timeout
            $ids = $query->limit(200)->pluck('id')->toArray();
        }

        if (empty($ids) || !is_array($ids)) {
            return response()->json([
                'success' => false,
                'message' => 'No audit log records selected for syncing.',
                'results' => ['total' => 0, 'synced' => 0, 'failed' => 0, 'items' => []]
            ], 400);
        }

        try {
            $results = $billingService->syncAuditHistoryRecordsBulk($ids);

            return response()->json([
                'success' => $results['synced'] > 0 || $results['total'] === 0,
                'message' => "Bulk sync finished: {$results['synced']} succeeded, {$results['failed']} failed out of {$results['total']}.",
                'results' => $results,
            ], 200);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Bulk sync failed: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get real-time customer ledger summary & balance directly from EasyEBilling
     */
    public function getCustomerSummary(Request $request, $customerId, EasyEBillingService $billingService)
    {
        $customerId = trim((string)$customerId);
        if (empty($customerId)) {
            return response()->json(['success' => false, 'message' => 'Customer ID required'], 400);
        }

        try {
            $forceRefresh = $request->boolean('refresh', false);
            $cacheKey = 'eb_summary_' . md5($customerId);

            if (!$forceRefresh && Cache::has($cacheKey)) {
                $cached = Cache::get($cacheKey);
                if (!empty($cached['success'])) {
                    return response()->json($cached);
                }
            }

            $knownPk = $request->input('pk');
            if (!$knownPk) {
                $knownPk = Customer::where('customer_id', $customerId)->value('billing_pk');
            }

            $data = $billingService->getCustomerSummary($customerId, $knownPk);

            if (!empty($data['success'])) {
                // Enrich customer metadata with local database records
                $localCust = Customer::where('customer_id', $customerId)->first();
                if ($localCust) {
                    if (empty($data['customer_meta']['name'])) {
                        $data['customer_meta']['name'] = $localCust->full_name;
                    }
                    $data['customer_meta']['mobile'] = !empty($data['customer_meta']['mobile']) ? $data['customer_meta']['mobile'] : ($localCust->mobile ?? '');
                    $data['customer_meta']['area'] = !empty($data['customer_meta']['area']) ? $data['customer_meta']['area'] : ($localCust->area_name ?? '');
                    $data['customer_meta']['building'] = !empty($data['customer_meta']['building']) ? $data['customer_meta']['building'] : ($localCust->building_name ?? '');
                    $data['customer_meta']['house_no'] = !empty($data['customer_meta']['house_no']) ? $data['customer_meta']['house_no'] : ($localCust->house_no ?? '');
                    $data['customer_meta']['flat_no'] = !empty($data['customer_meta']['flat_no']) ? $data['customer_meta']['flat_no'] : ($localCust->flat_no ?? '');
                }

                // Calculate total debit and add aliases for frontend compatibility
                $totalDebit = 0;
                if (!empty($data['ledger']) && is_array($data['ledger'])) {
                    foreach ($data['ledger'] as &$item) {
                        if ($item['type'] === 'payment') {
                            $item['description'] = !empty($item['collector']) ? 'Collected by: ' . $item['collector'] : 'Payment Received';
                            $item['particulars'] = $item['description'];
                            $item['credit'] = (float)($item['paid_amount'] ?? 0);
                            $item['debit'] = 0;
                            $item['balance'] = (float)($item['total_due'] ?? 0);
                        } else {
                            $debitAmt = (float)(!empty($item['sum_with_vat']) ? $item['sum_with_vat'] : ($item['sum'] ?? $item['monthly_rent'] ?? 0));
                            $totalDebit += $debitAmt;
                            $item['description'] = 'Monthly Bill' . (!empty($item['monthly_rent']) ? " (Rent: ৳{$item['monthly_rent']})" : '');
                            $item['particulars'] = $item['description'];
                            $item['debit'] = $debitAmt;
                            $item['credit'] = 0;
                            $item['balance'] = (float)($item['due'] ?? 0);
                        }
                    }
                    unset($item);
                }

                if (!empty($data['totals'])) {
                    $curDue = (float)($data['totals']['current_due'] ?? 0);
                    $totPaid = (float)($data['totals']['total_paid'] ?? 0);
                    $data['totals']['current_balance'] = $curDue;
                    $data['totals']['total_credit'] = $totPaid;
                    $data['totals']['total_debit'] = $totalDebit;
                }

                Cache::put($cacheKey, $data, now()->addMinutes(10));
                if (!empty($data['customer_meta']['pk'])) {
                    Customer::where('customer_id', $customerId)->update(['billing_pk' => $data['customer_meta']['pk']]);
                    CustomerMonthlyBilling::where('customer_id', $customerId)->update(['billing_pk' => $data['customer_meta']['pk']]);
                }
            }

            return response()->json($data);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch customer summary: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get real-time customer security deposit history directly from EasyEBilling
     */
    public function getCustomerSecurityDeposit(Request $request, $customerId, EasyEBillingService $billingService)
    {
        $customerId = trim((string)$customerId);
        if (empty($customerId)) {
            return response()->json(['success' => false, 'message' => 'Customer ID required'], 400);
        }

        try {
            $forceRefresh = $request->boolean('refresh', false);
            $cacheKey = 'eb_deposit_' . md5($customerId);

            if (!$forceRefresh && Cache::has($cacheKey)) {
                $cached = Cache::get($cacheKey);
                if (!empty($cached['success'])) {
                    return response()->json($cached);
                }
            }

            $knownPk = $request->input('pk');
            if (!$knownPk) {
                $knownPk = Customer::where('customer_id', $customerId)->value('billing_pk');
            }

            $data = $billingService->getCustomerSecurityDeposit($customerId, $knownPk);

            if (!empty($data['success'])) {
                Cache::put($cacheKey, $data, now()->addMinutes(10));
            }

            return response()->json($data);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch security deposit: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Search customers live on EasyEBilling
     */
    public function searchLive(Request $request, EasyEBillingService $billingService)
    {
        $query = trim((string)$request->input('query'));
        if (empty($query)) {
            return response()->json(['success' => true, 'data' => []]);
        }

        try {
            $data = $billingService->searchCustomerList($query);
            return response()->json([
                'success' => true,
                'count' => count($data),
                'data' => $data,
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Live search failed: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Direct sync of customer record changes (e.g. from CustomerIdSearchEditor)
     */
    public function syncCustomerDirect(Request $request, EasyEBillingService $billingService)
    {
        $request->validate([
            'customer_id' => 'required|string',
            'changes' => 'required|array',
        ]);

        $customerId = trim((string)$request->input('customer_id'));
        $changes = $request->input('changes');
        $knownPk = $request->input('pk');

        if (!$knownPk) {
            $knownPk = Customer::where('customer_id', $customerId)->value('billing_pk');
        }

        $results = [];
        $hasError = false;

        foreach ($changes as $key => $val) {
            // Create a pseudo log to reuse the proven mapping logic
            $tempLog = new CustomerRecordHistory([
                'customer_id' => $customerId,
                'field_name' => $key,
                'new_value' => (string)$val,
                'billing_pk' => $knownPk,
            ]);

            $res = $billingService->syncAuditHistoryRecord($tempLog);
            $results[$key] = $res;
            if (empty($res['success'])) {
                $hasError = true;
            } elseif (!empty($res['pk'])) {
                $knownPk = $res['pk'];
            }
        }

        return response()->json([
            'success' => !$hasError,
            'customer_id' => $customerId,
            'pk' => $knownPk,
            'results' => $results,
            'message' => !$hasError ? "Customer {$customerId} successfully synced to EasyEBilling!" : "Some fields could not be synced.",
        ], !$hasError ? 200 : 422);
    }
}
