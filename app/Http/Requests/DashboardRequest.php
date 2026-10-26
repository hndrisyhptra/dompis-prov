<?php

namespace App\Http\Requests;

use App\Enums\Role;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class DashboardRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->active && $this->user()->role !== Role::Bot;
    }

    public function rules(): array
    {
        $area = $this->routeIs('area.*');

        return ['branch' => 'nullable|integer', 'jenis_pekerjaan' => 'nullable|string|max:80'] + ($area
            ? ['region' => 'nullable|integer|exists:regions,id_regions', 'service' => 'nullable|string|max:255', 'month' => 'nullable|date_format:Y-m|after_or_equal:2000-01|before_or_equal:2100-12']
            : ['days' => ['nullable', Rule::in([7, 14, 30])]]);
    }

    protected function prepareForValidation(): void
    {
        if (! $this->has('jenis_pekerjaan') && $this->has('job_type')) {
            $this->merge(['jenis_pekerjaan' => $this->input('job_type')]);
        }
    }
}
