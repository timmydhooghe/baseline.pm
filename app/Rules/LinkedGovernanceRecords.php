<?php

namespace App\Rules;

use App\Models\BaselineItem;
use App\Models\ChangeRequest;
use App\Models\Decision;
use App\Models\Deliverable;
use App\Models\Dependency;
use App\Models\Engagement;
use App\Models\Risk;
use App\Models\WorkItem;
use Closure;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Database\Eloquent\Model;

/**
 * Validates the linked-record chips the governance ledgers are built on
 * (FA-18, FA-19, FA-20): each entry names a record type the ledger accepts,
 * and that record must live on the same engagement. Without the second
 * check a chip could quietly point a risk at another client's deliverable —
 * the morph column would happily store it.
 */
class LinkedGovernanceRecords implements ValidationRule
{
    /**
     * @param  list<class-string<Model>>  $allowed  Record types this ledger may link to.
     */
    public function __construct(
        private readonly ?Engagement $engagement,
        private readonly array $allowed,
    ) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value === null || $value === []) {
            return;
        }

        if (! is_array($value) || ! array_is_list($value)) {
            $fail(__('Linked records must be sent as a list.'));

            return;
        }

        if ($this->engagement === null) {
            $fail(__('Linked records can only be resolved against an engagement.'));

            return;
        }

        foreach ($value as $entry) {
            if (! is_array($entry) || ! is_string($entry['type'] ?? null) || ! is_string($entry['id'] ?? null)) {
                $fail(__('Every linked record needs a type and an id.'));

                return;
            }

            if (! in_array($entry['type'], $this->allowed, true)) {
                $fail(__('Records of that kind cannot be linked here.'));

                return;
            }

            if (! $this->existsOnEngagement($entry['type'], $entry['id'])) {
                $fail(__('Linked records must belong to this engagement.'));

                return;
            }
        }
    }

    /**
     * Whether the record exists within the engagement. Baseline items reach
     * their engagement through the baseline they sit on; everything else
     * carries it directly.
     *
     * @param  class-string<Model>  $type
     */
    private function existsOnEngagement(string $type, string $id): bool
    {
        $engagementId = $this->engagement?->id;

        $query = match ($type) {
            BaselineItem::class => BaselineItem::query()->whereHas(
                'baseline',
                fn (Builder $baseline) => $baseline->where('engagement_id', $engagementId),
            ),
            Deliverable::class => Deliverable::query()->where('engagement_id', $engagementId),
            ChangeRequest::class => ChangeRequest::query()->where('engagement_id', $engagementId),
            Risk::class => Risk::query()->where('engagement_id', $engagementId),
            Dependency::class => Dependency::query()->where('engagement_id', $engagementId),
            Decision::class => Decision::query()->where('engagement_id', $engagementId),
            WorkItem::class => WorkItem::query()->where('engagement_id', $engagementId),
            default => null,
        };

        return $query?->whereKey($id)->exists() ?? false;
    }
}
