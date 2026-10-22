<?php
/**
 * AIService.php
 * ------------------------------------------------------------------
 * AI-Enhanced School Clinic Management System for Bulacan Polytechnic College
 * Healthcare Service Improvement
 *
 * Main orchestrator for the MICA AI Health & Clinic Intelligence System.
 *
 * Workflow:
 * 1. Receive user message
 * 2. Check for emergency or mental health crisis safety
 * 3. Retrieve multi-turn conversation history
 * 4. Check for grounded natural language database queries via MICAWorkflowService
 * 5. If not an exact structured query, build structured system prompt via PromptBuilder & MICAContextService
 * 6. Call OpenRouter API with multi-turn context
 * 7. Store exchange in ChatHistory
 * 8. Return clean response with action metadata
 * ------------------------------------------------------------------
 */

require_once __DIR__ . '/IntentDetector.php';
require_once __DIR__ . '/MICAContextService.php';
require_once __DIR__ . '/MICAActionService.php';
require_once __DIR__ . '/MICAAutomationEngine.php';
require_once __DIR__ . '/MICAWorkflowService.php';
require_once __DIR__ . '/PromptBuilder.php';
require_once __DIR__ . '/OpenRouter.php';
require_once __DIR__ . '/ChatHistory.php';
require_once __DIR__ . '/NearbyHospitalService.php';

class AIService
{
    private IntentDetector $intentDetector;
    private MICAContextService $contextService;
    private MICAActionService $actionService;
    private MICAAutomationEngine $automationEngine;
    private MICAWorkflowService $workflowService;
    private PromptBuilder $promptBuilder;
    private OpenRouter $openRouter;
    private ChatHistory $chatHistory;
    private PDO $db;
    private array $user;
    private string $page = '';
    private array $locationData = [
        'latitude'  => null,
        'longitude' => null,
        'accuracy'  => null,
        'timestamp' => null,
        'status'    => 'unrequested',
    ];

    public function __construct(PDO $db, array $user, array $config = [])
    {
        $this->db = $db;
        $this->user = $user;
        $this->page = $config['page'] ?? '';
        if (isset($config['location']) && is_array($config['location'])) {
            $this->setLocationData($config['location']);
        }
        $this->intentDetector   = new IntentDetector();
        $this->contextService   = new MICAContextService($db, $user, $this->page);
        $this->actionService    = new MICAActionService($db, $user);
        $this->automationEngine = new MICAAutomationEngine($db);
        $this->workflowService  = new MICAWorkflowService($db, $user);
        $this->promptBuilder    = new PromptBuilder();
        $this->openRouter       = new OpenRouter($config);
        $this->chatHistory      = new ChatHistory();
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

    public function getContextService(): MICAContextService
    {
        return $this->contextService;
    }

    public function getActionService(): MICAActionService
    {
        return $this->actionService;
    }

    public function getAutomationEngine(): MICAAutomationEngine
    {
        return $this->automationEngine;
    }

    public function getWorkflowService(): MICAWorkflowService
    {
        return $this->workflowService;
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
     * @return array{success: bool, reply: string, intent: string, model: ?string, source: string, actions: ?array}
     */
    public function processMessage(string $message): array
    {
        $message = trim($message);
        if ($message === '') {
            return [
                'success' => false,
                'reply'   => 'Please type a message so I can assist you with clinic intelligence and health guidance.',
                'intent'  => 'empty',
                'model'   => null,
                'source'  => 'validation',
                'actions' => []
            ];
        }

        // 1. Detect Intent
        $intent = $this->intentDetector->detect($message);

        // 2. Direct Grounded Natural Language Database Query Check
        $groundedRes = $this->workflowService->handleNaturalLanguageQuery($message);
        if ($groundedRes && !empty($groundedRes['handled'])) {
            $this->chatHistory->addMessage('user', $message);
            $this->chatHistory->addMessage('assistant', $groundedRes['reply']);
            return [
                'success' => true,
                'reply'   => $groundedRes['reply'],
                'intent'  => $intent,
                'model'   => 'mica-database-grounding',
                'source'  => 'database_grounded',
                'actions' => $this->actionService->getRecommendedActions(null, 3)
            ];
        }

        // 3. Location / Nearby Facilities Handling
        $locationContext = '';
        if ($intent === 'nearby_facilities' || $this->locationData['status'] === 'granted') {
            if ($this->locationData['latitude'] !== null && $this->locationData['longitude'] !== null) {
                $locationContext = NearbyHospitalService::formatLocationContext(
                    $this->locationData['latitude'],
                    $this->locationData['longitude'],
                    $this->locationData['accuracy']
                );
            }
        }

        // 4. Build Live System & Database Context
        $systemContext = $this->contextService->getSystemContextString((bool)preg_match('/(my|own|aking|ko).*(record|consultation|visit|health|bmi)|(record|consultation|bmi).*(ko|akin)/i',$message));
        $recentHistory = $this->chatHistory->getMessagesForPrompt(10);

        // 5. Build Structured Multi-Turn Prompt
        $promptData = $this->promptBuilder->build(
            $intent,
            $message,
            $systemContext,
            $recentHistory,
            $this->user,
            $this->page,
            $locationContext
        );

        // 6. Call OpenRouter AI
        $aiResult = $this->openRouter->complete($promptData['system'], $promptData['messages']);

        if ($aiResult['success'] && !empty($aiResult['reply'])) {
            $reply = trim($aiResult['reply']);
            $this->chatHistory->addMessage('user', $message);
            $this->chatHistory->addMessage('assistant', $reply);

            return [
                'success' => true,
                'reply'   => $reply,
                'intent'  => $intent,
                'model'   => $aiResult['model'] ?? 'gpt-4o-mini',
                'source'  => 'openrouter',
                'actions' => $this->actionService->getRecommendedActions(null, 3)
            ];
        }

        // 7. Fallback Response (Clean Factual Response if API is offline)
        $fallbackReply = 'The AI provider is temporarily unavailable. Please retry, or use the clinic pages for verified records. For emergencies, use the SOS button directly.';
        $this->chatHistory->addMessage('user', $message);
        $this->chatHistory->addMessage('assistant', $fallbackReply);

        return [
            'success' => true,
            'reply'   => $fallbackReply,
            'intent'  => $intent,
            'model'   => 'rule_based_fallback',
            'source'  => 'fallback',
            'actions' => $this->actionService->getRecommendedActions(null, 3)
        ];
    }

    /**
     * Fallback factual response generator
     */
    private function generateRuleBasedFallback(string $message, string $intent, string $context): string
    {
        $role = $this->user['role'] ?? 'student';
        $firstName = $this->user['first_name'] ?? 'there';

        if ($role === 'student') {
            return "Hello {$firstName}! I am **MICA**, your BPC School Clinic Assistant.\n\n"
                 . "**Clinic Operating Hours:** Monday - Friday, 8:00 AM - 5:00 PM\n"
                 . "**Services Available:** Walk-in Triage, Consultation Bookings, Vital Signs / Health Pass Check-in, Medicine Dispensing, and Medical Certificates.\n\n"
                 . "*How can I assist you with your clinic needs today?*";
        }

        return "Good day! I am **MICA**, the BPC Clinic Intelligence Assistant.\n\n"
             . "**Clinic Status Overview:**\n"
             . "- Run an intelligence scan to verify the latest monitoring state.\n"
             . "- You can ask natural language questions regarding **low-stock medicines**, **expiring supplies**, **today's appointments**, or **clinic statistics**.\n\n"
             . "*Please let me know which operational records or recommendations you wish to inspect.*";
    }
}