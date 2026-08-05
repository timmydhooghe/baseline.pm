<?php

namespace App\Http\Requests\Baselines;

use App\Enums\BaselineItemType;
use App\Models\Baseline;
use App\Models\BaselineItem;
use App\Models\RateCardRole;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateBaselineCommercialsRequest extends FormRequest
{
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
     * The whole role mix is replaced at once. Every line must price a role
     * from the baseline's pinned rate card version — cost is derived, never
     * typed — and lines without an item are delivery-management effort.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $baseline = $this->route('baseline');
        $baseline = $baseline instanceof Baseline ? $baseline : null;

        return [
            'allocations' => ['present', 'list'],
            'allocations.*.baseline_item_id' => [
                'nullable',
                'uuid',
                Rule::exists(BaselineItem::class, 'id')
                    ->where('baseline_id', $baseline?->id)
                    ->where('type', BaselineItemType::Deliverable->value),
            ],
            'allocations.*.rate_card_role_id' => [
                'required',
                'uuid',
                Rule::exists(RateCardRole::class, 'id')
                    ->where('rate_card_version_id', $baseline?->rate_card_version_id),
            ],
            'allocations.*.days' => ['required', 'numeric', 'decimal:0,2', 'min:0.01', 'max:10000'],
        ];
    }

    /**
     * Refuse the same role appearing twice for one deliverable (or twice as
     * delivery management).
     *
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                /** @var list<array{baseline_item_id?: string|null, rate_card_role_id?: string}> $allocations */
                $allocations = is_array($this->input('allocations')) ? array_values($this->input('allocations')) : [];

                $seen = [];

                foreach ($allocations as $index => $allocation) {
                    $key = ($allocation['baseline_item_id'] ?? 'delivery_management').'|'.($allocation['rate_card_role_id'] ?? '');

                    if (isset($seen[$key])) {
                        $validator->errors()->add("allocations.{$index}.rate_card_role_id", __('This role is already allocated on the same line.'));
                    }

                    $seen[$key] = true;
                }
            },
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
            'allocations.*.rate_card_role_id.exists' => __('Roles must come from the rate card version this baseline was priced with.'),
            'allocations.*.baseline_item_id.exists' => __('Role mixes attach to deliverables on this baseline.'),
            'allocations.*.days.required' => __('Every role line needs estimated days.'),
        ];
    }
}
