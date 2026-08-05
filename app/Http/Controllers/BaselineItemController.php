<?php

namespace App\Http\Controllers;

use App\Enums\BaselineItemType;
use App\Http\Requests\Baselines\StoreBaselineItemRequest;
use App\Http\Requests\Baselines\UpdateBaselineItemRequest;
use App\Models\Baseline;
use App\Models\BaselineItem;
use App\ValueObjects\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

class BaselineItemController extends Controller
{
    /**
     * Add a typed contract item to the draft baseline (wizard step 3).
     */
    public function store(StoreBaselineItemRequest $request, Baseline $baseline): RedirectResponse
    {
        $validated = $request->validated();
        $type = BaselineItemType::from($validated['type']);

        $baseline->items()->create([
            'organization_id' => $baseline->organization_id,
            'type' => $type,
            'position' => (int) $baseline->items()->where('type', $type)->max('position') + 1,
            ...self::itemAttributes($type, $validated),
        ]);

        Inertia::flash('toast', ['type' => 'success', 'message' => __(':type added.', ['type' => $type->label()])]);

        return to_route('engagements.baseline.show', $baseline->engagement_id);
    }

    /**
     * Update a contract item on the draft baseline. The type never changes.
     */
    public function update(UpdateBaselineItemRequest $request, Baseline $baseline, BaselineItem $item): RedirectResponse
    {
        $item->update(self::itemAttributes($item->type, $request->validated()));

        Inertia::flash('toast', ['type' => 'success', 'message' => __(':type saved.', ['type' => $item->type->label()])]);

        return to_route('engagements.baseline.show', $baseline->engagement_id);
    }

    /**
     * Remove a contract item from the draft baseline.
     */
    public function destroy(Request $request, Baseline $baseline, BaselineItem $item): RedirectResponse
    {
        Gate::authorize('update', $baseline);

        $item->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => __(':type removed.', ['type' => $item->type->label()])]);

        return to_route('engagements.baseline.show', $baseline->engagement_id);
    }

    /**
     * Map validated input onto item columns for the given type.
     *
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    private static function itemAttributes(BaselineItemType $type, array $validated): array
    {
        $attributes = [
            'title' => $validated['title'],
            'description' => $validated['description'] ?? null,
            'clause_reference' => $validated['clause_reference'],
        ];

        if ($type === BaselineItemType::Deliverable) {
            $value = $validated['value'] ?? null;

            /** @var list<array{criterion: string, verification_method?: string|null}>|null $criteria */
            $criteria = $validated['acceptance_criteria'] ?? null;

            $attributes += [
                'owner_id' => $validated['owner_id'] ?? null,
                'value' => $value === null ? null : self::eurosToMoney($value),
                'acceptance_criteria' => $criteria === null ? null : array_map(
                    fn (array $criterion): array => [
                        'criterion' => $criterion['criterion'],
                        'verification_method' => ($criterion['verification_method'] ?? '') === '' ? null : $criterion['verification_method'],
                    ],
                    $criteria,
                ),
            ];
        }

        if ($type === BaselineItemType::Milestone) {
            $attributes += [
                'baseline_date' => $validated['baseline_date'] ?? null,
                'payment_trigger' => $validated['payment_trigger'] ?? null,
            ];
        }

        return $attributes;
    }

    /**
     * Convert a validated decimal euro amount (e.g. "48000.50") to Money.
     */
    private static function eurosToMoney(string|int|float $euros): Money
    {
        return Money::fromCents((int) round((float) $euros * 100));
    }
}
