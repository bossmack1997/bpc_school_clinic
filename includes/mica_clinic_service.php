<?php
/** Deterministic, permission-scoped data service used by MICA clinic tools. */
final class MicaClinicService
{
    public function __construct(private PDO $db, private array $user)
    {
        if (!has_permission('clinical_records_read',$user,$db)) {
            throw new RuntimeException('MICA clinic management tools are restricted to nurses.');
        }
    }

    public function students(string $search, int $limit = 30): array
    {
        $search = trim($search);
        $limit = max(1, min(50, $limit));
        $where = '';
        $params = [];
        if ($search !== '') {
            $where = "WHERE s.student_number LIKE ? OR u.first_name LIKE ? OR u.last_name LIKE ? OR CONCAT(u.first_name,' ',u.last_name) LIKE ?";
            $like = '%' . $search . '%';
            $params = [$like, $like, $like, $like];
        }
        $stmt = $this->db->prepare("SELECT s.id, s.student_number, s.course, s.year_level, s.section,
                s.blood_type, s.allergies, u.first_name, u.last_name, u.gender,
                COALESCE(u.date_of_birth, s.birthdate) AS birthdate,
                (SELECT COUNT(*) FROM consultations c WHERE c.student_id=s.id) AS consultation_count,
                (SELECT MAX(c.consultation_date) FROM consultations c WHERE c.student_id=s.id) AS last_consultation
            FROM students s JOIN users u ON u.id=s.user_id $where
            ORDER BY u.last_name,u.first_name LIMIT $limit");
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function student(int $studentId): array
    {
        $stmt = $this->db->prepare("SELECT s.*, u.first_name,u.middle_name,u.last_name,u.gender,u.email,u.phone,
                COALESCE(u.date_of_birth,s.birthdate) AS date_of_birth
            FROM students s JOIN users u ON u.id=s.user_id WHERE s.id=?");
        $stmt->execute([$studentId]);
        $student = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$student) throw new InvalidArgumentException('Student record not found.');

        $stmt = $this->db->prepare("SELECT id,consultation_date,visit_type,chief_complaint,diagnosis,treatment,prescription,remarks,follow_up_date
            FROM consultations WHERE student_id=? ORDER BY consultation_date DESC LIMIT 50");
        $stmt->execute([$studentId]);
        $consultations = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $stmt = $this->db->prepare("SELECT id,visit_number,visit_date,arrival_time,status,priority_level,chief_complaint,
                symptoms,assessment,diagnosis,treatment,recommendation,temperature,blood_pressure_systolic,
                blood_pressure_diastolic,heart_rate,respiratory_rate,oxygen_saturation,height_cm,weight_kg,bmi,bmi_status
            FROM walk_in_visits WHERE student_id=? ORDER BY visit_date DESC,arrival_time DESC LIMIT 50");
        $stmt->execute([$studentId]);
        $visits = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $stmt = $this->db->prepare("SELECT mr.id,mr.description,mr.file_path,mr.file_type,c.consultation_date
            FROM medical_records mr LEFT JOIN consultations c ON c.id=mr.consultation_id
            WHERE mr.student_id=? ORDER BY mr.id DESC LIMIT 50");
        $stmt->execute([$studentId]);
        return ['student' => $student, 'consultations' => $consultations, 'visits' => $visits, 'files' => $stmt->fetchAll(PDO::FETCH_ASSOC)];
    }

    public function medicines(string $search = '', string $filter = 'all'): array
    {
        $where = ['is_archived=0'];
        $params = [];
        if ($search !== '') {
            $where[] = '(medicine_name LIKE ? OR generic_name LIKE ? OR category LIKE ? OR batch_number LIKE ?)';
            $like = '%' . trim($search) . '%';
            $params = [$like,$like,$like,$like];
        }
        if ($filter === 'low') $where[] = 'quantity<=reorder_level';
        if ($filter === 'expiring') $where[] = 'expiration_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(),INTERVAL 60 DAY)';
        if ($filter === 'available') $where[] = 'quantity>0 AND (expiration_date IS NULL OR expiration_date>=CURDATE())';
        $stmt = $this->db->prepare('SELECT id,medicine_name,generic_name,brand,category,dosage,form,supplier,manufacturer,quantity,unit,reorder_level,expiration_date,batch_number,storage_location,description,status FROM medicines WHERE '.implode(' AND ',$where).' ORDER BY medicine_name LIMIT 250');
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$row) $row['computed_status'] = medicine_status($row['quantity'],$row['reorder_level'],$row['expiration_date']);
        return $rows;
    }

    public function inventoryHistory(int $medicineId = 0): array
    {
        $where = $medicineId > 0 ? 'WHERE x.medicine_id=?' : '';
        $stmt = $this->db->prepare("SELECT * FROM (
            SELECT ml.medicine_id,m.medicine_name,ml.action AS change_type,ml.quantity AS quantity_change,ml.reason AS remarks,ml.performed_at AS changed_at,CONCAT(u.first_name,' ',u.last_name) AS changed_by
            FROM medicine_logs ml JOIN medicines m ON m.id=ml.medicine_id LEFT JOIN users u ON u.id=ml.performed_by
            UNION ALL
            SELECT il.medicine_id,m.medicine_name,il.change_type,il.quantity_change,il.remarks,il.logged_at,CONCAT(u.first_name,' ',u.last_name)
            FROM inventory_logs il JOIN medicines m ON m.id=il.medicine_id LEFT JOIN users u ON u.id=il.logged_by
        ) x $where ORDER BY changed_at DESC LIMIT 100");
        $stmt->execute($medicineId > 0 ? [$medicineId] : []);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function certificates(string $search = ''): array
    {
        $params = [];
        $where = '';
        if ($search !== '') {
            $where = "WHERE c.certificate_number LIKE ? OR s.student_number LIKE ? OR CONCAT(u.first_name,' ',u.last_name) LIKE ?";
            $like = '%' . trim($search) . '%'; $params=[$like,$like,$like];
        }
        $stmt=$this->db->prepare("SELECT c.id,c.certificate_number,c.certificate_type,c.purpose,c.remarks,c.date_issued,c.file_path,
                s.id AS student_id,s.student_number,CONCAT(u.first_name,' ',u.last_name) AS student_name,
                CONCAT(issuer.first_name,' ',issuer.last_name) AS issued_by_name
            FROM certificates c JOIN students s ON s.id=c.student_id JOIN users u ON u.id=s.user_id
            LEFT JOIN users issuer ON issuer.id=c.issued_by $where ORDER BY c.date_issued DESC,c.id DESC LIMIT 100");
        $stmt->execute($params); return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function certificate(int $id): array
    {
        $stmt=$this->db->prepare("SELECT c.*,s.student_number,s.course,s.year_level,s.section,CONCAT(u.first_name,' ',u.last_name) student_name,
                CONCAT(i.first_name,' ',i.last_name) issued_by_name
            FROM certificates c JOIN students s ON s.id=c.student_id JOIN users u ON u.id=s.user_id LEFT JOIN users i ON i.id=c.issued_by WHERE c.id=?");
        $stmt->execute([$id]); $row=$stmt->fetch(PDO::FETCH_ASSOC);
        if(!$row) throw new InvalidArgumentException('Certificate not found.');
        return $row;
    }

    public function report(string $type,string $from,string $to): array
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/D',$from) || !preg_match('/^\d{4}-\d{2}-\d{2}$/D',$to) || $from>$to) throw new InvalidArgumentException('Select a valid report date range.');
        $sql=''; $title='';
        if ($type==='daily_visits' || $type==='consultations') {
            $title=$type==='daily_visits'?'Daily Clinic Visits':'Consultation Summary';
            $sql="SELECT DATE(c.consultation_date) date,TIME(c.consultation_date) time,s.student_number,CONCAT(u.first_name,' ',u.last_name) student_name,s.course,s.year_level,s.section,c.visit_type,c.chief_complaint,c.diagnosis,c.treatment,c.follow_up_date FROM consultations c JOIN students s ON s.id=c.student_id JOIN users u ON u.id=s.user_id WHERE DATE(c.consultation_date) BETWEEN ? AND ? ORDER BY c.consultation_date DESC";
        } elseif($type==='clinic_activity') {
            $title='Clinic Activity';
            $sql="SELECT visit_date,arrival_time,visit_number,COALESCE(CONCAT(u.first_name,' ',u.last_name),w.temp_full_name) patient_name,w.visit_type,w.priority_level,w.status,w.chief_complaint,w.diagnosis FROM walk_in_visits w LEFT JOIN students s ON s.id=w.student_id LEFT JOIN users u ON u.id=s.user_id WHERE w.visit_date BETWEEN ? AND ? ORDER BY w.visit_date DESC,w.arrival_time DESC";
        } elseif($type==='emergencies') {
            $title='Emergency Incident Report';
            $sql="SELECT se.id emergency_id,se.created_at incident_time,se.emergency_type,se.location,se.status,se.priority,se.source,se.reporter_role,se.description,se.acknowledged_at,se.responded_at,se.resolved_at,se.resolution_notes FROM sos_emergencies se WHERE DATE(se.created_at) BETWEEN ? AND ? ORDER BY se.created_at DESC";
        } elseif($type==='inventory') {
            $title='Medicine Inventory Report';
            $sql="SELECT medicine_name,generic_name,category,quantity,unit,reorder_level,expiration_date,batch_number,storage_location,status FROM medicines WHERE is_archived=0 ORDER BY medicine_name";
        } else throw new InvalidArgumentException('Unsupported report type.');
        $stmt=$this->db->prepare($sql); $stmt->execute($type==='inventory'?[]:[$from,$to]);
        return ['title'=>$title,'type'=>$type,'from'=>$from,'to'=>$to,'rows'=>$stmt->fetchAll(PDO::FETCH_ASSOC)];
    }
}
