<?php

namespace App\Http\Requests;

use App\Models\MappingOwnergrup;
use App\Services\MappingOrderAnalyticsService;
use App\Support\OrderAging;
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
            'mapping_order' => ['nullable', Rule::in([...MappingOrderAnalyticsService::CATEGORIES, 'unmapped', 'open_stuck'])],
            'sto' => 'nullable|string|max:255', 'sto_missing' => 'nullable|boolean|prohibits:sto',
            'aging_bucket' => ['nullable', 'required_with:aging_on', Rule::in(array_keys(OrderAging::LABELS))],
            'aging_on' => 'nullable|required_with:aging_bucket|date_format:Y-m-d|after_or_equal:2000-01-01|before_or_equal:2100-12-31',
            'bucket' => ['nullable', Rule::in([...array_keys(ProgressSummary::GROUPS), 'other', 'cndc'])],
            'without_region' => 'nullable|boolean', 'unassigned' => 'nullable|boolean', 'modal' => 'nullable|boolean',
            'ps_date' => 'nullable|date_format:Y-m-d|after_or_equal:2000-01-01|before_or_equal:2100-12-31',
            'ps_from' => 'nullable|required_with:ps_to|date_format:Y-m-d|after_or_equal:2000-01-01|before_or_equal:2100-12-31|prohibits:ps_date',
            'ps_to' => 'nullable|required_with:ps_from|date_format:Y-m-d|after_or_equal:ps_from|before_or_equal:2100-12-31|prohibits:ps_date',
            'queue' => ['nullable', Rule::in(['all', 'today', 'ps_today', 'complete', 'closed', 'outstanding', 'issues', 'unupdated', 'waiting_bima', 'missing_ps_date', 'future_ps_date', 'unmapped', 'potential_ps', 'new_order'])]];
    }

    protected function prepareForValidation(): void
    {
        if (! $this->has('jenis_pekerjaan') && $this->has('job_type')) {
            $this->merge(['jenis_pekerjaan' => $this->input('job_type')]);
        }
    }
}
