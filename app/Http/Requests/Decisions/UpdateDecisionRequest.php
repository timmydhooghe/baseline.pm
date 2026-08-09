<?php

namespace App\Http\Requests\Decisions;

use App\Models\Decision;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Validator;

class UpdateDecisionRequest extends StoreDecisionRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $decision = $this->route('decision');

        return $decision instanceof Decision
            && ($this->user()?->can('update', $decision) ?? false);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * The same shape as drafting, resolved against the record's own
     * engagement.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $decision = $this->route('decision');

        $decision = $decision instanceof Decision ? $decision : null;

        return $this->decisionRules($decision?->engagement, $decision);
    }

    /**
     * A confirmed record is history — corrections arrive as a superseding
     * decision, never as an edit — and a decision cannot supersede itself.
     *
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $decision = $this->route('decision');

                if (! $decision instanceof Decision) {
                    return;
                }

                if (! $decision->status->acceptsEdits()) {
                    $validator->errors()->add('title', __('This decision is on the ledger — record a superseding decision instead.'));
                }

                if ($this->input('supersedes_id') === $decision->id) {
                    $validator->errors()->add('supersedes_id', __('A decision cannot supersede itself.'));
                }
            },
        ];
    }
}
