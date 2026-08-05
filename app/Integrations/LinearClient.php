<?php

namespace App\Integrations;

use App\Enums\EstimateUnit;
use App\Enums\WorkItemState;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Linear GraphQL client, authenticated with a personal or OAuth API key and
 * scoped to one team by key. Linear state types normalize onto
 * WorkItemState; estimates are points. Releases carry their lifecycle in the
 * pipeline stage timestamps — completedAt set means shipped (there is no
 * status field on Release). Both connections follow cursor pagination to
 * completion. Linear has no native time tracking, so issues sync without
 * worklogs — standalone burn entry covers time there (FA-16).
 */
final readonly class LinearClient implements ProviderClient
{
    private const string ENDPOINT = 'https://api.linear.app/graphql';

    /**
     * Safety valve against a runaway pagination loop; far above any project
     * this product governs.
     */
    private const int MAX_SYNCED_ISSUES = 5000;

    public function __construct(
        private string $apiToken,
        private string $teamKey,
    ) {}

    public function fetchIssues(): array
    {
        $issues = [];
        $cursor = null;

        do {
            $response = $this->query(
                <<<'GRAPHQL'
                query Issues($team: String!, $after: String) {
                  issues(filter: { team: { key: { eq: $team } } }, first: 100, after: $after) {
                    nodes {
                      id
                      identifier
                      title
                      url
                      estimate
                      updatedAt
                      state { name type }
                      assignee { name }
                    }
                    pageInfo { hasNextPage endCursor }
                  }
                }
                GRAPHQL,
                array_filter(['team' => $this->teamKey, 'after' => $cursor]),
            );

            /** @var list<array<string, mixed>> $nodes */
            $nodes = $response->json('data.issues.nodes', []);

            foreach ($nodes as $node) {
                $issues[] = new SyncedIssue(
                    externalId: (string) $node['id'],
                    externalKey: data_get($node, 'identifier'),
                    title: (string) $node['title'],
                    externalStatus: data_get($node, 'state.name'),
                    state: $this->state((string) data_get($node, 'state.type', 'backlog')),
                    type: null,
                    assigneeName: data_get($node, 'assignee.name'),
                    url: data_get($node, 'url'),
                    estimateValue: isset($node['estimate']) ? (float) $node['estimate'] : null,
                    estimateUnit: isset($node['estimate']) ? EstimateUnit::Points : null,
                    externalUpdatedAt: isset($node['updatedAt']) ? CarbonImmutable::parse((string) $node['updatedAt']) : null,
                );
            }

            $cursor = $this->nextCursor($response, 'data.issues.pageInfo');
        } while ($cursor !== null && $nodes !== [] && count($issues) < self::MAX_SYNCED_ISSUES);

        return $issues;
    }

    public function fetchReleases(): array
    {
        $releases = [];
        $cursor = null;

        do {
            $response = $this->query(
                <<<'GRAPHQL'
                query Releases($team: String!, $after: String) {
                  releases(filter: { team: { key: { eq: $team } } }, first: 50, after: $after) {
                    nodes { id name url targetDate completedAt }
                    pageInfo { hasNextPage endCursor }
                  }
                }
                GRAPHQL,
                array_filter(['team' => $this->teamKey, 'after' => $cursor]),
            );

            /** @var list<array<string, mixed>> $nodes */
            $nodes = $response->json('data.releases.nodes', []);

            foreach ($nodes as $node) {
                $releases[] = new SyncedRelease(
                    externalId: (string) $node['id'],
                    name: (string) $node['name'],
                    released: isset($node['completedAt']),
                    releasedOn: isset($node['completedAt']) ? CarbonImmutable::parse((string) $node['completedAt']) : null,
                    url: data_get($node, 'url'),
                );
            }

            $cursor = $this->nextCursor($response, 'data.releases.pageInfo');
        } while ($cursor !== null && $nodes !== []);

        return $releases;
    }

    public function postIssueComment(string $issueId, string $body): void
    {
        $this->query(
            <<<'GRAPHQL'
            mutation CommentCreate($issueId: String!, $body: String!) {
              commentCreate(input: { issueId: $issueId, body: $body }) { success }
            }
            GRAPHQL,
            ['issueId' => $issueId, 'body' => $body],
        );
    }

    private function state(string $type): WorkItemState
    {
        return match ($type) {
            'started' => WorkItemState::InProgress,
            'completed' => WorkItemState::Done,
            'canceled' => WorkItemState::Canceled,
            default => WorkItemState::Todo,
        };
    }

    private function nextCursor(Response $response, string $pageInfoPath): ?string
    {
        if ($response->json("{$pageInfoPath}.hasNextPage") !== true) {
            return null;
        }

        $cursor = $response->json("{$pageInfoPath}.endCursor");

        return is_string($cursor) ? $cursor : null;
    }

    /**
     * @param  array<string, string>  $variables
     */
    private function query(string $query, array $variables): Response
    {
        $response = Http::withHeaders(['Authorization' => $this->apiToken])
            ->acceptJson()
            ->post(self::ENDPOINT, ['query' => $query, 'variables' => $variables])
            ->throw();

        if ($response->json('errors') !== null) {
            throw new RuntimeException('Linear rejected the request: '.json_encode($response->json('errors')));
        }

        return $response;
    }
}
