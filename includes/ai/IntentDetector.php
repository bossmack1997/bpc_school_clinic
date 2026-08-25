<?php
/**
 * IntentDetector.php
 * 
 * Detects the user's intent from a text message.
 * Supports multi-turn history for context continuity.
 * 
 * Intents:
 * - emergency (Medical emergency & high-risk crisis safety)
 * - mental_health (Stress, anxiety, burnout, emotional support)
 * - appointment (Booking, checking, rescheduling)
 * - bmi (Body mass index records & calculation)
 * - medical_record (Consultations, clinic history)
 * - health_monitoring (Vitals, BP, heart rate, temp)
 * - vaccination (Vaccine records, doses)
 * - medicine_availability (Clinic pharmacy inventory)
 * - medical_certificate (Requesting medical certs)
 * - announcements (Clinic news & updates)
 * - clinic_schedule (Hours, location, services)
 * - health_education (Conditions, self-care, wellness)
 * - qr_health_pass (QR check-in & queue)
 * - greeting (Greetings & hellos)
 * - general_conversation (General inquiry & fallback)
 */

class IntentDetector
{
    /**
     * Detect the primary intent of a user message.
     *
     * @param string $message The raw user message
     * @param array  $history Optional recent chat history
     * @return string Single intent name
     */
    public function detect(string $message, array $history = []): string
    {
        $lower = mb_strtolower(trim($message));

        // =========================================================
        // 1. EMERGENCY & CRISIS SAFETY — highest priority
        // =========================================================
        if (self::isEmergency($lower)) {
            return 'emergency';
        }

        // =========================================================
        // 2. MENTAL HEALTH & EMOTIONAL SUPPORT
        // =========================================================
        $mentalHealthKeywords = [
            'stressed', 'stress', 'stressful', 'thesis', 'capstone', 'exam',
            'school pressure', 'burnout', 'burned out', 'anxious', 'anxiety',
            'panic attack', 'overwhelmed', 'depressed', 'depression', 'sad',
            'sadness', 'lonely', 'loneliness', 'mental health', 'emotional',
            'counselor', 'counseling', 'guidance', 'trouble sleeping',
            'insomnia', 'can\'t sleep', 'cannot sleep', 'lack of sleep',
            'exhausted', 'pagod na pagod', 'nakaka-stress', 'sobrang stress',
            'nahihirapan', 'breathing exercise', 'grounding technique',
            'coping', 'pressure sa school', 'pressure', 'breakdown',
        ];
        if ($this->matchKeywords($lower, $mentalHealthKeywords)) {
            return 'mental_health';
        }

        // =========================================================
        // 3. BMI
        // =========================================================
        $bmiKeywords = [
            'my bmi', 'show bmi', 'bmi', 'body mass index', 'what is my bmi',
            'bmi history', 'bmi trend', 'bmi record', 'check bmi',
            'compute bmi', 'calculate bmi', 'bmi status', 'timbang', 'height', 'weight',
        ];
        if ($this->matchKeywords($lower, $bmiKeywords)) {
            return 'bmi';
        }

        // =========================================================
        // 4. MEDICAL RECORD
        // =========================================================
        $medicalRecordKeywords = [
            'medical record', 'my record', 'health record', 'show record',
            'view record', 'consultation record', 'my consultation',
            'clinic record', 'patient record', 'view my record',
            'medical history', 'my history', 'clinic history', 'past visit',
        ];
        if ($this->matchKeywords($lower, $medicalRecordKeywords)) {
            return 'medical_record';
        }

        // =========================================================
        // 5. APPOINTMENT
        // =========================================================
        $appointmentKeywords = [
            'appointment', 'book appointment', 'schedule', 'my appointment',
            'when is my appointment', 'set appointment', 'make appointment',
            'visit clinic', 'see the nurse', 'appointment date',
            'appointment time', 'appointment status', 'book a visit',
            'cancel appointment', 'reschedule', 'appointment booking',
            'mag-book', 'magbook', 'paano mag book', 'available date',
        ];
        if ($this->matchKeywords($lower, $appointmentKeywords)) {
            return 'appointment';
        }

        // =========================================================
        // 6. HEALTH MONITORING
        // =========================================================
        $healthMonitoringKeywords = [
            'health monitoring', 'vital signs', 'vitals', 'blood pressure',
            'heart rate', 'temperature', 'oxygen saturation', 'pulse rate',
            'my vitals', 'check vitals', 'record vitals', 'health check',
            'monitoring', 'bp', 'heartbeat', 'lagnat check',
        ];
        if ($this->matchKeywords($lower, $healthMonitoringKeywords)) {
            return 'health_monitoring';
        }

        // =========================================================
        // 7. VACCINATION
        // =========================================================
        $vaccinationKeywords = [
            'vaccination', 'vaccine', 'bakuna', 'vaccination record',
            'my vaccine', 'vaccine history', 'immunization', 'dose',
            'booster', 'vaccinated', 'vaccination schedule',
            'my vaccination', 'view vaccination', 'vaccine record',
            'flu vaccine', 'tetanus',
        ];
        if ($this->matchKeywords($lower, $vaccinationKeywords)) {
            return 'vaccination';
        }

        // =========================================================
        // 8. MEDICINE AVAILABILITY
        // =========================================================
        $medicineKeywords = [
            'medicine', 'medication', 'prescription', 'drugs', 'pills',
            'medicine available', 'medicine stock', 'pharmacy', 'gamot',
            'is there medicine', 'do you have medicine', 'meds',
            'medicine inventory', 'available medicine', 'paracetamol',
            'mefenamic', 'ibuprofen', 'amoxicillin', 'biogesic', 'antacid',
            'cetirizine', 'pain reliever', 'first aid', 'may gamot ba',
            'available ba na', 'may available ba',
        ];
        if ($this->matchKeywords($lower, $medicineKeywords)) {
            return 'medicine_availability';
        }

        // =========================================================
        // 9. MEDICAL CERTIFICATE
        // =========================================================
        $certificateKeywords = [
            'medical certificate', 'med cert', 'certificate', 'doctor note',
            'medical note', 'fit to return', 'fit to attend',
            'health certificate', 'get certificate', 'request certificate',
            'how to get medical certificate', 'medical certification',
            'excuse letter', 'clinic cert',
        ];
        if ($this->matchKeywords($lower, $certificateKeywords)) {
            return 'medical_certificate';
        }

        // =========================================================
        // 10. ANNOUNCEMENTS
        // =========================================================
        $announcementKeywords = [
            'announcement', 'announcements', 'latest announcement',
            'clinic news', 'latest news', 'what is new', 'any announcement',
            'clinic update', 'recent announcement', 'show announcement',
            'view announcement', 'latest announcements', 'bulletin',
            'balita sa clinic',
        ];
        if ($this->matchKeywords($lower, $announcementKeywords)) {
            return 'announcements';
        }

        // =========================================================
        // 11. CLINIC SCHEDULE & SERVICES
        // =========================================================
        $clinicScheduleKeywords = [
            'clinic hours', 'clinic schedule', 'open hours', 'operating hours',
            'when is clinic open', 'clinic time', 'clinic open',
            'what time does clinic open', 'what time does clinic close',
            'clinic location', 'where is clinic', 'clinic contact',
            'how to contact clinic', 'clinic phone', 'clinic number',
            'saan ang clinic', 'bukas ba clinic',
        ];
        if ($this->matchKeywords($lower, $clinicScheduleKeywords)) {
            return 'clinic_schedule';
        }

        // =========================================================
        // 12. HEALTH EDUCATION (disease info, symptoms, wellness tips)
        // =========================================================
        $healthEducationKeywords = [
            'what is', 'tell me about', 'symptoms of', 'causes of',
            'treatment for', 'how to prevent', 'dengue', 'flu', 'colds',
            'fever', 'lagnat', 'headache', 'migraine', 'cough', 'ubo', 'sipon',
            'diarrhea', 'allergy', 'asthma', 'diabetes', 'hypertension', 'cancer',
            'covid', 'virus', 'infection', 'wellness', 'vitamin',
            'how to stay healthy', 'healthy', 'nutrition', 'diet',
            'exercise', 'sleep tips', 'sakit ng ulo', 'sakit ng tiyan',
            'first aid', 'home remedy', 'natural remedy',
        ];
        if ($this->matchKeywords($lower, $healthEducationKeywords)) {
            return 'health_education';
        }

        // =========================================================
        // 13. QR HEALTH PASS
        // =========================================================
        $qrKeywords = [
            'qr code', 'qr scan', 'health pass', 'digital pass', 'smart pass',
            'my qr', 'scan qr', 'qr check-in', 'check in', 'queue number',
            'current queue', 'waiting number', 'queue status', 'qr timeline',
            'scan history', 'where is my queue', 'what is my queue',
            'my queue', 'queue', 'check-in', 'check in today',
        ];
        if ($this->matchKeywords($lower, $qrKeywords)) {
            return 'qr_health_pass';
        }

        // =========================================================
        // 14. GREETING
        // =========================================================
        $greetingKeywords = [
            'hello', 'hi', 'hey', 'good morning', 'good afternoon',
            'good evening', 'magandang umaga', 'magandang hapon',
            'magandang gabi', 'kamusta', 'musta', 'hello po',
            'hi po', 'greetings', 'good day', 'good day po', 'hi mica',
            'hello mica', 'hey mica',
        ];
        if ($this->matchKeywords($lower, $greetingKeywords) && mb_strlen($lower) < 25) {
            return 'greeting';
        }

        // =========================================================
        // 15. MULTI-TURN CONTINUITY CHECK
        // If message is short (e.g. "mostly my thesis", "tomorrow", "yes")
        // check previous intent from history
        // =========================================================
        if (!empty($history) && mb_strlen($lower) < 40) {
            $lastEntry = end($history);
            if (is_array($lastEntry) && !empty($lastEntry['intent'])) {
                $lastIntent = $lastEntry['intent'];
                if (in_array($lastIntent, ['mental_health', 'appointment', 'health_education', 'medicine_availability'], true)) {
                    return $lastIntent;
                }
            }
        }

        // =========================================================
        // 16. GENERAL CONVERSATION (default fallback)
        // =========================================================
        return 'general_conversation';
    }

    /**
     * Check if any of the keywords appear in the message.
     */
    private function matchKeywords(string $lower, array $keywords): bool
    {
        foreach ($keywords as $keyword) {
            if (str_contains($lower, $keyword)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Static emergency & high-risk check.
     */
    public static function isEmergency(string $message): bool
    {
        $lower = mb_strtolower(trim($message));
        $emergencyKeywords = [
            "can't breathe", "cannot breathe", "chest pain", "severe bleeding",
            "unconscious", "seizure", "heart attack", "stroke", "not breathing",
            "choking", "overdose", "poisoning", "deep cut", "major injury",
            "broken bone", "head injury", "difficulty breathing", "hard to breathe",
            "stop breathing",
            // High-risk self-harm / suicide crisis statements
            "i want to die", "want to die", "want to hurt myself", "hurt myself",
            "don't want to live", "dont want to live", "i don't want to live anymore",
            "can't go on", "cant go on", "everyone would be better without me",
            "better off without me", "better without me", "kill myself",
            "end my life", "suicide", "suicidal", "self-harm", "self harm",
            "gusto ko na mamatay", "ayoko na mabuhay", "magpakamatay",
            "kitlin ang buhay",
        ];
        foreach ($emergencyKeywords as $keyword) {
            if (str_contains($lower, $keyword)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Get all supported intents.
     *
     * @return array
     */
    public static function getSupportedIntents(): array
    {
        return [
            'emergency',
            'mental_health',
            'greeting',
            'medical_record',
            'appointment',
            'bmi',
            'health_monitoring',
            'vaccination',
            'medicine_availability',
            'medical_certificate',
            'announcements',
            'clinic_schedule',
            'health_education',
            'qr_health_pass',
            'general_conversation',
        ];
    }
}
