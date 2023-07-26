<?php

namespace App\Consumer\Part\Input;

use JsonException;

class Message
{
    private string $text;

    private ?string $sourceMessage;

    /**
     * @throws JsonException
     */
    public static function createFromQueue(string $messageBody): self
    {
        $message = json_decode($messageBody, true, 512, JSON_THROW_ON_ERROR);
        $result = new self();
        $result->text = $message['text'];
        $result->sourceMessage = $message['sourceMessage'];

        return $result;
    }

    public function getText(): string
    {
        return $this->text;
    }

    public function getSourceMessage(): ?string
    {
        return $this->sourceMessage;
    }
}
