<?php

namespace App\Http\Requests;

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
            'source' => ['nullable', Rule::in($this->user()->role->areaScope() ? ['area'] : ['all', 'area', 'branch'])],
            'state' => ['nullable', Rule::in(['all', 'outstanding', 'issues', 'waiting_bima', 'closed'])],
            'sort' => ['nullable', Rule::in(['selected', 'age', 'updated'])]];
    }
}
