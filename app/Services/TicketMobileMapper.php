<?php

namespace App\Services;

use App\Models\Ticket;
use App\Models\TicketAttachment;
use App\Models\TicketReply;

class TicketMobileMapper
{
    public static function mapAttachment(TicketAttachment $attachment): ?array
    {
        if (! $attachment->image || ! $attachment->image->image_name) {
            return null;
        }

        return [
            'id' => $attachment->id,
            'url' => storage_public_url($attachment->image->image_name),
        ];
    }

    public static function mapReply(TicketReply $reply, Ticket $ticket): array
    {
        $reply->loadMissing(['user', 'attachments.image']);

        return [
            'id' => $reply->id,
            'body' => $reply->body,
            'user_id' => $reply->user_id,
            'user_name' => $reply->user
                ? trim($reply->user->first_name.' '.$reply->user->last_name)
                : null,
            'created_at' => $reply->created_at?->toIso8601String(),
            'created_at_label' => $reply->created_at?->format('d/m/Y H:i'),
            'is_creator' => $reply->user_id === $ticket->user_id,
            'attachments' => $reply->attachments
                ->map(fn (TicketAttachment $a) => self::mapAttachment($a))
                ->filter()
                ->values()
                ->all(),
        ];
    }

    public static function mapTicketSummary(Ticket $ticket): array
    {
        $ticket->loadMissing(['user', 'replies']);

        return [
            'id' => $ticket->id,
            'subject' => $ticket->subject,
            'status' => $ticket->status,
            'status_label' => Ticket::statuses()[$ticket->status] ?? $ticket->status,
            'user_id' => $ticket->user_id,
            'user_name' => $ticket->user
                ? trim($ticket->user->first_name.' '.$ticket->user->last_name)
                : null,
            'replies_count' => $ticket->replies->count(),
            'updated_at' => $ticket->updated_at?->toIso8601String(),
            'updated_at_label' => $ticket->updated_at?->format('d/m/Y H:i'),
            'created_at' => $ticket->created_at?->toIso8601String(),
            'created_at_label' => $ticket->created_at?->format('d/m/Y H:i'),
        ];
    }

    public static function mapTicketDetail(Ticket $ticket, int $viewerId, bool $hasManage): array
    {
        $ticket->loadMissing([
            'user',
            'replies.user',
            'attachments.image',
            'replies.attachments.image',
        ]);

        $isAuthor = $ticket->user_id === $viewerId;

        return [
            'id' => $ticket->id,
            'subject' => $ticket->subject,
            'body' => $ticket->body,
            'status' => $ticket->status,
            'status_label' => Ticket::statuses()[$ticket->status] ?? $ticket->status,
            'user_id' => $ticket->user_id,
            'user_name' => $ticket->user
                ? trim($ticket->user->first_name.' '.$ticket->user->last_name)
                : null,
            'created_at' => $ticket->created_at?->toIso8601String(),
            'created_at_label' => $ticket->created_at?->format('d/m/Y H:i'),
            'updated_at' => $ticket->updated_at?->toIso8601String(),
            'updated_at_label' => $ticket->updated_at?->format('d/m/Y H:i'),
            'replies_count' => $ticket->replies->count(),
            'is_author' => $isAuthor,
            'can_reply' => $hasManage || $isAuthor,
            'can_resolve' => ($hasManage || $isAuthor) && $ticket->status !== Ticket::STATUS_SOLVED,
            'can_manage_status' => $hasManage,
            'attachments' => $ticket->attachments
                ->map(fn (TicketAttachment $a) => self::mapAttachment($a))
                ->filter()
                ->values()
                ->all(),
            'replies' => $ticket->replies
                ->map(fn (TicketReply $reply) => self::mapReply($reply, $ticket))
                ->values()
                ->all(),
        ];
    }
}
