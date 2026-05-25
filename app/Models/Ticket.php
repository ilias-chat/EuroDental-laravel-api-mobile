<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Ticket extends Model
{
    public const STATUS_OPEN = 'open';
    public const STATUS_IN_PROGRESS = 'in_progress';
    public const STATUS_SOLVED = 'solved';

    public static function statuses(): array
    {
        return [
            self::STATUS_OPEN => 'En attente',
            self::STATUS_IN_PROGRESS => 'En cours',
            self::STATUS_SOLVED => 'Résolu',
        ];
    }

    /** Tailwind classes for status badges (bg-* text-*). */
    public static function statusBadgeClasses(string $status): string
    {
        return match ($status) {
            self::STATUS_OPEN => 'bg-amber-100 text-amber-800',
            self::STATUS_IN_PROGRESS => 'bg-green-100 text-green-800',
            self::STATUS_SOLVED => 'bg-blue-100 text-blue-800',
            default => 'bg-gray-100 text-gray-800',
        };
    }

    protected $fillable = [
        'user_id',
        'subject',
        'body',
        'status',
    ];

    protected $casts = [
        //
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function replies(): HasMany
    {
        return $this->hasMany(TicketReply::class)->orderBy('created_at');
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(TicketAttachment::class)->whereNull('ticket_reply_id');
    }

    /**
     * All users who are part of this ticket: creator + everyone who has replied.
     * Used for notifications on reply.
     */
    public function participants(): \Illuminate\Support\Collection
    {
        $userIds = collect([$this->user_id]);
        foreach ($this->replies as $reply) {
            $userIds->push($reply->user_id);
        }
        return User::whereIn('id', $userIds->unique()->values())->get();
    }
}
