<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\FeatureFlag;
use App\Services\Audit\AuditLogger;
use App\Services\FeatureFlagService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class FeatureFlagController extends Controller
{
    /**
     * Display a listing of all feature flags (Strictly Read-Only).
     */
    public function index(): JsonResponse
    {
        $flags = FeatureFlag::query()->orderBy('key')->get();

        return response()->json([
            'data' => $flags,
        ]);
    }

    /**
     * Update the specified feature flag state and rules transactionally with audit trail.
     */
    public function update(Request $request, string $key): JsonResponse
    {
        $validated = $request->validate([
            'is_enabled' => 'required|boolean',
            'rules' => 'nullable|array',
            'rules.roles' => 'nullable|array',
            'rules.roles.*' => 'string|in:learner,teacher,academic,admin,support,finance',
            'rules.countries' => 'nullable|array',
            'rules.countries.*' => 'string|size:2',
            'rules.users' => 'nullable|array',
            'rules.users.*' => 'string|numeric',
            'rules.percentage' => 'nullable|integer|between:0,100',
            'description' => 'nullable|string|max:255',
            'name' => 'nullable|string|max:150',
        ]);

        $purpose = (string) $request->attributes->get('audit_purpose');

        // Transactional update & audit logging
        $flag = DB::transaction(function () use ($key, $validated, $purpose) {
            $record = FeatureFlag::query()->where('key', $key)->lockForUpdate()->first();

            if (! $record) {
                if (isset(FeatureFlagService::DEFAULT_FLAGS[$key])) {
                    $default = FeatureFlagService::DEFAULT_FLAGS[$key];
                    $record = FeatureFlag::query()->create([
                        'key' => $key,
                        'name' => $validated['name'] ?? $default['name'],
                        'description' => $validated['description'] ?? $default['description'],
                        'is_enabled' => $validated['is_enabled'],
                        'rules' => $validated['rules'] ?? null,
                    ]);
                } else {
                    throw ApiException::notFound(__('api.feature_flag_missing'));
                }
            } else {
                $record->update([
                    'is_enabled' => $validated['is_enabled'],
                    'rules' => array_key_exists('rules', $validated) ? $validated['rules'] : $record->rules,
                    'description' => $validated['description'] ?? $record->description,
                    'name' => $validated['name'] ?? $record->name,
                ]);
            }

            AuditLogger::log(
                action: 'feature_flag.updated',
                subjectType: 'feature_flag',
                subjectId: $record->id,
                purpose: $purpose,
                metadata: [
                    'key' => $record->key,
                    'is_enabled' => $record->is_enabled,
                    'rules' => $record->rules,
                ]
            );

            return $record;
        });

        // Safely invalidate and refresh cache
        FeatureFlagService::clearCache($key, [
            'key' => $flag->key,
            'is_enabled' => (bool) $flag->is_enabled,
            'rules' => $flag->rules,
            'description' => $flag->description,
        ]);

        return response()->json([
            'data' => $flag,
            'message' => __('api.feature_flag_updated'),
        ]);
    }
}
