<?php

declare(strict_types=1);

namespace App\Platform\FeatureFlags\Application;

use App\Exceptions\ApiException;
use App\Models\FeatureFlag;
use App\Services\Audit\AuditLogger;
use App\Services\FeatureFlagService;
use Illuminate\Support\Facades\DB;

final class UpdateFeatureFlag
{
    public function execute(string $key, UpdateFeatureFlagData $data): FeatureFlag
    {
        return DB::transaction(function () use ($key, $data): FeatureFlag {
            $record = FeatureFlag::query()->where('key', $key)->lockForUpdate()->first();

            if (! $record) {
                $default = FeatureFlagService::DEFAULT_FLAGS[$key] ?? null;
                if ($default === null) {
                    throw ApiException::notFound(__('api.feature_flag_missing'));
                }

                $record = FeatureFlag::query()->create([
                    'key' => $key,
                    'name' => $data->name ?? $default['name'],
                    'description' => $data->description ?? $default['description'],
                    'is_enabled' => $data->enabled,
                    'rules' => $data->rules,
                ]);
            } else {
                $record->update([
                    'is_enabled' => $data->enabled,
                    'rules' => $data->replaceRules ? $data->rules : $record->rules,
                    'description' => $data->description ?? $record->description,
                    'name' => $data->name ?? $record->name,
                ]);
            }

            AuditLogger::log(
                action: 'feature_flag.updated',
                subjectType: 'feature_flag',
                subjectId: $record->id,
                purpose: $data->purpose,
                metadata: [
                    'key' => $record->key,
                    'is_enabled' => $record->is_enabled,
                    'rules' => $record->rules,
                ],
            );

            DB::afterCommit(fn () => FeatureFlagService::clearCache($key));

            return $record;
        }, 3);
    }
}
