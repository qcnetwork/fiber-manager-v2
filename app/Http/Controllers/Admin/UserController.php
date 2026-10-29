<?php

namespace App\Http\Controllers\Admin;

use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Models\Area;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Inertia\Response;

class UserController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('admin/Users', [
            'users' => User::with('area:id,code,name')->orderBy('name')->get(),
            'areas' => Area::orderBy('name')->get(['id', 'code', 'name']),
            'roles' => Role::options(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        User::create([
            ...$this->validated($request),
            'email_verified_at' => now(),
        ]);

        return back();
    }

    public function update(Request $request, User $user): RedirectResponse
    {
        $data = $this->validated($request, $user);

        if (blank($data['password'] ?? null)) {
            unset($data['password']);
        }

        $user->update($data);

        return back();
    }

    public function destroy(Request $request, User $user): RedirectResponse
    {
        abort_if($user->is($request->user()), 422, 'Tidak bisa menghapus akun sendiri.');

        $user->delete();

        return back();
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, ?User $user = null): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'username' => ['nullable', 'alpha_dash', 'max:50', Rule::unique('users', 'username')->ignore($user)],
            'email' => ['required_without:username', 'nullable', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user)],
            'role' => ['required', Rule::enum(Role::class)],
            // No area = works across all areas.
            'area_id' => ['nullable', 'exists:areas,id'],
            'password' => [$user ? 'nullable' : 'required', Password::defaults()],
        ]);

        if ($data['role'] === Role::SuperAdmin->value) {
            $data['area_id'] = null;
        }

        return $data;
    }
}
