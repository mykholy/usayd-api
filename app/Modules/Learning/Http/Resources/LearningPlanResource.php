<?php

declare(strict_types=1);

namespace App\Modules\Learning\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class LearningPlanResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'user_id' => $this->user_id,
            'teacher_id' => $this->teacher_id,
            'title' => $this->title,
            'status' => $this->status,
            'curriculum_level' => $this->curriculum_level,
            'created_at' => $this->resource->created_at?->toJSON(),
            'updated_at' => $this->resource->updated_at?->toJSON(),
        ];
    }
}
