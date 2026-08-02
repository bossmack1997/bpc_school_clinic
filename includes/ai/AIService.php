<?php
/**
 * AIService.php
 * Main orchestrator for the AI Health Assistant.
 *
 * Workflow:
 * 1. Receive user message
 * 2. Detect intent via IntentDetector (returns string)
 * 3. Fetch database context via DatabaseContext
 * 4. Build prompt via PromptBuilder
 * 5. Send to OpenRouter API (with fallback to rule-based)
 * 6. Store in ChatHistory
 * 7. Return response
 */

require_once __DIR__ . '/IntentDetector.php';
require_once __DIR__ . '/DatabaseContext.php';
require_once __DIR__ . '/PromptBuilder.php';
require_once __DIR__ . '/OpenRouter.php';
require_once __DIR__ . '/ChatHistory.php';

class AIService
{
    private IntentDetector $intentDetector;
    private DatabaseContext $databaseContext;
    private PromptBuilder $promptBuilder;
    private OpenRouter $openRouter;
    private ChatHistory $chatHistory;
    private PDO $db;
    private array $user;
    private string $page = '';

    /** Intents that can be answered directly from database records (DB-first) */
    private const DATA_INTENTS = [
        'appointment',
        'bmi',
        'medical_record',
        'health_monitoring',
        'vaccination',
        'medicine_availability',
        'announcements',
        'clinic_schedule',
    ];

    public function __construct(PDO $db, array $user, array $config = [])
    {
        $this->db = $db;
        $this->user = $user;
        $this->page = $config['page'] ?? '';
        $this->intentDetector = new IntentDetector();
        $this->databaseContext = new DatabaseContext($db, $user);
        $this->promptBuilder = new PromptBuilder();
        $this->openRouter = new OpenRouter($config);
        $this->chatHistory = new ChatHistory();
    }

    /**
     * Process a user message and return an AI response.
     *
     * @param string $message The user's message
     * @return array{success: bool, reply: string, intent: string, model: ?string, source: string}
     */
    public function processMessage(string $message): array
    {
        $message = trim($message);
        if ($message === '') {
            return [
                'success' => false,
                'reply' => 'Please type a message so I can help you.',
                'intent' => 'empty',
                'model' => null,
                'source' => 'validation',
            ];
        }

        // 1. Emergency check — fastest path
        if (IntentDetector::isEmergency($message)) {
            $reply = "⚠️ **EMERGENCY DETECTED**\n\nThis sounds like a medical emergency. Please stop chatting and seek immediate help:\n\n";
            $reply .= "• **Go to the BPC Clinic** right now\n";
            $reply .= "• If the clinic is closed, call **emergency services**\n";
            $reply .= "• Contact **campus security** for assistance\n\n";
            $reply .= "Don't wait for a reply — get help now! 🚨";

            return [
                'success' => true,
                'reply' => $reply,
                'intent' => 'emergency',
                'model' => null,
                'source' => 'emergency_detector',
            ];
        }

        // 2. Detect intent — returns string directly
        $recentHistory = $this->chatHistory->getRecent(4);
        $intent = $this->intentDetector->detect($message, $recentHistory);

        // 2b. DB-FIRST: For data intents, try to answer from live records
        // before calling the LLM/rule-based fallback. This guarantees real,
        // personalized data is used and never fabricated.
        if ($this->hasDataRecords($intent)) {
            $dbReply = $this->buildDataDrivenReply($intent, $message, $recentHistory);
            if ($dbReply !== null) {
                $this->chatHistory->addMessage('user', $message, $intent);
                $this->chatHistory->addMessage('assistant', $dbReply);
                $this->logInteraction($message, $dbReply, $intent, 'database');
                return [
                    'success'  => true,
                    'reply'    => $dbReply,
                    'intent'   => $intent,
                    'model'    => null,
                    'source'   => 'database',
                ];
            }
        }

        // 3. Fetch database context
        $dbContext = $this->databaseContext->buildContextSummary();

// 4. Build the prompt — now includes user info & current page
        $userInfo = [
            'id'   => $this->user['id'] ?? 0,
            'name' => ($this->user['first_name'] ?? '') . ' ' . ($this->user['last_name'] ?? ''),
            'role' => $this->user['role'] ?? 'unknown',
        ];
        $promptData = $this->promptBuilder->build(
            $intent,
            $message,
            $dbContext,
            $this->chatHistory->exportForApi(6),
            $userInfo,
            $this->page
        );

        // 5. Try OpenRouter API
        $apiResponse = null;
        $source = 'rule_based';

        if ($this->openRouter->isEnabled()) {
            $apiResponse = $this->openRouter->chat(
                $promptData['system'],
                $promptData['messages']
            );

            if ($apiResponse['success']) {
                $source = 'openrouter';
            } else {
                // Log why OpenRouter failed for debugging
                error_log('[AI Debug] OpenRouter API call failed: '
                    . 'HTTP=' . ($apiResponse['http_code'] ?? 'N/A')
                    . ' | Error=' . ($apiResponse['error'] ?? 'N/A')
                    . ' | Type=' . ($apiResponse['error_type'] ?? 'N/A')
                    . ' | Model=' . ($this->openRouter->getModel() ?? 'N/A')
                    . ' | Enabled=' . ($this->openRouter->isEnabled() ? 'yes' : 'no'));
            }
        } else {
            error_log('[AI Debug] OpenRouter is not enabled. API key empty or invalid. '
                . 'Model=' . $this->openRouter->getModel()
                . ' | Key starts with: ' . substr($this->openRouter->getApiKey(), 0, 10) . '...');
        }

        // 6. Fallback to rule-based response if API fails
        if (!$apiResponse || !$apiResponse['success']) {
            $reply = $this->generateRuleBasedReply($message, $intent);
        } else {
            $reply = $apiResponse['content'];
        }

        // 7. Store in chat history
        $this->chatHistory->addMessage('user', $message, $intent);
        $this->chatHistory->addMessage('assistant', $reply);

        // 8. Log the interaction
        $this->logInteraction($message, $reply, $intent, $source);

        return [
            'success' => true,
            'reply' => $reply,
            'intent' => $intent,
            'model' => $apiResponse['model'] ?? null,
            'source' => $source,
        ];
    }

    /**
     * Generate a rule-based fallback response based on detected intent.
     * Supports all 13 intents defined in IntentDetector.
     */
    private function generateRuleBasedReply(string $message, string $intent): string
    {
        $lower = mb_strtolower($message);

        return match ($intent) {
            'greeting' => "Hi there! 👋 I'm your BPC Clinic AI Health Assistant. I can help you with:\n\n• 🏥 **Wellness tips and health information**\n• 📅 **Appointment booking guidance**\n• 📋 **Clinic procedures info**\n• 💊 **Medicine and vaccination info**\n\nWhat would you like to know about?",

            'medical_record' => "**Medical Records** 📄\n\nYour medical records include all your consultation history, diagnoses, treatments, and any uploaded documents.\n\n• **To view your records:** Go to the **Medical Record** section in your dashboard\n• You can see past consultations, doctor's notes, and treatments\n• Records are kept confidential and secure\n\nWould you like me to help you find something specific in your records?",

            'appointment' => $this->appointmentReply($lower),

            'bmi' => "**BMI (Body Mass Index)** ⚖️\n\nBMI is a measure based on your height and weight.\n\n• **To check your BMI:** Go to the **Health Monitoring** section\n• You can see your current BMI and history\n\n**BMI Categories:**\n• Below 18.5 — Underweight\n• 18.5 – 24.9 — Normal ✅\n• 25.0 – 29.9 — Overweight\n• 30.0 and above — Obese\n\nWould you like tips on maintaining a healthy weight? 😊",

            'health_monitoring' => "**Health Monitoring** 🩺\n\nYou can track your vital signs in the **Health Monitoring** section:\n\n• **Height & Weight** — for BMI calculation\n• **Blood Pressure** — systolic/diastolic\n• **Heart Rate** — beats per minute\n• **Temperature** — body temperature\n• **Oxygen Saturation** — blood oxygen level\n\nRegular monitoring helps you stay on top of your health! Would you like to know more?",

            'vaccination' => "**Vaccination Records** 💉\n\nYou can view your vaccination history under **Vaccination Record** in your dashboard.\n\nThis includes:\n• Vaccine name and dose number\n• Date administered\n• Next dose due date (if applicable)\n\nIf you need a specific vaccine or booster, visit the clinic and let the nurse know!",

            'medicine_availability' => "**Medicine Availability** 💊\n\nThe BPC Clinic maintains a medicine inventory for common ailments.\n\n• **To check medicine stock:** Visit the clinic directly\n• The nurse can dispense medicine after consultation\n• For prescription medications, you need a doctor's prescription\n\n⚠️ Always inform the nurse about any allergies before taking medicine.",

            'medical_certificate' => "**Medical Certificates** 📋\n\nYou can request a Medical Certificate if you need one for class absences or other purposes.\n\n**How to get one:**\n1. Visit the clinic for a consultation\n2. Or go to the **Medical Certificates** section in your dashboard\n3. A certificate is issued based on your consultation record\n\nNeed more details about the process? 😊",

            'announcements' => "**Clinic Announcements** 📢\n\nYou can view the latest clinic announcements in the **Announcements** section of your dashboard.\n\nThis includes:\n• Clinic schedules and closures\n• Health awareness campaigns\n• Important health reminders\n• School health events\n\nCheck back regularly for updates! Is there a specific announcement you're looking for?",

            'clinic_schedule' => "**BPC Clinic Schedule** 🏥\n\n• **Location:** BPC Campus\n• **Services:** Consultations, first aid, health monitoring, vaccinations, medicine dispensing\n• **Staff:** Available school nurses\n\nYou can book an appointment through the system or visit during clinic hours for walk-in consultations.\n\nNeed the clinic contact number or specific hours?",

            'emergency' => "⚠️ **EMERGENCY**\n\nIf you or someone near you is experiencing a medical emergency:\n\n1. **Go to the BPC Clinic immediately** 🏃\n2. If the clinic is closed, call **emergency services** 🚑\n3. Contact **campus security** for assistance\n4. Do not wait — every second counts!\n\nPlease get help right now! 🚨",

            'health_education' => $this->healthEducationReply($lower),

            'general_conversation' => "I'd be happy to help! Here are some things I can assist you with:\n\n• 💊 **Medicine availability** — what's in stock\n• 📄 **Medical records** — view your history\n• 📅 **Appointments** — book or check status\n• ⚖️ **BMI** — check your body mass index\n• 🩺 **Health monitoring** — vital signs tracking\n• 💉 **Vaccinations** — view your vaccine records\n• 📋 **Medical certificates** — how to request\n• 📢 **Announcements** — latest clinic news\n• 🏥 **Clinic schedule** — hours and location\n• 📚 **Health education** — learn about conditions\n\nWhat would you like to know about? 😊",

            default => "I'm here to help with anything about the BPC Clinic! I can assist with appointments, health records, medicine info, and more. What's on your mind? 😊",
        };
    }

    /**
     * Appointment-related reply based on message content.
     */
    private function appointmentReply(string $lower): string
    {
        if (str_contains($lower, 'cancel')) {
            return "To cancel an appointment:\n\n1. Go to your **Appointment Booking** page\n2. Find the appointment you want to cancel\n3. Contact the clinic to request cancellation\n\nIf you need to reschedule, you can book a new appointment after canceling. Need help with anything else?";
        }
        if (str_contains($lower, 'reschedule')) {
            return "To reschedule an appointment:\n\n1. Contact the clinic nurse directly or\n2. Book a new appointment and mention it's a reschedule\n\nYou can find the Appointment Booking section in your sidebar.";
        }
        if (str_contains($lower, 'status') || str_contains($lower, 'when')) {
            return "You can check the status of your appointments in the **Appointment Booking** page in your sidebar. You'll see the status listed as:\n\n• ⏳ **Pending** — waiting for nurse approval\n• ✅ **Approved** — confirmed\n• ❌ **Rejected/Cancelled** — not approved\n• ✅ **Completed** — done\n\nIf you have an urgent concern, visit the clinic directly! 😊";
        }
        // Default — booking
        return "I'd be happy to help you book an appointment! Here's how:\n\n1. Go to **Appointment Booking** in your sidebar\n2. Choose your preferred **date and time**\n3. Describe your **reason for visiting**\n4. Click **Submit Request**\n\nThe school nurse will review your request and approve or reschedule it.\n\nWould you like me to explain more about any of these steps? 📅";
    }

    /**
     * Health education reply based on keywords.
     */
    private function healthEducationReply(string $lower): string
    {
        if (str_contains($lower, 'dengue')) {
            return "**About Dengue** 🦟\n\nDengue is a viral infection transmitted by mosquitoes. Common symptoms include:\n• High fever (40°C/104°F)\n• Severe headache\n• Pain behind the eyes\n• Joint and muscle pain\n• Rash\n\n**Prevention:** Use mosquito repellent, wear long sleeves, and keep your surroundings clean.\n\n⚠️ If you suspect dengue, visit the clinic immediately for proper evaluation!";
        }
        if (str_contains($lower, 'flu') || str_contains($lower, 'colds') || str_contains($lower, 'cough')) {
            return "**About Colds and Flu** 🤧\n\nCommon symptoms:\n• Runny or stuffy nose\n• Sneezing\n• Sore throat\n• Cough\n• Body aches\n• Fever (more common with flu)\n\n**Self-care tips:**\n• Rest and stay hydrated 🛌\n• Warm fluids can soothe your throat ☕\n• Get plenty of sleep 😴\n\n⚠️ **See the nurse if:** symptoms last more than a week, you have high fever, or trouble breathing.";
        }
        if (str_contains($lower, 'fever') || str_contains($lower, 'lagnat')) {
            return "**About Fever** 🌡️\n\nFever is your body's natural response to infection.\n\n**Self-care:**\n• Rest and stay hydrated\n• Monitor your temperature\n• Keep cool with light clothing\n• Take paracetamol as directed (if available)\n\n⚠️ **See the nurse if:**\n• Temperature above 38.5°C\n• Fever lasts more than 2 days\n• Accompanied by rash, difficulty breathing, or severe headache";
        }
        if (str_contains($lower, 'headache') || str_contains($lower, 'migraine')) {
            return "**About Headaches** 🤕\n\nCommon causes:\n• Stress and tension\n• Dehydration\n• Lack of sleep\n• Eye strain from screens\n\n**Self-care tips:**\n• Rest in a quiet, dark room 😴\n• Stay hydrated 💧\n• Take breaks from screens 📱\n\n⚠️ **See the nurse if:** headache is severe, sudden, or comes with vision changes or vomiting.";
        }

        return "**Health Education** 📚\n\nI can share information about various health topics, including:\n\n• 🦟 **Dengue** — symptoms and prevention\n• 🤧 **Colds and Flu** — self-care tips\n• 🌡️ **Fever** — when to seek help\n• 🤕 **Headaches** — causes and relief\n• 🥗 **Nutrition** — healthy eating tips\n• 🧘 **Stress Management** — ways to cope\n• 😴 **Sleep Hygiene** — better sleep tips\n\nWhat topic would you like to learn more about?";
    }

    // ================================================================
    // DB-FIRST answer engine
    // ================================================================

    private function firstName(): string
    {
        return $this->user['first_name'] ?? 'there';
    }

    /**
     * Check whether the logged-in user actually has records for the intent.
     */
    private function hasDataRecords(string $intent): bool
    {
        try {
            return match ($intent) {
                'appointment'           => !empty($this->databaseContext->getAppointments()),
                'bmi'                   => !empty($this->databaseContext->getBmiRecords()),
                'medical_record'        => !empty($this->databaseContext->getMedicalRecords()),
                'health_monitoring'     => !empty($this->databaseContext->getBmiRecords()),
                'vaccination'           => !empty($this->databaseContext->getVaccinations()),
                'medicine_availability' => !empty($this->databaseContext->getMedicines()),
                'announcements'         => !empty($this->databaseContext->getAnnouncements()),
                'clinic_schedule'       => !empty($this->databaseContext->getClinicSchedule()),
                default                 => false,
            };
        } catch (\Throwable $e) {
            error_log('[MICA DB Error] hasDataRecords(' . $intent . '): ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Build a personalized, factual reply directly from database records.
     * Returns null when there is no relevant data so the caller can fall
     * through to the LLM / rule-based general guidance.
     */
    private function buildDataDrivenReply(string $intent, string $message, array $history = []): ?string
    {
        try {
            return match ($intent) {
                'appointment'           => $this->dataReplyAppointment($message),
                'bmi'                   => $this->dataReplyBmi(),
                'medical_record'        => $this->dataReplyMedicalRecords(),
                'health_monitoring'     => $this->dataReplyHealthMonitoring(),
                'vaccination'           => $this->dataReplyVaccination(),
                'medicine_availability' => $this->dataReplyMedicines(),
                'announcements'         => $this->dataReplyAnnouncements(),
                'clinic_schedule'       => $this->dataReplyClinicSchedule(),
                default                 => null,
            };
        } catch (\Throwable $e) {
            error_log('[MICA DB Error] buildDataDrivenReply(' . $intent . '): ' . $e->getMessage());
            return null;
        }
    }

    private function dataReplyAppointment(string $message): ?string
    {
        $rows = $this->databaseContext->getAppointments();
        if (empty($rows)) return null;
        $name = $this->firstName();

        $lower = mb_strtolower($message);
        $today = date('Y-m-d');

        // Look for next pending/approved/completed appointment
        $upcoming = [];
        foreach ($rows as $r) {
            if (isset($r['appointment_date']) && $r['appointment_date'] >= $today
                && in_array($r['status'] ?? '', ['pending', 'approved'], true)) {
                $upcoming[] = $r;
            }
        }
        usort($upcoming, fn($a, $b) => strcmp($a['appointment_date'] . ' ' . ($a['appointment_time'] ?? ''), $b['appointment_date'] . ' ' . ($b['appointment_time'] ?? '')));

        if ($upcoming) {
            $a = $upcoming[0];
            $when = format_date($a['appointment_date']);
            $time = isset($a['appointment_time']) && $a['appointment_time']
                ? date('g:i A', strtotime($a['appointment_time'])) : '—';
            $reason = $a['reason'] ?? 'General consultation';
            $status = ucfirst($a['status'] ?? 'pending');

            if (str_contains($lower, 'today') && $a['appointment_date'] === $today) {
                return "📋 **From the clinic records** — Yes, {$name}, you have an appointment **today** at **{$time}** ({$status}). Reason: {$reason}.";
            }
            return "📋 **From the clinic records** — {$name}, your next appointment is on **{$when}** at **{$time}** ({$status}). Reason: {$reason}.";
        }

        // No upcoming — show the latest record so we never fabricate
        $rows = array_values(array_filter($rows, function ($r) use ($today) {
            return isset($r['appointment_date']) && $r['appointment_date'] < $today;
        }));
        if ($rows) {
            $a = $rows[0];
            $when = format_date($a['appointment_date']);
            $status = ucfirst($a['status'] ?? 'completed');
            return "📋 **From the clinic records** — I'm sorry, {$name}, I couldn't find any upcoming appointment for you. Your latest visit was on **{$when}** ({$status}). Would you like guidance on booking a new one?";
        }

        return null;
    }

    private function dataReplyBmi(): ?string
    {
        $rows = $this->databaseContext->getBmiRecords();
        if (empty($rows)) return null;
        $name = $this->firstName();
        $latest = $rows[0];

        $bmi = $latest['bmi'] ?? null;
        $status = $latest['bmi_status'] ?? null;
        $recorded = isset($latest['recorded_at']) ? format_date($latest['recorded_at']) : null;
        $weight = $latest['weight_kg'] ?? null;
        $height = $latest['height_cm'] ?? null;

        $line = "**BMI (Body Mass Index)** ⚖️\n";
        if ($bmi !== null) {
            $line .= "📋 **From the clinic records** — {$name}, your latest BMI is **{$bmi}** (" . ($status ? e($status) : '—') . ")";
            if ($recorded) $line .= ", recorded on **{$recorded}**";
            $line .= ".\n";
        }
        if (count($rows) > 1) {
            $line .= "You have **" . count($rows) . "** BMI records on file.\n";
        }
        $line .= "\n**BMI Categories:**\n• Below 18.5 — Underweight\n• 18.5 – 24.9 — Normal ✅\n• 25.0 – 29.9 — Overweight\n• 30.0 and above — Obese\n\nWould you like general wellness tips to keep a healthy weight? 😊";
        return $line;
    }

    private function dataReplyMedicalRecords(): ?string
    {
        $rows = $this->databaseContext->getMedicalRecords();
        if (empty($rows)) return null;
        $name = $this->firstName();

        $lines = ["**Medical Records** 📄\n", "📋 **From the clinic records** — {$name}, I found **" . count($rows) . "** medical record(s):\n"];
        $shown = 0;
        foreach ($rows as $r) {
            if ($shown >= 3) break;
            $date = isset($r['consultation_date']) ? format_date($r['consultation_date']) : (isset($r['uploaded_at']) ? format_date($r['uploaded_at']) : null);
            $dx = $r['diagnosis'] ?? $r['description'] ?? null;
            if ($date || $dx) {
                $lines[] = "• " . ($date ? "**{$date}**" : '') . ($date && $dx ? ' — ' : '') . ($dx ? e($dx) : '') . ($r['treatment'] ?? '' ? ' · ' . e($r['treatment']) : '');
                $shown++;
            }
        }
        if (count($rows) > 3) $lines[] = "…and " . (count($rows) - 3) . " more record(s).";
        $lines[] = "\nRecords are kept confidential and secure. Would you like me to help you find something specific?";
        return implode("\n", $lines);
    }

    private function dataReplyHealthMonitoring(): ?string
    {
        $rows = $this->databaseContext->getBmiRecords();
        if (empty($rows)) return null;
        $name = $this->firstName();
        $latest = $rows[0];

        $line = "**Health Monitoring** 🩺\n";
        $line .= "📋 **From the clinic records** — {$name}, your latest vitals (recorded " . (isset($latest['recorded_at']) ? format_date($latest['recorded_at']) : 'recently') . "):\n";

        $fields = [
            'height_cm' => 'Height',
            'weight_kg' => 'Weight',
        ];
        $stats = [];
        foreach ($fields as $col => $label) {
            if (!empty($latest[$col])) $stats[] = "{$label}: {$latest[$col]}";
        }
        foreach (['blood_pressure', 'heart_rate', 'pulse_rate', 'temperature', 'oxygen_saturation'] as $col) {
            if (!empty($latest[$col])) $stats[] = "{$col}: " . $latest[$col];
        }
        if ($stats) $line .= "• " . implode(', ', $stats) . "\n";
        if (isset($latest['bmi'])) $line .= "• BMI: **" . $latest['bmi'] . "** (" . e($latest['bmi_status'] ?? '') . ")\n";

        $line .= "\nRegular monitoring helps you stay on top of your health! Would you like tips?";
        return $line;
    }

    private function dataReplyVaccination(): ?string
    {
        $rows = $this->databaseContext->getVaccinations();
        if (empty($rows)) return null;
        $name = $this->firstName();

        $lines = ["**Vaccination Records** 💉\n", "📋 **From the clinic records** — {$name}, you have **" . count($rows) . "** vaccination record(s):\n"];
        $shown = 0;
        foreach ($rows as $r) {
            if ($shown >= 5) break;
            $vax = $r['vaccine_name'] ?? 'Vaccine';
            $dose = isset($r['dose_number']) ? ' (Dose ' . $r['dose_number'] . ')' : '';
            $date = isset($r['date_administered']) ? format_date($r['date_administered']) : '';
            $lines[] = "• **" . e($vax) . "**" . $dose . ($date ? " — {$date}" : '');
            $shown++;
        }
        if (count($rows) > 5) $lines[] = "…and " . (count($rows) - 5) . " more.";
        $lines[] = "\nIf you need a specific vaccine or booster, visit the clinic and let the nurse know!";
        return implode("\n", $lines);
    }

    private function dataReplyMedicines(): ?string
    {
        $rows = $this->databaseContext->getMedicines();
        if (empty($rows)) return null;
        $name = $this->firstName();
        $role = $this->user['role'] ?? '';

        if ($role === 'student') {
            $m = $rows[0];
            $avail = (int)($m['available'] ?? 0);
            $low = (int)($m['low_stock'] ?? 0);
            $out = (int)($m['out_of_stock'] ?? 0);
            $line = "**Medicine Availability** 💊\n";
            $line .= "📋 **From the clinic records** — The clinic currently has:\n";
            $line .= "• **{$avail}** medicine type(s) in stock\n";
            if ($low > 0) $line .= "• {$low} running **low**\n";
            if ($out > 0) $line .= "• {$out} **out of stock**\n";
            $line .= "\nThe nurse can dispense medicine after a consultation. Always inform the nurse about any allergies before taking medicine.";
            return $line;
        }

        // Nurse/Admin — show actual stock levels
        $lines = ["**Medicine Inventory** 💊\n", "📋 **From the clinic records**, here's the current inventory:\n"];
        $shown = 0;
        foreach ($rows as $m) {
            if ($shown >= 8) break;
            $qty = $m['quantity'] ?? 0;
            $unit = $m['unit'] ?? 'unit(s)';
            $reorder = $m['reorder_level'] ?? 0;
            $flag = $qty <= $reorder ? ' ⚠️' : '';
            $lines[] = "• {$m['medicine_name']} — **{$qty} {$unit}**{$flag}";
            $shown++;
        }
        if (count($rows) > 8) $lines[] = "…and " . (count($rows) - 8) . " more.";
        $lines[] = "\nTotal: **" . count($rows) . "** medicine item(s) on file.";
        return implode("\n", $lines);
    }

    private function dataReplyAnnouncements(): ?string
    {
        $rows = $this->databaseContext->getAnnouncements();
        if (empty($rows)) return null;

        $lines = ["**Clinic Announcements** 📢\n", "📋 **From the clinic records**, here are the latest announcements:\n"];
        $shown = 0;
        foreach ($rows as $a) {
            if ($shown >= 5) break;
            $pin = !empty($a['is_pinned']) ? ' 📌' : '';
            $date = isset($a['created_at']) ? format_date($a['created_at']) : '';
            $lines[] = "• **" . e($a['title']) . "**" . $pin . ($date ? " — {$date}" : '') . "\n  " . e(mb_substr($a['content'] ?? '', 0, 120)) . (mb_strlen($a['content'] ?? '') > 120 ? '…' : '');
            $shown++;
        }
        $lines[] = "\nCheck the Announcements section for full details!";
        return implode("\n", $lines);
    }

    private function dataReplyClinicSchedule(): ?string
    {
        $schedule = $this->databaseContext->getClinicSchedule();
        if (empty($schedule)) return null;

        $name = $schedule['clinic_name'] ?? 'BPC School Clinic';
        $address = $schedule['school_address'] ?? 'Bulacan Polytechnic College Campus';
        $contact = $schedule['clinic_contact'] ?? 'Contact the clinic directly';

        return "**BPC Clinic Schedule** 🏥\n"
            . "📋 **From the clinic records**:\n"
            . "• **{$name}**\n"
            . "• 📍 {$address}\n"
            . "• ☎️ {$contact}\n\n"
            . "Appointments can be booked through the system, or you may walk in during clinic hours for consultations. Need help booking? 😊";
    }

    /**
     * Personalized "not found" note shown when a data intent has no records.
     * Clearly separates the factual system status from general AI advice.
     */
    private function buildDataGapNote(string $intent): string
    {
        $name = $this->firstName();
        $note = match ($intent) {
            'appointment'           => "I'm sorry, {$name}, I couldn't find any appointment records for you yet.",
            'bmi'                   => "I'm sorry, {$name}, I couldn't find any BMI records for you yet. Visit the clinic or check the Health Monitoring section after your next vitals check.",
            'medical_record'        => "I'm sorry, {$name}, I couldn't find any medical records for you yet.",
            'health_monitoring'     => "I'm sorry, {$name}, I couldn't find any health monitoring records for you yet.",
            'vaccination'           => "I'm sorry, {$name}, I couldn't find any vaccination records for you yet.",
            'medicine_availability' => "I'm sorry, {$name}, the clinic's medicine inventory is currently empty or unavailable.",
            'announcements'         => "I'm sorry, {$name}, there are no clinic announcements posted right now.",
            'clinic_schedule'       => "I'm sorry, {$name}, the clinic's schedule details haven't been configured yet.",
            default                 => "I'm sorry, {$name}, I couldn't find that information in the clinic records.",
        };
        return $note . "\n\nI can still share general guidance about this topic — please confirm if you'd like that.";
    }

    /**
     * Log the AI interaction to the activity log.
     */
    private function logInteraction(string $message, string $reply, string $intent, string $source): void
    {
        try {
            log_activity(
                $this->user['id'],
                'AI: ' . $intent . ' (' . $source . ')',
                'ai_assistant',
                mb_substr($message, 0, 100)
            );
        } catch (\Exception $e) {
            // Silently fail
        }
    }

    public function getChatHistory(): ChatHistory { return $this->chatHistory; }
    public function getDatabaseContext(): DatabaseContext { return $this->databaseContext; }
    public function getIntentDetector(): IntentDetector { return $this->intentDetector; }
    public function getOpenRouter(): OpenRouter { return $this->openRouter; }
}

