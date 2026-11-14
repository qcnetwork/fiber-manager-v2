<?php

namespace App\Services;

use App\Exceptions\ReplayFailed;
use App\Http\Middleware\HoldForApproval;
use App\Models\ActivityLog;
use App\Models\Area;
use App\Models\CableCore;
use App\Models\ChangeBatch;
use App\Models\ChangeRequest;
use App\Models\Photo;
use App\Models\User;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Routing\Route;
use Illuminate\Routing\Router;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * Technicians' changes: kept as drafts, sent together as a batch, applied when an approver approves it.
 *
 * Drafts are never written to the network tables. To check one, the user's earlier drafts are replayed
 * through their controllers inside a transaction that is rolled back, so later drafts can build on
 * records earlier drafts create (referred to by token: the draft's negative id).
 */
class ChangeRequestService
{
    /** Asset forms where users with an area don't send it; the approver may see all areas and then must. */
    private const AREA_PINNED_ROUTES = ['odcs.store', 'odcs.update', 'odps.store', 'odps.update', 'joints.store', 'joints.update', 'cables.store', 'cables.update'];

    private const ASSET_DELETE_ROUTES = ['odcs.destroy', 'odps.destroy', 'joints.destroy', 'cables.destroy'];

    /** Changes that can be opened in their map form to be changed (by the technician or an approver). */
    public const EDITABLE_ROUTES = [
        'odcs.store', 'odcs.update', 'odps.store', 'odps.update', 'joints.store', 'joints.update',
        'cables.store', 'cables.update', 'customers.update', 'ports.update',
    ];

    public function __construct(private Router $router) {}

    // ---- Technicians: drafts

    /**
     * Keeps a technician's change as a draft instead of saving it, or updates the draft it edits (`draft_id`).
     */
    public function hold(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $name = (string) $request->route()->getName();
        $subject = $this->routeModel($request->route());
        $baseUpdatedAt = $subject?->updated_at?->copy();

        $editing = null;
        if ($request->filled('draft_id')) {
            $editing = ChangeRequest::draftsOf($user)->find($request->integer('draft_id'));
            abort_unless($editing && $editing->route_name === $name, 404);
        }

        // Drafts made before the one being changed are replayed first; the ones after must still work afterwards.
        $drafts = ChangeRequest::draftsOf($user)->get();
        $position = $editing ? $drafts->search(fn (ChangeRequest $d) => $d->is($editing)) : $drafts->count();
        $before = $drafts->slice(0, $position);
        $after = $editing ? $drafts->slice($position + 1) : collect();
        $payload = $this->payload($request);

        try {
            [$response, $logs, $resolved] = $this->dryRun(function () use ($request, $next, $before, $after, $editing, $payload, $user) {
                $created = $this->replay($before, $user)['created'];
                $resolved = $this->resolveTokens($payload, $created);
                $request->merge($resolved);

                $lastLog = (int) ActivityLog::max('id');
                $response = $this->deletesDirectly($request->route()->getName(), $user)
                    ? $this->deleteBound($request->route())
                    : $next($request);
                $logs = $this->logsSince($lastLog);

                if ($this->succeeded($response) && $after->isNotEmpty()) {
                    $first = $logs->first();
                    if ($editing && $first?->action === 'created') {
                        $created[$editing->id] = $first->subject_id;
                    }
                    $this->replay($after, $user, $created);
                }

                return [$response, $logs, $resolved];
            });
        } catch (ReplayFailed $e) {
            $when = $before->contains($e->item) ? 'Draft lain sudah tidak valid' : 'Perubahan ini membuat draft lain tidak valid';

            return back()->with('noticeError', "{$when} — {$e->describe()}");
        }

        // Validation errors and refusals go back to the technician as usual.
        if (! $this->succeeded($response)) {
            return $response;
        }

        $attributes = match (true) {
            $name === 'photos.store' => $this->photoAttributes($request, $payload, $user),
            $name === 'photos.update' => $this->photoUpdateAttributes($request, $payload, $subject),
            $logs->isNotEmpty() => $this->logAttributes($logs),
            default => $this->unloggedAttributes($resolved, $subject),
        };
        if ($attributes === null) {
            return back()->with('notice', 'Tidak ada perubahan untuk disimpan.');
        }

        $data = [
            ...$attributes,
            'area_id' => $attributes['area_id'] ?? $user->area_id,
            'route_name' => $name,
            'route_params' => $request->route()->originalParameters(),
            'method' => $request->method(),
            'payload' => $payload,
            'base_updated_at' => $editing ? $editing->base_updated_at : $baseUpdatedAt,
        ];

        if ($editing) {
            if (isset($data['files'])) {
                $editing->deleteFiles();
            }
            $editing->update($data);

            return back()->with('notice', 'Draft diperbarui.');
        }

        ChangeRequest::create([
            ...$data,
            'user_id' => $user->id,
            'status' => ChangeRequest::DRAFT,
            'sequence' => (int) ChangeRequest::draftsOf($user)->reorder()->max('sequence') + 1,
        ]);

        return back()->with('notice', 'Disimpan sebagai draft. Kirim ke approval setelah semua perubahan selesai.');
    }

    /**
     * Throws a draft away, unless another draft builds on what it creates.
     *
     * @return string|null why it can't be removed
     */
    public function discardDraft(ChangeRequest $draft): ?string
    {
        $dependents = ChangeRequest::draftsOf($draft->user)->whereKeyNot($draft->id)->get()
            ->filter(fn (ChangeRequest $other) => $this->refersTo($other->payload ?? [], $draft->token()));

        if ($dependents->isNotEmpty()) {
            return 'Hapus dulu draft yang memakai '.($draft->subject_label ?: 'draft ini').': '.$dependents->pluck('subject_label')->filter()->implode(', ').'.';
        }

        $draft->delete();

        return null;
    }

    public function discardAll(User $user): void
    {
        ChangeRequest::draftsOf($user)->get()->each->delete();
    }

    /**
     * Sends all the user's drafts as one batch, after checking they still apply together.
     *
     * @return string|null why they could not be sent
     */
    public function submit(User $user, ?string $note): ?string
    {
        $drafts = ChangeRequest::draftsOf($user)->get();
        if ($drafts->isEmpty()) {
            return 'Belum ada draft untuk dikirim.';
        }

        try {
            $this->dryRun(fn () => $this->replay($drafts, $user));
        } catch (ReplayFailed $e) {
            return 'Draft tidak bisa dikirim — '.$e->describe();
        }

        $areas = $drafts->pluck('area_id')->unique();

        DB::transaction(function () use ($user, $note, $drafts, $areas) {
            $batch = ChangeBatch::create([
                'user_id' => $user->id,
                // Spanning several areas: only approvers of all areas may decide it.
                'area_id' => $areas->count() === 1 ? $areas->first() : null,
                'status' => ChangeBatch::PENDING,
                'note' => $note,
                'submitted_at' => now(),
            ]);
            ChangeRequest::whereKey($drafts->modelKeys())->update(['batch_id' => $batch->id, 'status' => ChangeRequest::PENDING]);
        });

        return null;
    }

    // ---- Approvers

    /**
     * Applies every change of the batch as the approver, all or nothing.
     *
     * @return string|null why it could not be applied, or null when it was
     */
    public function approveBatch(ChangeBatch $batch, User $approver, ?string $note = null): ?string
    {
        $items = $batch->items()->get();
        $lastLog = (int) ActivityLog::max('id');
        $lastPhoto = (int) Photo::max('id');

        DB::beginTransaction();
        try {
            $this->replay($items, $approver);

            // Photos keep the technician who took them.
            Photo::where('id', '>', $lastPhoto)->update(['user_id' => $batch->user_id]);
            $from = "Paket #{$batch->id} dari ".($batch->user?->name ?? 'pengguna terhapus');
            ActivityLog::where('id', '>', $lastLog)->get()->each(function (ActivityLog $log) use ($from) {
                $log->update(['changes' => [...($log->changes ?? []), 'request' => [null, $from]]]);
            });

            ChangeRequest::whereKey($items->modelKeys())->update(['status' => ChangeRequest::APPROVED]);
            $batch->update([
                'status' => ChangeBatch::APPROVED,
                'reviewer_id' => $approver->id,
                'reviewed_at' => now(),
                'review_note' => $note,
            ]);
            DB::commit();
        } catch (ReplayFailed $e) {
            DB::rollBack();

            return $e->describe();
        } catch (\Throwable $e) {
            DB::rollBack();

            throw $e;
        }

        $batch->deleteFiles();

        return null;
    }

    public function rejectBatch(ChangeBatch $batch, User $reviewer, ?string $note): void
    {
        $this->close($batch, ChangeBatch::REJECTED, [
            'reviewer_id' => $reviewer->id,
            'reviewed_at' => now(),
            'review_note' => $note,
        ]);
    }

    public function cancelBatch(ChangeBatch $batch): void
    {
        $this->close($batch, ChangeBatch::CANCELLED, ['reviewed_at' => now(), 'seen_at' => now()]);
    }

    /**
     * An approver changed one item of a batch on the map: the item now holds that, still to be approved with the batch.
     */
    public function saveEdited(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $item = ChangeRequest::where('status', ChangeRequest::PENDING)
            ->whereHas('batch', fn ($q) => $q->reviewableBy($user)->pending())
            ->find($request->integer('change_request_id'));

        if (! $item || $item->route_name !== $request->route()->getName()) {
            return $next($request);
        }

        $payload = $this->payload($request);
        try {
            $result = $this->dryRun(fn () => $this->replay($item->batch->items()->get(), $user, [], [$item->id => $payload]));
        } catch (ReplayFailed $e) {
            // Errors in the edited form show on its fields; problems elsewhere in the batch as a message.
            if ($e->item->is($item) && $e->errors) {
                throw ValidationException::withMessages($e->errors);
            }

            return back()->with('noticeError', 'Perubahan ini membuat isi paket tidak valid — '.$e->describe());
        }

        $logs = $result['logs'][$item->id] ?? collect();
        $item->update(['payload' => $payload, ...($logs->isNotEmpty() ? Arr::except($this->logAttributes($logs), ['area_id']) : [])]);

        return back()->with('notice', "Isi request #{$item->id} diperbarui. Setujui paketnya untuk menerapkan.");
    }

    // ---- Map overlay

    /**
     * Drafts (or a batch's changes) as the map shows them: new assets where they would be, changed ones marked.
     *
     * @param  Collection<int, ChangeRequest>  $items
     * @return list<array<string, mixed>>
     */
    public function overlay(Collection $items): array
    {
        $byId = $items->keyBy('id');

        return $items->map(function (ChangeRequest $item) use ($byId) {
            $resource = Str::before($item->route_name, '.');
            $payload = $item->payload ?? [];
            $point = $this->point($payload);
            $path = $payload['path'] ?? null;

            // Changes without a place of their own are shown at the record they touch.
            $model = null;
            if ((! $point || ($resource === 'cables' && ! $path)) && $item->subject_id && ($class = array_search($item->subject_type, ActivityLog::TYPES, true))) {
                $model = $class::withoutGlobalScopes()->find($item->subject_id);
            }
            if ($resource === 'photos' && $item->subject_id) {
                // A change to an existing photo: shown at what the photo belongs to.
                $model = Photo::find($item->subject_id)?->photoable()->withoutGlobalScopes()->first();
            } elseif ($resource === 'photos') {
                $ownerId = $payload['photoable_id'] ?? null;
                $class = Relation::getMorphedModel((string) ($payload['photoable_type'] ?? ''));
                $model = $class && ! $this->isToken($ownerId) ? $class::withoutGlobalScopes()->find($ownerId) : null;
                $point = $this->isToken($ownerId) ? $this->point($byId->get(-(int) $ownerId)?->payload ?? []) : null;
            }
            $point ??= $model && isset($model->lat, $model->lng) ? ['lat' => (float) $model->lat, 'lng' => (float) $model->lng] : null;
            if ($resource === 'cables' && ! $path && $model) {
                $path = $model->path;
            }

            return [
                'id' => $item->id,
                'token' => $item->token(),
                'status' => $item->status,
                'action' => $item->action,
                'kind' => $item->subject_type,
                'route_name' => $item->route_name,
                'subject_id' => $item->subject_id,
                'label' => $item->subject_label,
                'lat' => $point['lat'] ?? null,
                'lng' => $point['lng'] ?? null,
                'path' => $resource === 'cables' ? $path : null,
                'payload' => $payload,
                'preview' => $item->preview,
                'has_file' => ! empty($item->files),
                'conflict' => $item->conflict(),
                'editable' => in_array($item->route_name, self::EDITABLE_ROUTES, true),
            ];
        })->values()->all();
    }

    // ---- Replaying stored changes

    /**
     * Runs the given changes in order through their routes as $actor, in the current transaction. Records a change
     * creates replace its token in the changes after it.
     *
     * @param  Collection<int, ChangeRequest>  $items
     * @param  array<int, int>  $created  id of the record each draft created, by draft id
     * @param  array<int, array<string, mixed>>  $overrides  payloads to use instead of the stored ones, by id
     * @return array{created: array<int, int>, logs: array<int, Collection<int, ActivityLog>>}
     *
     * @throws ReplayFailed
     */
    private function replay(Collection $items, User $actor, array $created = [], array $overrides = []): array
    {
        $logs = [];
        foreach ($items as $item) {
            $lastLog = (int) ActivityLog::max('id');

            try {
                $this->runStored($item, $actor, $this->resolveTokens($overrides[$item->id] ?? $item->payload ?? [], $created));
            } catch (ValidationException $e) {
                throw new ReplayFailed($item, collect($e->errors())->flatten()->unique()->implode(' '), $e->errors());
            } catch (ModelNotFoundException) {
                throw new ReplayFailed($item, 'Data yang diubah sudah tidak ada.');
            } catch (AuthorizationException|HttpExceptionInterface) {
                throw new ReplayFailed($item, 'Tidak bisa diterapkan (data tidak ditemukan atau tidak diizinkan).');
            }

            $logs[$item->id] = $this->logsSince($lastLog);
            $first = $logs[$item->id]->first();
            if ($item->action === 'created' && $first?->action === 'created') {
                $created[$item->id] = $first->subject_id;
            }
        }

        return ['created' => $created, 'logs' => $logs];
    }

    /**
     * Sends a stored change through its route as $actor, skipping the route middleware (this one included).
     *
     * @param  array<string, mixed>  $payload
     */
    private function runStored(ChangeRequest $item, User $actor, array $payload): void
    {
        $route = $this->router->getRoutes()->getByName($item->route_name)
            ?? throw new ReplayFailed($item, 'Jenis perubahan ini tidak dikenal lagi.');

        $files = [];
        foreach ($item->files ?? [] as $file) {
            if (! Storage::disk(Photo::DISK)->exists($file['path'])) {
                throw new ReplayFailed($item, 'File fotonya tidak ditemukan lagi.');
            }
            $files[$file['field']] = new UploadedFile(Storage::disk(Photo::DISK)->path($file['path']), $file['name'], $file['mime'], null, true);
        }

        $route = clone $route;
        $original = app('request');
        $replay = Request::create(route($item->route_name, $item->route_params ?? []), $item->method, $payload, [], $files, ['HTTP_ACCEPT' => 'text/html']);
        $replay->attributes->set(HoldForApproval::REPLAY, true);
        $replay->setUserResolver(fn () => $actor);
        if ($original->hasSession()) {
            $replay->setLaravelSession($original->session());
        }

        $this->swapRequest($replay);
        try {
            $route->bind($replay);
            $replay->setRouteResolver(fn () => $route);
            $this->router->substituteBindings($route);
            $this->router->substituteImplicitBindings($route);
            $this->deletesDirectly($item->route_name, $actor) ? $this->deleteBound($route) : $route->run();
        } finally {
            $this->swapRequest($original);
        }
    }

    /**
     * Asset deletes a technician makes as drafts: their controllers only let admins delete, so checking and
     * replaying the technician's drafts deletes the record itself. Approving runs the controller as the approver.
     */
    private function deletesDirectly(?string $routeName, User $actor): bool
    {
        return ! $actor->canDeleteNetwork() && in_array($routeName, self::ASSET_DELETE_ROUTES, true);
    }

    private function deleteBound(Route $route): Response
    {
        $this->routeModel($route)?->delete() ?? throw new ModelNotFoundException;

        return back();
    }

    /**
     * Runs the callback in a transaction that is always rolled back. Photo files are not written meanwhile:
     * they would outlive the rollback.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    private function dryRun(callable $callback): mixed
    {
        app()->instance(PhotoService::class, new class extends PhotoService
        {
            public function store(Model $owner, UploadedFile $file, ?string $caption, ?User $user): Photo
            {
                $this->checkDimensions($file);

                return new Photo;
            }

            public function replace(Photo $photo, UploadedFile $file): void
            {
                $this->checkDimensions($file);
            }
        });

        DB::beginTransaction();
        try {
            return $callback();
        } finally {
            DB::rollBack();
            app()->forgetInstance(PhotoService::class);
        }
    }

    /**
     * Replaces tokens in the reference fields (`*_id`, `id`, `connect_cables`) with the records the drafts created.
     *
     * @param  array<array-key, mixed>  $data
     * @param  array<int, int>  $created
     * @return array<array-key, mixed>
     */
    private function resolveTokens(array $data, array $created, bool $references = false): array
    {
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $data[$key] = $this->resolveTokens($value, $created, $key === 'connect_cables');
            } elseif (($references || $this->isReferenceKey($key)) && $this->isToken($value) && isset($created[-(int) $value])) {
                $data[$key] = $created[-(int) $value];
            }
        }

        return $data;
    }

    /**
     * @param  array<array-key, mixed>  $data
     */
    private function refersTo(array $data, int $token, bool $references = false): bool
    {
        foreach ($data as $key => $value) {
            if (is_array($value) ? $this->refersTo($value, $token, $key === 'connect_cables') : (($references || $this->isReferenceKey($key)) && $this->isToken($value) && (int) $value === $token)) {
                return true;
            }
        }

        return false;
    }

    private function isReferenceKey(int|string $key): bool
    {
        return $key === 'id' || (is_string($key) && str_ends_with($key, '_id'));
    }

    private function isToken(mixed $value): bool
    {
        return (is_int($value) && $value < 0) || (is_string($value) && preg_match('/^-\d+$/', $value) === 1);
    }

    // ---- What a change does, for the preview

    /**
     * Log entries written since $lastLog. "Created" entries carry no values, so they get the new record's.
     *
     * @return Collection<int, ActivityLog>
     */
    private function logsSince(int $lastLog): Collection
    {
        return ActivityLog::where('id', '>', $lastLog)->orderBy('id')->get()->each(function (ActivityLog $log) {
            $class = array_search($log->subject_type, ActivityLog::TYPES, true);
            if ($log->action === 'created' && ! $log->changes && $class && $log->subject_id) {
                $model = $class::withoutGlobalScopes()->find($log->subject_id);
                $log->changes = $model ? array_map(fn (array $v) => [null, $v[0]], $model->activityDeletePreview()) : null;
            }
        });
    }

    /**
     * @param  Collection<int, ActivityLog>  $logs
     * @return array<string, mixed>
     */
    private function logAttributes(Collection $logs): array
    {
        $first = $logs->first();

        return [
            'action' => $first->action,
            'subject_type' => $first->subject_type,
            // A new record's id from the dry run means nothing once rolled back.
            'subject_id' => $first->action === 'created' ? null : $first->subject_id,
            'subject_label' => $first->subject_label,
            'area_id' => $logs->firstWhere('area_id', '!==', null)?->area_id,
            'preview' => $logs->map(fn (ActivityLog $log) => [
                'action' => $log->action,
                'type' => $log->subject_type,
                'label' => $log->subject_label,
                'changes' => $log->changes,
            ])->all(),
        ];
    }

    /**
     * A photo: the file waits on disk; the photo itself is only made once approved.
     *
     * @return array<string, mixed>
     */
    private function photoAttributes(Request $request, array $payload, User $user): array
    {
        $ownerId = $payload['photoable_id'] ?? null;
        $owner = null;
        $draftOwner = null;
        if ($this->isToken($ownerId)) {
            $draftOwner = ChangeRequest::draftsOf($user)->findOrFail(-(int) $ownerId);
        } else {
            // Already validated and resolved through the area scope by the dry run.
            $owner = Relation::getMorphedModel($payload['photoable_type'])::findOrFail($ownerId);
        }

        $file = $request->file('photo');
        $caption = $request->input('caption');
        $label = 'Foto '.($owner ? $owner->activityLabel() : $draftOwner->subject_label);

        return [
            'action' => 'created',
            'subject_type' => 'photo',
            'subject_id' => null,
            'subject_label' => $label,
            'area_id' => $owner ? ($owner instanceof Area ? $owner->id : $owner->area_id) : $draftOwner->area_id,
            'files' => [[
                'field' => 'photo',
                'path' => $file->store(ChangeRequest::PENDING_DIR, Photo::DISK),
                'name' => $file->getClientOriginalName(),
                'mime' => $file->getMimeType(),
            ]],
            'preview' => [[
                'action' => 'created',
                'type' => 'photo',
                'label' => $label,
                'changes' => $caption ? ['caption' => [null, $caption]] : null,
            ]],
        ];
    }

    /**
     * A photo's new caption and/or image: a new image waits on disk like a new photo does.
     *
     * @return array<string, mixed>|null
     */
    private function photoUpdateAttributes(Request $request, array $payload, ?Model $photo): ?array
    {
        // The dry run changed the bound photo in memory; read it again as stored.
        $photo = $photo?->fresh() ?? throw new ModelNotFoundException;
        $file = $request->file('photo');

        $changes = [];
        $caption = array_key_exists('caption', $payload) ? ($payload['caption'] ?: null) : $photo->caption;
        if ($caption !== $photo->caption) {
            $changes['caption'] = [$photo->caption, $caption];
        }
        if ($file) {
            $changes['photo'] = ['Foto lama', 'Diganti foto baru'];
        }
        if (! $changes) {
            return null;
        }

        $label = $photo->activityLabel();

        return [
            'action' => 'updated',
            'subject_type' => 'photo',
            'subject_id' => $photo->id,
            'subject_label' => $label,
            'area_id' => $photo->activityAreaId(),
            'files' => $file ? [[
                'field' => 'photo',
                'path' => $file->store(ChangeRequest::PENDING_DIR, Photo::DISK),
                'name' => $file->getClientOriginalName(),
                'mime' => $file->getMimeType(),
            ]] : null,
            'preview' => [['action' => 'updated', 'type' => 'photo', 'label' => $label, 'changes' => $changes]],
        ];
    }

    /**
     * Records without an activity log of their own (cable cores): the preview compares the form with the record.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>|null
     */
    private function unloggedAttributes(array $input, ?Model $subject): ?array
    {
        // The dry run changed the bound model in memory; read it again as stored.
        $subject = $subject?->fresh();
        $changes = [];
        foreach ($subject?->getAttributes() ?? [] as $key => $old) {
            if (array_key_exists($key, $input) && ! is_array($new = $input[$key]) && (string) $old !== (string) $new) {
                $changes[$key] = [$old, $new];
            }
        }
        if (! $changes) {
            return null;
        }

        $type = ActivityLog::TYPES[$subject::class] ?? Str::snake(class_basename($subject));
        $label = $subject instanceof CableCore
            ? ($subject->cable?->code ?? 'Kabel #'.$subject->cable_id).' core '.$subject->number
            : (method_exists($subject, 'activityLabel') ? $subject->activityLabel() : '#'.$subject->getKey());

        return [
            'action' => 'updated',
            'subject_type' => $type,
            'subject_id' => $subject->getKey(),
            'subject_label' => $label,
            'area_id' => $subject instanceof CableCore ? $subject->cable?->area_id : $subject->getAttribute('area_id'),
            'preview' => [['action' => 'updated', 'type' => $type, 'label' => $label, 'changes' => $changes]],
        ];
    }

    // ---- Helpers

    /**
     * The form as sent, with tokens kept, without transport fields.
     *
     * @return array<string, mixed>
     */
    private function payload(Request $request): array
    {
        $payload = Arr::except($request->input(), ['_token', '_method', 'change_request_id', 'draft_id']);
        $user = $request->user();

        if ($user->area_id && ! array_key_exists('area_id', $payload) && in_array($request->route()->getName(), self::AREA_PINNED_ROUTES, true)) {
            $payload['area_id'] = $user->area_id;
        }

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private function close(ChangeBatch $batch, string $status, array $values): void
    {
        DB::transaction(function () use ($batch, $status, $values) {
            $batch->update(['status' => $status, ...$values]);
            $batch->items()->update(['status' => $status]);
        });
        $batch->deleteFiles();
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{lat: float, lng: float}|null
     */
    private function point(array $payload): ?array
    {
        $lat = $payload['lat'] ?? null;
        $lng = $payload['lng'] ?? null;

        return is_numeric($lat) && is_numeric($lng) ? ['lat' => (float) $lat, 'lng' => (float) $lng] : null;
    }

    private function succeeded(Response $response): bool
    {
        return empty($response->exception) && $response->getStatusCode() < 400;
    }

    private function routeModel(Route $route): ?Model
    {
        foreach ($route->parameters() as $value) {
            if ($value instanceof Model) {
                return $value;
            }
        }

        return null;
    }

    private function swapRequest(Request $request): void
    {
        app()->instance('request', $request);
        Facade::clearResolvedInstance('request');
    }
}
