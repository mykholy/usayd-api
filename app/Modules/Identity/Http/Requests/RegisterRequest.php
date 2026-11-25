<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Requests;

use App\Modules\Identity\Application\DTOs\RegisterData;
use Illuminate\Foundation\Http\FormRequest;

final class RegisterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:users,email',
            'password' => 'required|string|min:8',
            'phone' => 'nullable|string|max:30',
            'locale' => 'nullable|string|in:ar,en',
            'device_name' => 'nullable|string|max:64',
        ];
    }

    public function toDto(): RegisterData
    {
        return new RegisterData(
            $this->validated('name'),
            $this->validated('email'),
            $this->validated('password'),
            $this->validated('phone'),
            $this->validated('locale') ?? 'ar',
            $this->validated('device_name') ?? 'mobile',
            $this->ip() ?? '',
            $this->hasSession(),
        );
    }
}
