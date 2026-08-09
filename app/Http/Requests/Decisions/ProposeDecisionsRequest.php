<?php

namespace App\Http\Requests\Decisions;

use App\Models\Decision;
use App\Models\Engagement;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class ProposeDecisionsRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $engagement = $this->route('engagement');

        return $engagement instanceof Engagement
            && ($this->user()?->can('create', [Decision::class, $engagement]) ?? false);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * A pasted meeting transcript (FA-18). The ceiling is generous enough
     * for a long call and small enough that the extraction stays a request
     * cycle rather than a job.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'transcript' => ['required', 'string', 'min:20', 'max:200000'],
        ];
    }
}
