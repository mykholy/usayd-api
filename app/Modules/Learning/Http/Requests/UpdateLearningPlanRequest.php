<?php

declare(strict_types=1);

namespace App\Modules\Learning\Http\Requests;

use App\Modules\Learning\Application\DTOs\LearningPlanData;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

final class UpdateLearningPlanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('update', $this->route('learningPlan'));
    }

    public function rules(): array
    {
        return [
            'title' => 'required|string|max:255',
            'status' => 'required|string|in:active,completed,draft',
        ];
    }

    public function toDto(): LearningPlanData
    {
        return new LearningPlanData($this->validated('title'), $this->validated('status'));
    }
}
