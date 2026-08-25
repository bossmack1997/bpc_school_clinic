<?php
/**
 * Test Suite: MICA AI Appointment Responses
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/ai/AIService.php';

echo "========================================================\n";
echo "MICA AI APPOINTMENT WORKFLOW TEST SUITE\n";
echo "========================================================\n\n";

$db = getDB();

$stmt = $db->query("
    SELECT u.id, u.first_name, u.last_name, u.role, s.student_number, s.course, s.year_level, s.section
    FROM users u
    JOIN students s ON s.user_id = u.id
    LIMIT 1
");
$studentUser = $stmt->fetch();

$aiConfig = [
    'api_key'     => defined('AI_OPENROUTER_API_KEY') ? AI_OPENROUTER_API_KEY : '',
    'endpoint'    => defined('AI_OPENROUTER_ENDPOINT') ? AI_OPENROUTER_ENDPOINT : 'https://openrouter.ai/api/v1/chat/completions',
    'model'       => defined('AI_OPENROUTER_MODEL') ? AI_OPENROUTER_MODEL : 'openai/gpt-4o-mini',
    'timeout'     => 30,
];

$aiService = new AIService($db, $studentUser, $aiConfig);

$questions = [
    "Question 1: How do I book an appointment?" => "How do I book an appointment?",
    "Question 2: I want to make an appointment." => "I want to make an appointment.",
    "Question 3: Where can I see my appointment?" => "Where can I see my appointment?",
    "Question 4: Can I cancel my appointment?" => "Can I cancel my appointment?",
    "Question 5: Is my appointment approved?" => "Is my appointment approved?",
    "Question 6: What time is my appointment?" => "What time is my appointment?",
];

foreach ($questions as $title => $query) {
    echo "--- {$title} ---\n";
    $res = $aiService->processMessage($query);
    echo "Query: {$query}\n";
    echo "Intent: {$res['intent']} (Source: {$res['source']})\n";
    echo "MICA Response:\n{$res['reply']}\n";
    
    // Validate that MICA does not invent physical clinic paper form arrival
    $hasHallucination = stripos($res['reply'], 'fill out an appointment form upon') !== false
                     || stripos($res['reply'], 'arrive at the clinic to fill') !== false
                     || stripos($res['reply'], 'physical form at the clinic') !== false;
                     
    if (!$hasHallucination) {
        echo "✅ Validation Passed: No generic paper form hallucinations.\n\n";
    } else {
        echo "⚠️ Validation Failed: Generic paper form detected.\n\n";
    }
}

echo "========================================================\n";
echo "ALL APPOINTMENT TESTS COMPLETED!\n";
echo "========================================================\n";

