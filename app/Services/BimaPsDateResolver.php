<?php

namespace App\Services;

use App\Models\WorkOrder;
use App\Support\PsEligibility;

class BimaPsDateResolver
{
    private ?array $rules = null;

    public function __construct(private WorkOrderMappingResolver $mappings, private ExcelWorkOrderMapper $mapper) {}

    /** Caller holds the inbound lock; never carry a master lookup across preview and commit. */
    public function withRuleCache(callable $callback): mixed
    {
        $previous = $this->rules;
        $this->rules = [];
        try {
            return $callback();
        } finally {
            $this->rules = $previous;
        }
    }

    public static function usesDateModified(array $import): bool
    {
        $column = (int) ($import['mapping']['tgl_ps'] ?? 0);
        $header = $column > 0 ? ($import['headers'][$column - 1] ?? '') : '';

        return mb_strtoupper(preg_replace('/\s+/u', ' ', trim($header))) === 'DATE MODIFIED';
    }

    private function rule(array $data, ?WorkOrder $existing): array
    {
        $task = array_key_exists('task_bima', $data) ? $data['task_bima'] : $existing?->task_bima;
        $status = array_key_exists('status_bima', $data) ? $data['status_bima'] : $existing?->status_bima;
        $key = WorkOrderDerivedFields::status($task, $status);
        $cacheKey = json_encode([$task, $status], JSON_THROW_ON_ERROR);
        if (isset($this->rules[$cacheKey])) {
            return $this->rules[$cacheKey];
        }
        $sourceEligible = PsEligibility::sourceMatches($key, $task, $status);
        $mapping = $sourceEligible ? $this->mappings->resolve($task, $status) : null;
        $rule = ['status_order' => $key, 'id_mapping_order' => $mapping?->getKey(), 'mapping_order' => $mapping?->mapping_order,
            'eligible' => $mapping?->mapping_order === 'PS' && $sourceEligible];
        if ($this->rules !== null) {
            $this->rules[$cacheKey] = $rule;
        }

        return $rule;
    }

    public function fingerprint(array $data, ?WorkOrder $existing, array $import): ?string
    {
        return self::usesDateModified($import) ? hash('sha256', json_encode($this->rule($data, $existing), JSON_THROW_ON_ERROR)) : null;
    }

    public function prepare(array $data, ?WorkOrder $existing, array $raw, array $import): array
    {
        if (! self::usesDateModified($import)) {
            return ['data' => $data, 'fingerprint' => null];
        }
        $rule = $this->rule($data, $existing);
        if ($rule['eligible']) {
            $cell = $raw[(int) $import['mapping']['tgl_ps'] - 1] ?? ['value' => '', 'type' => 's', 'formula' => false];
            $date = $this->mapper->dateCell($cell, (bool) $import['date1904'], 'Date Modified (Tanggal PS)');
            if ($date !== null) {
                $data['tgl_ps'] = $date;
                $data['source_bima_fields']['TGL PS'] = $cell['value'];
            }
        }

        return ['data' => $data, 'fingerprint' => hash('sha256', json_encode($rule, JSON_THROW_ON_ERROR))];
    }
}
