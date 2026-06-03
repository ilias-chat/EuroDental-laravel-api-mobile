<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Mail\TaskProposedMail;
use App\Models\Client;
use App\Models\ProposedTask;
use App\Models\TaskType;
use App\Models\User;
use App\Services\NotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;

class ProposedTaskController extends Controller
{
    public function __construct(
        protected NotificationService $notificationService
    ) {}

    public function index(): JsonResponse
    {
        $this->ensureCanPropose();

        $proposedTasks = ProposedTask::with(['client.image', 'client.city'])
            ->where('proposed_by', Auth::id())
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (ProposedTask $proposal) => $this->mapProposal($proposal))
            ->values()
            ->all();

        $clients = Client::with(['image', 'city'])
            ->orderBy('first_name')
            ->get()
            ->map(fn (Client $client) => $this->mapClient($client))
            ->values()
            ->all();

        $taskTypes = TaskType::orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (TaskType $type) => ['id' => $type->id, 'name' => $type->name])
            ->values()
            ->all();

        return response()->json([
            'proposed_tasks' => $proposedTasks,
            'clients' => $clients,
            'task_types' => $taskTypes,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->ensureCanPropose();

        try {
            $validated = $this->validateProposalPayload($request);
        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur de validation',
                'errors' => $e->errors(),
            ], 422);
        }

        $proposedTask = ProposedTask::create([
            'task_name' => $validated['task_name'],
            'task_type' => $validated['task_type'],
            'client_id' => $validated['client_id'] ?? null,
            'description' => $validated['description'] ?? '',
            'proposed_by' => Auth::id(),
            'urgent' => $validated['urgent'] ?? false,
            'status' => 'en attente',
        ]);

        $proposedTask->load(['client.image', 'client.city']);

        $proposer = Auth::user();
        $proposerName = trim(($proposer->first_name ?? '') . ' ' . ($proposer->last_name ?? ''));

        $clientName = $proposedTask->client
            ? trim(($proposedTask->client->first_name ?? '') . ' ' . ($proposedTask->client->last_name ?? ''))
            : null;

        $adminUsers = User::where('profile_id', '!=', 1)
            ->whereHas('profile.permissions', fn ($q) => $q->where('code', 'tasks_view_all'))
            ->whereHas('profile.permissions', fn ($q) => $q->where('code', 'tasks_write'))
            ->get();

        $this->notifyAdminsOfProposal($proposedTask, $proposerName, $adminUsers, $clientName);

        return response()->json([
            'success' => true,
            'message' => 'Votre proposition de tâche a été envoyée avec succès',
            'proposal' => $this->mapProposal($proposedTask),
        ]);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $this->ensureCanPropose();

        $proposedTask = $this->findOwnedPending($id);

        try {
            $validated = $this->validateProposalPayload($request);
        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur de validation',
                'errors' => $e->errors(),
            ], 422);
        }

        $proposedTask->update([
            'task_name' => $validated['task_name'],
            'task_type' => $validated['task_type'],
            'client_id' => $validated['client_id'] ?? null,
            'description' => $validated['description'] ?? '',
            'urgent' => $validated['urgent'] ?? false,
        ]);

        $proposedTask->load(['client.image', 'client.city']);

        return response()->json([
            'success' => true,
            'message' => 'Proposition mise à jour avec succès',
            'proposal' => $this->mapProposal($proposedTask),
        ]);
    }

    public function destroy(int $id): JsonResponse
    {
        $this->ensureCanPropose();

        $proposedTask = $this->findOwnedPending($id);
        $proposedTask->delete();

        return response()->json([
            'success' => true,
            'message' => 'Proposition supprimée avec succès',
        ]);
    }

    private function notifyAdminsOfProposal(
        ProposedTask $proposedTask,
        string $proposerName,
        $adminUsers,
        ?string $clientName
    ): void {
        foreach ($adminUsers as $admin) {
            if ($admin->email) {
                try {
                    Mail::to($admin->email)->send(new TaskProposedMail($proposedTask, $proposerName, $clientName));
                } catch (\Throwable $e) {
                    Log::warning('Task proposed mail failed', [
                        'admin_id' => $admin->id,
                        'proposed_task_id' => $proposedTask->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }

        foreach ($adminUsers as $admin) {
            try {
                $this->notificationService->sendToUser(
                    $admin->id,
                    'Nouvelle proposition de tâche',
                    $proposerName . ' a proposé une nouvelle tâche: ' . $proposedTask->task_name,
                    ['type' => 'task_proposed', 'proposed_task_id' => $proposedTask->id]
                );
            } catch (\Throwable $e) {
                Log::warning('Task proposed notification failed', [
                    'admin_id' => $admin->id,
                    'proposed_task_id' => $proposedTask->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    private function ensureCanPropose(): void
    {
        $user = Auth::user();
        $can = $user?->profile?->permissions->pluck('code')->contains('mobile_tasks_propose') ?? false;

        if (! $can) {
            abort(403, 'Non autorisé');
        }
    }

    private function findOwnedPending(int $id): ProposedTask
    {
        $proposedTask = ProposedTask::where('proposed_by', Auth::id())->findOrFail($id);

        if (($proposedTask->status ?? 'en attente') !== 'en attente') {
            abort(403, 'Cette proposition ne peut plus être modifiée');
        }

        return $proposedTask;
    }

    /**
     * @return array<string, mixed>
     */
    private function validateProposalPayload(Request $request): array
    {
        return $request->validate([
            'task_name' => 'required|string|max:255',
            'task_type' => 'required|string',
            'client_id' => 'nullable|exists:clients,id',
            'description' => 'required_if:client_id,null|nullable|string',
            'urgent' => 'boolean',
        ]);
    }

    private function mapProposal(ProposedTask $proposal): array
    {
        $client = $proposal->client;

        return [
            'id' => $proposal->id,
            'task_name' => $proposal->task_name,
            'task_type' => $proposal->task_type,
            'description' => $proposal->description,
            'urgent' => (bool) $proposal->urgent,
            'status' => $proposal->status ?? 'en attente',
            'created_at' => $proposal->created_at->format('d/m/Y H:i'),
            'client_id' => $proposal->client_id,
            'client_name' => $client
                ? trim(($client->first_name ?? '') . ' ' . ($client->last_name ?? ''))
                : null,
            'client_image' => $client?->image
                ? asset('storage/' . $client->image->image_name)
                : null,
            'client_city' => $client?->city?->name,
        ];
    }

    private function mapClient(Client $client): array
    {
        return [
            'id' => $client->id,
            'name' => trim(($client->first_name ?? '') . ' ' . ($client->last_name ?? '')),
            'image' => $client->image ? asset('storage/' . $client->image->image_name) : null,
            'city' => $client->city ? $client->city->name : null,
        ];
    }
}
