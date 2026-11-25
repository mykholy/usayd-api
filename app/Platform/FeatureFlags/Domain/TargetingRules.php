<?php

declare(strict_types=1);

namespace App\Platform\FeatureFlags\Domain;

final class TargetingRules
{
    public function matches(array $rules, ?string $userId, ?string $role, ?string $country, string $anonymousKey): bool
    {
        if (isset($rules['roles']) && (! is_array($rules['roles']) || ! in_array($role, $rules['roles'], true))) {
            return false;
        }

        if (isset($rules['users']) && (! is_array($rules['users']) || $userId === null || ! in_array($userId, array_map('strval', $rules['users']), true))) {
            return false;
        }

        if (isset($rules['countries']) && (! is_array($rules['countries']) || $country === null || ! in_array(strtoupper($country), array_map('strtoupper', $rules['countries']), true))) {
            return false;
        }

        if (isset($rules['percentage'])) {
            if (! is_numeric($rules['percentage'])) {
                return false;
            }

            $percentage = (int) $rules['percentage'];
            if ($percentage <= 0) {
                return false;
            }

            if ($percentage >= 100) {
                return true;
            }

            return (($userId !== null ? (int) $userId : crc32($anonymousKey)) % 100) < $percentage;
        }

        return true;
    }
}
