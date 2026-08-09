<?php

namespace App\Http\Requests\Decisions;

use App\Enums\DecisionStatus;
use App\Enums\RecordVisibility;
use App\Models\BaselineItem;
use App\Models\ChangeRequest;
use App\Models\Decision;
use App\Models\Deliverable;
use App\Models\Dependency;
use App\Models\Engagement;
use App\Models\Risk;
use App\Models\User;
use App\Models\WorkItem;
use App\Rules\LinkedGovernanceRecords;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreDecisionRequest extends FormRequest
{
    /**
     * The record types a decision may name (FA-18).
     *
     * @var list<class-string<Model>>
     */
    public const array LINKABLE = [
        BaselineItem::class,
        Deliverable::class,
        ChangeRequest::class,
        Risk::class,
        Dependency::class,
        WorkItem::class,
    ];

    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $engagement = $this->route('engagement');

        return $engagement instanceof Engagement
            && ($this->user()?->can('create', [Decision::class, $engagement]) ?? false);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $engagement = $this->route('engagement');

        return $this->decisionRules($engagement instanceof Engagement ? $engagement : null);
    }

    /**
     * The ledger's fields are structured (FA-18): alternatives as options
     * with a reason they lost, participants as named people, evidence as
     * labelled links, impact split into scope, budget and timeline rather
     * than one paragraph that cannot be queried. A draft only needs its
     * title and context — the outcome is required at confirmation, when
     * somebody actually stands behind it.
     *
     * Every reference is resolved against the engagement the record belongs
     * to, so drafting and editing share one definition of what is valid.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    protected function decisionRules(?Engagement $engagement, ?Decision $decision = null): array
    {
        return [
            'title' => ['required', 'string', 'max:200'],
            'context' => ['required', 'string', 'max:5000'],
            'decision' => ['nullable', 'string', 'max:5000'],
            'alternatives' => ['nullable', 'list'],
            'alternatives.*.option' => ['required', 'string', 'max:255'],
            'alternatives.*.why_not' => ['nullable', 'string', 'max:1000'],
            'participants' => ['nullable', 'list'],
            'participants.*.name' => ['required', 'string', 'max:120'],
            'participants.*.affiliation' => ['nullable', 'string', 'max:120'],
            'evidence' => ['nullable', 'list'],
            'evidence.*.label' => ['required', 'string', 'max:255'],
            'evidence.*.url' => ['nullable', 'url', 'max:2048'],
            /*
             * A form that removed the last row of a structured list has to
             * say so out loud: an absent list means "unchanged", so without
             * these flags the last alternative could never be deleted.
             */
            'alternatives_cleared' => ['sometimes', 'boolean'],
            'participants_cleared' => ['sometimes', 'boolean'],
            'evidence_cleared' => ['sometimes', 'boolean'],
            'impact_scope' => ['nullable', 'string', 'max:2000'],
            'impact_budget' => ['nullable', 'numeric', 'decimal:0,2', 'min:-100000000', 'max:100000000'],
            'impact_timeline_days' => ['nullable', 'integer', 'between:-3650,3650'],
            'visibility' => ['required', Rule::enum(RecordVisibility::class)],
            'decided_on' => ['nullable', 'date'],
            'decided_by' => [
                'nullable',
                'uuid',
                Rule::exists(User::class, 'id')->where('organization_id', $engagement?->organization_id),
            ],
            'supersedes_id' => [
                'nullable',
                'uuid',
                Rule::exists(Decision::class, 'id')
                    ->where('engagement_id', $engagement?->id)
                    ->where('status', DecisionStatus::Confirmed->value),
                function (string $attribute, mixed $value, Closure $fail) use ($decision): void {
                    /*
                     * The supersedes reference is unique, so a second draft
                     * claiming the same predecessor would only fail at
                     * confirmation time — as a database error rather than
                     * something the author can act on.
                     */
                    $claimed = Decision::query()
                        ->where('supersedes_id', $value)
                        ->when($decision !== null, fn ($query) => $query->whereKeyNot($decision?->id))
                        ->first();

                    if ($claimed !== null) {
                        $fail(__('That decision is already superseded by :title.', ['title' => $claimed->title]));
                    }
                },
            ],
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
            'supersedes_id.exists' => __('A decision can only supersede a confirmed decision on this engagement that nothing has replaced yet.'),
            'decided_by.exists' => __('The decision owner must be a member of your organization.'),
        ];
    }
}
