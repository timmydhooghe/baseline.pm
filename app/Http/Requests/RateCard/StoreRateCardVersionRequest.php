<?php

namespace App\Http\Requests\RateCard;

use App\Models\RateCardVersion;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreRateCardVersionRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('create', RateCardVersion::class) ?? false;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * Rates arrive as decimal euros per day and are stored as cents; the
     * whole role list is validated because every version is a complete
     * snapshot of the rate card.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'roles' => ['required', 'array', 'min:1'],
            'roles.*.name' => ['required', 'string', 'max:255', 'distinct:ignore_case'],
            'roles.*.cost_per_day' => ['required', 'numeric', 'decimal:0,2', 'min:0', 'max:1000000'],
            'roles.*.sell_per_day' => ['required', 'numeric', 'decimal:0,2', 'min:0', 'max:1000000'],
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
            'roles.required' => __('A rate card needs at least one role.'),
            'roles.*.name.required' => __('Every role needs a name.'),
            'roles.*.name.distinct' => __('Role names must be unique within a version.'),
            'roles.*.cost_per_day.required' => __('Every role needs a cost per day.'),
            'roles.*.sell_per_day.required' => __('Every role needs a sell rate per day.'),
        ];
    }
}
