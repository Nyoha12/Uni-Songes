<?php

namespace Drupal\unisonges_structure\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Configure Google Calendar sync for course bookings.
 */
class GoogleCalendarSettingsForm extends ConfigFormBase {

  private const CONFIG_NAME = 'unisonges_structure.google_calendar';

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames(): array {
    return [self::CONFIG_NAME];
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'unisonges_structure_google_calendar_settings';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $config = $this->config(self::CONFIG_NAME);

    $form['activation_hold'] = [
      '#type' => 'item',
      '#title' => $this->t('Production activation hold'),
      '#markup' => $this->t('Google Calendar processing is unavailable in this state-foundation release. Cron, credentials, Calendar targets, and remote requests are not activated.'),
    ];

    $form['enabled'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable outgoing Google Calendar sync'),
      '#default_value' => FALSE,
      '#description' => $this->t('This value is forced off for this release.'),
      '#disabled' => TRUE,
    ];

    $form['dry_run'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Dry-run mode'),
      '#default_value' => TRUE,
      '#description' => $this->t('Preview processing is also unavailable and cannot consume backlog.'),
      '#disabled' => TRUE,
    ];

    $form['timezone'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Timezone'),
      '#default_value' => (string) ($config->get('timezone') ?: 'Europe/Paris'),
      '#description' => $this->t('IANA timezone used for booking payloads, for example Europe/Paris.'),
      '#maxlength' => 64,
      '#required' => TRUE,
    ];

    $form['batch_size'] = [
      '#type' => 'number',
      '#title' => $this->t('Batch size'),
      '#default_value' => (int) ($config->get('batch_size') ?: 10),
      '#description' => $this->t('Reserved limit for a later dedicated worker; no rows are processed in this release.'),
      '#min' => 1,
      '#max' => 100,
      '#required' => TRUE,
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    $timezone = trim((string) $form_state->getValue('timezone'));
    try {
      new \DateTimeZone($timezone);
    }
    catch (\Throwable $e) {
      $form_state->setErrorByName('timezone', $this->t('Enter a valid IANA timezone, for example Europe/Paris.'));
    }

    $batch_size = (int) $form_state->getValue('batch_size');
    if ($batch_size < 1 || $batch_size > 100) {
      $form_state->setErrorByName('batch_size', $this->t('Batch size must be between 1 and 100.'));
    }

    parent::validateForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $this->config(self::CONFIG_NAME)
      ->set('enabled', FALSE)
      ->set('dry_run', TRUE)
      ->set('timezone', trim((string) $form_state->getValue('timezone')))
      ->set('batch_size', (int) $form_state->getValue('batch_size'))
      ->set('token_provider', 'disabled')
      ->save();

    parent::submitForm($form, $form_state);
  }

}
