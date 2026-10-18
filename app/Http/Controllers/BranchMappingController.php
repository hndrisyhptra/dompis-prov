<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\BranchMapping;
use App\Models\Region;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class BranchMappingController extends Controller
{
    public function index()
    {
        Gate::authorize('sync');

        return view('admin.mappings', ['regions' => Region::where('is_active', true)->orderBy('name')->get(), 'branches' => Branch::where('active', true)->orderBy('name')->get(), 'mappings' => BranchMapping::with('branch')->orderBy('bima_branch')->paginate(30)]);
    }

    public function storeBranch(Request $request)
    {
        Gate::authorize('sync');
        $data = $request->validate(['code' => 'required|string|max:40|alpha_dash|unique:branches', 'name' => 'required|string|max:255', 'region_id' => 'required|integer|exists:regions,id_regions']);
        $region = Region::where('is_active', true)->findOrFail($data['region_id']);
        $data['regional'] = $region->name;
        DB::transaction(function () use ($data, $request) {
            $branch = Branch::create($data);
            AuditLog::create(['id_users' => $request->user()->getKey(), 'action' => 'branch.created', 'entity_type' => 'branch', 'id_entity' => $branch->getKey()]);
        });

        return back()->with('status', 'Branch ditambahkan.');
    }

    public function store(Request $request)
    {
        Gate::authorize('sync');
        $data = $request->validate(['bima_branch' => 'required|string|max:120', 'sto_code' => 'nullable|string|max:40', 'id_branches' => 'required|exists:branches,id_branches']);
        $data['bima_branch'] = mb_strtoupper(trim($data['bima_branch']));
        $data['sto_code'] = mb_strtoupper(trim($data['sto_code'] ?? ''));
        abort_unless(Branch::whereKey($data['id_branches'])->where('active', true)->exists(), 422);
        DB::transaction(function () use ($request, $data) {
            $mapping = BranchMapping::updateOrCreate(['bima_branch' => $data['bima_branch'], 'sto_code' => $data['sto_code']], ['id_branches' => $data['id_branches'], 'enabled' => true]);
            AuditLog::create(['id_users' => $request->user()->getKey(), 'action' => 'branch_mapping.saved', 'entity_type' => 'branch_mapping', 'id_entity' => $mapping->getKey(), 'metadata' => ['id_branches' => (int) $data['id_branches']]]);
        });

        return back()->with('status', 'Mapping disimpan. Perubahan branch WO yang sudah diimpor tetap memerlukan review.');
    }
}
