<?php

declare(strict_types=1);

namespace App\Controller;

use App\DTO\Task\TaskView;
use App\Entity\User;
use App\Http\TaskRequestMapper;
use App\Service\Task\CreateTask;
use App\Service\Task\DeleteTask;
use App\Service\Task\FindOwnedTask;
use App\Service\Task\TaskListQuery;
use App\Service\Task\UpdateTask;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

#[AsController]
final class TaskController
{
    #[Route('/api/tasks', name: 'get_tasks', methods: ['GET'])]
    public function list(#[CurrentUser] User $user, Request $request, TaskRequestMapper $input, TaskListQuery $list): JsonResponse
    {
        return new JsonResponse($list->execute($user, $input->pagination($request)));
    }

    #[Route('/api/tasks', name: 'create_task', methods: ['POST'])]
    public function create(#[CurrentUser] User $user, Request $request, TaskRequestMapper $input, CreateTask $create): JsonResponse
    {
        $task = $create->execute($user, $input->payload($request));

        return new JsonResponse($task, 201, ['Location' => '/api/tasks/'.$task->id]);
    }

    #[Route('/api/tasks/{id}', name: 'get_task', methods: ['GET'])]
    public function get(string $id, #[CurrentUser] User $user, FindOwnedTask $find): JsonResponse
    {
        return new JsonResponse(TaskView::fromTask($find->execute($user, $id)));
    }

    #[Route('/api/tasks/{id}', name: 'update_task', methods: ['PUT'])]
    public function update(string $id, #[CurrentUser] User $user, Request $request, FindOwnedTask $find, TaskRequestMapper $input, UpdateTask $update): JsonResponse
    {
        // Look up ownership before inspecting the body: foreign and missing IDs are opaque.
        $task = $find->execute($user, $id);

        return new JsonResponse($update->execute($task, $input->payload($request, replacement: true)));
    }

    #[Route('/api/tasks/{id}', name: 'delete_task', methods: ['DELETE'])]
    public function delete(string $id, #[CurrentUser] User $user, FindOwnedTask $find, DeleteTask $delete): Response
    {
        $delete->execute($find->execute($user, $id));

        return new Response(status: 204);
    }
}
