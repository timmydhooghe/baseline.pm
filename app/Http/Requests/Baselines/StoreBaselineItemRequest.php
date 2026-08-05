<?php

namespace App\Http\Requests\Baselines;

use App\Concerns\BaselineItemValidationRules;
use App\Enums\BaselineItemType;
use App\Models\Baseline;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreBaselineItemRequest extends FormRequest
{
    use BaselineItemValidationRules;

    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $baseline = $this->route('baseline');

        return $baseline instanceof Baseline
            && ($this->user()?->can('update', $baseline) ?? false);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'type' => ['required', Rule::enum(BaselineItemType::class)],
            ...$this->baselineItemRules(BaselineItemType::tryFrom((string) $this->input('type'))),
        ];
    }

    /**
     * Get custom messages for validator errors.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return $this->baselineItemMessages();
    }
}
