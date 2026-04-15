<?php

declare(strict_types=1);

namespace Drupal\ai_content_ingest\Logger;

use Psr\Log\LoggerInterface;

/**
 * PSR-3 logger that drops pathauto ContextException entries.
 *
 * Used exclusively from CliNoiseFilterLoggerFactory, which only wraps the
 * 'pathauto' channel during CLI runs. Anything that doesn't match the
 * ContextException signature is forwarded verbatim to the inner channel.
 */
final class ContextExceptionFilteredChannel implements LoggerInterface {

  public function __construct(
    private readonly LoggerInterface $inner,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function log($level, string|\Stringable $message, array $context = []): void {
    if ($this->shouldDrop((string) $message, $context)) {
      return;
    }
    $this->inner->log($level, $message, $context);
  }

  /**
   * Detect the pathauto ContextException noise signature.
   */
  private function shouldDrop(string $message, array $context): bool {
    if (\str_contains($message, 'Assigned contexts were not satisfied')) {
      return TRUE;
    }
    $type = (string) ($context['%type'] ?? '');
    if ($type !== '' && \str_contains($type, 'ContextException')) {
      return TRUE;
    }
    return FALSE;
  }

  /**
   * {@inheritdoc}
   */
  public function emergency(string|\Stringable $message, array $context = []): void {
    $this->log('emergency', $message, $context);
  }

  /**
   * {@inheritdoc}
   */
  public function alert(string|\Stringable $message, array $context = []): void {
    $this->log('alert', $message, $context);
  }

  /**
   * {@inheritdoc}
   */
  public function critical(string|\Stringable $message, array $context = []): void {
    $this->log('critical', $message, $context);
  }

  /**
   * {@inheritdoc}
   */
  public function error(string|\Stringable $message, array $context = []): void {
    $this->log('error', $message, $context);
  }

  /**
   * {@inheritdoc}
   */
  public function warning(string|\Stringable $message, array $context = []): void {
    $this->log('warning', $message, $context);
  }

  /**
   * {@inheritdoc}
   */
  public function notice(string|\Stringable $message, array $context = []): void {
    $this->log('notice', $message, $context);
  }

  /**
   * {@inheritdoc}
   */
  public function info(string|\Stringable $message, array $context = []): void {
    $this->log('info', $message, $context);
  }

  /**
   * {@inheritdoc}
   */
  public function debug(string|\Stringable $message, array $context = []): void {
    $this->log('debug', $message, $context);
  }

}
