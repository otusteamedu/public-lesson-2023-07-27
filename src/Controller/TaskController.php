<?php

namespace App\Controller;

use App\Entity\Task;
use App\Service\TaskService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

class TaskController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly TaskService $taskService,
    ) {
    }

    public function addTask(): Response
    {
        $task = new Task();
        $this->entityManager->persist($task);
        $this->entityManager->flush();

        return new JsonResponse($this->taskService->call($task), Response::HTTP_OK, [], true);
    }
}
