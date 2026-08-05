<?php

namespace App\Http\Requests\Baselines;

use App\Concerns\BaselineItemValidationRules;
use App\Models\Baseline;
use App\Models\BaselineItem;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateBaselineItemRequest extends FormRequest
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
     * Get the validation rules that apply to the request. An item never
     * changes type — the typed fields follow the type it was created with.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $item = $this->route('item');

        return $this->baselineItemRules($item instanceof BaselineItem ? $item->type : null);
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
