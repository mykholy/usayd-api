<?php

declare(strict_types=1);

namespace App\Platform\FeatureFlags\Http\Requests;

use App\Platform\FeatureFlags\Application\UpdateFeatureFlagData;
use Illuminate\Foundation\Http\FormRequest;

final class UpdateFeatureFlagRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isAdmin() === true;
    }

    public function rules(): array
    {
        return [
            'is_enabled' => 'required|boolean',
            'rules' => 'nullable|array:roles,countries,users,percentage',
            'rules.roles' => 'nullable|array',
            'rules.roles.*' => 'string|in:learner,teacher,academic,admin,support,finance',
            'rules.countries' => 'nullable|array',
            'rules.countries.*' => 'string|size:2',
            'rules.users' => 'nullable|array',
            'rules.users.*' => 'string|numeric',
            'rules.percentage' => 'nullable|integer|between:0,100',
            'description' => 'nullable|string|max:255',
            'name' => 'nullable|string|max:150',
        ];
    }

    public function toDto(): UpdateFeatureFlagData
    {
        $data = $this->validated();

        return new UpdateFeatureFlagData(
            $this->boolean('is_enabled'),
            array_key_exists('rules', $data),
            $data['rules'] ?? null,
            $data['name'] ?? null,
            $data['description'] ?? null,
            (string) $this->attributes->get('audit_purpose'),
        );
    }
}
