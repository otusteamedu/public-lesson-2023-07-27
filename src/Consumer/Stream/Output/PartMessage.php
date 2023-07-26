<?php

namespace App\Consumer\Stream\Output;

use JsonException;

class PartMessage
{
    /**
     * @param string[] $texts
     */
    public function __construct(
        private readonly array $texts,
    ) {
    }

    /**
     * @throws JsonException
     */
    public function toAMQPMessage(): string
    {
        return json_encode(
            ['texts' => $this->texts, 'index' => 0, 'result' => ['texts' => []]],
            JSON_THROW_ON_ERROR
        );
    }
}
