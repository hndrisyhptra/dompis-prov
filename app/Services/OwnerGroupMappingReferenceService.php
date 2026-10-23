<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\MappingOwnergrup;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

class OwnerGroupMappingReferenceService
{
    public function reference(): array
    {
        return json_decode(file_get_contents(database_path('data/owner_group_mapping_20261021.json')), true, flags: JSON_THROW_ON_ERROR);
    }

    private function validatedRules(array $reference): array
    {
        $rules = $reference['rules'] ?? [];
        $seen = [];
        if (! is_array($rules) || ! $rules) {
            throw new InvalidArgumentException('Referensi mapping Owner Group kosong.');
        }
        foreach ($rules as $rule) {
            $owner = $rule['owner_grup'] ?? null;
            if (! is_string($owner) || $owner === '' || trim($owner) !== $owner || mb_strlen($owner) > 255 || isset($seen[$owner])
                || ! in_array($rule['kelompok_ownergrup'] ?? null, MappingOwnergrup::GROUPS, true)) {
                throw new InvalidArgumentException('Referensi Owner Group tidak valid atau memiliki kunci duplikat. Tidak ada perubahan disimpan.');
            }
            $seen[$owner] = true;
        }

        return $rules;
    }

    public function preview(array $reference): array
    {
        return $this->syncRules($this->validatedRules($reference), false)['stats'];
    }

    public function apply(array $reference, User $actor): array
    {
        if (! $actor->active || ! $actor->role->sync()) {
            throw new InvalidArgumentException('Actor harus berupa akun Area aktif yang memiliki izin sinkronisasi.');
        }
        $rules = $this->validatedRules($reference);
        $lock = Cache::lock('olo:work-order-inbound', 240);
        if (! $lock->get()) {
            throw new RuntimeException('Import work order sedang berjalan. Coba terapkan mapping setelah import selesai.');
        }
        try {
            return DB::transaction(function () use ($rules, $actor, $reference) {
                $result = $this->syncRules($rules, true);
                AuditLog::create(['id_users' => $actor->getKey(), 'action' => 'owner_group_mapping.applied', 'entity_type' => 'owner_group_mapping_reference', 'id_entity' => 0,
                    'metadata' => ['source' => $reference['source'] ?? null, 'sha256' => $reference['sha256'] ?? null,
                        'stats' => $result['stats'], 'rule_changes' => $result['changes']]]);

                return $result['stats'];
            }, 3);
        } finally {
            $lock->release();
        }
    }

    private function syncRules(array $rules, bool $write): array
    {
        $stats = ['rules_inserted' => 0, 'rules_updated' => 0, 'rules_unchanged' => 0];
        $changes = [];
        foreach ($rules as $rule) {
            $query = MappingOwnergrup::where('owner_grup', $rule['owner_grup']);
            $mapping = ($write ? $query->lockForUpdate() : $query)->first();
            if (! $mapping) {
                if ($write) {
                    MappingOwnergrup::create(['owner_grup' => $rule['owner_grup'], 'kelompok_ownergrup' => $rule['kelompok_ownergrup'], 'active' => true]);
                }
                $stats['rules_inserted']++;
            } elseif ($mapping->kelompok_ownergrup !== $rule['kelompok_ownergrup'] || ! $mapping->active) {
                $changes[] = ['id_mapping_ownergrup' => $mapping->getKey(), 'before' => $mapping->only(['kelompok_ownergrup', 'active']),
                    'after' => ['kelompok_ownergrup' => $rule['kelompok_ownergrup'], 'active' => true]];
                if ($write) {
                    $mapping->update(['kelompok_ownergrup' => $rule['kelompok_ownergrup'], 'active' => true]);
                }
                $stats['rules_updated']++;
            } else {
                $stats['rules_unchanged']++;
            }
        }

        return compact('stats', 'changes');
    }
}
