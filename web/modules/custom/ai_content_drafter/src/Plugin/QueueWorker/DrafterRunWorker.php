<?php

declare(strict_types=1);

namespace Drupal\ai_content_drafter\Plugin\QueueWorker;

use Drupal\ai_content_drafter\Service\DraftPipeline;
use Drupal\Core\Queue\QueueWorkerBase;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Drain up to N Source Item clusters into drafts.
 *
 * @QueueWorker(
 *   id = "ai_content_drafter_run",
 *   title = @Translation("AI Content Drafter: run"),
 *   cron = {"time" = 120}
 * )
 */
final class DrafterRunWorker extends QueueWorkerBase implements ContainerFactoryPluginInterface {

  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    private readonly DraftPipeline $pipeline,
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
      $container->get('ai_content_drafter.pipeline'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function processItem($data): void {
    $max = isset($data['max']) && \is_int($data['max']) ? $data['max'] : 5;
    $this->pipeline->run($max);
  }

}
