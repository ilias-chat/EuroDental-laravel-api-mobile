<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Task;
use App\Models\TaskComment;
use App\Services\Tasks\TaskCommentAccess;
use App\Services\Tasks\TaskCommentNotifications;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class TaskCommentController extends Controller
{
    public function index(Request $request, Task $task, TaskCommentAccess $access)
    {
        abort_unless($access->canView($request->user(), $task), 403, 'Vous ne pouvez pas consulter les commentaires de cette tache.');
        $data = $request->validate(['before_id' => 'nullable|integer|min:1']);
        $comments = TaskComment::with('author.image')->where('task_id', $task->id)
            ->when($data['before_id'] ?? null, fn ($query, $id) => $query->where('id', '<', $id))
            ->orderByDesc('id')->limit(21)->get();
        $items = $comments->take(20)->values();
        return response()->json([
            'comments' => $items->map(fn ($comment) => $this->present($comment)),
            'can_post' => $access->canPost($request->user(), $task),
            'next_cursor' => $comments->count() > 20 ? $items->last()->id : null,
        ]);
    }

    public function store(Request $request, Task $task, TaskCommentAccess $access)
    {
        $comment = DB::transaction(function () use ($request, $task, $access) {
            $current = Task::whereKey($task->id)->lockForUpdate()->firstOrFail();
            abort_unless($access->canPost($request->user(), $current), 403, 'Vous ne pouvez pas commenter cette tache.');
            $data = $request->validate(['body' => 'required|string|max:5000'], [
                'body.required' => 'Saisissez un commentaire.',
                'body.string' => 'Le commentaire doit etre du texte.',
                'body.max' => 'Le commentaire ne doit pas depasser 5000 caracteres.',
            ]);
            $body = trim($data['body']);
            if ($body === '') throw ValidationException::withMessages(['body' => 'Saisissez un commentaire.']);
            $comment = TaskComment::create(['task_id' => $current->id, 'user_id' => $request->user()->id, 'body' => $body]);
            app(TaskCommentNotifications::class)->record($current, $comment, $request->user());
            return $comment;
        });
        return response()->json(['message' => 'Commentaire ajoute.', 'comment' => $this->present($comment->load('author.image'))], 201);
    }

    public function markRead(Request $request, Task $task, TaskCommentAccess $access, TaskCommentNotifications $notifications)
    {
        $data = $request->validate(['through_id' => 'required|integer|min:1']);
        DB::transaction(function () use ($request, $task, $access, $notifications, $data) {
            $current = Task::whereKey($task->id)->lockForUpdate()->firstOrFail();
            abort_unless($access->canView($request->user(), $current), 403);
            abort_unless(TaskComment::where('task_id', $current->id)->whereKey($data['through_id'])->exists(), 422);
            $notifications->markRead($request->user(), $current, $data['through_id']);
        });
        return response()->json(['success' => true]);
    }

    private function present(TaskComment $comment): array
    {
        return [
            'id' => $comment->id, 'body' => $comment->body,
            'created_at' => $comment->created_at->toISOString(),
            'author' => $comment->author ? [
                'id' => $comment->author->id,
                'name' => trim($comment->author->first_name . ' ' . $comment->author->last_name),
                'image' => $comment->author->image ? asset('storage/' . $comment->author->image->image_name) : null,
            ] : null,
        ];
    }
}
