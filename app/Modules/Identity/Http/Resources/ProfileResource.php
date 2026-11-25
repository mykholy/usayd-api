<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class ProfileResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $user = $this->resource;

        return [
            'id' => (string) $user->id,
            'name' => (string) $user->name,
            'email' => $user->email,
            'locale' => in_array($user->locale, ['ar', 'en'], true) ? $user->locale : 'ar',
            'timezone' => (string) ($user->timezone ?? config('identity.registration_timezone')),
            'roles' => [(string) ($user->role ?? 'learner')],
            'verification_status' => $user->status === 'active' ? 'verified' : ((string) ($user->status ?? 'pending')),
        ];
    }
}
