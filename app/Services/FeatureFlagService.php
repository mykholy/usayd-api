<?php

namespace App\Services;

use App\Models\FeatureFlag;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Throwable;

class FeatureFlagService
{
    private const CACHE_PREFIX = 'usayd:feature_flag:';

    private const CACHE_TTL_SECONDS = 60;

    /**
     * Canonical default flags with owner decision mappings and fail-safe defaults.
     */
    public const DEFAULT_FLAGS = [
        'feature_waitlist_only' => [
            'name' => 'وضع قائمة الانتظار الحصري',
            'description' => 'حجب التسجيل المدفوع وقصر المنصة على قائمة الانتظار (O-01)',
            'is_enabled' => true,
        ],
        'feature_paid_learning_minors' => [
            'name' => 'الاشتراكات المدفوعة للقُصَّر',
            'description' => 'تمكين القُصَّر دون سن الرشد من الاشتراكات المدفوعة (O-04)',
            'is_enabled' => false,
        ],
        'feature_in_app_video' => [
            'name' => 'الغرف المرئية التفاعلية داخل التطبيق',
            'description' => 'تفعيل غرف الفيديو المباشرة عبر مزود معتمد (O-03)',
            'is_enabled' => false,
        ],
        'feature_ai_analysis' => [
            'name' => 'تحليل التلاوة بالذكاء الاصطناعي',
            'description' => 'تفعيل المعالجة الصوتية وتحليل مخارج الحروف آلياً (O-05)',
            'is_enabled' => false,
        ],
        'feature_recording' => [
            'name' => 'تسجيل الجلسات والحصص',
            'description' => 'تسجيل وحفظ جلسات التسميع والدروس (O-05)',
            'is_enabled' => false,
        ],
        'feature_public_pricing' => [
            'name' => 'عرض جداول الأسعار العامة',
            'description' => 'عرض أرقام وباقات الأسعار المعتمدة للجمهور (O-02)',
            'is_enabled' => false,
        ],
        'feature_safeguarding_break_glass' => [
            'name' => 'الوصول الاستثنائي لبيانات الحماية',
            'description' => 'تمكين إجراءات break-glass لحالات حماية الأطفال (D-39)',
            'is_enabled' => false,
        ],
    ];

    /**
     * Identify whether a flag is safety-critical or child-protection sensitive.
     */
    public static function isCriticalFlag(string $key): bool
    {
        return in_array($key, [
            'feature_safeguarding_break_glass',
            'feature_paid_learning_minors',
            'feature_recording',
        ], true);
    }

    /**
     * Determine whether a feature flag is enabled for the context.
     * Enforces fail-closed evaluation on errors for critical flags.
     */
    public static function isEnabled(string $key, ?User $user = null): bool
    {
        try {
            $flagData = self::getFlagData($key);

            if ($flagData === null) {
                return self::DEFAULT_FLAGS[$key]['is_enabled'] ?? false;
            }

            if (! $flagData['is_enabled']) {
                return false;
            }

            // Evaluate targeting rules if configured
            if (! empty($flagData['rules']) && is_array($flagData['rules'])) {
                return self::evaluateRules($flagData['rules'], $user);
            }

            return true;
        } catch (Throwable) {
            // Fail-closed for safety-critical flags in case of database or rule evaluation outage
            if (self::isCriticalFlag($key)) {
                return false;
            }

            return self::DEFAULT_FLAGS[$key]['is_enabled'] ?? false;
        }
    }

    /**
     * Retrieve flag data with cache stampede lock protection and outage resilience.
     *
     * @return array{key: string, is_enabled: bool, rules: ?array, description: ?string}|null
     */
    public static function getFlagData(string $key): ?array
    {
        $cacheKey = self::CACHE_PREFIX.$key;
        $lockKey = "usayd:lock:flag:{$key}";

        try {
            $cached = Cache::get($cacheKey);
            if ($cached !== null) {
                return $cached;
            }

            // Stampede protection: acquire atomic cache lock to populate cache
            $lock = Cache::lock($lockKey, 5);

            try {
                $lock->block(2);

                // Double check cache after acquiring lock
                $cached = Cache::get($cacheKey);
                if ($cached !== null) {
                    return $cached;
                }

                $data = self::fetchFromDatabase($key);
                if ($data !== null) {
                    Cache::put($cacheKey, $data, self::CACHE_TTL_SECONDS);
                }

                return $data;
            } finally {
                optional($lock)->release();
            }
        } catch (Throwable) {
            // Direct fail-safe fallback to database query in case of cache service outage
            return self::fetchFromDatabase($key);
        }
    }

    /**
     * Clear cached state for a flag and immediately update cache with fresh data if provided.
     *
     * @param  array{key: string, is_enabled: bool, rules: ?array, description: ?string}|null  $newFlagData
     */
    public static function clearCache(string $key, ?array $newFlagData = null): void
    {
        $cacheKey = self::CACHE_PREFIX.$key;

        try {
            Cache::forget($cacheKey);

            if ($newFlagData !== null) {
                // Immediately update cache to prevent stale flag retrieval
                Cache::put($cacheKey, $newFlagData, self::CACHE_TTL_SECONDS);
            }
        } catch (Throwable) {
            // Tolerate cache unavailability during database write
        }
    }

    /**
     * Fetch raw flag record from database.
     *
     * @return array{key: string, is_enabled: bool, rules: ?array, description: ?string}|null
     */
    private static function fetchFromDatabase(string $key): ?array
    {
        try {
            $flag = FeatureFlag::query()->where('key', $key)->first();
            if (! $flag) {
                return null;
            }

            return [
                'key' => $flag->key,
                'is_enabled' => (bool) $flag->is_enabled,
                'rules' => $flag->rules,
                'description' => $flag->description,
            ];
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Evaluate dynamic rules (roles, countries, user IDs, percentage).
     */
    private static function evaluateRules(array $rules, ?User $user): bool
    {
        // 1. Allowed roles rule
        if (isset($rules['roles']) && is_array($rules['roles'])) {
            if (! $user || ! in_array($user->role, $rules['roles'], true)) {
                return false;
            }
        }

        // 2. Allowed user IDs rule
        if (isset($rules['users']) && is_array($rules['users'])) {
            if (! $user || ! in_array($user->id, $rules['users'], true)) {
                return false;
            }
        }

        // 3. Allowed countries rule
        if (isset($rules['countries']) && is_array($rules['countries'])) {
            $userCountry = $user?->country;
            if (! $userCountry || ! in_array(strtoupper($userCountry), array_map('strtoupper', $rules['countries']), true)) {
                return false;
            }
        }

        // 4. Percentage rollout rule (0-100)
        if (isset($rules['percentage']) && is_numeric($rules['percentage'])) {
            $percentage = (int) $rules['percentage'];
            if ($percentage <= 0) {
                return false;
            }
            if ($percentage >= 100) {
                return true;
            }
            $targetId = $user ? $user->id : (crc32(request()->ip() ?? '127.0.0.1'));

            return ($targetId % 100) < $percentage;
        }

        return true;
    }
}
