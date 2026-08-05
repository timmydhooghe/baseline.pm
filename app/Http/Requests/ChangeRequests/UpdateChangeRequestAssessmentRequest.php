<?php

namespace App\Http\Requests\ChangeRequests;

use App\Enums\BaselineItemType;
use App\Models\BaselineItem;
use App\Models\ChangeRequest;
use App\Models\RateCardRole;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateChangeRequestAssessmentRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $changeRequest = $this->route('changeRequest');

        return $changeRequest instanceof ChangeRequest
            && ($this->user()?->can('update', $changeRequest) ?? false);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * The whole structured assessment is replaced at once (FA-12): role-mix
     * lines priced against the change request's pinned rate card version,
     * affected items linked on the current approved baseline, and schedule
     * impact as a milestone reference plus a day count — no free-text dates.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $changeRequest = $this->route('changeRequest');
        $changeRequest = $changeRequest instanceof ChangeRequest ? $changeRequest : null;
        $baselineId = $changeRequest?->engagement->approvedBaseline()?->id;

        return [
            'allocations' => ['nullable', 'list'],
            'allocations.*.rate_card_role_id' => [
                'required',
                'uuid',
                Rule::exists(RateCardRole::class, 'id')
                    ->where('rate_card_version_id', $changeRequest?->rate_card_version_id),
            ],
            'allocations.*.days' => ['required', 'numeric', 'decimal:0,2', 'min:0.01', 'max:10000'],
            'affected_items' => ['nullable', 'list'],
            'affected_items.*' => [
                'uuid',
                'distinct',
                Rule::exists(BaselineItem::class, 'id')->where('baseline_id', $baselineId),
            ],
            'impact_milestone_id' => [
                'nullable',
                'uuid',
                'required_with:impact_days',
                Rule::exists(BaselineItem::class, 'id')
                    ->where('baseline_id', $baselineId)
                    ->where('type', BaselineItemType::Milestone->value),
            ],
            'impact_days' => ['nullable', 'integer', 'between:-365,365', 'required_with:impact_milestone_id'],
            'scope_added' => ['nullable', 'string', 'max:5000'],
            'scope_removed' => ['nullable', 'string', 'max:5000'],
            'alternatives' => ['nullable', 'string', 'max:5000'],
        ];
    }

    /**
     * Refuse the same role appearing twice in the mix.
     *
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                /** @var list<array{rate_card_role_id?: string}> $allocations */
                $allocations = is_array($this->input('allocations')) ? array_values($this->input('allocations')) : [];

                $seen = [];

                foreach ($allocations as $index => $allocation) {
                    $key = $allocation['rate_card_role_id'] ?? '';

                    if (isset($seen[$key])) {
                        $validator->errors()->add("allocations.{$index}.rate_card_role_id", __('This role is already in the mix.'));
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
            'allocations.*.rate_card_role_id.exists' => __('Roles must come from the rate card version this change request was pinned to.'),
            'allocations.*.days.required' => __('Every role line needs estimated days.'),
            'affected_items.*.exists' => __('Affected items must live on the current approved baseline.'),
            'impact_milestone_id.exists' => __('Schedule impact references a milestone on the current approved baseline.'),
            'impact_days.required_with' => __('Schedule impact is a milestone plus a day count — set the days.'),
            'impact_milestone_id.required_with' => __('Schedule impact is a milestone plus a day count — pick the milestone.'),
        ];
    }
}
