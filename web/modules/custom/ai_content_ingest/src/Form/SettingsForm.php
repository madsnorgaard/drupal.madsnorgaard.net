<?php

declare(strict_types=1);

namespace Drupal\ai_content_ingest\Form;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\TypedConfigManagerInterface;
use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Configure target content type, target body field, GitHub repos, rate limit.
 *
 * Intentionally schema-aware: the target bundle dropdown is populated from
 * the live node bundle list and the body field dropdown is populated from
 * whichever fields the selected bundle has that are text_with_summary or
 * text_long. That way the form survives new content types being added or
 * renamed without any code change.
 */
final class SettingsForm extends ConfigFormBase {

  private const CONFIG_NAME = 'ai_content_ingest.settings';

  public function __construct(
    ConfigFactoryInterface $config_factory,
    TypedConfigManagerInterface $typed_config_manager,
    private readonly EntityTypeBundleInfoInterface $bundleInfo,
    private readonly EntityFieldManagerInterface $fieldManager,
  ) {
    parent::__construct($config_factory, $typed_config_manager);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('config.factory'),
      $container->get('config.typed'),
      $container->get('entity_type.bundle.info'),
      $container->get('entity_field.manager'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'ai_content_ingest_settings_form';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames(): array {
    return [self::CONFIG_NAME];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $config = $this->config(self::CONFIG_NAME);

    $form['target'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Draft target'),
    ];

    $bundles = $this->bundleInfo->getBundleInfo('node');
    $bundle_options = [];
    foreach ($bundles as $machine => $info) {
      // Source Item and Topic are staging/taxonomy — don't let the drafter
      // write back into its own inbox.
      if ($machine === 'source_item') {
        continue;
      }
      $bundle_options[$machine] = $info['label'] ?? $machine;
    }
    ksort($bundle_options);

    $form['target']['target_bundle'] = [
      '#type' => 'select',
      '#title' => $this->t('Target content type'),
      '#description' => $this->t('The content type AI drafts are created as. Defaults to Article.'),
      '#options' => $bundle_options,
      '#default_value' => $config->get('target_bundle') ?: 'article',
      '#required' => TRUE,
    ];

    $form['target']['target_body_field'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Target body field machine name'),
      '#description' => $this->t('Machine name of the text field AI drafts write to. Usually <code>body</code>.'),
      '#default_value' => $config->get('target_body_field') ?: 'body',
      '#required' => TRUE,
      '#size' => 40,
    ];

    $form['target']['default_moderation_state'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Default moderation state for drafts'),
      '#description' => $this->t('If Content Moderation is enabled on the target bundle, drafts are saved in this state. Usually <code>draft</code>.'),
      '#default_value' => $config->get('default_moderation_state') ?: 'draft',
      '#size' => 40,
    ];

    $form['rate_limit'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Rate limiting'),
    ];
    $form['rate_limit']['max_drafts_per_run'] = [
      '#type' => 'number',
      '#title' => $this->t('Maximum drafts created per cron run'),
      '#default_value' => (int) ($config->get('max_drafts_per_run') ?: 5),
      '#min' => 1,
      '#max' => 100,
      '#required' => TRUE,
    ];

    $form['github'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('GitHub sources'),
    ];
    $form['github']['github_repos'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Repositories to watch'),
      '#description' => $this->t('One <code>owner/repo</code> slug per line. Only public repos — no token support yet.'),
      '#default_value' => implode("\n", (array) ($config->get('github_repos') ?? [])),
      '#rows' => 6,
    ];
    $form['github']['github_poll_interval_seconds'] = [
      '#type' => 'number',
      '#title' => $this->t('Minimum seconds between polls per repo'),
      '#default_value' => (int) ($config->get('github_poll_interval_seconds') ?: 3600),
      '#min' => 300,
      '#max' => 86400,
    ];
    $form['github']['github_event_types'] = [
      '#type' => 'checkboxes',
      '#title' => $this->t('Event types to ingest'),
      '#options' => [
        'push' => $this->t('Commits (PushEvent)'),
        'release' => $this->t('Releases (ReleaseEvent)'),
        'pull_request' => $this->t('Pull requests (PullRequestEvent)'),
        'issues' => $this->t('Issues (IssuesEvent)'),
      ],
      '#default_value' => $config->get('github_event_types') ?: ['push', 'release'],
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    parent::validateForm($form, $form_state);

    // Validate target bundle has the body field.
    $bundle = $form_state->getValue('target_bundle');
    $body = $form_state->getValue('target_body_field');
    $fields = $this->fieldManager->getFieldDefinitions('node', $bundle);
    if (!isset($fields[$body])) {
      $form_state->setErrorByName('target_body_field', $this->t('Content type %bundle has no field named %field.', [
        '%bundle' => $bundle,
        '%field' => $body,
      ]));
    }

    // Validate each GitHub repo slug.
    $repos_raw = trim((string) $form_state->getValue('github_repos'));
    $repos = [];
    foreach (preg_split('/\r\n|\r|\n/', $repos_raw) as $line) {
      $line = trim($line);
      if ($line === '') {
        continue;
      }
      if (!preg_match('#^[A-Za-z0-9][A-Za-z0-9._-]{0,38}/[A-Za-z0-9._-]{1,100}$#', $line)) {
        $form_state->setErrorByName('github_repos', $this->t('Invalid GitHub repo slug: %slug. Expected owner/repo.', [
          '%slug' => $line,
        ]));
        return;
      }
      $repos[] = $line;
    }
    $form_state->setValue('github_repos', $repos);

    // Normalise event types to a flat list.
    $event_types = array_values(array_filter((array) $form_state->getValue('github_event_types')));
    $form_state->setValue('github_event_types', $event_types);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $this->config(self::CONFIG_NAME)
      ->set('target_bundle', $form_state->getValue('target_bundle'))
      ->set('target_body_field', $form_state->getValue('target_body_field'))
      ->set('default_moderation_state', $form_state->getValue('default_moderation_state'))
      ->set('max_drafts_per_run', (int) $form_state->getValue('max_drafts_per_run'))
      ->set('github_repos', $form_state->getValue('github_repos'))
      ->set('github_poll_interval_seconds', (int) $form_state->getValue('github_poll_interval_seconds'))
      ->set('github_event_types', $form_state->getValue('github_event_types'))
      ->save();

    parent::submitForm($form, $form_state);
  }

}
