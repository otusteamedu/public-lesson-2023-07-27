<?php

namespace App\Consumer\Part;

use App\Consumer\Part\Input\Message;
use App\Entity\MessageLog;
use Doctrine\ORM\EntityManagerInterface;
use JsonException;
use OldSound\RabbitMqBundle\RabbitMq\ConsumerInterface;
use OldSound\RabbitMqBundle\RabbitMq\ProducerInterface;
use PhpAmqpLib\Message\AMQPMessage;

class Consumer implements ConsumerInterface
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ProducerInterface $producer,
    ) {
    }

    public function execute(AMQPMessage $msg): int
    {
        try {
            $message = Message::createFromQueue($msg->getBody());
        } catch (JsonException $e) {
            return $this->reject($e->getMessage());
        }

        sleep(random_int(1, 3));
        $text = $message->getTexts()[$message->getIndex()];
        echo $text."\n";

        $messageLog = new MessageLog();
        $messageLog->setMessage($msg->getBody());
        $this->entityManager->persist($messageLog);

        $result = $message->getResult();
        $result['texts'][] = $text;
        $newIndex = $message->getIndex() + 1;

        try {
            if ($newIndex === count($message->getTexts())) {
                $messageLog = new MessageLog();
                $messageLog->setMessage(json_encode($result, JSON_THROW_ON_ERROR));
                $this->entityManager->persist($messageLog);
            } else {
                $this->producer->publish(
                    (new Message($message->getTexts(), $newIndex, $result))->toAMQPMessage(),
                );
            }
        } catch (JsonException $e) {
            return $this->reject($e->getMessage());
        }
        $this->entityManager->flush();

        return self::MSG_ACK;
    }

    private function reject(string $error): int
    {
        echo "Incorrect message: $error";

        return self::MSG_REJECT;
    }
}
