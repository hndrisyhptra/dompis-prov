<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Models\MappingOwnergrup;
use App\Models\Region;
use App\Services\MasterDataService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class MasterDataController extends Controller
{
    public function __construct(private MasterDataService $service) {}

    public function index(Request $request, string $masterType)
    {
        $definition = $this->service->definition($masterType);
        $filters = $request->validate(['q' => 'nullable|string|max:100', 'status' => ['nullable', Rule::in(['active', 'inactive'])], 'category' => ['nullable', Rule::in($masterType === 'mapping-orders' ? MasterDataService::ORDER_CATEGORIES : MappingOwnergrup::GROUPS)],
            'region' => 'nullable|integer|exists:regions,id_regions', 'branch' => 'nullable|integer|exists:branches,id_branches']);
        $query = $definition['model']::query()->when($filters['q'] ?? null, fn ($q, $value) => $q->where(fn ($q) => $q->where($definition['value'] ?? 'name', 'like', '%'.$value.'%')->orWhere($definition['code'], 'like', '%'.$value.'%')))
            ->when($filters['status'] ?? null, fn ($q, $value) => $q->where($definition['active'], $value === 'active'));
        if (in_array($masterType, ['branches', 'service-areas'], true)) {
            $query->with(['region' => fn ($q) => $q->withTrashed()])->when($filters['region'] ?? null, fn ($q, $id) => $q->where($masterType === 'branches' ? 'region_id' : 'id_regions', $id));
        }
        if ($masterType === 'service-areas') {
            $query->with(['branch' => fn ($q) => $q->withTrashed()])->when($filters['branch'] ?? null, fn ($q, $id) => $q->where('id_branches', $id));
        }
        if (isset($definition['value'])) {
            $query->when($filters['category'] ?? null, fn ($q, $category) => $q->where($definition['value'], $category));
        }

        return view('master-data.index', $this->context($masterType) + ['records' => $query->orderBy($definition['code'])->paginate(25)->withQueryString(), 'filters' => $filters]);
    }

    public function create(string $masterType)
    {
        $definition = $this->service->definition($masterType);

        return view(isset($definition['value']) ? 'master-data.mapping-form' : 'master-data.form', $this->context($masterType) + ['record' => new $definition['model'], 'version' => null]);
    }

    public function edit(string $masterType, int $record)
    {
        $record = $this->find($masterType, $record);

        return view(isset($this->service->definition($masterType)['value']) ? 'master-data.mapping-form' : 'master-data.form', $this->context($masterType) + ['record' => $record, 'version' => $this->service->version($record)]);
    }

    public function store(Request $request, string $masterType)
    {
        $record = $this->service->save($request->user(), $masterType, $this->validated($request, $masterType));

        return redirect()->route('master-data.edit', [$masterType, $record->getKey()])->with('status', $this->service->definition($masterType)['label'].' berhasil ditambahkan.');
    }

    public function update(Request $request, string $masterType, int $record)
    {
        $target = $this->find($masterType, $record);
        $this->service->save($request->user(), $masterType, $this->validated($request, $masterType, $target), $record);

        return redirect()->route('master-data.edit', [$masterType, $record])->with('status', 'Perubahan '.$this->service->definition($masterType)['label'].' disimpan.');
    }

    public function confirmDelete(string $masterType, int $record)
    {
        $record = $this->find($masterType, $record);

        return view('master-data.delete', $this->context($masterType) + ['record' => $record, 'version' => $this->service->version($record), 'usage' => $this->service->usage($masterType, $record)]);
    }

    public function destroy(Request $request, string $masterType, int $record)
    {
        $this->service->definition($masterType);
        $data = $request->validate(['version' => 'required|string|size:64', 'confirmed' => 'required|accepted'], ['confirmed.required' => 'Konfirmasi penghapusan terlebih dahulu.', 'confirmed.accepted' => 'Konfirmasi penghapusan terlebih dahulu.']);
        $this->service->delete($request->user(), $masterType, $record, $data['version']);

        return redirect()->route('master-data.index', $masterType)->with('status', isset($this->service->definition($masterType)['value']) ? 'Mapping yang tidak digunakan berhasil dihapus.' : 'Data dihapus dari daftar aktif dan diarsipkan.');
    }

    private function find(string $type, int $id): Model
    {
        return $this->service->definition($type)['model']::findOrFail($id);
    }

    private function context(string $type): array
    {
        return ['masterType' => $type, 'definition' => $this->service->definition($type), 'types' => MasterDataService::TYPES,
            'categories' => $type === 'mapping-orders' ? MasterDataService::ORDER_CATEGORIES : MappingOwnergrup::GROUPS,
            'regions' => Region::orderBy('name')->get(), 'branches' => Branch::with('region')->orderBy('name')->get()];
    }

    private function validated(Request $request, string $type, ?Model $record = null): array
    {
        $definition = $this->service->definition($type);
        $code = $definition['code'];
        if (isset($definition['value'])) {
            if (is_string($request->input($code))) {
                $request->merge([$code => trim($request->input($code))]);
            }
            $rules = [$code => ['required', 'string', 'max:'.($type === 'mapping-orders' ? 511 : 255), Rule::unique((new $definition['model'])->getTable(), $code)->ignore($record?->getKey(), (new $definition['model'])->getKeyName())],
                $definition['value'] => ['required', Rule::in($type === 'mapping-orders' ? MasterDataService::ORDER_CATEGORIES : MappingOwnergrup::GROUPS)], 'active' => 'required|boolean'];
            if ($record) {
                $rules['version'] = 'required|string|size:64';
            }

            return $request->validate($rules, ['required' => ':attribute wajib diisi.', 'unique' => ':attribute sudah digunakan.', 'in' => 'Pilih kategori mapping yang tersedia.', 'max.string' => ':attribute maksimal :max karakter.'],
                [$code => $definition['code_label'], $definition['value'] => $definition['value_label']]);
        }
        foreach ([$code, 'name'] as $field) {
            $value = $request->input($field);
            if (is_string($value)) {
                $request->merge([$field => $field === $code ? strtoupper(trim($value)) : trim($value)]);
            }
        }
        $rules = [$code => ['required', 'string', 'max:'.($type === 'branches' ? 40 : ($type === 'service-areas' ? 20 : 255)),
            'regex:/\A[A-Z0-9][A-Z0-9_-]*\z/', Rule::unique((new $definition['model'])->getTable(), $code)->ignore($record?->getKey(), (new $definition['model'])->getKeyName())],
            'name' => 'required|string|max:'.($type === 'service-areas' ? 100 : 255), $definition['active'] => 'required|boolean'];
        if ($record) {
            $rules['version'] = 'required|string|size:64';
        }
        if ($type === 'branches') {
            $rules['region_id'] = ['required', 'integer', Rule::exists('regions', 'id_regions')->whereNull('deleted_at')];
        } elseif ($type === 'service-areas') {
            $rules['id_regions'] = ['nullable', 'required_if:is_active,1', 'integer', Rule::exists('regions', 'id_regions')->whereNull('deleted_at')];
            $rules['id_branches'] = ['nullable', 'required_if:is_active,1', 'integer', Rule::exists('branches', 'id_branches')->whereNull('deleted_at')];
        }

        return $request->validate($rules, ['required' => ':attribute wajib diisi.', 'required_if' => ':attribute wajib untuk Service Area aktif.',
            'unique' => ':attribute sudah digunakan.', 'regex' => ':attribute hanya boleh berisi huruf, angka, tanda hubung dan garis bawah.',
            'exists' => ':attribute tidak tersedia.', 'max.string' => ':attribute maksimal :max karakter.', 'boolean' => 'Pilih status Aktif atau Nonaktif.'],
            [$code => $type === 'service-areas' ? 'Kode STO' : 'Kode', 'name' => 'Nama', 'region_id' => 'Region', 'id_regions' => 'Region', 'id_branches' => 'Branch', 'active' => 'Status', 'is_active' => 'Status']);
    }
}
