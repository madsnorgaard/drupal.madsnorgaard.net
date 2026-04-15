<?php

declare(strict_types=1);

namespace Drupal\ai_content_drafter\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\node\NodeInterface;

/**
 * Groups unprocessed Source Items into draftable clusters.
 *
 * Clustering strategy (v1, keep it simple):
 *
 * 1. Pull every unprocessed Source Item, ordered by created desc.
 * 2. Group by field_topic (null topic gets its own "untagged" bucket).
 * 3. Within each topic bucket, split by time window — items created more
 *    than N seconds apart land in separate clusters so a single draft
 *    doesn't mix last week's news with yesterday's.
 * 4. Enforce min_group_size and max_group_size.
 *
 * This intentionally does NOT use vector similarity for clustering.
 * Topic + time window is deterministic and debuggable. Vector similarity
 * is only used later by StyleSampleCollector + SemanticLinker where the
 * downside of a surprising result is small.
 */
final class SourceItemGrouper {

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly LoggerChannelInterface $logger,
  ) {}

  /**
   * Build clusters from the current backlog.
   *
   * @return list<list<\Drupal\node\NodeInterface>>
   *   Each inner list is one cluster ready for the drafter to write about.
   */
  public function group(int $window_seconds, int $min_size, int $max_size): array {
    $storage = $this->entityTypeManager->getStorage('node');
    $nids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'source_item')
      ->condition('field_processed', 0)
      ->sort('created', 'DESC')
      ->range(0, 200)
      ->execute();

    if (!$nids) {
      return [];
    }

    // Bucket nodes by topic. Use a nested list (numeric keys) instead of
    // keying by created timestamp — a burst import of 40 items within one
    // second produces duplicate timestamps, and associative keys collapse
    // them so the clusterer sees a fraction of the real backlog.
    /** @var array<string, list<\Drupal\node\NodeInterface>> $by_topic */
    $by_topic = [];
    foreach ($storage->loadMultiple($nids) as $node) {
      \assert($node instanceof NodeInterface);
      $topic = 'untagged';
      if ($node->hasField('field_topic') && !$node->get('field_topic')->isEmpty()) {
        $topic = 'topic:' . $node->get('field_topic')->target_id;
      }
      $by_topic[$topic][] = $node;
    }

    // Ensure each topic bucket is sorted newest-first regardless of the
    // underlying entity query ordering.
    foreach ($by_topic as &$items) {
      \usort($items, static fn (NodeInterface $a, NodeInterface $b): int => $b->getCreatedTime() <=> $a->getCreatedTime());
    }
    unset($items);

    $clusters = [];
    foreach ($by_topic as $items) {
      $current = [];
      $anchor_ts = NULL;
      foreach ($items as $node) {
        $ts = $node->getCreatedTime();
        if ($anchor_ts === NULL) {
          $anchor_ts = $ts;
        }
        // Close the current cluster when we step outside the time window
        // OR when we hit the max size cap.
        if (($anchor_ts - $ts) > $window_seconds || \count($current) >= $max_size) {
          if (\count($current) >= $min_size) {
            $clusters[] = $current;
          }
          $current = [];
          $anchor_ts = $ts;
        }
        $current[] = $node;
      }
      if (\count($current) >= $min_size) {
        $clusters[] = $current;
      }
    }

    $this->logger->info('Grouped @items Source Items into @clusters clusters', [
      '@items' => \count($nids),
      '@clusters' => \count($clusters),
    ]);

    return $clusters;
  }

  /**
   * Mark a cluster's source items as processed so we don't re-draft them.
   */
  public function markProcessed(array $cluster): void {
    foreach ($cluster as $node) {
      \assert($node instanceof NodeInterface);
      $node->set('field_processed', 1);
      $node->save();
    }
  }

}
