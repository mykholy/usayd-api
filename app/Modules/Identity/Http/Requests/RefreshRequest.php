<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Requests;

use App\Modules\Identity\Application\DTOs\RefreshData;
use Illuminate\Foundation\Http\FormRequest;

final class RefreshRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'refresh_token' => 'required|string',
            'device_name' => 'nullable|string|max:64',
        ];
    }

    public function toDto(): RefreshData
    {
        return new RefreshData(
            $this->validated('refresh_token'),
            $this->validated('device_name') ?? 'mobile',
        );
    }
}
