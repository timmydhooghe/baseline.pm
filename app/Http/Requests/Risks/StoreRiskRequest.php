<?php

namespace App\Http\Requests\Risks;

use App\Enums\RecordVisibility;
use App\Enums\RiskRating;
use App\Enums\RiskStatus;
use App\Models\BaselineItem;
use App\Models\ChangeRequest;
use App\Models\Deliverable;
use App\Models\Dependency;
use App\Models\Engagement;
use App\Models\Risk;
use App\Models\User;
use App\Rules\LinkedGovernanceRecords;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreRiskRequest extends FormRequest
{
    /**
     * The record types a risk may threaten (FA-19).
     *
     * @var list<class-string<Model>>
     */
    public const array LINKABLE = [
        BaselineItem::class,
        Deliverable::class,
        ChangeRequest::class,
        Dependency::class,
    ];

    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $engagement = $this->route('engagement');

        return $engagement instanceof Engagement
            && ($this->user()?->can('create', [Risk::class, $engagement]) ?? false);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $engagement = $this->route('engagement');

        return $this->riskRules($engagement instanceof Engagement ? $engagement : null);
    }

    /**
     * The register takes a rating on a three-point scale, a named owner, the
     * records the risk threatens as chips, and effort at risk as days per
     * rate card role (FA-19). It never takes a euro amount: exposure derives
     * from the pinned rate card, which is what makes it defensible.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    protected function riskRules(?Engagement $engagement): array
    {
        return [
            'title' => ['required', 'string', 'max:200'],
            'description' => ['nullable', 'string', 'max:5000'],
            'probability' => ['required', Rule::enum(RiskRating::class)],
            'impact' => ['required', Rule::enum(RiskRating::class)],
            'status' => ['required', Rule::enum(RiskStatus::class)],
            'owner_id' => [
                'nullable',
                'uuid',
                Rule::exists(User::class, 'id')->where('organization_id', $engagement?->organization_id),
            ],
            'mitigation' => ['nullable', 'string', 'max:5000'],
            'visibility' => ['required', Rule::enum(RecordVisibility::class)],
            'note' => ['nullable', 'string', 'max:1000'],
            'links' => ['nullable', new LinkedGovernanceRecords($engagement, self::LINKABLE)],
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
            'owner_id.exists' => __('The risk owner must be a member of your organization.'),
        ];
    }
}
