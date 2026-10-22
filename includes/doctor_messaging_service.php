<?php
final class DoctorMessagingService
{
 public function __construct(private PDO $db,private array $user){}
 private function member(int $id): void {$q=$this->db->prepare("SELECT COUNT(*) FROM conversations c JOIN conversation_participants p ON p.conversation_id=c.id WHERE c.id=? AND p.user_id=? AND c.conversation_type='doctor_consultation' AND c.status='active'");$q->execute([$id,$this->user['id']]);if(!(int)$q->fetchColumn())throw new RuntimeException('This physician conversation is outside your participant scope.');}
 public function threads(): array {$q=$this->db->prepare("SELECT c.id,c.title,c.updated_at FROM conversations c JOIN conversation_participants p ON p.conversation_id=c.id WHERE p.user_id=? AND c.conversation_type='doctor_consultation' AND c.status='active' ORDER BY c.updated_at DESC LIMIT 50");$q->execute([$this->user['id']]);return $q->fetchAll();}
 public function messages(int $id): array {$this->member($id);$q=$this->db->prepare('SELECT m.id,m.message_body,m.created_at,u.first_name,u.last_name FROM messages m JOIN users u ON u.id=m.sender_id WHERE m.conversation_id=? ORDER BY m.id DESC LIMIT 100');$q->execute([$id]);return array_reverse($q->fetchAll());}
 public function start(int $recipient): int {
  if(permission_profile($this->user,$this->db)!=='doctor')throw new RuntimeException('Only a verified physician can initiate a physician consultation thread.');
  $this->db->beginTransaction();try{$q=$this->db->prepare('SELECT id FROM users WHERE id=? FOR UPDATE');$q->execute([$this->user['id']]);$q->fetch();
   $q=$this->db->prepare("SELECT id FROM users WHERE id=? AND role IN ('student','teacher','nurse') AND status='active'");$q->execute([$recipient]);if(!$q->fetchColumn())throw new InvalidArgumentException('Select an active patient or nurse account.');
   $q=$this->db->prepare("SELECT c.id FROM conversations c JOIN conversation_participants p1 ON p1.conversation_id=c.id AND p1.user_id=? JOIN conversation_participants p2 ON p2.conversation_id=c.id AND p2.user_id=? WHERE c.conversation_type='doctor_consultation' AND c.status='active' LIMIT 1");$q->execute([$this->user['id'],$recipient]);$id=$q->fetchColumn();
   if(!$id){$this->db->prepare("INSERT INTO conversations(conversation_type,title,created_by) VALUES ('doctor_consultation','Physician consultation communication',?)")->execute([$this->user['id']]);$id=(int)$this->db->lastInsertId();$q=$this->db->prepare('INSERT INTO conversation_participants(conversation_id,user_id) VALUES (?,?)');$q->execute([$id,$this->user['id']]);$q->execute([$id,$recipient]);}
   $this->db->commit();return (int)$id;
  }catch(Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}
 }
 public function send(int $id,string $message): void {
  $message=trim($message);if($message===''||mb_strlen($message)>4000)throw new InvalidArgumentException('Messages must contain 1–4000 characters.');
  $this->db->beginTransaction();try{$this->member($id);$this->db->prepare("INSERT INTO messages(conversation_id,sender_id,message_body,message_type) VALUES (?,?,?,'text')")->execute([$id,$this->user['id'],$message]);$this->db->prepare('UPDATE conversations SET updated_at=NOW() WHERE id=?')->execute([$id]);$this->db->prepare("INSERT INTO activity_logs(user_id,action,module,details) VALUES (?,'Physician conversation message sent','messages',?)")->execute([$this->user['id'],'Conversation #'.$id.'; message contents excluded from audit.']);$this->db->commit();}catch(Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}
 }
}
