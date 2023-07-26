<?php

namespace App\Controller;

use App\Entity\Task;
use Doctrine\ORM\EntityManagerInterface;
use OldSound\RabbitMqBundle\RabbitMq\ProducerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

class TaskController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ProducerInterface $producer,
    ) {
    }

    public function addTask(): Response
    {
        $task = new Task();
        $this->entityManager->persist($task);
        $this->entityManager->flush();

        $this->producer->publish(json_encode(['taskId' => $task->getId()], JSON_THROW_ON_ERROR));

        return new JsonResponse('Task accepted', Response::HTTP_ACCEPTED);
    }
}
