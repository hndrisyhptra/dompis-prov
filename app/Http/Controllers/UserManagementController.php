<?php

namespace App\Http\Controllers;

use App\Enums\Role;
use App\Http\Requests\SaveUserRequest;
use App\Models\Branch;
use App\Models\Region;
use App\Models\ServiceArea;
use App\Models\User;
use App\Services\UserManagementService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class UserManagementController extends Controller
{
    public function index(Request $request)
    {
        Gate::authorize('administer');
        $filters = $request->validate(['q' => 'nullable|string|max:120', 'role' => ['nullable', Rule::in(array_map(fn ($role) => $role->value, $this->roles()))], 'active' => ['nullable', Rule::in(['1', '0'])]]);
        $users = User::where('role', '!=', Role::Bot->value)->with(['branches', 'region', 'serviceArea'])
            ->when($filters['q'] ?? null, fn ($query, $q) => $query->where(fn ($query) => $query->where('name', 'like', '%'.$q.'%')->orWhere('username', 'like', '%'.$q.'%')->orWhere('email', 'like', '%'.$q.'%')))
            ->when($filters['role'] ?? null, fn ($query, $role) => $query->where('role', $role))
            ->when(isset($filters['active']), fn ($query) => $query->where('active', (bool) $filters['active']))
            ->orderByDesc('id_users')->paginate(15)->withQueryString();

        return view('admin.users.index', ['users' => $users, 'roles' => $this->roles()]);
    }

    public function create()
    {
        Gate::authorize('administer');

        return view('admin.users.form', $this->options(new User));
    }

    public function store(SaveUserRequest $request, UserManagementService $service)
    {
        $user = $service->save($request->user(), $request->validated());

        return redirect()->route('users.edit', $user)->with('status', 'User berhasil ditambahkan.');
    }

    public function edit(User $user)
    {
        Gate::authorize('administer');
        abort_if($user->role === Role::Bot, 403);

        return view('admin.users.form', $this->options($user->load('branches')));
    }

    public function update(SaveUserRequest $request, User $user, UserManagementService $service)
    {
        $service->save($request->user(), $request->validated(), $user);

        return redirect()->route('users.edit', $user)->with('status', 'Perubahan user berhasil disimpan.');
    }

    private function roles(): array
    {
        return array_values(array_filter(Role::cases(), fn ($role) => $role !== Role::Bot));
    }

    private function options(User $user): array
    {
        return ['managedUser' => $user, 'roles' => $this->roles(),
            'branches' => Branch::with('region')->orderBy('name')->get(),
            'regions' => Region::orderBy('name')->get(),
            'serviceAreas' => ServiceArea::withTrashed()->with(['branch', 'region'])->where(fn ($query) => $query->where(fn ($query) => $query->whereNull('deleted_at')->whereNotNull('id_branches')->where('is_active', true)
                ->whereHas('branch', fn ($query) => $query->where('active', true))->whereHas('region', fn ($query) => $query->where('is_active', true)))
                ->when($user->id_service_areas, fn ($query) => $query->orWhere('id_service_areas', $user->id_service_areas)))->orderBy('workzone')->get()];
    }
}
