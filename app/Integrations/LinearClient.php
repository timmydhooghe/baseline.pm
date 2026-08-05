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
 * WorkItemState; estimates are points. Linear has no native time tracking,
 * so issues sync without worklogs — standalone burn entry covers time there
 * (FA-16).
 */
final readonly class LinearClient implements ProviderClient
{
    private const string ENDPOINT = 'https://api.linear.app/graphql';

    public function __construct(
        private string $apiToken,
        private string $teamKey,
    ) {}

    public function fetchIssues(): array
    {
        $response = $this->query(
            <<<'GRAPHQL'
            query Issues($team: String!) {
              issues(filter: { team: { key: { eq: $team } } }, first: 100) {
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
              }
            }
            GRAPHQL,
            ['team' => $this->teamKey],
        );

        /** @var list<array<string, mixed>> $nodes */
        $nodes = $response->json('data.issues.nodes', []);

        return array_map(fn (array $node): SyncedIssue => new SyncedIssue(
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
        ), $nodes);
    }

    public function fetchReleases(): array
    {
        $response = $this->query(
            <<<'GRAPHQL'
            query Releases($team: String!) {
              releases(filter: { team: { key: { eq: $team } } }, first: 50) {
                nodes { id name status targetDate url }
              }
            }
            GRAPHQL,
            ['team' => $this->teamKey],
        );

        /** @var list<array<string, mixed>> $nodes */
        $nodes = $response->json('data.releases.nodes', []);

        return array_map(fn (array $node): SyncedRelease => new SyncedRelease(
            externalId: (string) $node['id'],
            name: (string) $node['name'],
            released: data_get($node, 'status') === 'released',
            releasedOn: isset($node['targetDate']) ? CarbonImmutable::parse((string) $node['targetDate']) : null,
            url: data_get($node, 'url'),
        ), $nodes);
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
