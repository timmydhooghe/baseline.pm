<?php

use Illuminate\Database\Migrations\Migration;
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
        DB::table('audit_logs')
            ->where('subject_type', 'App\Models\Engagement')
            ->whereNull('engagement_id')
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

    public function down(): void
    {
        Schema::table('audit_logs', function (Blueprint $table): void {
            $table->dropIndex(['engagement_id', 'created_at']);
            $table->dropConstrainedForeignId('engagement_id');
        });
    }
};
