<?php

namespace Database\Seeders;

use App\Models\FeatureFlag;
use App\Services\FeatureFlagService;
use Illuminate\Database\Seeder;

class FeatureFlagSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        foreach (FeatureFlagService::DEFAULT_FLAGS as $key => $data) {
            FeatureFlag::query()->updateOrCreate(
                ['key' => $key],
                [
                    'name' => $data['name'],
                    'description' => $data['description'],
                    'is_enabled' => $data['is_enabled'],
                    'rules' => null,
                ]
            );
        }
    }
}
