<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Who changed what and when. Written by the LogsActivity trait and by actions that span several records
 * (moving a customer, importing a file), which log one entry and mute the per-record ones.
 */
class ActivityLog extends Model
{
    use MassPrunable;

    public const UPDATED_AT = null;

    /** How long entries are kept. */
    public const KEEP_MONTHS = 24;

    /** @var array<class-string<Model>, string> */
    public const TYPES = [
        Area::class => 'pop',
        Olt::class => 'olt',
        OltPort::class => 'olt_port',
        Odc::class => 'odc',
        Odp::class => 'odp',
        OdpPort::class => 'odp_port',
        Customer::class => 'customer',
        Cable::class => 'cable',
        Photo::class => 'photo',
        User::class => 'user',
    ];

    protected $fillable = [
        'user_id', 'user_name', 'area_id', 'action', 'subject_type', 'subject_id', 'subject_label', 'changes', 'ip_address',
    ];

    private static bool $muted = false;

    protected function casts(): array
    {
        return [
            'changes' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Records an entry for the signed-in user. Nothing is logged outside a request (console commands,
     * legacy migration, seeders) or while muted.
     *
     * @param  array<string, mixed>|null  $changes
     */
    public static function record(string $action, string $type, ?int $subjectId, string $label, ?int $areaId, ?array $changes = null): void
    {
        $user = auth()->user();
        if (self::$muted || ! $user) {
            return;
        }

        static::create([
            'user_id' => $user->id,
            'user_name' => $user->name,
            'area_id' => $areaId,
            'action' => $action,
            'subject_type' => $type,
            'subject_id' => $subjectId,
            'subject_label' => mb_substr($label, 0, 255),
            'changes' => $changes ?: null,
            'ip_address' => request()->ip(),
        ]);
    }

    /**
     * Runs the callback without logging, for actions that log one summary entry themselves.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function muted(callable $callback): mixed
    {
        $previous = self::$muted;
        self::$muted = true;

        try {
            return $callback();
        } finally {
            self::$muted = $previous;
        }
    }

    /**
     * Area users only see their own area's history.
     */
    public function scopeVisibleTo(Builder $query, User $user): void
    {
        if (! $user->seesAllAreas()) {
            $query->where('area_id', $user->area_id);
        }
    }

    public function prunable(): Builder
    {
        return static::where('created_at', '<', now()->subMonths(self::KEEP_MONTHS));
    }
}
