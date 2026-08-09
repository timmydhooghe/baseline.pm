<?php

namespace App\Http\Requests\Risks;

use App\Models\Risk;
use Illuminate\Contracts\Validation\ValidationRule;

class UpdateRiskRequest extends StoreRiskRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $risk = $this->route('risk');

        return $risk instanceof Risk
            && ($this->user()?->can('update', $risk) ?? false);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * Re-rating uses the same shape as raising: the rating is not a separate
     * kind of edit, it is the register entry being written again — and the
     * model freezes a revision whenever the rating or status actually moves.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $risk = $this->route('risk');

        return $this->riskRules($risk instanceof Risk ? $risk->engagement : null);
    }
}
