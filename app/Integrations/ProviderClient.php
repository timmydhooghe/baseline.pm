<?php

namespace App\Integrations;

/**
 * What every execution-tool client must offer (FA-7): pull issues (with
 * their worklogs) and releases into normalized DTOs, and push a comment back
 * to an issue — the outbound half of the two-way sync, used to annotate an
 * issue when it is mapped to a deliverable (FA-8).
 */
interface ProviderClient
{
    /**
     * @return list<SyncedIssue>
     */
    public function fetchIssues(): array;

    /**
     * @return list<SyncedRelease>
     */
    public function fetchReleases(): array;

    /**
     * Post a comment on an issue, identified the way the provider prefers
     * (issue key for Jira, issue id for Linear).
     */
    public function postIssueComment(string $issueId, string $body): void;
}
