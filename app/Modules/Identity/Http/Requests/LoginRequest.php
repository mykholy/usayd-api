<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Requests;

use App\Modules\Identity\Application\DTOs\LoginData;
use Illuminate\Foundation\Http\FormRequest;

final class LoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'email' => 'required|email',
            'password' => 'required|string',
            'device_name' => 'nullable|string|max:64',
        ];
    }

    public function toDto(): LoginData
    {
        return new LoginData(
            $this->validated('email'),
            $this->validated('password'),
            $this->validated('device_name') ?? 'mobile',
            $this->ip() ?? '',
            $this->hasSession(),
        );
    }
}
