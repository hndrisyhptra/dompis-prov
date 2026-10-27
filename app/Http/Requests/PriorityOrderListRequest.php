<?php

namespace App\Http\Requests;

use App\Models\MappingOwnergrup;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PriorityOrderListRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('priority-orders') ?? false;
    }

    public function rules(): array
    {
        return ['q' => 'nullable|string|max:191', 'branch' => 'nullable|integer',
            'unassigned' => 'nullable|boolean|prohibits:branch',
            'source' => ['nullable', Rule::in($this->user()->role->areaScope() ? ['area'] : ['all', 'area', 'branch'])],
            'state' => ['nullable', Rule::in(['all', 'outstanding', 'issues', 'waiting_bima', 'closed'])],
            'sort' => ['nullable', Rule::in(['selected', 'age', 'updated'])],
            'owner_group' => ['nullable', Rule::in([...MappingOwnergrup::GROUPS, 'unmapped'])],
            'bima_status' => 'nullable|string|max:255', 'bima_task' => 'nullable|string|max:255',
            'bima_status_missing' => 'nullable|boolean|prohibits:bima_status',
            'bima_task_missing' => 'nullable|boolean|prohibits:bima_task']
            + ($this->routeIs('priorities.monitoring') ? ['tab' => ['nullable', Rule::in(['branch', 'owner-group', 'issues'])]] : []);
    }

    public function messages(): array
    {
        return ['unassigned.prohibits' => 'Pilih satu cakupan: Branch tertentu atau hanya order belum ditugaskan.'];
    }
}
