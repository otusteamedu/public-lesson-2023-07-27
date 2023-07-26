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

    public function execute(AMQPMessage $msg): string
    {
        try {
            $message = Message::createFromQueue($msg->getBody());
        } catch (JsonException $e) {
            return $this->reject($e->getMessage());
        }

        sleep(2);

        /** @var Task|null $task */
        $task = $this->entityManager->getRepository(Task::class)->find($message->getTaskId());
        if ($task !== null) {
            $task->setResult((string)random_int(0, PHP_INT_MAX));
            $task->setCompletedAt();
            $this->entityManager->flush();
        }

        return json_encode(
            [
                'result' => $task->getResult(),
                'process_time' => $task->getCompletedAt()?->diff($task->getCreatedAt())->s,
            ],
            JSON_THROW_ON_ERROR,
        );
    }

    private function reject(string $error): int
    {
        echo "Incorrect message: $error";

        return self::MSG_REJECT;
    }
}
