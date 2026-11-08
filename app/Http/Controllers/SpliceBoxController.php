<?php

namespace App\Http\Controllers;

use App\Http\Requests\SpliceBoxRequest;
use App\Models\Cable;
use App\Models\SpliceBox;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Joint boxes and rosettes. The route parameter is {joint}, matching the morph alias.
 */
class SpliceBoxController extends Controller
{
    public function show(SpliceBox $joint): JsonResponse
    {
        $joint->load(['area:id,code,name', 'photos.user:id,name']);

        return response()->json([
            'joint' => $joint,
            'cables' => Cable::connectedTo($joint)->orderBy('code')->get(['id', 'code', 'type', 'cores', 'status', 'length_m']),
        ]);
    }

    public function store(SpliceBoxRequest $request): RedirectResponse
    {
        DB::transaction(fn () => $request->connectCables(SpliceBox::create($request->assetData())));

        return back();
    }

    public function update(SpliceBoxRequest $request, SpliceBox $joint): RedirectResponse
    {
        DB::transaction(function () use ($request, $joint) {
            $joint->update($request->assetData());
            $request->connectCables($joint);
        });

        return back();
    }

    public function destroy(Request $request, SpliceBox $joint): RedirectResponse
    {
        abort_unless($request->user()->canDeleteNetwork(), 403);

        $joint->delete();

        return back();
    }
}
