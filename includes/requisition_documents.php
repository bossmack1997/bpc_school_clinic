<?php
require_once __DIR__ . '/../vendor/autoload.php';

/** Both formats consume the same server-validated document model. */
final class RequisitionDocuments
{
    public static function model(array $draft, array $rows, array $user, array $institution): array
    {
        return [
            'school_name'=>$institution['school_name']??'BULACAN POLYTECHNIC COLLEGE','clinic_name'=>$institution['clinic_name']??'BPC SCHOOL CLINIC',
            'reference' => $draft['reference'], 'prepared' => $draft['prepared'],
            'prepared_by' => trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? '')),
            'department' => 'School Clinic', 'status' => 'Draft',
            'address' => $institution['school_address'] ?? '', 'contact' => $institution['clinic_contact'] ?? '',
            'rows' => $rows,
        ];
    }

    private static function cells(array $m, int $index): array
    {
        return [(string)$index,
            $m['medicine_name']."\nGeneric: ".($m['generic_name']??'Not specified')."\nBrand: ".($m['brand']??'Not specified')."\nID ".$m['id'].(!empty($m['batch_number'])?' / Batch '.$m['batch_number']:'')."\n".$m['stock_status'],
            (string)$m['usable_stock'],(string)$m['reorder_level'],isset($m['suggested_quantity'])?(string)$m['suggested_quantity']:'Review required',
            (string)$m['requested_quantity'],$m['unit'],!empty($m['supplier'])?$m['supplier']:'Not specified'];
    }
    private static function headings(): array {
        return ['Item','Medicine / Generic / Brand','Current Stock','Minimum Stock','Suggested Order','Quantity Requested','Unit','Supplier'];
    }

    public static function pdf(array $model): string
    {
        $escape = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $html = '<!DOCTYPE html><html><head><meta charset="UTF-8"><style>
        @page{size:A4 portrait;margin:16mm}body{font-family:"DejaVu Sans",sans-serif;font-size:9pt;color:#111;line-height:1.35}
        .header{text-align:center;border-bottom:1.5pt solid #111;padding-bottom:10pt;margin-bottom:12pt}.institution{font-size:13pt;font-weight:bold}.clinic{font-size:11pt;margin:4pt 0}h1{font-size:14pt;margin:10pt 0 0}
        .meta{width:100%;margin-bottom:12pt}.meta td{border:0;padding:3pt 0;width:50%}table.items{width:100%;border-collapse:collapse;table-layout:fixed;font-size:8pt}
        .items th,.items td{border:.6pt solid #555;padding:5pt;vertical-align:top;overflow-wrap:break-word;word-wrap:break-word}.items th{background:#eee;font-weight:bold;font-size:7pt;word-wrap:normal;overflow-wrap:normal}thead{display:table-header-group}tr{page-break-inside:avoid}
        .signatures{width:100%;margin-top:22pt;border-collapse:collapse;page-break-inside:avoid}.signatures td{width:50%;padding:8pt 10pt 8pt 0;vertical-align:top}.note{font-size:8pt;margin-top:12pt}
        </style></head><body><div class="header"><div class="institution">' . $escape($model['school_name']??'BULACAN POLYTECHNIC COLLEGE') . '</div><div class="clinic">' . $escape($model['clinic_name']??'BPC SCHOOL CLINIC') . '</div><div>' . $escape($model['address'] . ' ' . $model['contact']) . '</div><h1>MEDICINE REQUISITION FORM</h1></div>';
        $html .= '<table class="meta"><tr><td>Reference: ' . $escape($model['reference']) . '</td><td>Date Prepared: ' . $escape($model['prepared']) . '</td></tr><tr><td>Requesting Department: School Clinic</td><td>Prepared By: ' . $escape($model['prepared_by']) . '</td></tr><tr><td colspan="2"><strong>Requisition Status: Draft</strong> - quantities confirmed; pending review and approval</td></tr></table><table class="items"><thead><tr>';
        $widths = [5,29,9,9,11,11,9,17];
        foreach (self::headings() as $i => $heading) $html .= '<th style="width:' . $widths[$i] . '%">' . $escape($heading) . '</th>';
        $html .= '</tr></thead><tbody>';
        foreach ($model['rows'] as $i => $m) {
            $html .= '<tr>';
            foreach (self::cells($m, $i + 1) as $value) $html .= '<td>' . nl2br($escape($value)) . '</td>';
            $html .= '</tr>';
        }
        $html .= '</tbody></table><p class="note">Purpose: Medicine Inventory Replenishment. This draft is not an approved purchase order. Signatures and approvals must be completed by authorized personnel.</p>';
        $html .= '<table class="signatures"><tr><td>Prepared By: ' . $escape($model['prepared_by']) . '<br><br>Signature: ____________________<br>Date: ____________________</td><td>Reviewed By: ____________________<br><br>Signature: ____________________<br>Date: ____________________</td></tr><tr><td>Approved By: ____________________<br><br>Signature: ____________________<br>Date: ____________________</td><td></td></tr></table></body></html>';
        $options = new Dompdf\Options();
        $options->set('isRemoteEnabled', false);
        $options->set('isPhpEnabled', false);
        $options->set('isJavascriptEnabled', false);
        $pdf = new Dompdf\Dompdf($options);
        $pdf->setPaper('A4', 'portrait'); $pdf->loadHtml($html, 'UTF-8'); $pdf->render();
        $pdf->getCanvas()->page_text(460, 810, 'Page {PAGE_NUM} of {PAGE_COUNT}', null, 8);
        return $pdf->output();
    }

    public static function word(array $model): string
    {
        PhpOffice\PhpWord\Settings::setOutputEscapingEnabled(true);
        $doc = new PhpOffice\PhpWord\PhpWord();
        $doc->setDefaultFontName('Arial'); $doc->setDefaultFontSize(10);
        $doc->setDefaultParagraphStyle(['spaceAfter'=>90,'lineHeight'=>1.15]);
        $section = $doc->addSection(['pageSizeW'=>11906,'pageSizeH'=>16838,'orientation'=>'portrait','marginTop'=>907,'marginBottom'=>907,'marginLeft'=>907,'marginRight'=>907]);
        $center = ['alignment'=>'center','spaceAfter'=>80];
        $section->addText($model['school_name']??'BULACAN POLYTECHNIC COLLEGE',['bold'=>true,'size'=>14],$center);
        $section->addText($model['clinic_name']??'BPC SCHOOL CLINIC',['bold'=>true,'size'=>12],$center);
        if ($model['address'] || $model['contact']) $section->addText($model['address'].' '.$model['contact'],['size'=>9],$center);
        $section->addText('MEDICINE REQUISITION FORM',['bold'=>true,'size'=>14],['alignment'=>'center','spaceBefore'=>100,'spaceAfter'=>220]);
        foreach (['Requisition Reference Number'=>$model['reference'],'Date Prepared'=>$model['prepared'],'Requesting Department'=>$model['department'],'Prepared By'=>$model['prepared_by'],'Requisition Status'=>'Draft - quantities confirmed; pending approval'] as $label=>$value) {
            $section->addText($label . ': ' . $value,['size'=>10],['spaceAfter'=>65]);
        }
        $table = $section->addTable(['borderSize'=>6,'borderColor'=>'555555','cellMargin'=>80,'layout'=>'fixed','width'=>10090,'unit'=>'dxa']);
        $widths = [505,2926,908,908,1110,1110,908,1715];
        $table->addRow(null,['tblHeader'=>true,'cantSplit'=>true]);
        foreach (self::headings() as $i=>$text) $table->addCell($widths[$i],['bgColor'=>'EEEEEE'])->addText($text,['bold'=>true,'size'=>8],['spaceAfter'=>0]);
        foreach ($model['rows'] as $i=>$m) {
            $table->addRow(null,['cantSplit'=>true]);
            foreach (self::cells($m,$i+1) as $j=>$value) $table->addCell($widths[$j])->addText($value,['size'=>9],['spaceAfter'=>0]);
        }
        $section->addText('Purpose: Medicine Inventory Replenishment. Draft only; not an approved purchase order.',['size'=>9],['spaceBefore'=>180]);
        foreach (['Prepared By: '.$model['prepared_by'],'Reviewed By: ____________________','Approved By: ____________________'] as $text) {
            $section->addText($text,['size'=>10],['spaceBefore'=>170,'keepNext'=>true]);
            $section->addText('Signature: ____________________    Date: ____________________',['size'=>10],['keepLines'=>true]);
        }
        $section->addFooter()->addPreserveText('Page {PAGE} of {NUMPAGES}', ['size'=>8],['alignment'=>'right']);
        $path = tempnam(sys_get_temp_dir(),'bpc_docx_');
        if ($path === false) throw new RuntimeException('Document temporary storage is unavailable.');
        try {
            PhpOffice\PhpWord\IOFactory::createWriter($doc,'Word2007')->save($path);
            $bytes = file_get_contents($path);
            if ($bytes === false) throw new RuntimeException('Unable to read generated document.');
            return $bytes;
        } finally { unlink($path); }
    }
}
