<?php

namespace App\Http\Requests\Baselines;

use App\Enums\CommercialModel;
use App\Enums\ExecutionMode;
use App\Models\Baseline;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateBaselineRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request. The policy
     * refuses updates once the baseline left draft.
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
            'commercial_model' => ['required', Rule::enum(CommercialModel::class)],
            'contract_value' => ['required', 'numeric', 'decimal:0,2', 'min:0', 'max:100000000'],
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after_or_equal:start_date'],
            'execution_mode' => ['required', Rule::enum(ExecutionMode::class)],
        ];
    }

    /**
     * Get custom messages for validator errors.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'end_date.after_or_equal' => __('The end date must not be before the start date.'),
        ];
    }
}
