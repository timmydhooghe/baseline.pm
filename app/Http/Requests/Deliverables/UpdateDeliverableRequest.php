<?php

namespace App\Http\Requests\Deliverables;

use App\Enums\BaselineItemType;
use App\Enums\DeliverableConfidence;
use App\Enums\RecordVisibility;
use App\Models\BaselineItem;
use App\Models\Deliverable;
use App\Models\DeliverableEvidence;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateDeliverableRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $deliverable = $this->route('deliverable');

        return $deliverable instanceof Deliverable
            && ($this->user()?->can('update', $deliverable) ?? false);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * The execution record in one update (FA-22): progress, confidence and
     * forecast as typed fields, the milestone assignment referencing the
     * record's own baseline, and per-criterion evidence links referencing
     * the record's own evidence list — structured first, no free text.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $deliverable = $this->route('deliverable');
        $deliverable = $deliverable instanceof Deliverable ? $deliverable : null;

        return [
            'progress' => ['required', 'integer', 'between:0,100'],
            'confidence' => ['required', Rule::enum(DeliverableConfidence::class)],
            'forecast_date' => ['nullable', 'date'],
            'milestone_item_id' => [
                'nullable',
                'uuid',
                Rule::exists(BaselineItem::class, 'id')
                    ->where('baseline_id', $deliverable?->baselineItem->baseline_id)
                    ->where('type', BaselineItemType::Milestone->value),
            ],
            'criteria' => ['nullable', 'list'],
            'criteria.*.evidence_id' => [
                'nullable',
                'uuid',
                Rule::exists(DeliverableEvidence::class, 'id')
                    ->where('deliverable_id', $deliverable?->id),
            ],
            'criteria.*.visibility' => ['required', Rule::enum(RecordVisibility::class)],
        ];
    }

    /**
     * A frozen or signed record refuses execution updates.
     *
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $deliverable = $this->route('deliverable');

                if ($deliverable instanceof Deliverable && ! $deliverable->status->acceptsUpdates()) {
                    $validator->errors()->add('progress', __('This record is frozen — it awaits the customer decision or carries a signed acceptance.'));
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
            'milestone_item_id.exists' => __('The milestone must live on the same baseline version as the deliverable.'),
            'criteria.*.evidence_id.exists' => __('Criterion evidence must come from this deliverable\'s evidence list.'),
        ];
    }
}
