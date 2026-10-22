<?php
function clinic_availability(PDO $db, ?DateTimeImmutable $now=null): array
{
    $defaults=['clinic_open_time'=>'08:00','clinic_close_time'=>'17:00','clinic_operating_days'=>'1,2,3,4,5'];
    try {
        $stmt=$db->prepare("SELECT setting_key,setting_value FROM system_settings WHERE setting_key IN ('clinic_open_time','clinic_close_time','clinic_operating_days')");
        $stmt->execute();
        foreach($stmt->fetchAll(PDO::FETCH_KEY_PAIR) as $key=>$value){if(trim((string)$value)!=='')$defaults[$key]=(string)$value;}
    } catch(Throwable $e){error_log('Clinic availability settings: '.$e->getMessage());}
    $now=$now?:new DateTimeImmutable('now',new DateTimeZone('Asia/Manila'));
    $days=array_values(array_filter(array_map('intval',explode(',',$defaults['clinic_operating_days'])),static fn($d)=>$d>=1&&$d<=7));
    $open=DateTimeImmutable::createFromFormat('Y-m-d H:i',$now->format('Y-m-d').' '.$defaults['clinic_open_time'],$now->getTimezone());
    $close=DateTimeImmutable::createFromFormat('Y-m-d H:i',$now->format('Y-m-d').' '.$defaults['clinic_close_time'],$now->getTimezone());
    $isOpen=$open&&$close&&in_array((int)$now->format('N'),$days,true)&&$now>=$open&&$now<$close;
    return ['is_open'=>$isOpen,'label'=>$isOpen?'Open':'Closed','hours'=>date('g:i A',strtotime($defaults['clinic_open_time'])).' – '.date('g:i A',strtotime($defaults['clinic_close_time'])),'days'=>$days];
}
