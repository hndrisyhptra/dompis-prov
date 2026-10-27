<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class PriorityBulkRequest extends FormRequest
{
    protected $redirectRoute = 'priorities.bulk.create';

    public function authorize(): bool
    {
        return $this->user()?->can('priority-orders') ?? false;
    }

    public function rules(): array
    {
        return ['scope_key' => 'prohibited', 'id_branches' => 'prohibited', 'ids' => 'prohibited']
            + ($this->routeIs('priorities.bulk.preview') ? ['numbers' => 'required|string|max:10000', 'token' => 'prohibited']
                : ['token' => 'required|string|max:20000', 'numbers' => 'prohibited', 'original_numbers' => 'nullable|string|max:10000']);
    }

    public function messages(): array
    {
        return ['numbers.required' => 'Tempel minimal satu No WO terlebih dahulu.', 'numbers.max' => 'Daftar terlalu panjang. Masukkan maksimal 50 No WO.',
            'token.required' => 'Periksa daftar No WO sebelum menyimpan bookmark.'];
    }
}
