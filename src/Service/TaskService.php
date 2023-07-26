<?php

namespace App\Service;

use App\Entity\Task;
use Exception;
use OldSound\RabbitMqBundle\RabbitMq\RpcClient;
use PhpAmqpLib\Exception\AMQPTimeoutException;

class TaskService
{
    private readonly string $correlationId;

    public function __construct(
        private readonly RpcClient $rpcClient,
        private readonly string $rpcServer,
    ) {
        $this->correlationId = 'task_'.crc32(microtime());
    }

    /**
     * @throws Exception
     */
    public function call(Task $task): string
    {
        $this->rpcClient->addRequest(
            json_encode(['taskId' => $task->getId()], JSON_THROW_ON_ERROR),
            $this->rpcServer,
            $this->correlationId
        );

        try {
            $reply = $this->rpcClient->getReplies();
        } catch (AMQPTimeoutException $e) {
            throw new Exception($e->getMessage());
        }

        if (!isset($reply[$this->correlationId])) {
            throw new Exception(
                "RPC call response does not contain correlation id {$this->correlationId}"
            );
        }

        return $reply[$this->correlationId];
    }
}
