<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class AuthenticationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $account = $this->resource->account;

        if ($account !== null) {
            return [
                'user' => [
                    'id' => (string) $account->id,
                    'name' => $account->name,
                    'email' => $account->email,
                    'locale' => $account->locale,
                ],
                'session_authenticated' => true,
            ];
        }

        return $this->resource->tokens;
    }
}
