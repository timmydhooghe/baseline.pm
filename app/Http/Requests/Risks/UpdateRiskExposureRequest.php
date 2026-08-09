<?php

namespace App\Http\Requests\Risks;

use App\Models\RateCardRole;
use App\Models\Risk;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateRiskExposureRequest extends FormRequest
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
     * Structured exposure only (FA-19): days per role, priced at the rate
     * card version pinned on the risk. Roles from any other version are
     * refused — an exposure priced off an unpinned card would trace to
     * nothing.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $risk = $this->route('risk');
        $risk = $risk instanceof Risk ? $risk : null;

        return [
            'lines' => ['present', 'list'],
            'lines.*.rate_card_role_id' => [
                'required',
                'uuid',
                Rule::exists(RateCardRole::class, 'id')
                    ->where('rate_card_version_id', $risk?->rate_card_version_id),
            ],
            'lines.*.days' => ['required', 'numeric', 'min:0.25', 'max:2000'],
        ];
    }

    /**
     * A risk raised before the organization published a rate card has
     * nothing to price against, and one role cannot appear twice.
     *
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $risk = $this->route('risk');

                if ($risk instanceof Risk && $risk->rate_card_version_id === null) {
                    $validator->errors()->add('lines', __('This risk has no pinned rate card version — publish a rate card and raise the risk again.'));
                }

                $roles = array_column((array) $this->input('lines', []), 'rate_card_role_id');

                if (count($roles) !== count(array_unique($roles))) {
                    $validator->errors()->add('lines', __('Each role carries one exposure line — combine the days instead.'));
                }
            },
        ];
    }
}
