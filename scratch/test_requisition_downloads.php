<?php
require __DIR__ . '/../includes/medicine_inventory_service.php';
require __DIR__ . '/../includes/requisition_documents.php';
function check($ok,$message){if(!$ok)throw new RuntimeException($message);}
$base=['id'=>12,'medicine_name'=>'Paracetamol & Zinc <Tablet>','unit'=>'tablets','quantity'=>5,'reorder_level'=>20,'expiration_date'=>null,'batch_number'=>'BPC-12'];
$low=MedicineInventoryService::classify($base,'2026-10-17');
$healthy=MedicineInventoryService::classify(array_replace($base,['id'=>13,'quantity'=>21]),'2026-10-17');
$missing=MedicineInventoryService::classify(array_replace($base,['id'=>14,'quantity'=>0,'reorder_level'=>null]),'2026-10-17');
$inventory=[12=>$low,13=>$healthy,14=>$missing];
$draft=['owner'=>7,'confirmed'=>true,'reference'=>'BPC-MR-QA-001','prepared'=>'2026-10-17 10:00:00','quantities'=>[12=>31],'confirmed_hash'=>MedicineInventoryService::fingerprint([12=>$low]),'revision'=>'fixture-revision'];
$rows=MedicineInventoryService::verifiedDraft($draft,$inventory,7,'fixture-revision');
check(count($rows)===1 && $rows[0]['requested_quantity']===31,'Eligible row and confirmed quantity');
foreach ([[$draft,$inventory,8,'fixture-revision'],[$draft,$inventory,7,'stale'],[array_replace($draft,['confirmed'=>false]),$inventory,7,'fixture-revision'],[$draft,[],7,'fixture-revision'],[array_replace($draft,['quantities'=>[13=>2]]),$inventory,7,'fixture-revision'],[array_replace($draft,['quantities'=>[14=>2]]),$inventory,7,'fixture-revision']] as $args) {
 try{MedicineInventoryService::verifiedDraft(...$args);throw new RuntimeException('Unsafe export accepted');}catch(InvalidArgumentException $e){}
}
$changed=$inventory;$changed[12]['quantity']=4;
try{MedicineInventoryService::verifiedDraft($draft,$changed,7,'fixture-revision');throw new RuntimeException('Stale inventory accepted');}catch(InvalidArgumentException $e){}
$user=['first_name'=>'Clinic','last_name'=>'Nurse'];
$model=RequisitionDocuments::model($draft,$rows,$user,['school_address'=>'Malolos, Bulacan','clinic_contact'=>'Clinic Office']);
$dir=__DIR__.'/requisition-document-qa';if(!is_dir($dir))mkdir($dir);
foreach(['single'=>$rows,'multiple'=>array_map(function($i)use($rows){$m=$rows[0];$m['id']=$i;$m['medicine_name']='Medicine '.$i.' - Extended medicine name for pagination verification';$m['batch_number']='BATCH-'.$i;return $m;},range(1,85))] as $name=>$testRows){
 $testModel=array_replace($model,['rows'=>$testRows]);
 $pdf=RequisitionDocuments::pdf($testModel);check(str_starts_with($pdf,'%PDF-'),'Actual PDF signature');file_put_contents($dir.'/'.$name.'.pdf',$pdf);
 $docx=RequisitionDocuments::word($testModel);check(str_starts_with($docx,'PK'),'Actual DOCX ZIP signature');file_put_contents($dir.'/'.$name.'.docx',$docx);
 $zip=new ZipArchive();check($zip->open($dir.'/'.$name.'.docx')===true,'Word package opens');
 $xml=$zip->getFromName('word/document.xml');$document=new DOMDocument();check($document->loadXML($xml),'Valid Word XML');
 $xpath=new DOMXPath($document);$xpath->registerNamespace('w','http://schemas.openxmlformats.org/wordprocessingml/2006/main');
 check($xpath->query('//w:tbl/w:tr')->length===count($testRows)+1,'Editable table rows');
 check($xpath->query('//w:tblHeader')->length===1,'Word repeated header');
 check(str_contains($xml,'Requisition Status: Draft'),'Draft status');
 check(str_contains($xml,'31'),'Confirmed requested quantity');
 $size=$xpath->query('//w:pgSz')->item(0);check($size->getAttribute('w:w')==='11906' && $size->getAttribute('w:h')==='16838','Word A4 portrait');
 check($zip->getFromName('[Content_Types].xml')!==false,'Real OOXML content types');$zip->close();
}
echo "PASS: real PDF/DOCX generation; editable Word table, escaping, A4, repeated headings, quantities; unauthorized owner/unconfirmed/stale/healthy/missing-threshold/empty rejection.\n";
