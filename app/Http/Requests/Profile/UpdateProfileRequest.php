<?php

namespace App\Http\Requests\Profile;

use App\Support\PhoneNumber;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['phone' => PhoneNumber::normalize($this->input('phone'))]);
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],
            'phone' => [
                'required',
                'regex:/^08[1-9][0-9]{7,11}$/',
                Rule::unique('users', 'phone')->ignore($this->user()->id),
            ],
            'bio' => ['nullable', 'string', 'max:500'], // hanya dipakai untuk teknisi
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'Nama wajib diisi.',
            'name.max' => 'Nama maksimal 100 karakter.',
            'phone.required' => 'Nomor HP wajib diisi.',
            'phone.regex' => 'Nomor HP tidak valid. Contoh: 081234567890.',
            'phone.unique' => 'Nomor HP sudah dipakai akun lain.',
            'bio.max' => 'Bio maksimal 500 karakter.',
        ];
    }
}