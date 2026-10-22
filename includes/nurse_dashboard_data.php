<?php
require_once __DIR__.'/medicine_inventory_service.php';
final class NurseDashboardData
{
    public static function ranges(array $records, string $today): array
    {
        $date=new DateTimeImmutable($today);
        $monday=$date->modify('monday this week');
        $month=$date->modify('first day of this month');
        $daily=[];$hourly=array_fill(0,12,0);
        foreach($records as $row){
            $day=$row['day'];$count=(int)$row['total'];
            $daily[$day]=($daily[$day]??0)+$count;
            if($day===$today)$hourly[(int)floor((int)$row['hour']/2)]+=$count;
        }
        $ranges=['today'=>['labels'=>[],'values'=>$hourly,'line'=>false],'week'=>['labels'=>[],'values'=>[],'line'=>true],'month'=>['labels'=>[],'values'=>[],'line'=>true]];
        for($hour=0;$hour<24;$hour+=2)$ranges['today']['labels'][]=date('g A',strtotime(sprintf('%02d:00',$hour)));
        for($i=0;$i<7;$i++){$day=$monday->modify('+'.$i.' days');$ranges['week']['labels'][]=$day->format('D');$ranges['week']['values'][]=$daily[$day->format('Y-m-d')]??0;}
        for($i=0;$i<(int)$month->format('t');$i++){$day=$month->modify('+'.$i.' days');$ranges['month']['labels'][]=$day->format('M j');$ranges['month']['values'][]=$daily[$day->format('Y-m-d')]??0;}
        return $ranges;
    }
    public static function snapshot(PDO $db): array
    {
        $today=(string)$db->query('SELECT CURDATE()')->fetchColumn();
        $date=new DateTimeImmutable($today);
        $start=min($date->modify('monday this week')->format('Y-m-d'),$date->modify('first day of this month')->format('Y-m-d'));
        $end=$date->modify('+1 day')->format('Y-m-d');
        $stmt=$db->prepare('SELECT DATE(consultation_date) AS day,HOUR(consultation_date) AS hour,COUNT(*) AS total FROM consultations WHERE consultation_date >= ? AND consultation_date < ? GROUP BY DATE(consultation_date),HOUR(consultation_date)');
        $stmt->execute([$start,$end]);$ranges=self::ranges($stmt->fetchAll(PDO::FETCH_ASSOC),$today);
        $queue=$db->query("SELECT COALESCE(SUM(status='waiting'),0) AS waiting,COALESCE(SUM(status IN ('called','serving')),0) AS serving,COALESCE(SUM(status='completed'),0) AS completed FROM queue_numbers WHERE created_at >= CURDATE() AND created_at < CURDATE()+INTERVAL 1 DAY")->fetch(PDO::FETCH_ASSOC);
        $emergencies=(int)$db->query("SELECT COUNT(*) FROM sos_emergencies WHERE status IN ('new','active','acknowledged','responding','responded') AND resolved_at IS NULL")->fetchColumn();
        $medicines=MedicineInventoryService::requisitionItems((new MedicineInventoryService($db))->snapshot());
        return ['visits_today'=>array_sum($ranges['today']['values']),'queue_waiting'=>(int)$queue['waiting'],'queue_serving'=>(int)$queue['serving'],'queue_completed'=>(int)$queue['completed'],'emergencies'=>$emergencies,'low_stock'=>count($medicines),'ranges'=>$ranges];
    }
}
