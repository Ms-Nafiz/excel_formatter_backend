<?php

namespace App\Http\Controllers;

use App\Models\ProcessedFile;
use App\Services\ExcelFormattingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class ExcelProcessingController extends Controller
{
    protected ExcelFormattingService $formattingService;

    public function __construct(ExcelFormattingService $formattingService)
    {
        $this->formattingService = $formattingService;
    }

    /**
     * Upload & Process Raw Excel/CSV File with custom Multi-Column Sort Rules
     */
    public function process(Request $request)
    {
        @ini_set('memory_limit', '1024M');
        @set_time_limit(300);

        $request->validate([
            'file' => 'required|file|mimes:xlsx,xls,csv,txt|max:25600', // max 25MB
            'sort_rules' => 'nullable',
        ]);

        $user = $request->user();
        $file = $request->file('file');
        $originalName = $file->getClientOriginalName();
        
        $billingMonth = trim((string)$request->input('billing_month'));
        if (empty($billingMonth)) {
            $billingMonth = \Carbon\Carbon::now()->format('F Y');
        }

        $timestamp = time();
        $meaningfulBase = $this->generateMeaningfulFileName($originalName, $billingMonth);
        $storedName = pathinfo($meaningfulBase, PATHINFO_FILENAME) . '_' . $timestamp . '.xlsx';
        
        Storage::disk('public')->makeDirectory('processed');
        $outputStoragePath = 'processed/' . $storedName;
        $fullOutputPath = Storage::disk('public')->path($outputStoragePath);

        $tempInputPath = $file->getRealPath();

        // Extract custom sort rules if provided by user
        $options = [];
        $rawSortRules = $request->input('sort_rules');
        if (is_string($rawSortRules)) {
            $rawSortRules = json_decode($rawSortRules, true);
        }
        if (!empty($rawSortRules) && is_array($rawSortRules)) {
            $options['sort_rules'] = $rawSortRules;
        }

        try {
            // Execute automated formatting, address parsing, and multi-column sorting engine
            $stats = $this->formattingService->processFile($tempInputPath, $fullOutputPath, $options);
            
            $fileSize = Storage::disk('public')->size($outputStoragePath);

            $record = ProcessedFile::create([
                'user_id' => $user->id,
                'original_name' => $originalName,
                'billing_month' => $billingMonth,
                'stored_name' => $storedName,
                'file_path' => $outputStoragePath,
                'row_count' => $stats['row_count'],
                'file_size' => $fileSize,
                'status' => 'completed',
                'formatting_options' => $stats,
            ]);

            // Synchronize rows into high-speed customer_records database table with billing_month tag
            $this->formattingService->syncDatabaseRecordsFromExcelFile($record->id, $fullOutputPath, $billingMonth);

            return response()->json([
                'message' => 'Excel file formatted, parsed & sorted successfully!',
                'data' => $record,
                'meaningful_name' => $meaningfulBase,
                'download_url' => $record->download_url,
                'stats' => $stats,
            ], 200);

        } catch (\Throwable $e) {
            $record = ProcessedFile::create([
                'user_id' => $user->id,
                'original_name' => $originalName,
                'stored_name' => $storedName,
                'file_path' => '',
                'row_count' => 0,
                'file_size' => 0,
                'status' => 'failed',
                'error_message' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'Failed to process Excel file: ' . $e->getMessage(),
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Fetch Upload & Formatting History for Auth User
     */
    public function history(Request $request)
    {
        $user = $request->user();
        $query = ProcessedFile::query();

        if ($user->isAdmin()) {
            $query->with('user:id,name,email');
        } else {
            $query->where('user_id', $user->id);
        }

        $history = $query->orderBy('created_at', 'desc')->paginate(15);

        return response()->json($history, 200);
    }

    /**
     * Fetch History Analytics & Aggregated Report Metrics
     */
    public function historyAnalytics(Request $request)
    {
        $user = $request->user();
        $query = ProcessedFile::query();

        if (!$user->isAdmin()) {
            $query->where('user_id', $user->id);
        }

        $files = $query->get();

        $totalFiles = $files->count();
        $totalRowsProcessed = $files->sum('row_count');
        $totalSizeBytes = $files->sum('file_size');
        $successCount = $files->where('status', 'completed')->count();
        $failedCount = $files->where('status', 'failed')->count();

        // Estimated hours saved (approx 1.5 hours per 1,000 rows manual formatting)
        $hoursSaved = round(($totalRowsProcessed / 1000) * 1.5, 1);

        return response()->json([
            'total_files' => $totalFiles,
            'total_rows' => $totalRowsProcessed,
            'total_rows_processed' => $totalRowsProcessed,
            'total_downloads' => $successCount,
            'total_file_size_formatted' => $totalSizeBytes,
            'success_count' => $successCount,
            'failed_count' => $failedCount,
            'hours_saved' => $hoursSaved,
            'success_rate' => $totalFiles > 0 ? round(($successCount / $totalFiles) * 100, 1) : 100,
        ], 200);
    }

    /**
     * Export Master History Audit Report as a Formatted Excel File
     */
    public function exportHistoryReport(Request $request)
    {
        $user = $request->user();
        $query = ProcessedFile::query();

        if ($user->isAdmin()) {
            $query->with('user:id,name,email');
        } else {
            $query->where('user_id', $user->id);
        }

        $files = $query->orderBy('created_at', 'desc')->get();

        $spreadsheet = new Spreadsheet();
        $spreadsheet->getDefaultStyle()->getFont()->setName('Noto Sans Bengali');
        $sheet = $spreadsheet->getActiveSheet();

        // Header Title Block
        $sheet->setCellValue('A1', 'EXCEL PROCESSING AUDIT & HISTORY REPORT');
        $sheet->mergeCells('A1:G1');
        $sheet->getStyle('A1')->applyFromArray([
            'font' => ['bold' => true, 'size' => 14, 'color' => ['rgb' => 'FFFFFF']],
            'fill' => ['fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID, 'startColor' => ['rgb' => '1E293B']],
            'alignment' => ['horizontal' => \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER],
        ]);

        // Table Headers
        $headers = [
            'ID',
            'Original File Name',
            'Status',
            'Rows Processed',
            'Formatted File Size',
            'Processed Date & Time',
            'Download URL'
        ];
        $sheet->fromArray([$headers], null, 'A3');

        $sheet->getStyle('A3:G3')->applyFromArray([
            'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
            'fill' => ['fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID, 'startColor' => ['rgb' => '334155']],
            'alignment' => ['horizontal' => \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER],
        ]);

        $rows = [];
        foreach ($files as $f) {
            $rows[] = [
                $f->id,
                $f->original_name,
                strtoupper($f->status),
                $f->row_count,
                $f->formatted_file_size,
                $f->created_at->format('Y-m-d H:i:s'),
                $f->status === 'completed' ? $f->download_url : 'N/A',
            ];
        }

        if (!empty($rows)) {
            $sheet->fromArray($rows, null, 'A4');
        }

        // Auto-fit columns
        for ($col = 1; $col <= 7; $col++) {
            $colLetter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($col);
            $sheet->getColumnDimension($colLetter)->setAutoSize(true);
        }

        Storage::disk('public')->makeDirectory('reports');
        $fileName = 'master_history_report_' . time() . '.xlsx';
        $filePath = 'reports/' . $fileName;
        $fullPath = Storage::disk('public')->path($filePath);

        $writer = new Xlsx($spreadsheet);
        $writer->save($fullPath);

        return response()->json([
            'message' => 'Master History Audit Report generated successfully!',
            'file_name' => $fileName,
            'download_url' => url(Storage::url($filePath)),
        ], 200);
    }

    /**
     * Fetch raw data matrix of a processed file from high-speed SQL database
     */
    public function getFileData(Request $request, $id)
    {
        $user = $request->user();
        $query = ProcessedFile::where('id', $id);
        if (!$user->isAdmin()) {
            $query->where('user_id', $user->id);
        }
        $record = $query->firstOrFail();

        $data = $this->formattingService->readProcessedFileDataFromDatabase($record->id);
        
        // Fallback to disk if database record empty
        if (empty($data['rows']) && Storage::disk('public')->exists($record->file_path)) {
            $fullPath = Storage::disk('public')->path($record->file_path);
            $data = $this->formattingService->readProcessedFileData($fullPath);
        }

        return response()->json([
            'file_id' => $record->id,
            'file_name' => $record->original_name,
            'data' => $data,
        ], 200);
    }

    /**
     * Save cell edits (e.g. status, bill/rent) directly into SQL database in < 5ms
     */
    public function updateFileData(Request $request)
    {
        $request->validate([
            'file_id' => 'required|integer',
            'updates' => 'required|array',
        ]);

        $user = $request->user();
        $query = ProcessedFile::where('id', $request->input('file_id'));
        if (!$user->isAdmin()) {
            $query->where('user_id', $user->id);
        }
        $record = $query->firstOrFail();

        $fullPath = Storage::disk('public')->exists($record->file_path)
            ? Storage::disk('public')->path($record->file_path)
            : null;

        $updates = $request->input('updates');

        $this->formattingService->updateProcessedFileDataInDatabase($record->id, $updates, $fullPath);

        return response()->json([
            'message' => 'Excel data updated successfully in database! Values recalculated.',
            'file_id' => $record->id,
        ], 200);
    }

    /**
     * Active Customer Summary Matrix (Customer Type & Category Wise)
     */
    public function getCustomerSummary(Request $request, $id = null)
    {
        $user = $request->user();
        $fileId = $id ? (int)$id : ($request->filled('file_id') ? (int)$request->input('file_id') : null);
        $billingMonth = $request->input('billing_month');

        $record = null;
        if ($fileId) {
            $query = ProcessedFile::where('id', $fileId);
            if (!$user->isAdmin()) {
                $query->where('user_id', $user->id);
            }
            $record = $query->first();
        }

        $summary = $this->formattingService->getActiveCustomerTypeCategorySummary(
            $fileId, 
            $billingMonth, 
            $user->isAdmin() ? null : $user->id
        );

        return response()->json([
            'file_id' => $record?->id,
            'file_name' => $record?->original_name,
            'billing_month' => $record?->billing_month ?? $billingMonth,
            'data' => $summary,
        ], 200);
    }

    /**
     * Fetch available target months for auth user
     */
    public function getTargetMonths(Request $request)
    {
        $user = $request->user();
        
        $fileQuery = ProcessedFile::where('status', 'completed')->whereNotNull('billing_month');
        $dbQuery = \App\Models\CustomerMonthlyBilling::whereNotNull('billing_month');

        if (!$user->isAdmin()) {
            $fileQuery->where('user_id', $user->id);
            $dbQuery->whereHas('processedFile', function($q) use ($user) {
                $q->where('user_id', $user->id);
            });
        }

        $uploadedMonths = $fileQuery->pluck('billing_month')
            ->unique()
            ->values()
            ->toArray();

        $dbMonths = $dbQuery->pluck('billing_month')
            ->unique()
            ->values()
            ->toArray();

        $activeDataMonths = array_values(array_unique(array_merge($uploadedMonths, $dbMonths)));

        $defaultMonths = [];
        $currentDate = now();
        for ($i = 2; $i >= -14; $i--) {
            $defaultMonths[] = $currentDate->copy()->addMonths($i)->format('F Y');
        }

        $combinedMonths = array_values(array_unique(array_merge($activeDataMonths, $defaultMonths)));

        $latestFile = ProcessedFile::where('user_id', $user->id)
            ->where('status', 'completed')
            ->latest()
            ->first();

        $currentSelected = $latestFile ? $latestFile->billing_month : ($activeDataMonths[0] ?? now()->format('F Y'));

        return response()->json([
            'current_month' => $currentSelected,
            'months' => $combinedMonths,
            'active_data_months' => $activeDataMonths,
        ], 200);
    }

    /**
     * Generate Collector & Customer Type Monthly Target Report for specific Month
     */
    public function generateCollectorTargetReport(Request $request)
    {
        $user = $request->user();
        
        $inputPath = null;
        $requestedMonth = trim((string)$request->input('billing_month'));

        if ($request->hasFile('file')) {
            $file = $request->file('file');
            $inputPath = $file->getRealPath();
        } elseif ($request->filled('file_id')) {
            $fQuery = ProcessedFile::where('id', $request->input('file_id'));
            if (!$user->isAdmin()) {
                $fQuery->where('user_id', $user->id);
            }
            $record = $fQuery->first();
            if ($record && Storage::disk('public')->exists($record->file_path)) {
                $inputPath = Storage::disk('public')->path($record->file_path);
                $requestedMonth = $record->billing_month;
            }
        } elseif (!empty($requestedMonth)) {
            // Find file specifically matching requested month
            $fQuery = ProcessedFile::where('status', 'completed')
                ->where('billing_month', $requestedMonth);
            if (!$user->isAdmin()) {
                $fQuery->where('user_id', $user->id);
            }
            $record = $fQuery->latest()->first();

            if (!$record) {
                // Case insensitive or partial month matching
                $fAllQuery = ProcessedFile::where('status', 'completed');
                if (!$user->isAdmin()) {
                    $fAllQuery->where('user_id', $user->id);
                }
                $userFiles = $fAllQuery->latest()->get();
                foreach ($userFiles as $uf) {
                    if (strcasecmp(trim($uf->billing_month), $requestedMonth) === 0 ||
                        stripos($uf->billing_month, $requestedMonth) !== false ||
                        stripos($requestedMonth, $uf->billing_month) !== false) {
                        $record = $uf;
                        break;
                    }
                }
            }

            if ($record && Storage::disk('public')->exists($record->file_path)) {
                $inputPath = Storage::disk('public')->path($record->file_path);
            }
        } else {
            // Default to latest completed file if no month passed
            $fLatestQuery = ProcessedFile::where('status', 'completed');
            if (!$user->isAdmin()) {
                $fLatestQuery->where('user_id', $user->id);
            }
            $record = $fLatestQuery->latest()->first();

            if ($record && Storage::disk('public')->exists($record->file_path)) {
                $inputPath = Storage::disk('public')->path($record->file_path);
                $requestedMonth = $record->billing_month;
            }
        }

        // 1. Check if SQL database has active customer records for this month
        if (!empty($requestedMonth)) {
            $dbRecordsQuery = \App\Models\CustomerMonthlyBilling::where('billing_month', $requestedMonth)
                ->where('status', '!=', 'Inactive');
            if (!$user->isAdmin()) {
                $dbRecordsQuery->whereHas('processedFile', function($q) use ($user) {
                    $q->where('user_id', $user->id);
                });
            }
            $dbRecords = $dbRecordsQuery->get();

            if ($dbRecords->count() > 0) {
                $analogStats = [];
                $digitalStats = [];

                foreach ($dbRecords as $rec) {
                    $collectorName = $rec->collector_name ?: 'Unassigned / Unmapped';
                    $customerType = $rec->customer_type ?: 'Analog';
                    $rent = (float) $rec->monthly_rent;
                    $adv = (float) $rec->advance;
                    $dues = (float) $rec->previous_dues;
                    $discount = (float) ($rec->discount ?? 0.0);
                    $effectiveAdv = $adv + $discount;
                    $actualBill = max(0.0, $rent - $effectiveAdv);
                    $fiftyPercent = $dues * 0.5;
                    $target = $actualBill + $fiftyPercent;

                    $isDigital = (stripos($customerType, 'digital') !== false);
                    $targetArr = &$analogStats;
                    if ($isDigital) {
                        $targetArr = &$digitalStats;
                    }

                    if (!isset($targetArr[$collectorName])) {
                        $targetArr[$collectorName] = [
                            'collector_name' => $collectorName,
                            'count_of_id' => 0,
                            'sum_of_rent' => 0.0,
                            'sum_of_due' => 0.0,
                            'sum_of_advnc' => 0.0,
                            'sum_of_actual_bill' => 0.0,
                            'sum_of_50' => 0.0,
                            'sum_of_target' => 0.0,
                        ];
                    }

                    $targetArr[$collectorName]['count_of_id']++;
                    $targetArr[$collectorName]['sum_of_rent'] += $rent;
                    $targetArr[$collectorName]['sum_of_due'] += $dues;
                    $targetArr[$collectorName]['sum_of_advnc'] += $effectiveAdv;
                    $targetArr[$collectorName]['sum_of_actual_bill'] += $actualBill;
                    $targetArr[$collectorName]['sum_of_50'] += $fiftyPercent;
                    $targetArr[$collectorName]['sum_of_target'] += $target;
                }

                ksort($analogStats);
                ksort($digitalStats);

                $fileName = null;
                $downloadUrl = null;

                if ($inputPath) {
                    try {
                        Storage::disk('public')->makeDirectory('reports');
                        $fileName = 'monthly_collector_target_report_' . time() . '.xlsx';
                        $outputStoragePath = 'reports/' . $fileName;
                        $fullOutputPath = Storage::disk('public')->path($outputStoragePath);

                        $this->formattingService->generateTargetReport($inputPath, $fullOutputPath, $requestedMonth);
                        $downloadUrl = url(Storage::url($outputStoragePath));
                    } catch (\Throwable $e) {
                    }
                }

                return response()->json([
                    'message' => "Target Report for {$requestedMonth} generated from active customer records.",
                    'has_data' => true,
                    'file_name' => $fileName,
                    'download_url' => $downloadUrl,
                    'stats' => [
                        'month_label' => "Target for the month of {$requestedMonth}",
                        'analog_stats' => array_values($analogStats),
                        'digital_stats' => array_values($digitalStats),
                    ],
                ], 200);
            }
        }

        // If no file and no DB records for requested month, return clear notice response
        if (!$inputPath) {
            $monthTitle = $requestedMonth ?: 'selected month';
            return response()->json([
                'message' => "No Excel billing records found for '{$monthTitle}'. Please upload a spreadsheet for {$monthTitle} to generate its target report.",
                'has_data' => false,
                'requested_month' => $monthTitle,
                'stats' => [
                    'month_label' => "Target for the month of {$monthTitle}",
                    'analog_stats' => [],
                    'digital_stats' => [],
                ],
            ], 200);
        }

        Storage::disk('public')->makeDirectory('reports');
        $fileName = 'monthly_collector_target_report_' . time() . '.xlsx';
        $outputStoragePath = 'reports/' . $fileName;
        $fullOutputPath = Storage::disk('public')->path($outputStoragePath);

        try {
            $stats = $this->formattingService->generateTargetReport($inputPath, $fullOutputPath, $requestedMonth);
            $downloadUrl = url(Storage::url($outputStoragePath));

            return response()->json([
                'message' => "Target Report for {$requestedMonth} generated successfully!",
                'has_data' => true,
                'file_name' => $fileName,
                'download_url' => $downloadUrl,
                'stats' => $stats,
            ], 200);

        } catch (\Throwable $e) {
            return response()->json([
                'message' => 'Failed to generate target report: ' . $e->getMessage(),
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Generate a clean, structured, and meaningful download filename
     */
    private function generateMeaningfulFileName(string $originalName, ?string $billingMonth = null): string
    {
        $cleanName = pathinfo($originalName, PATHINFO_FILENAME);
        
        // Sanitize & enhance generic file names like book1, sheet1, data, input, sample, etc.
        if (preg_match('/^(book\d*|sheet\d*|data|input|file|sample|raw.*)$/i', trim($cleanName))) {
            $cleanName = 'Customer_Billing_Report';
        } else {
            $cleanName = Str::slug($cleanName, '_');
        }

        $monthStr = $billingMonth ? Str::slug($billingMonth, '_') : \Carbon\Carbon::now()->format('F_Y');
        
        return 'Formatted_' . $cleanName . '_' . $monthStr . '.xlsx';
    }

    /**
     * Download Formatted File
     */
    public function download(Request $request, $id)
    {
        $user = $request->user();
        $query = ProcessedFile::where('id', $id);
        if (!$user->isAdmin()) {
            $query->where('user_id', $user->id);
        }
        $record = $query->firstOrFail();

        if (!Storage::disk('public')->exists($record->file_path)) {
            return response()->json(['message' => 'File not found on storage server.'], 404);
        }

        $meaningfulFileName = $this->generateMeaningfulFileName($record->original_name, $record->billing_month);

        return Storage::disk('public')->download($record->file_path, $meaningfulFileName);
    }

    /**
     * Move History Record to Trash (Soft Delete)
     */
    public function destroy(Request $request, $id)
    {
        $user = $request->user();
        $query = ProcessedFile::where('id', $id);
        if (!$user->isAdmin()) {
            $query->where('user_id', $user->id);
        }
        $record = $query->firstOrFail();

        $record->delete();

        return response()->json(['message' => 'File moved to Trash / Recycle Bin.'], 200);
    }

    /**
     * List Soft-Deleted Trash Items
     */
    public function trash(Request $request)
    {
        $user = $request->user();
        $query = ProcessedFile::onlyTrashed();

        if ($user->isAdmin()) {
            $query->with('user:id,name,email');
        } else {
            $query->where('user_id', $user->id);
        }

        $trash = $query->orderBy('deleted_at', 'desc')->get();

        return response()->json($trash, 200);
    }

    /**
     * Restore Soft-Deleted Item Back to Active History
     */
    public function restore(Request $request, $id)
    {
        $user = $request->user();
        $query = ProcessedFile::onlyTrashed()->where('id', $id);
        if (!$user->isAdmin()) {
            $query->where('user_id', $user->id);
        }
        $record = $query->firstOrFail();

        $record->restore();

        return response()->json(['message' => 'File restored successfully back to active history!'], 200);
    }

    /**
     * Permanently Delete Item from Database & Storage Server
     */
    public function forceDelete(Request $request, $id)
    {
        $user = $request->user();
        $query = ProcessedFile::onlyTrashed()->where('id', $id);
        if (!$user->isAdmin()) {
            $query->where('user_id', $user->id);
        }
        $record = $query->firstOrFail();

        if ($record->file_path && Storage::disk('public')->exists($record->file_path)) {
            Storage::disk('public')->delete($record->file_path);
        }

        \App\Models\CustomerMonthlyBilling::where('processed_file_id', $record->id)->delete();
        $record->forceDelete();

        return response()->json(['message' => 'File permanently deleted from database and storage disk.'], 200);
    }

    /**
     * Generate 4,000-Row Sample Raw Excel File with Address & Rent/Adv/Dues Columns for Testing
     */
    public function generateSample(Request $request)
    {
        $rowCount = (int) $request->input('rows', 4000);
        if ($rowCount < 10) $rowCount = 10;
        if ($rowCount > 10000) $rowCount = 10000;

        $spreadsheet = new Spreadsheet();
        $spreadsheet->getDefaultStyle()->getFont()->setName('Noto Sans Bengali');
        $sheet = $spreadsheet->getActiveSheet();

        $headers = [
            'emp_id',
            'full_name',
            'customer_address',
            'joining_date',
            'monthly_rent',
            'advance_amount',
            'previous_dues',
            'phone_number'
        ];

        $sheet->fromArray([$headers], null, 'A1');

        $firstNames = ['John', 'Jane', 'Michael', 'Emily', 'David', 'Sarah', 'Alex', 'Rachel', 'Chris', 'Jessica'];
        $lastNames = ['Smith', 'Johnson', 'Williams', 'Brown', 'Jones', 'Garcia', 'Miller', 'Davis', 'Rodriguez', 'Martinez'];
        
        $houses = ['House No # 10', 'House # 45', 'House No # 12', 'H # 99', 'House No # 3', ''];
        $flats = ['Flat No # C', 'Flat # 4A', 'Flat No # 2B', 'Flat # 1A', 'F # 5', ''];
        $buildings = ['Banalata', 'Rose Villa', 'Sunset Tower', 'Green House', 'Imperial Heights', ''];
        $areas = ['Banani-2', 'Banani', 'Canbazar-A', 'Canbazar Army-B', 'Staff Road-2', 'Zia Koloni'];
        $types = ['Digital', 'Analog'];

        $rows = [];
        for ($i = 1; $i <= $rowCount; $i++) {
            $fn = $firstNames[array_rand($firstNames)];
            $ln = $lastNames[array_rand($lastNames)];

            $houseStr = $houses[array_rand($houses)];
            $flatStr = $flats[array_rand($flats)];
            $buildingStr = $buildings[array_rand($buildings)];
            $areaStr = $areas[array_rand($areas)];
            $typeStr = $types[array_rand($types)];
            $accNo = rand(10000000000000, 99999999999999);

            $addrComponents = array_filter([$houseStr, $flatStr, $buildingStr, $areaStr, $typeStr, $accNo]);
            $rawAddress = implode(', ', $addrComponents);

            $rawDate = sprintf('202%d-%02d-%02d', rand(0, 6), rand(1, 12), rand(1, 28));
            $rawRent = rand(3000, 10000) + (rand(0, 99) / 100);
            $rawAdv = rand(0, 1) === 1 ? (rand(200, 1500) + (rand(0, 99) / 100)) : 0.0;
            $rawDues = rand(0, 1) === 1 ? (rand(500, 3000) + (rand(0, 99) / 100)) : 0.0;
            $rawPhone = sprintf('+1 (%03d) %03d-%04d ', rand(200, 999), rand(100, 999), rand(1000, 9999));

            $rows[] = [
                '100' . $i,
                ' ' . $fn . '  ' . $ln . ' ',
                $rawAddress,
                $rawDate,
                number_format($rawRent, 2, '.', ''),
                number_format($rawAdv, 2, '.', ''),
                number_format($rawDues, 2, '.', ''),
                $rawPhone,
            ];
        }

        $sheet->fromArray($rows, null, 'A2');

        Storage::disk('public')->makeDirectory('samples');
        $fileName = 'raw_sample_address_' . $rowCount . '_rows.xlsx';
        $filePath = 'samples/' . $fileName;
        $fullPath = Storage::disk('public')->path($filePath);

        $writer = new Xlsx($spreadsheet);
        $writer->save($fullPath);

        return response()->json([
            'message' => "Sample raw file with {$rowCount} rows & Rent/Advance/Dues data generated successfully!",
            'file_name' => $fileName,
            'download_url' => url(Storage::url($filePath)),
            'row_count' => $rowCount,
        ], 200);
    }

    /**
     * Search Customer Records directly from SQL Database by Customer ID / Name / Query
     */
    public function searchCustomers(Request $request)
    {
        $user = $request->user();
        $query = trim((string)$request->input('query'));
        $fileId = $request->filled('file_id') ? (int)$request->input('file_id') : null;
        $exactMatch = $request->boolean('exact', true);
        $collector = $request->filled('collector') ? trim((string)$request->input('collector')) : null;

        if (empty($query)) {
            return response()->json(['data' => []], 200);
        }

        $results = $this->formattingService->searchCustomerRecordsInDatabase(
            $user->isAdmin() ? null : $user->id,
            $query,
            $fileId,
            $exactMatch,
            $collector
        );

        return response()->json([
            'query' => $query,
            'exact' => $exactMatch,
            'collector' => $collector,
            'count' => count($results),
            'data' => $results,
        ], 200);
    }

    /**
     * Instant Update Customer Record directly by ID in SQL Database (< 5ms execution)
     */
    public function updateCustomer(Request $request)
    {
        $request->validate([
            'id' => 'required|integer',
            'changes' => 'required|array',
        ]);

        $recordId = (int)$request->input('id');
        $changes = $request->input('changes');

        $success = $this->formattingService->updateCustomerRecordById($recordId, $changes);

        if (!$success) {
            return response()->json(['message' => 'Customer record not found.'], 404);
        }

        return response()->json(['message' => 'Customer record updated successfully in database!'], 200);
    }

    /**
     * Fetch active dropdown lookup options for Buildings, Areas, and Collectors from SQL Database
     */
    public function getLookupOptions(Request $request)
    {
        // 1. Registered Buildings + Distinct building names in master customers
        $mBuildings = \App\Models\Building::pluck('name')->toArray();
        $dbBuildings = \App\Models\Customer::whereNotNull('building_name')
            ->where('building_name', '!=', '')
            ->pluck('building_name')
            ->toArray();
        $buildings = array_values(array_filter(array_unique(array_merge($mBuildings, $dbBuildings))));
        natcasesort($buildings);

        // 2. Registered Areas + Distinct area names in master customers
        $mAreas = \App\Models\Area::pluck('name')->toArray();
        $dbAreas = \App\Models\Customer::whereNotNull('area_name')
            ->where('area_name', '!=', '')
            ->pluck('area_name')
            ->toArray();
        $areas = array_values(array_filter(array_unique(array_merge($mAreas, $dbAreas))));
        natcasesort($areas);

        // 3. Registered Collectors + Distinct collector names in master customers + billings
        $mCollectors = \App\Models\Collector::pluck('name')->toArray();
        $dbCollectors = \App\Models\Customer::whereNotNull('collector_name')
            ->where('collector_name', '!=', '')
            ->where('collector_name', '!=', 'Unassigned / Unmapped')
            ->pluck('collector_name')
            ->toArray();
        $billCollectors = \App\Models\CustomerMonthlyBilling::whereNotNull('collector_name')
            ->where('collector_name', '!=', '')
            ->where('collector_name', '!=', 'Unassigned / Unmapped')
            ->pluck('collector_name')
            ->toArray();
        $collectors = array_values(array_filter(array_unique(array_merge($mCollectors, $dbCollectors, $billCollectors))));
        natcasesort($collectors);

        return response()->json([
            'buildings' => array_values($buildings),
            'areas' => array_values($areas),
            'collectors' => array_values($collectors),
        ], 200);
    }

    /**
     * Fetch edit audit history logs for a specific CustomerRecord ID
     */
    public function getCustomerHistory(Request $request, $recordId)
    {
        $histories = \App\Models\CustomerRecordHistory::where('customer_record_id', (int)$recordId)
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json([
            'record_id' => $recordId,
            'count' => $histories->count(),
            'histories' => $histories,
        ], 200);
    }

    /**
     * Fetch master system-wide edit history audit log
     */
    public function getAllHistoryLogs(Request $request)
    {
        $query = \App\Models\CustomerRecordHistory::query();

        if ($request->filled('search')) {
            $term = trim($request->search);
            $query->where(function($q) use ($term) {
                $q->where('customer_id', 'like', "%{$term}%")
                  ->orWhere('customer_name', 'like', "%{$term}%")
                  ->orWhere('field_name', 'like', "%{$term}%")
                  ->orWhere('old_value', 'like', "%{$term}%")
                  ->orWhere('new_value', 'like', "%{$term}%")
                  ->orWhere('edited_by', 'like', "%{$term}%");
            });
        }

        $logs = $query->orderBy('created_at', 'desc')->paginate(30);

        return response()->json($logs, 200);
    }

    /**
     * Fetch available update months and billing months for filtering
     */
    public function getCustomerUpdateMonths(Request $request)
    {
        $updateMonths = \App\Models\CustomerRecordHistory::selectRaw("DATE_FORMAT(created_at, '%M %Y') as month_name, DATE_FORMAT(created_at, '%Y-%m') as month_key, COUNT(*) as log_count")
            ->groupBy('month_name', 'month_key')
            ->orderBy('month_key', 'desc')
            ->get()
            ->map(function ($item) {
                return [
                    'label' => $item->month_name,
                    'key' => $item->month_key,
                    'count' => (int)$item->log_count,
                ];
            });

        $billingMonths = \App\Models\CustomerMonthlyBilling::whereNotNull('billing_month')
            ->where('billing_month', '!=', '')
            ->distinct()
            ->pluck('billing_month')
            ->toArray();

        $fileMonths = \App\Models\ProcessedFile::whereNotNull('billing_month')
            ->where('billing_month', '!=', '')
            ->distinct()
            ->pluck('billing_month')
            ->toArray();

        $allBilling = array_values(array_unique(array_filter(array_merge($billingMonths, $fileMonths))));
        natcasesort($allBilling);
        $allBilling = array_values(array_reverse($allBilling));

        return response()->json([
            'update_months' => $updateMonths,
            'billing_months' => $allBilling,
        ], 200);
    }

    /**
     * Fetch customer update history audit logs filtered by month, field, search with statistics
     */
    public function getCustomerUpdateReport(Request $request)
    {
        $month = trim((string)$request->input('month'));
        $filterBy = trim((string)$request->input('filter_by', 'update_month')); // 'update_month' | 'billing_month' | 'all'
        $field = trim((string)$request->input('field'));
        $search = trim((string)$request->input('search'));
        $latestOnly = $request->has('latest_only') ? $request->boolean('latest_only') : true;
        $perPage = (int)$request->input('per_page', 25);
        if ($perPage <= 0 || $perPage > 200) {
            $perPage = 25;
        }

        $query = \App\Models\CustomerRecordHistory::with(['customerRecord', 'processedFile']);

        // Filter by month
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

        // Filter by field_name
        if (!empty($field) && $field !== 'all') {
            $query->where('field_name', $field);
        }

        // Search query filter
        if (!empty($search)) {
            $query->where(function ($q) use ($search) {
                $q->where('customer_id', 'like', "%{$search}%")
                  ->orWhere('customer_name', 'like', "%{$search}%")
                  ->orWhere('field_name', 'like', "%{$search}%")
                  ->orWhere('old_value', 'like', "%{$search}%")
                  ->orWhere('new_value', 'like', "%{$search}%")
                  ->orWhere('edited_by', 'like', "%{$search}%");
            });
        }

        // Filter to only the most recent / latest change per customer ID & field name
        if ($latestOnly) {
            $subQuery = \DB::table('customer_record_histories as h_sub')
                ->selectRaw('MAX(h_sub.id) as max_id');

            if (!empty($month) && $month !== 'all') {
                if ($filterBy === 'billing_month') {
                    $subQuery->whereExists(function ($q) use ($month) {
                        $q->select(\DB::raw(1))
                          ->from('customer_records as cr_sub')
                          ->whereColumn('cr_sub.id', 'h_sub.customer_record_id')
                          ->where('cr_sub.billing_month', $month);
                    });
                } else {
                    if (preg_match('/^\d{4}-\d{2}$/', $month)) {
                        $subQuery->whereRaw("DATE_FORMAT(h_sub.created_at, '%Y-%m') = ?", [$month]);
                    } else {
                        $subQuery->whereRaw("DATE_FORMAT(h_sub.created_at, '%M %Y') = ?", [$month]);
                    }
                }
            }

            if (!empty($field) && $field !== 'all') {
                $subQuery->where('h_sub.field_name', $field);
            }

            $subQuery->groupBy(\DB::raw('COALESCE(h_sub.customer_id, h_sub.customer_record_id)'), 'h_sub.field_name');

            $query->whereIn('customer_record_histories.id', $subQuery);
        }

        // Stats calculation
        $totalUpdates = (clone $query)->count();
        $uniqueCustomers = (clone $query)->distinct('customer_id')->count('customer_id');

        $topFields = (clone $query)->without(['customerRecord', 'processedFile'])
            ->select('field_name', \DB::raw('count(*) as count'))
            ->groupBy('field_name')
            ->orderBy('count', 'desc')
            ->take(6)
            ->get();

        $topEditors = (clone $query)->without(['customerRecord', 'processedFile'])
            ->select('edited_by', \DB::raw('count(*) as count'))
            ->groupBy('edited_by')
            ->orderBy('count', 'desc')
            ->take(5)
            ->get();

        // Helper to attach edit counts and initial old value if latestOnly is active
        $attachEditStats = function ($items) use ($latestOnly) {
            if (!$latestOnly || empty($items)) {
                return;
            }
            $coll = collect($items);
            $keys = $coll->map(fn($it) => ($it->customer_id ?: $it->customer_record_id) . '___' . $it->field_name)->unique()->values();

            if ($keys->isNotEmpty()) {
                $pairStats = \DB::table('customer_record_histories as hs')
                    ->selectRaw("COALESCE(hs.customer_id, hs.customer_record_id) as cust_key, hs.field_name, COUNT(*) as edit_count, MIN(hs.id) as min_id")
                    ->whereIn(\DB::raw("CONCAT(COALESCE(hs.customer_id, hs.customer_record_id), '___', hs.field_name)"), $keys)
                    ->groupBy(\DB::raw('COALESCE(hs.customer_id, hs.customer_record_id)'), 'hs.field_name')
                    ->get();

                $minIds = $pairStats->pluck('min_id')->unique()->filter()->values()->toArray();
                $minRecords = !empty($minIds) ? \App\Models\CustomerRecordHistory::whereIn('id', $minIds)->get()->keyBy('id') : collect();
                $statMap = $pairStats->keyBy(fn($s) => $s->cust_key . '___' . $s->field_name);

                foreach ($items as $item) {
                    $key = ($item->customer_id ?: $item->customer_record_id) . '___' . $item->field_name;
                    $stat = $statMap->get($key);
                    $item->edit_count = $stat ? (int)$stat->edit_count : 1;
                    $minRec = $stat && isset($minRecords[$stat->min_id]) ? $minRecords[$stat->min_id] : null;
                    $item->initial_old_value = $minRec ? $minRec->old_value : $item->old_value;
                }
            }
        };

        // If all=true (for export or complete client-side view)
        if ($request->boolean('all')) {
            $logs = $query->orderBy('created_at', 'desc')->get();
            $attachEditStats($logs);

            return response()->json([
                'logs' => $logs,
                'total' => $totalUpdates,
                'latest_only' => $latestOnly,
                'stats' => [
                    'total_updates' => $totalUpdates,
                    'unique_customers' => $uniqueCustomers,
                    'top_fields' => $topFields,
                    'top_editors' => $topEditors,
                ],
            ], 200);
        }

        $paginated = $query->orderBy('created_at', 'desc')->paginate($perPage);
        $attachEditStats($paginated->items());

        return response()->json([
            'logs' => $paginated->items(),
            'current_page' => $paginated->currentPage(),
            'last_page' => $paginated->lastPage(),
            'per_page' => $paginated->perPage(),
            'total' => $paginated->total(),
            'latest_only' => $latestOnly,
            'stats' => [
                'total_updates' => $totalUpdates,
                'unique_customers' => $uniqueCustomers,
                'top_fields' => $topFields,
                'top_editors' => $topEditors,
            ],
        ], 200);
    }

    /**
     * Export Customer Update Audit Report for selected month to Excel (.xlsx)
     */
    public function exportCustomerUpdateReport(Request $request)
    {
        $month = trim((string)$request->input('month'));
        $filterBy = trim((string)$request->input('filter_by', 'update_month'));
        $field = trim((string)$request->input('field'));
        $search = trim((string)$request->input('search'));
        $latestOnly = $request->has('latest_only') ? $request->boolean('latest_only') : true;

        $query = \App\Models\CustomerRecordHistory::with(['customerRecord', 'processedFile']);

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

        if (!empty($search)) {
            $query->where(function ($q) use ($search) {
                $q->where('customer_id', 'like', "%{$search}%")
                  ->orWhere('customer_name', 'like', "%{$search}%")
                  ->orWhere('field_name', 'like', "%{$search}%")
                  ->orWhere('old_value', 'like', "%{$search}%")
                  ->orWhere('new_value', 'like', "%{$search}%")
                  ->orWhere('edited_by', 'like', "%{$search}%");
            });
        }

        if ($latestOnly) {
            $subQuery = \DB::table('customer_record_histories as h_sub')
                ->selectRaw('MAX(h_sub.id) as max_id');

            if (!empty($month) && $month !== 'all') {
                if ($filterBy === 'billing_month') {
                    $subQuery->whereExists(function ($q) use ($month) {
                        $q->select(\DB::raw(1))
                          ->from('customer_records as cr_sub')
                          ->whereColumn('cr_sub.id', 'h_sub.customer_record_id')
                          ->where('cr_sub.billing_month', $month);
                    });
                } else {
                    if (preg_match('/^\d{4}-\d{2}$/', $month)) {
                        $subQuery->whereRaw("DATE_FORMAT(h_sub.created_at, '%Y-%m') = ?", [$month]);
                    } else {
                        $subQuery->whereRaw("DATE_FORMAT(h_sub.created_at, '%M %Y') = ?", [$month]);
                    }
                }
            }

            if (!empty($field) && $field !== 'all') {
                $subQuery->where('h_sub.field_name', $field);
            }

            $subQuery->groupBy(\DB::raw('COALESCE(h_sub.customer_id, h_sub.customer_record_id)'), 'h_sub.field_name');

            $query->whereIn('customer_record_histories.id', $subQuery);
        }

        $logs = $query->orderBy('created_at', 'desc')->get();

        $spreadsheet = new Spreadsheet();
        $spreadsheet->getDefaultStyle()->getFont()->setName('Noto Sans Bengali');
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Update Audit Logs');

        $displayMonth = !empty($month) && $month !== 'all' ? $month : 'All Months';

        $sheet->setCellValue('A1', "CUSTOMER DATA UPDATE & EDIT AUDIT REPORT - {$displayMonth}");
        $sheet->mergeCells('A1:I1');
        $sheet->getStyle('A1')->applyFromArray([
            'font' => ['bold' => true, 'size' => 14, 'color' => ['rgb' => 'FFFFFF']],
            'fill' => ['fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID, 'startColor' => ['rgb' => '1E293B']],
            'alignment' => ['horizontal' => \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER],
        ]);

        $sheet->setCellValue('A2', "Filter Mode: " . ($filterBy === 'billing_month' ? "Billing Month" : "Update Date Month") . " | Generated: " . now()->toDateTimeString() . " | Total Records: " . $logs->count());
        $sheet->mergeCells('A2:I2');
        $sheet->getStyle('A2')->applyFromArray([
            'font' => ['italic' => true, 'color' => ['rgb' => 'FFFFFF']],
            'fill' => ['fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID, 'startColor' => ['rgb' => '334155']],
            'alignment' => ['horizontal' => \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER],
        ]);

        $headers = [
            'SL',
            'Edit Date & Time',
            'Customer ID',
            'Customer Name',
            'Field Changed',
            'Old Value',
            'New Value',
            'Edited By',
            'Billing Month',
        ];

        $sheet->fromArray([$headers], null, 'A4');
        $sheet->getStyle('A4:I4')->applyFromArray([
            'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
            'fill' => ['fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID, 'startColor' => ['rgb' => '0F172A']],
            'alignment' => ['horizontal' => \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER],
        ]);

        $rows = [];
        $sl = 1;
        foreach ($logs as $item) {
            $rec = $item->customerRecord;
            $billingM = $rec ? $rec->billing_month : ($item->processedFile?->billing_month ?? 'N/A');
            $rows[] = [
                $sl++,
                $item->created_at ? $item->created_at->format('Y-m-d h:i A') : 'N/A',
                $item->customer_id ?? 'N/A',
                $item->customer_name ?? 'N/A',
                $item->field_name,
                $item->old_value ?? '(empty)',
                $item->new_value ?? '(empty)',
                $item->edited_by ?? 'Admin',
                $billingM,
            ];
        }

        if (!empty($rows)) {
            $sheet->fromArray($rows, null, 'A5');
        }

        // Auto-fit columns
        for ($col = 1; $col <= 9; $col++) {
            $colLetter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($col);
            $sheet->getColumnDimension($colLetter)->setAutoSize(true);
        }

        Storage::disk('public')->makeDirectory('reports');
        $cleanMonth = Str::slug($displayMonth, '_');
        $fileName = 'customer_update_audit_report_' . $cleanMonth . '_' . time() . '.xlsx';
        $filePath = 'reports/' . $fileName;
        $fullPath = Storage::disk('public')->path($filePath);

        $writer = new Xlsx($spreadsheet);
        $writer->save($fullPath);

        return response()->json([
            'message' => 'Customer Update Audit Excel Report generated successfully!',
            'file_name' => $fileName,
            'download_url' => url(Storage::url($filePath)),
        ], 200);
    }

    /**
     * Compare two selected months to audit New Connections & Line Disconnections
     */
    public function getMonthlyComparisonReport(Request $request)
    {
        $user = $request->user();
        $baseMonth = trim((string)$request->input('base_month'));
        $compareMonth = trim((string)$request->input('compare_month'));

        if (empty($baseMonth) || empty($compareMonth)) {
            return response()->json([
                'message' => 'Please select both base month and compare month.',
            ], 422);
        }

        // Ensure Base Month (Month A) is always chronologically earlier than Compare Month (Month B)
        $timeBase = strtotime("1 " . $baseMonth);
        $timeCompare = strtotime("1 " . $compareMonth);
        if ($timeBase && $timeCompare && $timeBase > $timeCompare) {
            $temp = $baseMonth;
            $baseMonth = $compareMonth;
            $compareMonth = $temp;
        }

        $data = $this->formattingService->compareTwoMonthsCustomerRecords(
            $baseMonth, 
            $compareMonth, 
            $user->isAdmin() ? null : $user->id
        );

        return response()->json([
            'message' => "Comparison report between {$baseMonth} and {$compareMonth} generated successfully.",
            'data' => $data,
        ], 200);
    }

    /**
     * Export Excel Workbook for Monthly Connection Audit & Comparison Report
     */
    public function exportMonthlyComparisonReport(Request $request)
    {
        $user = $request->user();
        $baseMonth = trim((string)$request->input('base_month'));
        $compareMonth = trim((string)$request->input('compare_month'));

        if (empty($baseMonth) || empty($compareMonth)) {
            return response()->json(['message' => 'Both base_month and compare_month are required.'], 422);
        }

        // Ensure Base Month (Month A) is always chronologically earlier than Compare Month (Month B)
        $timeBase = strtotime("1 " . $baseMonth);
        $timeCompare = strtotime("1 " . $compareMonth);
        if ($timeBase && $timeCompare && $timeBase > $timeCompare) {
            $temp = $baseMonth;
            $baseMonth = $compareMonth;
            $compareMonth = $temp;
        }

        $data = $this->formattingService->compareTwoMonthsCustomerRecords(
            $baseMonth, 
            $compareMonth, 
            $user->isAdmin() ? null : $user->id
        );

        Storage::disk('public')->makeDirectory('reports');
        $fileName = 'monthly_connection_comparison_' . Str::slug($baseMonth, '_') . '_vs_' . Str::slug($compareMonth, '_') . '_' . time() . '.xlsx';
        $filePath = 'reports/' . $fileName;
        $fullPath = Storage::disk('public')->path($filePath);

        $this->formattingService->generateComparisonExcelReport($baseMonth, $compareMonth, $data, $fullPath);

        return response()->json([
            'message' => 'Monthly Connection Audit Excel Report generated successfully!',
            'file_name' => $fileName,
            'download_url' => url(Storage::url($filePath)),
        ], 200);
    }
}
