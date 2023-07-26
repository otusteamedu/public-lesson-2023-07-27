<?php

namespace App\Consumer\Stream\Output;

use JsonException;

class PartMessage
{
    public function __construct(
        private readonly string $text,
        private readonly ?string $sourceMessage,
    ) {
    }

    /**
     * @throws JsonException
     */
    public function toAMQPMessage(): string
    {
        return json_encode(['text' => $this->text, 'sourceMessage' => $this->sourceMessage], JSON_THROW_ON_ERROR);
    }
}
