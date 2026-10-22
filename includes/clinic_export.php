<?php
final class ClinicExport
{
    public static function send(array $report,string $format,string $baseName): never
    {
        $rows=$report['rows']??[]; $headers=$rows?array_keys($rows[0]):[];
        $baseName=preg_replace('/[^A-Za-z0-9_-]+/','_',trim($baseName,'_')) ?: 'BPC_Clinic_Report';
        if($format==='csv') self::csv($headers,$rows,$baseName.'.csv');
        if($format==='xlsx') self::xlsx($headers,$rows,$baseName.'.xlsx',$report['title']??'Report');
        if($format==='pdf') self::pdf($headers,$rows,$baseName.'.pdf',$report);
        throw new InvalidArgumentException('Unsupported export format.');
    }
    private static function csv(array $headers,array $rows,string $name): never
    {
        header('Content-Type:text/csv; charset=utf-8');header('Content-Disposition:attachment; filename="'.$name.'"');
        $out=fopen('php://output','wb');fwrite($out,"\xEF\xBB\xBF");fputcsv($out,$headers);
        foreach($rows as $row)fputcsv($out,array_values($row));fclose($out);exit;
    }
    private static function x(string $value): string {return htmlspecialchars($value,ENT_XML1|ENT_QUOTES,'UTF-8');}
    private static function xlsx(array $headers,array $rows,string $name,string $title): never
    {
        if(!class_exists('ZipArchive'))throw new RuntimeException('XLSX export requires the PHP ZIP extension.');
        $tmp=tempnam(sys_get_temp_dir(),'bpcxlsx_');$zip=new ZipArchive();$zip->open($tmp,ZipArchive::OVERWRITE);
        $types='<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/></Types>';
        $zip->addFromString('[Content_Types].xml',$types);
        $zip->addFromString('_rels/.rels','<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');
        $zip->addFromString('xl/workbook.xml','<?xml version="1.0"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="'.self::x(substr($title,0,31)).'" sheetId="1" r:id="rId1"/></sheets></workbook>');
        $zip->addFromString('xl/_rels/workbook.xml.rels','<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/></Relationships>');
        $all=array_merge([$headers],array_map('array_values',$rows));$xml='<?xml version="1.0" encoding="UTF-8"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>';
        foreach($all as $ri=>$row){$xml.='<row r="'.($ri+1).'">';foreach($row as $ci=>$value){$col='';$n=$ci+1;while($n){$n--; $col=chr(65+$n%26).$col;$n=intdiv($n,26);} $xml.='<c r="'.$col.($ri+1).'" t="inlineStr"><is><t>'.self::x((string)$value).'</t></is></c>';}$xml.='</row>';}$xml.='</sheetData></worksheet>';
        $zip->addFromString('xl/worksheets/sheet1.xml',$xml);$zip->close();
        header('Content-Type:application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');header('Content-Disposition:attachment; filename="'.$name.'"');header('Content-Length:'.filesize($tmp));readfile($tmp);unlink($tmp);exit;
    }
    private static function pdf(array $headers,array $rows,string $name,array $report): never
    {
        require_once BASE_PATH.'/vendor/autoload.php';
        $html='<style>@page{margin:24px}body{font-family:DejaVu Sans;font-size:9px;color:#111}h1,h2,p{text-align:center;margin:3px}table{width:100%;border-collapse:collapse;margin-top:14px;table-layout:fixed}th,td{border:1px solid #555;padding:4px;word-wrap:break-word}th{background:#eee}</style><h2>BULACAN POLYTECHNIC COLLEGE</h2><h1>'.e($report['title']??'Clinic Report').'</h1><p>Period: '.e($report['from']??'').' to '.e($report['to']??'').' | Generated: '.date('Y-m-d H:i').'</p><table><thead><tr>';
        foreach($headers as $h)$html.='<th>'.e(ucwords(str_replace('_',' ',$h))).'</th>';$html.='</tr></thead><tbody>';
        if(!$rows)$html.='<tr><td colspan="'.max(1,count($headers)).'">No records found for this period.</td></tr>';
        foreach($rows as $row){$html.='<tr>';foreach($row as $v)$html.='<td>'.e((string)$v).'</td>';$html.='</tr>';}$html.='</tbody></table>';
        $dompdf=new Dompdf\Dompdf();$dompdf->loadHtml($html);$dompdf->setPaper('A4',count($headers)>6?'landscape':'portrait');$dompdf->render();$dompdf->stream($name,['Attachment'=>true]);exit;
    }
}
