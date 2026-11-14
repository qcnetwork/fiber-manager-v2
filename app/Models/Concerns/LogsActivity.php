<?php

namespace App\Models\Concerns;

use App\Models\ActivityLog;
use App\Models\Area;
use App\Models\Customer;
use App\Models\Odc;
use App\Models\OltPort;

/**
 * Writes an activity log entry whenever the model is created, changed or deleted.
 *
 * Models can override activityLabel(), activityAreaId(), activityIgnored(), activityMasked() and
 * activityValue() to make the entries readable.
 */
trait LogsActivity
{
    public static function bootLogsActivity(): void
    {
        static::created(fn ($model) => $model->logActivity('created'));

        static::updated(function ($model) {
            // Only bookkeeping columns changed (e.g. remember_token on login): nothing to show.
            if ($changes = $model->activityChanges()) {
                $model->logActivity('updated', $changes);
            }
        });

        static::deleted(fn ($model) => $model->logActivity('deleted', $model->activitySnapshot()));
    }

    /**
     * @param  array<string, mixed>|null  $changes
     */
    public function logActivity(string $action, ?array $changes = null): void
    {
        ActivityLog::record($action, ActivityLog::TYPES[static::class], $this->getKey(), $this->activityLabel(), $this->activityAreaId(), $changes);
    }

    public function activityLabel(): string
    {
        return (string) ($this->code ?? $this->name ?? '#'.$this->getKey());
    }

    public function activityAreaId(): ?int
    {
        return $this->area_id;
    }

    /**
     * Columns never shown in the log.
     *
     * @return list<string>
     */
    protected function activityIgnored(): array
    {
        return [];
    }

    /**
     * Columns logged only as "changed", without their values.
     *
     * @return list<string>
     */
    protected function activityMasked(): array
    {
        return [];
    }

    /**
     * Turns a stored value into something readable: a link to another record becomes that record's label.
     */
    protected function activityValue(string $key, mixed $value): mixed
    {
        $related = match ($key) {
            'area_id' => Area::class,
            'odc_id' => Odc::class,
            'olt_port_id' => OltPort::class,
            'customer_id' => Customer::class,
            default => null,
        };

        if ($related === null || $value === null) {
            return $value;
        }

        return $related::withoutGlobalScopes()->find($value)?->activityLabel() ?? $value;
    }

    /**
     * Changed columns as [column => [old, new]].
     *
     * @return array<string, array{0: mixed, 1: mixed}>
     */
    protected function activityChanges(): array
    {
        $changes = [];
        foreach (array_keys($this->getChanges()) as $key) {
            if ($this->isSkippedForActivity($key)) {
                continue;
            }

            $changes[$key] = in_array($key, $this->activityMasked(), true)
                ? [null, null]
                : [$this->activityValue($key, $this->getRawOriginal($key)), $this->activityValue($key, $this->getAttributes()[$key])];
        }

        return $changes;
    }

    /**
     * What deleting the record would remove, for previewing a delete request.
     *
     * @return array<string, array{0: mixed, 1: null}>
     */
    public function activityDeletePreview(): array
    {
        return $this->activitySnapshot();
    }

    /**
     * What the record held when it was deleted, as [column => [value, null]].
     *
     * @return array<string, array{0: mixed, 1: null}>
     */
    protected function activitySnapshot(): array
    {
        $snapshot = [];
        foreach ($this->getAttributes() as $key => $value) {
            if ($value === null || $key === $this->getKeyName() || $this->isSkippedForActivity($key) || in_array($key, $this->activityMasked(), true)) {
                continue;
            }
            $snapshot[$key] = [$this->activityValue($key, $value), null];
        }

        return $snapshot;
    }

    private function isSkippedForActivity(string $key): bool
    {
        return in_array($key, ['created_at', 'updated_at', ...$this->activityIgnored()], true);
    }
}
