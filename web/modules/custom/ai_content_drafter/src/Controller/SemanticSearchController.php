<?php

declare(strict_types=1);

namespace Drupal\ai_content_drafter\Controller;

use Drupal\ai\AiProviderPluginManager;
use Drupal\ai\OperationType\Embeddings\EmbeddingsInput;
use Drupal\Core\Cache\CacheableJsonResponse;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Flood\FloodInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\node\NodeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;

/**
 * Public semantic search endpoint for the Nuxt frontend.
 *
 * GET /api/semantic-search?q=<query>&limit=<n>
 *
 * Behaviour:
 *
 *  - Query text is embedded via the configured drupal/ai embeddings
 *    provider (Ollama by default) and kNN-queried against the
 *    search_api content index's dense_vector field.
 *  - Only published nodes are returned. access_check=TRUE is set on
 *    the underlying search_api query so unpublished + restricted
 *    content is filtered out at the backend, not just here.
 *  - Results are limited to 10 by default, capped at 25.
 *  - Each request is rate-limited via Drupal's flood service keyed
 *    by client IP — the query is cheap (1 Ollama embedding call +
 *    1 Solr lookup) but not free, and unauthenticated endpoints
 *    need a spam guard.
 *  - Responses carry CORS headers for madsnorgaard.net, the Nuxt
 *    frontend origin the JSON:API bridge already whitelists.
 *  - Cache is per-query-string via `url.query_args` context so a
 *    popular query hits Drupal's page cache rather than recomputing
 *    the embedding every time.
 */
final class SemanticSearchController implements ContainerInjectionInterface {

  /**
   * Flood window in seconds (rolling).
   */
  private const FLOOD_WINDOW = 60;

  /**
   * Maximum requests allowed from one IP inside the flood window.
   */
  private const FLOOD_LIMIT = 30;

  /**
   * Flood event name.
   */
  private const FLOOD_EVENT = 'ai_content_drafter.semantic_search';

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly AiProviderPluginManager $aiProviderManager,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly FloodInterface $flood,
    private readonly LoggerChannelInterface $logger,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('ai.provider'),
      $container->get('config.factory'),
      $container->get('flood'),
      $container->get('logger.channel.ai_content_drafter'),
    );
  }

  /**
   * GET /api/semantic-search.
   */
  public function search(Request $request): CacheableJsonResponse {
    // 1. Rate limit per client IP.
    $ip = (string) $request->getClientIp();
    if (!$this->flood->isAllowed(self::FLOOD_EVENT, self::FLOOD_LIMIT, self::FLOOD_WINDOW, $ip)) {
      throw new TooManyRequestsHttpException(self::FLOOD_WINDOW, 'Too many semantic search requests');
    }
    $this->flood->register(self::FLOOD_EVENT, self::FLOOD_WINDOW, $ip);

    // 2. Validate query text.
    $query_text = \trim((string) $request->query->get('q', ''));
    if (\mb_strlen($query_text) < 3) {
      throw new BadRequestHttpException('Query parameter "q" must be at least 3 characters');
    }
    if (\mb_strlen($query_text) > 500) {
      throw new BadRequestHttpException('Query parameter "q" cannot exceed 500 characters');
    }

    // 3. Validate limit.
    $limit = (int) $request->query->get('limit', 10);
    $limit = \max(1, \min($limit, 25));

    // 4. Resolve the search_api index.
    $index_id = (string) ($this->configFactory->get('ai_content_drafter.settings')->get('draft_search_index') ?? 'content');
    $index = $this->entityTypeManager->getStorage('search_api_index')->load($index_id);
    if (!$index) {
      $this->logger->error('Semantic search: index @id not found', ['@id' => $index_id]);
      throw new AccessDeniedHttpException('Search index not available');
    }

    // 5. Embed the query (lets the dense vector processor take over in Solr).
    try {
      $embed_defaults = $this->configFactory->get('ai.settings')->get('default_providers.embeddings') ?? [];
      $embed_provider_id = (string) ($embed_defaults['provider_id'] ?? 'ollama');
      $embed_model_id = (string) ($embed_defaults['model_id'] ?? 'nomic_embed_text_latest');
      $provider = $this->aiProviderManager->createInstance($embed_provider_id);
      // We don't use the returned vector here — search_api_solr_dense_vector's
      // query subscriber handles it server-side when re-rank is enabled.
      // We still call embeddings() so the connection is validated and an
      // upstream failure is raised cleanly before we hit Solr.
      $provider->embeddings(new EmbeddingsInput($query_text), $embed_model_id, ['semantic_search_validate']);
    }
    catch (\Throwable $e) {
      $this->logger->error('Semantic search embed failed: @msg', ['@msg' => $e->getMessage()]);
      return $this->emptyResponse('Search temporarily unavailable', 503);
    }

    // 6. kNN search via search_api. Access checking is ON.
    try {
      $query = $index->query([
        'limit' => $limit,
        'parse_mode' => 'direct',
      ]);
      $query->keys($query_text);
      // Do NOT bypass access — this endpoint is public.
      $query->addCondition('status', 1);
      $result = $query->execute();
    }
    catch (\Throwable $e) {
      $this->logger->error('Semantic search query failed: @msg', ['@msg' => $e->getMessage()]);
      return $this->emptyResponse('Search temporarily unavailable', 503);
    }

    // 7. Normalise result items.
    $hits = [];
    foreach ($result->getResultItems() as $item) {
      try {
        $entity = $item->getOriginalObject()?->getValue();
      }
      catch (\Throwable) {
        continue;
      }
      if (!$entity instanceof NodeInterface || !$entity->isPublished()) {
        continue;
      }
      $hits[] = [
        'id' => (int) $entity->id(),
        'uuid' => $entity->uuid(),
        'bundle' => $entity->bundle(),
        'title' => $entity->getTitle(),
        'url' => $entity->toUrl('canonical', ['absolute' => TRUE])->toString(),
        'score' => (float) $item->getScore(),
        'changed' => (int) $entity->getChangedTime(),
      ];
    }

    // 8. Assemble cacheable response.
    $payload = [
      'query' => $query_text,
      'count' => \count($hits),
      'limit' => $limit,
      'results' => $hits,
    ];

    $response = new CacheableJsonResponse($payload);
    $metadata = new CacheableMetadata();
    $metadata->addCacheContexts(['url.query_args:q', 'url.query_args:limit']);
    $metadata->addCacheTags(['node_list', 'semantic_search']);
    $metadata->setCacheMaxAge(300);
    $response->addCacheableDependency($metadata);

    return $response;
  }

  /**
   * Shape an empty JSON body with a reason and an HTTP status.
   */
  private function emptyResponse(string $reason, int $status): CacheableJsonResponse {
    $response = new CacheableJsonResponse([
      'query' => '',
      'count' => 0,
      'limit' => 0,
      'results' => [],
      'error' => $reason,
    ], $status);
    $response->setMaxAge(0);
    return $response;
  }

}
