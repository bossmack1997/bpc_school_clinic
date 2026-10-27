<?php
function clinic_availability(PDO $db, ?DateTimeImmutable $now=null): array
{
    $defaults=['clinic_open_time'=>'08:00','clinic_close_time'=>'17:00','clinic_operating_days'=>'1,2,3,4,5','clinic_manual_status'=>'scheduled','clinic_closed_dates'=>'','clinic_queue_capacity'=>'100'];
    try {
        $stmt=$db->prepare("SELECT setting_key,setting_value FROM system_settings WHERE setting_key IN ('clinic_open_time','clinic_close_time','clinic_operating_days','clinic_manual_status','clinic_closed_dates','clinic_queue_capacity')");
        $stmt->execute();
        foreach($stmt->fetchAll(PDO::FETCH_KEY_PAIR) as $key=>$value){if(trim((string)$value)!=='')$defaults[$key]=(string)$value;}
    } catch(Throwable $e){error_log('Clinic availability settings unavailable.');return ['is_open'=>false,'label'=>'Unavailable','hours'=>'Unavailable','days'=>[],'reason'=>'Clinic availability cannot be verified. Normal queue requests are unavailable. Use SOS for emergencies.','capacity'=>0];}
    $now=$now?:new DateTimeImmutable('now',new DateTimeZone('Asia/Manila'));
    $days=array_values(array_filter(array_map('intval',explode(',',$defaults['clinic_operating_days'])),static fn($d)=>$d>=1&&$d<=7));
    $open=DateTimeImmutable::createFromFormat('Y-m-d H:i',$now->format('Y-m-d').' '.$defaults['clinic_open_time'],$now->getTimezone());
    $close=DateTimeImmutable::createFromFormat('Y-m-d H:i',$now->format('Y-m-d').' '.$defaults['clinic_close_time'],$now->getTimezone());
    $closedDates=array_map('trim',explode(',',$defaults['clinic_closed_dates']));
    $isOpen=$open&&$close&&$open<$close&&in_array((int)$now->format('N'),$days,true)&&$now>=$open&&$now<$close&&$defaults['clinic_manual_status']!=='closed'&&!in_array($now->format('Y-m-d'),$closedDates,true);
    $reason=$defaults['clinic_manual_status']==='closed'?'Clinic staff have closed normal queue requests.':(in_array($now->format('Y-m-d'),$closedDates,true)?'The clinic is closed for a holiday or scheduled closure.':'The clinic is outside its operating schedule.');
    return ['is_open'=>(bool)$isOpen,'label'=>$isOpen?'Open':'Closed','hours'=>date('g:i A',strtotime($defaults['clinic_open_time'])).' – '.date('g:i A',strtotime($defaults['clinic_close_time'])),'days'=>$days,'reason'=>$isOpen?'Normal queue requests are available.':$reason,'capacity'=>max(0,(int)$defaults['clinic_queue_capacity'])];
}
