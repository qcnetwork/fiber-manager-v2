<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Area;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class AreaController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('admin/Areas', [
            'areas' => Area::withCount(['users', 'odcs', 'odps'])->orderBy('name')->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        Area::create($this->validated($request));

        return back();
    }

    public function update(Request $request, Area $area): RedirectResponse
    {
        $area->update($this->validated($request, $area));

        return back();
    }

    public function destroy(Area $area): RedirectResponse
    {
        if ($area->odcs()->exists() || $area->odps()->exists()) {
            return back()->withErrors(['area' => 'Area masih memiliki ODC/ODP dan tidak bisa dihapus.']);
        }

        $area->delete();

        return back();
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, ?Area $area = null): array
    {
        return $request->validate([
            'code' => ['required', 'string', 'max:20', Rule::unique('areas', 'code')->ignore($area)],
            'name' => ['required', 'string', 'max:255'],
            'center_lat' => ['nullable', 'numeric', 'between:-90,90'],
            'center_lng' => ['nullable', 'numeric', 'between:-180,180'],
        ]);
    }
}
