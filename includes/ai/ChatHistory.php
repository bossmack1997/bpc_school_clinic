<?php
/**
 * ChatHistory.php
 * Manages chat conversation history stored in the session.
 * Provides methods for adding, retrieving, and maintaining history size.
 */

class ChatHistory
{
    private const MAX_HISTORY = 30;
    private const SESSION_KEY = 'ai_chat_history_v2';

    private function scope(): string
    {
        $user = function_exists('current_user') ? current_user() : ($_SESSION['user'] ?? null);
        if (!$user || empty($user['id'])) throw new RuntimeException('Authenticated MICA history required.');
        $profile = function_exists('permission_profile') ? permission_profile($user) : ($user['role'] ?? 'anonymous');
        $scope = implode(':', [(int)$user['id'], $profile, (int)($_SESSION['credential_version'] ?? 0), (int)($user['self_service_only'] ?? 0)]);
        // Old unscoped messages cannot be safely attributed to a current profile.
        if (($_SESSION['mica_history_scope'] ?? null) !== $scope) {
            unset($_SESSION[self::SESSION_KEY]);
            $_SESSION['mica_history_scope'] = $scope;
        }
        return $scope;
    }

    /**
     * Get the full chat history from session.
     *
     * @return array Array of ['role' => 'user'|'assistant', 'content' => string, 'intent' => ?string]
     */
    public function getHistory(): array
    {
        $this->scope();
        return $_SESSION[self::SESSION_KEY] ?? [];
    }

    /**
     * Add a message to the chat history.
     *
     * @param string $role    'user' or 'assistant'
     * @param string $content The message content
     * @param string|null $intent Detected intent (for analytics)
     */
    public function addMessage(string $role, string $content, ?string $intent = null): void
    {
        $history = $this->getHistory();
        $entry = [
            'role' => $role,
            'content' => $content,
            'timestamp' => time(),
        ];
        if ($intent) {
            $entry['intent'] = $intent;
        }

        $history[] = $entry;

        // Trim to max size
        if (count($history) > self::MAX_HISTORY) {
            $history = array_slice($history, -self::MAX_HISTORY);
        }

        $_SESSION[self::SESSION_KEY] = $history;
    }

    /**
     * Get the recent conversation for context (last N exchanges).
     *
     * @param int $count Number of recent exchanges to retrieve
     * @return array
     */
    public function getRecent(int $count = 6): array
    {
        $history = $this->getHistory();
        return array_slice($history, -$count);
    }

    /**
     * Get messages alias
     */
    public function getMessages(): array
    {
        return $this->getHistory();
    }

    /**
     * Get messages formatted for prompt injection
     */
    public function getMessagesForPrompt(int $count = 10): array
    {
        return array_values(array_filter($this->getRecent($count), fn($entry)=>($entry['intent']??'')!=='local_only'));
    }

    /**
     * Clear the chat history.
     */
    public function clear(): void
    {
        $this->scope();
        $_SESSION[self::SESSION_KEY] = [];
    }

    /**
     * Get the last user message.
     *
     * @return string|null
     */
    public function getLastUserMessage(): ?string
    {
        $history = $this->getHistory();
        for ($i = count($history) - 1; $i >= 0; $i--) {
            if ($history[$i]['role'] === 'user') {
                return $history[$i]['content'];
            }
        }
        return null;
    }

    /**
     * Get the last AI response.
     *
     * @return string|null
     */
    public function getLastAssistantMessage(): ?string
    {
        $history = $this->getHistory();
        for ($i = count($history) - 1; $i >= 0; $i--) {
            if ($history[$i]['role'] === 'assistant') {
                return $history[$i]['content'];
            }
        }
        return null;
    }

    /**
     * Get conversation statistics.
     *
     * @return array{total_messages: int, user_messages: int, assistant_messages: int, last_activity: ?int}
     */
    public function getStats(): array
    {
        $history = $this->getHistory();
        $userCount = 0;
        $assistantCount = 0;
        $lastTimestamp = null;

        foreach ($history as $entry) {
            if ($entry['role'] === 'user') $userCount++;
            if ($entry['role'] === 'assistant') $assistantCount++;
            if (isset($entry['timestamp'])) {
                $lastTimestamp = max($lastTimestamp ?? 0, $entry['timestamp']);
            }
        }

        return [
            'total_messages' => count($history),
            'user_messages' => $userCount,
            'assistant_messages' => $assistantCount,
            'last_activity' => $lastTimestamp,
        ];
    }

    /**
     * Export history as a simple array for API context.
     *
     * @param int $maxMessages Max messages to include
     * @return array
     */
    public function exportForApi(int $maxMessages = 10): array
    {
        $history = $this->getHistory();
        $recent = array_slice($history, -$maxMessages);

        $export = [];
        foreach ($recent as $entry) {
            $export[] = [
                'role' => $entry['role'],
                'content' => $entry['content'],
            ];
        }

        return $export;
    }
}
