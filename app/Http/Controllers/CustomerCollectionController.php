<?php

namespace App\Http\Controllers;

use App\Models\Area;
use App\Models\Collector;
use App\Models\Customer;
use App\Models\CustomerCollection;
use App\Models\CustomerMonthlyBilling;
use App\Models\ProcessedFile;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CustomerCollectionController extends Controller
{
    /**
     * Download a clean, professionally formatted Sample / Demo Excel Template
     */
    public function downloadSampleTemplate()
    {
        $spreadsheet = new Spreadsheet();
        $spreadsheet->getDefaultStyle()->getFont()->setName('Noto Sans Bengali');
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Collection Template');

        // Header Title Banner
        $sheet->setCellValue('A1', 'CHITTAGONG COMMUNICATIONS LTD - CUSTOMER COLLECTION TEMPLATE');
        $sheet->mergeCells('A1:I1');
        $sheet->getStyle('A1')->applyFromArray([
            'font' => ['bold' => true, 'size' => 14, 'color' => ['rgb' => 'FFFFFF'], 'name' => 'Noto Sans Bengali'],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '0F172A']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
        ]);
        $sheet->getRowDimension(1)->setRowHeight(32);

        // Subtitle Instructions
        $sheet->setCellValue('A2', 'INSTRUCTIONS: "Customer ID", "Payment Date" and "Amount Paid" are mandatory. If "Collector Name" is blank, providing "Address" will automatically detect Area & assign the Collector!');
        $sheet->mergeCells('A2:I2');
        $sheet->getStyle('A2')->applyFromArray([
            'font' => ['italic' => true, 'size' => 10, 'color' => ['rgb' => 'E2E8F0'], 'name' => 'Noto Sans Bengali'],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '334155']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
        ]);
        $sheet->getRowDimension(2)->setRowHeight(22);

        // Row 3 is an empty spacing row
        $sheet->getRowDimension(3)->setRowHeight(8);

        // Column Headers (Row 4)
        $headers = [
            'Customer ID *',
            'Customer Name',
            'Address (Combined)',
            'Payment Date (YYYY-MM-DD) *',
            'Amount Paid (৳) *',
            'Payment Method',
            'Collector Name',
            'Money Receipt No',
            'Remarks'
        ];
        $sheet->fromArray([$headers], null, 'A4');

        $sheet->getStyle('A4:I4')->applyFromArray([
            'font' => ['bold' => true, 'size' => 11, 'color' => ['rgb' => 'FFFFFF'], 'name' => 'Noto Sans Bengali'],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '059669']], // Emerald
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
            'borders' => [
                'allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => '047857']],
            ],
        ]);
        $sheet->getRowDimension(4)->setRowHeight(26);

        // Sample Demo Data Rows (Rows 5 to 14)
        $demoRows = [
            ['CCL-1001', 'Md. Abdul Karim', 'House # 12, Banani, Digital', '2026-09-05', 500, 'Cash', 'Mr.Shimul Mahmud', 'MR-5001', 'Monthly bill paid'],
            ['CCL-1002', 'Kamal Uddin', 'House # 45, Staff Road, Analog', '2026-09-06', 1000, 'bKash', 'Mr.Esrafil Hossen', 'TRX-82910', 'Bill + Partial Dues'],
            ['CCL-1003', 'Mrs. Nasreen Akhter', 'Flat # 4B, Banani-2, Digital', '2026-09-07', 600, 'Cash', '', 'MR-5002', 'Auto-assigned to Mr.Eyamin from Address'],
            ['CCL-1004', 'Engr. Rafiqul Islam', 'House # 88, Nirjhor, Digital', '2026-09-08', 1200, 'Nagad', '', 'TRX-94821', 'Auto-assigned to Mr.Al-Amin from Address'],
            ['CCL-1005', 'Shah Alam', 'House # 10, Seena Polly, Digital', '2026-09-10', 500, 'Cash', '', 'MR-5003', 'Auto-assigned to Mr.Golam Kibria from Address'],
            ['CCL-1006', 'Dr. Mahmudul Hasan', 'House # 23, Canbazar-E, Analog', '2026-09-11', 800, 'Bank', 'Mr.Eklas', 'CHQ-40291', 'Cheque deposit'],
            ['CCL-1007', 'Tanvir Ahmed', 'House # 15, Mostofa Kamal, Digital', '2026-09-12', 500, 'Cash', '', 'MR-5004', 'Auto-assigned to Mr.Esrafil Hossen from Address'],
            ['CCL-1008', 'Jannatul Ferdous', 'House # 7, Zia Koloni, Analog', '2026-09-14', 600, 'bKash', 'Mr.Esrafil Hossen', 'TRX-55102', 'Online bKash payment'],
            ['CCL-1009', 'Siddiqur Rahman', 'Flat # 3A, Moinul Road, Digital', '2026-09-15', 750, 'Cash', '', 'MR-5005', 'Auto-assigned to Mr.Shimul Mahmud from Address'],
            ['CCL-1010', 'Mohammad Ali', 'House # 9, Rajonigondha, Digital', '2026-09-16', 500, 'Rocket', 'Mr.Eyamin', 'TRX-30192', 'Mobile Rocket payment'],
        ];

        $sheet->fromArray($demoRows, null, 'A5');

        // Style the demo rows
        $endRow = 4 + count($demoRows);
        $sheet->getStyle("A5:I{$endRow}")->applyFromArray([
            'font' => ['size' => 11, 'name' => 'Noto Sans Bengali'],
            'borders' => [
                'allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'CBD5E1']],
            ],
            'alignment' => ['vertical' => Alignment::VERTICAL_CENTER],
        ]);

        // Specific column alignments & number formats
        $sheet->getStyle("A5:A{$endRow}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $sheet->getStyle("D5:D{$endRow}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $sheet->getStyle("E5:E{$endRow}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
        $sheet->getStyle("E5:E{$endRow}")->getNumberFormat()->setFormatCode('#,##0.00');
        $sheet->getStyle("F5:F{$endRow}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $sheet->getStyle("H5:H{$endRow}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

        // Zebra striping on demo rows
        for ($r = 5; $r <= $endRow; $r++) {
            $sheet->getRowDimension($r)->setRowHeight(20);
            if ($r % 2 === 0) {
                $sheet->getStyle("A{$r}:I{$r}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('F8FAFC');
            }
        }

        // Auto-fit column widths with some breathing room
        foreach (range('A', 'I') as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }

        $writer = new Xlsx($spreadsheet);
        $fileName = 'Customer_Collection_Sample_Template.xlsx';

        return response()->streamDownload(function () use ($writer) {
            $writer->save('php://output');
        }, $fileName, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Cache-Control' => 'max-age=0, no-cache, no-store, must-revalidate',
            'Pragma' => 'no-cache',
        ]);
    }

    /**
     * Upload & Process Customer Collection Excel File
     */
    public function uploadExcel(Request $request)
    {
        @ini_set('memory_limit', '1024M');
        @set_time_limit(300);

        $request->validate([
            'file' => 'required|file|mimes:xlsx,xls,csv,txt|max:25600',
            'billing_month' => 'required|string',
            'upload_mode' => 'nullable|string|in:append,replace',
        ]);

        $file = $request->file('file');
        $billingMonth = trim($request->input('billing_month'));
        $uploadMode = $request->input('upload_mode', 'append');

        try {
            $reader = IOFactory::createReaderForFile($file->getRealPath());
            $reader->setReadDataOnly(true);
            $spreadsheet = $reader->load($file->getRealPath());
            $sheet = $spreadsheet->getActiveSheet();

            $highestRow = $sheet->getHighestRow();
            $highestColumn = $sheet->getHighestColumn();
            $highestColumnIndex = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($highestColumn);

            if ($highestRow < 2) {
                return response()->json([
                    'message' => 'The uploaded file appears to be empty or contains no data rows.'
                ], 422);
            }

            // Find Header Row: Scan first 10 rows to locate actual headers
            $headerRowIndex = 1;
            $headerMap = [];

            for ($r = 1; $r <= min(10, $highestRow); $r++) {
                $rowCols = [];
                for ($c = 1; $c <= $highestColumnIndex; $c++) {
                    $rawCell = trim((string)$sheet->getCellByColumnAndRow($c, $r)->getValue());
                    if (!empty($rawCell)) {
                        $cleanCell = strtolower(preg_replace('/[*()৳\-]/u', ' ', $rawCell));
                        $rowCols[$c] = trim($cleanCell);
                    }
                }

                // A header row must have at least 2 populated cells and not be a title/instruction banner
                if (count($rowCols) < 2) {
                    continue;
                }

                $combinedRowText = implode(' ', $rowCols);
                if (Str::contains($combinedRowText, ['instruction', 'template', 'chittagong communications'])) {
                    continue;
                }

                $hasId = false;
                $hasAmount = false;
                foreach ($rowCols as $v) {
                    if (Str::contains($v, ['customer id', 'customer_id', 'cust id', 'client id', 'id', 'emp id', 'code'])) {
                        $hasId = true;
                    }
                    if (Str::contains($v, ['amount', 'amount paid', 'paid', 'collection', 'taka', 'bill'])) {
                        $hasAmount = true;
                    }
                }

                if ($hasId && $hasAmount) {
                    $headerRowIndex = $r;
                    $headerMap = $rowCols;
                    break;
                }
            }

            // If strict (hasId && hasAmount) didn't find, accept if either found with at least 2 columns
            if (empty($headerMap)) {
                for ($r = 1; $r <= min(10, $highestRow); $r++) {
                    $rowCols = [];
                    for ($c = 1; $c <= $highestColumnIndex; $c++) {
                        $rawCell = trim((string)$sheet->getCellByColumnAndRow($c, $r)->getValue());
                        if (!empty($rawCell)) {
                            $cleanCell = strtolower(preg_replace('/[*()৳\-]/u', ' ', $rawCell));
                            $rowCols[$c] = trim($cleanCell);
                        }
                    }
                    if (count($rowCols) < 2) continue;
                    $combinedRowText = implode(' ', $rowCols);
                    if (Str::contains($combinedRowText, ['instruction', 'template', 'chittagong communications'])) continue;

                    foreach ($rowCols as $v) {
                        if (Str::contains($v, ['customer id', 'customer_id', 'cust id', 'client id', 'id', 'emp id', 'amount', 'paid'])) {
                            $headerRowIndex = $r;
                            $headerMap = $rowCols;
                            break 2;
                        }
                    }
                }
            }

            // Map Column Positions
            $colId = null;
            $colName = null;
            $colAddress = null;
            $colDate = null;
            $colAmount = null;
            $colMethod = null;
            $colCollector = null;
            $colReceipt = null;
            $colRemarks = null;

            foreach ($headerMap as $c => $h) {
                if ($colId === null && Str::contains($h, ['customer id', 'customer_id', 'cust id', 'client id', 'subscriber id', 'emp id', 'code', 'id'])) {
                    $colId = $c;
                } elseif ($colName === null && Str::contains($h, ['customer name', 'customer_name', 'full name', 'client name', 'name'])) {
                    $colName = $c;
                } elseif ($colAddress === null && Str::contains($h, ['address', 'add', 'location', 'customer address', 'full address'])) {
                    $colAddress = $c;
                } elseif ($colAmount === null && Str::contains($h, ['amount paid', 'amount_paid', 'collected amount', 'amount', 'paid', 'collection', 'taka', 'bill'])) {
                    $colAmount = $c;
                } elseif ($colDate === null && Str::contains($h, ['payment date', 'collection date', 'pay date', 'date', 'trx date', 'entry date'])) {
                    $colDate = $c;
                } elseif ($colMethod === null && Str::contains($h, ['payment method', 'payment_method', 'pay method', 'method', 'mode', 'type'])) {
                    $colMethod = $c;
                } elseif ($colCollector === null && Str::contains($h, ['collector name', 'collector_name', 'collector', 'collected by', 'agent'])) {
                    $colCollector = $c;
                } elseif ($colReceipt === null && Str::contains($h, ['receipt no', 'receipt_no', 'money receipt', 'receipt', 'mr no', 'mr_no', 'slip no', 'trx id', 'trxid', 'trx'])) {
                    $colReceipt = $c;
                } elseif ($colRemarks === null && Str::contains($h, ['remarks', 'remark', 'note', 'notes', 'comments', 'comment', 'desc'])) {
                    $colRemarks = $c;
                }
            }

            if ($colId === null && $colAmount === null) {
                // Fallback default: Col 1 = ID, Col 2 = Name, Col 3 = Date, Col 4 = Amount
                $colId = 1;
                $colName = 2;
                $colDate = 3;
                $colAmount = 4;
            }

            // Pre-fetch Master Customers for Instant Fast Linking
            $masterCustomers = Customer::select('id', 'customer_id', 'full_name', 'collector_name', 'customer_type', 'area_name', 'add_combined')
                ->get()
                ->keyBy(function ($c) {
                    return strtolower(trim((string)$c->customer_id));
                });

            // Load Database Areas & Active Collectors for Smart Address-to-Collector Routing
            $dbAreas = Area::pluck('name')->toArray();
            usort($dbAreas, fn($a, $b) => strlen($b) <=> strlen($a));

            $activeCollectors = Collector::with('areas')->where('status', 'active')->get();
            $areaCollectorMap = [];
            foreach ($activeCollectors as $ac) {
                foreach ($ac->areas as $ar) {
                    $areaCollectorMap[trim($ar->name)][] = $ac->name;
                }
            }

            $recordsToInsert = [];
            $totalAmount = 0.0;
            $skippedCount = 0;
            $now = Carbon::now();

            for ($r = $headerRowIndex + 1; $r <= $highestRow; $r++) {
                $rawId = $colId ? trim((string)$sheet->getCellByColumnAndRow($colId, $r)->getValue()) : '';

                // Skip Totals or blank rows
                if (empty($rawId) || strcasecmp($rawId, 'TOTALS') === 0 || strcasecmp($rawId, 'TOTAL') === 0) {
                    $skippedCount++;
                    continue;
                }

                // Extract Amount Paid
                $rawAmountVal = $colAmount ? $sheet->getCellByColumnAndRow($colAmount, $r)->getValue() : 0.0;
                $amountPaid = $this->parseNumericValue($rawAmountVal);

                if ($amountPaid <= 0.0) {
                    // Try to see if amount was 0 or just skip if completely invalid
                    $amountPaid = 0.0;
                }

                // Extract Payment Date
                $rawDateVal = $colDate ? $sheet->getCellByColumnAndRow($colDate, $r)->getValue() : null;
                $paymentDate = $this->parseDateValue($rawDateVal);

                // Extract Customer Name
                $custName = $colName ? trim((string)$sheet->getCellByColumnAndRow($colName, $r)->getValue()) : '';

                // Extract Raw Combined Address
                $rawAddress = $colAddress ? trim((string)$sheet->getCellByColumnAndRow($colAddress, $r)->getValue()) : '';

                // Extract Payment Method
                $payMethod = $colMethod ? trim((string)$sheet->getCellByColumnAndRow($colMethod, $r)->getValue()) : '';
                if (empty($payMethod)) {
                    $payMethod = 'Cash';
                }

                // Extract Collector Name (if present in file)
                $collectorName = $colCollector ? trim((string)$sheet->getCellByColumnAndRow($colCollector, $r)->getValue()) : '';

                // Extract Receipt No
                $receiptNo = $colReceipt ? trim((string)$sheet->getCellByColumnAndRow($colReceipt, $r)->getValue()) : '';

                // Extract Remarks
                $remarks = $colRemarks ? trim((string)$sheet->getCellByColumnAndRow($colRemarks, $r)->getValue()) : '';

                // Master Customer Cross-Referencing
                $idKey = strtolower($rawId);
                $master = $masterCustomers->get($idKey);
                $masterCustId = $master ? $master->id : null;
                $custType = $master ? $master->customer_type : null;
                $areaName = $master ? $master->area_name : null;

                if (empty($custName) && $master && !empty($master->full_name)) {
                    $custName = $master->full_name;
                }
                if (empty($collectorName) && $master && !empty($master->collector_name)) {
                    $collectorName = $master->collector_name;
                }
                if (empty($rawAddress) && $master && !empty($master->add_combined)) {
                    $rawAddress = $master->add_combined;
                }

                // SMART ADDRESS AUTO-DETECTION:
                // When Customer ID is not in DB or Collector is missing, parse Address to identify Area and mapped Collector!
                if (!empty($rawAddress)) {
                    $parsed = $this->parseAddressForCollector($rawAddress, $dbAreas);
                    if (empty($areaName) && !empty($parsed['area_name'])) {
                        $areaName = $parsed['area_name'];
                    }
                    if (empty($custType) && !empty($parsed['customer_type'])) {
                        $custType = $parsed['customer_type'];
                    }
                    if (empty($collectorName) && !empty($parsed['area_name']) && isset($areaCollectorMap[$parsed['area_name']])) {
                        $collectorName = implode(', ', array_unique($areaCollectorMap[$parsed['area_name']]));
                    }
                }

                if (empty($custType)) {
                    $custType = 'Analog';
                }
                if (empty($collectorName)) {
                    $collectorName = 'Unassigned';
                }

                $recordsToInsert[] = [
                    'master_customer_id' => $masterCustId,
                    'customer_id'        => $rawId,
                    'customer_name'      => $custName ?: null,
                    'customer_type'      => $custType,
                    'area_name'          => $areaName,
                    'address'            => $rawAddress ?: null,
                    'billing_month'      => $billingMonth,
                    'payment_date'       => $paymentDate,
                    'amount_paid'        => $amountPaid,
                    'payment_method'     => $payMethod,
                    'collector_name'     => $collectorName,
                    'receipt_no'         => $receiptNo ?: null,
                    'remarks'            => $remarks ?: null,
                    'created_at'         => $now,
                    'updated_at'         => $now,
                ];

                $totalAmount += $amountPaid;
            }

            if (empty($recordsToInsert)) {
                return response()->json([
                    'message' => 'No valid collection records could be found in the uploaded file.'
                ], 422);
            }

            // Save to database within Transaction
            DB::transaction(function () use ($uploadMode, $billingMonth, $recordsToInsert) {
                if ($uploadMode === 'replace') {
                    CustomerCollection::where('billing_month', $billingMonth)->delete();
                }

                foreach (array_chunk($recordsToInsert, 500) as $chunk) {
                    CustomerCollection::insert($chunk);
                }
            });

            return response()->json([
                'message' => 'Customer collection Excel file processed and saved successfully!',
                'billing_month' => $billingMonth,
                'upload_mode' => $uploadMode,
                'total_inserted' => count($recordsToInsert),
                'total_amount' => $totalAmount,
                'skipped_rows' => $skippedCount,
            ], 200);

        } catch (\Throwable $e) {
            return response()->json([
                'message' => 'Failed to process collection Excel file: ' . $e->getMessage(),
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * List Customer Collections with Filtering, Search & Aggregated Metrics
     */
    public function index(Request $request)
    {
        $billingMonth = $request->input('billing_month');
        $collector = $request->input('collector_name');
        $method = $request->input('payment_method');
        $search = trim((string)$request->input('search'));
        $perPage = (int)$request->input('per_page', 25);
        if ($perPage <= 0 || $perPage > 200) $perPage = 25;

        $query = CustomerCollection::query();

        if (!empty($billingMonth) && $billingMonth !== 'ALL') {
            $query->where('billing_month', $billingMonth);
        }

        if (!empty($collector) && $collector !== 'ALL') {
            $query->where('collector_name', $collector);
        }

        if (!empty($method) && $method !== 'ALL') {
            $query->where('payment_method', $method);
        }

        if (!empty($search)) {
            $query->where(function ($q) use ($search) {
                $q->where('customer_id', 'LIKE', "%{$search}%")
                  ->orWhere('customer_name', 'LIKE', "%{$search}%")
                  ->orWhere('address', 'LIKE', "%{$search}%")
                  ->orWhere('area_name', 'LIKE', "%{$search}%")
                  ->orWhere('receipt_no', 'LIKE', "%{$search}%")
                  ->orWhere('collector_name', 'LIKE', "%{$search}%")
                  ->orWhere('remarks', 'LIKE', "%{$search}%");
            });
        }

        // Calculate Aggregated Metrics on the current filtered dataset
        $statsQuery = clone $query;
        $totalCollected = (float)$statsQuery->sum('amount_paid');
        $totalCount = (int)$statsQuery->count();

        // Payment Method Breakdown
        $methodStats = (clone $query)
            ->select('payment_method', DB::raw('SUM(amount_paid) as total_amount'), DB::raw('COUNT(*) as count'))
            ->groupBy('payment_method')
            ->orderByDesc('total_amount')
            ->get();

        // Collector Breakdown
        $collectorStats = (clone $query)
            ->select('collector_name', DB::raw('SUM(amount_paid) as total_amount'), DB::raw('COUNT(*) as count'))
            ->groupBy('collector_name')
            ->orderByDesc('total_amount')
            ->get();

        // Paginated Collection Records
        $collections = $query->orderByDesc('payment_date')
            ->orderByDesc('id')
            ->paginate($perPage);

        return response()->json([
            'collections' => $collections,
            'metrics' => [
                'total_amount' => $totalCollected,
                'total_count' => $totalCount,
                'method_stats' => $methodStats,
                'collector_stats' => $collectorStats,
                'unique_collectors' => $collectorStats->count(),
            ],
        ], 200);
    }

    /**
     * Get distinct billing months available for collections
     */
    public function months()
    {
        $collMonths = CustomerCollection::whereNotNull('billing_month')
            ->where('billing_month', '!=', '')
            ->distinct()
            ->pluck('billing_month')
            ->toArray();

        $recordMonths = CustomerMonthlyBilling::whereNotNull('billing_month')
            ->where('billing_month', '!=', '')
            ->distinct()
            ->pluck('billing_month')
            ->toArray();

        $fileMonths = ProcessedFile::whereNotNull('billing_month')
            ->where('billing_month', '!=', '')
            ->distinct()
            ->pluck('billing_month')
            ->toArray();

        $currentMonth = Carbon::now()->format('F Y');

        $allMonths = array_unique(array_merge([$currentMonth], $collMonths, $recordMonths, $fileMonths));

        // Sort months chronologically if possible
        usort($allMonths, function ($a, $b) {
            $timeA = strtotime($a) ?: 0;
            $timeB = strtotime($b) ?: 0;
            return $timeB <=> $timeA;
        });

        // Also get unique collectors for dropdown
        $collectors = CustomerCollection::whereNotNull('collector_name')
            ->where('collector_name', '!=', '')
            ->distinct()
            ->orderBy('collector_name')
            ->pluck('collector_name')
            ->toArray();

        // Unique payment methods
        $methods = CustomerCollection::whereNotNull('payment_method')
            ->where('payment_method', '!=', '')
            ->distinct()
            ->orderBy('payment_method')
            ->pluck('payment_method')
            ->toArray();

        return response()->json([
            'months' => array_values($allMonths),
            'collectors' => array_values($collectors),
            'methods' => array_values($methods),
            'current_month' => $currentMonth,
        ], 200);
    }

    /**
     * Delete a single collection item
     */
    public function destroy($id)
    {
        $record = CustomerCollection::findOrFail($id);
        $record->delete();

        return response()->json([
            'message' => 'Collection record deleted successfully.',
            'id' => $id,
        ], 200);
    }

    /**
     * Clear all collections for a specific billing month
     */
    public function clearMonth(Request $request)
    {
        $request->validate([
            'billing_month' => 'required|string',
        ]);

        $month = trim($request->input('billing_month'));
        $deleted = CustomerCollection::where('billing_month', $month)->delete();

        return response()->json([
            'message' => "Successfully cleared {$deleted} collection records for {$month}.",
            'deleted_count' => $deleted,
        ], 200);
    }

    /**
     * Export filtered collections to Excel
     */
    public function export(Request $request)
    {
        $billingMonth = $request->input('billing_month');
        $collector = $request->input('collector_name');
        $method = $request->input('payment_method');
        $search = trim((string)$request->input('search'));

        $query = CustomerCollection::query();

        if (!empty($billingMonth) && $billingMonth !== 'ALL') {
            $query->where('billing_month', $billingMonth);
        }
        if (!empty($collector) && $collector !== 'ALL') {
            $query->where('collector_name', $collector);
        }
        if (!empty($method) && $method !== 'ALL') {
            $query->where('payment_method', $method);
        }
        if (!empty($search)) {
            $query->where(function ($q) use ($search) {
                $q->where('customer_id', 'LIKE', "%{$search}%")
                  ->orWhere('customer_name', 'LIKE', "%{$search}%")
                  ->orWhere('receipt_no', 'LIKE', "%{$search}%")
                  ->orWhere('collector_name', 'LIKE', "%{$search}%")
                  ->orWhere('remarks', 'LIKE', "%{$search}%");
            });
        }

        $records = $query->orderByDesc('payment_date')->orderByDesc('id')->get();

        $spreadsheet = new Spreadsheet();
        $spreadsheet->getDefaultStyle()->getFont()->setName('Noto Sans Bengali');
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Collection Report');

        $titleMonth = (!empty($billingMonth) && $billingMonth !== 'ALL') ? $billingMonth : 'All Months';
        $sheet->setCellValue('A1', "CUSTOMER COLLECTION LOGS - {$titleMonth}");
        $sheet->mergeCells('A1:I1');
        $sheet->getStyle('A1')->applyFromArray([
            'font' => ['bold' => true, 'size' => 14, 'color' => ['rgb' => 'FFFFFF']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '0F172A']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
        ]);
        $sheet->getRowDimension(1)->setRowHeight(28);

        $headers = ['SL', 'Customer ID', 'Customer Name', 'Address', 'Billing Month', 'Payment Date', 'Amount (৳)', 'Method', 'Collector', 'Receipt No', 'Remarks'];
        $sheet->fromArray([$headers], null, 'A3');
        $sheet->getStyle('A3:K3')->applyFromArray([
            'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '059669']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
        ]);

        $rows = [];
        $sl = 1;
        $totalAmount = 0;
        foreach ($records as $rec) {
            $dateStr = $rec->payment_date ? Carbon::parse($rec->payment_date)->format('Y-m-d') : '';
            $rows[] = [
                $sl++,
                $rec->customer_id,
                $rec->customer_name ?: 'N/A',
                $rec->address ?: '',
                $rec->billing_month,
                $dateStr,
                $rec->amount_paid,
                $rec->payment_method,
                $rec->collector_name,
                $rec->receipt_no ?: '',
                $rec->remarks ?: '',
            ];
            $totalAmount += $rec->amount_paid;
        }

        if (!empty($rows)) {
            $sheet->fromArray($rows, null, 'A4');
        }

        $lastRow = 3 + count($rows);
        $totalRow = $lastRow + 1;
        $sheet->setCellValue("F{$totalRow}", 'TOTAL:');
        $sheet->setCellValue("G{$totalRow}", $totalAmount);
        $sheet->getStyle("F{$totalRow}:G{$totalRow}")->applyFromArray([
            'font' => ['bold' => true],
            'borders' => ['top' => ['borderStyle' => Border::BORDER_THIN], 'bottom' => ['borderStyle' => Border::BORDER_DOUBLE]],
        ]);
        $sheet->getStyle("G4:G{$totalRow}")->getNumberFormat()->setFormatCode('#,##0.00');

        foreach (range('A', 'K') as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }

        $writer = new Xlsx($spreadsheet);
        $safeMonth = Str::slug($titleMonth);
        $fileName = "Collection_Report_{$safeMonth}.xlsx";

        return response()->streamDownload(function () use ($writer) {
            $writer->save('php://output');
        }, $fileName, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    /**
     * Smart Address parser to detect Area and Customer Type from combined address
     */
    private function parseAddressForCollector(string $address, array $areasList): array
    {
        $cleanAddr = trim($address);
        $normalizedAddr = preg_replace('/[^a-z0-9]/i', '', $cleanAddr);

        $areaName = '';
        foreach ($areasList as $aName) {
            if (stripos($cleanAddr, $aName) !== false) {
                $areaName = $aName;
                break;
            }
            $normArea = preg_replace('/[^a-z0-9]/i', '', $aName);
            if (!empty($normArea) && stripos($normalizedAddr, $normArea) !== false) {
                $areaName = $aName;
                break;
            }
        }

        $customerType = (stripos($cleanAddr, 'digital') !== false) ? 'Digital' : 'Analog';

        return [
            'area_name' => $areaName,
            'customer_type' => $customerType,
        ];
    }

    /**
     * Parse numeric values safely
     */
    private function parseNumericValue($value): float
    {
        if (is_numeric($value)) {
            return (float)$value;
        }
        if (empty($value)) {
            return 0.0;
        }
        $cleaned = preg_replace('/[^0-9.-]/', '', (string)$value);
        return is_numeric($cleaned) ? (float)$cleaned : 0.0;
    }

    /**
     * Parse date values (Excel serial number or date strings)
     */
    private function parseDateValue($value): ?string
    {
        if (empty($value)) {
            return Carbon::now()->format('Y-m-d H:i:s');
        }

        if (is_numeric($value)) {
            try {
                $dt = ExcelDate::excelToDateTimeObject((float)$value);
                return Carbon::instance($dt)->format('Y-m-d H:i:s');
            } catch (\Throwable $e) {
                // Ignore and try standard string parsing
            }
        }

        try {
            return Carbon::parse($value)->format('Y-m-d H:i:s');
        } catch (\Throwable $e) {
            return Carbon::now()->format('Y-m-d H:i:s');
        }
    }

    /**
     * Collector Target vs Collection Achievement Report
     */
    public function getAchievementReport(Request $request)
    {
        $billingMonth = trim((string)$request->input('billing_month'));

        $user = $request->user();

        // Fetch available months from ProcessedFiles, Collections, and CustomerRecords
        $pfMonths = ProcessedFile::where('status', 'completed')
            ->whereNotNull('billing_month')
            ->where('billing_month', '!=', '')
            ->when($user && !$user->isAdmin(), fn($q) => $q->where('user_id', $user->id))
            ->distinct()
            ->pluck('billing_month')
            ->toArray();

        $colMonths = CustomerCollection::whereNotNull('billing_month')
            ->where('billing_month', '!=', '')
            ->distinct()
            ->pluck('billing_month')
            ->toArray();

        $recMonths = CustomerMonthlyBilling::whereNotNull('billing_month')
            ->where('billing_month', '!=', '')
            ->distinct()
            ->pluck('billing_month')
            ->toArray();

        $availableMonths = array_values(array_unique(array_filter(array_merge($colMonths, $pfMonths, $recMonths))));

        // Sort months descending by parsed date
        usort($availableMonths, function ($a, $b) {
            try {
                $ta = Carbon::parse("01 " . $a)->timestamp;
                $tb = Carbon::parse("01 " . $b)->timestamp;
                return $tb <=> $ta;
            } catch (\Throwable $e) {
                return strcasecmp($b, $a);
            }
        });

        // If no month selected, pick latest month
        if (empty($billingMonth)) {
            $billingMonth = !empty($availableMonths) ? $availableMonths[0] : Carbon::now()->format('F Y');
        }

        // 1. Resolve active ProcessedFile for the requested billing month to avoid historical multi-upload duplication
        $fileQuery = ProcessedFile::where('status', 'completed')
            ->where(function ($q) use ($billingMonth) {
                $q->where('billing_month', $billingMonth)
                  ->orWhere('billing_month', 'LIKE', '%' . $billingMonth . '%');
            });

        if ($user && !$user->isAdmin()) {
            $fileQuery->where('user_id', $user->id);
        }

        $latestFile = $fileQuery->latest('id')->first();

        if (!$latestFile) {
            $latestFile = ProcessedFile::where('status', 'completed')
                ->where(function ($q) use ($billingMonth) {
                    $q->where('billing_month', $billingMonth)
                      ->orWhere('billing_month', 'LIKE', '%' . $billingMonth . '%');
                })
                ->latest('id')
                ->first();
        }

        $targetQuery = CustomerMonthlyBilling::query();
        if ($latestFile) {
            $targetQuery->where('processed_file_id', $latestFile->id);
        } else {
            // Fallback to highest processed_file_id containing records for this billing month
            $latestFileId = CustomerMonthlyBilling::where('billing_month', $billingMonth)->max('processed_file_id');
            if ($latestFileId) {
                $targetQuery->where('processed_file_id', $latestFileId);
            } else {
                $targetQuery->where('billing_month', $billingMonth);
            }
        }

        $targetRecords = $targetQuery
            ->where(function ($q) {
                $q->whereNull('status')->orWhere('status', '!=', 'Inactive');
            })
            ->select(
                'collector_name',
                DB::raw('COUNT(id) as total_customers'),
                DB::raw('SUM(monthly_rent) as sum_rent'),
                DB::raw('SUM(previous_dues) as sum_dues'),
                DB::raw('SUM(actual_bill) as sum_actual_bill'),
                DB::raw('SUM(fifty_percent) as sum_fifty_percent'),
                DB::raw('SUM(target) as sum_target')
            )
            ->groupBy('collector_name')
            ->get()
            ->keyBy(function ($item) {
                $name = trim((string)$item->collector_name);
                return empty($name) ? 'Unassigned / Unmapped' : $name;
            });

        // 2. Fetch Collection Metrics per Collector from customer_collections
        $collectionRecords = CustomerCollection::where('billing_month', $billingMonth)
            ->select(
                'collector_name',
                DB::raw('COUNT(id) as total_receipts'),
                DB::raw('SUM(amount_paid) as sum_collected')
            )
            ->groupBy('collector_name')
            ->get()
            ->keyBy(function ($item) {
                $name = trim((string)$item->collector_name);
                return empty($name) ? 'Unassigned / Unmapped' : $name;
            });

        // 3. Merge all unique collectors
        $allCollectors = array_unique(array_merge(
            $targetRecords->keys()->toArray(),
            $collectionRecords->keys()->toArray()
        ));

        // Sort alphabetically but put 'Unassigned' at end
        usort($allCollectors, function ($a, $b) {
            if (Str::contains(strtolower($a), 'unassigned')) return 1;
            if (Str::contains(strtolower($b), 'unassigned')) return -1;
            return strcasecmp($a, $b);
        });

        $collectorBreakdown = [];
        $grandTarget = 0.0;
        $grandCollected = 0.0;
        $grandCustomers = 0;
        $grandReceipts = 0;

        foreach ($allCollectors as $name) {
            $tObj = $targetRecords->get($name);
            $cObj = $collectionRecords->get($name);

            $target = $tObj ? (float)$tObj->sum_target : 0.0;
            $actualBill = $tObj ? (float)$tObj->sum_actual_bill : 0.0;
            $duesHalf = $tObj ? (float)$tObj->sum_fifty_percent : 0.0;
            $collected = $cObj ? (float)$cObj->sum_collected : 0.0;
            $custCount = $tObj ? (int)$tObj->total_customers : 0;
            $recCount = $cObj ? (int)$cObj->total_receipts : 0;

            $achievedPct = ($target > 0)
                ? round(($collected / $target) * 100, 2)
                : ($collected > 0 ? 100.0 : 0.0);

            $remaining = max(0.0, $target - $collected);
            $surplus = max(0.0, $collected - $target);

            // Performance Status classification
            $status = 'Needs Attention';
            $statusLabel = 'মনোযোগ প্রয়োজন (<৪০%)';
            if ($achievedPct >= 100.0) {
                $status = 'Achieved';
                $statusLabel = 'অর্জিত (≥১০০%)';
            } elseif ($achievedPct >= 70.0) {
                $status = 'On Track';
                $statusLabel = 'সন্তোষজনক (৭০-৯৯%)';
            } elseif ($achievedPct >= 40.0) {
                $status = 'In Progress';
                $statusLabel = 'চলমান (৪০-৬৯%)';
            }

            $collectorBreakdown[] = [
                'collector_name' => $name,
                'target_amount' => $target,
                'assigned_target' => $target,
                'target_actual_bill' => $actualBill,
                'target_dues_component' => $duesHalf,
                'collected_amount' => $collected,
                'total_collected' => $collected,
                'achievement_percentage' => $achievedPct,
                'achievement_rate' => $achievedPct,
                'remaining_amount' => $remaining,
                'surplus_amount' => $surplus,
                'customer_count' => $custCount,
                'receipt_count' => $recCount,
                'status' => $status,
                'status_label' => $statusLabel,
                'has_target_data' => ($tObj !== null),
                'has_collection_data' => ($cObj !== null),
            ];

            $grandTarget += $target;
            $grandCollected += $collected;
            $grandCustomers += $custCount;
            $grandReceipts += $recCount;
        }

        $grandPercentage = ($grandTarget > 0)
            ? round(($grandCollected / $grandTarget) * 100, 2)
            : ($grandCollected > 0 ? 100.0 : 0.0);

        $grandRemaining = max(0.0, $grandTarget - $grandCollected);
        $grandSurplus = max(0.0, $grandCollected - $grandTarget);

        // Find top performer
        $topPerformer = null;
        if (!empty($collectorBreakdown)) {
            $sortedByCol = $collectorBreakdown;
            usort($sortedByCol, fn($a, $b) => $b['collected_amount'] <=> $a['collected_amount']);
            if (!empty($sortedByCol) && $sortedByCol[0]['collected_amount'] > 0) {
                $topPerformer = [
                    'name' => $sortedByCol[0]['collector_name'],
                    'collector_name' => $sortedByCol[0]['collector_name'],
                    'achievement_rate' => $sortedByCol[0]['achievement_rate'],
                    'achievement_percentage' => $sortedByCol[0]['achievement_percentage'],
                    'collected' => $sortedByCol[0]['collected_amount'],
                    'collected_amount' => $sortedByCol[0]['collected_amount'],
                    'target' => $sortedByCol[0]['target_amount'],
                    'target_amount' => $sortedByCol[0]['target_amount'],
                ];
            }
        }

        return response()->json([
            'success' => true,
            'billing_month' => $billingMonth,
            'selected_month' => $billingMonth,
            'available_months' => $availableMonths,
            'summary' => [
                'total_target' => round($grandTarget, 2),
                'total_collected' => round($grandCollected, 2),
                'achievement_percentage' => $grandPercentage,
                'overall_achievement_rate' => $grandPercentage,
                'remaining_amount' => round($grandRemaining, 2),
                'total_remaining' => round($grandRemaining, 2),
                'surplus_amount' => round($grandSurplus, 2),
                'total_customers' => $grandCustomers,
                'total_receipts' => $grandReceipts,
                'total_collections_count' => $grandReceipts,
                'total_collectors' => count($collectorBreakdown),
                'top_performer' => $topPerformer,
            ],
            'collectors' => $collectorBreakdown,
            'data' => $collectorBreakdown,
        ], 200);
    }

    /**
     * Export Target vs Collection Achievement Report to Excel
     */
    public function exportAchievementReport(Request $request)
    {
        $billingMonth = trim((string)$request->input('billing_month'));
        if (empty($billingMonth)) {
            $billingMonth = CustomerMonthlyBilling::whereNotNull('billing_month')
                ->where('billing_month', '!=', '')
                ->orderByDesc('id')
                ->value('billing_month') ?? Carbon::now()->format('F Y');
        }

        $reportData = $this->getAchievementReport($request)->getData();
        $collectors = $reportData->collectors ?? [];
        $summary = $reportData->summary ?? null;

        $spreadsheet = new Spreadsheet();
        $spreadsheet->getDefaultStyle()->getFont()->setName('Noto Sans Bengali');
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Achievement Report');

        // Header Title Banner
        $sheet->setCellValue('A1', "COLLECTOR TARGET VS COLLECTION ACHIEVEMENT REPORT - {$billingMonth}");
        $sheet->mergeCells('A1:I1');
        $sheet->getStyle('A1')->applyFromArray([
            'font' => ['bold' => true, 'size' => 14, 'color' => ['rgb' => 'FFFFFF'], 'name' => 'Noto Sans Bengali'],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '0F172A']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
        ]);
        $sheet->getRowDimension(1)->setRowHeight(30);

        // Sub-banner Overall Summary
        $overallPct = $summary ? $summary->achievement_percentage : 0;
        $sheet->setCellValue('A2', "OVERALL TEAM ACHIEVEMENT: {$overallPct}% | Total Target: ৳" . number_format($summary->total_target ?? 0, 2) . " | Total Collected: ৳" . number_format($summary->total_collected ?? 0, 2));
        $sheet->mergeCells('A2:I2');
        $sheet->getStyle('A2')->applyFromArray([
            'font' => ['italic' => true, 'size' => 10, 'color' => ['rgb' => 'F1F5F9'], 'name' => 'Noto Sans Bengali'],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '1E293B']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
        ]);
        $sheet->getRowDimension(2)->setRowHeight(22);

        // Table Column Headers (Row 4)
        $headers = ['Rank', 'Collector Name', 'Customers', 'Receipts', 'Assigned Target (৳)', 'Collected Amount (৳)', 'Achievement (%)', 'Remaining Due (৳)', 'Performance Status'];
        $sheet->fromArray([$headers], null, 'A4');
        $sheet->getStyle('A4:I4')->applyFromArray([
            'font' => ['bold' => true, 'size' => 11, 'color' => ['rgb' => 'FFFFFF'], 'name' => 'Noto Sans Bengali'],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '059669']], // Emerald
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
        ]);
        $sheet->getRowDimension(4)->setRowHeight(26);

        $rows = [];
        $rank = 1;
        foreach ($collectors as $c) {
            $rows[] = [
                $rank++,
                $c->collector_name,
                $c->customer_count,
                $c->receipt_count,
                $c->target_amount,
                $c->collected_amount,
                $c->achievement_percentage . '%',
                $c->remaining_amount,
                $c->status,
            ];
        }

        if (!empty($rows)) {
            $sheet->fromArray($rows, null, 'A5');
        }

        $endRow = 4 + count($rows);
        $totalRow = $endRow + 1;

        // Grand Total Row
        $sheet->setCellValue("B{$totalRow}", 'GRAND TOTAL');
        $sheet->setCellValue("C{$totalRow}", $summary->total_customers ?? 0);
        $sheet->setCellValue("D{$totalRow}", $summary->total_receipts ?? 0);
        $sheet->setCellValue("E{$totalRow}", $summary->total_target ?? 0);
        $sheet->setCellValue("F{$totalRow}", $summary->total_collected ?? 0);
        $sheet->setCellValue("G{$totalRow}", ($summary->achievement_percentage ?? 0) . '%');
        $sheet->setCellValue("H{$totalRow}", $summary->remaining_amount ?? 0);
        $sheet->setCellValue("I{$totalRow}", ($summary->achievement_percentage >= 100) ? 'ACHIEVED' : 'IN PROGRESS');

        $sheet->getStyle("A{$totalRow}:I{$totalRow}")->applyFromArray([
            'font' => ['bold' => true, 'size' => 11],
            'borders' => [
                'top' => ['borderStyle' => Border::BORDER_THIN],
                'bottom' => ['borderStyle' => Border::BORDER_DOUBLE],
            ],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'F1F5F9']],
        ]);

        // Formats
        $sheet->getStyle("E5:F{$totalRow}")->getNumberFormat()->setFormatCode('#,##0.00');
        $sheet->getStyle("H5:H{$totalRow}")->getNumberFormat()->setFormatCode('#,##0.00');
        $sheet->getStyle("A5:A{$endRow}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $sheet->getStyle("C5:D{$totalRow}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $sheet->getStyle("G5:G{$totalRow}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $sheet->getStyle("I5:I{$totalRow}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

        foreach (range('A', 'I') as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }

        $writer = new Xlsx($spreadsheet);
        $safeMonth = Str::slug($billingMonth);
        $fileName = "Target_vs_Achievement_{$safeMonth}.xlsx";

        return response()->streamDownload(function () use ($writer) {
            $writer->save('php://output');
        }, $fileName, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }
}
