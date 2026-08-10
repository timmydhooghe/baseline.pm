<?php

namespace App\Http\Requests\Burn;

use App\Models\BurnEntry;
use App\Models\BurnWeek;
use App\Models\Engagement;
use App\Models\RateCardRole;
use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Recording a week of burn (FA-16). Days are the typed field; euros are not
 * — every line prices from the rate card role it names, so nothing here
 * accepts an amount. Roles must belong to the version the engagement is
 * priced against, and each person or profile carries one line.
 *
 * Provenance is deliberately absent from these rules: where a figure came
 * from is derived at recording time from the worklogs and the plan, never
 * claimed by the form. A client that posted `worklog` beside a hand-typed
 * number would freeze a lie into an immutable snapshot.
 */
class RecordBurnWeekRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $engagement = $this->route('engagement');

        return $engagement instanceof Engagement
            && ($this->user()?->can('create', [BurnWeek::class, $engagement]) ?? false);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $engagement = $this->route('engagement');
        $engagement = $engagement instanceof Engagement ? $engagement : null;

        return [
            'week_start' => ['required', 'date'],
            'note' => ['nullable', 'string', 'max:1000'],
            'lines' => ['required', 'list', 'min:1'],
            'lines.*.rate_card_role_id' => [
                'required',
                'uuid',
                Rule::exists(RateCardRole::class, 'id')
                    ->where('rate_card_version_id', $this->pinnedRateCardVersionId($engagement)),
            ],
            'lines.*.days' => ['required', 'numeric', 'min:0.01', 'max:500'],
            'lines.*.person_name' => ['nullable', 'string', 'max:255'],
            'lines.*.user_id' => [
                'nullable',
                'uuid',
                Rule::exists(User::class, 'id')->where('organization_id', $engagement?->organization_id),
            ],
        ];
    }

    /**
     * A person cannot spend the same day twice, and one week is seven days
     * long however hard it was.
     *
     * The ceiling counts a person's whole week, not each of their lines:
     * somebody who split five developer days and five lead days across two
     * rows still claims ten days out of seven, and the resulting burn would
     * be frozen wrong. Names are compared folded and trimmed, so a stray
     * capital cannot split one person into two.
     *
     * A line naming nobody is exempt — a profile row aggregates a whole
     * team's week, and a team can legitimately spend more days than a week
     * has.
     *
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $seen = [];
                $daysPerPerson = [];
                $lastIndexPerPerson = [];

                foreach ((array) $this->input('lines', []) as $index => $line) {
                    if (! is_array($line)) {
                        continue;
                    }

                    $person = BurnEntry::normalizePerson($line['person_name'] ?? null);
                    $key = ($line['rate_card_role_id'] ?? '').'|'.($person ?? '');

                    if (isset($seen[$key])) {
                        $validator->errors()->add(
                            "lines.{$index}.days",
                            __('This person and profile already have a line this week — record their days once.'),
                        );
                    }

                    $seen[$key] = true;

                    if ($person === null) {
                        continue;
                    }

                    $daysPerPerson[$person] = ($daysPerPerson[$person] ?? 0.0) + (float) ($line['days'] ?? 0);
                    $lastIndexPerPerson[$person] = $index;
                }

                foreach ($daysPerPerson as $person => $days) {
                    if ($days <= 7) {
                        continue;
                    }

                    $validator->errors()->add(
                        "lines.{$lastIndexPerPerson[$person]}.days",
                        __('A week holds seven days — :person cannot have spent :days across their lines.', [
                            'person' => $person,
                            'days' => rtrim(rtrim(number_format($days, 2, '.', ''), '0'), '.'),
                        ]),
                    );
                }
            },
        ];
    }

    /**
     * The rate card version the engagement's burn prices against: the
     * approved baseline's pin, or the organization's current version while
     * no baseline is approved yet.
     */
    private function pinnedRateCardVersionId(?Engagement $engagement): ?string
    {
        if ($engagement === null) {
            return null;
        }

        $pinned = $engagement->approvedBaseline()?->rate_card_version_id;

        return $pinned ?? $engagement->organization->currentRateCardVersion()?->id;
    }
}
