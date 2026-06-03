<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Mail\NewLeaveRequestMail;
use App\Models\LeaveRequest;
use App\Models\Permission;
use App\Models\User;
use App\Services\NotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class LeaveRequestController extends Controller
{
    public function __construct(
        protected NotificationService $notificationService
    ) {}

    public function index(): JsonResponse
    {
        $leaveRequests = LeaveRequest::where('user_id', Auth::id())
            ->with(['reviewer'])
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (LeaveRequest $request) => $this->mapLeaveRequest($request))
            ->values()
            ->all();

        return response()->json(['leave_requests' => $leaveRequests]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'start_date' => 'required|date|after_or_equal:today',
            'end_date' => 'required|date|after_or_equal:start_date',
            'leave_type' => 'nullable|in:vacation,sick_leave,personal,other',
            'description' => 'nullable|string|max:1000',
            'justification_method' => 'nullable|in:whatsapp,email,telegram,other',
        ]);

        $leaveRequest = LeaveRequest::create([
            'user_id' => Auth::id(),
            'start_date' => $validated['start_date'],
            'end_date' => $validated['end_date'],
            'leave_type' => $validated['leave_type'] ?? null,
            'description' => $validated['description'] ?? null,
            'justification_method' => $validated['justification_method'] ?? null,
            'status' => 'waiting',
        ]);

        $leaveRequest->load('user');
        $this->notifyAdminsOfNewRequest($leaveRequest);

        return response()->json([
            'success' => true,
            'message' => 'Demande de congé créée avec succès',
            'leave_request' => $this->mapLeaveRequest($leaveRequest->fresh(['reviewer'])),
        ]);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $leaveRequest = LeaveRequest::where('user_id', Auth::id())->findOrFail($id);

        if (! $leaveRequest->canBeEdited()) {
            return response()->json([
                'success' => false,
                'message' => 'Cette demande ne peut pas être modifiée',
            ], 403);
        }

        $validated = $request->validate([
            'start_date' => 'required|date|after_or_equal:today',
            'end_date' => 'required|date|after_or_equal:start_date',
            'leave_type' => 'nullable|in:vacation,sick_leave,personal,other',
            'description' => 'nullable|string|max:1000',
            'justification_method' => 'nullable|in:whatsapp,email,telegram,other',
        ]);

        $leaveRequest->update($validated);

        return response()->json([
            'success' => true,
            'message' => 'Demande de congé mise à jour avec succès',
            'leave_request' => $this->mapLeaveRequest($leaveRequest->fresh(['reviewer'])),
        ]);
    }

    public function cancel(int $id): JsonResponse
    {
        $leaveRequest = LeaveRequest::where('user_id', Auth::id())->findOrFail($id);

        if (! $leaveRequest->canBeCancelled()) {
            return response()->json([
                'success' => false,
                'message' => 'Cette demande ne peut pas être annulée',
            ], 403);
        }

        $leaveRequest->update([
            'status' => 'denied',
            'rejection_reason' => 'Annulée par l\'utilisateur',
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Demande de congé annulée avec succès',
            'leave_request' => $this->mapLeaveRequest($leaveRequest->fresh(['reviewer'])),
        ]);
    }

    public function destroy(int $id): JsonResponse
    {
        $leaveRequest = LeaveRequest::where('user_id', Auth::id())->findOrFail($id);

        if (! $leaveRequest->canBeEdited()) {
            return response()->json([
                'success' => false,
                'message' => 'Cette demande ne peut pas être supprimée',
            ], 403);
        }

        $leaveRequest->delete();

        return response()->json([
            'success' => true,
            'message' => 'Demande de congé supprimée avec succès',
        ]);
    }

    protected function mapLeaveRequest(LeaveRequest $request): array
    {
        return [
            'id' => $request->id,
            'start_date' => $request->start_date->format('Y-m-d'),
            'end_date' => $request->end_date->format('Y-m-d'),
            'leave_type' => $request->leave_type,
            'description' => $request->description,
            'status' => $request->status,
            'justification_method' => $request->justification_method,
            'reviewed_by_name' => $request->reviewer
                ? trim($request->reviewer->first_name.' '.$request->reviewer->last_name)
                : null,
            'reviewed_at' => $request->reviewed_at?->format('Y-m-d H:i:s'),
            'rejection_reason' => $request->rejection_reason,
            'created_at' => $request->created_at->format('Y-m-d H:i:s'),
        ];
    }

    protected function notifyAdminsOfNewRequest(LeaveRequest $leaveRequest): void
    {
        try {
            $adminUsers = $this->getUsersWithPermission('manage_vacations');
            $userName = $leaveRequest->user->first_name.' '.$leaveRequest->user->last_name;
            $startDate = $leaveRequest->start_date->format('d/m/Y');
            $endDate = $leaveRequest->end_date->format('d/m/Y');
            $notificationTitle = 'Nouvelle demande de congé';
            $notificationBody = "{$userName} a soumis une demande de congé du {$startDate} au {$endDate}";

            foreach ($adminUsers as $adminUser) {
                $this->notificationService->sendToUser(
                    $adminUser->id,
                    $notificationTitle,
                    $notificationBody,
                    [
                        'type' => 'leave_request_created',
                        'leave_request_id' => $leaveRequest->id,
                        'user_id' => $leaveRequest->user_id,
                    ]
                );

                if ($adminUser->email) {
                    try {
                        Mail::to($adminUser->email)->send(new NewLeaveRequestMail($leaveRequest));
                    } catch (\Exception $e) {
                        Log::error('Error sending email to admin for new leave request', [
                            'admin_id' => $adminUser->id,
                            'email' => $adminUser->email,
                            'error' => $e->getMessage(),
                        ]);
                    }
                }
            }
        } catch (\Exception $e) {
            Log::error('Error notifying admins of new leave request', [
                'leave_request_id' => $leaveRequest->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    protected function getUsersWithPermission(string $permissionCode)
    {
        $permission = Permission::where('code', $permissionCode)->first();

        if (! $permission) {
            return collect([]);
        }

        $profileIds = $permission->profiles()->pluck('profiles.id');

        return User::whereIn('profile_id', $profileIds)
            ->where('is_blocked', false)
            ->get();
    }
}
