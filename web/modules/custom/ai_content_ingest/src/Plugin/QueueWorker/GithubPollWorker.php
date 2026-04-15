<?php

declare(strict_types=1);

namespace Drupal\ai_content_ingest\Plugin\QueueWorker;

use Drupal\ai_content_ingest\Service\GithubPoller;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Queue\QueueWorkerBase;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Process one queued GitHub repo poll.
 *
 * @QueueWorker(
 *   id = "ai_content_ingest_github",
 *   title = @Translation("AI Content Ingest: GitHub poller"),
 *   cron = {"time" = 30}
 * )
 */
final class GithubPollWorker extends QueueWorkerBase implements ContainerFactoryPluginInterface {

  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    private readonly GithubPoller $poller,
    private readonly ConfigFactoryInterface $configFactory,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): self {
    return new self(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('ai_content_ingest.github_poller'),
      $container->get('config.factory'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function processItem($data): void {
    if (!is_array($data) || !isset($data['repo']) || !is_string($data['repo'])) {
      return;
    }

    $event_types_raw = $this->configFactory
      ->get('ai_content_ingest.settings')
      ->get('github_event_types') ?? [];
    $event_types = array_map(
      static fn (string $short): string => match ($short) {
        'push' => 'PushEvent',
        'release' => 'ReleaseEvent',
        'pr', 'pull_request' => 'PullRequestEvent',
        'issue', 'issues' => 'IssuesEvent',
        default => $short,
      },
      array_filter($event_types_raw, 'is_string'),
    );

    $this->poller->poll($data['repo'], $event_types);
  }

}
