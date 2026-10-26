<?php

namespace App\Http\Requests;

use App\Models\MappingOwnergrup;
use App\Support\ProgressSummary;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class WorkOrderListRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->active;
    }

    public function rules(): array
    {
        return ['q' => 'nullable|string|max:191', 'jenis_pekerjaan' => 'nullable|string|max:80', 'status' => 'nullable|string|max:80', 'branch' => 'nullable|integer',
            'region' => 'nullable|integer|exists:regions,id_regions', 'service' => 'nullable|string|max:255',
            'order_month' => 'nullable|date_format:Y-m|after_or_equal:2000-01|before_or_equal:2100-12',
            'owner_group' => ['nullable', Rule::in([...MappingOwnergrup::GROUPS, 'unmapped'])],
            'bucket' => ['nullable', Rule::in([...array_keys(ProgressSummary::GROUPS), 'other', 'cndc'])],
            'without_region' => 'nullable|boolean', 'unassigned' => 'nullable|boolean', 'modal' => 'nullable|boolean',
            'ps_date' => 'nullable|date_format:Y-m-d|after_or_equal:2000-01-01|before_or_equal:2100-12-31',
            'queue' => ['nullable', Rule::in(['all', 'today', 'ps_today', 'complete', 'closed', 'outstanding', 'issues', 'unupdated', 'waiting_bima', 'missing_ps_date', 'future_ps_date', 'unmapped', 'potential_ps', 'new_order'])]];
    }

    protected function prepareForValidation(): void
    {
        if (! $this->has('jenis_pekerjaan') && $this->has('job_type')) {
            $this->merge(['jenis_pekerjaan' => $this->input('job_type')]);
        }
    }
}
