<?php

declare(strict_types=1);

namespace Drupal\ai_content_drafter\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Mail\MailManagerInterface;
use Drupal\Core\Url;
use Drupal\node\NodeInterface;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;

/**
 * Posts a "new AI draft ready for review" notification.
 *
 * Two sinks, evaluated in this order:
 *
 *  1. Rocket.Chat webhook (if rocketchat_webhook is set). Sends a rich
 *     attachment with title, edit URL, author model, and word count so a
 *     reviewer can decide whether to open it without clicking through.
 *  2. Plain email (if notification_email is set and Rocket.Chat isn't).
 *     Falls back to Drupal's MailManager which respects the site mail
 *     handler (docker-mailserver on VPS2 per project memory).
 *
 * Both are best-effort: failures are logged but never re-thrown, so a
 * transient webhook outage cannot crash the drafting pipeline.
 */
final class DraftNotifier {

  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
    private readonly ClientInterface $httpClient,
    private readonly MailManagerInterface $mailManager,
    private readonly LoggerChannelInterface $logger,
  ) {}

  /**
   * Dispatch a notification for a freshly-created AI draft.
   */
  public function notify(NodeInterface $draft): void {
    $settings = $this->configFactory->get('ai_content_drafter.settings');
    $webhook = (string) ($settings->get('rocketchat_webhook') ?? '');
    $email = (string) ($settings->get('notification_email') ?? '');

    if ($webhook !== '') {
      $this->sendRocketChat($webhook, $draft);
      return;
    }
    if ($email !== '') {
      $this->sendEmail($email, $draft);
    }
  }

  /**
   * POST an incoming-webhook payload to Rocket.Chat.
   */
  private function sendRocketChat(string $webhook, NodeInterface $draft): void {
    if (!\preg_match('#^https://[A-Za-z0-9.\-]+/hooks/[A-Za-z0-9/_-]+$#', $webhook)) {
      $this->logger->warning('Rejected malformed Rocket.Chat webhook URL');
      return;
    }

    $edit_url = Url::fromRoute('entity.node.edit_form', ['node' => $draft->id()], ['absolute' => TRUE])->toString();
    $word_count = \str_word_count(\strip_tags((string) $draft->get('body')->value));

    $payload = [
      'alias' => 'AI Drafter',
      'emoji' => ':robot:',
      'text' => \sprintf('New AI draft ready for review: *%s*', $draft->getTitle()),
      'attachments' => [[
        'title' => $draft->getTitle(),
        'title_link' => $edit_url,
        'text' => \sprintf(
          "%d words  |  moderation: %s  |  nid: %d",
          $word_count,
          $draft->get('moderation_state')->value,
          $draft->id(),
        ),
        'color' => '#4caf50',
      ],
      ],
    ];

    try {
      $this->httpClient->request('POST', $webhook, [
        'json' => $payload,
        'timeout' => 5,
        'connect_timeout' => 3,
        'headers' => [
          'Content-Type' => 'application/json',
          'User-Agent' => 'drupal-ai-content-drafter',
        ],
      ]);
      $this->logger->info('Rocket.Chat notification sent for draft @nid', ['@nid' => $draft->id()]);
    }
    catch (GuzzleException $e) {
      $this->logger->error('Rocket.Chat notification failed: @msg', ['@msg' => $e->getMessage()]);
    }
  }

  /**
   * Send a plain-text email via Drupal's mail system.
   */
  private function sendEmail(string $to, NodeInterface $draft): void {
    $edit_url = Url::fromRoute('entity.node.edit_form', ['node' => $draft->id()], ['absolute' => TRUE])->toString();
    $params = [
      'subject' => \sprintf('[AI draft] %s', $draft->getTitle()),
      'body' => [
        \sprintf('A new AI draft is ready for review.'),
        '',
        \sprintf('Title: %s', $draft->getTitle()),
        \sprintf('Edit: %s', $edit_url),
        \sprintf('State: %s', $draft->get('moderation_state')->value),
        \sprintf('Words: %d', \str_word_count(\strip_tags((string) $draft->get('body')->value))),
      ],
    ];
    try {
      $this->mailManager->mail('ai_content_drafter', 'draft_ready', $to, 'en', $params);
      $this->logger->info('Email notification sent for draft @nid to @to', [
        '@nid' => $draft->id(),
        '@to' => $to,
      ]);
    }
    catch (\Throwable $e) {
      $this->logger->error('Email notification failed: @msg', ['@msg' => $e->getMessage()]);
    }
  }

}
