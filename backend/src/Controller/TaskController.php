<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use App\Entity\Task;
use App\Entity\User;

class TaskController extends AbstractController
{
    #[Route('/api/tasks', 'get_tasks', ['GET'])]
    public function getTasks(): JsonResponse
    {
        $user = $this->getUser();
        $tasks = $this->getDoctrine()->getRepository(Task::class)->findBy(['user' => $user]);

        return new JsonResponse($tasks);
    }

    #[Route('/api/tasks', 'create_task', ['POST'])]
    public function createTask(Request $request): JsonResponse
    {
        $user = $this->getUser();
        $data = json_decode($request->getContent(), true);

        $task = new Task();
        $task->setUser($user);
        $task->setTitle($data['title']);
        $task->setDescription($data['description']);

        $em = $this->getDoctrine()->getManager();
        $em->persist($task);
        $em->flush();

        return new JsonResponse($task, 201);
    }

    #[Route('/api/tasks/{id}', 'update_task', ['PUT'])]
    public function updateTask($id, Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        $task = $this->getDoctrine()->getRepository(Task::class)->find($id);

        if (!$task || $task->getUser() !== $this->getUser()) {
            return new JsonResponse(['error' => 'Task not found or not authorized'], 404);
        }

        $task->setTitle($data['title']);
        $task->setDescription($data['description']);

        $em = $this->getDoctrine()->getManager();
        $em->flush();

        return new JsonResponse($task);
    }

    #[Route('/api/tasks/{id}', 'delete_task', ['DELETE'])]
    public function deleteTask($id): JsonResponse
    {
        $task = $this->getDoctrine()->getRepository(Task::class)->find($id);

        if (!$task || $task->getUser() !== $this->getUser()) {
            return new JsonResponse(['error' => 'Task not found or not authorized'], 404);
        }

        $em = $this->getDoctrine()->getManager();
        $em->remove($task);
        $em->flush();

        return new JsonResponse(null, 204);
    }
}
