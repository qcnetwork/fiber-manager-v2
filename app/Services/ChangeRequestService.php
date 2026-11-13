<?php

namespace App\Services;

use App\Http\Middleware\HoldForApproval;
use App\Models\ActivityLog;
use App\Models\Area;
use App\Models\CableCore;
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
 * Holds technicians' changes as change requests and applies them once approved.
 */
class ChangeRequestService
{
    /**
     * Deletes that also remove photo files, which a rolled-back transaction would not bring back.
     * They are not dry-run; the request stores what the record holds instead.
     */
    private const DELETE_WITHOUT_DRY_RUN = ['odcs.destroy', 'odps.destroy', 'joints.destroy', 'cables.destroy', 'photos.destroy'];

    /** Asset forms where users with an area don't send it; the approver may see all areas and then must. */
    private const AREA_PINNED_ROUTES = ['odcs.store', 'odcs.update', 'odps.store', 'odps.update', 'joints.store', 'joints.update', 'cables.store', 'cables.update'];

    /** Requests an approver can open on the map and change before approving. */
    public const EDITABLE_ROUTES = [
        'odcs.store', 'odcs.update', 'odps.store', 'odps.update', 'joints.store', 'joints.update',
        'cables.store', 'cables.update', 'customers.update', 'ports.update',
    ];

    public function __construct(private Router $router) {}

    /**
     * Turns a technician's change into a pending request instead of saving it.
     */
    public function hold(Request $request, Closure $next): Response
    {
        $name = (string) $request->route()->getName();
        $subject = $this->routeModel($request->route());
        // Taken before the dry run touches the bound model.
        $request->attributes->set('approval.base_updated_at', $subject?->updated_at?->copy());

        if ($request->isMethod('DELETE') && in_array($name, self::DELETE_WITHOUT_DRY_RUN, true)) {
            return $this->holdDelete($request, $subject);
        }

        $isPhoto = $name === 'photos.store';
        [$response, $logs] = $this->dryRun($request, $next, $isPhoto);

        // Validation errors and refusals go back to the technician as usual.
        if (! $this->succeeded($response)) {
            return $response;
        }

        if ($isPhoto) {
            return $this->holdPhoto($request);
        }

        $first = $logs->first();
        if (! $first) {
            return $this->holdUnlogged($request, $subject);
        }

        return $this->store($request, [
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
        ]);
    }

    /**
     * An approver saved a request after changing it on the map: the save itself applies it.
     */
    public function saveEdited(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $changeRequest = ChangeRequest::reviewableBy($user)->pending()->find($request->integer('change_request_id'));

        if (! $changeRequest || $changeRequest->route_name !== $request->route()->getName()) {
            return $next($request);
        }

        $lastLog = (int) ActivityLog::max('id');
        $response = $next($request);

        if ($this->succeeded($response)) {
            $this->markApproved($changeRequest, $user, 'Diedit penyetuju sebelum disetujui.', $lastLog);
            $changeRequest->deleteFiles();
            $request->session()->flash('notice', "Request #{$changeRequest->id} disetujui dengan perubahan.");
        }

        return $response;
    }

    /**
     * Applies a pending request by replaying it through its route as the approver.
     *
     * @return string|null why it could not be applied, or null when it was
     */
    public function approve(ChangeRequest $changeRequest, User $approver, ?string $note = null): ?string
    {
        $route = $this->router->getRoutes()->getByName($changeRequest->route_name);
        if (! $route) {
            return 'Jenis perubahan ini tidak dikenal lagi.';
        }

        $files = [];
        foreach ($changeRequest->files ?? [] as $file) {
            if (! Storage::disk(Photo::DISK)->exists($file['path'])) {
                return 'File foto request ini tidak ditemukan lagi.';
            }
            $files[$file['field']] = new UploadedFile(Storage::disk(Photo::DISK)->path($file['path']), $file['name'], $file['mime'], null, true);
        }

        $route = clone $route;
        $original = app('request');
        $replay = Request::create(
            route($changeRequest->route_name, $changeRequest->route_params ?? []),
            $changeRequest->method,
            $changeRequest->payload ?? [],
            [],
            $files,
            ['HTTP_ACCEPT' => 'text/html'],
        );
        $replay->attributes->set(HoldForApproval::REPLAY, true);
        $replay->setUserResolver(fn () => $approver);
        if ($original->hasSession()) {
            $replay->setLaravelSession($original->session());
        }

        $lastLog = (int) ActivityLog::max('id');
        $lastPhoto = (int) Photo::max('id');
        $this->swapRequest($replay);
        DB::beginTransaction();

        try {
            $route->bind($replay);
            $replay->setRouteResolver(fn () => $route);
            $this->router->substituteBindings($route);
            $this->router->substituteImplicitBindings($route);
            $route->run();

            // Photos keep the technician who took them.
            Photo::where('id', '>', $lastPhoto)->update(['user_id' => $changeRequest->user_id]);
            $this->markApproved($changeRequest, $approver, $note, $lastLog);
            DB::commit();
        } catch (ValidationException $e) {
            DB::rollBack();

            return collect($e->errors())->flatten()->unique()->implode(' ');
        } catch (ModelNotFoundException) {
            DB::rollBack();

            return 'Data yang diubah request ini sudah tidak ada.';
        } catch (AuthorizationException|HttpExceptionInterface) {
            DB::rollBack();

            return 'Perubahan ini tidak bisa diterapkan (data tidak ditemukan atau tidak diizinkan).';
        } catch (\Throwable $e) {
            DB::rollBack();

            throw $e;
        } finally {
            $this->swapRequest($original);
        }

        $changeRequest->deleteFiles();

        return null;
    }

    public function reject(ChangeRequest $changeRequest, User $reviewer, ?string $note): void
    {
        $changeRequest->update([
            'status' => ChangeRequest::REJECTED,
            'reviewer_id' => $reviewer->id,
            'reviewed_at' => now(),
            'review_note' => $note,
        ]);
        $changeRequest->deleteFiles();
    }

    public function cancel(ChangeRequest $changeRequest): void
    {
        $changeRequest->update(['status' => ChangeRequest::CANCELLED, 'reviewed_at' => now(), 'seen_at' => now()]);
        $changeRequest->deleteFiles();
    }

    /**
     * Runs the controller in a transaction that is always rolled back, collecting the log entries it wrote.
     *
     * @return array{0: Response, 1: Collection<int, ActivityLog>}
     */
    private function dryRun(Request $request, Closure $next, bool $withoutPhotoFiles): array
    {
        $lastLog = (int) ActivityLog::max('id');
        if ($withoutPhotoFiles) {
            app()->instance(PhotoService::class, new class extends PhotoService
            {
                // Checks the image but writes no files: they would outlive the rollback.
                public function store(Model $owner, UploadedFile $file, ?string $caption, ?User $user): Photo
                {
                    $this->checkDimensions($file);

                    return new Photo;
                }
            });
        }

        DB::beginTransaction();
        try {
            $response = $next($request);
            $logs = ActivityLog::where('id', '>', $lastLog)->orderBy('id')->get()->each(function (ActivityLog $log) {
                // "Created" entries carry no values; read them from the new record before it is rolled back.
                $class = array_search($log->subject_type, ActivityLog::TYPES, true);
                if ($log->action === 'created' && ! $log->changes && $class && $log->subject_id) {
                    $model = $class::withoutGlobalScopes()->find($log->subject_id);
                    $log->changes = $model ? array_map(fn (array $v) => [null, $v[0]], $model->activityDeletePreview()) : null;
                }
            });
        } finally {
            DB::rollBack();
            if ($withoutPhotoFiles) {
                app()->forgetInstance(PhotoService::class);
            }
        }

        return [$response, $logs];
    }

    private function holdPhoto(Request $request): Response
    {
        // Already validated and resolved through the area scope by the dry run.
        $owner = Relation::getMorphedModel($request->input('photoable_type'))::findOrFail($request->input('photoable_id'));
        $file = $request->file('photo');
        $caption = $request->input('caption');
        $path = $file->store(ChangeRequest::PENDING_DIR, Photo::DISK);
        $label = 'Foto '.$owner->activityLabel();

        return $this->store($request, [
            'action' => 'created',
            'subject_type' => 'photo',
            'subject_id' => null,
            'subject_label' => $label,
            'area_id' => $owner instanceof Area ? $owner->id : $owner->area_id,
            'files' => [['field' => 'photo', 'path' => $path, 'name' => $file->getClientOriginalName(), 'mime' => $file->getMimeType()]],
            'preview' => [[
                'action' => 'created',
                'type' => 'photo',
                'label' => $label,
                'changes' => $caption ? ['caption' => [null, $caption]] : null,
            ]],
        ]);
    }

    /**
     * Records without an activity log of their own (cable cores): the preview compares the form with the record.
     */
    private function holdUnlogged(Request $request, ?Model $subject): Response
    {
        // The dry run changed the bound model in memory; read it again as stored.
        $subject = $subject?->fresh();
        $changes = [];
        foreach ($subject?->getAttributes() ?? [] as $key => $old) {
            if ($request->exists($key) && ! is_array($new = $request->input($key)) && (string) $old !== (string) $new) {
                $changes[$key] = [$old, $new];
            }
        }
        if (! $changes) {
            return back()->with('notice', 'Tidak ada perubahan untuk dikirim.');
        }

        $type = ActivityLog::TYPES[$subject::class] ?? Str::snake(class_basename($subject));
        $label = $subject instanceof CableCore
            ? ($subject->cable?->code ?? 'Kabel #'.$subject->cable_id).' core '.$subject->number
            : (method_exists($subject, 'activityLabel') ? $subject->activityLabel() : '#'.$subject->getKey());

        return $this->store($request, [
            'action' => 'updated',
            'subject_type' => $type,
            'subject_id' => $subject->getKey(),
            'subject_label' => $label,
            'area_id' => $subject instanceof CableCore ? $subject->cable?->area_id : $subject->getAttribute('area_id'),
            'preview' => [['action' => 'updated', 'type' => $type, 'label' => $label, 'changes' => $changes]],
        ]);
    }

    private function holdDelete(Request $request, ?Model $subject): Response
    {
        abort_if($subject === null, 404);

        if ($subject instanceof Photo) {
            $owner = $subject->photoable;
            abort_if($owner === null || ($owner instanceof Area && ! $request->user()->canAccessArea($owner->id)), 404);
            // Technicians may only ask to remove photos they took themselves.
            abort_unless($subject->user_id === $request->user()->id, 403);
        }

        $type = ActivityLog::TYPES[$subject::class];

        return $this->store($request, [
            'action' => 'deleted',
            'subject_type' => $type,
            'subject_id' => $subject->getKey(),
            'subject_label' => $subject->activityLabel(),
            'area_id' => $subject->activityAreaId(),
            'preview' => [[
                'action' => 'deleted',
                'type' => $type,
                'label' => $subject->activityLabel(),
                'changes' => $subject->activityDeletePreview(),
            ]],
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function store(Request $request, array $attributes): Response
    {
        $user = $request->user();
        $name = $request->route()->getName();
        $payload = Arr::except($request->input(), ['_token', '_method', 'change_request_id']);

        if ($user->area_id && ! array_key_exists('area_id', $payload) && in_array($name, self::AREA_PINNED_ROUTES, true)) {
            $payload['area_id'] = $user->area_id;
        }

        ChangeRequest::create([
            ...$attributes,
            'area_id' => $attributes['area_id'] ?? $user->area_id,
            'user_id' => $user->id,
            'route_name' => $name,
            'route_params' => $request->route()->originalParameters(),
            'method' => $request->method(),
            'payload' => $payload,
            'base_updated_at' => $request->attributes->get('approval.base_updated_at'),
            'status' => ChangeRequest::PENDING,
        ]);

        return back()->with('notice', 'Request dikirim dan menunggu persetujuan admin.');
    }

    /**
     * Marks the request approved and notes it on the log entries the change wrote.
     */
    private function markApproved(ChangeRequest $changeRequest, User $reviewer, ?string $note, int $lastLog): void
    {
        $from = "Request #{$changeRequest->id} dari ".($changeRequest->user?->name ?? 'pengguna terhapus');
        ActivityLog::where('id', '>', $lastLog)->get()->each(function (ActivityLog $log) use ($from) {
            $log->update(['changes' => [...($log->changes ?? []), 'request' => [null, $from]]]);
        });

        $changeRequest->update([
            'status' => ChangeRequest::APPROVED,
            'reviewer_id' => $reviewer->id,
            'reviewed_at' => now(),
            'review_note' => $note,
        ]);
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
