<?php

namespace App\Http\Requests\ChangeRequests;

use App\Enums\ChangeRequestStatus;
use App\Models\ChangeRequest;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class TransitionChangeRequestRequest extends FormRequest
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
     * Only the internal forward moves travel through here: opening the
     * assessment and moving to the customer proposal (or back). Submission
     * carries a respond-by deadline and has its own flow; decisions belong
     * to the customer (FA-13).
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'status' => ['required', Rule::enum(ChangeRequestStatus::class)->only([
                ChangeRequestStatus::UnderAssessment,
                ChangeRequestStatus::CustomerProposal,
            ])],
        ];
    }

    /**
     * Refuse moves the ChangeRequestStatus state machine does not allow,
     * with the reason a manager can act on.
     *
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $changeRequest = $this->route('changeRequest');
                $target = ChangeRequestStatus::tryFrom((string) $this->input('status'));

                if (! $changeRequest instanceof ChangeRequest || $target === null) {
                    return;
                }

                if (! $changeRequest->status->canTransitionTo($target)) {
                    $validator->errors()->add('status', __('This change request cannot move from :from to :to.', [
                        'from' => $changeRequest->status->label(),
                        'to' => $target->label(),
                    ]));

                    return;
                }

                if ($target === ChangeRequestStatus::CustomerProposal && $changeRequest->allocations()->doesntExist()) {
                    $validator->errors()->add('status', __('Assess the effort as a role mix first — commercial terms derive from it.'));
                }
            },
        ];
    }
}
