from pathlib import Path
p=Path('includes/requisition_documents.php');s=p.read_text().replace("'reference' => $draft['reference']", "'school_name'=>$institution['school_name']??'BULACAN POLYTECHNIC COLLEGE','clinic_name'=>$institution['clinic_name']??'BPC SCHOOL CLINIC',\n            'reference' => $draft['reference']",1)
a=s.index('        return [(string)$index');b=s.index('\n    public static function pdf',a)
s=s[:a]+'''        return [(string)$index,
            $m['medicine_name']."\\nGeneric: ".($m['generic_name']??'Not specified')."\\nBrand: ".($m['brand']??'Not specified')."\\nID ".$m['id'].(!empty($m['batch_number'])?' / Batch '.$m['batch_number']:'')."\\n".$m['stock_status'],
            (string)$m['usable_stock'],(string)$m['reorder_level'],isset($m['suggested_quantity'])?(string)$m['suggested_quantity']:'Review required',
            (string)$m['requested_quantity'],$m['unit'],!empty($m['supplier'])?$m['supplier']:'Not specified'];
    }
    private static function headings(): array {
        return ['Item','Medicine / Generic / Brand','Current Stock','Minimum Stock','Suggested Order','Quantity Requested','Unit','Supplier'];
    }
'''+s[b:]
s=s.replace('BULACAN POLYTECHNIC COLLEGE</div><div class="clinic">BPC SCHOOL CLINIC', "' . $escape($model['school_name']??'BULACAN POLYTECHNIC COLLEGE') . '</div><div class=\"clinic\">' . $escape($model['clinic_name']??'BPC SCHOOL CLINIC') . '")
s=s.replace('$widths = [7,25,14,10,10,12,22];','$widths = [5,29,9,9,11,11,9,17];')
s=s.replace("$escape($value) . '</td>'", "nl2br($escape($value)) . '</td>'")
s=s.replace("$section->addText('BULACAN POLYTECHNIC COLLEGE',", "$section->addText($model['school_name']??'BULACAN POLYTECHNIC COLLEGE',").replace("$section->addText('BPC SCHOOL CLINIC',", "$section->addText($model['clinic_name']??'BPC SCHOOL CLINIC',")
s=s.replace('$widths = [706,2523,1413,1009,1009,1211,2219];','$widths = [505,2926,908,908,1110,1110,908,1715];')
p.write_text(s)
p=Path('api/medicine_requisition_download.php');s=p.read_text().replace("IN ('school_address','clinic_contact')", "IN ('school_name','clinic_name','school_address','clinic_contact')")
s=s.replace("csrf_verify_or_die();", "if(!csrf_verify($_POST['csrf_token']??'')){http_response_code(403);json_response(false,[],'Your security token expired. Refresh and try again.');}",1)
s=s.replace("} catch (InvalidArgumentException $e) {\n    http_response_code(409); json_response(false, [], $e->getMessage());\n}", "} catch (InvalidArgumentException $e) {\n    http_response_code(409); json_response(false, [], $e->getMessage());\n} catch(Throwable $e){error_log('Requisition export: '.$e->getMessage());http_response_code(500);json_response(false,[],'The restock document could not be generated. Retry from the confirmed preview.');}")
p.write_text(s)
p=Path('pages/medicine_requisition.php');s=p.read_text().replace("e($m['medicine_name']) ?><br><small>","e($m['medicine_name']) ?><br><small>Generic: <?= e($m['generic_name']??'Not specified') ?> / Brand: <?= e($m['brand']??'Not specified') ?><br>")
s=s.replace('<small>Draft until confirmed</small>', '<small>Suggested: <?= isset($m[\'suggested_quantity\'])?(int)$m[\'suggested_quantity\']:\'Review required\' ?>. Review before confirming.</small>')
s=s.replace("e($m['stock_status'] . '. ' . $m['remarks']) ?>", "e($m['stock_status'] . '. ' . $m['remarks']) ?><br><small>Supplier: <?= e($m['supplier']?:'Not specified') ?></small>")
p.write_text(s)
