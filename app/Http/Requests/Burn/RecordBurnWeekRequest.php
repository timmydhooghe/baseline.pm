<?php

namespace App\Http\Requests\Burn;

use App\Enums\BurnSource;
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
            'lines.*.source' => ['required', Rule::enum(BurnSource::class)],
        ];
    }

    /**
     * A person cannot spend the same day twice, and one week is seven days
     * long however hard it was. Both refusals belong here rather than in a
     * database constraint: "the same person" is a name plus a profile, and
     * the seven-day ceiling only applies to a line that names somebody — a
     * profile line aggregates a whole team's week.
     *
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $seen = [];

                foreach ((array) $this->input('lines', []) as $index => $line) {
                    if (! is_array($line)) {
                        continue;
                    }

                    $person = $line['person_name'] ?? null;
                    $key = ($line['rate_card_role_id'] ?? '').'|'.($person ?? '');

                    if (isset($seen[$key])) {
                        $validator->errors()->add(
                            "lines.{$index}.days",
                            __('This person and profile already have a line this week — record their days once.'),
                        );
                    }

                    $seen[$key] = true;

                    if ($person !== null && (float) ($line['days'] ?? 0) > 7) {
                        $validator->errors()->add(
                            "lines.{$index}.days",
                            __('A week holds seven days — :person cannot have spent more.', ['person' => $person]),
                        );
                    }
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
