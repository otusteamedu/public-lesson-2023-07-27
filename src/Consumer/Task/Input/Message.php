<?php

namespace App\Consumer\Task\Input;

use JsonException;

class Message
{
    private int $taskId;

    /**
     * @throws JsonException
     */
    public static function createFromQueue(string $messageBody): self
    {
        $message = json_decode($messageBody, true, 512, JSON_THROW_ON_ERROR);
        $result = new self();
        $result->taskId = $message['taskId'];

        return $result;
    }

    public function getTaskId(): int
    {
        return $this->taskId;
    }
}
