<?php

declare(strict_types=1);

namespace Drupal\ai_content_drafter\Service;

use Drupal\ai\AiProviderPluginManager;
use Drupal\ai\OperationType\Chat\ChatInput;
use Drupal\ai\OperationType\Chat\ChatMessage;
use Drupal\Component\Serialization\Json;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\node\NodeInterface;

/**
 * Runs the full "Source Items → draft article" pipeline for one cluster.
 *
 * Composition of small single-purpose services:
 *
 *   SourceItemGrouper   → cluster unprocessed inbound items
 *   StyleSampleCollector → fetch internal + external voice samples
 *   Anthropic chat       → body draft (grounded in source + style)
 *   Anthropic chat       → SEO fields (title, description, keywords)
 *   SemanticLinker       → kNN on body, literal-anchor inline links
 *   DraftWriter          → persist as unpublished, moderation_state=draft
 *
 * Each call is bounded, rate-limited, and fails soft: if any stage returns
 * nothing useful, the pipeline logs it and moves on to the next cluster
 * rather than crashing the whole cron run.
 */
final class DraftPipeline {

  /**
   * Chat model id used for drafting + SEO enrichment.
   *
   * Pulled from drupal/ai default_providers.chat; hardcoded here as a
   * fallback in case someone misconfigures the provider defaults.
   */
  private const DEFAULT_CHAT_MODEL = 'claude-sonnet-4-5-20250929';

  public function __construct(
    private readonly AiProviderPluginManager $aiProviderManager,
    private readonly SourceItemGrouper $grouper,
    private readonly StyleSampleCollector $samples,
    private readonly SemanticLinker $linker,
    private readonly DraftWriter $writer,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly LoggerChannelInterface $logger,
  ) {}

  /**
   * Entry point: drain up to $max clusters from the backlog.
   *
   * @return int
   *   Number of drafts successfully saved.
   */
  public function run(int $max): int {
    $settings = $this->configFactory->get('ai_content_drafter.settings');
    if (!(bool) $settings->get('enabled')) {
      return 0;
    }

    $window = (int) ($settings->get('group_time_window_seconds') ?: 259200);
    $min_size = (int) ($settings->get('min_group_size') ?: 1);
    $max_size = (int) ($settings->get('max_group_size') ?: 6);

    $clusters = $this->grouper->group($window, $min_size, $max_size);
    if ($clusters === []) {
      $this->logger->info('No Source Item clusters to draft');
      return 0;
    }

    $written = 0;
    foreach ($clusters as $cluster) {
      if ($written >= $max) {
        break;
      }
      try {
        if ($this->draftCluster($cluster, $settings) !== NULL) {
          $this->grouper->markProcessed($cluster);
          $written++;
        }
      }
      catch (\Throwable $e) {
        $this->logger->error('Draft pipeline error on cluster: @msg', ['@msg' => $e->getMessage()]);
      }
    }

    return $written;
  }

  /**
   * Turn a single cluster of Source Items into a draft node.
   *
   * @param list<\Drupal\node\NodeInterface> $cluster
   *   The cluster of unprocessed source items.
   * @param \Drupal\Core\Config\ImmutableConfig $settings
   *   The ai_content_drafter.settings config object.
   */
  private function draftCluster(array $cluster, ImmutableConfig $settings): ?NodeInterface {
    $voice_prompt = (string) ($settings->get('author_voice_prompt') ?: '');
    $internal_count = (int) ($settings->get('style_sample_internal_count') ?: 3);
    $external_count = (int) ($settings->get('style_sample_fetch_count') ?: 3);
    $link_count = (int) ($settings->get('internal_link_count') ?: 3);

    // 1. Style context.
    $internal = $this->samples->internalSamples($cluster, $internal_count);
    $external = $this->samples->externalSamples($external_count);

    // 2. Body draft via chat model.
    $body_chat = $this->buildBodyPrompt($cluster, $internal, $external, $voice_prompt);
    $body_raw = $this->invokeChat($body_chat);
    if ($body_raw === NULL || \trim($body_raw) === '') {
      $this->logger->warning('Body draft returned empty');
      return NULL;
    }
    [$title, $body] = $this->parseDraftedBody($body_raw);

    // 3. SEO enrichment via a second chat call.
    $seo_chat = $this->buildSeoPrompt($title, $body);
    $seo_raw = $this->invokeChat($seo_chat);
    $seo = $this->parseSeo($seo_raw);

    // 4. Semantic inline links.
    $exclude = \array_map(static fn (NodeInterface $n): int => (int) $n->id(), $cluster);
    $linked = $this->linker->inject($body, $link_count, $exclude);
    $body = $linked['body'];

    // 5. Persist as unpublished draft with SEO metatags populated.
    $draft = [
      'title' => $title,
      'body' => $body,
      'summary' => $seo['description'] ?? '',
      'seo_title' => $seo['title'] ?? '',
      'seo_description' => $seo['description'] ?? '',
      'keywords' => $seo['keywords'] ?? [],
    ];
    $node = $this->writer->write($draft);

    $this->logger->info('Drafted cluster (@cluster_size items) into node @nid; @links inline links', [
      '@cluster_size' => \count($cluster),
      '@nid' => $node->id(),
      '@links' => \count($linked['links']),
    ]);

    return $node;
  }

  /**
   * Build the body-draft chat input.
   */
  private function buildBodyPrompt(array $cluster, array $internal_samples, array $external_samples, string $voice_prompt): ChatInput {
    $system = $voice_prompt . "\n\nOutput format: first line is the H1 title prefixed with 'TITLE: '. Remaining lines are the full draft body, using basic HTML (h2, p, ul, li, strong, em, a). No code blocks, no markdown. Always cite facts from the source material with inline links. Do NOT fabricate details.";

    $user_lines = [];
    $user_lines[] = "SOURCE MATERIAL (facts to ground the draft in):";
    foreach ($cluster as $i => $node) {
      \assert($node instanceof NodeInterface);
      $url = $node->hasField('field_source_url') && !$node->get('field_source_url')->isEmpty()
        ? (string) $node->get('field_source_url')->uri
        : '';
      $user_lines[] = sprintf("- [%d] %s", $i + 1, $node->getTitle());
      if ($url !== '') {
        $user_lines[] = "    url: $url";
      }
      if ($node->hasField('body') && !$node->get('body')->isEmpty()) {
        $user_lines[] = "    body: " . \mb_substr((string) $node->get('body')->value, 0, 800);
      }
    }

    if ($internal_samples !== [] || $external_samples !== []) {
      $user_lines[] = "";
      $user_lines[] = "STYLE EXAMPLES (match this voice, do not copy):";
      foreach ($internal_samples as $s) {
        $user_lines[] = "## " . $s['title'];
        $user_lines[] = \mb_substr($s['body'], 0, 800);
        $user_lines[] = "";
      }
      foreach ($external_samples as $s) {
        $user_lines[] = "## " . $s['title'];
        $user_lines[] = \mb_substr($s['body'], 0, 800);
        $user_lines[] = "";
      }
    }

    $user_lines[] = "Draft one article combining the source material, in the author's voice.";

    return new ChatInput([
      new ChatMessage('system', $system),
      new ChatMessage('user', \implode("\n", $user_lines)),
    ]);
  }

  /**
   * Build the SEO enrichment chat input.
   */
  private function buildSeoPrompt(string $title, string $body): ChatInput {
    $system = "You are an SEO assistant. Return ONLY a single JSON object with these keys and no surrounding prose: {\"title\": string (<=60 chars), \"description\": string (<=155 chars), \"keywords\": array of 4-6 short keyword strings}.";
    $user = "TITLE: $title\n\nBODY:\n" . \mb_substr(\strip_tags($body), 0, 2000);
    return new ChatInput([
      new ChatMessage('system', $system),
      new ChatMessage('user', $user),
    ]);
  }

  /**
   * Call the drafter's chat provider and return the raw text response.
   *
   * Reads drafter_chat_provider / drafter_chat_model from the module's
   * own settings first so the drafter can use a high-quality hosted
   * model (e.g. Claude Sonnet) even when the global ai.settings default
   * is a lightweight local Ollama model used for interactive field
   * assistance. If the drafter-specific settings are empty, falls back
   * to ai.settings.default_providers.chat.
   */
  private function invokeChat(ChatInput $input): ?string {
    $drafter_cfg = $this->configFactory->get('ai_content_drafter.settings');
    $provider_id = (string) ($drafter_cfg->get('drafter_chat_provider') ?? '');
    $model_id = (string) ($drafter_cfg->get('drafter_chat_model') ?? '');

    if ($provider_id === '' || $model_id === '') {
      $default = $this->configFactory->get('ai.settings')->get('default_providers.chat') ?? [];
      $provider_id = $provider_id ?: (string) ($default['provider_id'] ?? 'anthropic');
      $model_id = $model_id ?: (string) ($default['model_id'] ?? self::DEFAULT_CHAT_MODEL);
    }

    try {
      $provider = $this->aiProviderManager->createInstance($provider_id);
      $result = $provider->chat($input, $model_id, ['ai_content_drafter']);
      $message = $result->getNormalized();
      return $message->getText();
    }
    catch (\Throwable $e) {
      $this->logger->error('Chat invocation failed: @msg', ['@msg' => $e->getMessage()]);
      return NULL;
    }
  }

  /**
   * Extract title + body from the drafter's response.
   */
  private function parseDraftedBody(string $raw): array {
    $lines = \preg_split("/\r\n|\r|\n/", \trim($raw)) ?: [];
    $title = 'AI draft';
    $body_lines = [];
    foreach ($lines as $i => $line) {
      if ($i === 0 && \preg_match('/^TITLE:\s*(.+)$/i', $line, $m)) {
        $title = \trim($m[1]);
        continue;
      }
      $body_lines[] = $line;
    }
    return [$title, \trim(\implode("\n", $body_lines))];
  }

  /**
   * Parse the SEO chat response JSON. Returns empty fields on failure.
   */
  private function parseSeo(?string $raw): array {
    if ($raw === NULL) {
      return ['title' => '', 'description' => '', 'keywords' => []];
    }
    // Strip any surrounding code fences the model may add despite the prompt.
    $raw = (string) \preg_replace('/^```(?:json)?\s*|\s*```$/m', '', \trim($raw));
    $data = Json::decode($raw);
    if (!\is_array($data)) {
      return ['title' => '', 'description' => '', 'keywords' => []];
    }
    return [
      'title' => (string) ($data['title'] ?? ''),
      'description' => (string) ($data['description'] ?? ''),
      'keywords' => \array_values(\array_filter((array) ($data['keywords'] ?? []), 'is_string')),
    ];
  }

}
