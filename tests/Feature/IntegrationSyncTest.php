<?php

use App\Enums\EngagementStatus;
use App\Enums\EstimateUnit;
use App\Enums\SyncRunStatus;
use App\Enums\WorkItemSource;
use App\Enums\WorkItemState;
use App\Jobs\SyncIntegrationConnection;
use App\Models\Engagement;
use App\Models\IntegrationConnection;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

/**
 * @return list<array<string, mixed>>
 */
function jiraIssues(): array
{
    return [
        [
            'id' => '10001',
            'key' => 'ENG-1',
            'fields' => [
                'summary' => 'Build login flow',
                'status' => ['name' => 'In Progress', 'statusCategory' => ['key' => 'indeterminate']],
                'issuetype' => ['name' => 'Story'],
                'assignee' => ['displayName' => 'Dana Developer'],
                'timeoriginalestimate' => 28800,
                'updated' => '2026-08-01T10:00:00.000+0000',
                'worklog' => ['worklogs' => [
                    [
                        'id' => '20001',
                        'author' => ['displayName' => 'Dana Developer'],
                        'timeSpentSeconds' => 7200,
                        'started' => '2026-08-01T09:00:00.000+0000',
                    ],
                ]],
            ],
        ],
        [
            'id' => '10002',
            'key' => 'ENG-2',
            'fields' => [
                'summary' => 'Fix payment bug',
                'status' => ['name' => 'To Do', 'statusCategory' => ['key' => 'new']],
                'issuetype' => ['name' => 'Bug'],
                'assignee' => null,
                'timeoriginalestimate' => null,
                'updated' => '2026-08-02T10:00:00.000+0000',
                'worklog' => ['worklogs' => []],
            ],
        ],
    ];
}

/**
 * Fakes the Jira API against a by-reference issue list, so a test can mutate
 * the payload between sync passes — Http::fake keeps the first matching stub,
 * it cannot be re-registered.
 *
 * @param  list<array<string, mixed>>  $issues
 */
function fakeJira(array &$issues): void
{
    Http::fake(function (Request $request) use (&$issues) {
        if (str_contains($request->url(), '/rest/api/3/search/jql')) {
            return Http::response(['issues' => $issues]);
        }

        if (str_contains($request->url(), '/versions')) {
            return Http::response([
                ['id' => '30001', 'name' => 'v1.0', 'released' => true, 'releaseDate' => '2026-07-15'],
                ['id' => '30002', 'name' => 'v1.1', 'released' => false],
            ]);
        }

        return Http::response([], 404);
    });
}

test('a jira sync imports issues, worklogs and releases and records a successful run', function () {
    $issues = jiraIssues();
    fakeJira($issues);

    $connection = IntegrationConnection::factory()->create();

    (new SyncIntegrationConnection($connection))->handle();

    $engagement = $connection->engagement;

    expect($engagement->workItems()->count())->toBe(2)
        ->and($engagement->releases()->count())->toBe(2);

    $item = $engagement->workItems()->where('external_key', 'ENG-1')->sole();

    expect($item->source)->toBe(WorkItemSource::Jira)
        ->and($item->title)->toBe('Build login flow')
        ->and($item->state)->toBe(WorkItemState::InProgress)
        ->and($item->external_status)->toBe('In Progress')
        ->and($item->assignee_name)->toBe('Dana Developer')
        ->and($item->estimate_value)->toBe(28800.0)
        ->and($item->estimate_unit)->toBe(EstimateUnit::Seconds)
        ->and($item->external_url)->toBe('https://example.atlassian.net/browse/ENG-1')
        ->and($item->worklogs()->count())->toBe(1)
        ->and($item->worklogs()->sole()->seconds)->toBe(7200);

    $run = $connection->syncRuns()->sole();

    expect($run->status)->toBe(SyncRunStatus::Succeeded)
        ->and($run->counts)->toBe(['work_items' => 2, 'worklogs' => 1, 'releases' => 2])
        ->and($connection->refresh()->last_synced_at)->not->toBeNull();
});

test('resyncing updates items in place instead of duplicating them', function () {
    $issues = jiraIssues();
    fakeJira($issues);

    $connection = IntegrationConnection::factory()->create();

    (new SyncIntegrationConnection($connection))->handle();
    (new SyncIntegrationConnection($connection))->handle();

    expect($connection->engagement->workItems()->count())->toBe(2)
        ->and($connection->engagement->releases()->count())->toBe(2);

    $issues[0]['fields']['summary'] = 'Build login & SSO flow';
    $issues[0]['fields']['status'] = ['name' => 'Done', 'statusCategory' => ['key' => 'done']];

    (new SyncIntegrationConnection($connection))->handle();

    $item = $connection->engagement->workItems()->where('external_id', '10001')->sole();

    expect($connection->engagement->workItems()->count())->toBe(2)
        ->and($item->title)->toBe('Build login & SSO flow')
        ->and($item->state)->toBe(WorkItemState::Done)
        ->and($item->worklogs()->count())->toBe(1);
});

test('a linear sync imports issues and releases without worklogs', function () {
    Http::fake(function (Request $request) {
        $query = (string) ($request->data()['query'] ?? '');

        if (str_contains($query, 'issues(')) {
            return Http::response(['data' => ['issues' => ['nodes' => [
                [
                    'id' => 'b2f7f6a0-0000-0000-0000-000000000001',
                    'identifier' => 'ENG-7',
                    'title' => 'Design the portal shell',
                    'url' => 'https://linear.app/example/issue/ENG-7',
                    'estimate' => 3,
                    'updatedAt' => '2026-08-03T08:00:00.000Z',
                    'state' => ['name' => 'In Progress', 'type' => 'started'],
                    'assignee' => ['name' => 'Sam Designer'],
                ],
            ]]]]);
        }

        if (str_contains($query, 'releases(')) {
            return Http::response(['data' => ['releases' => ['nodes' => [
                ['id' => 'rel-1', 'name' => 'Portal beta', 'targetDate' => '2026-07-20', 'completedAt' => '2026-07-20T09:00:00.000Z', 'url' => null],
            ], 'pageInfo' => ['hasNextPage' => false, 'endCursor' => null]]]]);
        }

        return Http::response([], 404);
    });

    $connection = IntegrationConnection::factory()->linear()->create();

    (new SyncIntegrationConnection($connection))->handle();

    $item = $connection->engagement->workItems()->sole();

    expect($item->source)->toBe(WorkItemSource::Linear)
        ->and($item->external_key)->toBe('ENG-7')
        ->and($item->state)->toBe(WorkItemState::InProgress)
        ->and($item->estimate_unit)->toBe(EstimateUnit::Points)
        ->and($item->worklogs()->count())->toBe(0)
        ->and($connection->engagement->releases()->sole()->released)->toBeTrue();
});

test('a jira sync follows the search pagination to the end', function () {
    Http::fake(function (Request $request) {
        $url = $request->url();

        if (str_contains($url, '/rest/api/3/search/jql')) {
            if (str_contains($url, 'nextPageToken=page-2')) {
                return Http::response(['issues' => [jiraIssues()[1]]]);
            }

            return Http::response(['issues' => [jiraIssues()[0]], 'nextPageToken' => 'page-2']);
        }

        if (str_contains($url, '/versions')) {
            return Http::response([]);
        }

        return Http::response([], 404);
    });

    $connection = IntegrationConnection::factory()->create();

    (new SyncIntegrationConnection($connection))->handle();

    expect($connection->engagement->workItems()->pluck('external_key')->sort()->values()->all())
        ->toBe(['ENG-1', 'ENG-2']);
});

test('a jira issue with more worklogs than the search embeds fetches the complete list', function () {
    $issue = jiraIssues()[0];
    $issue['fields']['worklog'] = ['total' => 3, 'maxResults' => 1, 'worklogs' => [$issue['fields']['worklog']['worklogs'][0]]];

    Http::fake(function (Request $request) use ($issue) {
        $url = $request->url();

        if (str_contains($url, '/rest/api/3/issue/ENG-1/worklog')) {
            return Http::response(['total' => 3, 'worklogs' => [
                ['id' => '20001', 'author' => ['displayName' => 'Dana Developer'], 'timeSpentSeconds' => 7200, 'started' => '2026-08-01T09:00:00.000+0000'],
                ['id' => '20002', 'author' => ['displayName' => 'Dana Developer'], 'timeSpentSeconds' => 3600, 'started' => '2026-08-02T09:00:00.000+0000'],
                ['id' => '20003', 'author' => ['displayName' => 'Sam Ops'], 'timeSpentSeconds' => 1800, 'started' => '2026-08-03T09:00:00.000+0000'],
            ]]);
        }

        if (str_contains($url, '/rest/api/3/search/jql')) {
            return Http::response(['issues' => [$issue]]);
        }

        if (str_contains($url, '/versions')) {
            return Http::response([]);
        }

        return Http::response([], 404);
    });

    $connection = IntegrationConnection::factory()->create();

    (new SyncIntegrationConnection($connection))->handle();

    $item = $connection->engagement->workItems()->where('external_key', 'ENG-1')->sole();

    expect($item->worklogs()->count())->toBe(3)
        ->and((int) $item->worklogs()->sum('seconds'))->toBe(12600);
});

test('a linear sync follows the cursor pagination to the end', function () {
    Http::fake(function (Request $request) {
        $query = (string) ($request->data()['query'] ?? '');
        $after = data_get($request->data(), 'variables.after');

        if (str_contains($query, 'issues(')) {
            if ($after === 'cursor-1') {
                return Http::response(['data' => ['issues' => [
                    'nodes' => [['id' => 'lin-2', 'identifier' => 'ENG-8', 'title' => 'Second page item', 'state' => ['name' => 'Todo', 'type' => 'unstarted']]],
                    'pageInfo' => ['hasNextPage' => false, 'endCursor' => null],
                ]]]);
            }

            return Http::response(['data' => ['issues' => [
                'nodes' => [['id' => 'lin-1', 'identifier' => 'ENG-7', 'title' => 'First page item', 'state' => ['name' => 'Todo', 'type' => 'unstarted']]],
                'pageInfo' => ['hasNextPage' => true, 'endCursor' => 'cursor-1'],
            ]]]);
        }

        if (str_contains($query, 'releases(')) {
            return Http::response(['data' => ['releases' => ['nodes' => [], 'pageInfo' => ['hasNextPage' => false, 'endCursor' => null]]]]);
        }

        return Http::response([], 404);
    });

    $connection = IntegrationConnection::factory()->linear()->create();

    (new SyncIntegrationConnection($connection))->handle();

    expect($connection->engagement->workItems()->pluck('external_key')->sort()->values()->all())
        ->toBe(['ENG-7', 'ENG-8']);
});

test('a provider failure marks the run failed and rethrows for the queue to retry', function () {
    Http::fake(['example.atlassian.net/*' => Http::response('', 500)]);

    $connection = IntegrationConnection::factory()->create();

    expect(fn () => (new SyncIntegrationConnection($connection))->handle())
        ->toThrow(RequestException::class);

    $run = $connection->syncRuns()->sole();

    expect($run->status)->toBe(SyncRunStatus::Failed)
        ->and($run->error)->not->toBeNull()
        ->and($connection->refresh()->last_synced_at)->toBeNull();
});

test('a sync pass skips quietly when the connection was disconnected in the meantime', function () {
    Http::fake();

    $connection = IntegrationConnection::factory()->disconnected()->create();

    (new SyncIntegrationConnection($connection))->handle();

    expect($connection->syncRuns()->count())->toBe(0);

    Http::assertNothingSent();
});

test('a sync pass skips quietly when the engagement was archived in the meantime', function () {
    Http::fake();

    $engagement = Engagement::factory()->status(EngagementStatus::Archived)->create();
    $connection = IntegrationConnection::factory()
        ->for($engagement->organization)
        ->for($engagement)
        ->create();

    (new SyncIntegrationConnection($connection))->handle();

    expect($connection->syncRuns()->count())->toBe(0);

    Http::assertNothingSent();
});

test('the scheduled sync skips archived engagements — they are read-only', function () {
    Queue::fake();

    $activeConnection = IntegrationConnection::factory()->create();

    $archived = Engagement::factory()->status(EngagementStatus::Archived)->create();
    IntegrationConnection::factory()
        ->for($archived->organization)
        ->for($archived)
        ->create();

    $this->artisan('integrations:sync')->assertSuccessful();

    Queue::assertPushed(SyncIntegrationConnection::class, 1);
    Queue::assertPushed(SyncIntegrationConnection::class, fn (SyncIntegrationConnection $job): bool => $job->integration->is($activeConnection));
});
