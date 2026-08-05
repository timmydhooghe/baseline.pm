<?php

namespace App\Concerns;

use App\Enums\BaselineItemType;
use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Rule;

/**
 * Validation rules for baseline items, keyed by item type: deliverables
 * carry owner, commercial value and acceptance criteria; milestones carry a
 * baseline date and payment trigger; the narrative types (assumptions,
 * exclusions, responsibilities) only trace to their clause. The typed fields
 * stay optional while drafting — the completeness check is what forces them
 * before submission.
 */
trait BaselineItemValidationRules
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    protected function baselineItemRules(?BaselineItemType $type): array
    {
        $rules = [
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:10000'],
            'clause_reference' => ['required', 'string', 'max:255'],
        ];

        if ($type === BaselineItemType::Deliverable) {
            $user = $this->user();

            $rules += [
                'owner_id' => [
                    'nullable',
                    'uuid',
                    Rule::exists(User::class, 'id')->where('organization_id', $user instanceof User ? $user->organization_id : null),
                ],
                'value' => ['nullable', 'numeric', 'decimal:0,2', 'min:0', 'max:100000000'],
                'acceptance_criteria' => ['nullable', 'list'],
                'acceptance_criteria.*.criterion' => ['required', 'string', 'max:1000'],
                'acceptance_criteria.*.verification_method' => ['nullable', 'string', 'max:1000'],
            ];
        }

        if ($type === BaselineItemType::Milestone) {
            $rules += [
                'baseline_date' => ['nullable', 'date'],
                'payment_trigger' => ['nullable', 'string', 'max:255'],
            ];
        }

        return $rules;
    }

    /**
     * @return array<string, string>
     */
    protected function baselineItemMessages(): array
    {
        return [
            'clause_reference.required' => __('Every item must trace to a contract clause.'),
            'acceptance_criteria.*.criterion.required' => __('An acceptance criterion cannot be empty.'),
        ];
    }
}
