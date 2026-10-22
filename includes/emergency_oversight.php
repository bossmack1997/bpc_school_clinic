<?php
/** Read-only administration queries; mutations remain in sos_workflow.php. */
final class EmergencyOversight
{
    public const GROUPS = [
        'active'=>['new','active','acknowledged'],
        'responding'=>['responding','responded'],
        'history'=>['resolved','cancelled','false_alarm'],
    ];
    public function __construct(private PDO $db, private bool $canRespond=true) {}
    private function select(): string {
        return "SELECT se.id,se.user_id,se.student_id,se.emergency_type,se.description,se.status,
            se.location,se.location_source,se.latitude,se.longitude,se.location_accuracy,se.location_updated_at,
            se.created_at,se.source,se.priority,se.reporter_role,se.assigned_to,se.acknowledged_by,se.acknowledged_at,
            se.responded_by,se.responded_at,se.resolved_at,se.resolution_notes,se.cancelled_by,se.cancelled_at,se.notes,
            patient.first_name, patient.last_name, patient.role AS patient_role,
            s.student_number, t.employee_id, patient.phone,
            reporter.role AS actual_reporter_role,
            CONCAT(reporter.first_name,' ',reporter.last_name) AS reporter_name,
            CONCAT(responder.first_name,' ',responder.last_name) AS responder_name
            FROM sos_emergencies se
            LEFT JOIN students s ON s.id=se.student_id
            LEFT JOIN users patient ON patient.id=COALESCE(s.user_id,se.user_id)
            LEFT JOIN teachers t ON t.user_id=patient.id
            LEFT JOIN users reporter ON reporter.id=se.user_id
            LEFT JOIN users responder ON responder.id=COALESCE(se.assigned_to,se.responded_by,se.acknowledged_by)";
    }
    public static function actions(array $row, int $actor): array {
        $owner=(int)($row['assigned_to']?:$row['responded_by']?:$row['acknowledged_by']);
        if(($owner && $owner!==$actor) || in_array($row['status'],self::GROUPS['history'],true))return [];
        $actions=[];
        if(in_array($row['status'],['new','active'],true))$actions[]='accept';
        if(in_array($row['status'],['new','active','acknowledged','responded'],true))$actions[]='respond';
        $actions[]='resolve';$actions[]='cancel';
        return $actions;
    }
    private function decorate(array $row,int $actor): array {
        $row['id']=(int)$row['id'];
        $row['patient_name']=trim(($row['first_name']??'').' '.($row['last_name']??''))?:'Unknown patient';
        $row['patient_identifier']=$row['student_number']?:($row['employee_id']?:null);
        $row['allowed_actions']=$this->canRespond?self::actions($row,$actor):[];
        return $row;
    }
    public function summary(): array {
        $row=$this->db->query("SELECT COUNT(*) AS total,
            COALESCE(SUM(status IN ('new','active','acknowledged')),0) AS active,
            COALESCE(SUM(status IN ('responding','responded')),0) AS responding,
            COALESCE(SUM(status='resolved'),0) AS resolved,
            COALESCE(SUM(status IN ('resolved','cancelled','false_alarm')),0) AS history
            FROM sos_emergencies")->fetch();
        return array_map('intval',$row);
    }
    public function listing(array $input,int $actor): array {
        $tab=(string)($input['tab']??'active');
        if(!isset(self::GROUPS[$tab]))throw new InvalidArgumentException('Choose a valid emergency tab.');
        $size=(int)($input['per_page']??10);
        if(!in_array($size,[10,25,50],true))throw new InvalidArgumentException('Choose 10, 25, or 50 reports per page.');
        $page=max(1,(int)($input['page']??1));
        $where=['se.status IN ('.implode(',',array_fill(0,count(self::GROUPS[$tab]),'?')).')'];$params=self::GROUPS[$tab];
        $status=trim((string)($input['status']??''));
        if($status!==''){
            if(!in_array($status,self::GROUPS[$tab],true))throw new InvalidArgumentException('The status does not belong to the selected tab.');
            $where[]='se.status=?';$params[]=$status;
        }
        $role=trim((string)($input['reporter_role']??''));
        if($role!==''){
            if(!in_array($role,['student','teacher','nurse','admin'],true))throw new InvalidArgumentException('Choose a valid reporter role.');
            $where[]='reporter.role=?';$params[]=$role;
        }
        $from=(string)($input['date_from']??'');$to=(string)($input['date_to']??'');
        foreach([$from,$to] as $date)if($date!=='' && (!preg_match('/^\d{4}-\d{2}-\d{2}$/D',$date) || !($parsed=DateTimeImmutable::createFromFormat('!Y-m-d',$date)) || $parsed->format('Y-m-d')!==$date))throw new InvalidArgumentException('Enter a valid date range.');
        if($from!=='' && $to!=='' && $from>$to)throw new InvalidArgumentException('The start date must be before the end date.');
        if($from!==''){$where[]='se.created_at>=?';$params[]=$from.' 00:00:00';}
        if($to!==''){$where[]='se.created_at<DATE_ADD(?,INTERVAL 1 DAY)';$params[]=$to.' 00:00:00';}
        $search=trim((string)($input['search']??''));
        if(mb_strlen($search)>120)throw new InvalidArgumentException('Search must be at most 120 characters.');
        if($search!==''){
            // Treat SQL wildcard characters as literal search text.
            $term='%'.strtr($search,['!'=>'!!','%'=>'!%','_'=>'!_']).'%';
            $where[]="(CONCAT_WS(' ',patient.first_name,patient.last_name) LIKE ? ESCAPE '!' OR CONCAT_WS(' ',reporter.first_name,reporter.last_name) LIKE ? ESCAPE '!' OR s.student_number LIKE ? ESCAPE '!' OR t.employee_id LIKE ? ESCAPE '!' OR se.emergency_type LIKE ? ESCAPE '!')";
            array_push($params,$term,$term,$term,$term,$term);
        }
        $sql=$this->select().' WHERE '.implode(' AND ',$where);
        $count=$this->db->prepare('SELECT COUNT(*) FROM ('.$sql.') matched');$count->execute($params);$total=(int)$count->fetchColumn();
        $page=min($page,max(1,(int)ceil($total/$size)));$offset=($page-1)*$size;
        $query=$this->db->prepare($sql." ORDER BY se.created_at DESC,se.id DESC LIMIT $size OFFSET $offset");$query->execute($params);
        return ['reports'=>array_map(fn($r)=>$this->decorate($r,$actor),$query->fetchAll()),'pagination'=>['page'=>$page,'per_page'=>$size,'total'=>$total,'pages'=>max(1,(int)ceil($total/$size))],'summary'=>$this->summary()];
    }
    public function detail(int $id,int $actor): ?array {
        $q=$this->db->prepare($this->select().' WHERE se.id=?');$q->execute([$id]);$row=$q->fetch();
        return $row?$this->decorate($row,$actor):null;
    }
}
