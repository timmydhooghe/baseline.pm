<?php

namespace App\Http\Requests\Baselines;

use App\Enums\CommercialModel;
use App\Enums\ExecutionMode;
use App\Models\Baseline;
use App\Models\Engagement;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreBaselineRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $engagement = $this->route('engagement');

        return $engagement instanceof Engagement
            && ($this->user()?->can('create', [Baseline::class, $engagement]) ?? false);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * The contract value arrives as decimal euros and is stored as cents.
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
