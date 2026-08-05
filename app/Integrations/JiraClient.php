<?php

namespace App\Integrations;

use App\Enums\EstimateUnit;
use App\Enums\WorkItemState;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/**
 * Jira Cloud REST v3 client, authenticated with the account email and an API
 * token against the customer's own site URL. Issues arrive with their status
 * category (new / indeterminate / done), which normalizes onto WorkItemState.
 * Search results and worklogs are fully paginated — the search response only
 * embeds the first page of worklogs, so issues with more fetch the complete
 * list separately. Releases are the project's versions.
 */
final readonly class JiraClient implements ProviderClient
{
    /**
     * Safety valve against a runaway pagination loop; far above any project
     * this product governs.
     */
    private const int MAX_SYNCED_ISSUES = 5000;

    private const int PAGE_SIZE = 100;

    public function __construct(
        private string $baseUrl,
        private string $email,
        private string $apiToken,
        private string $projectKey,
    ) {}

    public function fetchIssues(): array
    {
        $issues = [];
        $pageToken = null;

        do {
            $query = [
                'jql' => "project = \"{$this->projectKey}\" ORDER BY updated DESC",
                'fields' => 'summary,status,issuetype,assignee,timeoriginalestimate,updated,worklog',
                'maxResults' => self::PAGE_SIZE,
            ];

            if ($pageToken !== null) {
                $query['nextPageToken'] = $pageToken;
            }

            $response = $this->request()
                ->get('/rest/api/3/search/jql', $query)
                ->throw();

            /** @var list<array<string, mixed>> $page */
            $page = $response->json('issues', []);

            foreach ($page as $issue) {
                $issues[] = $this->issue($issue);
            }

            $pageToken = $response->json('nextPageToken');
        } while (is_string($pageToken) && $page !== [] && count($issues) < self::MAX_SYNCED_ISSUES);

        return $issues;
    }

    public function fetchReleases(): array
    {
        $response = $this->request()
            ->get("/rest/api/3/project/{$this->projectKey}/versions")
            ->throw();

        /** @var list<array<string, mixed>> $versions */
        $versions = $response->json() ?? [];

        return array_map(fn (array $version): SyncedRelease => new SyncedRelease(
            externalId: (string) $version['id'],
            name: (string) $version['name'],
            released: (bool) ($version['released'] ?? false),
            releasedOn: isset($version['releaseDate']) ? CarbonImmutable::parse((string) $version['releaseDate']) : null,
            url: null,
        ), $versions);
    }

    public function postIssueComment(string $issueId, string $body): void
    {
        $this->request()
            ->post("/rest/api/3/issue/{$issueId}/comment", [
                'body' => [
                    'type' => 'doc',
                    'version' => 1,
                    'content' => [
                        [
                            'type' => 'paragraph',
                            'content' => [['type' => 'text', 'text' => $body]],
                        ],
                    ],
                ],
            ])
            ->throw();
    }

    /**
     * @param  array<string, mixed>  $issue
     */
    private function issue(array $issue): SyncedIssue
    {
        /** @var array<string, mixed> $fields */
        $fields = $issue['fields'] ?? [];
        /** @var array<string, mixed> $embeddedWorklog */
        $embeddedWorklog = data_get($fields, 'worklog', []);
        $estimate = $fields['timeoriginalestimate'] ?? null;
        $key = (string) $issue['key'];

        return new SyncedIssue(
            externalId: (string) $issue['id'],
            externalKey: $key,
            title: (string) data_get($fields, 'summary', $key),
            externalStatus: data_get($fields, 'status.name'),
            state: $this->state((string) data_get($fields, 'status.statusCategory.key', 'new')),
            type: data_get($fields, 'issuetype.name'),
            assigneeName: data_get($fields, 'assignee.displayName'),
            url: rtrim($this->baseUrl, '/')."/browse/{$key}",
            estimateValue: $estimate === null ? null : (float) $estimate,
            estimateUnit: $estimate === null ? null : EstimateUnit::Seconds,
            externalUpdatedAt: isset($fields['updated']) ? CarbonImmutable::parse((string) $fields['updated']) : null,
            worklogs: $this->worklogs($key, $embeddedWorklog),
        );
    }

    /**
     * The search response embeds only the first page of an issue's worklogs;
     * when the total says there are more, fetch the complete list from the
     * worklog endpoint — otherwise burn data would silently truncate.
     *
     * @param  array<string, mixed>  $embeddedWorklog
     * @return list<SyncedWorklog>
     */
    private function worklogs(string $issueKey, array $embeddedWorklog): array
    {
        /** @var list<array<string, mixed>> $worklogs */
        $worklogs = $embeddedWorklog['worklogs'] ?? [];
        $total = (int) ($embeddedWorklog['total'] ?? count($worklogs));

        if ($total > count($worklogs)) {
            $worklogs = [];
            $startAt = 0;

            do {
                $response = $this->request()
                    ->get("/rest/api/3/issue/{$issueKey}/worklog", [
                        'startAt' => $startAt,
                        'maxResults' => self::PAGE_SIZE,
                    ])
                    ->throw();

                /** @var list<array<string, mixed>> $page */
                $page = $response->json('worklogs', []);
                $worklogs = [...$worklogs, ...$page];
                $startAt += count($page);
            } while ($page !== [] && $startAt < (int) $response->json('total', 0));
        }

        return array_map(fn (array $worklog): SyncedWorklog => new SyncedWorklog(
            externalId: (string) $worklog['id'],
            authorName: (string) data_get($worklog, 'author.displayName', 'Unknown'),
            seconds: (int) ($worklog['timeSpentSeconds'] ?? 0),
            loggedOn: CarbonImmutable::parse((string) ($worklog['started'] ?? now()->toIso8601String())),
        ), $worklogs);
    }

    /**
     * Jira's three status categories are the only workflow signal that is
     * stable across customer-specific workflows.
     */
    private function state(string $statusCategory): WorkItemState
    {
        return match ($statusCategory) {
            'indeterminate' => WorkItemState::InProgress,
            'done' => WorkItemState::Done,
            default => WorkItemState::Todo,
        };
    }

    private function request(): PendingRequest
    {
        return Http::baseUrl(rtrim($this->baseUrl, '/'))
            ->withBasicAuth($this->email, $this->apiToken)
            ->acceptJson();
    }
}
