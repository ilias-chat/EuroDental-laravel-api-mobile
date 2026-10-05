<?php

namespace Tests\Feature;

use App\Http\Controllers\API\TaskController;
use Illuminate\Http\Request;
use Tests\TestCase;

class TaskUpdateRouteTest extends TestCase
{
    public function test_put_task_route_resolves_to_update_with_authentication(): void
    {
        $route = app('router')->getRoutes()->match(Request::create('/api/tasks/4996', 'PUT'));

        self::assertSame(TaskController::class.'@update', $route->getActionName());
        self::assertSame('4996', $route->parameter('id'));
        self::assertContains('auth:sanctum', $route->gatherMiddleware());
    }

    public function test_task_update_requires_authentication(): void
    {
        $this->putJson('/api/tasks/4996', [])->assertUnauthorized();
    }
}
