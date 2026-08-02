<?php
/**
 * IntentDetector.php
 * 
 * Detects the user's intent from a text message.
 * Returns exactly ONE intent from the following list:
 * 
 * - greeting
 * - medical_record
 * - appointment
 * - bmi
 * - health_monitoring
 * - vaccination
 * - medicine_availability
 * - medical_certificate
 * - announcements
 * - clinic_schedule
 * - emergency
 * - health_education
 * - general_conversation
 * 
 * Uses priority-based keyword matching. Emergency has highest priority.
 * Each intent has explicit keyword arrays for accurate detection.
 */

class IntentDetector
{
    /**
     * Detect the primary intent of a user message.
     *
     * @param string $message The raw user message
     * @param array  $history Optional chat history (not used in simple detection)
     * @return string Single intent name
     */
    public function detect(string $message, array $history = []): string
    {
        $lower = mb_strtolower(trim($message));

        // =========================================================
        // 1. EMERGENCY — highest priority
        // =========================================================
        $emergencyKeywords = [
            "can't breathe", "cannot breathe", "chest pain", "severe bleeding",
            "unconscious", "suicide", "self-harm", "seizure", "heart attack",
            "stroke", "not breathing", "choking", "overdose", "poisoning",
            "deep cut", "major injury", "broken bone", "head injury",
            "difficulty breathing", "hard to breathe", "stop breathing",
        ];
        if ($this->matchKeywords($lower, $emergencyKeywords)) {
            return 'emergency';
        }

        // =========================================================
        // 2. BMI
        // =========================================================
        $bmiKeywords = [
            'my bmi', 'show bmi', 'bmi', 'body mass index', 'what is my bmi',
            'bmi history', 'bmi trend', 'bmi record', 'check bmi',
            'compute bmi', 'calculate bmi', 'bmi status',
        ];
        if ($this->matchKeywords($lower, $bmiKeywords)) {
            return 'bmi';
        }

        // =========================================================
        // 3. MEDICAL RECORD
        // =========================================================
        $medicalRecordKeywords = [
            'medical record', 'my record', 'health record', 'show record',
            'view record', 'consultation record', 'my consultation',
            'clinic record', 'patient record', 'view my record',
            'medical history', 'my history', 'clinic history',
        ];
        if ($this->matchKeywords($lower, $medicalRecordKeywords)) {
            return 'medical_record';
        }

        // =========================================================
        // 4. APPOINTMENT
        // =========================================================
        $appointmentKeywords = [
            'appointment', 'book appointment', 'schedule', 'my appointment',
            'when is my appointment', 'set appointment', 'make appointment',
            'visit clinic', 'see the nurse', 'appointment date',
            'appointment time', 'appointment status', 'book a visit',
            'cancel appointment', 'reschedule', 'appointment booking',
        ];
        if ($this->matchKeywords($lower, $appointmentKeywords)) {
            return 'appointment';
        }

        // =========================================================
        // 5. HEALTH MONITORING
        // =========================================================
        $healthMonitoringKeywords = [
            'health monitoring', 'vital signs', 'vitals', 'blood pressure',
            'heart rate', 'temperature', 'oxygen saturation', 'pulse rate',
            'my vitals', 'check vitals', 'record vitals', 'health check',
            'monitoring', 'bp', 'heartbeat',
        ];
        if ($this->matchKeywords($lower, $healthMonitoringKeywords)) {
            return 'health_monitoring';
        }

        // =========================================================
        // 6. VACCINATION
        // =========================================================
        $vaccinationKeywords = [
            'vaccination', 'vaccine', 'bakuna', 'vaccination record',
            'my vaccine', 'vaccine history', 'immunization', 'dose',
            'booster', 'vaccinated', 'vaccination schedule',
            'my vaccination', 'view vaccination', 'vaccine record',
        ];
        if ($this->matchKeywords($lower, $vaccinationKeywords)) {
            return 'vaccination';
        }

        // =========================================================
        // 7. MEDICINE AVAILABILITY
        // =========================================================
        $medicineKeywords = [
            'medicine', 'medication', 'prescription', 'drugs', 'pills',
            'medicine available', 'medicine stock', 'pharmacy',
            'is there medicine', 'do you have medicine', 'meds',
            'medicine inventory', 'available medicine', 'paracetamol',
            'antibiotic', 'pain reliever', 'first aid',
        ];
        if ($this->matchKeywords($lower, $medicineKeywords)) {
            return 'medicine_availability';
        }

        // =========================================================
        // 8. MEDICAL CERTIFICATE
        // =========================================================
        $certificateKeywords = [
            'medical certificate', 'med cert', 'certificate', 'doctor note',
            'medical note', 'fit to return', 'fit to attend',
            'health certificate', 'get certificate', 'request certificate',
            'how to get medical certificate', 'medical certification',
        ];
        if ($this->matchKeywords($lower, $certificateKeywords)) {
            return 'medical_certificate';
        }

        // =========================================================
        // 9. ANNOUNCEMENTS
        // =========================================================
        $announcementKeywords = [
            'announcement', 'announcements', 'latest announcement',
            'clinic news', 'latest news', 'what is new', 'any announcement',
            'clinic update', 'recent announcement', 'show announcement',
            'view announcement', 'latest announcements', 'bulletin',
        ];
        if ($this->matchKeywords($lower, $announcementKeywords)) {
            return 'announcements';
        }

        // =========================================================
        // 10. CLINIC SCHEDULE
        // =========================================================
        $clinicScheduleKeywords = [
            'clinic hours', 'clinic schedule', 'open hours', 'operating hours',
            'when is clinic open', 'clinic time', 'clinic open',
            'what time does clinic open', 'what time does clinic close',
            'clinic location', 'where is clinic', 'clinic contact',
            'how to contact clinic', 'clinic phone', 'clinic number',
        ];
        if ($this->matchKeywords($lower, $clinicScheduleKeywords)) {
            return 'clinic_schedule';
        }

        // =========================================================
        // 11. HEALTH EDUCATION (disease info, symptoms, wellness tips)
        // =========================================================
        $healthEducationKeywords = [
            'what is', 'tell me about', 'symptoms of', 'causes of',
            'treatment for', 'how to prevent', 'dengue', 'flu', 'colds',
            'fever', 'lagnat', 'headache', 'migraine', 'cough', 'diarrhea',
            'allergy', 'asthma', 'diabetes', 'hypertension', 'cancer',
            'covid', 'virus', 'infection', 'wellness', 'vitamin',
            'how to stay healthy', 'healthy', 'nutrition', 'diet',
            'exercise', 'sleep tips', 'stress management',
            'first aid', 'home remedy', 'natural remedy',
        ];
        if ($this->matchKeywords($lower, $healthEducationKeywords)) {
            return 'health_education';
        }

        // =========================================================
        // 12. GREETING
        // =========================================================
        $greetingKeywords = [
            'hello', 'hi', 'hey', 'good morning', 'good afternoon',
            'good evening', 'magandang umaga', 'magandang hapon',
            'magandang gabi', 'kamusta', 'musta', 'hello po',
            'hi po', 'greetings', 'good day', 'good day po',
        ];
        if ($this->matchKeywords($lower, $greetingKeywords)) {
            return 'greeting';
        }

        // =========================================================
        // 13. GENERAL CONVERSATION (default fallback)
        // =========================================================
        return 'general_conversation';
    }

    /**
     * Check if any of the keywords appear in the message.
     *
     * @param string $lower    Lowercased user message
     * @param array  $keywords Array of keywords to match
     * @return bool
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
     * Static emergency check — fast path without instantiating the class.
     *
     * @param string $message
     * @return bool
     */
    public static function isEmergency(string $message): bool
    {
        $lower = mb_strtolower(trim($message));
        $emergencyKeywords = [
            "can't breathe", "cannot breathe", "chest pain", "severe bleeding",
            "unconscious", "suicide", "self-harm", "seizure", "heart attack",
            "stroke", "not breathing", "choking", "overdose", "poisoning",
            "deep cut", "major injury", "broken bone", "head injury",
            "difficulty breathing", "hard to breathe", "stop breathing",
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
            'emergency',
            'health_education',
            'general_conversation',
        ];
    }
}

