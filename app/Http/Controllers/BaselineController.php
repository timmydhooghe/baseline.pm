<?php

namespace App\Http\Controllers;

use App\Enums\CommercialModel;
use App\Enums\ExecutionMode;
use App\Http\Requests\Baselines\AcknowledgeBaselineCheckRequest;
use App\Http\Requests\Baselines\StoreBaselineRequest;
use App\Http\Requests\Baselines\UpdateBaselineRequest;
use App\Models\Baseline;
use App\Models\BaselineAllocation;
use App\Models\BaselineDocument;
use App\Models\BaselineItem;
use App\Models\Engagement;
use App\Models\RateCardRole;
use App\Models\User;
use App\ValueObjects\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class BaselineController extends Controller
{
    /**
     * The baseline builder wizard. Shows the open draft (or the submitted /
     * approved baseline read-only); before any baseline exists it opens on
     * the details step. Commercials are internal-only — this page never
     * renders for portal users.
     */
    public function show(Request $request, Engagement $engagement): Response
    {
        Gate::authorize('view', $engagement);

        /** @var User $user */
        $user = $request->user();

        $baseline = $engagement->openBaseline() ?? $engagement->approvedBaseline();
        $baseline?->load(['items.owner', 'allocations.role', 'documents.uploadedBy', 'rateCardVersion.roles']);

        $rateCardVersion = $baseline !== null
            ? $baseline->rateCardVersion
            : $user->organization->currentRateCardVersion()?->load('roles');

        return Inertia::render('engagements/baseline', [
            'engagement' => [
                'id' => $engagement->id,
                'name' => $engagement->name,
                'status' => $engagement->status->value,
                'statusLabel' => $engagement->status->label(),
                'customerName' => $engagement->customer->name,
            ],
            'baseline' => $baseline === null ? null : $this->baselineViewModel($baseline),
            'rateCard' => $rateCardVersion === null ? null : [
                'version' => $rateCardVersion->version,
                'roles' => $rateCardVersion->roles
                    ->map(fn (RateCardRole $role): array => [
                        'id' => $role->id,
                        'name' => $role->name,
                        'costPerDay' => $role->cost_per_day->toArray(),
                        'sellPerDay' => $role->sell_per_day->toArray(),
                    ])
                    ->values(),
            ],
            'members' => $user->organization->users()
                ->orderBy('name')
                ->get()
                ->map(fn (User $member): array => [
                    'id' => $member->id,
                    'name' => $member->name,
                ]),
            'commercialModels' => collect(CommercialModel::cases())
                ->map(fn (CommercialModel $model): array => [
                    'value' => $model->value,
                    'label' => $model->label(),
                ]),
            'executionModes' => collect(ExecutionMode::cases())
                ->map(fn (ExecutionMode $mode): array => [
                    'value' => $mode->value,
                    'label' => $mode->label(),
                ]),
            'can' => [
                'manage' => $baseline === null
                    ? $user->can('create', [Baseline::class, $engagement])
                    : $user->can('update', $baseline),
            ],
        ]);
    }

    /**
     * Create the baseline draft from the details step, pinning the current
     * rate card version and moving a draft engagement into preparation.
     */
    public function store(StoreBaselineRequest $request, Engagement $engagement): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        $validated = $request->validated();

        $engagement->startBaseline([
            'commercial_model' => $validated['commercial_model'],
            'contract_value' => self::eurosToMoney($validated['contract_value']),
            'start_date' => $validated['start_date'],
            'end_date' => $validated['end_date'],
            'execution_mode' => $validated['execution_mode'],
        ], $user);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Baseline draft started.')]);

        return to_route('engagements.baseline.show', $engagement);
    }

    /**
     * Update the details step of a draft baseline.
     */
    public function update(UpdateBaselineRequest $request, Baseline $baseline): RedirectResponse
    {
        $validated = $request->validated();

        $baseline->update([
            'commercial_model' => $validated['commercial_model'],
            'contract_value' => self::eurosToMoney($validated['contract_value']),
            'start_date' => $validated['start_date'],
            'end_date' => $validated['end_date'],
            'execution_mode' => $validated['execution_mode'],
        ]);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Baseline details saved.')]);

        return to_route('engagements.baseline.show', $baseline->engagement_id);
    }

    /**
     * Record that a failing completeness check is accepted as-is.
     */
    public function acknowledge(AcknowledgeBaselineCheckRequest $request, Baseline $baseline): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        /** @var string $check */
        $check = $request->validated('check');

        $baseline->acknowledgeCheck($check, $user);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Warning acknowledged.')]);

        return to_route('engagements.baseline.show', $baseline->engagement_id);
    }

    /**
     * Submit the draft for customer approval, freezing the review snapshots.
     */
    public function submit(Request $request, Baseline $baseline): RedirectResponse
    {
        Gate::authorize('submit', $baseline);

        /** @var User $user */
        $user = $request->user();

        $baseline->submitForApproval($user);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Baseline v:version submitted for approval.', [
            'version' => $baseline->version,
        ])]);

        return to_route('engagements.baseline.show', $baseline->engagement_id);
    }

    /**
     * The full wizard view model of a baseline, commercials included.
     *
     * @return array<string, mixed>
     */
    private function baselineViewModel(Baseline $baseline): array
    {
        $budgets = $baseline->deliverableCostBudgets();
        $checks = $baseline->completenessChecks();

        return [
            'id' => $baseline->id,
            'version' => $baseline->version,
            'status' => $baseline->status->value,
            'statusLabel' => $baseline->status->label(),
            'commercialModel' => $baseline->commercial_model->value,
            'contractValue' => $baseline->contract_value->toArray(),
            'startDate' => $baseline->start_date->toDateString(),
            'endDate' => $baseline->end_date->toDateString(),
            'executionMode' => $baseline->execution_mode->value,
            'submittedAt' => $baseline->submitted_at?->toFormattedDateString(),
            'approvedAt' => $baseline->approved_at?->toFormattedDateString(),
            'documents' => $baseline->documents
                ->map(fn (BaselineDocument $document): array => [
                    'id' => $document->id,
                    'filename' => $document->filename,
                    'sizeBytes' => $document->size_bytes,
                    'uploadedAt' => $document->created_at?->toFormattedDateString(),
                    'uploadedBy' => $document->uploadedBy?->name,
                ])
                ->values(),
            'items' => $baseline->items
                ->map(fn (BaselineItem $item): array => [
                    'id' => $item->id,
                    'type' => $item->type->value,
                    'position' => $item->position,
                    'title' => $item->title,
                    'description' => $item->description,
                    'clauseReference' => $item->clause_reference,
                    'owner' => $item->owner === null ? null : [
                        'id' => $item->owner->id,
                        'name' => $item->owner->name,
                    ],
                    'value' => $item->value?->toArray(),
                    'acceptanceCriteria' => collect($item->acceptance_criteria ?? [])
                        ->map(fn (array $criterion): array => [
                            'criterion' => $criterion['criterion'],
                            'verificationMethod' => $criterion['verification_method'] ?? null,
                        ])
                        ->values(),
                    'baselineDate' => $item->baseline_date?->toDateString(),
                    'paymentTrigger' => $item->payment_trigger,
                ])
                ->values(),
            'allocations' => $baseline->allocations
                ->map(fn (BaselineAllocation $allocation): array => [
                    'id' => $allocation->id,
                    'baselineItemId' => $allocation->baseline_item_id,
                    'rateCardRoleId' => $allocation->rate_card_role_id,
                    'days' => $allocation->days,
                ])
                ->values(),
            'totals' => [
                'costBudget' => $baseline->costBudget()->toArray(),
                'deliveryManagementCost' => $baseline->deliveryManagementCost()->toArray(),
                'plannedMargin' => $baseline->plannedMargin()->toArray(),
                'deliverableBudgets' => collect($budgets)->map(fn (array $budget): array => [
                    'direct' => $budget['direct']->toArray(),
                    'budget' => $budget['budget']->toArray(),
                ]),
            ],
            'checks' => $checks,
            'canSubmit' => array_all($checks, fn (array $check): bool => $check['passed'] || $check['acknowledged']),
        ];
    }

    /**
     * Convert a validated decimal euro amount (e.g. "125000.50") to Money.
     */
    private static function eurosToMoney(string|int|float $euros): Money
    {
        return Money::fromCents((int) round((float) $euros * 100));
    }
}
