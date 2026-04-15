<?php

declare(strict_types=1);

namespace Drupal\ai_content_drafter\Service;

use Drupal\ai\AiProviderPluginManager;
use Drupal\ai\OperationType\Embeddings\EmbeddingsInput;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\node\NodeInterface;

/**
 * Finds semantically-related existing content and injects inline links.
 *
 * Workflow:
 *
 * 1. Use the drafted body as a query: embed it via drupal/ai, then run a
 *    kNN search against the configured search_api index to find the top-N
 *    most similar existing entities (articles, projects, work experiences).
 * 2. For each candidate, look for a phrase in the body that could plausibly
 *    host the link — the candidate's title, or a short noun phrase from
 *    it. First literal-substring match wins, case-insensitive.
 * 3. Wrap that phrase in an anchor pointing at the candidate's URL.
 * 4. At most one link per candidate, at most N links per draft, never
 *    linking an article to itself.
 *
 * The LLM is intentionally NOT consulted to decide the anchor phrase — it
 * has a tendency to invent phrases that don't appear in the body verbatim.
 * The literal-match approach is conservative and explainable, which suits
 * a draft-then-review workflow.
 */
final class SemanticLinker {

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly AiProviderPluginManager $aiProviderManager,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly LoggerChannelInterface $logger,
  ) {}

  /**
   * Inject semantic links into a body string.
   *
   * @param string $body
   *   The LLM-drafted body text. May contain HTML from the Anthropic output.
   * @param int $max_links
   *   Upper bound on number of links to add.
   * @param list<int> $exclude_nids
   *   Node IDs already referenced by the cluster (don't link to these).
   *
   * @return array{body: string, links: list<array{nid: int, url: string, anchor: string}>}
   *   The modified body plus a list of what was linked.
   */
  public function inject(string $body, int $max_links, array $exclude_nids = []): array {
    if ($max_links <= 0 || \trim($body) === '') {
      return ['body' => $body, 'links' => []];
    }

    // Embed the body text for kNN lookup.
    $plain = (string) \preg_replace('/\s+/u', ' ', \strip_tags($body));
    $plain = \mb_substr($plain, 0, 1600);
    try {
      /** @var \Drupal\ai\Plugin\ProviderProxy $provider */
      $provider = $this->aiProviderManager->createInstance('ollama');
      $output = $provider->embeddings(new EmbeddingsInput($plain), 'nomic_embed_text_latest', ['semantic_linker']);
      $vector = $output->getNormalized();
    }
    catch (\Throwable $e) {
      $this->logger->error('Semantic linker embedding failed: @msg', ['@msg' => $e->getMessage()]);
      return ['body' => $body, 'links' => []];
    }

    if ($vector === []) {
      return ['body' => $body, 'links' => []];
    }

    // Query search_api for nearest neighbours.
    $index_id = (string) ($this->configFactory->get('ai_content_drafter.settings')->get('draft_search_index') ?? 'content');
    $index = $this->entityTypeManager->getStorage('search_api_index')->load($index_id);
    if (!$index) {
      return ['body' => $body, 'links' => []];
    }

    try {
      $query = $index->query([
        'limit' => $max_links * 3,
        'parse_mode' => 'direct',
      ]);
      $query->keys($plain);
      $query->setOption('search_api_bypass_access', TRUE);
      $result = $query->execute();
    }
    catch (\Throwable $e) {
      $this->logger->error('Semantic linker search failed: @msg', ['@msg' => $e->getMessage()]);
      return ['body' => $body, 'links' => []];
    }

    $links = [];
    $used_nids = \array_flip($exclude_nids);
    foreach ($result->getResultItems() as $item) {
      if (\count($links) >= $max_links) {
        break;
      }
      try {
        $entity = $item->getOriginalObject()?->getValue();
      }
      catch (\Throwable) {
        continue;
      }
      if (!$entity instanceof NodeInterface) {
        continue;
      }
      $nid = (int) $entity->id();
      if (isset($used_nids[$nid]) || $entity->bundle() === 'source_item') {
        continue;
      }

      $title = $entity->getTitle();
      // Find an anchor phrase literally present in the body (case-insensitive).
      // First try the title, then the first 4-word phrase of the title.
      $anchor = $this->findAnchor($body, $title);
      if ($anchor === NULL) {
        continue;
      }

      $url = $entity->toUrl('canonical', ['absolute' => FALSE])->toString();
      $replacement = '<a href="' . \htmlspecialchars($url, \ENT_QUOTES, 'UTF-8') . '">' . \htmlspecialchars($anchor, \ENT_QUOTES, 'UTF-8') . '</a>';

      // Replace only the first occurrence to avoid flooding.
      $pattern = '/\b' . \preg_quote($anchor, '/') . '\b/u';
      $count = 0;
      $body = (string) \preg_replace($pattern, $replacement, $body, 1, $count);
      if ($count > 0) {
        $links[] = [
          'nid' => $nid,
          'url' => $url,
          'anchor' => $anchor,
        ];
        $used_nids[$nid] = TRUE;
      }
    }

    return ['body' => $body, 'links' => $links];
  }

  /**
   * Find an anchor phrase in the body that matches the candidate's title.
   *
   * Looks for the full title first, then truncated prefixes (>= 3 words).
   * Returns NULL if nothing matches.
   */
  private function findAnchor(string $body, string $title): ?string {
    $title = \trim(\html_entity_decode($title, \ENT_QUOTES | \ENT_HTML5, 'UTF-8'));
    if ($title === '') {
      return NULL;
    }

    $candidates = [$title];
    $words = \preg_split('/\s+/u', $title) ?: [];
    if (\count($words) > 4) {
      $candidates[] = \implode(' ', \array_slice($words, 0, 4));
    }
    if (\count($words) > 3) {
      $candidates[] = \implode(' ', \array_slice($words, 0, 3));
    }

    foreach ($candidates as $candidate) {
      if ($candidate === '' || \mb_strlen($candidate) < 3) {
        continue;
      }
      if (\stripos($body, $candidate) !== FALSE) {
        return $candidate;
      }
    }
    return NULL;
  }

}
