<?php

namespace App\Consumer\Stream;

use App\Consumer\Stream\Input\Message;
use App\Entity\MessageLog;
use Doctrine\ORM\EntityManagerInterface;
use JsonException;
use OldSound\RabbitMqBundle\RabbitMq\ConsumerInterface;
use PhpAmqpLib\Message\AMQPMessage;

class Consumer implements ConsumerInterface
{
    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
    }

    public function execute(AMQPMessage $msg): int
    {
        try {
            $message = Message::createFromQueue($msg->getBody());
        } catch (JsonException $e) {
            return $this->reject($e->getMessage());
        }

        foreach ($message->getTexts() as $text) {
            sleep(1);
            echo $text."\n";
        }

        $messageLog = new MessageLog();
        $messageLog->setMessage($msg->getBody());
        $this->entityManager->persist($messageLog);
        $this->entityManager->flush();

        return self::MSG_ACK;
    }

    private function reject(string $error): int
    {
        echo "Incorrect message: $error";

        return self::MSG_REJECT;
    }
}
