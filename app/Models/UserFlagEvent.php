<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserFlagEvent extends Model
{
    public const KIND_GREEN = 'green';

    public const KIND_RED = 'red';

    protected $fillable = [
        'user_id',
        'kind',
        'user_flag_title_id',
        'note',
        'created_by',
        'retired_at',
        'retired_by',
        'retired_note',
    ];

    protected function casts(): array
    {
        return [
            'retired_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function flagTitle(): BelongsTo
    {
        return $this->belongsTo(UserFlagTitle::class, 'user_flag_title_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function retiredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'retired_by');
    }

    public function scopeActive($query)
    {
        return $query->whereNull('retired_at');
    }

    public function getIsActiveAttribute(): bool
    {
        return $this->retired_at === null;
    }

    public static function netForUser(int $userId, string $kind): int
    {
        return static::query()
            ->where('user_id', $userId)
            ->where('kind', $kind)
            ->active()
            ->count();
    }

    /**
     * @return array<int, array<string, int>> [user_id => ['green' => n, 'red' => n]]
     */
    public static function netCountsGroupedByUser(): array
    {
        $rows = static::query()
            ->selectRaw('user_id, kind, COUNT(*) as net')
            ->active()
            ->groupBy('user_id', 'kind')
            ->get();

        $byUser = [];
        foreach ($rows as $row) {
            $byUser[$row->user_id][$row->kind] = (int) $row->net;
        }

        return $byUser;
    }
}
