<?php

namespace App\Consumer\Task;

use App\Consumer\Task\Input\Message;
use App\Entity\Task;
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

        /** @var Task|null $task */
        $task = $this->entityManager->getRepository(Task::class)->find($message->getTaskId());
        if ($task !== null) {
            $task->setResult((string)random_int(0, PHP_INT_MAX));
            $task->setCompletedAt();
            $this->entityManager->flush();
        }

        return self::MSG_ACK;
    }

    private function reject(string $error): int
    {
        echo "Incorrect message: $error";

        return self::MSG_REJECT;
    }
}
