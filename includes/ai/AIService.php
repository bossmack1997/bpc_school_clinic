<?php
/**
 * AIService.php
 * Main orchestrator for the MICA AI Health Assistant.
 *
 * Workflow:
 * 1. Receive user message
 * 2. Check for emergency or mental health crisis safety
 * 3. Retrieve multi-turn conversation history
 * 4. Detect intent via IntentDetector
 * 5. Fetch comprehensive live database context via DatabaseContext
 * 6. Build structured system prompt + messages via PromptBuilder
 * 7. Call OpenRouter API with multi-turn context
 * 8. If API fails/offline, fallback to factual database reply / rule-based reply
 * 9. Store exchange in ChatHistory
 * 10. Return clean response
 */

require_once __DIR__ . '/IntentDetector.php';
require_once __DIR__ . '/DatabaseContext.php';
require_once __DIR__ . '/PromptBuilder.php';
require_once __DIR__ . '/OpenRouter.php';
require_once __DIR__ . '/ChatHistory.php';
require_once __DIR__ . '/NearbyHospitalService.php';

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
    private array $locationData = [
        'latitude' => null,
        'longitude' => null,
        'accuracy' => null,
        'timestamp' => null,
        'status' => 'unrequested',
    ];

    public function __construct(PDO $db, array $user, array $config = [])
    {
        $this->db = $db;
        $this->user = $user;
        $this->page = $config['page'] ?? '';
        if (isset($config['location']) && is_array($config['location'])) {
            $this->setLocationData($config['location']);
        }
        $this->intentDetector = new IntentDetector();
        $this->databaseContext = new DatabaseContext($db, $user);
        $this->promptBuilder = new PromptBuilder();
        $this->openRouter = new OpenRouter($config);
        $this->chatHistory = new ChatHistory();
    }

    public function setLocationData(array $loc): void
    {
        $lat = isset($loc['latitude']) && is_numeric($loc['latitude']) ? (float)$loc['latitude'] : (isset($loc['lat']) && is_numeric($loc['lat']) ? (float)$loc['lat'] : null);
        $lng = isset($loc['longitude']) && is_numeric($loc['longitude']) ? (float)$loc['longitude'] : (isset($loc['lng']) && is_numeric($loc['lng']) ? (float)$loc['lng'] : null);
        if ($lat !== null && ($lat < -90 || $lat > 90)) $lat = null;
        if ($lng !== null && ($lng < -180 || $lng > 180)) $lng = null;

        $this->locationData = [
            'latitude'  => $lat,
            'longitude' => $lng,
            'accuracy'  => isset($loc['accuracy']) && $loc['accuracy'] !== '' ? (string)$loc['accuracy'] : null,
            'timestamp' => isset($loc['timestamp']) && $loc['timestamp'] !== '' ? (string)$loc['timestamp'] : null,
            'status'    => isset($loc['status']) && $loc['status'] !== '' ? (string)$loc['status'] : ($lat !== null ? 'granted' : 'unrequested'),
        ];
    }

    public function getOpenRouter(): OpenRouter
    {
        return $this->openRouter;
    }

    public function getChatHistory(): ChatHistory
    {
        return $this->chatHistory;
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

        // 1. High-risk crisis / acute emergency check
        if (IntentDetector::isEmergency($message)) {
            $lower = mb_strtolower($message);
            $isCrisis = str_contains($lower, 'die') || str_contains($lower, 'hurt myself')
                || str_contains($lower, 'suicide') || str_contains($lower, 'kill myself')
                || str_contains($lower, 'end my life') || str_contains($lower, 'better without me')
                || str_contains($lower, 'mamatay') || str_contains($lower, 'ayoko na mabuhay');

            if ($isCrisis) {
                $name = $this->firstName();
                $reply = "I hear how much pain you are going through, {$name}, and I want you to know that you are not alone. Please stay safe right now.\n\n";
                $reply .= "There are people who genuinely care and want to support you. Please reach out to these free, confidential hotlines:\n\n";
                $reply .= "• **National Center for Mental Health (NCMH) Crisis Hotline**:\n";
                $reply .= "  📞 **1553** (Luzon-wide toll-free)\n";
                $reply .= "  📱 **0917-899-8727** / **0966-351-4518** / **0908-639-2672**\n\n";
                $reply .= "• **Hopeline Philippines**:\n";
                $reply .= "  📞 **2919** (Globe/TM) | **(02) 8804-4673**\n\n";
                $reply .= "You can also visit the **BPC School Clinic** or talk to a teacher, family member, or trusted friend right now. Please reach out to someone who can be with you.";

                $this->chatHistory->addMessage('user', $message, 'emergency');
                $this->chatHistory->addMessage('assistant', $reply);
                $this->logInteraction($message, $reply, 'emergency', 'crisis_safety');

                return [
                    'success' => true,
                    'reply' => $reply,
                    'intent' => 'emergency',
                    'model' => null,
                    'source' => 'crisis_safety',
                ];
            } else {
                $reply = "⚠️ **EMERGENCY DETECTED**\n\nThis sounds like an urgent medical situation. Please seek immediate help right now:\n\n";
                $reply .= "• **Go to the BPC School Clinic immediately** 🏃\n";
                $reply .= "• If the clinic is closed or you need an ambulance, call **emergency services (911)** 🚑\n";
                $reply .= "• Contact **campus security** or staff nearby for assistance.\n\n";
                $reply .= "Please don't wait — prioritize your safety and get help now! 🚨";

                $this->chatHistory->addMessage('user', $message, 'emergency');
                $this->chatHistory->addMessage('assistant', $reply);
                $this->logInteraction($message, $reply, 'emergency', 'emergency_detector');

                return [
                    'success' => true,
                    'reply' => $reply,
                    'intent' => 'emergency',
                    'model' => null,
                    'source' => 'emergency_detector',
                ];
            }
        }

        // 2. Retrieve recent history and detect intent
        $recentHistory = $this->chatHistory->getRecent(6);
        $intent = $this->intentDetector->detect($message, $recentHistory);

        // 3. Fetch comprehensive live database context
        $dbContext = $this->databaseContext->buildContextSummary();

        // 4. Build prompt with multi-turn history & user info
        $profile = ($this->user['role'] ?? '') === 'student' ? $this->databaseContext->getStudentProfile() : null;
        $userInfo = [
            'id'             => $this->user['id'] ?? 0,
            'name'           => ($this->user['first_name'] ?? '') . ' ' . ($this->user['last_name'] ?? ''),
            'role'           => $this->user['role'] ?? 'unknown',
            'student_number' => $profile['student_number'] ?? '',
            'course'         => $profile['course'] ?? '',
            'year_level'     => $profile['year_level'] ?? '',
            'section'        => $profile['section'] ?? '',
        ];

        $locationContext = NearbyHospitalService::buildLocationContext(
            $this->locationData['latitude'],
            $this->locationData['longitude'],
            $this->locationData['accuracy'],
            $this->locationData['timestamp'],
            $this->locationData['status'],
            !empty($this->locationData['is_low_accuracy']),
            isset($this->locationData['accuracy_meters']) ? (float)$this->locationData['accuracy_meters'] : null
        );

        $promptData = $this->promptBuilder->build(
            $intent,
            $message,
            $dbContext,
            $this->chatHistory->exportForApi(10),
            $userInfo,
            $this->page,
            $locationContext
        );

        // 5. Query OpenRouter API with multi-turn context
        $apiResponse = null;
        $source = 'rule_based';

        if ($this->openRouter->isEnabled()) {
            $apiResponse = $this->openRouter->chat(
                $promptData['system'],
                $promptData['messages']
            );

            if ($apiResponse['success'] && !empty(trim($apiResponse['content'] ?? ''))) {
                $source = 'openrouter';
                $reply = trim($apiResponse['content']);
            } else {
                error_log('[MICA OpenRouter Error] '
                    . 'HTTP=' . ($apiResponse['http_code'] ?? 'N/A')
                    . ' | Error=' . ($apiResponse['error'] ?? 'N/A')
                    . ' | Model=' . $this->openRouter->getModel());
            }
        }

        // 6. If OpenRouter is not enabled or failed, use rich fallback
        if ($source !== 'openrouter') {
            $dbReply = $this->buildDataDrivenReply($intent, $message, $recentHistory);
            if ($dbReply !== null) {
                $reply = $dbReply;
                $source = 'database_fallback';
            } else {
                $reply = $this->generateRuleBasedReply($message, $intent);
                $source = 'rule_based_fallback';
            }
        }

        // 7. Store in chat history
        $this->chatHistory->addMessage('user', $message, $intent);
        $this->chatHistory->addMessage('assistant', $reply);

        // 8. Log interaction
        $this->logInteraction($message, $reply, $intent, $source);

        return [
            'success' => true,
            'reply'   => $reply,
            'intent'  => $intent,
            'model'   => $apiResponse['model'] ?? null,
            'source'  => $source,
        ];
    }

    /**
     * Generate fallback response based on detected intent.
     */
    private function generateRuleBasedReply(string $message, string $intent): string
    {
        $lower = mb_strtolower($message);
        $name = $this->firstName();

        return match ($intent) {
            'nearby_facilities' => NearbyHospitalService::buildFallbackReply(
                $this->locationData['latitude'],
                $this->locationData['longitude'],
                $this->locationData['status'],
                $name,
                $this->locationData['accuracy'] ?? null,
                $this->locationData['timestamp'] ?? null,
                !empty($this->locationData['is_low_accuracy']),
                isset($this->locationData['accuracy_meters']) ? (float)$this->locationData['accuracy_meters'] : null,
                $message
            ),

            'mental_health' => "I hear you, {$name}. Dealing with academic pressure, stress, or burnout can feel heavy.\n\n"
                . "Here are a few quick things that can help right now:\n"
                . "• **Take a pause:** Step away from the screen for 5 minutes and take 3 deep, slow breaths.\n"
                . "• **Break it down:** Write down just the single next task you need to do, rather than the whole project.\n"
                . "• **Talk to someone:** Sharing what you feel with a trusted friend, family member, or visiting the school clinic to talk with our nurse makes a real difference.\n\n"
                . "Would you like to try a quick breathing exercise together, or talk more about what is on your mind?",

            'greeting' => "Hello {$name}! 👋 I'm MICA, your Medical Intelligent Clinic Assistant from BPC.\n\n"
                . "How can I help you today? I can assist with checking appointments, medicine availability, BMI and vitals, medical records, or answer health questions.",

            'medical_record' => "**Medical Records** 📄\n\n"
                . "Your medical records include consultation history, vitals, and treatments.\n"
                . "• Go to **Medical Record** in your navigation to view detailed history.\n"
                . "• All records are kept confidential.",

            'appointment' => $this->appointmentReply($lower),

            'bmi' => "**Health Monitoring & BMI** ⚖️\n\n"
                . "You can view your BMI records and vital signs under the **Health Monitoring** section in your dashboard.\n"
                . "Regular checkups help maintain your overall wellness.",

            'health_monitoring' => "**Health Monitoring** 🩺\n\n"
                . "You can track vital signs (blood pressure, heart rate, temperature, BMI) in the **Health Monitoring** section.",

            'vaccination' => "**Vaccination Records** 💉\n\n"
                . "You can check your vaccine history in the **Vaccination Record** section of your dashboard.",

            'medicine_availability' => "**Medicine Inventory** 💊\n\n"
                . "The BPC Clinic provides basic over-the-counter and first-aid medicines for students during school hours.\n"
                . "Please visit the clinic so the school nurse can assess your condition and dispense appropriate medication.",

            'medical_certificate' => "**Medical Certificate** 📋\n\n"
                . "To request a Medical Certificate for school absences:\n"
                . "1. Visit the clinic for an in-person consultation, or\n"
                . "2. Submit a request through the **Medical Certificates** section.",

            'announcements' => "**Clinic Announcements** 📢\n\n"
                . "Check the **Announcements** section in your sidebar for the latest health advisories and schedules from the school clinic.",

            'clinic_schedule' => "**BPC Clinic Schedule** 🏥\n\n"
                . "• **Location:** BPC Main Campus Clinic\n"
                . "• **Hours:** Monday to Friday, 8:00 AM – 5:00 PM\n"
                . "• **Services:** Consultations, First Aid, Vitals Monitoring, Medicine Dispensing.",

            'health_education' => $this->healthEducationReply($lower),

            'general_conversation' => "I'm here to help you with anything related to the BPC Clinic, your health records, appointments, or wellness support. What would you like to check?",

            default => "I'm listening, {$name}. How can I assist you with the clinic or your health today?",
        };
    }

    /**
     * Appointment-related reply based on message content.
     */
    private function appointmentReply(string $lower): string
    {
        $name = $this->firstName();
        if (str_contains($lower, 'cancel')) {
            return "To cancel an appointment:\n\n• In our system, appointment status updates are managed by the school nurses.\n• You can coordinate directly with the clinic nurse or submit a new scheduled service request.";
        }
        if (str_contains($lower, 'reschedule')) {
            return "To reschedule an appointment:\n\n1. Open **Scheduled Services** from the left sidebar.\n2. Submit a new request with your new preferred **Date** and **Time**.\n3. The clinic nurse will review and update your schedule.";
        }
        if (str_contains($lower, 'where') || str_contains($lower, 'see') || str_contains($lower, 'view') || str_contains($lower, 'status')) {
            return "You can view your appointment records and current status:\n\n1. Open **Scheduled Services** (or **Appointments**) from the left sidebar.\n2. Look at the **\"My Scheduled Services\"** table.\n3. You will see your appointment **Date**, **Time**, **Reason**, **Nurse**, and current **Status** (Pending, Approved, Rescheduled, Completed, Rejected, Cancelled).";
        }
        // Default — booking workflow
        return "Here is how to book an appointment in the clinic system:\n\n"
            . "1. Open **Scheduled Services** (or **Appointments**) from your left sidebar menu.\n"
            . "2. Under **Request a Scheduled Service**, select your preferred **Date**.\n"
            . "3. Choose your preferred **Time**.\n"
            . "4. Enter your **Reason for Visit** in the text box.\n"
            . "5. Click **Submit Request**.\n\n"
            . "Your request will be submitted with **Pending** status awaiting nurse review. *(Note: For urgent medical needs, you can simply walk in or scan your QR code anytime without booking!)*";
    }

    /**
     * Health education reply.
     */
    private function healthEducationReply(string $lower): string
    {
        if (str_contains($lower, 'dengue')) {
            return "**About Dengue** 🦟\n\nCommon symptoms include high fever, severe headache, joint pain, and rashes. Use mosquito repellent and drink plenty of fluids. Visit the clinic immediately if you experience persistent high fever!";
        }
        if (str_contains($lower, 'flu') || str_contains($lower, 'colds') || str_contains($lower, 'ubo') || str_contains($lower, 'sipon')) {
            return "**Colds & Flu Care** 🤧\n\nRest, drink warm water or soups, and get 7-8 hours of sleep. If symptoms persist over a week or fever rises, please see the school nurse.";
        }
        if (str_contains($lower, 'fever') || str_contains($lower, 'lagnat')) {
            return "**Fever Care** 🌡️\n\nStay well hydrated, rest in a well-ventilated room, and monitor your temperature. Visit the clinic if your temperature exceeds 38.5°C or lasts more than 2 days.";
        }
        if (str_contains($lower, 'headache') || str_contains($lower, 'sakit ng ulo')) {
            return "**Headache Relief** 🤕\n\nCommon causes include dehydration, screen strain, stress, and lack of sleep. Rest in a dim room and drink water. If pain is severe or sudden, visit the clinic.";
        }
        return "I can share general wellness and self-care information. What health topic or symptom would you like to know more about?";
    }

    private function firstName(): string
    {
        $first = $this->user['first_name'] ?? 'there';
        return trim($first) !== '' ? $first : 'there';
    }

    /**
     * Build a personalized, factual reply directly from database records when offline.
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
                'medicine_availability' => $this->dataReplyMedicines($message),
                'announcements'         => $this->dataReplyAnnouncements(),
                'nearby_facilities'     => NearbyHospitalService::buildFallbackReply(
                    $this->locationData['latitude'],
                    $this->locationData['longitude'],
                    $this->locationData['status'],
                    $this->firstName()
                ),
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
        $name = $this->firstName();
        $today = date('Y-m-d');

        $upcoming = [];
        foreach ($rows as $r) {
            if (isset($r['appointment_date']) && $r['appointment_date'] >= $today
                && in_array($r['status'] ?? '', ['pending', 'approved'], true)) {
                $upcoming[] = $r;
            }
        }

        if (!empty($upcoming)) {
            $a = $upcoming[0];
            $when = format_date($a['appointment_date']);
            $time = (!empty($a['appointment_time'])) ? date('g:i A', strtotime($a['appointment_time'])) : 'Anytime';
            $status = ucfirst($a['status'] ?? 'pending');
            $reason = $a['reason'] ?? 'Consultation';
            return "📋 **Appointment Information**\n\n{$name}, you have an active appointment on **{$when}** at **{$time}** (Status: **{$status}**).\nReason: *{$reason}*.";
        }

        return "I checked our clinic records, {$name}, and you don't have any active upcoming appointments right now. Would you like help booking one?";
    }

    private function dataReplyBmi(): ?string
    {
        $rows = $this->databaseContext->getBmiRecords();
        if (empty($rows)) return null;
        $latest = $rows[0];
        $bmi = $latest['bmi'] ?? null;
        $status = $latest['bmi_status'] ?? 'Normal';
        $weight = $latest['weight_kg'] ?? '';
        $height = $latest['height_cm'] ?? '';
        $name = $this->firstName();

        if ($bmi !== null) {
            $res = "⚖️ **Your Latest Health & BMI Record**\n\n{$name}, your recorded BMI is **{$bmi}** (**{$status}**)";
            if ($weight && $height) {
                $res .= " with weight **{$weight} kg** and height **{$height} cm**.";
            } else {
                $res .= ".";
            }
            return $res;
        }
        return null;
    }

    private function dataReplyMedicalRecords(): ?string
    {
        $records = $this->databaseContext->getMedicalRecords();
        $name = $this->firstName();
        if (empty($records)) {
            return "You don't have any consultation records on file yet, {$name}. If you feel unwell, feel free to visit the school clinic!";
        }
        $r = $records[0];
        $d = !empty($r['consultation_date']) ? format_date($r['consultation_date']) : 'Recent';
        $diag = $r['diagnosis'] ?? 'General Consultation';
        $treat = $r['treatment'] ?? 'Self-care advice';
        return "📄 **Latest Clinic Record**\n\n• **Date:** {$d}\n• **Diagnosis:** {$diag}\n• **Treatment:** {$treat}\n\nYou can see full records in your **Medical Record** page.";
    }

    private function dataReplyHealthMonitoring(): ?string
    {
        return $this->dataReplyBmi();
    }

    private function dataReplyVaccination(): ?string
    {
        $vax = $this->databaseContext->getVaccinations();
        $name = $this->firstName();
        if (empty($vax)) {
            return "No vaccination records are currently logged for you, {$name}. If you received vaccines at the clinic or have certificates to log, visit the clinic nurse!";
        }
        $res = "💉 **Vaccination Records**\n\n";
        foreach (array_slice($vax, 0, 3) as $v) {
            $res .= "• **{$v['vaccine_name']}** (Dose {$v['dose_number']}) - " . format_date($v['date_administered']) . "\n";
        }
        return $res;
    }

    private function dataReplyMedicines(string $message): ?string
    {
        $meds = $this->databaseContext->getMedicines();
        if (empty($meds)) return null;

        $inStock = [];
        foreach ($meds as $m) {
            $name = $m['medicine_name'] ?? '';
            $avail = (isset($m['is_available']) && $m['is_available']) || (isset($m['quantity']) && $m['quantity'] > 0);
            if ($name && $avail) {
                $inStock[] = $name;
            }
        }

        $lower = mb_strtolower($message);
        foreach ($inStock as $item) {
            if (str_contains($lower, mb_strtolower($item))) {
                return "💊 **Medicine Available**\n\nYes, **{$item}** is currently in stock at the BPC Clinic. Please visit the clinic so the school nurse can properly assist and dispense it to you.";
            }
        }

        if (!empty($inStock)) {
            return "💊 **Clinic Medicines**\n\nThe clinic has common medicines in stock (such as " . implode(', ', array_slice($inStock, 0, 5)) . "). Please visit the clinic nurse for evaluation and dispensing.";
        }
        return null;
    }

    private function dataReplyAnnouncements(): ?string
    {
        $ann = $this->databaseContext->getAnnouncements();
        if (empty($ann)) return "There are no new clinic announcements at this time.";
        $a = $ann[0];
        $title = $a['title'] ?? 'Clinic Update';
        $content = strip_tags($a['content'] ?? '');
        return "📢 **Latest Announcement: {$title}**\n\n{$content}\n\nCheck the **Announcements** page for more details.";
    }

    private function logInteraction(string $message, string $reply, string $intent, string $source): void
    {
        try {
            $userId = $this->user['id'] ?? null;
            if ($userId) {
                log_activity($userId, 'MICA Interaction', 'ai_assistant', "Intent: {$intent} | Source: {$source}");
            }
        } catch (\Throwable $e) {
            // Silently fail if logging fails
        }
    }
}
