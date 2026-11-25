<?php

declare(strict_types=1);

namespace App\Platform\FeatureFlags\Application;

use App\Models\FeatureFlag;
use Illuminate\Database\Eloquent\Collection;

final class ListFeatureFlags
{
    /** @return Collection<int, FeatureFlag> */
    public function execute(): Collection
    {
        return FeatureFlag::query()->orderBy('key')->get();
    }
}
