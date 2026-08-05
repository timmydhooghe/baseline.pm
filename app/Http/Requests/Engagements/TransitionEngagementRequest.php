<?php

namespace App\Http\Requests\Engagements;

use App\Enums\BaselineStatus;
use App\Enums\EngagementStatus;
use App\Enums\FinalAcceptanceStatus;
use App\Models\Engagement;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class TransitionEngagementRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $engagement = $this->route('engagement');

        return $engagement instanceof Engagement
            && ($this->user()?->can('transition', $engagement) ?? false);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'status' => ['required', Rule::enum(EngagementStatus::class)],
        ];
    }

    /**
     * Refuse moves the EngagementStatus state machine does not allow, and
     * keep the approval roads owned by their flows: only submitting a
     * baseline puts an engagement under review, only submitting for final
     * acceptance starts the acceptance gate, and only the customer's signed
     * acceptance completes the engagement (FA-24).
     *
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $engagement = $this->route('engagement');
                $target = EngagementStatus::tryFrom((string) $this->input('status'));

                if (! $engagement instanceof Engagement || $target === null) {
                    return;
                }

                if (! $engagement->status->canTransitionTo($target)) {
                    $validator->errors()->add('status', __('This engagement cannot move from :from to :to.', [
                        'from' => $engagement->status->label(),
                        'to' => $target->label(),
                    ]));

                    return;
                }

                if ($target === EngagementStatus::AwaitingBaselineApproval
                    && $engagement->baselines()->where('status', BaselineStatus::AwaitingApproval)->doesntExist()) {
                    $validator->errors()->add('status', __('Submit the baseline from the baseline builder to put it up for approval.'));
                }

                if ($target === EngagementStatus::AwaitingFinalAcceptance
                    && $engagement->finalAcceptances()->where('status', FinalAcceptanceStatus::AwaitingResponse)->doesntExist()) {
                    $validator->errors()->add('status', __('Submit for final acceptance from the engagement overview — the customer signs a frozen record.'));
                }

                if ($target === EngagementStatus::Completed
                    && $engagement->finalAcceptances()->where('status', FinalAcceptanceStatus::Accepted)->doesntExist()) {
                    $validator->errors()->add('status', __('Completion requires the customer\'s signed final acceptance.'));
                }
            },
        ];
    }
}
