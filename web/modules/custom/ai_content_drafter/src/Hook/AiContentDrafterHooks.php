<?php

declare(strict_types=1);

namespace Drupal\ai_content_drafter\Hook;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Queue\QueueFactory;

/**
 * OOP hook implementations for the AI drafter.
 */
final class AiContentDrafterHooks {

  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
    private readonly QueueFactory $queueFactory,
    private readonly LoggerChannelInterface $logger,
  ) {}

  /**
   * Queue a single "drain clusters" job on cron.
   *
   * The job itself is idempotent and rate-limited inside DraftPipeline::run,
   * so queueing once per cron tick is enough. Splitting per-cluster would
   * just add queue bookkeeping with no benefit.
   */
  #[Hook('cron')]
  public function cron(): void {
    if (!(bool) $this->configFactory->get('ai_content_drafter.settings')->get('enabled')) {
      return;
    }
    $max = (int) ($this->configFactory->get('ai_content_ingest.settings')->get('max_drafts_per_run') ?: 5);
    $this->queueFactory->get('ai_content_drafter_run')->createItem(['max' => $max]);
  }

}
