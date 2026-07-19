<?php
declare(strict_types=1);

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Table;
use PhpOffice\PhpSpreadsheet\Worksheet\Table\TableStyle;
use Mpdf\Mpdf;

class ReportGenerator
{
    private string $title;
    private string $reportType;
    private array $headers;
    private array $data;
    private array $documentHeader;

    public function __construct(string $title, string $reportType, array $headers, array $data, array $documentHeader = [])
    {
        $this->title = $title;
        $this->reportType = $reportType;
        $this->headers = $headers;
        $this->data = $data;
        $this->documentHeader = $documentHeader;
    }

    public function exportCsv(string $filename = ''): void
    {
        $filename = $filename ?: $this->generateFilename('csv');
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');

        $out = fopen('php://output', 'w');
        fputcsv($out, $this->headers);
        foreach ($this->data as $row) {
            fputcsv($out, $row);
        }
        fclose($out);
        exit;
    }

    public function exportExcel(string $filename = ''): void
    {
        $filename = $filename ?: $this->generateFilename('xlsx');
        try {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Report');

        $lastColumn = $this->getColumnLetter(count($this->headers));
        $headerRow = 4;
        if ($this->documentHeader !== []) {
            foreach ($this->documentHeader as $index => $line) {
                $lineRow = $index + 1;
                $sheet->setCellValue('A' . $lineRow, $line);
                $sheet->mergeCells('A' . $lineRow . ':' . $lastColumn . $lineRow);
                $sheet->getStyle('A' . $lineRow)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
                $sheet->getStyle('A' . $lineRow)->getFont()->setBold($index < 3)->setSize($index === 0 ? 16 : ($index === 2 ? 13 : 11));
            }
            $headerRow = count($this->documentHeader) + 2;
        } else {
            $sheet->setCellValue('A1', $this->title);
            $sheet->getStyle('A1')->getFont()->setSize(16)->setBold(true);
            $sheet->mergeCells('A1:' . $lastColumn . '1');
            $sheet->getStyle('A1')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
            $sheet->setCellValue('A2', 'Generated: ' . date('F d, Y h:i A'));
            $sheet->getStyle('A2')->getFont()->setItalic(true)->setSize(10);
            $sheet->mergeCells('A2:' . $lastColumn . '2');
        }

        // Add headers with formatting
        $col = 'A';
        foreach ($this->headers as $header) {
            $sheet->setCellValue($col . $headerRow, $header);
            $col++;
        }

        // Format header row
        $headerRange = 'A' . $headerRow . ':' . $lastColumn . $headerRow;
        $sheet->getStyle($headerRange)->getFont()->setBold(true)->getColor()->setARGB('FFFFFFFF');
        $sheet->getStyle($headerRange)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FF246B49');
        $sheet->getStyle($headerRange)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER)->setVertical(Alignment::VERTICAL_CENTER);
        $sheet->getStyle($headerRange)->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);

        // Add data
        $row = $headerRow + 1;
        foreach ($this->data as $rowData) {
            $col = 'A';
            foreach ($rowData as $value) {
                $sheet->setCellValue($col . $row, $value);
                $col++;
            }
            $sheet->getStyle('A' . $row . ':' . $this->getColumnLetter(count($this->headers)) . $row)->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
            $sheet->getStyle('A' . $row . ':' . $lastColumn . $row)->getAlignment()->setVertical(Alignment::VERTICAL_CENTER)->setWrapText(true);
            $row++;
        }

        // Flexible but bounded widths keep both compact and wide reports readable.
        for ($columnIndex = 1; $columnIndex <= count($this->headers); $columnIndex++) {
            $column = $this->getColumnLetter($columnIndex);
            $maxLength = strlen((string) ($this->headers[$columnIndex - 1] ?? ''));
            foreach ($this->data as $dataRow) {
                $maxLength = max($maxLength, strlen((string) ($dataRow[$columnIndex - 1] ?? '')));
            }
            $sheet->getColumnDimension($column)->setWidth(min(34, max(11, $maxLength + 2)));
        }
        $sheet->getStyle($headerRange)->getAlignment()->setWrapText(true);
        $sheet->getPageSetup()->setOrientation(\PhpOffice\PhpSpreadsheet\Worksheet\PageSetup::ORIENTATION_LANDSCAPE)->setFitToWidth(1)->setFitToHeight(0);
        $sheet->getPageMargins()->setTop(0.35)->setRight(0.3)->setBottom(0.45)->setLeft(0.3);
        $sheet->getPageSetup()->setRowsToRepeatAtTopByStartAndEnd($headerRow, $headerRow);
        $sheet->freezePane('A' . ($headerRow + 1));
        $sheet->setAutoFilter($headerRange);

        // Generate and validate a temporary XLSX before sending any response bytes.
        $tempFile = tempnam(sys_get_temp_dir(), 'appraisa-xlsx-');
        if ($tempFile === false) throw new RuntimeException('Unable to allocate a temporary Excel file.');
        $writer = \PhpOffice\PhpSpreadsheet\IOFactory::createWriter($spreadsheet, 'Xlsx');
        $writer->save($tempFile);
        $spreadsheet->disconnectWorksheets();
        if (!is_file($tempFile) || filesize($tempFile) < 1000) throw new RuntimeException('The generated Excel workbook is invalid.');
        while (ob_get_level() > 0) ob_end_clean();
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . filesize($tempFile));
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: public');
        readfile($tempFile);
        unlink($tempFile);
        exit;
        } catch (Throwable $error) {
            if (isset($tempFile) && is_string($tempFile) && is_file($tempFile)) unlink($tempFile);
            while (ob_get_level() > 0) ob_end_clean();
            http_response_code(500);
            header('Content-Type: text/plain; charset=utf-8');
            echo 'Unable to generate Excel report: ' . $error->getMessage();
            exit;
        }
    }

    public function exportPdf(string $filename = ''): void
    {
        $filename = $filename ?: $this->generateFilename('pdf');

        $html = $this->generatePdfHtml();

        try {
            $mpdf = new Mpdf([
                'mode' => 'utf-8',
                'format' => 'A4',
                'margin_left' => 15,
                'margin_right' => 15,
                'margin_top' => 20,
                'margin_bottom' => 20,
            ]);

            $mpdf->setHeader([
                'odd' => [
                    'L' => '',
                    'C' => $this->title,
                    'R' => date('F d, Y'),
                ],
            ]);

            $mpdf->setFooter([
                'odd' => [
                    'L' => 'DIPASCAF System',
                    'C' => 'Page {PAGENO} of {nb}',
                    'R' => '',
                ],
            ]);

            $mpdf->WriteHTML($html);
            $mpdf->Output($filename, 'D');
            exit;
        } catch (\Exception $e) {
            http_response_code(500);
            echo 'Error generating PDF: ' . htmlspecialchars($e->getMessage());
            exit;
        }
    }

    private function generatePdfHtml(): string
    {
        $html = '<html><head><meta charset="UTF-8">';
        $html .= '<style>
            body { font-family: Arial, sans-serif; font-size: 10pt; }
            h1 { text-align: center; color: #366092; margin-bottom: 5px; }
            .meta { text-align: center; font-size: 9pt; color: #666; margin-bottom: 20px; }
            table { width: 100%; border-collapse: collapse; margin-top: 20px; }
            th { background-color: #366092; color: white; padding: 8px; text-align: left; border: 1px solid #ccc; font-weight: bold; }
            td { padding: 6px; border: 1px solid #ddd; }
            tr:nth-child(even) { background-color: #f9f9f9; }
        </style></head><body>';
        
        $html .= '<h1>' . htmlspecialchars($this->title) . '</h1>';
        $html .= '<div class="meta">Generated: ' . date('F d, Y h:i A') . '</div>';
        
        $html .= '<table><thead><tr>';
        foreach ($this->headers as $header) {
            $html .= '<th>' . htmlspecialchars($header) . '</th>';
        }
        $html .= '</tr></thead><tbody>';
        
        foreach ($this->data as $row) {
            $html .= '<tr>';
            foreach ($row as $cell) {
                $html .= '<td>' . htmlspecialchars((string) $cell) . '</td>';
            }
            $html .= '</tr>';
        }
        
        $html .= '</tbody></table></body></html>';
        return $html;
    }

    private function generateFilename(string $extension): string
    {
        $timestamp = date('Ymd-His');
        return strtolower(str_replace(' ', '-', $this->reportType)) . '-' . $timestamp . '.' . $extension;
    }

    private function getColumnLetter(int $num): string
    {
        $numeric = ($num - 1) % 26;
        $letter = chr(65 + $numeric);
        $num = (int)(($num - 1) / 26);
        if ($num > 0) {
            return $this->getColumnLetter($num) . $letter;
        }
        return $letter;
    }
}
