<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Mail\NewTicketMail;
use App\Mail\TicketReplyMail;
use App\Models\Image;
use App\Models\Ticket;
use App\Models\TicketAttachment;
use App\Models\TicketReply;
use App\Services\NotificationService;
use App\Services\TicketMobileMapper;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;

class TicketController extends Controller
{
    public function __construct(
        protected NotificationService $notificationService
    ) {}

    public function index(Request $request): JsonResponse
    {
        if (! $this->canAccessTickets()) {
            return $this->forbidden();
        }

        $query = Ticket::with(['user:id,first_name,last_name', 'replies']);

        if (! $this->hasManage()) {
            $query->where('user_id', Auth::id());
        }

        if ($request->filled('status') && in_array($request->status, [
            Ticket::STATUS_OPEN,
            Ticket::STATUS_IN_PROGRESS,
            Ticket::STATUS_SOLVED,
        ], true)) {
            $query->where('status', $request->status);
        }

        $paginator = $query->orderByDesc('updated_at')->paginate(15);

        return response()->json([
            'success' => true,
            'has_manage' => $this->hasManage(),
            'tickets' => collect($paginator->items())
                ->map(fn (Ticket $ticket) => TicketMobileMapper::mapTicketSummary($ticket))
                ->values()
                ->all(),
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'has_more' => $paginator->hasMorePages(),
            ],
        ]);
    }

    public function show(int $id): JsonResponse
    {
        if (! $this->canAccessTickets()) {
            return $this->forbidden();
        }

        $ticket = Ticket::with([
            'user',
            'replies.user',
            'attachments.image',
            'replies.attachments.image',
        ])->findOrFail($id);

        if (! $this->canViewTicket($ticket)) {
            return $this->forbidden();
        }

        return response()->json([
            'success' => true,
            'ticket' => TicketMobileMapper::mapTicketDetail(
                $ticket,
                (int) Auth::id(),
                $this->hasManage()
            ),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        if (! $this->hasCreate()) {
            return $this->forbidden();
        }

        $validated = $request->validate([
            'subject' => 'required|string|max:255',
            'body' => 'required|string',
            'attachment' => 'nullable|image|mimes:jpeg,png,webp,gif|max:5120',
        ]);

        $ticket = Ticket::create([
            'user_id' => Auth::id(),
            'subject' => $validated['subject'],
            'body' => $validated['body'],
            'status' => Ticket::STATUS_OPEN,
        ]);

        if ($request->hasFile('attachment')) {
            $this->storeAttachment($ticket, null, $request->file('attachment'));
        }

        $this->notifyNewTicket($ticket);

        return response()->json([
            'success' => true,
            'message' => 'Ticket créé.',
            'ticket_id' => $ticket->id,
            'ticket' => TicketMobileMapper::mapTicketDetail(
                $ticket->fresh(['user', 'replies', 'attachments.image', 'replies.attachments.image']),
                (int) Auth::id(),
                $this->hasManage()
            ),
        ]);
    }

    public function storeReply(Request $request, int $id): JsonResponse
    {
        if (! $this->canAccessTickets()) {
            return $this->forbidden();
        }

        $ticket = Ticket::findOrFail($id);

        if (! $this->canReplyToTicket($ticket)) {
            return $this->forbidden('Non autorisé.');
        }

        $validated = $request->validate([
            'body' => 'required|string',
            'attachment' => 'nullable|image|mimes:jpeg,png,webp,gif|max:5120',
        ]);

        $reply = TicketReply::create([
            'ticket_id' => $ticket->id,
            'user_id' => Auth::id(),
            'body' => $validated['body'],
        ]);

        if ($ticket->status === Ticket::STATUS_OPEN) {
            $ticket->update(['status' => Ticket::STATUS_IN_PROGRESS]);
        }

        if ($request->hasFile('attachment')) {
            $this->storeAttachment($ticket, $reply, $request->file('attachment'));
        }

        $this->notifyTicketReply($ticket, $reply);

        $ticket->refresh();
        $reply->load(['user', 'attachments.image']);

        return response()->json([
            'success' => true,
            'message' => 'Réponse ajoutée.',
            'reply' => TicketMobileMapper::mapReply($reply, $ticket),
            'ticket' => TicketMobileMapper::mapTicketDetail(
                $ticket->load(['user', 'replies.user', 'attachments.image', 'replies.attachments.image']),
                (int) Auth::id(),
                $this->hasManage()
            ),
        ]);
    }

    public function resolve(int $id): JsonResponse
    {
        if (! $this->canAccessTickets()) {
            return $this->forbidden();
        }

        $ticket = Ticket::findOrFail($id);

        if (! $this->canViewTicket($ticket)) {
            return $this->forbidden();
        }

        $isAuthor = $ticket->user_id === Auth::id();
        if (! $this->hasManage() && ! $isAuthor) {
            return $this->forbidden('Seul l\'auteur du ticket ou un gestionnaire peut le marquer comme résolu.');
        }

        if ($ticket->status === Ticket::STATUS_SOLVED) {
            return response()->json([
                'success' => true,
                'message' => 'Ce ticket est déjà marqué comme résolu.',
                'ticket' => TicketMobileMapper::mapTicketDetail(
                    $ticket->load(['user', 'replies.user', 'attachments.image', 'replies.attachments.image']),
                    (int) Auth::id(),
                    $this->hasManage()
                ),
            ]);
        }

        $ticket->update(['status' => Ticket::STATUS_SOLVED]);

        return response()->json([
            'success' => true,
            'message' => 'Ticket marqué comme résolu.',
            'ticket' => TicketMobileMapper::mapTicketDetail(
                $ticket->fresh(['user', 'replies.user', 'attachments.image', 'replies.attachments.image']),
                (int) Auth::id(),
                $this->hasManage()
            ),
        ]);
    }

    public function updateStatus(Request $request, int $id): JsonResponse
    {
        if (! $this->hasManage()) {
            return $this->forbidden();
        }

        $ticket = Ticket::findOrFail($id);

        $validated = $request->validate([
            'status' => 'required|in:open,in_progress,solved',
        ]);

        $ticket->update(['status' => $validated['status']]);

        return response()->json([
            'success' => true,
            'message' => 'Statut mis à jour.',
            'ticket' => TicketMobileMapper::mapTicketDetail(
                $ticket->fresh(['user', 'replies.user', 'attachments.image', 'replies.attachments.image']),
                (int) Auth::id(),
                true
            ),
        ]);
    }

    private function storeAttachment(Ticket $ticket, ?TicketReply $reply, $file): void
    {
        $path = $file->store('tickets', 'public');
        $image = Image::create(['image_name' => $path]);
        TicketAttachment::create([
            'ticket_id' => $ticket->id,
            'ticket_reply_id' => $reply?->id,
            'image_id' => $image->id,
        ]);
    }

    private function canAccessTickets(): bool
    {
        return $this->hasCreate() || $this->hasManage();
    }

    private function hasManage(): bool
    {
        $user = Auth::user();

        return $user?->profile
            && $user->profile->permissions->pluck('code')->contains('tickets_manage');
    }

    private function hasCreate(): bool
    {
        $user = Auth::user();

        return $user?->profile
            && $user->profile->permissions->pluck('code')->contains('tickets_create');
    }

    private function canViewTicket(Ticket $ticket): bool
    {
        if ($this->hasManage()) {
            return true;
        }

        return $this->hasCreate() && $ticket->user_id === Auth::id();
    }

    private function canReplyToTicket(Ticket $ticket): bool
    {
        if ($this->hasManage()) {
            return true;
        }

        return $this->hasCreate() && $ticket->user_id === Auth::id();
    }

    private function forbidden(string $message = 'Non autorisé.'): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => $message,
        ], 403);
    }

    private function notifyNewTicket(Ticket $ticket): void
    {
        $ticket->load('user');
        $users = \App\Models\User::whereHas('profile.permissions', fn ($q) => $q->where('code', 'tickets_manage'))
            ->where('profile_id', '!=', 1)
            ->get();
        $creatorName = trim($ticket->user->first_name.' '.$ticket->user->last_name);
        $title = 'Nouveau ticket';
        $body = $creatorName.' a créé un ticket : '.\Str::limit($ticket->subject, 50);
        $data = ['type' => 'new_ticket', 'ticket_id' => $ticket->id];

        foreach ($users as $user) {
            $this->notificationService->sendToUser($user->id, $title, $body, $data);
            try {
                Mail::to($user->email)->send(new NewTicketMail($ticket, $creatorName));
            } catch (\Throwable $e) {
                report($e);
            }
        }
    }

    private function notifyTicketReply(Ticket $ticket, TicketReply $reply): void
    {
        $reply->load('user');
        $participants = $ticket->participants()
            ->filter(fn ($u) => $u->id !== $reply->user_id && $u->profile_id != 1);
        $authorName = trim($reply->user->first_name.' '.$reply->user->last_name);
        $title = 'Nouvelle réponse sur un ticket';
        $body = $authorName.' a répondu : '.\Str::limit($reply->body, 50);
        $data = ['type' => 'ticket_reply', 'ticket_id' => $ticket->id];

        foreach ($participants as $user) {
            $this->notificationService->sendToUser($user->id, $title, $body, $data);
            try {
                Mail::to($user->email)->send(new TicketReplyMail($ticket, $reply, $authorName));
            } catch (\Throwable $e) {
                report($e);
            }
        }
    }
}
