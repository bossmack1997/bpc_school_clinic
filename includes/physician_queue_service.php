<?php
require_once __DIR__.'/doctor_clinical_service.php';

/** Explicit physician handoff; existing intake and nursing authorship stay intact. */
final class PhysicianQueueService
{
    public function __construct(private PDO $db, private array $user) {}

    private function authority(array $actor, bool $nurse = false): void
    {
        $permission = $nurse ? 'clinic_monitor' : 'consultations_manage';
        if (($actor['status'] ?? '') !== 'active'
            || permission_profile($actor, $this->db) !== ($nurse ? 'nurse' : 'doctor')
            || !has_permission($permission, $actor, $this->db)) {
            throw new RuntimeException('Current clinical authorization is required.');
        }
    }

    private function audit(string $action, int $id): void
    {
        $this->db->prepare('INSERT INTO activity_logs(user_id,action,module,details) VALUES (?,?,\'clinical\',?)')
            ->execute([$this->user['id'], $action, 'Physician handoff #'.$id.'; clinical contents remain in the authorized record.']);
    }

    public function rows(): array
    {
        $actor=$this->db->prepare('SELECT * FROM users WHERE id=?');$actor->execute([$this->user['id']]);
        $this->authority($actor->fetch()?:[]);
        return $this->db->query("SELECT p.*,q.queue_number,u.first_name,u.last_name,u.role,
            w.chief_complaint,w.symptoms,w.assessment,w.recommendation,
            w.temperature,w.blood_pressure_systolic,w.blood_pressure_diastolic,
            w.heart_rate,w.respiratory_rate,w.oxygen_saturation,w.height_cm,w.weight_kg
            FROM physician_queue p JOIN queue_numbers q ON q.id=p.queue_id
            JOIN users u ON u.id=p.patient_user_id
            LEFT JOIN walk_in_visits w ON w.id=(SELECT MAX(v.id) FROM walk_in_visits v WHERE v.queue_id=p.queue_id)
            WHERE p.status<>'completed' ORDER BY FIELD(p.priority,'emergency','urgent','normal'),p.created_at,p.id LIMIT 100")->fetchAll();
    }

    public function refer(array $input): int
    {
        $ticket = filter_var($input['queue_id'] ?? null, FILTER_VALIDATE_INT);
        $reason = $input['reason'] ?? '';
        $priority = $input['priority'] ?? 'normal';
        if (!$ticket || $ticket < 1 || !is_string($reason) || mb_strlen(trim($reason)) < 2 || mb_strlen($reason) > 1000
            || !in_array($priority, ['normal','urgent','emergency'], true)) {
            throw new InvalidArgumentException('Select an active ticket, a referral reason and valid clinical priority.');
        }
        $this->db->beginTransaction();
        try {
            $q=$this->db->prepare('SELECT * FROM users WHERE id=? FOR UPDATE');$q->execute([$this->user['id']]);
            $this->authority($q->fetch() ?: [], true);
            $q=$this->db->prepare('SELECT * FROM queue_numbers WHERE id=? FOR UPDATE');$q->execute([$ticket]);$row=$q->fetch();
            if (!$row || !in_array($row['status'], ['waiting','called','serving'], true)) throw new RuntimeException('The ticket is closed or unavailable.');
            $field=$row['patient_type']==='teacher'?'teacher_id':'student_id';$table=$field==='teacher_id'?'teachers':'students';
            $q=$this->db->prepare("SELECT user_id FROM $table WHERE id=?");$q->execute([$row[$field]]);$patient=$q->fetchColumn();
            if (!$patient) throw new InvalidArgumentException('A linked Student or Teacher clinical profile is required.');
            $q=$this->db->prepare('SELECT id FROM physician_queue WHERE queue_id=?');$q->execute([$ticket]);
            if ($q->fetchColumn()) throw new RuntimeException('This ticket already has a physician handoff.');
            $q=$this->db->prepare('SELECT id FROM walk_in_visits WHERE queue_id=? AND assessment IS NOT NULL AND TRIM(assessment)<>\'\' LIMIT 1');$q->execute([$ticket]);
            if (!$q->fetchColumn()) throw new RuntimeException('Save the nursing assessment before referring this patient.');
            $this->db->prepare('INSERT INTO physician_queue(queue_id,patient_user_id,referred_by,priority,reason) VALUES (?,?,?,?,?)')
                ->execute([$ticket,$patient,$this->user['id'],$priority,trim($reason)]);
            $id=(int)$this->db->lastInsertId();
            $this->db->prepare('UPDATE queue_numbers SET priority=? WHERE id=?')->execute([$priority,$ticket]);
            $this->audit('Patient referred for physician assessment',$id);
            $this->db->commit();return $id;
        } catch (Throwable $e) { if ($this->db->inTransaction()) $this->db->rollBack();throw $e; }
    }

    public function transition(string $action, array $input): int
    {
        $id=filter_var($input['handoff_id']??null,FILTER_VALIDATE_INT);
        $version=filter_var($input['record_version']??null,FILTER_VALIDATE_INT);
        if (!$id || $id<1 || $version===false || $version===null || $version<0 || !in_array($action,['start','complete'],true)) throw new InvalidArgumentException('Select a valid handoff and version.');
        $this->db->beginTransaction();
        try {
            $q=$this->db->prepare('SELECT * FROM users WHERE id=? FOR UPDATE');$q->execute([$this->user['id']]);$this->authority($q->fetch()?:[]);
            $q=$this->db->prepare('SELECT * FROM physician_queue WHERE id=? FOR UPDATE');$q->execute([$id]);$row=$q->fetch();
            if (!$row || (int)$row['record_version']!==$version) throw new RuntimeException('Handoff changed or unavailable. Reload before continuing.');
            if ($action==='start') {
                if ($row['status']!=='awaiting' || $row['assigned_doctor_id']!==null) throw new RuntimeException('This handoff has already been claimed.');
                $this->db->prepare("UPDATE physician_queue SET assigned_doctor_id=?,status='in_progress',started_at=NOW(),record_version=record_version+1 WHERE id=?")->execute([$this->user['id'],$id]);
                $this->audit('Physician assessment started',$id);
            } else {
                if ($row['status']!=='in_progress' || (int)$row['assigned_doctor_id']!==(int)$this->user['id']) throw new RuntimeException('Only the assigned Doctor can complete an active assessment.');
                foreach (['diagnosis','treatment'] as $field) if (!is_string($input[$field]??null) || trim($input[$field])==='') throw new InvalidArgumentException('Diagnosis / findings and treatment plan are required for completion.');
                // Never trust client patient IDs or encounter IDs to link this handoff.
                $record=$input;$record['patient_user_id']=$row['patient_user_id'];$record['id']=0;
                $consultation=(new DoctorClinicalService($this->db,$this->user))->save($record);
                $vitals=$this->db->prepare('SELECT temperature,blood_pressure_systolic,blood_pressure_diastolic,heart_rate,respiratory_rate,oxygen_saturation,height_cm,weight_kg FROM walk_in_visits WHERE queue_id=? ORDER BY id DESC LIMIT 1');$vitals->execute([$row['queue_id']]);
                $this->db->prepare('UPDATE consultations SET vital_signs=? WHERE id=?')->execute([json_encode($vitals->fetch()?:[],JSON_THROW_ON_ERROR),$consultation]);
                $this->db->prepare("UPDATE physician_queue SET consultation_id=?,status='completed',completed_at=NOW(),record_version=record_version+1 WHERE id=?")->execute([$consultation,$id]);
                $this->audit('Physician assessment completed',$id);
            }
            $this->db->commit();return $id;
        } catch (Throwable $e) { if ($this->db->inTransaction()) $this->db->rollBack();throw $e; }
    }
}
