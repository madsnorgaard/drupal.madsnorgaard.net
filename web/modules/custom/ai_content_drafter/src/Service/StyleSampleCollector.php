<?php

declare(strict_types=1);

namespace Drupal\ai_content_drafter\Service;

use Drupal\Component\Serialization\Json;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\node\NodeInterface;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;

/**
 * Collects style samples from Drupal's article corpus + an external WP REST.
 *
 * Two sources feed the author-voice context window:
 *
 *  - Internal: a small, semantically-close set of the user's existing article
 *    nodes, chosen by nearest-neighbour on the Solr dense_vector index.
 *    Fetched at draft time via search_api.
 *
 *  - External: a fixed batch of posts from an external WordPress REST API
 *    (defaults to photo.madsnorgaard.net/wp-json/wp/v2/posts), cached in the
 *    default cache bin for the lifetime of a cron run so multiple drafts in
 *    one tick share a single fetch. Public endpoints only — no auth.
 *
 * Only title + a trimmed plain-text body are extracted. HTML is stripped at
 * ingestion time so the LLM doesn't waste context on markup.
 */
final class StyleSampleCollector {

  /**
   * Cache bin key for WP sample fetches.
   */
  private const WP_CACHE_KEY = 'ai_content_drafter:wp_samples';

  /**
   * Cache lifetime for WP samples — 1 hour.
   */
  private const WP_CACHE_TTL = 3600;

  /**
   * Maximum characters we keep from each body when trimming.
   */
  private const MAX_SAMPLE_CHARS = 1600;

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly ClientInterface $httpClient,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly CacheBackendInterface $cache,
    private readonly LoggerChannelInterface $logger,
  ) {}

  /**
   * Fetch internal style samples matching the cluster's theme.
   *
   * @param list<\Drupal\node\NodeInterface> $cluster
   *   The Source Item cluster driving the draft.
   * @param int $count
   *   How many internal articles to return.
   *
   * @return list<array{title: string, body: string, url: string}>
   *   Ready-to-use sample structs.
   */
  public function internalSamples(array $cluster, int $count): array {
    if ($count <= 0 || $cluster === []) {
      return [];
    }

    // Use the cluster's concatenated titles + bodies as a query string.
    // The Solr dense vector index already stores article embeddings, so
    // kNN on this query returns the most semantically relevant articles
    // to the source material.
    $query_text = '';
    foreach ($cluster as $node) {
      \assert($node instanceof NodeInterface);
      $query_text .= $node->getTitle() . ". ";
      if ($node->hasField('body') && !$node->get('body')->isEmpty()) {
        $query_text .= \mb_substr((string) $node->get('body')->value, 0, 400) . "\n";
      }
    }

    $index_id = (string) ($this->configFactory->get('ai_content_drafter.settings')->get('draft_search_index') ?? 'content');
    $index_storage = $this->entityTypeManager->getStorage('search_api_index');
    $index = $index_storage->load($index_id);
    if (!$index) {
      $this->logger->warning('Style sample lookup: search_api index @id not found', ['@id' => $index_id]);
      return [];
    }

    try {
      // Headroom for deduping against the cluster's own node ids.
      $query = $index->query([
        'limit' => $count + 4,
        'parse_mode' => 'direct',
      ]);
      $query->keys($query_text);
      $query->setOption('search_api_bypass_access', TRUE);
      $result = $query->execute();
    }
    catch (\Throwable $e) {
      $this->logger->error('Style sample search failed: @msg', ['@msg' => $e->getMessage()]);
      return [];
    }

    $samples = [];
    $cluster_nids = \array_map(static fn (NodeInterface $n): int => (int) $n->id(), $cluster);

    foreach ($result->getResultItems() as $item) {
      $entity = NULL;
      try {
        $entity = $item->getOriginalObject()?->getValue();
      }
      catch (\Throwable) {
        continue;
      }
      if (!$entity instanceof NodeInterface) {
        continue;
      }
      // Only use target-bundle content as style samples, not Source Items
      // (they're raw inbound data) and not the cluster itself.
      if ($entity->bundle() === 'source_item' || \in_array((int) $entity->id(), $cluster_nids, TRUE)) {
        continue;
      }
      $samples[] = [
        'title' => $entity->getTitle(),
        'body' => $this->extractPlainText($entity),
        'url' => $entity->toUrl('canonical', ['absolute' => TRUE])->toString(),
      ];
      if (\count($samples) >= $count) {
        break;
      }
    }

    return $samples;
  }

  /**
   * Fetch external style samples from the configured WP REST endpoint.
   *
   * @return list<array{title: string, body: string, url: string}>
   *   Ready-to-use sample structs, possibly empty.
   */
  public function externalSamples(int $count): array {
    if ($count <= 0) {
      return [];
    }

    $url = (string) ($this->configFactory->get('ai_content_drafter.settings')->get('style_sample_url') ?? '');
    if ($url === '') {
      return [];
    }
    // SSRF guard: accept only https URLs to expected hosts.
    if (!\preg_match('#^https://[A-Za-z0-9.\-]+/wp-json/wp/v2/[a-z_/-]+$#', $url)) {
      $this->logger->warning('Rejected malformed style_sample_url: @url', ['@url' => $url]);
      return [];
    }

    $cache_key = self::WP_CACHE_KEY . ':' . \md5($url . ':' . $count);
    if ($cached = $this->cache->get($cache_key)) {
      return $cached->data;
    }

    try {
      $response = $this->httpClient->request('GET', $url, [
        'query' => [
          'per_page' => $count,
          '_fields' => 'id,title,excerpt,content,link',
        ],
        'timeout' => 10,
        'connect_timeout' => 5,
        'headers' => [
          'Accept' => 'application/json',
          'User-Agent' => 'drupal-ai-content-drafter',
        ],
      ]);
    }
    catch (GuzzleException $e) {
      $this->logger->error('Style sample fetch failed: @msg', ['@msg' => $e->getMessage()]);
      return [];
    }

    $posts = Json::decode((string) $response->getBody());
    if (!\is_array($posts)) {
      return [];
    }

    $samples = [];
    foreach ($posts as $post) {
      $title_raw = (string) ($post['title']['rendered'] ?? '');
      $content_raw = (string) ($post['content']['rendered'] ?? $post['excerpt']['rendered'] ?? '');
      if ($title_raw === '' || $content_raw === '') {
        continue;
      }
      $samples[] = [
        'title' => \html_entity_decode(\strip_tags($title_raw), \ENT_QUOTES | \ENT_HTML5, 'UTF-8'),
        'body' => $this->trimText($content_raw),
        'url' => (string) ($post['link'] ?? ''),
      ];
    }

    $this->cache->set($cache_key, $samples, \time() + self::WP_CACHE_TTL);
    return $samples;
  }

  /**
   * Extract plain text from a node's body or field_description field.
   */
  private function extractPlainText(NodeInterface $node): string {
    $source = '';
    foreach (['body', 'field_description', 'field_teaser'] as $field_name) {
      if ($node->hasField($field_name) && !$node->get($field_name)->isEmpty()) {
        $source = (string) ($node->get($field_name)->value ?? '');
        if ($source !== '') {
          break;
        }
      }
    }
    return $this->trimText($source);
  }

  /**
   * Strip HTML, collapse whitespace, truncate to a sample-friendly length.
   */
  private function trimText(string $html): string {
    $text = \html_entity_decode(\strip_tags($html), \ENT_QUOTES | \ENT_HTML5, 'UTF-8');
    $text = (string) \preg_replace('/\s+/u', ' ', $text);
    $text = \trim($text);
    return \mb_substr($text, 0, self::MAX_SAMPLE_CHARS);
  }

}
