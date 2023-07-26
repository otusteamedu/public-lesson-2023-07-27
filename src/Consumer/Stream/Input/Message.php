<?php

namespace App\Consumer\Stream\Input;

use JsonException;

class Message
{
    /** @var string[] */
    private array $texts;

    /**
     * @throws JsonException
     */
    public static function createFromQueue(string $messageBody): self
    {
        $message = json_decode($messageBody, true, 512, JSON_THROW_ON_ERROR);
        $result = new self();
        $result->texts = $message['texts'];

        return $result;
    }

    public function getTexts(): array
    {
        return $this->texts;
    }
}
