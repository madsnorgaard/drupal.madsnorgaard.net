<?php

declare(strict_types=1);

namespace Drupal\ai_content_ingest\Service;

use Drupal\Component\Serialization\Json;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\State\StateInterface;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;

/**
 * Fetches GitHub activity for a watched repo and stores it as Source Items.
 *
 * State API tracks the last-seen event id per repo so we never re-create a
 * node for the same commit or release. GitHub REST returns events newest-
 * first; we walk until we hit the last-seen id and stop.
 *
 * No access to private repos and no OAuth by design — the module is meant
 * for the site owner's own public repositories. A future enhancement could
 * add a drupal/key-backed GitHub token for higher rate limits and private
 * repo support.
 */
final class GithubPoller {

  /**
   * Queue name used by the corresponding queue worker plugin.
   */
  public const QUEUE_NAME = 'ai_content_ingest_github';

  /**
   * State key prefix for the last-seen GitHub event id per repo.
   */
  private const LAST_EVENT_STATE_KEY = 'ai_content_ingest.github_last_event:';

  /**
   * Maximum events to pull per poll. GitHub's default page size is 30.
   */
  private const MAX_EVENTS_PER_POLL = 30;

  public function __construct(
    private readonly ClientInterface $httpClient,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly StateInterface $state,
    private readonly LoggerChannelInterface $logger,
  ) {}

  /**
   * Poll one GitHub repo and create Source Item nodes for new events.
   *
   * @param string $repo
   *   The owner/repo slug. Already validated by the queue producer for
   *   shape — do NOT skip validation in callers that bypass the hook.
   * @param list<string> $event_types
   *   Allowed event type names (see the GitHub Events API docs).
   *
   * @return int
   *   Number of Source Item nodes created.
   */
  public function poll(string $repo, array $event_types): int {
    // Defence in depth: re-validate shape before any HTTP call so a rogue
    // caller can't inject arbitrary URL parts into the request path.
    if (!preg_match('#^[A-Za-z0-9][A-Za-z0-9._-]{0,38}/[A-Za-z0-9._-]{1,100}$#', $repo)) {
      $this->logger->warning('Rejected GitHub poll for invalid repo slug @slug', ['@slug' => $repo]);
      return 0;
    }

    $state_key = self::LAST_EVENT_STATE_KEY . $repo;
    $last_seen = (string) ($this->state->get($state_key) ?? '');

    try {
      $response = $this->httpClient->request('GET', sprintf('https://api.github.com/repos/%s/events', $repo), [
        'headers' => [
          'Accept' => 'application/vnd.github+json',
          'User-Agent' => 'drupal-ai-content-ingest',
          'X-GitHub-Api-Version' => '2022-11-28',
        ],
        'timeout' => 10,
        'connect_timeout' => 5,
        'query' => ['per_page' => self::MAX_EVENTS_PER_POLL],
      ]);
    }
    catch (GuzzleException $e) {
      $this->logger->error('GitHub poll for @repo failed: @msg', [
        '@repo' => $repo,
        '@msg' => $e->getMessage(),
      ]);
      return 0;
    }

    $body = (string) $response->getBody();
    $events = Json::decode($body);
    if (!is_array($events)) {
      $this->logger->error('GitHub poll for @repo returned non-array payload', ['@repo' => $repo]);
      return 0;
    }

    $created = 0;
    $newest_id = NULL;
    foreach ($events as $event) {
      $id = (string) ($event['id'] ?? '');
      if ($id === '') {
        continue;
      }
      // Stop walking when we hit something we've already seen.
      if ($last_seen !== '' && $id === $last_seen) {
        break;
      }
      // Remember the first (newest) id so we can resume cleanly next poll.
      $newest_id ??= $id;

      $type = (string) ($event['type'] ?? '');
      if ($type === '' || !in_array($type, $event_types, TRUE)) {
        continue;
      }

      $summary = $this->summarise($event, $repo);
      if ($summary === NULL) {
        continue;
      }

      if ($this->createSourceItem($id, $repo, $type, $summary)) {
        $created++;
      }
    }

    if ($newest_id !== NULL) {
      $this->state->set($state_key, $newest_id);
    }

    return $created;
  }

  /**
   * Build a human-readable summary of a GitHub event.
   *
   * @return array{title: string, url: string, body: string}|null
   *   A compact struct used by the Source Item writer, or NULL to skip.
   */
  private function summarise(array $event, string $repo): ?array {
    $type = (string) $event['type'];
    $payload = $event['payload'] ?? [];
    $repo_url = 'https://github.com/' . $repo;

    return match ($type) {
      'PushEvent' => $this->summarisePush($payload, $repo, $repo_url),
      'ReleaseEvent' => $this->summariseRelease($payload, $repo, $repo_url),
      'PullRequestEvent' => $this->summarisePullRequest($payload, $repo, $repo_url),
      'IssuesEvent' => $this->summariseIssue($payload, $repo, $repo_url),
      default => NULL,
    };
  }

  /**
   * Summarise a PushEvent payload into a Source Item struct.
   *
   * Handles both the webhook-style payload (full commits array) and the
   * compact form returned by /repos/{owner}/{repo}/events, which may ship
   * only push_id/ref/head/before without individual commit messages.
   */
  private function summarisePush(array $payload, string $repo, string $repo_url): ?array {
    $ref = (string) ($payload['ref'] ?? '');
    $branch = $ref !== '' ? preg_replace('#^refs/heads/#', '', $ref) : 'unknown';
    $head = (string) ($payload['head'] ?? '');
    $before = (string) ($payload['before'] ?? '');
    $commits = $payload['commits'] ?? [];

    // Rich form: webhook-style payload with an actual commits list.
    if (is_array($commits) && $commits !== []) {
      $first_sha = (string) ($commits[0]['sha'] ?? $head);
      $messages = [];
      foreach ($commits as $commit) {
        $messages[] = '- ' . trim((string) ($commit['message'] ?? ''));
      }
      return [
        'title' => sprintf('%s: %d commit%s pushed to %s',
          $repo,
          count($commits),
          count($commits) === 1 ? '' : 's',
          $branch,
        ),
        'url' => $first_sha !== '' ? $repo_url . '/commit/' . $first_sha : $repo_url,
        'body' => implode("\n", $messages),
      ];
    }

    // Compact form: no commits array, just point at the HEAD sha.
    if ($head === '') {
      return NULL;
    }
    $url = ($before !== '' && $before !== str_repeat('0', 40))
      ? $repo_url . '/compare/' . substr($before, 0, 12) . '...' . substr($head, 0, 12)
      : $repo_url . '/commit/' . $head;
    return [
      'title' => sprintf('%s: push to %s (HEAD %s)', $repo, $branch, substr($head, 0, 8)),
      'url' => $url,
      'body' => sprintf('Push to %s. Head %s. Compare %s..%s', $branch, $head, $before, $head),
    ];
  }

  /**
   * Summarise a ReleaseEvent payload into a Source Item struct.
   */
  private function summariseRelease(array $payload, string $repo, string $repo_url): ?array {
    $release = $payload['release'] ?? [];
    $name = (string) ($release['name'] ?? $release['tag_name'] ?? '');
    if ($name === '') {
      return NULL;
    }
    return [
      'title' => sprintf('%s: release %s', $repo, $name),
      'url' => (string) ($release['html_url'] ?? ($repo_url . '/releases')),
      'body' => (string) ($release['body'] ?? ''),
    ];
  }

  /**
   * Summarise a PullRequestEvent payload into a Source Item struct.
   */
  private function summarisePullRequest(array $payload, string $repo, string $repo_url): ?array {
    $pr = $payload['pull_request'] ?? [];
    $action = (string) ($payload['action'] ?? 'updated');
    $title = (string) ($pr['title'] ?? '');
    if ($title === '') {
      return NULL;
    }
    return [
      'title' => sprintf('%s: PR %s (%s)', $repo, $title, $action),
      'url' => (string) ($pr['html_url'] ?? $repo_url),
      'body' => (string) ($pr['body'] ?? ''),
    ];
  }

  /**
   * Summarise an IssuesEvent payload into a Source Item struct.
   */
  private function summariseIssue(array $payload, string $repo, string $repo_url): ?array {
    $issue = $payload['issue'] ?? [];
    $action = (string) ($payload['action'] ?? 'updated');
    $title = (string) ($issue['title'] ?? '');
    if ($title === '') {
      return NULL;
    }
    return [
      'title' => sprintf('%s: issue %s (%s)', $repo, $title, $action),
      'url' => (string) ($issue['html_url'] ?? $repo_url),
      'body' => (string) ($issue['body'] ?? ''),
    ];
  }

  /**
   * Create a Source Item node if we haven't already stored this event id.
   */
  private function createSourceItem(string $event_id, string $repo, string $type, array $summary): bool {
    $storage = $this->entityTypeManager->getStorage('node');
    $existing = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'source_item')
      ->condition('field_external_id', $event_id)
      ->range(0, 1)
      ->execute();
    if ($existing) {
      return FALSE;
    }

    $node = $storage->create([
      'type' => 'source_item',
      'title' => mb_substr($summary['title'], 0, 255),
      'status' => 0,
      'body' => [
        'value' => $summary['body'],
        'format' => 'plain_text',
      ],
      'field_source_url' => [
        'uri' => $summary['url'],
      ],
      'field_source_type' => 'github',
      'field_external_id' => $event_id,
      'field_processed' => 0,
    ]);
    $node->save();

    $this->logger->info('Stored GitHub @type event for @repo as node @nid', [
      '@type' => $type,
      '@repo' => $repo,
      '@nid' => $node->id(),
    ]);
    return TRUE;
  }

}
