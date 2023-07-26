<?php

namespace App\Consumer\Part\Input;

use JsonException;

class Message
{
    /**
     * @param string[] $texts
     */
    public function __construct(
        private readonly array $texts,
        private readonly int $index,
        private readonly array $result,
    ) {
    }


    /**
     * @throws JsonException
     */
    public static function createFromQueue(string $messageBody): self
    {
        $message = json_decode($messageBody, true, 512, JSON_THROW_ON_ERROR);

        return new self($message['texts'], $message['index'], $message['result']);
    }

    /**
     * @throws JsonException
     */
    public function toAMQPMessage(): string
    {
        return json_encode(
            ['texts' => $this->texts, 'index' => $this->index, 'result' => $this->result],
            JSON_THROW_ON_ERROR,
        );
    }

    public function getTexts(): array
    {
        return $this->texts;
    }

    public function getIndex(): int
    {
        return $this->index;
    }

    public function getResult(): array
    {
        return $this->result;
    }
}
