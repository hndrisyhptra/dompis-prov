<?php

namespace App\Http\Requests;

use App\Enums\Role;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class SaveUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('administer') && $this->route('user')?->role !== Role::Bot;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'branches' => $this->input('branches', []),
            'name' => is_string($this->name) ? trim($this->name) : $this->name,
            'username' => is_string($this->username) ? mb_strtolower(trim($this->username)) : $this->username,
            'email' => is_string($this->email) ? mb_strtolower(trim($this->email)) : $this->email,
        ]);
    }

    public function rules(): array
    {
        $target = $this->route('user');

        return [
            'name' => ['required', 'string', 'max:255'],
            'username' => ['nullable', 'required_without:email', 'string', 'max:80', 'regex:/\A[a-zA-Z0-9][a-zA-Z0-9._-]*\z/', Rule::unique('users', 'username')->ignore($target?->getKey(), 'id_users')],
            'email' => ['nullable', 'required_without:username', 'email', 'max:255', Rule::unique('users', 'email')->ignore($target?->getKey(), 'id_users')],
            'password' => [$target ? 'nullable' : 'required', 'string', 'max:255', 'confirmed', Password::min(12)->mixedCase()->numbers()],
            'role' => ['required', Rule::in(array_map(fn ($role) => $role->value, array_filter(Role::cases(), fn ($role) => $role !== Role::Bot)))],
            'active' => ['required', 'boolean'],
            'branches' => ['sometimes', 'array', 'max:100'],
            'branches.*' => ['integer', 'distinct', 'exists:branches,id_branches'],
            'id_regions' => ['nullable', 'integer', 'exists:regions,id_regions'],
            'id_service_areas' => ['nullable', 'integer', 'exists:service_areas,id_service_areas'],
            'management_revision' => [$target ? 'required' : 'exclude', 'integer', 'min:0'],
        ];
    }

    public function messages(): array
    {
        return [
            'required' => ':attribute wajib diisi.',
            'required_without' => 'Isi minimal username atau email untuk login.',
            'username.regex' => 'Username hanya boleh berisi huruf, angka, titik, garis bawah, dan tanda hubung.',
            'unique' => ':attribute sudah digunakan oleh pengguna lain.',
            'email.email' => 'Format email belum sesuai.',
            'password.confirmed' => 'Konfirmasi kata sandi belum cocok.',
            'exists' => ':attribute tidak ditemukan.',
            'role.in' => 'Role pengguna tidak tersedia.',
            'branches.*.distinct' => 'Branch yang dipilih tidak boleh berulang.',
        ];
    }

    public function attributes(): array
    {
        return ['name' => 'nama', 'username' => 'username', 'email' => 'email', 'password' => 'kata sandi', 'role' => 'role', 'active' => 'status aktif', 'branches' => 'Branch', 'id_regions' => 'region utama', 'id_service_areas' => 'STO utama', 'management_revision' => 'versi data'];
    }
}
