<?php

namespace App\Http\Requests\Dependencies;

use App\Enums\DependencyParty;
use App\Enums\RecordVisibility;
use App\Models\BaselineItem;
use App\Models\Deliverable;
use App\Models\Dependency;
use App\Models\Engagement;
use App\Models\Stakeholder;
use App\Models\User;
use App\Rules\LinkedGovernanceRecords;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreDependencyRequest extends FormRequest
{
    /**
     * The record types a dependency may block (FA-20).
     *
     * @var list<class-string<Model>>
     */
    public const array LINKABLE = [
        BaselineItem::class,
        Deliverable::class,
    ];

    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $engagement = $this->route('engagement');

        return $engagement instanceof Engagement
            && ($this->user()?->can('create', [Dependency::class, $engagement]) ?? false);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $engagement = $this->route('engagement');

        return $this->dependencyRules($engagement instanceof Engagement ? $engagement : null);
    }

    /**
     * The register is person-level (FA-20): a customer-owed item names a
     * stakeholder of this engagement's customer, an internal one names a
     * colleague. The required date is typed, never prose, because the delay
     * is counted against it day for day.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    protected function dependencyRules(?Engagement $engagement): array
    {
        return [
            'title' => ['required', 'string', 'max:200'],
            'description' => ['nullable', 'string', 'max:5000'],
            'party' => ['required', Rule::enum(DependencyParty::class)],
            'responsible_stakeholder_id' => [
                'nullable',
                'uuid',
                Rule::exists(Stakeholder::class, 'id')->where('customer_id', $engagement?->customer_id),
            ],
            'responsible_user_id' => [
                'nullable',
                'uuid',
                Rule::exists(User::class, 'id')->where('organization_id', $engagement?->organization_id),
            ],
            'required_on' => ['required', 'date'],
            'visibility' => ['required', Rule::enum(RecordVisibility::class)],
            'links' => ['nullable', new LinkedGovernanceRecords($engagement, self::LINKABLE)],
        ];
    }

    /**
     * An item nobody owns cannot be chased, and a customer-owed item that is
     * not shared never reaches the action list it exists for.
     *
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $party = DependencyParty::tryFrom((string) $this->input('party'));

                if ($party === null) {
                    return;
                }

                if ($party->isCustomer() && $this->input('responsible_stakeholder_id') === null) {
                    $validator->errors()->add(
                        'responsible_stakeholder_id',
                        __('Name the customer stakeholder who owes this — "the client" cannot be chased.'),
                    );
                }

                if (! $party->isCustomer() && $this->input('responsible_user_id') === null) {
                    $validator->errors()->add(
                        'responsible_user_id',
                        __('Name the colleague who owes this — a dependency without a person is a wish.'),
                    );
                }

                if ($party->isCustomer() && $this->input('visibility') !== RecordVisibility::Shared->value) {
                    $validator->errors()->add(
                        'visibility',
                        __('An item the customer owes has to be shared with them — that is how it reaches their action list.'),
                    );
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
            'responsible_stakeholder_id.exists' => __('The responsible stakeholder must be a contact of this engagement\'s customer.'),
            'responsible_user_id.exists' => __('The responsible colleague must be a member of your organization.'),
        ];
    }
}
