<?php

namespace Tests\Feature;

use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TaskBoardTest extends TestCase
{
    use RefreshDatabase;

    public function test_create_task_with_responsibles(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();

        $this->actingAs($user)->post('/tarefas', [
            'title' => 'Protocolar recurso',
            'status' => 'pendente',
            'priority' => 'alta',
            'points' => 20,
            'due_date' => now()->addDay()->format('Y-m-d'),
            'due_time' => '17:00',
            'is_deadline' => '1',
            'users' => [$user->id, $other->id],
        ])->assertRedirect();

        $task = Task::firstOrFail();
        $this->assertTrue($task->is_deadline);
        $this->assertSame('17:00', $task->due_at->format('H:i'));
        $this->assertCount(2, $task->users);
    }

    public function test_moving_task_between_columns(): void
    {
        $user = User::factory()->create();
        $task = Task::factory()->create(['due_at' => now()->addDays(20), 'points' => 15]);
        $task->users()->attach($user);
        $this->actingAs($user);

        $this->patchJson("/tarefas/{$task->id}/mover", ['column' => 'hoje'])->assertOk();
        $this->assertTrue($task->fresh()->due_at->isToday());

        $this->patchJson("/tarefas/{$task->id}/mover", ['column' => 'fazendo'])->assertOk();
        $this->assertSame('fazendo', $task->fresh()->status);

        $this->patchJson("/tarefas/{$task->id}/mover", ['column' => 'concluidas'])->assertOk();
        $this->assertSame('concluida', $task->fresh()->status);
        $this->assertNotNull($task->fresh()->completed_at);

        $this->get('/tarefas')->assertOk()->assertSee('15 pontos');

        $this->patchJson("/tarefas/{$task->id}/mover", ['column' => 'todas'])->assertOk();
        $this->assertNull($task->fresh()->completed_at);

        $this->patchJson("/tarefas/{$task->id}/mover", ['column' => 'invalida'])->assertUnprocessable();
    }

    public function test_redirect_after_save_stays_inside_the_app(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post('/tarefas', [
            'title' => 'X', 'status' => 'pendente', 'priority' => 'normal', 'points' => 1,
            'redirect' => 'https://site-malicioso.example/phishing',
        ])->assertRedirect(route('tasks.index'));
    }
}
