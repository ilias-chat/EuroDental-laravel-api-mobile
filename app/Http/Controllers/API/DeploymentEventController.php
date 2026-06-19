<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Deployment;
use App\Models\DeploymentEvent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DeploymentEventController extends Controller
{
    public function index(Deployment $deployment): JsonResponse
    {
        if (! auth()->user()->profile || ! auth()->user()->profile->permissions->pluck('code')->contains('deployment_write')) {
            $canView = $deployment->responsible_id === auth()->id()
                || $deployment->driver_id === auth()->id()
                || (is_array($deployment->team_member_ids) && in_array(auth()->id(), $deployment->team_member_ids));
            if (! $canView) {
                return response()->json(['success' => false, 'message' => 'Non autorisé'], 403);
            }
        }

        $events = $deployment->events()->with('user.image')->get();

        return response()->json([
            'success' => true,
            'events' => $events->map(function ($event) {
                return [
                    'id' => $event->id,
                    'event_type' => $event->event_type,
                    'user_id' => $event->user_id,
                    'user_name' => $event->user ? $event->user->first_name.' '.$event->user->last_name : null,
                    'user_image' => $event->user && $event->user->image ? asset('storage/'.$event->user->image->image_name) : null,
                    'event_time' => $event->event_time ? $event->event_time->toIso8601String() : $event->created_at->toIso8601String(),
                    'created_at' => $event->created_at->toIso8601String(),
                ];
            })->values()->all(),
        ]);
    }

    public function store(Request $request, Deployment $deployment): JsonResponse
    {
        if ($deployment->responsible_id !== auth()->id()) {
            return response()->json([
                'success' => false,
                'message' => 'Seul le responsable du déploiement peut ajouter des événements',
            ], 403);
        }

        $validated = $request->validate([
            'event_type' => 'required|string|in:start,end,joined',
            'user_id' => 'nullable|exists:users,id',
            'event_time' => 'nullable|date',
        ]);

        $events = $deployment->events()->orderByRaw('COALESCE(event_time, created_at) ASC')->get();
        $hasStart = $events->contains('event_type', 'start');
        $hasEnd = $events->contains('event_type', 'end');

        if ($events->isEmpty()) {
            if ($validated['event_type'] !== 'start') {
                return response()->json([
                    'success' => false,
                    'message' => 'Le premier événement doit être "Début du déploiement"',
                ], 422);
            }
        } else {
            if (! $hasStart) {
                return response()->json([
                    'success' => false,
                    'message' => 'Un événement "Début" doit exister avant les autres',
                ], 422);
            }
            if ($validated['event_type'] === 'start') {
                return response()->json([
                    'success' => false,
                    'message' => 'L\'événement "Début" existe déjà',
                ], 422);
            }
            if ($validated['event_type'] === 'end' && $hasEnd) {
                return response()->json([
                    'success' => false,
                    'message' => 'L\'événement "Fin du déploiement" existe déjà',
                ], 422);
            }
        }

        if ($validated['event_type'] === 'joined') {
            if (empty($validated['user_id'])) {
                return response()->json([
                    'success' => false,
                    'message' => 'L\'événement "a rejoint" requiert un utilisateur',
                ], 422);
            }
            $alreadyJoined = $deployment->events()
                ->where('event_type', 'joined')
                ->where('user_id', $validated['user_id'])
                ->exists();
            if ($alreadyJoined) {
                return response()->json([
                    'success' => false,
                    'message' => 'Ce membre a déjà rejoint le déploiement',
                ], 422);
            }
        }

        $eventTime = isset($validated['event_time'])
            ? \Carbon\Carbon::parse($validated['event_time'])
            : now();

        $event = DeploymentEvent::create([
            'deployment_id' => $deployment->id,
            'event_type' => $validated['event_type'],
            'user_id' => $validated['event_type'] === 'joined' ? $validated['user_id'] : null,
            'event_time' => $eventTime,
        ]);

        $event->load('user.image');

        return response()->json([
            'success' => true,
            'message' => 'Événement ajouté',
            'event' => [
                'id' => $event->id,
                'event_type' => $event->event_type,
                'user_id' => $event->user_id,
                'user_name' => $event->user ? $event->user->first_name.' '.$event->user->last_name : null,
                'user_image' => $event->user && $event->user->image ? asset('storage/'.$event->user->image->image_name) : null,
                'event_time' => $event->event_time ? $event->event_time->toIso8601String() : $event->created_at->toIso8601String(),
                'created_at' => $event->created_at->toIso8601String(),
            ],
        ]);
    }
}
