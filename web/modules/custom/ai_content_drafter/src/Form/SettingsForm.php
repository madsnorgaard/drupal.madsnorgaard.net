<?php

declare(strict_types=1);

namespace Drupal\ai_content_drafter\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Admin settings for the AI drafter.
 */
final class SettingsForm extends ConfigFormBase {

  private const CONFIG_NAME = 'ai_content_drafter.settings';

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('config.factory'),
      $container->get('config.typed'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'ai_content_drafter_settings_form';
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

    $form['enabled'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable drafter cron'),
      '#description' => $this->t('When off, Source Items continue to accumulate but no drafts are created.'),
      '#default_value' => (bool) $config->get('enabled'),
    ];

    $form['search'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Semantic search'),
    ];
    $form['search']['draft_search_index'] = [
      '#type' => 'textfield',
      '#title' => $this->t('search_api index for style + related lookups'),
      '#default_value' => $config->get('draft_search_index') ?: 'content',
      '#required' => TRUE,
    ];
    $form['search']['internal_link_count'] = [
      '#type' => 'number',
      '#title' => $this->t('Target internal links per draft'),
      '#default_value' => (int) ($config->get('internal_link_count') ?: 3),
      '#min' => 0,
      '#max' => 10,
    ];
    $form['search']['style_sample_internal_count'] = [
      '#type' => 'number',
      '#title' => $this->t('Internal style samples per draft'),
      '#default_value' => (int) ($config->get('style_sample_internal_count') ?: 3),
      '#min' => 0,
      '#max' => 10,
    ];

    $form['external'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('External style samples'),
    ];
    $form['external']['style_sample_url'] = [
      '#type' => 'url',
      '#title' => $this->t('External WP REST endpoint'),
      '#description' => $this->t('Must be https and end in /wp-json/wp/v2/<path>. Public endpoints only — no auth supported yet.'),
      '#default_value' => $config->get('style_sample_url') ?: '',
    ];
    $form['external']['style_sample_fetch_count'] = [
      '#type' => 'number',
      '#title' => $this->t('External samples per draft'),
      '#default_value' => (int) ($config->get('style_sample_fetch_count') ?: 3),
      '#min' => 0,
      '#max' => 10,
    ];

    $form['voice'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Author voice'),
    ];
    $form['voice']['author_voice_prompt'] = [
      '#type' => 'textarea',
      '#title' => $this->t('System prompt fragment describing the author voice'),
      '#default_value' => $config->get('author_voice_prompt') ?: '',
      '#rows' => 6,
      '#description' => $this->t('Appended to every draft system message. Describe tone, structure, banned phrasing.'),
    ];

    $form['grouping'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Clustering'),
    ];
    $form['grouping']['group_time_window_seconds'] = [
      '#type' => 'number',
      '#title' => $this->t('Time window (seconds) within which Source Items cluster together'),
      '#default_value' => (int) ($config->get('group_time_window_seconds') ?: 259200),
      '#min' => 3600,
      '#max' => 2592000,
    ];
    $form['grouping']['min_group_size'] = [
      '#type' => 'number',
      '#title' => $this->t('Minimum Source Items per cluster'),
      '#default_value' => (int) ($config->get('min_group_size') ?: 1),
      '#min' => 1,
      '#max' => 20,
    ];
    $form['grouping']['max_group_size'] = [
      '#type' => 'number',
      '#title' => $this->t('Maximum Source Items per cluster'),
      '#default_value' => (int) ($config->get('max_group_size') ?: 6),
      '#min' => 1,
      '#max' => 20,
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $this->config(self::CONFIG_NAME)
      ->set('enabled', (bool) $form_state->getValue('enabled'))
      ->set('draft_search_index', (string) $form_state->getValue('draft_search_index'))
      ->set('internal_link_count', (int) $form_state->getValue('internal_link_count'))
      ->set('style_sample_internal_count', (int) $form_state->getValue('style_sample_internal_count'))
      ->set('style_sample_url', (string) $form_state->getValue('style_sample_url'))
      ->set('style_sample_fetch_count', (int) $form_state->getValue('style_sample_fetch_count'))
      ->set('author_voice_prompt', (string) $form_state->getValue('author_voice_prompt'))
      ->set('group_time_window_seconds', (int) $form_state->getValue('group_time_window_seconds'))
      ->set('min_group_size', (int) $form_state->getValue('min_group_size'))
      ->set('max_group_size', (int) $form_state->getValue('max_group_size'))
      ->save();

    parent::submitForm($form, $form_state);
  }

}
