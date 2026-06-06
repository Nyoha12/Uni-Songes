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
    $env_var = (string) ($config->get('access_token_env_var') ?: 'UNISONGES_GCAL_ACCESS_TOKEN');

    $form['enabled'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable outgoing Google Calendar sync'),
      '#default_value' => (bool) $config->get('enabled'),
      '#description' => $this->t('When disabled, Drupal cron leaves pending booking sync rows untouched.'),
    ];

    $form['dry_run'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Dry-run mode'),
      '#default_value' => $config->get('dry_run') === NULL ? TRUE : (bool) $config->get('dry_run'),
      '#description' => $this->t('When enabled, cron logs what would be sent and performs no Google Calendar request.'),
    ];

    $form['calendar_id'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Google Calendar ID'),
      '#default_value' => (string) ($config->get('calendar_id') ?: ''),
      '#description' => $this->t('Store only a non-sensitive calendar identifier here. Do not paste credentials or tokens.'),
      '#maxlength' => 255,
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
      '#description' => $this->t('Maximum pending booking rows processed by each Drupal cron run.'),
      '#min' => 1,
      '#max' => 100,
      '#required' => TRUE,
    ];

    $form['token_provider'] = [
      '#type' => 'select',
      '#title' => $this->t('Token provider mode'),
      '#default_value' => (string) ($config->get('token_provider') ?: 'env_access_token'),
      '#options' => [
        'env_access_token' => $this->t('Environment access token'),
        'disabled' => $this->t('Disabled / stub'),
      ],
      '#description' => $this->t('This module does not store Google tokens in Drupal config or Git.'),
    ];

    $form['access_token_env_var'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Access token environment variable'),
      '#default_value' => $env_var,
      '#description' => $this->t('Name of the environment variable containing the short-lived Google OAuth access token. The token value itself is never saved.'),
      '#maxlength' => 128,
      '#required' => TRUE,
    ];

    $form['credential_status'] = [
      '#type' => 'item',
      '#title' => $this->t('Current token status'),
      '#markup' => $this->environmentHasValue($env_var) ? $this->t('A value is currently available in the environment.') : $this->t('No value is currently available in the environment.'),
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

    $env_var = trim((string) $form_state->getValue('access_token_env_var'));
    if (!preg_match('/^[A-Z][A-Z0-9_]*$/', $env_var)) {
      $form_state->setErrorByName('access_token_env_var', $this->t('Use an uppercase environment variable name, for example UNISONGES_GCAL_ACCESS_TOKEN.'));
    }

    $enabled = (bool) $form_state->getValue('enabled');
    $dry_run = (bool) $form_state->getValue('dry_run');
    $calendar_id = trim((string) $form_state->getValue('calendar_id'));
    $token_provider = (string) $form_state->getValue('token_provider');

    if ($enabled && !$dry_run && $calendar_id === '') {
      $form_state->setErrorByName('calendar_id', $this->t('Calendar ID is required when real sync is enabled.'));
    }
    if ($enabled && !$dry_run && $token_provider === 'disabled') {
      $form_state->setErrorByName('token_provider', $this->t('Choose an active token provider before disabling dry-run mode.'));
    }

    parent::validateForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $this->config(self::CONFIG_NAME)
      ->set('enabled', (bool) $form_state->getValue('enabled'))
      ->set('dry_run', (bool) $form_state->getValue('dry_run'))
      ->set('calendar_id', trim((string) $form_state->getValue('calendar_id')))
      ->set('timezone', trim((string) $form_state->getValue('timezone')))
      ->set('batch_size', (int) $form_state->getValue('batch_size'))
      ->set('token_provider', (string) $form_state->getValue('token_provider'))
      ->set('access_token_env_var', trim((string) $form_state->getValue('access_token_env_var')))
      ->save();

    parent::submitForm($form, $form_state);
  }

  /**
   * Checks whether an environment variable is populated without exposing it.
   */
  private function environmentHasValue(string $name): bool {
    if ($name === '') {
      return FALSE;
    }

    $value = getenv($name);
    if (is_string($value) && $value !== '') {
      return TRUE;
    }

    return !empty($_ENV[$name]) || !empty($_SERVER[$name]);
  }

}
