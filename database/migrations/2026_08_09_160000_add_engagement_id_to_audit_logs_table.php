<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * FA-21 asks the audit log to be linked from everywhere. The subject morph
 * says what an entry is about, but not which engagement it belongs to —
 * answering that would mean joining every governed table in turn. The
 * engagement is resolved once at append time and stored beside the subject,
 * so an engagement's trail is one indexed read.
 */
return new class extends Migration
{
    /**
     * Where an already-recorded entry's engagement can be found: the morph
     * class it was written against, and the table that knows the answer.
     * Subjects that hang off a parent reach their engagement through it,
     * exactly as AuditLog::resolveEngagementId() does for new entries.
     *
     * @var array<string, array{table: string, via?: array{table: string, key: string}}>
     */
    private const array SUBJECT_SOURCES = [
        'App\Models\Baseline' => ['table' => 'baselines'],
        'App\Models\ChangeRequest' => ['table' => 'change_requests'],
        'App\Models\Deliverable' => ['table' => 'deliverables'],
        'App\Models\FinalAcceptance' => ['table' => 'final_acceptances'],
        'App\Models\IntegrationConnection' => ['table' => 'integration_connections'],
        'App\Models\Release' => ['table' => 'releases'],
        'App\Models\WorkItem' => ['table' => 'work_items'],
        'App\Models\BaselineAllocation' => ['table' => 'baselines', 'via' => ['table' => 'baseline_allocations', 'key' => 'baseline_id']],
        'App\Models\BaselineDocument' => ['table' => 'baselines', 'via' => ['table' => 'baseline_documents', 'key' => 'baseline_id']],
        'App\Models\BaselineItem' => ['table' => 'baselines', 'via' => ['table' => 'baseline_items', 'key' => 'baseline_id']],
        'App\Models\ChangeRequestAllocation' => ['table' => 'change_requests', 'via' => ['table' => 'change_request_allocations', 'key' => 'change_request_id']],
        'App\Models\ChangeRequestResponse' => ['table' => 'change_requests', 'via' => ['table' => 'change_request_responses', 'key' => 'change_request_id']],
        'App\Models\DeliverableEvidence' => ['table' => 'deliverables', 'via' => ['table' => 'deliverable_evidence', 'key' => 'deliverable_id']],
        'App\Models\DeliverableResponse' => ['table' => 'deliverables', 'via' => ['table' => 'deliverable_responses', 'key' => 'deliverable_id']],
        'App\Models\DeliverableVersion' => ['table' => 'deliverables', 'via' => ['table' => 'deliverable_versions', 'key' => 'deliverable_id']],
    ];

    public function up(): void
    {
        Schema::table('audit_logs', function (Blueprint $table): void {
            $table->foreignUuid('engagement_id')->nullable()->after('organization_id')->constrained()->nullOnDelete();

            $table->index(['engagement_id', 'created_at']);
        });

        $this->backfill();
    }

    /**
     * Entries appended before this column existed belong to an engagement
     * just as much as new ones do. Without this every engagement with
     * history would open an empty trail the day this ships — which is
     * precisely the claim FA-21 makes and would be breaking.
     *
     * Public so the resolution can be tested directly: the migration itself
     * only ever runs once, and getting this wrong is silent.
     */
    public function backfill(): void
    {
        $this->backfillFromLiveSubjects();
        $this->backfillFromPayloads();
    }

    /**
     * Resolve every entry whose subject — or the parent it hangs off — is
     * still in the database.
     */
    private function backfillFromLiveSubjects(): void
    {
        DB::table('audit_logs')
            ->where('subject_type', 'App\Models\Engagement')
            ->whereNull('engagement_id')
            /*
             * An engagement that was hard-deleted leaves its entries behind;
             * writing its id back would violate the foreign key this
             * migration just added.
             */
            ->whereIn('subject_id', fn (Builder $engagements) => $engagements->select('id')->from('engagements'))
            ->update(['engagement_id' => DB::raw('subject_id')]);

        foreach (self::SUBJECT_SOURCES as $subjectType => $source) {
            if (! Schema::hasTable($source['table'])) {
                continue;
            }

            $owner = $source['table'];
            $via = $source['via'] ?? null;

            if ($via !== null && ! Schema::hasTable($via['table'])) {
                continue;
            }

            $lookup = $via === null
                ? "select {$owner}.engagement_id from {$owner} where {$owner}.id = audit_logs.subject_id"
                : "select {$owner}.engagement_id from {$owner}"
                    ." inner join {$via['table']} on {$via['table']}.{$via['key']} = {$owner}.id"
                    ." where {$via['table']}.id = audit_logs.subject_id";

            DB::table('audit_logs')
                ->where('subject_type', $subjectType)
                ->whereNull('engagement_id')
                ->update(['engagement_id' => DB::raw("({$lookup})")]);
        }
    }

    /**
     * Deleted subjects have no row left to join to — a baseline item removed
     * from a draft, a document replaced, a role-mix line rewritten. Their
     * history is exactly the history the trail exists to keep, so the
     * engagement is read from the payload instead: the create and delete
     * entries carry the record's own attributes, parent reference included.
     *
     * One resolved entry answers for every entry about that subject, which
     * is what rescues the update entries in between — those carry only the
     * changed columns.
     */
    private function backfillFromPayloads(): void
    {
        $engagementBySubject = [];

        DB::table('audit_logs')
            ->whereNull('engagement_id')
            ->whereIn('subject_type', array_keys(self::SUBJECT_SOURCES))
            ->orderBy('id')
            ->each(function (object $entry) use (&$engagementBySubject): void {
                $subjectId = (string) $entry->subject_id;

                if (array_key_exists($subjectId, $engagementBySubject)) {
                    return;
                }

                $payload = json_decode((string) $entry->payload, true);

                if (! is_array($payload)) {
                    return;
                }

                $engagementId = $this->engagementFromPayload(
                    $payload,
                    self::SUBJECT_SOURCES[(string) $entry->subject_type],
                );

                if ($engagementId !== null) {
                    $engagementBySubject[$subjectId] = $engagementId;
                }
            });

        foreach ($engagementBySubject as $subjectId => $engagementId) {
            DB::table('audit_logs')
                ->where('subject_id', $subjectId)
                ->whereNull('engagement_id')
                ->update(['engagement_id' => $engagementId]);
        }
    }

    /**
     * The engagement a recorded payload points at: directly for records that
     * carry it, otherwise through the parent they name. A subject whose
     * parent is gone too stays unresolved — an invented reference would be
     * worse than an absent one.
     *
     * @param  array<string, mixed>  $payload
     * @param  array{table: string, via?: array{table: string, key: string}}  $source
     */
    private function engagementFromPayload(array $payload, array $source): ?string
    {
        $via = $source['via'] ?? null;

        if ($via === null) {
            $engagementId = $payload['engagement_id'] ?? null;

            return is_string($engagementId)
                && DB::table('engagements')->where('id', $engagementId)->exists()
                    ? $engagementId
                    : null;
        }

        $parentId = $payload[$via['key']] ?? null;

        if (! is_string($parentId)) {
            return null;
        }

        $engagementId = DB::table($source['table'])->where('id', $parentId)->value('engagement_id');

        return is_string($engagementId) ? $engagementId : null;
    }

    public function down(): void
    {
        Schema::table('audit_logs', function (Blueprint $table): void {
            $table->dropIndex(['engagement_id', 'created_at']);
            $table->dropConstrainedForeignId('engagement_id');
        });
    }
};
