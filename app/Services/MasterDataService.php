<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\MappingOrder;
use App\Models\MappingOwnergrup;
use App\Models\Region;
use App\Models\ServiceArea;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderPriority;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class MasterDataService
{
    public const TYPES = ['branches' => ['label' => 'Branch', 'model' => Branch::class, 'code' => 'code', 'active' => 'active'],
        'regions' => ['label' => 'Region', 'model' => Region::class, 'code' => 'code', 'active' => 'is_active'],
        'service-areas' => ['label' => 'Service Area', 'model' => ServiceArea::class, 'code' => 'workzone', 'active' => 'is_active'],
        'mapping-orders' => ['label' => 'Mapping Order', 'model' => MappingOrder::class, 'code' => 'status_order', 'active' => 'active', 'value' => 'mapping_order', 'code_label' => 'Status Order', 'value_label' => 'Kategori Mapping'],
        'mapping-owner-groups' => ['label' => 'Mapping Owner Grup', 'model' => MappingOwnergrup::class, 'code' => 'owner_grup', 'active' => 'active', 'value' => 'kelompok_ownergrup', 'code_label' => 'Owner Grup', 'value_label' => 'Kelompok Owner Grup']];

    public const ORDER_CATEGORIES = ['OPEN', 'STUCK', 'PS', 'CANCLWORK'];

    public function definition(string $type): array
    {
        abort_unless(isset(self::TYPES[$type]), 404);

        return self::TYPES[$type];
    }

    public function version(Model $record): string
    {
        return hash('sha256', json_encode($record->getRawOriginal(), JSON_THROW_ON_ERROR));
    }

    public function usage(string $type, Model $record): array
    {
        $id = $record->getKey();

        return array_filter(match ($type) {
            'regions' => ['Branch' => Branch::withTrashed()->where('region_id', $id)->count(),
                'Service Area' => ServiceArea::withTrashed()->where('id_regions', $id)->count(), 'User' => User::where('id_regions', $id)->count()],
            'branches' => ['Service Area' => ServiceArea::withTrashed()->where('id_branches', $id)->count(),
                'Penugasan user' => DB::table('user_branches')->where('id_branches', $id)->count(),
                'Work Order' => WorkOrder::where('id_branches', $id)->count(), 'Referensi routing' => DB::table('branch_mappings')->where('id_branches', $id)->count()],
            'service-areas' => ['User' => User::where('id_service_areas', $id)->count(),
                'Work Order' => $this->stoOrders($record->workzone)->count(), 'Referensi routing' => DB::table('branch_mappings')->whereRaw('UPPER(TRIM(sto_code)) = ?', [strtoupper($record->workzone)])->count()],
            'mapping-orders' => ['Work Order' => WorkOrder::where(fn ($q) => $q->where('id_mapping_order', $id)->orWhereIn('status_order', $this->orderKeys($record->status_order)))->count()],
            'mapping-owner-groups' => ['Work Order' => WorkOrder::whereRaw(DB::getDriverName() === 'mysql' ? 'BINARY owner_grup = BINARY ?' : 'owner_grup = ?', [$record->owner_grup])->count()],
        }, fn ($count) => $count > 0);
    }

    private function stoOrders(string $workzone)
    {
        return WorkOrder::whereRaw('UPPER(TRIM(sto)) = ?', [strtoupper($workzone)]);
    }

    public function save(User $actor, string $type, array $data, ?int $id = null): Model
    {
        return $this->mutate($actor, function () use ($actor, $type, $data, $id) {
            $definition = $this->definition($type);
            if (isset($definition['value'])) {
                return $this->saveMapping($actor, $type, $data, $id);
            }
            $record = $id ? $definition['model']::lockForUpdate()->findOrFail($id) : new $definition['model'];
            if ($id) {
                abort_unless(hash_equals($this->version($record), $data['version']), 409, 'Data sudah berubah. Muat ulang halaman sebelum menyimpan.');
            }
            $before = $record->exists ? $record->getAttributes() : null;
            $code = $definition['code'];
            // Enforce uniqueness again inside the serialized transaction, including archived codes.
            if ($definition['model']::withTrashed()->whereRaw('UPPER('.$code.') = ?', [strtoupper($data[$code])])->when($id, fn ($q) => $q->whereKeyNot($id))->exists()) {
                throw ValidationException::withMessages([$code => 'Kode sudah digunakan, termasuk pada data yang diarsipkan. Gunakan kode lain.']);
            }
            $values = [$code => $data[$code], 'name' => $data['name'], $definition['active'] => (bool) $data[$definition['active']]];
            if ($type === 'branches') {
                $region = Region::lockForUpdate()->findOrFail($data['region_id']);
                if ($values['active'] && ! $region->is_active) {
                    throw ValidationException::withMessages(['region_id' => 'Branch aktif memerlukan Region aktif.']);
                }
                if ($id && (int) $record->region_id !== (int) $region->getKey()) {
                    $conflictingStos = ServiceArea::withTrashed()->where('id_branches', $id)->where(fn ($q) => $q->whereNull('id_regions')->orWhere('id_regions', '!=', $region->getKey()))->exists();
                    $conflictingUsers = DB::table('users')->join('user_branches', 'users.id_users', '=', 'user_branches.id_users')->where('user_branches.id_branches', $id)->whereNotNull('users.id_regions')->where('users.id_regions', '!=', $region->getKey())->exists();
                    if ($conflictingStos || $conflictingUsers) {
                        throw ValidationException::withMessages(['region_id' => 'Region belum sesuai dengan Service Area atau lokasi utama user yang terhubung. Sesuaikan referensi tersebut terlebih dahulu.']);
                    }
                }
                if (! $values['active'] && $id && ServiceArea::where('id_branches', $id)->where('is_active', true)->exists()) {
                    throw ValidationException::withMessages(['active' => 'Nonaktifkan Service Area aktif pada Branch ini terlebih dahulu.']);
                }
                $values += ['region_id' => $region->getKey(), 'regional' => $region->name];
            } elseif ($type === 'regions') {
                if (! $values['is_active'] && $id && (Branch::where('region_id', $id)->where('active', true)->exists() || ServiceArea::where('id_regions', $id)->where('is_active', true)->exists())) {
                    throw ValidationException::withMessages(['is_active' => 'Nonaktifkan Branch dan Service Area aktif pada Region ini terlebih dahulu.']);
                }
            } else {
                $branch = ! empty($data['id_branches']) ? Branch::lockForUpdate()->findOrFail($data['id_branches']) : null;
                $region = ! empty($data['id_regions']) ? Region::lockForUpdate()->findOrFail($data['id_regions']) : null;
                if (($branch && (! $region || (int) $branch->region_id !== (int) $region->getKey())) || ($values['is_active'] && (! $branch?->active || ! $region?->is_active))) {
                    throw ValidationException::withMessages(['id_branches' => 'Pilih Branch dan Region yang saling sesuai. Service Area aktif memerlukan Branch dan Region aktif.']);
                }
                if ($id && $record->workzone !== $data['workzone'] && $this->usage($type, $record)) {
                    throw ValidationException::withMessages(['workzone' => 'Kode STO sedang digunakan. Kode tidak dapat diubah sebelum seluruh referensinya dilepas.']);
                }
                if ($id && ((int) $record->id_branches !== (int) $branch?->getKey() || (int) $record->id_regions !== (int) $region?->getKey())) {
                    if (User::where('id_service_areas', $id)->exists() || $this->stoOrders($record->workzone)->whereNotNull('id_branches')->where('id_branches', '!=', $branch?->getKey() ?? 0)->exists()) {
                        throw ValidationException::withMessages(['id_branches' => 'Mapping STO masih dipakai user atau Work Order pada Branch lain. Referensi yang sedang digunakan tidak dipindahkan otomatis.']);
                    }
                }
                $values += ['id_branches' => $branch?->getKey(), 'id_regions' => $region?->getKey()];
            }
            if ($type !== 'branches') {
                $values['id_users_updated_by'] = $actor->getKey();
                if (! $record->exists) {
                    $values['id_users_created_by'] = $actor->getKey();
                }
            }
            $created = ! $record->exists;
            $record->fill($values)->save();
            if ($type === 'regions') {
                // Only the legacy master label follows a Region rename; BIMA source attributes remain untouched.
                DB::table('branches')->where('region_id', $record->getKey())->update(['regional' => $record->name]);
            }
            AuditLog::create(['id_users' => $actor->getKey(), 'action' => 'master_data.'.($created ? 'created' : 'updated'), 'entity_type' => $type, 'id_entity' => $record->getKey(),
                'metadata' => ['before' => $before, 'after' => $record->getAttributes()]]);

            return $record;
        });
    }

    public function delete(User $actor, string $type, int $id, string $version): void
    {
        $this->mutate($actor, function () use ($actor, $type, $id, $version) {
            $definition = $this->definition($type);
            $record = $definition['model']::lockForUpdate()->findOrFail($id);
            abort_unless(hash_equals($this->version($record), $version), 409, 'Data sudah berubah. Periksa kembali sebelum menghapus.');
            if ($this->usage($type, $record) || ($type === 'branches' && WorkOrderPriority::where('id_branches', $id)->exists())) {
                throw ValidationException::withMessages(['delete' => 'Data masih digunakan dan tidak dapat dihapus. Periksa daftar referensi yang terhubung.']);
            }
            $before = $record->getAttributes();
            $record->delete();
            AuditLog::create(['id_users' => $actor->getKey(), 'action' => 'master_data.deleted', 'entity_type' => $type, 'id_entity' => $id,
                'metadata' => ['before' => $before, 'deletion' => isset($definition['value']) ? 'delete_unused_mapping' : 'soft_delete']]);
        });
    }

    private function orderKeys(string $key): array
    {
        return str_starts_with($key, '-') ? [$key, substr($key, 1)] : [$key];
    }

    private function saveMapping(User $actor, string $type, array $data, ?int $id): Model
    {
        $definition = $this->definition($type);
        $record = $id ? $definition['model']::lockForUpdate()->findOrFail($id) : new $definition['model'];
        if ($id) {
            abort_unless(hash_equals($this->version($record), $data['version']), 409, 'Data sudah berubah. Muat ulang halaman sebelum menyimpan.');
        }
        $code = $definition['code'];
        $value = $definition['value'];
        if ($definition['model']::where($code, $data[$code])->when($id, fn ($q) => $q->whereKeyNot($id))->exists()) {
            throw ValidationException::withMessages([$code => 'Kunci mapping sudah digunakan.']);
        }
        if ($id && $data[$code] !== $record->$code && $this->usage($type, $record)) {
            throw ValidationException::withMessages([$code => 'Kunci mapping masih digunakan Work Order. Ubah kategori/status aktif atau tambahkan aturan baru.']);
        }
        $before = $record->exists ? $record->getAttributes() : null;
        $record->fill([$code => $data[$code], $value => $data[$value], 'active' => (bool) $data['active']])->save();
        $reconciled = [];
        if ($type === 'mapping-orders') {
            $keys = array_unique([...$this->orderKeys($record->status_order), ...$this->orderKeys($before['status_order'] ?? $record->status_order)]);
            WorkOrder::where('source_system', 'BIMA')->where(fn ($q) => $q->where('id_mapping_order', $record->getKey())->orWhereIn('status_order', $keys))
                ->lockForUpdate()->chunkById(200, function ($orders) use (&$reconciled) {
                    foreach ($orders as $order) {
                        $mapping = app(WorkOrderMappingResolver::class)->resolve($order->task_bima, $order->status_bima);
                        if ($order->id_mapping_order !== $mapping?->getKey() || $order->mapping_order !== $mapping?->mapping_order) {
                            DB::table('work_orders')->where('id_work_orders', $order->getKey())->update(['id_mapping_order' => $mapping?->getKey(), 'mapping_order' => $mapping?->mapping_order]);
                            $reconciled[] = $order->getKey();
                        }
                    }
                }, 'id_work_orders');
        }
        AuditLog::create(['id_users' => $actor->getKey(), 'action' => 'master_data.'.($before ? 'updated' : 'created'), 'entity_type' => $type, 'id_entity' => $record->getKey(),
            'metadata' => ['before' => $before, 'after' => $record->getAttributes(), 'reconciled_work_order_ids' => $reconciled, 'reconciled_count' => count($reconciled)]]);

        return $record;
    }

    private function mutate(User $actor, callable $operation): mixed
    {
        Gate::forUser($actor->fresh())->authorize('manage-master-data');
        $lock = Cache::lock('olo:work-order-inbound', 240);
        if (! $lock->get()) {
            throw ValidationException::withMessages(['master_data' => 'Import atau pembaruan referensi sedang berjalan. Tunggu hingga selesai lalu coba lagi.']);
        }
        try {
            return DB::transaction(function () use ($actor, $operation) {
                Gate::forUser(User::lockForUpdate()->findOrFail($actor->getKey()))->authorize('manage-master-data');

                return $operation();
            }, 3);
        } finally {
            $lock->release();
        }
    }
}
