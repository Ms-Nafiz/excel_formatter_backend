<?php

namespace App\Services;

use App\Models\Area;
use App\Models\Building;
use App\Models\Collector;
use App\Models\Customer;
use App\Models\CustomerMonthlyBilling;
use App\Models\CustomerRecord;
use App\Models\CustomerRecordHistory;
use App\Models\ProcessedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use Illuminate\Support\Str;
use Carbon\Carbon;

class ExcelFormattingService
{
    /**
     * Process an incoming Excel / CSV file and apply standardized formatting,
     * smart address parsing, short 'Add' combined column creation, collector assignment lookup,
     * default 'Active' Customer Status column addition, multi-column advanced sorting, custom column re-ordering,
     * data cleaning, cell normalization, auto-totals, custom styling,
     * AND automatically create a linked 'Target Report' Sheet 2 inside the same Excel workbook!
     */
    public function processFile(string $inputFilePath, string $outputFilePath, array $options = []): array
    {
        @ini_set('memory_limit', '1024M');
        @set_time_limit(300);

        $startTime = microtime(true);

        // Load database lookup lists once for memory efficiency across 4,000+ rows
        $dbAreas = Area::pluck('name')->toArray();
        usort($dbAreas, fn($a, $b) => strlen($b) <=> strlen($a));

        $dbBuildings = Building::pluck('name')->toArray();
        usort($dbBuildings, fn($a, $b) => strlen($b) <=> strlen($a));

        // Load Area -> Collector Map
        $collectors = Collector::with('areas')->where('status', 'active')->get();
        $areaCollectorMap = [];
        foreach ($collectors as $c) {
            foreach ($c->areas as $a) {
                $areaCollectorMap[$a->name][] = $c->name;
            }
        }

        // Load spreadsheet using PhpOffice IOFactory
        $reader = IOFactory::createReaderForFile($inputFilePath);
        if (method_exists($reader, 'setReadDataOnly')) {
            $reader->setReadDataOnly(false);
        }
        
        $spreadsheet = $reader->load($inputFilePath);
        $spreadsheet->getDefaultStyle()->getFont()->setName('Noto Sans Bengali');
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Formatted Data'); // Sheet 1: Formatted Data

        $highestRow = $sheet->getHighestRow();
        $highestColumn = $sheet->getHighestColumn();
        $highestColumnIndex = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($highestColumn);

        if ($highestRow < 1) {
            throw new \Exception("Uploaded spreadsheet is empty.");
        }

        // 1. Scan headers and check for Address & Status columns
        $addressColumnIndex = null;
        $statusColumnIndex = null;
        for ($col = 1; $col <= $highestColumnIndex; $col++) {
            $headerVal = (string) $sheet->getCellByColumnAndRow($col, 1)->getValue();
            $headerLower = strtolower(trim($headerVal));
            if ($addressColumnIndex === null && Str::contains($headerLower, ['address', 'customer_address', 'full_address', 'location'])) {
                $addressColumnIndex = $col;
            }
            if ($statusColumnIndex === null && Str::contains($headerLower, ['status', 'customer_status'])) {
                $statusColumnIndex = $col;
            }
        }

        // If Address column exists, parse address into 7 distinct columns (including 'Add')
        $parsedAddressData = [];
        if ($addressColumnIndex !== null) {
            for ($row = 2; $row <= $highestRow; $row++) {
                $rawAddress = (string) $sheet->getCellByColumnAndRow($addressColumnIndex, $row)->getValue();
                $parsed = $this->parseSmartAddress($rawAddress, $dbAreas, $dbBuildings);
                $parsed['raw_address'] = $rawAddress;

                // Construct short 'Add' combined column: House-Flat, Building (e.g. 123-2, abc)
                $h = trim($parsed['house_no']);
                $f = trim($parsed['flat_no']);
                $b = trim($parsed['building_name']);

                $hf = '';
                if (!empty($h) && !empty($f)) {
                    $hf = "{$h}-{$f}";
                } elseif (!empty($h)) {
                    $hf = $h;
                } elseif (!empty($f)) {
                    $hf = $f;
                }

                $a = trim($parsed['area_name'] ?? '');

                $addCombined = '';
                if (!empty($hf) && !empty($b)) {
                    $addCombined = "{$hf}, {$b}";
                } elseif (!empty($b)) {
                    $addCombined = $b;
                } elseif (!empty($hf) && !empty($a)) {
                    $addCombined = "{$hf}, {$a}";
                } elseif (!empty($a)) {
                    $addCombined = $a;
                } elseif (!empty($hf)) {
                    $addCombined = $hf;
                }

                $parsed['add_combined'] = $addCombined;

                // Lookup Collector Name based on Area Name match
                $areaFound = $parsed['area_name'];
                $collectorName = '';
                if (!empty($areaFound) && isset($areaCollectorMap[$areaFound])) {
                    $collectorName = implode(', ', array_unique($areaCollectorMap[$areaFound]));
                }
                $parsed['collector_name'] = $collectorName;

                $parsedAddressData[$row] = $parsed;
            }

            // Insert new headers (House No, Flat No, Building Name, Add, Area Name, Collector Name, Customer Type, Category, Status)
            $newHeaders = ['House No', 'Flat No', 'Building Name', 'Add', 'Area Name', 'Collector Name', 'Customer Type', 'Category'];
            if ($statusColumnIndex === null) {
                $newHeaders[] = 'Status';
            }

            foreach ($newHeaders as $offset => $newHeaderName) {
                $insertCol = $highestColumnIndex + 1 + $offset;
                $sheet->setCellValueByColumnAndRow($insertCol, 1, $newHeaderName);
                
                // Fill row data for newly created columns
                for ($row = 2; $row <= $highestRow; $row++) {
                    $key = match ($offset) {
                        0 => 'house_no',
                        1 => 'flat_no',
                        2 => 'building_name',
                        3 => 'add_combined',
                        4 => 'area_name',
                        5 => 'collector_name',
                        6 => 'customer_type',
                        7 => 'category',
                        8 => 'status',
                        default => '',
                    };

                    if ($key === 'status') {
                        $sheet->setCellValueByColumnAndRow($insertCol, $row, 'Active');
                    } elseif ($key === 'category') {
                        $rawAddr = strtolower($parsedAddressData[$row]['raw_address'] ?? '');
                        $catVal = (stripos($rawAddr, 'civil') !== false) ? 'Civil' : 'Army';
                        $sheet->setCellValueByColumnAndRow($insertCol, $row, $catVal);
                    } else {
                        $sheet->setCellValueByColumnAndRow($insertCol, $row, $parsedAddressData[$row][$key] ?? '');
                    }
                }
            }

            // Refresh highest column index after insertion
            $highestColumn = $sheet->getHighestColumn();
            $highestColumnIndex = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($highestColumn);
        } else {
            // If no address column, ensure Status column exists with default 'Active'
            if ($statusColumnIndex === null) {
                $insertCol = $highestColumnIndex + 1;
                $sheet->setCellValueByColumnAndRow($insertCol, 1, 'Status');
                for ($row = 2; $row <= $highestRow; $row++) {
                    $sheet->setCellValueByColumnAndRow($insertCol, $row, 'Active');
                }
                $highestColumn = $sheet->getHighestColumn();
                $highestColumnIndex = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($highestColumn);
            }
        }

        // Fill any empty status cell with default 'Active'
        for ($col = 1; $col <= $highestColumnIndex; $col++) {
            $headerVal = (string) $sheet->getCellByColumnAndRow($col, 1)->getValue();
            if (strcasecmp(trim($headerVal), 'Status') === 0) {
                for ($r = 2; $r <= $highestRow; $r++) {
                    $cVal = trim((string)$sheet->getCellByColumnAndRow($col, $r)->getValue());
                    if (empty($cVal)) {
                        $sheet->setCellValueByColumnAndRow($col, $r, 'Active');
                    }
                }
                break;
            }
        }

        // 2. Process & Clean Headers (Row 1)
        $columnTypes = [];
        $headerIndexMap = [];

        for ($col = 1; $col <= $highestColumnIndex; $col++) {
            $cell = $sheet->getCellByColumnAndRow($col, 1);
            $rawHeader = (string) $cell->getValue();
            $cleanHeader = $this->cleanHeader($rawHeader);
            $sheet->setCellValueByColumnAndRow($col, 1, $cleanHeader);

            $headerLower = strtolower($cleanHeader);
            $headerIndexMap[$headerLower] = $col;

            if (Str::contains($headerLower, ['price', 'cost', 'amount', 'salary', 'total', 'revenue', 'tax', 'fee', 'balance', 'discount', 'subtotal', 'rent', 'advance', 'dues', 'due'])) {
                $columnTypes[$col] = 'currency';
            } elseif (Str::contains($headerLower, ['date', 'created_at', 'dob', 'birthday', 'timestamp', 'joining_date', 'entry_dt'])) {
                $columnTypes[$col] = 'date';
            } elseif (Str::contains($headerLower, ['phone', 'mobile', 'cell', 'tel', 'contact_number'])) {
                $columnTypes[$col] = 'phone';
            } elseif (Str::contains($headerLower, ['qty', 'quantity', 'count', 'age', 'score', 'units', 'items'])) {
                $columnTypes[$col] = 'integer';
            } else {
                $columnTypes[$col] = 'general';
            }
        }

        // 3. Data Cleaning & Normalization for Data Rows (Rows 2 to $highestRow)
        for ($row = 2; $row <= $highestRow; $row++) {
            for ($col = 1; $col <= $highestColumnIndex; $col++) {
                $cell = $sheet->getCellByColumnAndRow($col, $row);
                $val = $cell->getValue();

                if ($val === null || $val === '') {
                    continue;
                }

                $colType = $columnTypes[$col] ?? 'general';

                if ($colType === 'date') {
                    $formattedDate = $this->parseAndFormatDate($val);
                    if ($formattedDate) {
                        $sheet->setCellValueByColumnAndRow($col, $row, $formattedDate);
                    }
                } elseif ($colType === 'currency') {
                    $numericVal = $this->extractNumericValue($val);
                    if ($numericVal !== null) {
                        $sheet->setCellValueByColumnAndRow($col, $row, $numericVal);
                    }
                } elseif ($colType === 'phone') {
                    $cleanPhone = $this->cleanPhoneNumber((string) $val);
                    $sheet->setCellValueByColumnAndRow($col, $row, $cleanPhone);
                } elseif (is_numeric($val) && $colType === 'integer') {
                    $sheet->setCellValueByColumnAndRow($col, $row, (float)$val);
                } elseif (is_string($val)) {
                    $sheet->setCellValueByColumnAndRow($col, $row, trim($val));
                }
            }
        }

        // 4. Advanced Multi-Column Excel Sorting Engine (Default priority: Area Name -> Building Name -> House No -> Flat No)
        $sortRules = $options['sort_rules'] ?? [
            ['column' => 'Area Name', 'direction' => 'asc'],
            ['column' => 'Building Name', 'direction' => 'asc'],
            ['column' => 'House No', 'direction' => 'asc'],
            ['column' => 'Flat No', 'direction' => 'asc'],
        ];

        if (!empty($sortRules) && $highestRow >= 2) {
            $rowsData = [];
            for ($r = 2; $r <= $highestRow; $r++) {
                $rowCells = [];
                for ($c = 1; $c <= $highestColumnIndex; $c++) {
                    $rowCells[$c] = $sheet->getCellByColumnAndRow($c, $r)->getValue();
                }
                $rowsData[] = $rowCells;
            }

            usort($rowsData, function ($rowA, $rowB) use ($sortRules, $headerIndexMap) {
                foreach ($sortRules as $rule) {
                    $colName = strtolower(trim($rule['column'] ?? ''));
                    $dir = strtolower(trim($rule['direction'] ?? 'asc'));

                    $colIdx = null;
                    if (isset($headerIndexMap[$colName])) {
                        $colIdx = $headerIndexMap[$colName];
                    } else {
                        // Check common header aliases
                        if (Str::contains($colName, ['area'])) {
                            $colIdx = $headerIndexMap['area name'] ?? $headerIndexMap['area'] ?? null;
                        } elseif (Str::contains($colName, ['building'])) {
                            $colIdx = $headerIndexMap['building name'] ?? $headerIndexMap['building'] ?? null;
                        } elseif (Str::contains($colName, ['house'])) {
                            $colIdx = $headerIndexMap['house no'] ?? $headerIndexMap['house'] ?? null;
                        } elseif (Str::contains($colName, ['flat'])) {
                            $colIdx = $headerIndexMap['flat no'] ?? $headerIndexMap['flat'] ?? null;
                        }
                    }

                    if ($colIdx === null) {
                        continue;
                    }

                    $valA = $rowA[$colIdx] ?? '';
                    $valB = $rowB[$colIdx] ?? '';

                    $cmp = 0;
                    if (is_numeric($valA) && is_numeric($valB)) {
                        $cmp = (float)$valA <=> (float)$valB;
                    } else {
                        $cmp = strnatcasecmp((string)$valA, (string)$valB);
                    }

                    if ($cmp !== 0) {
                        return ($dir === 'desc') ? -$cmp : $cmp;
                    }
                }
                return 0;
            });

            // Write back sorted rows into worksheet
            for ($i = 0; $i < count($rowsData); $i++) {
                $r = $i + 2;
                for ($c = 1; $c <= $highestColumnIndex; $c++) {
                    $sheet->setCellValueByColumnAndRow($c, $r, $rowsData[$i][$c]);
                }
            }
        }

        // 5. Custom Column Re-ordering (First Name, ID, Add; then after Entry Dt put Collector Name; rest as is)
        $this->applyRequestedColumnReorder($sheet, $highestRow, $highestColumnIndex, $columnTypes);

        // Refresh highest column & column types map after reordering
        $highestColumn = $sheet->getHighestColumn();
        $highestColumnIndex = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($highestColumn);

        $numericColumns = [];
        $columnTypes = [];
        for ($col = 1; $col <= $highestColumnIndex; $col++) {
            $headerLower = strtolower((string)$sheet->getCellByColumnAndRow($col, 1)->getValue());
            if (Str::contains($headerLower, ['price', 'cost', 'amount', 'salary', 'total', 'revenue', 'tax', 'fee', 'balance', 'discount', 'subtotal', 'rent', 'advance', 'dues', 'due'])) {
                $columnTypes[$col] = 'currency';
                $numericColumns[] = $col;
            } elseif (Str::contains($headerLower, ['date', 'created_at', 'dob', 'birthday', 'timestamp', 'joining_date', 'entry_dt'])) {
                $columnTypes[$col] = 'date';
            } elseif (Str::contains($headerLower, ['phone', 'mobile', 'cell', 'tel', 'contact_number'])) {
                $columnTypes[$col] = 'phone';
            } elseif (Str::contains($headerLower, ['qty', 'quantity', 'count', 'age', 'score', 'units', 'items'])) {
                $columnTypes[$col] = 'integer';
                $numericColumns[] = $col;
            } else {
                $columnTypes[$col] = 'general';
            }

            // Apply formatting code per row (using clean #,##0.00 format without $ symbol)
            for ($r = 2; $r <= $highestRow; $r++) {
                $colType = $columnTypes[$col] ?? 'general';
                if ($colType === 'date') {
                    $sheet->getStyleByColumnAndRow($col, $r)
                        ->getNumberFormat()
                        ->setFormatCode(NumberFormat::FORMAT_DATE_YYYYMMDD2);
                } elseif ($colType === 'currency') {
                    $sheet->getStyleByColumnAndRow($col, $r)
                        ->getNumberFormat()
                        ->setFormatCode('#,##0.00');
                } elseif ($colType === 'phone') {
                    $sheet->getStyleByColumnAndRow($col, $r)
                        ->getNumberFormat()
                        ->setFormatCode(NumberFormat::FORMAT_TEXT);
                } elseif ($colType === 'integer') {
                    $sheet->getStyleByColumnAndRow($col, $r)
                        ->getNumberFormat()
                        ->setFormatCode('#,##0');
                }
            }
        }

        // 6. Append Summary / Totals Row at bottom
        $totalsRowIndex = $highestRow + 1;
        if (!empty($numericColumns) && $highestRow >= 2) {
            $sheet->setCellValueByColumnAndRow(1, $totalsRowIndex, 'TOTALS');
            
            foreach ($numericColumns as $col) {
                $colLetter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($col);
                $formula = "=SUM({$colLetter}2:{$colLetter}{$highestRow})";
                $sheet->setCellValueByColumnAndRow($col, $totalsRowIndex, $formula);

                $colType = $columnTypes[$col] ?? 'general';
                if ($colType === 'currency') {
                    $sheet->getStyleByColumnAndRow($col, $totalsRowIndex)
                        ->getNumberFormat()
                        ->setFormatCode('#,##0.00');
                } else {
                    $sheet->getStyleByColumnAndRow($col, $totalsRowIndex)
                        ->getNumberFormat()
                        ->setFormatCode('#,##0');
                }
            }
        }

        // 7. Apply Custom Enterprise Styling to Sheet 1
        $headerRange = "A1:{$highestColumn}1";
        $sheet->getStyle($headerRange)->applyFromArray([
            'font' => [
                'bold' => true,
                'color' => ['rgb' => 'FFFFFF'],
                'size' => 11,
                'name' => 'Noto Sans Bengali',
            ],
            'fill' => [
                'fillType' => Fill::FILL_SOLID,
                'startColor' => ['rgb' => '1E293B'],
            ],
            'alignment' => [
                'horizontal' => Alignment::HORIZONTAL_CENTER,
                'vertical' => Alignment::VERTICAL_CENTER,
            ],
        ]);
        $sheet->getRowDimension(1)->setRowHeight(28);

        // Freeze Top Pane
        $sheet->freezePane('A2');

        // Data Rows & Alternating Zebra Shading
        $dataEndRow = !empty($numericColumns) ? $totalsRowIndex : $highestRow;
        $gridRange = "A1:{$highestColumn}{$dataEndRow}";

        $sheet->getStyle($gridRange)->applyFromArray([
            'borders' => [
                'allBorders' => [
                    'borderStyle' => Border::BORDER_THIN,
                    'color' => ['rgb' => 'CBD5E1'],
                ],
            ],
        ]);

        for ($r = 2; $r <= $highestRow; $r++) {
            $sheet->getRowDimension($r)->setRowHeight(20);
            if ($r % 2 === 0) {
                $rowRange = "A{$r}:{$highestColumn}{$r}";
                $sheet->getStyle($rowRange)->getFill()->applyFromArray([
                    'fillType' => Fill::FILL_SOLID,
                    'startColor' => ['rgb' => 'F8FAFC'],
                ]);
            }
        }

        // Totals Row Styling
        if (!empty($numericColumns) && $highestRow >= 2) {
            $totalsRange = "A{$totalsRowIndex}:{$highestColumn}{$totalsRowIndex}";
            $sheet->getStyle($totalsRange)->applyFromArray([
                'font' => [
                    'bold' => true,
                    'color' => ['rgb' => '0F172A'],
                    'size' => 11,
                    'name' => 'Noto Sans Bengali',
                ],
                'fill' => [
                    'fillType' => Fill::FILL_SOLID,
                    'startColor' => ['rgb' => 'E2E8F0'],
                ],
                'borders' => [
                    'top' => [
                        'borderStyle' => Border::BORDER_THIN,
                        'color' => ['rgb' => '475569'],
                    ],
                    'bottom' => [
                        'borderStyle' => Border::BORDER_DOUBLE,
                        'color' => ['rgb' => '0F172A'],
                    ],
                ],
            ]);
            $sheet->getRowDimension($totalsRowIndex)->setRowHeight(24);
        }

        // Auto-Fit Column Widths for Sheet 1
        for ($col = 1; $col <= $highestColumnIndex; $col++) {
            $colLetter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($col);
            $sheet->getColumnDimension($colLetter)->setAutoSize(true);
        }

        // =========================================================================
        // 8. AUTOMATICALLY CREATE LINKED 'TARGET REPORT' AS SHEET 2 INSIDE WORKBOOK
        // =========================================================================
        $targetSheet = $spreadsheet->createSheet();
        $targetSheet->setTitle('Target Report');

        $this->populateTargetReportSheet($spreadsheet, $sheet, $targetSheet, $dbAreas, $dbBuildings, $areaCollectorMap);

        // Ensure Sheet 1 ('Formatted Data') remains active tab when opened in Excel
        $spreadsheet->setActiveSheetIndex(0);

        // Save output 2-sheet formatted Excel workbook
        $writer = IOFactory::createWriter($spreadsheet, 'Xlsx');
        $writer->setPreCalculateFormulas(true);
        $writer->save($outputFilePath);

        $executionTime = round(microtime(true) - $startTime, 2);

        return [
            'row_count' => $highestRow - 1,
            'column_count' => $highestColumnIndex,
            'execution_time_seconds' => $executionTime,
            'totals_calculated' => !empty($numericColumns),
            'address_parsed' => ($addressColumnIndex !== null),
            'sorted_rules' => $sortRules,
            'sheets_created' => ['Formatted Data', 'Target Report'],
        ];
    }

    /**
     * Populate Sheet 2 ('Target Report') inside the workbook based on Sheet 1 ('Formatted Data')
     * Only Active customers are calculated into Monthly Target Report!
     */
    private function populateTargetReportSheet(Spreadsheet $spreadsheet, $dataSheet, $targetSheet, array $dbAreas, array $dbBuildings, array $areaCollectorMap, ?string $customMonthLabel = null)
    {
        $highestRow = $dataSheet->getHighestRow();
        $highestColumn = $dataSheet->getHighestColumn();
        $highestColumnIndex = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($highestColumn);

        // Scan column indices in dataSheet
        $addrCol = null;
        $collectorCol = null;
        $customerTypeCol = null;
        $statusCol = null;
        $rentCol = null;
        $advCol = null;
        $duesCol = null;
        $discountCol = null;

        // Strictly match Collector / Collector Name column (completely ignoring Connected By)
        for ($col = 1; $col <= $highestColumnIndex; $col++) {
            $headerLower = strtolower(trim((string)$dataSheet->getCellByColumnAndRow($col, 1)->getValue()));
            if (Str::contains($headerLower, ['collector name', 'collector_name', 'collector', 'collectorname'])) {
                $collectorCol = $col;
                break;
            }
        }

        for ($col = 1; $col <= $highestColumnIndex; $col++) {
            $headerLower = strtolower(trim((string)$dataSheet->getCellByColumnAndRow($col, 1)->getValue()));
            
            if (Str::contains($headerLower, ['customer type', 'type'])) {
                $customerTypeCol = $col;
            } elseif (Str::contains($headerLower, ['status', 'customer_status'])) {
                $statusCol = $col;
            } elseif (Str::contains($headerLower, ['address', 'location'])) {
                $addrCol = $col;
            } elseif (Str::contains($headerLower, ['advance', 'adv'])) {
                $advCol = $col;
            } elseif (Str::contains($headerLower, ['previous dues', 'previous_dues', 'prev dues', 'prev_dues', 'previous due', 'prev due'])) {
                $duesCol = $col;
            } elseif (Str::contains($headerLower, ['discount', 'disc', 'less'])) {
                $discountCol = $col;
            } elseif (Str::contains($headerLower, ['rent', 'salary', 'bill', 'amount', 'price', 'cost', 'total', 'revenue'])) {
                if ($rentCol === null) $rentCol = $col;
            }
        }

        if ($duesCol === null) {
            for ($col = 1; $col <= $highestColumnIndex; $col++) {
                $headerLower = strtolower(trim((string)$dataSheet->getCellByColumnAndRow($col, 1)->getValue()));
                if (Str::contains($headerLower, ['dues', 'due', 'arrears'])) {
                    $duesCol = $col;
                    break;
                }
            }
        }

        $analogStats = [];
        $digitalStats = [];

        for ($row = 2; $row <= $highestRow; $row++) {
            $firstCellVal = trim((string)$dataSheet->getCellByColumnAndRow(1, $row)->getValue());
            if (strcasecmp($firstCellVal, 'TOTALS') === 0 || strcasecmp($firstCellVal, 'TOTAL') === 0) {
                continue;
            }

            // Exclude Inactive customers from active target collection reports
            if ($statusCol !== null) {
                $statusVal = trim((string)$dataSheet->getCellByColumnAndRow($statusCol, $row)->getValue());
                if (strcasecmp($statusVal, 'Inactive') === 0) {
                    continue;
                }
            }

            $collectorName = '';
            $customerType = 'Analog';
            
            $rent = 0.0;
            $adv = 0.0;
            $dues = 0.0;
            $discount = 0.0;

            if ($collectorCol !== null) {
                $collectorName = trim((string)$dataSheet->getCellByColumnAndRow($collectorCol, $row)->getValue());
            }

            if ($customerTypeCol !== null) {
                $customerType = trim((string)$dataSheet->getCellByColumnAndRow($customerTypeCol, $row)->getValue());
            }

            if ($rentCol !== null) {
                $extracted = $this->extractNumericValue($dataSheet->getCellByColumnAndRow($rentCol, $row)->getValue());
                if ($extracted !== null) $rent = $extracted;
            }

            if ($advCol !== null) {
                $extracted = $this->extractNumericValue($dataSheet->getCellByColumnAndRow($advCol, $row)->getValue());
                if ($extracted !== null) $adv = $extracted;
            }

            if ($duesCol !== null) {
                $extracted = $this->extractNumericValue($dataSheet->getCellByColumnAndRow($duesCol, $row)->getValue());
                if ($extracted !== null) $dues = $extracted;
            }

            if ($discountCol !== null) {
                $extracted = $this->extractNumericValue($dataSheet->getCellByColumnAndRow($discountCol, $row)->getValue());
                if ($extracted !== null) $discount = $extracted;
            }

            if (empty($collectorName) || empty($customerType)) {
                if ($addrCol !== null) {
                    $rawAddress = (string)$dataSheet->getCellByColumnAndRow($addrCol, $row)->getValue();
                    $parsed = $this->parseSmartAddress($rawAddress, $dbAreas, $dbBuildings);
                    
                    if (empty($customerType)) $customerType = $parsed['customer_type'];
                    if (empty($collectorName) && !empty($parsed['area_name']) && isset($areaCollectorMap[$parsed['area_name']])) {
                        $collectorName = implode(', ', array_unique($areaCollectorMap[$parsed['area_name']]));
                    }
                }
            }

            if (empty($collectorName)) {
                $collectorName = 'Unassigned / Unmapped';
            }

            $effectiveAdv = $adv + $discount;
            $actualBill = max(0.0, $rent - $effectiveAdv);
            $fiftyPercent = $dues * 0.5;
            $target = $actualBill + $fiftyPercent;

            $isDigital = (stripos($customerType, 'digital') !== false);

            if ($isDigital) {
                if (!isset($digitalStats[$collectorName])) {
                    $digitalStats[$collectorName] = [
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
                $digitalStats[$collectorName]['count_of_id']++;
                $digitalStats[$collectorName]['sum_of_rent'] += $rent;
                $digitalStats[$collectorName]['sum_of_due'] += $dues;
                $digitalStats[$collectorName]['sum_of_advnc'] += $effectiveAdv;
                $digitalStats[$collectorName]['sum_of_actual_bill'] += $actualBill;
                $digitalStats[$collectorName]['sum_of_50'] += $fiftyPercent;
                $digitalStats[$collectorName]['sum_of_target'] += $target;
            } else {
                if (!isset($analogStats[$collectorName])) {
                    $analogStats[$collectorName] = [
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
                $analogStats[$collectorName]['count_of_id']++;
                $analogStats[$collectorName]['sum_of_rent'] += $rent;
                $analogStats[$collectorName]['sum_of_due'] += $dues;
                $analogStats[$collectorName]['sum_of_advnc'] += $effectiveAdv;
                $analogStats[$collectorName]['sum_of_actual_bill'] += $actualBill;
                $analogStats[$collectorName]['sum_of_50'] += $fiftyPercent;
                $analogStats[$collectorName]['sum_of_target'] += $target;
            }
        }

        ksort($analogStats);
        ksort($digitalStats);

        // Header Titles
        $targetSheet->setCellValue('A1', 'Chittagong Communications Ltd');
        $targetSheet->mergeCells('A1:I1');
        $targetSheet->getStyle('A1')->applyFromArray([
            'font' => ['bold' => true, 'size' => 16, 'name' => 'Noto Sans Bengali'],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
        ]);

        $monthStr = $customMonthLabel ?: Carbon::now()->format('F-y');
        $targetSheet->setCellValue('A2', "Target for the month of {$monthStr}");
        $targetSheet->mergeCells('A2:I2');
        $targetSheet->getStyle('A2')->applyFromArray([
            'font' => ['size' => 12, 'name' => 'Noto Sans Bengali'],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
        ]);

        // Table Headers (Row 4)
        $reportHeaders = ['Type', 'Collector', 'Customer', 'Rent', 'Due', 'Advnc', 'Actual Bill', '50%', 'Target'];
        $targetSheet->fromArray([$reportHeaders], null, 'A4');

        $targetSheet->getStyle('A4:I4')->applyFromArray([
            'font' => ['bold' => true, 'size' => 11, 'name' => 'Noto Sans Bengali'],
            'borders' => [
                'top' => ['borderStyle' => Border::BORDER_THIN],
                'bottom' => ['borderStyle' => Border::BORDER_THIN],
            ],
            'alignment' => ['vertical' => Alignment::VERTICAL_CENTER],
        ]);

        $currentRow = 5;

        // Analog Section
        $targetSheet->setCellValue("A{$currentRow}", 'Analog');
        $targetSheet->getStyle("A{$currentRow}")->getFont()->setBold(true);
        $currentRow++;

        $analogStartRow = $currentRow;
        foreach ($analogStats as $stat) {
            $targetSheet->setCellValue("B{$currentRow}", $stat['collector_name']);
            $targetSheet->setCellValue("C{$currentRow}", $stat['count_of_id']);
            $targetSheet->setCellValue("D{$currentRow}", $stat['sum_of_rent']);
            $targetSheet->setCellValue("E{$currentRow}", $stat['sum_of_due']);
            $targetSheet->setCellValue("F{$currentRow}", $stat['sum_of_advnc']);
            $targetSheet->setCellValue("G{$currentRow}", $stat['sum_of_actual_bill']);
            $targetSheet->setCellValue("H{$currentRow}", $stat['sum_of_50']);
            $targetSheet->setCellValue("I{$currentRow}", $stat['sum_of_target']);
            $currentRow++;
        }
        $analogEndRow = $currentRow - 1;

        // Analog Total Row
        $analogTotalRow = $currentRow;
        $targetSheet->setCellValue("A{$analogTotalRow}", 'Analog Total');
        if ($analogEndRow >= $analogStartRow) {
            $targetSheet->setCellValue("C{$analogTotalRow}", "=SUM(C{$analogStartRow}:C{$analogEndRow})");
            $targetSheet->setCellValue("D{$analogTotalRow}", "=SUM(D{$analogStartRow}:D{$analogEndRow})");
            $targetSheet->setCellValue("E{$analogTotalRow}", "=SUM(E{$analogStartRow}:E{$analogEndRow})");
            $targetSheet->setCellValue("F{$analogTotalRow}", "=SUM(F{$analogStartRow}:F{$analogEndRow})");
            $targetSheet->setCellValue("G{$analogTotalRow}", "=SUM(G{$analogStartRow}:G{$analogEndRow})");
            $targetSheet->setCellValue("H{$analogTotalRow}", "=SUM(H{$analogStartRow}:H{$analogEndRow})");
            $targetSheet->setCellValue("I{$analogTotalRow}", "=SUM(I{$analogStartRow}:I{$analogEndRow})");
        }
        $targetSheet->getStyle("A{$analogTotalRow}:I{$analogTotalRow}")->applyFromArray([
            'font' => ['bold' => true],
            'borders' => [
                'top' => ['borderStyle' => Border::BORDER_THIN],
                'bottom' => ['borderStyle' => Border::BORDER_THIN],
            ],
        ]);
        $currentRow++;

        // Digital Section
        $targetSheet->setCellValue("A{$currentRow}", 'Digital');
        $targetSheet->getStyle("A{$currentRow}")->getFont()->setBold(true);
        $currentRow++;

        $digitalStartRow = $currentRow;
        foreach ($digitalStats as $stat) {
            $targetSheet->setCellValue("B{$currentRow}", $stat['collector_name']);
            $targetSheet->setCellValue("C{$currentRow}", $stat['count_of_id']);
            $targetSheet->setCellValue("D{$currentRow}", $stat['sum_of_rent']);
            $targetSheet->setCellValue("E{$currentRow}", $stat['sum_of_due']);
            $targetSheet->setCellValue("F{$currentRow}", $stat['sum_of_advnc']);
            $targetSheet->setCellValue("G{$currentRow}", $stat['sum_of_actual_bill']);
            $targetSheet->setCellValue("H{$currentRow}", $stat['sum_of_50']);
            $targetSheet->setCellValue("I{$currentRow}", $stat['sum_of_target']);
            $currentRow++;
        }
        $digitalEndRow = $currentRow - 1;

        // Digital Total Row
        $digitalTotalRow = $currentRow;
        $targetSheet->setCellValue("A{$digitalTotalRow}", 'Digital Total');
        if ($digitalEndRow >= $digitalStartRow) {
            $targetSheet->setCellValue("C{$digitalTotalRow}", "=SUM(C{$digitalStartRow}:C{$digitalEndRow})");
            $targetSheet->setCellValue("D{$digitalTotalRow}", "=SUM(D{$digitalStartRow}:D{$digitalEndRow})");
            $targetSheet->setCellValue("E{$digitalTotalRow}", "=SUM(E{$digitalStartRow}:E{$digitalEndRow})");
            $targetSheet->setCellValue("F{$digitalTotalRow}", "=SUM(F{$digitalStartRow}:F{$digitalEndRow})");
            $targetSheet->setCellValue("G{$digitalTotalRow}", "=SUM(G{$digitalStartRow}:G{$digitalEndRow})");
            $targetSheet->setCellValue("H{$digitalTotalRow}", "=SUM(H{$digitalStartRow}:H{$digitalEndRow})");
            $targetSheet->setCellValue("I{$digitalTotalRow}", "=SUM(I{$digitalStartRow}:I{$digitalEndRow})");
        }
        $targetSheet->getStyle("A{$digitalTotalRow}:I{$digitalTotalRow}")->applyFromArray([
            'font' => ['bold' => true],
            'borders' => [
                'top' => ['borderStyle' => Border::BORDER_THIN],
                'bottom' => ['borderStyle' => Border::BORDER_THIN],
            ],
        ]);
        $currentRow++;

        // Grand Total Row
        $grandTotalRow = $currentRow;
        $targetSheet->setCellValue("A{$grandTotalRow}", 'Grand Total');
        $targetSheet->setCellValue("C{$grandTotalRow}", "=C{$analogTotalRow}+C{$digitalTotalRow}");
        $targetSheet->setCellValue("D{$grandTotalRow}", "=D{$analogTotalRow}+D{$digitalTotalRow}");
        $targetSheet->setCellValue("E{$grandTotalRow}", "=E{$analogTotalRow}+E{$digitalTotalRow}");
        $targetSheet->setCellValue("F{$grandTotalRow}", "=F{$analogTotalRow}+F{$digitalTotalRow}");
        $targetSheet->setCellValue("G{$grandTotalRow}", "=G{$analogTotalRow}+G{$digitalTotalRow}");
        $targetSheet->setCellValue("H{$grandTotalRow}", "=H{$analogTotalRow}+H{$digitalTotalRow}");
        $targetSheet->setCellValue("I{$grandTotalRow}", "=I{$analogTotalRow}+I{$digitalTotalRow}");

        $targetSheet->getStyle("A{$grandTotalRow}:I{$grandTotalRow}")->applyFromArray([
            'font' => ['bold' => true],
            'borders' => [
                'top' => ['borderStyle' => Border::BORDER_THIN],
                'bottom' => ['borderStyle' => Border::BORDER_DOUBLE],
            ],
        ]);
        $currentRow += 4;

        // Signature Footer
        $targetSheet->setCellValue("D{$currentRow}", '_________________________');
        $targetSheet->mergeCells("D{$currentRow}:F{$currentRow}");
        $targetSheet->getStyle("D{$currentRow}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $currentRow++;

        $targetSheet->setCellValue("D{$currentRow}", 'Prepared By');
        $targetSheet->mergeCells("D{$currentRow}:F{$currentRow}");
        $targetSheet->getStyle("D{$currentRow}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

        // Formatting
        for ($r = 5; $r <= $grandTotalRow; $r++) {
            $targetSheet->getStyleByColumnAndRow(3, $r)->getNumberFormat()->setFormatCode('#,##0');
            for ($c = 4; $c <= 9; $c++) {
                $targetSheet->getStyleByColumnAndRow($c, $r)->getNumberFormat()->setFormatCode('#,##0;(#,##0);"-"');
            }
        }

        for ($c = 1; $c <= 9; $c++) {
            $colLetter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($c);
            $targetSheet->getColumnDimension($colLetter)->setAutoSize(true);
        }
    }

    /**
     * Read data matrix from a processed Excel file for interactive editing
     */
    public function readProcessedFileData(string $inputFilePath, int $limit = 0): array
    {
        $reader = IOFactory::createReaderForFile($inputFilePath);
        $spreadsheet = $reader->load($inputFilePath);
        
        // Select Sheet 1 ('Formatted Data')
        $sheet = $spreadsheet->getSheet(0);

        $highestRow = $sheet->getHighestRow();
        $highestColumn = $sheet->getHighestColumn();
        $highestColumnIndex = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($highestColumn);

        $headers = [];
        for ($c = 1; $c <= $highestColumnIndex; $c++) {
            $headers[] = (string) $sheet->getCellByColumnAndRow($c, 1)->getValue();
        }

        $rows = [];
        $maxRow = ($limit > 0) ? min($highestRow, $limit + 1) : $highestRow;

        for ($r = 2; $r <= $maxRow; $r++) {
            $firstVal = trim((string)$sheet->getCellByColumnAndRow(1, $r)->getValue());
            if (strcasecmp($firstVal, 'TOTALS') === 0 || strcasecmp($firstVal, 'TOTAL') === 0) {
                continue; // Skip summary totals row
            }

            $rowData = ['_row_index' => $r];
            for ($c = 1; $c <= $highestColumnIndex; $c++) {
                $h = $headers[$c - 1] ?? "Col_$c";
                $rowData[$h] = $sheet->getCellByColumnAndRow($c, $r)->getValue();
            }
            $rows[] = $rowData;
        }

        return [
            'headers' => $headers,
            'rows' => $rows,
            'total_rows' => count($rows),
        ];
    }

    /**
     * Save cell updates back to processed Excel file on disk & recalculate formulas for both sheets
     */
    public function updateProcessedFileData(string $outputFilePath, array $rowUpdates): bool
    {
        $reader = IOFactory::createReaderForFile($outputFilePath);
        $spreadsheet = $reader->load($outputFilePath);
        $sheet = $spreadsheet->getSheet(0);

        $highestRow = $sheet->getHighestRow();
        $highestColumn = $sheet->getHighestColumn();
        $highestColumnIndex = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($highestColumn);

        $headerMap = [];
        for ($c = 1; $c <= $highestColumnIndex; $c++) {
            $h = (string) $sheet->getCellByColumnAndRow($c, 1)->getValue();
            $headerMap[strtolower(trim($h))] = $c;
        }

        foreach ($rowUpdates as $update) {
            $r = (int) ($update['row_index'] ?? 0);
            if ($r < 2 || $r > $highestRow) continue;

            $changes = $update['changes'] ?? [];
            foreach ($changes as $colName => $newVal) {
                $colKey = strtolower(trim($colName));
                
                $c = null;
                if (isset($headerMap[$colKey])) {
                    $c = $headerMap[$colKey];
                } else {
                    $colKeySpace = str_replace('_', ' ', $colKey);
                    if (isset($headerMap[$colKeySpace])) {
                        $c = $headerMap[$colKeySpace];
                    } else {
                        foreach ($headerMap as $hKey => $cIdx) {
                            if (Str::contains($colKey, ['rent', 'salary', 'bill']) && Str::contains($hKey, ['rent', 'salary', 'bill'])) {
                                $c = $cIdx; break;
                            }
                            if (Str::contains($colKey, ['dues', 'due', 'baki', 'bokea']) && Str::contains($hKey, ['dues', 'due', 'baki', 'bokea'])) {
                                $c = $cIdx; break;
                            }
                            if (Str::contains($colKey, ['status']) && Str::contains($hKey, ['status'])) {
                                $c = $cIdx; break;
                            }
                            if (Str::contains($colKey, ['collector']) && Str::contains($hKey, ['collector'])) {
                                $c = $cIdx; break;
                            }
                            if (Str::contains($colKey, ['name']) && Str::contains($hKey, ['full name', 'name'])) {
                                $c = $cIdx; break;
                            }
                        }
                    }
                }

                if ($c !== null) {
                    if (is_numeric($newVal)) {
                        $sheet->setCellValueByColumnAndRow($c, $r, (float)$newVal);
                    } else {
                        $sheet->setCellValueByColumnAndRow($c, $r, trim((string)$newVal));
                    }
                }
            }
        }

        // If Sheet 2 ('Target Report') exists, refresh it
        if ($spreadsheet->getSheetCount() > 1) {
            $dbAreas = Area::pluck('name')->toArray();
            usort($dbAreas, fn($a, $b) => strlen($b) <=> strlen($a));
            $dbBuildings = Building::pluck('name')->toArray();
            usort($dbBuildings, fn($a, $b) => strlen($b) <=> strlen($a));
            $collectors = Collector::with('areas')->where('status', 'active')->get();
            $areaCollectorMap = [];
            foreach ($collectors as $colItem) {
                foreach ($colItem->areas as $aItem) {
                    $areaCollectorMap[$aItem->name][] = $colItem->name;
                }
            }

            $targetSheet = $spreadsheet->getSheet(1);
            $this->populateTargetReportSheet($spreadsheet, $sheet, $targetSheet, $dbAreas, $dbBuildings, $areaCollectorMap);
        }

        // Save updated spreadsheet
        $writer = IOFactory::createWriter($spreadsheet, 'Xlsx');
        $writer->setPreCalculateFormulas(true);
        $writer->save($outputFilePath);

        return true;
    }

    /**
     * Generate Collector & Customer Type Monthly Target & Collection Excel Report
     */
    public function generateTargetReport(string $inputFilePath, string $outputFilePath, ?string $customMonthLabel = null): array
    {
        $dbAreas = Area::pluck('name')->toArray();
        usort($dbAreas, fn($a, $b) => strlen($b) <=> strlen($a));

        $dbBuildings = Building::pluck('name')->toArray();
        usort($dbBuildings, fn($a, $b) => strlen($b) <=> strlen($a));

        $collectors = Collector::with('areas')->where('status', 'active')->get();
        $areaCollectorMap = [];
        foreach ($collectors as $c) {
            foreach ($c->areas as $a) {
                $areaCollectorMap[$a->name][] = $c->name;
            }
        }

        $reader = IOFactory::createReaderForFile($inputFilePath);
        $spreadsheet = $reader->load($inputFilePath);
        $sheet = $spreadsheet->getSheet(0);

        $highestRow = $sheet->getHighestRow();
        $highestColumn = $sheet->getHighestColumn();
        $highestColumnIndex = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($highestColumn);

        // Find column indices
        $addrCol = null;
        $collectorCol = null;
        $customerTypeCol = null;
        $statusCol = null;
        $rentCol = null;
        $advCol = null;
        $duesCol = null;
        $discountCol = null;

        // Strictly match Collector / Collector Name column (completely ignoring Connected By)
        for ($col = 1; $col <= $highestColumnIndex; $col++) {
            $headerLower = strtolower(trim((string)$sheet->getCellByColumnAndRow($col, 1)->getValue()));
            if (Str::contains($headerLower, ['collector name', 'collector_name', 'collector', 'collectorname'])) {
                $collectorCol = $col;
                break;
            }
        }

        for ($col = 1; $col <= $highestColumnIndex; $col++) {
            $headerLower = strtolower(trim((string)$sheet->getCellByColumnAndRow($col, 1)->getValue()));
            
            if (Str::contains($headerLower, ['customer type', 'type'])) {
                $customerTypeCol = $col;
            } elseif (Str::contains($headerLower, ['status', 'customer_status'])) {
                $statusCol = $col;
            } elseif (Str::contains($headerLower, ['address', 'location'])) {
                $addrCol = $col;
            } elseif (Str::contains($headerLower, ['advance', 'adv'])) {
                $advCol = $col;
            } elseif (Str::contains($headerLower, ['previous dues', 'previous_dues', 'prev dues', 'prev_dues', 'previous due', 'prev due'])) {
                $duesCol = $col;
            } elseif (Str::contains($headerLower, ['discount', 'disc', 'less'])) {
                $discountCol = $col;
            } elseif (Str::contains($headerLower, ['rent', 'salary', 'bill', 'amount', 'price', 'cost', 'total', 'revenue'])) {
                if ($rentCol === null) $rentCol = $col;
            }
        }

        if ($duesCol === null) {
            for ($col = 1; $col <= $highestColumnIndex; $col++) {
                $headerLower = strtolower(trim((string)$sheet->getCellByColumnAndRow($col, 1)->getValue()));
                if (Str::contains($headerLower, ['dues', 'due', 'arrears'])) {
                    $duesCol = $col;
                    break;
                }
            }
        }

        $analogStats = [];
        $digitalStats = [];

        for ($row = 2; $row <= $highestRow; $row++) {
            $firstCellVal = trim((string)$sheet->getCellByColumnAndRow(1, $row)->getValue());
            if (strcasecmp($firstCellVal, 'TOTALS') === 0 || strcasecmp($firstCellVal, 'TOTAL') === 0) {
                continue;
            }

            // Exclude Inactive customers from active target collection reports
            if ($statusCol !== null) {
                $statusVal = trim((string)$sheet->getCellByColumnAndRow($statusCol, $row)->getValue());
                if (strcasecmp($statusVal, 'Inactive') === 0) {
                    continue;
                }
            }

            $collectorName = '';
            $customerType = 'Analog';
            
            $rent = 0.0;
            $adv = 0.0;
            $dues = 0.0;
            $discount = 0.0;

            if ($collectorCol !== null) {
                $collectorName = trim((string)$sheet->getCellByColumnAndRow($collectorCol, $row)->getValue());
            }

            if ($customerTypeCol !== null) {
                $customerType = trim((string)$sheet->getCellByColumnAndRow($customerTypeCol, $row)->getValue());
            }

            if ($rentCol !== null) {
                $extracted = $this->extractNumericValue($sheet->getCellByColumnAndRow($rentCol, $row)->getValue());
                if ($extracted !== null) $rent = $extracted;
            }

            if ($advCol !== null) {
                $extracted = $this->extractNumericValue($sheet->getCellByColumnAndRow($advCol, $row)->getValue());
                if ($extracted !== null) $adv = $extracted;
            }

            if ($duesCol !== null) {
                $extracted = $this->extractNumericValue($sheet->getCellByColumnAndRow($duesCol, $row)->getValue());
                if ($extracted !== null) $dues = $extracted;
            }

            if ($discountCol !== null) {
                $extracted = $this->extractNumericValue($sheet->getCellByColumnAndRow($discountCol, $row)->getValue());
                if ($extracted !== null) $discount = $extracted;
            }

            if (empty($collectorName) || empty($customerType)) {
                if ($addrCol !== null) {
                    $rawAddress = (string)$sheet->getCellByColumnAndRow($addrCol, $row)->getValue();
                    $parsed = $this->parseSmartAddress($rawAddress, $dbAreas, $dbBuildings);
                    
                    if (empty($customerType)) $customerType = $parsed['customer_type'];
                    if (empty($collectorName) && !empty($parsed['area_name']) && isset($areaCollectorMap[$parsed['area_name']])) {
                        $collectorName = implode(', ', array_unique($areaCollectorMap[$parsed['area_name']]));
                    }
                }
            }

            if (empty($collectorName)) {
                $collectorName = 'Unassigned / Unmapped';
            }

            $effectiveAdv = $adv + $discount;
            $actualBill = max(0.0, $rent - $effectiveAdv);
            $fiftyPercent = $dues * 0.5;
            $target = $actualBill + $fiftyPercent;

            $isDigital = (stripos($customerType, 'digital') !== false);

            if ($isDigital) {
                if (!isset($digitalStats[$collectorName])) {
                    $digitalStats[$collectorName] = [
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
                $digitalStats[$collectorName]['count_of_id']++;
                $digitalStats[$collectorName]['sum_of_rent'] += $rent;
                $digitalStats[$collectorName]['sum_of_due'] += $dues;
                $digitalStats[$collectorName]['sum_of_advnc'] += $effectiveAdv;
                $digitalStats[$collectorName]['sum_of_actual_bill'] += $actualBill;
                $digitalStats[$collectorName]['sum_of_50'] += $fiftyPercent;
                $digitalStats[$collectorName]['sum_of_target'] += $target;
            } else {
                if (!isset($analogStats[$collectorName])) {
                    $analogStats[$collectorName] = [
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
                $analogStats[$collectorName]['count_of_id']++;
                $analogStats[$collectorName]['sum_of_rent'] += $rent;
                $analogStats[$collectorName]['sum_of_due'] += $dues;
                $analogStats[$collectorName]['sum_of_advnc'] += $effectiveAdv;
                $analogStats[$collectorName]['sum_of_actual_bill'] += $actualBill;
                $analogStats[$collectorName]['sum_of_50'] += $fiftyPercent;
                $analogStats[$collectorName]['sum_of_target'] += $target;
            }
        }

        ksort($analogStats);
        ksort($digitalStats);

        // Build Standalone Target Report Workbook
        $outSpreadsheet = new Spreadsheet();
        $outSpreadsheet->getDefaultStyle()->getFont()->setName('Noto Sans Bengali');
        $outSheet = $outSpreadsheet->getActiveSheet();
        $outSheet->setTitle('Target Report');

        $monthStr = $customMonthLabel ?: Carbon::now()->format('F-y');
        $this->populateTargetReportSheet($outSpreadsheet, $sheet, $outSheet, $dbAreas, $dbBuildings, $areaCollectorMap, $monthStr);

        $writer = IOFactory::createWriter($outSpreadsheet, 'Xlsx');
        $writer->setPreCalculateFormulas(true);
        $writer->save($outputFilePath);

        return [
            'month_label' => "Target for the month of {$monthStr}",
            'analog_stats' => array_values($analogStats),
            'digital_stats' => array_values($digitalStats),
        ];
    }

    /**
     * Apply requested column reordering
     */
    private function applyRequestedColumnReorder($sheet, $highestRow, $highestColumnIndex, $columnTypes)
    {
        $existingHeaders = [];
        $headerColMap = [];

        for ($c = 1; $c <= $highestColumnIndex; $c++) {
            $h = (string) $sheet->getCellByColumnAndRow($c, 1)->getValue();
            $existingHeaders[$c] = $h;
            $headerColMap[strtolower(trim($h))] = $c;
        }

        $newOrderCols = [];
        $usedCols = [];

        // 1. Name
        $nameKey = isset($headerColMap['full name']) ? 'full name' : (isset($headerColMap['name']) ? 'name' : null);
        if ($nameKey && isset($headerColMap[$nameKey])) {
            $newOrderCols[] = $headerColMap[$nameKey];
            $usedCols[$headerColMap[$nameKey]] = true;
        }

        // 2. ID
        $idKey = isset($headerColMap['emp id']) ? 'emp id' : (isset($headerColMap['id']) ? 'id' : null);
        if ($idKey && isset($headerColMap[$idKey])) {
            $newOrderCols[] = $headerColMap[$idKey];
            $usedCols[$headerColMap[$idKey]] = true;
        }

        // 3. Add
        if (isset($headerColMap['add'])) {
            $newOrderCols[] = $headerColMap['add'];
            $usedCols[$headerColMap['add']] = true;
        }

        $collectorCol = $headerColMap['collector name'] ?? null;

        for ($c = 1; $c <= $highestColumnIndex; $c++) {
            if (isset($usedCols[$c])) continue;
            if ($collectorCol && $c === $collectorCol) continue;

            $newOrderCols[] = $c;
            $usedCols[$c] = true;

            $hLower = strtolower(trim($existingHeaders[$c]));
            if ($collectorCol && !isset($usedCols[$collectorCol]) && Str::contains($hLower, ['joining_date', 'joining date', 'entry_dt', 'entry dt', 'date', 'created_at'])) {
                $newOrderCols[] = $collectorCol;
                $usedCols[$collectorCol] = true;
            }
        }

        if ($collectorCol && !isset($usedCols[$collectorCol])) {
            $newOrderCols[] = $collectorCol;
            $usedCols[$collectorCol] = true;
        }

        for ($c = 1; $c <= $highestColumnIndex; $c++) {
            if (!isset($usedCols[$c])) {
                $newOrderCols[] = $c;
            }
        }

        $matrix = [];
        for ($r = 1; $r <= $highestRow; $r++) {
            $rowMatrix = [];
            foreach ($newOrderCols as $newIdx => $oldCol) {
                $rowMatrix[$newIdx + 1] = $sheet->getCellByColumnAndRow($oldCol, $r)->getValue();
            }
            $matrix[$r] = $rowMatrix;
        }

        for ($r = 1; $r <= $highestRow; $r++) {
            for ($c = 1; $c <= count($newOrderCols); $c++) {
                $sheet->setCellValueByColumnAndRow($c, $r, $matrix[$r][$c] ?? '');
            }
        }
    }

    /**
     * Smart Address Parsing Algorithm
     */
    public function parseSmartAddress(string $address, array $areasList, array $buildingsList): array
    {
        $cleanAddr = trim($address);
        $segments = array_map('trim', explode(',', $cleanAddr));

        $houseNo = '';
        $flatNo = '';

        foreach ($segments as $seg) {
            if ($houseNo === '' && (preg_match('/^(?:house|h\s*#)/i', $seg) || preg_match('/house\s*(?:no\.?|#)?/i', $seg))) {
                $val = preg_replace('/^(?:house\s*(?:no\.?|#)?|h\s*#)\s*[:#\-]?\s*/i', '', $seg);
                $houseNo = trim($val);
                if (empty($houseNo)) $houseNo = trim($seg);
            } elseif ($flatNo === '' && (preg_match('/^(?:flat|f\s*#)/i', $seg) || preg_match('/flat\s*(?:no\.?|#)?/i', $seg))) {
                $val = preg_replace('/^(?:flat\s*(?:no\.?|#)?|f\s*#)\s*[:#\-]?\s*/i', '', $seg);
                $flatNo = trim($val);
                if (empty($flatNo)) $flatNo = trim($seg);
            }
        }

        $buildingName = '';
        foreach ($buildingsList as $bName) {
            if (stripos($cleanAddr, $bName) !== false) {
                $buildingName = $bName;
                break;
            }
        }

        $areaName = '';
        $normalizedAddr = preg_replace('/[^a-z0-9]/i', '', $cleanAddr);

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
            'house_no' => $houseNo,
            'flat_no' => $flatNo,
            'building_name' => $buildingName,
            'area_name' => $areaName,
            'customer_type' => $customerType,
        ];
    }

    private function cleanHeader(string $header): string
    {
        $clean = trim($header);
        $clean = preg_replace('/[_\s]+/', ' ', $clean);
        $replacements = [
            'id' => 'ID',
            'dob' => 'Date of Birth',
            'ssn' => 'SSN',
            'qty' => 'Quantity',
        ];

        $words = explode(' ', $clean);
        $formattedWords = array_map(function ($w) use ($replacements) {
            $lower = strtolower($w);
            if (isset($replacements[$lower])) return $replacements[$lower];
            return ucfirst($lower);
        }, $words);

        return implode(' ', $formattedWords);
    }

    private function parseAndFormatDate($value): ?string
    {
        if (is_numeric($value) && $value > 25000 && $value < 60000) {
            try {
                $dt = ExcelDate::excelToDateTimeObject($value);
                return $dt->format('M-y');
            } catch (\Throwable $e) {
            }
        }

        if (is_string($value)) {
            $value = trim($value);
            try {
                return Carbon::parse($value)->format('M-y');
            } catch (\Throwable $e) {
                return null;
            }
        }

        return null;
    }

    private function extractNumericValue($value): ?float
    {
        if (is_numeric($value)) {
            return (float) $value;
        }

        if (is_string($value)) {
            $clean = preg_replace('/[^\d\.\-]/', '', $value);
            if (is_numeric($clean) && strlen($clean) > 0) {
                return (float) $clean;
            }
        }

        return null;
    }

    private function cleanPhoneNumber(string $phone): string
    {
        $trimmed = trim($phone);
        $hasPlus = Str::startsWith($trimmed, '+');
        $digitsOnly = preg_replace('/[^\d]/', '', $trimmed);

        if (empty($digitsOnly)) return $phone;
        if ($hasPlus) return '+' . $digitsOnly;
        if (strlen($digitsOnly) === 10) {
            return sprintf('(%s) %s-%s', substr($digitsOnly, 0, 3), substr($digitsOnly, 3, 3), substr($digitsOnly, 6));
        }

        return $digitsOnly;
    }

    /**
     * Bulk Ingest rows from a formatted Excel file into customer_records SQL table
     */
    public function syncDatabaseRecordsFromExcelFile(int $processedFileId, string $filePath, ?string $billingMonth = null): void
    {
        if (!file_exists($filePath)) return;

        @ini_set('memory_limit', '1024M');
        @set_time_limit(300);

        if (empty($billingMonth)) {
            $billingMonth = Carbon::now()->format('F Y');
        }

        $reader = IOFactory::createReaderForFile($filePath);
        if (method_exists($reader, 'setReadDataOnly')) {
            $reader->setReadDataOnly(true);
        }
        
        $spreadsheet = $reader->load($filePath);
        $sheet = $spreadsheet->getSheet(0);

        $highestRow = $sheet->getHighestRow();
        $highestColumn = $sheet->getHighestColumn();
        $highestColumnIndex = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($highestColumn);

        CustomerMonthlyBilling::where('processed_file_id', $processedFileId)->delete();

        $headers = [];
        for ($c = 1; $c <= $highestColumnIndex; $c++) {
            $headers[$c] = (string)$sheet->getCellByColumnAndRow($c, 1)->getValue();
        }

        $collectorCol = null;
        $areaCol = null;
        $bldgCol = null;
        $houseCol = null;
        $flatCol = null;
        $addCol = null;
        $typeCol = null;
        $categoryCol = null;
        $statusCol = null;
        $nameCol = null;
        $idCol = null;
        $rentCol = null;
        $advCol = null;
        $duesCol = null;
        $discountCol = null;

        // Strictly match Collector / Collector Name column (completely ignoring Connected By)
        foreach ($headers as $c => $h) {
            $hLower = strtolower(trim($h));
            if (Str::contains($hLower, ['collector name', 'collector_name', 'collector', 'collectorname'])) {
                $collectorCol = $c;
                break;
            }
        }

        foreach ($headers as $c => $h) {
            $hLower = strtolower(trim($h));
            $isTotalCol = Str::contains($hLower, ['total', 'grand_total', 'subtotal', 'total_dues', 'total dues', 'total_amount', 'total amount']);

            // Skip Collector column so it never matches Customer Name or Customer ID
            if ($collectorCol && $c === $collectorCol) continue;
            if (Str::contains($hLower, ['collector'])) continue;

            if ($areaCol === null && Str::contains($hLower, ['area name', 'area_name', 'area'])) {
                $areaCol = $c;
            } elseif ($bldgCol === null && Str::contains($hLower, ['building name', 'building_name', 'building'])) {
                $bldgCol = $c;
            } elseif ($houseCol === null && Str::contains($hLower, ['house no', 'house_no', 'house'])) {
                $houseCol = $c;
            } elseif ($flatCol === null && Str::contains($hLower, ['flat no', 'flat_no', 'flat'])) {
                $flatCol = $c;
            } elseif ($addCol === null && Str::contains($hLower, ['add_combined', 'add'])) {
                $addCol = $c;
            } elseif ($typeCol === null && Str::contains($hLower, ['customer type', 'customer_type', 'type'])) {
                $typeCol = $c;
            } elseif ($categoryCol === null && Str::contains($hLower, ['category', 'customer category'])) {
                $categoryCol = $c;
            } elseif ($statusCol === null && Str::contains($hLower, ['status', 'customer_status'])) {
                $statusCol = $c;
            } elseif ($nameCol === null && Str::contains($hLower, ['full name', 'full_name', 'customer name', 'customer_name', 'client name', 'client_name', 'name'])) {
                $nameCol = $c;
            } elseif ($idCol === null && Str::contains($hLower, ['customer id', 'customer_id', 'emp id', 'emp_id', 'client id', 'client_id', 'customer code', 'code', 'cust_id', 'id'])) {
                $idCol = $c;
            } elseif ($advCol === null && Str::contains($hLower, ['advance', 'adv'])) {
                $advCol = $c;
            } elseif (!$isTotalCol && $discountCol === null && Str::contains($hLower, ['discount', 'disc', 'less'])) {
                $discountCol = $c;
            } elseif (!$isTotalCol && $duesCol === null && Str::contains($hLower, ['previous dues', 'previous_dues', 'prev dues', 'prev_dues', 'previous_baki', 'bokea'])) {
                $duesCol = $c;
            } elseif (!$isTotalCol && $duesCol === null && Str::contains($hLower, ['dues', 'due', 'arrear', 'baki'])) {
                $duesCol = $c;
            } elseif (!$isTotalCol && $rentCol === null && Str::contains($hLower, ['monthly rent', 'monthly_rent', 'rent', 'salary', 'bill', 'fee'])) {
                $rentCol = $c;
            }
        }

        $records = [];
        $monthlyBillings = [];
        $masterProfiles = [];
        $now = now();
        $nowStr = $now->toDateTimeString();

        for ($r = 2; $r <= $highestRow; $r++) {
            $firstVal = trim((string)$sheet->getCellByColumnAndRow(1, $r)->getValue());
            if (strcasecmp($firstVal, 'TOTALS') === 0 || strcasecmp($firstVal, 'TOTAL') === 0) {
                continue;
            }

            $collectorName = $collectorCol ? trim((string)$sheet->getCellByColumnAndRow($collectorCol, $r)->getValue()) : '';
            $areaName = $areaCol ? trim((string)$sheet->getCellByColumnAndRow($areaCol, $r)->getValue()) : '';
            $bldgName = $bldgCol ? trim((string)$sheet->getCellByColumnAndRow($bldgCol, $r)->getValue()) : '';
            $houseNo = $houseCol ? trim((string)$sheet->getCellByColumnAndRow($houseCol, $r)->getValue()) : '';
            $flatNo = $flatCol ? trim((string)$sheet->getCellByColumnAndRow($flatCol, $r)->getValue()) : '';
            $addComb = $addCol ? trim((string)$sheet->getCellByColumnAndRow($addCol, $r)->getValue()) : '';
            $type = $typeCol ? trim((string)$sheet->getCellByColumnAndRow($typeCol, $r)->getValue()) : 'Analog';
            $status = $statusCol ? trim((string)$sheet->getCellByColumnAndRow($statusCol, $r)->getValue()) : 'Active';
            $fullName = $nameCol ? trim((string)$sheet->getCellByColumnAndRow($nameCol, $r)->getValue()) : '';
            $custId = $idCol ? trim((string)$sheet->getCellByColumnAndRow($idCol, $r)->getValue()) : '';

            $category = 'Army';
            if ($categoryCol) {
                $cVal = trim((string)$sheet->getCellByColumnAndRow($categoryCol, $r)->getValue());
                if (!empty($cVal)) $category = $cVal;
            }

            if ($category === 'Army') {
                $rowAllText = '';
                for ($c = 1; $c <= $highestColumnIndex; $c++) {
                    $rowAllText .= ' ' . (string)$sheet->getCellByColumnAndRow($c, $r)->getValue();
                }
                if (stripos($rowAllText, 'civil') !== false) {
                    $category = 'Civil';
                }
            }

            // Extract child subscriber count from number after the last comma in raw address or row
            $childCount = 0;
            $addrSearchStr = '';
            for ($c = 1; $c <= $highestColumnIndex; $c++) {
                $hName = strtolower(trim($headers[$c] ?? ''));
                if (Str::contains($hName, ['address', 'customer_address', 'full_address', 'location', 'add'])) {
                    $cellVal = trim((string)$sheet->getCellByColumnAndRow($c, $r)->getValue());
                    if (!empty($cellVal)) {
                        $addrSearchStr = $cellVal;
                        break;
                    }
                }
            }
            if (empty($addrSearchStr)) {
                $addrSearchStr = $addComb;
            }

            if (!empty($addrSearchStr) && strpos($addrSearchStr, ',') !== false) {
                $afterLastComma = trim(substr($addrSearchStr, strrpos($addrSearchStr, ',') + 1));
                if (preg_match('/^(\d+)/', $afterLastComma, $m)) {
                    $childCount = (int)$m[1];
                }
            }

            $rent = $rentCol ? ($this->extractNumericValue($sheet->getCellByColumnAndRow($rentCol, $r)->getValue()) ?? 0.0) : 0.0;
            $adv = $advCol ? ($this->extractNumericValue($sheet->getCellByColumnAndRow($advCol, $r)->getValue()) ?? 0.0) : 0.0;
            $dues = $duesCol ? ($this->extractNumericValue($sheet->getCellByColumnAndRow($duesCol, $r)->getValue()) ?? 0.0) : 0.0;
            $discount = $discountCol ? ($this->extractNumericValue($sheet->getCellByColumnAndRow($discountCol, $r)->getValue()) ?? 0.0) : 0.0;

            $actualBill = max(0.0, $rent - $adv - $discount);
            $fiftyPercent = $dues * 0.5;
            $target = $actualBill + $fiftyPercent;

            $rawData = [];
            for ($c = 1; $c <= $highestColumnIndex; $c++) {
                $hName = $headers[$c] ?? "Col_$c";
                $rawData[$hName] = $sheet->getCellByColumnAndRow($c, $r)->getValue();
            }

            $finalCollector = !empty($collectorName) ? $collectorName : 'Unassigned / Unmapped';
            $finalType = !empty($type) ? $type : 'Analog';
            $finalStatus = !empty($status) ? $status : 'Active';

            // Master profile snapshot
            if (!empty($custId)) {
                $masterProfiles[$custId] = [
                    'customer_id'    => $custId,
                    'full_name'      => $fullName,
                    'customer_type'  => $finalType,
                    'category'       => $category,
                    'status'         => $finalStatus,
                    'collector_name' => $finalCollector,
                    'area_name'      => $areaName,
                    'building_name'  => $bldgName,
                    'house_no'       => $houseNo,
                    'flat_no'        => $flatNo,
                    'add_combined'   => $addComb,
                    'raw_data_json'  => json_encode($rawData),
                    'created_at'     => $nowStr,
                    'updated_at'     => $nowStr,
                ];
            }

            $rowRecord = [
                'processed_file_id' => $processedFileId,
                'billing_month' => $billingMonth,
                'row_index' => $r,
                'collector_name' => $finalCollector,
                'area_name' => $areaName,
                'building_name' => $bldgName,
                'house_no' => $houseNo,
                'flat_no' => $flatNo,
                'add_combined' => $addComb,
                'customer_type' => $finalType,
                'category' => $category,
                'child_count' => $childCount,
                'status' => $finalStatus,
                'full_name' => $fullName,
                'customer_id' => $custId,
                'monthly_rent' => $rent,
                'advance' => $adv,
                'previous_dues' => $dues,
                'discount' => $discount,
                'actual_bill' => $actualBill,
                'fifty_percent' => $fiftyPercent,
                'target' => $target,
                'raw_data_json' => json_encode($rawData),
                'created_at' => $now,
                'updated_at' => $now,
            ];

            $records[] = $rowRecord;
        }

        // Upsert Master Customer profiles
        if (!empty($masterProfiles)) {
            foreach ($masterProfiles as $cid => $prof) {
                Customer::updateOrCreate(
                    ['customer_id' => $cid],
                    array_filter([
                        'full_name'      => $prof['full_name'],
                        'customer_type'  => $prof['customer_type'],
                        'category'       => $prof['category'],
                        'status'         => $prof['status'],
                        'collector_name' => $prof['collector_name'],
                        'area_name'      => $prof['area_name'],
                        'building_name'  => $prof['building_name'],
                        'house_no'       => $prof['house_no'],
                        'flat_no'        => $prof['flat_no'],
                        'add_combined'   => $prof['add_combined'],
                        'raw_data_json'  => $prof['raw_data_json'],
                    ])
                );
            }
        }

        $masterMap = Customer::pluck('id', 'customer_id')->toArray();

        foreach ($records as $rec) {
            $mbRec = $rec;
            $mbRec['master_customer_id'] = !empty($rec['customer_id']) ? ($masterMap[$rec['customer_id']] ?? null) : null;
            $monthlyBillings[] = $mbRec;
        }

        DB::transaction(function () use ($monthlyBillings) {
            foreach (array_chunk($monthlyBillings, 500) as $chunk) {
                DB::table('customer_monthly_billings')->insert($chunk);
            }
        });
    }

    /**
     * Sub-20ms Database Fetch for Data Grid & Editor
     */
    public function readProcessedFileDataFromDatabase(int $processedFileId): array
    {
        $dbRecords = CustomerMonthlyBilling::where('processed_file_id', $processedFileId)
            ->orderBy('row_index', 'asc')
            ->get();

        if ($dbRecords->isEmpty()) {
            return ['headers' => [], 'rows' => [], 'total_rows' => 0];
        }

        $sampleRaw = $dbRecords->first()->raw_data_json;
        $headers = is_array($sampleRaw) ? array_keys($sampleRaw) : array_keys(json_decode($sampleRaw, true) ?? []);

        $rows = [];
        foreach ($dbRecords as $rec) {
            $rowData = $rec->raw_data_json;
            if (is_string($rowData)) {
                $rowData = json_decode($rowData, true) ?? [];
            }
            $rowData['_row_index'] = $rec->row_index;
            $rowData['Category'] = $rec->category ?? 'Army';
            $rowData['Status'] = $rec->status;

            $duesVal = (float)$rec->previous_dues;
            if ($duesVal == 0.0) {
                // Pass 1: Explicit Previous Dues keys (excluding 'total')
                foreach ($rowData as $k => $v) {
                    $kLower = strtolower(trim((string)$k));
                    if (Str::contains($kLower, ['total'])) continue;
                    if (Str::contains($kLower, ['previous dues', 'previous_dues', 'prev dues', 'prev_dues', 'previous_baki', 'bokea'])) {
                        if ($v !== null && $v !== '') {
                            $parsedDues = (float) preg_replace('/[^0-9.-]/', '', (string)$v);
                            if ($parsedDues > 0) {
                                $duesVal = $parsedDues;
                                break;
                            }
                        }
                    }
                }
                // Pass 2: Standalone dues keys (excluding 'total')
                if ($duesVal == 0.0) {
                    foreach ($rowData as $k => $v) {
                        $kLower = strtolower(trim((string)$k));
                        if (Str::contains($kLower, ['total'])) continue;
                        if (Str::contains($kLower, ['dues', 'due', 'arrear', 'baki'])) {
                            if ($v !== null && $v !== '') {
                                $parsedDues = (float) preg_replace('/[^0-9.-]/', '', (string)$v);
                                if ($parsedDues > 0) {
                                    $duesVal = $parsedDues;
                                    break;
                                }
                            }
                        }
                    }
                }
            }

            $rentVal = (float)$rec->monthly_rent;
            if ($rentVal == 0.0) {
                foreach ($rowData as $k => $v) {
                    $kLower = strtolower(trim((string)$k));
                    if (Str::contains($kLower, ['total'])) continue;
                    if (Str::contains($kLower, ['monthly rent', 'monthly_rent', 'rent', 'salary', 'bill', 'fee'])) {
                        if ($v !== null && $v !== '') {
                            $parsedRent = (float) preg_replace('/[^0-9.-]/', '', (string)$v);
                            if ($parsedRent > 0) {
                                $rentVal = $parsedRent;
                                break;
                            }
                        }
                    }
                }
            }

            $discountVal = (float)$rec->discount;
            if ($discountVal == 0.0) {
                foreach ($rowData as $k => $v) {
                    $kLower = strtolower(trim((string)$k));
                    if (Str::contains($kLower, ['total'])) continue;
                    if (Str::contains($kLower, ['discount', 'disc', 'less'])) {
                        if ($v !== null && $v !== '') {
                            $parsedDisc = (float) preg_replace('/[^0-9.-]/', '', (string)$v);
                            if ($parsedDisc > 0) {
                                $discountVal = $parsedDisc;
                                break;
                            }
                        }
                    }
                }
            }

            $advanceVal = (float)$rec->advance;
            if ($advanceVal == 0.0) {
                foreach ($rowData as $k => $v) {
                    $kLower = strtolower(trim((string)$k));
                    if (Str::contains($kLower, ['total'])) continue;
                    if (Str::contains($kLower, ['advance', 'adv', 'agrim'])) {
                        if ($v !== null && $v !== '') {
                            $parsedAdv = (float) preg_replace('/[^0-9.-]/', '', (string)$v);
                            if ($parsedAdv > 0) {
                                $advanceVal = $parsedAdv;
                                break;
                            }
                        }
                    }
                }
            }

            $rowData['_db_previous_dues'] = $duesVal;
            $rowData['_db_monthly_rent'] = $rentVal;
            $rowData['_db_discount'] = $discountVal;
            $rowData['_db_advance'] = $advanceVal;
            $rowData['_db_actual_bill'] = (float)$rec->actual_bill;
            $rows[] = $rowData;
        }

        return [
            'headers' => $headers,
            'rows' => $rows,
            'total_rows' => count($rows),
        ];
    }

    /**
     * Ultra-Fast Database Row Updates (Runs in 1-5ms)
     */
    public function updateProcessedFileDataInDatabase(int $processedFileId, array $rowUpdates, ?string $outputFilePath = null): bool
    {
        foreach ($rowUpdates as $update) {
            $r = (int) ($update['row_index'] ?? 0);
            if ($r < 2) continue;

            $dbRec = CustomerMonthlyBilling::where('processed_file_id', $processedFileId)
                ->where('row_index', $r)
                ->first();

            if (!$dbRec) continue;

            $changes = $update['changes'] ?? [];
            $raw = $dbRec->raw_data_json;
            if (is_string($raw)) {
                $raw = json_decode($raw, true) ?? [];
            }

            foreach ($changes as $colName => $newVal) {
                $raw[$colName] = $newVal;
                
                $colKey = strtolower(trim($colName));
                if ($colKey === 'status' || $colKey === 'customer_status') {
                    $dbRec->status = trim((string)$newVal);
                } elseif (Str::contains($colKey, ['collector name', 'collector_name', 'collector', 'collectorname'])) {
                    $dbRec->collector_name = trim((string)$newVal);
                } elseif (Str::contains($colKey, ['advance', 'adv'])) {
                    $dbRec->advance = is_numeric($newVal) ? (float)$newVal : 0.0;
                } elseif (Str::contains($colKey, ['discount', 'disc', 'less'])) {
                    $dbRec->discount = is_numeric($newVal) ? (float)$newVal : 0.0;
                } elseif (Str::contains($colKey, ['previous dues', 'prev dues', 'previous_dues'])) {
                    $dbRec->previous_dues = is_numeric($newVal) ? (float)$newVal : 0.0;
                } elseif (!Str::contains($colKey, ['total']) && Str::contains($colKey, ['rent', 'salary', 'bill', 'amount', 'price', 'fee', 'monthly rent'])) {
                    $dbRec->monthly_rent = is_numeric($newVal) ? (float)$newVal : 0.0;
                } elseif (Str::contains($colKey, ['house', 'house_no', 'house no'])) {
                    $dbRec->house_no = trim((string)$newVal);
                } elseif (Str::contains($colKey, ['flat', 'flat_no', 'flat no'])) {
                    $dbRec->flat_no = trim((string)$newVal);
                } elseif (Str::contains($colKey, ['area', 'area_name', 'area name'])) {
                    $dbRec->area_name = trim((string)$newVal);
                } elseif (Str::contains($colKey, ['building', 'building_name', 'building name'])) {
                    $dbRec->building_name = trim((string)$newVal);
                }
            }

            $dbRec->actual_bill = max(0.0, $dbRec->monthly_rent - $dbRec->advance - $dbRec->discount);
            $dbRec->fifty_percent = $dbRec->previous_dues * 0.5;
            $dbRec->target = $dbRec->actual_bill + $dbRec->fifty_percent;
            $dbRec->raw_data_json = $raw;
            $dbRec->save();
        }

        if ($outputFilePath && file_exists($outputFilePath)) {
            $this->updateProcessedFileData($outputFilePath, $rowUpdates);
        }

        return true;
    }

    /**
     * Extract child count dynamically from DB column, raw address, or raw JSON
     */
    private function extractChildCountFromRecord($r): int
    {
        if (!empty($r->child_count) && (int)$r->child_count > 0) {
            return (int)$r->child_count;
        }

        $raw = $r->raw_data_json;
        if (is_string($raw)) {
            $raw = json_decode($raw, true) ?? [];
        }

        // 1. Check direct columns in raw data (e.g., 'Child', 'Sub', 'Connections')
        foreach ($raw as $col => $val) {
            $colKey = strtolower(trim((string)$col));
            if (Str::contains($colKey, ['child', 'sub', 'secondary', 'connection_count', 'connections'])) {
                if (is_numeric($val) && (int)$val > 0) {
                    return (int)$val;
                }
            }
        }

        // 2. Search address text after last comma
        $addrText = $r->add_combined;
        if (empty($addrText) && isset($raw['Address'])) {
            $addrText = (string)$raw['Address'];
        }
        if (empty($addrText) && isset($raw['Add'])) {
            $addrText = (string)$raw['Add'];
        }

        if (!empty($addrText) && strpos($addrText, ',') !== false) {
            $lastSegment = trim(substr($addrText, strrpos($addrText, ',') + 1));
            if (preg_match('/\b(\d+)\b/', $lastSegment, $m)) {
                $num = (int)$m[1];
                if ($num >= 1 && $num <= 20) {
                    return $num;
                }
            }
        }

        // 3. Fallback: Search all raw cell values for comma numbers
        foreach ($raw as $val) {
            $valStr = trim((string)$val);
            if (strpos($valStr, ',') !== false) {
                $lastSeg = trim(substr($valStr, strrpos($valStr, ',') + 1));
                if (preg_match('/^(\d+)$/', $lastSeg, $m)) {
                    $num = (int)$m[1];
                    if ($num >= 1 && $num <= 20) {
                        return $num;
                    }
                }
            }
        }

        return 0;
    }

    /**
     * Generate Active Customer Count & Cumulative Child Summary Matrix grouped by Customer Type and Category
     */
    public function getActiveCustomerTypeCategorySummary(?int $processedFileId = null, ?string $billingMonth = null, ?int $userId = null): array
    {
        if ($processedFileId) {
            $records = CustomerMonthlyBilling::where('processed_file_id', $processedFileId)
                ->where('status', 'Active')
                ->get();
        } elseif ($billingMonth) {
            $query = CustomerMonthlyBilling::where('billing_month', $billingMonth)->where('status', 'Active');
            if ($userId) {
                $query->whereHas('processedFile', function($q) use ($userId) {
                    $q->where('user_id', $userId);
                });
            }
            $records = $query->get();
        } else {
            // Master active customers or latest file active records
            $latestFile = ProcessedFile::when($userId, fn($q) => $q->where('user_id', $userId))
                ->where('status', 'completed')
                ->latest()
                ->first();

            if ($latestFile) {
                $records = CustomerMonthlyBilling::where('processed_file_id', $latestFile->id)
                    ->where('status', 'Active')
                    ->get();
            } else {
                $records = CustomerMonthlyBilling::where('status', 'Active')->get();
            }
        }

        $summary = [];
        $totalActive = 0;
        $totalFirstChild = 0;
        $totalSecondChild = 0;
        $totalThirdChild = 0;

        foreach ($records as $r) {
            $type = !empty($r->customer_type) ? $r->customer_type : 'Analog';
            $cat = !empty($r->category) ? $r->category : 'Army';
            $key = $type . '___' . $cat;

            if (!isset($summary[$key])) {
                $summary[$key] = [
                    'customer_type' => $type,
                    'category' => $cat,
                    'active_count' => 0,
                    'first_child_count' => 0,
                    'second_child_count' => 0,
                    'third_child_count' => 0,
                    'total_child_count' => 0,
                ];
            }

            $isDigital = (stripos($type, 'digital') !== false);
            $n = $isDigital ? $this->extractChildCountFromRecord($r) : 0;
            $summary[$key]['active_count']++;
            $totalActive++;

            if ($isDigital) {
                if ($n === 1) {
                    $summary[$key]['first_child_count']++;
                    $totalFirstChild++;
                } elseif ($n === 2) {
                    $summary[$key]['second_child_count']++;
                    $totalSecondChild++;
                } elseif ($n >= 3 && $n <= 10) {
                    $summary[$key]['third_child_count']++;
                    $totalThirdChild++;
                }
            }
            $summary[$key]['total_child_count'] = $summary[$key]['first_child_count'] + $summary[$key]['second_child_count'] + $summary[$key]['third_child_count'];
        }

        $items = array_values($summary);
        usort($items, function($a, $b) {
            if ($a['customer_type'] === $b['customer_type']) {
                return strcmp($a['category'], $b['category']);
            }
            return strcmp($a['customer_type'], $b['customer_type']);
        });

        $grandActive = 0;
        $grandFirst = 0;
        $grandSecond = 0;
        $grandThird = 0;
        $grandTotalChild = 0;

        foreach ($items as $it) {
            $grandActive += (int)$it['active_count'];
            $grandFirst += (int)$it['first_child_count'];
            $grandSecond += (int)$it['second_child_count'];
            $grandThird += (int)$it['third_child_count'];
            $grandTotalChild += (int)$it['total_child_count'];
        }

        return [
            'summary_items' => $items,
            'grand_total' => [
                'active_count' => $grandActive,
                'first_child_count' => $grandFirst,
                'second_child_count' => $grandSecond,
                'third_child_count' => $grandThird,
                'total_child_count' => $grandTotalChild,
                'combined_total' => $grandActive + $grandTotalChild,
            ]
        ];
    }

    /**
     * Compare two selected months to audit New Connections & Line Disconnections
     */
    public function compareTwoMonthsCustomerRecords(string $baseMonth, string $compareMonth, ?int $userId = null): array
    {
        // Guard: Base Month (Month A) must always be chronologically earlier than Compare Month (Month B)
        $timeBase = strtotime("1 " . $baseMonth);
        $timeCompare = strtotime("1 " . $compareMonth);
        if ($timeBase && $timeCompare && $timeBase > $timeCompare) {
            $temp = $baseMonth;
            $baseMonth = $compareMonth;
            $compareMonth = $temp;
        }

        $baseQuery = CustomerMonthlyBilling::with('processedFile')
            ->where('billing_month', $baseMonth)
            ->where('status', 'Active');
        if ($userId) {
            $baseQuery->whereHas('processedFile', function($q) use ($userId) {
                $q->where('user_id', $userId);
            });
        }
        $baseRecords = $baseQuery->get();

        $compareQuery = CustomerMonthlyBilling::with('processedFile')
            ->where('billing_month', $compareMonth)
            ->where('status', 'Active');
        if ($userId) {
            $compareQuery->whereHas('processedFile', function($q) use ($userId) {
                $q->where('user_id', $userId);
            });
        }
        $compareRecords = $compareQuery->get();

        $mapBase = [];
        foreach ($baseRecords as $rec) {
            $key = $this->extractCustomerUniqueKey($rec);
            if ($key && !isset($mapBase[$key])) {
                $mapBase[$key] = $rec;
            }
        }

        $mapCompare = [];
        foreach ($compareRecords as $rec) {
            $key = $this->extractCustomerUniqueKey($rec);
            if ($key && !isset($mapCompare[$key])) {
                $mapCompare[$key] = $rec;
            }
        }

        $disconnections = [];
        foreach ($mapBase as $key => $rec) {
            if (!isset($mapCompare[$key])) {
                $disconnections[] = $this->formatComparisonRecordItem($rec);
            }
        }

        $newConnections = [];
        foreach ($mapCompare as $key => $rec) {
            if (!isset($mapBase[$key])) {
                $newConnections[] = $this->formatComparisonRecordItem($rec);
            }
        }

        $retained = [];
        foreach ($mapCompare as $key => $rec) {
            if (isset($mapBase[$key])) {
                $retained[] = $this->formatComparisonRecordItem($rec);
            }
        }

        usort($disconnections, fn($a, $b) => strcmp($a['full_name'], $b['full_name']));
        usort($newConnections, fn($a, $b) => strcmp($a['full_name'], $b['full_name']));
        usort($retained, fn($a, $b) => strcmp($a['full_name'], $b['full_name']));

        $disconnStats = $this->calculateComparisonGroupStats($disconnections);
        $newConnStats = $this->calculateComparisonGroupStats($newConnections);

        $baseCount = count($mapBase);
        $compareCount = count($mapCompare);
        $newCount = count($newConnections);
        $disconnCount = count($disconnections);
        $retainedCount = count($retained);
        $netChange = $newCount - $disconnCount;

        return [
            'base_month' => $baseMonth,
            'compare_month' => $compareMonth,
            'base_total_count' => $baseCount,
            'compare_total_count' => $compareCount,
            'new_connections_count' => $newCount,
            'disconnections_count' => $disconnCount,
            'retained_count' => $retainedCount,
            'net_change' => $netChange,
            'new_connections' => $newConnections,
            'disconnections' => $disconnections,
            'retained' => $retained,
            'new_connections_stats' => $newConnStats,
            'disconnections_stats' => $disconnStats,
        ];
    }

    private function extractCustomerUniqueKey($rec): string
    {
        $raw = $rec->raw_data_json;
        if (is_string($raw)) {
            $raw = json_decode($raw, true) ?? [];
        }

        $custId = trim((string)$rec->customer_id);
        if (empty($custId)) {
            foreach (['Customer ID', 'ID', 'emp_id', 'Code', 'SL', 'client_id', 'customer_code', 'customer_id'] as $k) {
                if (isset($raw[$k]) && $raw[$k] !== null && $raw[$k] !== '') {
                    $custId = trim((string)$raw[$k]);
                    break;
                }
            }
        }

        if (!empty($custId)) {
            return 'ID_' . strtolower($custId);
        }

        $name = strtolower(trim((string)$rec->full_name));
        $add = strtolower(trim((string)$rec->add_combined));
        if (empty($name)) {
            foreach (['Full Name', 'Customer Name', 'full_name', 'Name'] as $nk) {
                if (isset($raw[$nk]) && !empty(trim((string)$raw[$nk]))) {
                    $name = strtolower(trim((string)$raw[$nk]));
                    break;
                }
            }
        }

        return 'NAME_ADDR_' . md5($name . '___' . $add);
    }

    private function formatComparisonRecordItem($rec): array
    {
        $raw = $rec->raw_data_json;
        if (is_string($raw)) {
            $raw = json_decode($raw, true) ?? [];
        }

        $custId = $rec->customer_id;
        if (empty($custId)) {
            foreach (['Customer ID', 'ID', 'emp_id', 'Code', 'SL', 'client_id', 'customer_code', 'customer_id'] as $k) {
                if (isset($raw[$k]) && $raw[$k] !== null && $raw[$k] !== '') {
                    $custId = (string)$raw[$k];
                    break;
                }
            }
        }

        $fullName = $rec->full_name;
        if (empty($fullName)) {
            foreach (['Full Name', 'Customer Name', 'full_name', 'Name'] as $nk) {
                if (isset($raw[$nk]) && !empty(trim((string)$raw[$nk]))) {
                    $fullName = trim((string)$raw[$nk]);
                    break;
                }
            }
        }

        $bldg = trim((string)$rec->building_name);
        $area = trim((string)$rec->area_name);
        $hasBldg = !empty($bldg) && strcasecmp($bldg, 'N/A') !== 0;
        $hasArea = !empty($area) && strcasecmp($area, 'N/A') !== 0;

        $rawAdd = trim((string)$rec->add_combined);
        $hasRawAdd = !empty($rawAdd) && strcasecmp($rawAdd, 'N/A') !== 0;

        if ($hasBldg) {
            if ($hasRawAdd) {
                $address = (stripos($rawAdd, $bldg) !== false) ? $rawAdd : "{$rawAdd}, {$bldg}";
            } else {
                $address = $bldg;
            }
        } elseif ($hasArea) {
            if ($hasRawAdd) {
                $address = (stripos($rawAdd, $area) !== false) ? $rawAdd : "{$rawAdd}, {$area}";
            } else {
                $address = $area;
            }
        } else {
            $address = $hasRawAdd ? $rawAdd : 'N/A';
        }

        return [
            'id' => $rec->id,
            'customer_id' => $custId ?: ('ID #' . $rec->id),
            'full_name' => $fullName ?: 'N/A',
            'customer_type' => $rec->customer_type ?: 'Analog',
            'category' => $rec->category ?: 'Army',
            'collector_name' => $rec->collector_name ?: 'Unassigned',
            'area_name' => $rec->area_name ?: 'N/A',
            'building_name' => $rec->building_name ?: 'N/A',
            'house_no' => $rec->house_no ?: '',
            'flat_no' => $rec->flat_no ?: '',
            'add_combined' => $address,
            'monthly_rent' => (float)$rec->monthly_rent,
            'previous_dues' => (float)$rec->previous_dues,
            'billing_month' => $rec->billing_month,
            'status' => $rec->status,
        ];
    }

    private function calculateComparisonGroupStats(array $items): array
    {
        $digitalCount = 0;
        $analogCount = 0;
        $armyCount = 0;
        $civilCount = 0;

        foreach ($items as $it) {
            $isDigital = (stripos($it['customer_type'], 'digital') !== false);
            if ($isDigital) {
                $digitalCount++;
            } else {
                $analogCount++;
            }

            if (strcasecmp($it['category'], 'Civil') === 0) {
                $civilCount++;
            } else {
                $armyCount++;
            }
        }

        return [
            'total' => count($items),
            'digital_count' => $digitalCount,
            'analog_count' => $analogCount,
            'army_count' => $armyCount,
            'civil_count' => $civilCount,
        ];
    }

    public function generateComparisonExcelReport(string $baseMonth, string $compareMonth, array $data, string $outputPath): string
    {
        // Guard: Base Month (Month A) must always be chronologically earlier than Compare Month (Month B)
        $timeBase = strtotime("1 " . $baseMonth);
        $timeCompare = strtotime("1 " . $compareMonth);
        if ($timeBase && $timeCompare && $timeBase > $timeCompare) {
            $temp = $baseMonth;
            $baseMonth = $compareMonth;
            $compareMonth = $temp;
        }

        $spreadsheet = new Spreadsheet();
        $spreadsheet->getDefaultStyle()->getFont()->setName('Noto Sans Bengali');

        $sheet1 = $spreadsheet->getActiveSheet();
        $sheet1->setTitle('Comparison Summary');

        $sheet1->setCellValue('A1', "MONTHLY CUSTOMER CONNECTION AUDIT & COMPARISON REPORT");
        $sheet1->mergeCells('A1:E1');
        $sheet1->getStyle('A1')->applyFromArray([
            'font' => ['bold' => true, 'size' => 14, 'color' => ['rgb' => 'FFFFFF']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '1E293B']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
        ]);

        $sheet1->setCellValue('A2', "Base Month (Month A): {$baseMonth} | Compare Month (Month B): {$compareMonth}");
        $sheet1->mergeCells('A2:E2');
        $sheet1->getStyle('A2')->applyFromArray([
            'font' => ['italic' => true, 'color' => ['rgb' => 'FFFFFF']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '334155']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
        ]);

        $summaryRows = [
            ['Metric Parameter', 'Value / Count'],
            ["Base Month Subscribers ({$baseMonth})", $data['base_total_count']],
            ["Compare Month Subscribers ({$compareMonth})", $data['compare_total_count']],
            ["New Connections Added (in {$compareMonth} only)", $data['new_connections_count']],
            ["Disconnections Lost (in {$baseMonth} only)", $data['disconnections_count']],
            ["Retained Active Connections", $data['retained_count']],
            ["Net Subscriber Growth / Change", $data['net_change']],
        ];

        $sheet1->fromArray($summaryRows, null, 'A4');
        $sheet1->getStyle('A4:B4')->applyFromArray([
            'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '0F172A']],
        ]);

        $sheet2 = $spreadsheet->createSheet();
        $sheet2->setTitle('New Connections');
        $sheet2->setCellValue('A1', "NEW CONNECTIONS ADDED IN {$compareMonth} (NOT IN {$baseMonth})");
        $sheet2->mergeCells('A1:G1');
        $sheet2->getStyle('A1')->applyFromArray([
            'font' => ['bold' => true, 'size' => 12, 'color' => ['rgb' => 'FFFFFF']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '059669']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
        ]);

        $headers = ['Customer ID', 'Full Name', 'Address', 'Customer Type', 'Category', 'Collector', 'Monthly Rent'];
        $sheet2->fromArray([$headers], null, 'A3');
        $sheet2->getStyle('A3:G3')->applyFromArray([
            'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '047857']],
        ]);

        $newRows = [];
        foreach ($data['new_connections'] as $item) {
            $newRows[] = [
                $item['customer_id'],
                $item['full_name'],
                $item['add_combined'],
                $item['customer_type'],
                $item['category'],
                $item['collector_name'],
                $item['monthly_rent'],
            ];
        }
        if (!empty($newRows)) {
            $sheet2->fromArray($newRows, null, 'A4');
        }

        $sheet3 = $spreadsheet->createSheet();
        $sheet3->setTitle('Disconnections');
        $sheet3->setCellValue('A1', "LINE DISCONNECTIONS LOST FROM {$baseMonth} (NOT IN {$compareMonth})");
        $sheet3->mergeCells('A1:G1');
        $sheet3->getStyle('A1')->applyFromArray([
            'font' => ['bold' => true, 'size' => 12, 'color' => ['rgb' => 'FFFFFF']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'DC2626']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
        ]);

        $sheet3->fromArray([$headers], null, 'A3');
        $sheet3->getStyle('A3:G3')->applyFromArray([
            'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'B91C1C']],
        ]);

        $disconnRows = [];
        foreach ($data['disconnections'] as $item) {
            $disconnRows[] = [
                $item['customer_id'],
                $item['full_name'],
                $item['add_combined'],
                $item['customer_type'],
                $item['category'],
                $item['collector_name'],
                $item['monthly_rent'],
            ];
        }
        if (!empty($disconnRows)) {
            $sheet3->fromArray($disconnRows, null, 'A4');
        }

        $sheet4 = $spreadsheet->createSheet();
        $sheet4->setTitle('Retained Active');
        $sheet4->setCellValue('A1', "RETAINED ACTIVE CONNECTIONS (ACTIVE IN BOTH {$baseMonth} AND {$compareMonth})");
        $sheet4->mergeCells('A1:G1');
        $sheet4->getStyle('A1')->applyFromArray([
            'font' => ['bold' => true, 'size' => 12, 'color' => ['rgb' => 'FFFFFF']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '4F46E5']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
        ]);

        $sheet4->fromArray([$headers], null, 'A3');
        $sheet4->getStyle('A3:G3')->applyFromArray([
            'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '4338CA']],
        ]);

        $retainedRows = [];
        if (!empty($data['retained'])) {
            foreach ($data['retained'] as $item) {
                $retainedRows[] = [
                    $item['customer_id'],
                    $item['full_name'],
                    $item['add_combined'],
                    $item['customer_type'],
                    $item['category'],
                    $item['collector_name'],
                    $item['monthly_rent'],
                ];
            }
            $sheet4->fromArray($retainedRows, null, 'A4');
        }

        foreach ([$sheet1, $sheet2, $sheet3, $sheet4] as $s) {
            for ($col = 1; $col <= 7; $col++) {
                $colLetter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($col);
                $s->getColumnDimension($colLetter)->setAutoSize(true);
            }
        }

        $writer = new Xlsx($spreadsheet);
        $writer->save($outputPath);

        return $outputPath;
    }

    /**
     * Global Database Search for Customer Records across all files (or specific file) by Customer ID / Name
     * Supports Exact Match Filtering & Exact Match Priority Sorting
     */
    public function searchCustomerRecordsInDatabase(?int $userId, string $searchTerm, ?int $fileId = null, bool $exactMatch = false, ?string $collector = null): array
    {
        $term = trim($searchTerm);
        if (empty($term)) {
            return [];
        }

        $query = CustomerMonthlyBilling::with('processedFile');
        if ($userId) {
            $query->whereHas('processedFile', function ($q) use ($userId) {
                $q->where('user_id', $userId);
            });
        }

        if ($fileId) {
            $query->where('processed_file_id', $fileId);
        }

        // Apply Collector & Assigned Area Scope Filter
        if (!empty($collector) && strtolower($collector) !== 'all') {
            $collectorClean = trim($collector);
            $collectorModel = \App\Models\Collector::with('areas')->where('name', $collectorClean)->first();
            $assignedAreaNames = [];
            if ($collectorModel && $collectorModel->areas) {
                $assignedAreaNames = $collectorModel->areas->pluck('name')->filter()->toArray();
            }

            $query->where(function ($q) use ($collectorClean, $assignedAreaNames) {
                $q->where('collector_name', $collectorClean)
                  ->orWhere('collector_name', 'LIKE', "%{$collectorClean}%");

                if (!empty($assignedAreaNames)) {
                    $q->orWhereIn('area_name', $assignedAreaNames);
                    foreach ($assignedAreaNames as $area) {
                        $q->orWhere('add_combined', 'LIKE', "%{$area}%");
                    }
                }
            });
        }

        if ($exactMatch) {
            // Strict exact match by customer_id or full_name or raw json
            $query->where(function ($q) use ($term) {
                $q->where('customer_id', '=', $term)
                  ->orWhere('full_name', '=', $term)
                  ->orWhere('raw_data_json', 'LIKE', '%"' . $term . '"%');
            });
        } else {
            // Standard partial query by customer_id or full_name or address or collector
            $query->where(function ($q) use ($term) {
                $q->where('customer_id', 'LIKE', "%{$term}%")
                  ->orWhere('full_name', 'LIKE', "%{$term}%")
                  ->orWhere('add_combined', 'LIKE', "%{$term}%")
                  ->orWhere('collector_name', 'LIKE', "%{$term}%")
                  ->orWhere('raw_data_json', 'LIKE', "%{$term}%");
            });
        }

        $records = $query->take(150)->get();

        $results = [];
        foreach ($records as $rec) {
            $raw = $rec->raw_data_json;
            if (is_string($raw)) {
                $raw = json_decode($raw, true) ?? [];
            }

            $extractedId = trim((string)$rec->customer_id);
            if (empty($extractedId)) {
                foreach (['Customer ID', 'ID', 'emp_id', 'Code', 'SL', 'client_id', 'customer_code', 'customer_id'] as $idKey) {
                    if (isset($raw[$idKey]) && $raw[$idKey] !== null && $raw[$idKey] !== '') {
                        $extractedId = trim((string)$raw[$idKey]);
                        break;
                    }
                }
            }

            $cName = trim((string)$rec->collector_name);
            $extractedName = trim((string)$rec->full_name);

            if (empty($extractedName) || (!empty($cName) && strcasecmp($extractedName, $cName) === 0)) {
                $extractedName = '';
                foreach (['Full Name', 'Customer Name', 'full_name', 'client_name', 'Name', 'Customer_Name'] as $nameKey) {
                    if (isset($raw[$nameKey]) && !empty(trim((string)$raw[$nameKey]))) {
                        $val = trim((string)$raw[$nameKey]);
                        if (empty($cName) || strcasecmp($val, $cName) !== 0) {
                            $extractedName = $val;
                            break;
                        }
                    }
                }
            }

            $isExactId = (strcasecmp(trim((string)$extractedId), $term) === 0) ||
                         (strcasecmp(trim((string)$rec->customer_id), $term) === 0);

            $isExactName = (strcasecmp(trim((string)$extractedName), $term) === 0);

            // Check if any raw json value equals $term exactly
            $hasExactRawVal = false;
            foreach ($raw as $k => $v) {
                if (strcasecmp(trim((string)$v), $term) === 0) {
                    $hasExactRawVal = true;
                    break;
                }
            }

            $isExact = $isExactId || $isExactName || $hasExactRawVal;

            // If exactMatch mode is ON, filter out non-exact records
            if ($exactMatch && !$isExact) {
                continue;
            }

            $duesVal = (float) $rec->previous_dues;
            $rentVal = (float) $rec->monthly_rent;
            $advVal = (float) $rec->advance;
            $discVal = (float) $rec->discount;

            $results[] = [
                'id' => $rec->id,
                'processed_file_id' => $rec->processed_file_id,
                'file_name' => $rec->processedFile?->original_name ?? $rec->processedFile?->formatted_name ?? ('File #' . $rec->processed_file_id),
                'billing_month' => $rec->billing_month ?? $rec->processedFile?->billing_month ?? 'N/A',
                'row_index' => $rec->row_index,
                'customer_id' => $extractedId ?: 'N/A',
                'full_name' => $extractedName ?: 'N/A',
                'monthly_rent' => $rentVal,
                'previous_dues' => $duesVal,
                'advance' => $advVal,
                'discount' => $discVal,
                'house_no' => $rec->house_no ?? ($raw['house_no'] ?? $raw['House No'] ?? $raw['House'] ?? ''),
                'flat_no' => $rec->flat_no ?? ($raw['flat_no'] ?? $raw['Flat No'] ?? $raw['Flat'] ?? ''),
                'area_name' => $rec->area_name ?? ($raw['area_name'] ?? $raw['Area Name'] ?? $raw['Area'] ?? ''),
                'building_name' => $rec->building_name ?? ($raw['building_name'] ?? $raw['Building Name'] ?? $raw['Building'] ?? ''),
                'status' => $rec->status ?? 'Active',
                'collector_name' => $rec->collector_name ?? 'Unassigned',
                'address' => $rec->add_combined ?? ($raw['Address'] ?? $raw['customer_address'] ?? ''),
                'raw_data' => $raw,
                'is_exact_match' => $isExact,
            ];
        }

        // Sort exact matches to top
        usort($results, function ($a, $b) {
            if ($a['is_exact_match'] && !$b['is_exact_match']) return -1;
            if (!$a['is_exact_match'] && $b['is_exact_match']) return 1;
            return 0;
        });

        return $results;
    }

    /**
     * Update a single CustomerRecord directly by ID in SQL DB (Sub-5ms execution)
     */
    public function updateCustomerRecordById(int $recordId, array $changes): bool
    {
        $model = CustomerMonthlyBilling::find($recordId);

        if (!$model) {
            return false;
        }

        $fileId = $model->processed_file_id;
        $rowIndex = $model->row_index;

        $user = auth()->user();
        $userName = $user ? ($user->name ?? $user->email) : 'Admin User';
        $userId = $user ? $user->id : null;

        $raw = $model->raw_data_json;
        if (is_string($raw)) {
            $raw = json_decode($raw, true) ?? [];
        }

        foreach ($changes as $key => $val) {
            $kLower = strtolower(trim($key));
            $oldVal = null;
            $newVal = (string)$val;
            $fieldName = $key;

            if ($kLower === 'status' || $kLower === 'customer_status') {
                $fieldName = 'Status';
                $oldVal = (string)($model->status ?? '');
                $model->status = trim((string)$val);
            } elseif ($kLower === 'full_name' || $kLower === 'customer name' || $kLower === 'name') {
                $fieldName = 'Customer Name';
                $oldVal = (string)($model->full_name ?? '');
                $model->full_name = trim((string)$val);
            } elseif ($kLower === 'customer_id' || $kLower === 'customer id' || $kLower === 'id' || $kLower === 'code' || $kLower === 'emp_id') {
                $fieldName = 'Customer ID';
                $oldVal = (string)($model->customer_id ?? '');
                $model->customer_id = trim((string)$val);
            } elseif (Str::contains($kLower, ['collector name', 'collector_name', 'collector', 'collectorname'])) {
                $fieldName = 'Collector Name';
                $oldVal = (string)($model->collector_name ?? '');
                $model->collector_name = trim((string)$val);
            } elseif (Str::contains($kLower, ['advance', 'adv'])) {
                $fieldName = 'Advance';
                $oldVal = (string)($model->advance ?? '0');
                $model->advance = is_numeric($val) ? (float)$val : 0.0;
            } elseif (Str::contains($kLower, ['discount', 'disc', 'less'])) {
                $fieldName = 'Discount';
                $oldVal = (string)($model->discount ?? '0');
                $model->discount = is_numeric($val) ? (float)$val : 0.0;
            } elseif (Str::contains($kLower, ['previous dues', 'prev dues', 'previous_dues', 'dues', 'due', 'bokea', 'baki'])) {
                $fieldName = 'Previous Dues';
                $oldVal = (string)($model->previous_dues ?? '0');
                $model->previous_dues = is_numeric($val) ? (float)$val : 0.0;
            } elseif (Str::contains($kLower, ['rent', 'salary', 'bill', 'fee', 'monthly rent', 'monthly_rent'])) {
                $fieldName = 'Monthly Rent';
                $oldVal = (string)($model->monthly_rent ?? '0');
                $model->monthly_rent = is_numeric($val) ? (float)$val : 0.0;
            } elseif (Str::contains($kLower, ['house', 'house_no', 'house no'])) {
                $fieldName = 'House No';
                $oldVal = (string)($model->house_no ?? '');
                $model->house_no = trim((string)$val);
            } elseif (Str::contains($kLower, ['flat', 'flat_no', 'flat no'])) {
                $fieldName = 'Flat No';
                $oldVal = (string)($model->flat_no ?? '');
                $model->flat_no = trim((string)$val);
            } elseif (Str::contains($kLower, ['area', 'area_name', 'area name'])) {
                $fieldName = 'Area Name';
                $oldVal = (string)($model->area_name ?? '');
                $model->area_name = trim((string)$val);
            } elseif (Str::contains($kLower, ['building', 'building_name', 'building name'])) {
                $fieldName = 'Building Name';
                $oldVal = (string)($model->building_name ?? '');
                $model->building_name = trim((string)$val);
            } else {
                $oldVal = isset($raw[$key]) ? (string)$raw[$key] : '';
            }

            $raw[$key] = $val;

            if (trim((string)$oldVal) !== trim((string)$newVal)) {
                CustomerRecordHistory::create([
                    'customer_monthly_billing_id' => $model->id,
                    'customer_record_id' => $model->id,
                    'processed_file_id' => $model->processed_file_id,
                    'user_id' => $userId,
                    'customer_id' => $model->customer_id ?: ('ID #' . $model->id),
                    'customer_name' => $model->full_name ?: 'N/A',
                    'field_name' => $fieldName,
                    'old_value' => $oldVal,
                    'new_value' => $newVal,
                    'edited_by' => $userName,
                ]);
            }
        }

        // Re-construct add_combined if address components are updated
        $addrParts = array_filter([
            !empty($model->house_no) ? "House No # " . $model->house_no : '',
            !empty($model->flat_no) ? "Flat No # " . $model->flat_no : '',
            $model->building_name,
            $model->area_name
        ]);
        if (!empty($addrParts)) {
            $model->add_combined = implode(', ', $addrParts);
        }

        // Recalculate derived target report values
        $model->actual_bill = max(0.0, (float)$model->monthly_rent - (float)$model->advance - (float)$model->discount);
        $model->fifty_percent = (float)$model->previous_dues * 0.5;
        $model->target = (float)$model->actual_bill + (float)$model->fifty_percent;

        $model->raw_data_json = json_encode($raw);
        $model->save();

        // Sync Master Customer Profile
        if (!empty($model->customer_id)) {
            $master = Customer::where('customer_id', $model->customer_id)->first();
            if ($master) {
                $master->update(array_filter([
                    'full_name'      => $model->full_name,
                    'customer_type'  => $model->customer_type,
                    'category'       => $model->category,
                    'status'         => $model->status,
                    'collector_name' => $model->collector_name,
                    'area_name'      => $model->area_name,
                    'building_name'  => $model->building_name,
                    'house_no'       => $model->house_no,
                    'flat_no'        => $model->flat_no,
                    'add_combined'   => $model->add_combined,
                ]));
            }
        }

        return true;
    }
}
