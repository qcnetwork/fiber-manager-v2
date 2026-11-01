<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\Area;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class ActivityLogController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();
        abort_unless($user->canDeleteNetwork(), 403);

        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'user_id' => ['nullable', 'integer'],
            'type' => ['nullable', Rule::in([...array_values(ActivityLog::TYPES), 'import'])],
            'subject_id' => ['nullable', 'integer'],
            'action' => ['nullable', Rule::in(['created', 'updated', 'deleted', 'moved', 'imported'])],
            'date' => ['nullable', 'date_format:Y-m-d'],
        ]);

        $logs = ActivityLog::query()
            ->visibleTo($user)
            ->when($filters['q'] ?? null, fn ($q, $search) => $q->where('subject_label', 'like', "%{$search}%"))
            ->when($filters['user_id'] ?? null, fn ($q, $id) => $q->where('user_id', $id))
            ->when($filters['type'] ?? null, fn ($q, $type) => $q->where('subject_type', $type))
            ->when($filters['subject_id'] ?? null, fn ($q, $id) => $q->where('subject_id', $id))
            ->when($filters['action'] ?? null, fn ($q, $action) => $q->where('action', $action))
            ->when($filters['date'] ?? null, fn ($q, $date) => $q->whereDate('created_at', $date))
            ->latest('id')
            ->paginate(50)
            ->withQueryString();

        $areas = Area::whereIn('id', $logs->pluck('area_id')->filter()->unique())->pluck('code', 'id');

        return Inertia::render('ActivityLogs', [
            'logs' => $logs->through(fn (ActivityLog $log) => [
                ...$log->only('id', 'user_name', 'action', 'subject_type', 'subject_id', 'subject_label', 'changes', 'ip_address'),
                'created_at' => $log->created_at->toIso8601String(),
                'area' => $areas[$log->area_id] ?? null,
            ]),
            'filters' => (object) $filters,
            'users' => User::query()
                ->when(! $user->seesAllAreas(), fn ($q) => $q->where('area_id', $user->area_id))
                ->orderBy('name')
                ->get(['id', 'name']),
            'showArea' => $user->seesAllAreas(),
        ]);
    }
}
