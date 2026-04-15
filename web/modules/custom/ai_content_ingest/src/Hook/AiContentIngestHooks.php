<?php

declare(strict_types=1);

namespace Drupal\ai_content_ingest\Hook;

use Drupal\ai_content_ingest\Service\GithubPoller;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Queue\QueueFactory;
use Drupal\Core\State\StateInterface;

/**
 * OOP hook implementations for AI Content Ingest.
 */
final class AiContentIngestHooks {

  /**
   * State key storing the unix timestamp of the last completed GitHub poll.
   */
  private const LAST_GITHUB_POLL_STATE_KEY = 'ai_content_ingest.last_github_poll';

  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
    private readonly StateInterface $state,
    private readonly QueueFactory $queueFactory,
    private readonly LoggerChannelInterface $logger,
  ) {}

  /**
   * Queue a GitHub poll item per watched repo on cron.
   *
   * Throttled by github_poll_interval_seconds so a stuck cron run (or a busy
   * site running cron every minute) doesn't hammer the GitHub API.
   */
  #[Hook('cron')]
  public function cron(): void {
    $settings = $this->configFactory->get('ai_content_ingest.settings');
    $repos = $settings->get('github_repos') ?: [];
    if (!$repos) {
      return;
    }

    $interval = (int) ($settings->get('github_poll_interval_seconds') ?? 3600);
    $last = (int) ($this->state->get(self::LAST_GITHUB_POLL_STATE_KEY) ?? 0);
    if (($last + $interval) > \time()) {
      return;
    }

    $queue = $this->queueFactory->get(GithubPoller::QUEUE_NAME);
    foreach ($repos as $repo) {
      // Guard against stray whitespace and obviously bogus slugs.
      $repo = trim((string) $repo);
      if (!preg_match('#^[A-Za-z0-9][A-Za-z0-9._-]{0,38}/[A-Za-z0-9._-]{1,100}$#', $repo)) {
        $this->logger->warning('Skipping invalid GitHub repo slug @slug', [
          '@slug' => $repo,
        ]);
        continue;
      }
      $queue->createItem(['repo' => $repo]);
    }

    $this->state->set(self::LAST_GITHUB_POLL_STATE_KEY, \time());
  }

}
