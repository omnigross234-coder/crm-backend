<?php

namespace App\Exports;

use App\Models\Lead;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Alignment;

class LeadsExport
{
    protected array $filters;

    public function __construct(array $filters)
    {
        $this->filters = $filters;
    }

    public function download(): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        $leads = $this->getLeads();

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();

        // ── Header row styling ──
        $headers = ['ID', 'Name', 'Phone', 'Email', 'Company', 'Source', 'Status', 'Priority', 'Assigned To', 'Remarks', 'Created At'];
        foreach ($headers as $col => $heading) {
            $cell = chr(65 + $col) . '1';
            $sheet->setCellValue($cell, $heading);
            $sheet->getStyle($cell)->applyFromArray([
                'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '1E3A5F']],
                'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
            ]);
        }

        // ── Data rows ──
        foreach ($leads as $i => $lead) {
            $row = $i + 2;
            $sheet->setCellValue("A{$row}", $lead->id);
            $sheet->setCellValue("B{$row}", $lead->name);
            $sheet->setCellValue("C{$row}", $lead->phone ?? '');
            $sheet->setCellValue("D{$row}", $lead->email ?? '');
            $sheet->setCellValue("E{$row}", $lead->company ?? '');
            $sheet->setCellValue("F{$row}", $lead->source ?? '');
            $sheet->setCellValue("G{$row}", $lead->status);
            $sheet->setCellValue("H{$row}", $lead->priority);
            $sheet->setCellValue("I{$row}", $lead->assignedTo->name ?? 'Unassigned');
            $sheet->setCellValue("J{$row}", $lead->remarks ?? '');
            $sheet->setCellValue("K{$row}", $lead->created_at->format('d M Y H:i'));
        }

        // ── Auto size columns ──
        foreach (range('A', 'K') as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }

        $writer = new Xlsx($spreadsheet);

        return response()->stream(function () use ($writer) {
            $writer->save('php://output');
        }, 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="leads.xlsx"',
            'Cache-Control' => 'max-age=0',
        ]);
    }

    private function getLeads()
    {
        $query = Lead::with(['assignedTo:id,name']);

        if (!empty($this->filters['status'])) {
            $query->where('status', $this->filters['status']);
        }
        if (!empty($this->filters['priority'])) {
            $query->where('priority', $this->filters['priority']);
        }
        if (!empty($this->filters['assigned_to'])) {
            $query->where('assigned_to', $this->filters['assigned_to']);
        }
        if (!empty($this->filters['search'])) {
            $search = $this->filters['search'];
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%");
            });
        }

        return $query->latest()->get();
    }
}