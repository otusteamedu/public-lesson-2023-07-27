<?php

namespace App\Consumer\Stream;

use App\Consumer\Stream\Input\Message;
use App\Consumer\Stream\Output\PartMessage;
use JsonException;
use OldSound\RabbitMqBundle\RabbitMq\ConsumerInterface;
use OldSound\RabbitMqBundle\RabbitMq\ProducerInterface;
use PhpAmqpLib\Message\AMQPMessage;

class Consumer implements ConsumerInterface
{
    public function __construct(
        private readonly ProducerInterface $producer,
    )
    {
    }

    public function execute(AMQPMessage $msg): int
    {
        try {
            $message = Message::createFromQueue($msg->getBody());
        } catch (JsonException $e) {
            return $this->reject($e->getMessage());
        }

        foreach ($message->getTexts() as $index => $text) {
            $partMessage = new PartMessage(
                $text,
                $index + 1 === count($message->getTexts()) ? $msg->getBody() : null
            );
            try {
                $this->producer->publish($partMessage->toAMQPMessage());
            } catch (JsonException $e) {
                return $this->reject($e->getMessage());
            }
        }

        return self::MSG_ACK;
    }

    private function reject(string $error): int
    {
        echo "Incorrect message: $error";

        return self::MSG_REJECT;
    }
}
