<?php

declare(strict_types=1);

namespace Drupal\ai_content_ingest\Logger;

use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Psr\Log\LoggerInterface;

/**
 * Decorator that filters out a specific class of noisy log entries in CLI.
 *
 * When we save nodes from CLI contexts (drush, queue workers, cron), the
 * pathauto module's pattern matcher tries to evaluate block visibility
 * conditions that expect a 'node' context, can't find one, throws a
 * ContextException, catches it, and logs it via
 * \Drupal\Core\Utility\Error::logException(). The save itself succeeds
 * but drush stdout gets flooded with lines like:
 *
 *   [error] ContextException: Assigned contexts were not satisfied: node
 *     in Drupal\Core\Plugin\Context\ContextHandler->applyContextMapping()
 *
 * This is known, upstream, and harmless. The clean fix would be a patch
 * to pathauto to skip the log call in CLI contexts. Until that happens
 * we intercept it at the logger-factory layer: for the pathauto channel
 * only, wrap the channel returned by the real factory so log entries
 * matching the ContextException signature are dropped during CLI runs.
 * Web requests and other channels keep logging normally.
 *
 * Registered via service decoration:
 *
 *   ai_content_ingest.cli_noise_filter_logger_factory:
 *     class: Drupal\ai_content_ingest\Logger\CliNoiseFilterLoggerFactory
 *     decorates: logger.factory
 *     arguments: ['@.inner']
 */
final class CliNoiseFilterLoggerFactory implements LoggerChannelFactoryInterface {

  /**
   * Cached filtered channels, keyed by channel name.
   *
   * @var array<string, \Psr\Log\LoggerInterface>
   */
  private array $filtered = [];

  public function __construct(
    private readonly LoggerChannelFactoryInterface $inner,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function get($channel): LoggerInterface {
    $real = $this->inner->get($channel);
    if (\PHP_SAPI !== 'cli' || $channel !== 'pathauto') {
      return $real;
    }
    return $this->filtered[$channel] ??= new ContextExceptionFilteredChannel($real);
  }

  /**
   * {@inheritdoc}
   */
  public function addLogger(LoggerInterface $logger, $priority = 0): void {
    $this->inner->addLogger($logger, $priority);
  }

}
