<?php

namespace Drupal\unisonges_structure\Form;

use Drupal\Component\Utility\Html;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\TempStore\PrivateTempStoreFactory;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Small reservation-first course tunnel.
 */
final class ReservationFirstCourseTunnelForm extends FormBase {

  private const TEMPSTORE_KEY = 'course_reservation_first_tunnel';

  private const COURSE_BUNDLES = [
    'cours_essai',
    'cours_deb_inter',
    'cours_avance',
  ];

  /**
   * The current user.
   *
   * @var \Drupal\Core\Session\AccountProxyInterface
   */
  private $currentAccount;

  /**
   * Private tempstore factory.
   *
   * @var \Drupal\Core\TempStore\PrivateTempStoreFactory
   */
  private $tempStoreFactory;

  /**
   * Entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  private $entityTypeManager;

  /**
   * Module handler.
   *
   * @var \Drupal\Core\Extension\ModuleHandlerInterface
   */
  private $moduleHandler;

  public function __construct(AccountProxyInterface $current_account, PrivateTempStoreFactory $temp_store_factory, EntityTypeManagerInterface $entity_type_manager, ModuleHandlerInterface $module_handler) {
    $this->currentAccount = $current_account;
    $this->tempStoreFactory = $temp_store_factory;
    $this->entityTypeManager = $entity_type_manager;
    $this->moduleHandler = $module_handler;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('current_user'),
      $container->get('tempstore.private'),
      $container->get('entity_type.manager'),
      $container->get('module_handler')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'unisonges_reservation_first_course_tunnel';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $stored = $this->getStoredSelection();
    $step = $this->resolveStep($form_state, $stored);

    $form['#attributes']['class'][] = 'reservation-first-course';
    $form['intro'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['reservation-first-course__intro']],
      'title' => [
        '#markup' => '<h2>' . $this->t('Réserver un cours') . '</h2>',
      ],
      'copy' => [
        '#markup' => '<p>' . $this->t('Choisissez le cours et le créneau avant le paiement. La confirmation finale reste séparée du choix de créneau.') . '</p>',
      ],
    ];

    $form['progress'] = $this->buildProgress($step);

    if ($this->currentAccount->isAnonymous()) {
      $form['anonymous'] = $this->buildAnonymousNotice();
      return $form;
    }

    if ($step === 'slot') {
      $this->buildSlotStep($form, $stored);
      return $form;
    }

    if ($step === 'payment') {
      $this->buildPaymentStep($form, $stored);
      return $form;
    }

    if ($step === 'pay_on_site_pending') {
      $this->buildPayOnSitePendingStep($form, $stored);
      return $form;
    }

    $this->buildCourseStep($form, $stored);
    return $form;
  }

  private function buildAnonymousNotice(): array {
    $destination = '/reservation-cours';
    return [
      '#type' => 'container',
      '#attributes' => ['class' => ['reservation-first-course__panel']],
      'message' => [
        '#markup' => '<h3>' . $this->t('Compte / identification') . '</h3><p>' . $this->t('Connectez-vous pour choisir un cours, un créneau, puis le paiement. La réservation ne peut pas être confirmée sans compte.') . '</p>',
      ],
      'actions' => [
        '#type' => 'container',
        '#attributes' => ['class' => ['reservation-portal__actions']],
        'login' => [
          '#type' => 'link',
          '#title' => $this->t('Se connecter'),
          '#url' => Url::fromRoute('user.login', [], ['query' => ['destination' => $destination]]),
          '#attributes' => ['class' => ['btn', 'btn--cta']],
        ],
        'register' => [
          '#type' => 'link',
          '#title' => $this->t('Créer un compte'),
          '#url' => Url::fromRoute('user.register', [], ['query' => ['destination' => $destination]]),
          '#attributes' => ['class' => ['btn']],
        ],
      ],
    ];
  }

  private function buildCourseStep(array &$form, array $stored): void {
    $form['step'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['reservation-first-course__panel']],
      'title' => [
        '#markup' => '<h3>' . $this->t('1. Choix du cours') . '</h3>',
      ],
      'course' => [
        '#type' => 'radios',
        '#title' => $this->t('Cours'),
        '#parents' => ['course'],
        '#options' => $this->getCourseOptions(),
        '#default_value' => $stored['course'] ?? '',
        '#required' => TRUE,
      ],
    ];

    $form['actions'] = [
      '#type' => 'actions',
      'next' => [
        '#type' => 'submit',
        '#value' => $this->t('Choisir le créneau'),
        '#button_type' => 'primary',
        '#validate' => ['::validateCourseStep'],
        '#submit' => ['::submitCourseStep'],
      ],
    ];
  }

  public function validateCourseStep(array &$form, FormStateInterface $form_state): void {
    $course = (string) $form_state->getValue('course');
    if (!isset($this->getCourseOptions()[$course])) {
      $form_state->setErrorByName('course', $this->t('Choisissez un cours.'));
    }
  }

  public function submitCourseStep(array &$form, FormStateInterface $form_state): void {
    $course = (string) $form_state->getValue('course');
    $stored = $this->getStoredSelection();
    $stored['course'] = $course;
    $stored['course_label'] = $this->getCourseLabel($course);
    $stored['step'] = 'slot';
    unset($stored['reservation_value'], $stored['slot_label'], $stored['payment_choice']);
    $this->setStoredSelection($stored);

    $form_state->set('step', 'slot');
    $form_state->setRebuild(TRUE);
  }

  private function buildSlotStep(array &$form, array $stored): void {
    if (empty($stored['course'])) {
      $form['empty'] = $this->buildResetNotice($this->t('Choisissez d’abord un cours.'));
      return;
    }

    $form['summary'] = $this->buildSummary($stored);
    $form['step'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['reservation-first-course__panel']],
      'title' => [
        '#markup' => '<h3>' . $this->t('2. Choix du créneau') . '</h3>',
      ],
      'reservation' => $this->buildReservationElement($stored['reservation_value'] ?? ''),
    ];

    $form['actions'] = [
      '#type' => 'actions',
      'previous' => [
        '#type' => 'submit',
        '#value' => $this->t('Modifier le cours'),
        '#submit' => ['::submitBackToCourse'],
        '#limit_validation_errors' => [],
      ],
      'next' => [
        '#type' => 'submit',
        '#value' => $this->t('Choisir le paiement'),
        '#button_type' => 'primary',
        '#validate' => ['::validateSlotStep'],
        '#submit' => ['::submitSlotStep'],
      ],
    ];
  }

  public function validateSlotStep(array &$form, FormStateInterface $form_state): void {
    $normalized = $this->normalizeReservationValue($form_state->getValue('reservation'));
    if ($normalized === '') {
      $form_state->setErrorByName('reservation', $this->t('Choisissez un créneau.'));
      return;
    }

    $booking = _unisonges_structure_parse_booking_form_value($normalized);
    if ($booking['error'] !== '') {
      $form_state->setErrorByName('reservation', $booking['error']);
      return;
    }
    if ($booking['slot'] === '') {
      $form_state->setErrorByName('reservation', $this->t('Choisissez un créneau.'));
      return;
    }

    $capacity = $this->getReservationCapacity();
    if ($booking['seats'] > $capacity['max_seats_per_booking']) {
      $form_state->setErrorByName('reservation', $this->t('Le nombre de places demandé dépasse le maximum autorisé pour une réservation.'));
      return;
    }

    $conflict_message = _unisonges_structure_get_booking_conflict_message($booking['slot'], $booking['seats'], (int) $this->currentAccount->id(), $capacity['seats_slot']);
    if ($conflict_message !== '') {
      $form_state->setErrorByName('reservation', $conflict_message);
      return;
    }

    $form_state->set('reservation_value', $normalized);
  }

  public function submitSlotStep(array &$form, FormStateInterface $form_state): void {
    $reservation_value = (string) $form_state->get('reservation_value');
    $stored = $this->getStoredSelection();
    $stored['reservation_value'] = $reservation_value;
    $stored['slot_label'] = $this->formatReservationValue($reservation_value);
    $stored['step'] = 'payment';
    unset($stored['payment_choice']);
    $this->setStoredSelection($stored);

    $form_state->set('step', 'payment');
    $form_state->setRebuild(TRUE);
  }

  private function buildPaymentStep(array &$form, array $stored): void {
    if (empty($stored['course']) || empty($stored['reservation_value'])) {
      $form['empty'] = $this->buildResetNotice($this->t('Choisissez un cours et un créneau avant le paiement.'));
      return;
    }

    $form['summary'] = $this->buildSummary($stored);
    $form['step'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['reservation-first-course__panel']],
      'title' => [
        '#markup' => '<h3>' . $this->t('3. Choix du paiement') . '</h3>',
      ],
      'notice' => [
        '#markup' => '<p>' . $this->t('Le créneau n’est pas encore confirmé. Choisissez le mode de paiement pour continuer.') . '</p>',
      ],
      'payment_choice' => [
        '#type' => 'radios',
        '#title' => $this->t('Paiement'),
        '#parents' => ['payment_choice'],
        '#options' => [
          'online' => $this->t('Payer en ligne'),
          'pay_on_site' => $this->t('Payer sur place'),
        ],
        '#required' => TRUE,
      ],
    ];

    $form['actions'] = [
      '#type' => 'actions',
      'previous' => [
        '#type' => 'submit',
        '#value' => $this->t('Modifier le créneau'),
        '#submit' => ['::submitBackToSlot'],
        '#limit_validation_errors' => [],
      ],
      'next' => [
        '#type' => 'submit',
        '#value' => $this->t('Continuer'),
        '#button_type' => 'primary',
        '#validate' => ['::validatePaymentStep'],
        '#submit' => ['::submitPaymentStep'],
      ],
    ];
  }

  public function validatePaymentStep(array &$form, FormStateInterface $form_state): void {
    $choice = (string) $form_state->getValue('payment_choice');
    if (!in_array($choice, ['online', 'pay_on_site'], TRUE)) {
      $form_state->setErrorByName('payment_choice', $this->t('Choisissez un mode de paiement.'));
    }
  }

  public function submitPaymentStep(array &$form, FormStateInterface $form_state): void {
    $choice = (string) $form_state->getValue('payment_choice');
    $stored = $this->getStoredSelection();
    $stored['payment_choice'] = $choice;
    $stored['step'] = $choice === 'pay_on_site' ? 'pay_on_site_pending' : 'payment';
    $this->setStoredSelection($stored);

    if ($choice === 'online') {
      $this->messenger()->addStatus($this->t('Cours et créneau sélectionnés. Continuez vers le paiement en ligne ; le créneau n’est pas encore confirmé automatiquement.'));
      $form_state->setRedirectUrl($this->getOnlinePaymentUrl($stored));
      return;
    }

    $form_state->set('step', 'pay_on_site_pending');
    $form_state->setRebuild(TRUE);
  }

  private function buildPayOnSitePendingStep(array &$form, array $stored): void {
    $form['summary'] = $this->buildSummary($stored);
    $form['step'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['reservation-first-course__panel', 'reservation-first-course__panel--warning']],
      'title' => [
        '#markup' => '<h3>' . $this->t('Paiement sur place sélectionné') . '</h3>',
      ],
      'message' => [
        '#markup' => '<p><strong>' . $this->t('Réservation non confirmée.') . '</strong> ' . $this->t('Le choix “Payer sur place” est mémorisé pour ce parcours, mais aucune commande manuelle ni aucun droit “COURS À PAYER” n’est encore créé. Le créneau ne sera confirmé qu’après raccord à la validation de paiement.') . '</p>',
      ],
    ];

    $form['actions'] = [
      '#type' => 'actions',
      'previous' => [
        '#type' => 'submit',
        '#value' => $this->t('Modifier le paiement'),
        '#submit' => ['::submitBackToPayment'],
        '#limit_validation_errors' => [],
      ],
      'restart' => [
        '#type' => 'submit',
        '#value' => $this->t('Recommencer'),
        '#submit' => ['::submitRestart'],
        '#limit_validation_errors' => [],
      ],
    ];
  }

  private function buildProgress(string $step): array {
    $steps = [
      'course' => $this->t('Compte / cours'),
      'slot' => $this->t('Créneau'),
      'payment' => $this->t('Paiement'),
      'pay_on_site_pending' => $this->t('À finaliser'),
    ];
    $current_index = array_search($step, array_keys($steps), TRUE);
    if ($current_index === FALSE) {
      $current_index = 0;
    }

    $items = [];
    foreach (array_values($steps) as $index => $label) {
      $class = $index < $current_index ? 'is-complete' : ($index === $current_index ? 'is-current' : 'is-upcoming');
      $items[] = '<li class="' . $class . '">' . Html::escape((string) $label) . '</li>';
    }

    return [
      '#markup' => '<ol class="reservation-first-course__steps">' . implode('', $items) . '</ol>',
    ];
  }

  private function buildSummary(array $stored): array {
    $rows = [];
    if (!empty($stored['course_label'])) {
      $rows[] = '<div><dt>' . $this->t('Cours') . '</dt><dd>' . Html::escape((string) $stored['course_label']) . '</dd></div>';
    }
    if (!empty($stored['slot_label'])) {
      $rows[] = '<div><dt>' . $this->t('Créneau') . '</dt><dd>' . Html::escape((string) $stored['slot_label']) . '</dd></div>';
    }

    return [
      '#markup' => '<dl class="reservation-first-course__summary">' . implode('', $rows) . '</dl>',
    ];
  }

  private function buildResetNotice($message): array {
    return [
      '#type' => 'container',
      '#attributes' => ['class' => ['reservation-first-course__panel']],
      'message' => [
        '#markup' => '<p>' . Html::escape((string) $message) . '</p>',
      ],
      'actions' => [
        '#type' => 'actions',
        'restart' => [
          '#type' => 'submit',
          '#value' => $this->t('Recommencer'),
          '#submit' => ['::submitRestart'],
          '#limit_validation_errors' => [],
        ],
      ],
    ];
  }

  private function buildReservationElement(string $default_value): array {
    $source = $this->getWebformReservationElement();
    if (!$this->moduleHandler->moduleExists('webform_booking')) {
      return [
        '#type' => 'textfield',
        '#title' => $this->t('Créneau'),
        '#parents' => ['reservation'],
        '#description' => $this->t('Format attendu : AAAA-MM-JJ HH:MM. Le sélecteur de créneaux sera utilisé quand le module webform_booking est disponible.'),
        '#placeholder' => '2026-07-01 14:00',
        '#default_value' => $default_value ? preg_replace('/\|\\d+$/', '', $default_value) : '',
        '#required' => TRUE,
      ];
    }

    $element = [
      '#type' => 'webform_booking',
      '#title' => $this->t('Choisir un créneau'),
      '#parents' => ['reservation'],
      '#description' => $this->t('Le créneau est choisi avant le paiement. Il sera confirmé seulement après raccord au paiement.'),
      '#required' => TRUE,
      '#days_visible' => '30',
      '#slot_duration' => 60,
      '#seats_slot' => '1',
      '#max_seats_per_booking' => 1,
      '#time_interval' => '9:00|16:30',
      '#no_slots' => $this->t('Aucune disponibilité ouverte sur cette période.'),
      '#date_label' => $this->t('Date'),
      '#slot_label' => $this->t('Créneau'),
      '#seats_label' => $this->t('Places'),
    ];

    foreach (['#days_visible', '#slot_duration', '#seats_slot', '#max_seats_per_booking', '#time_interval', '#date_label', '#slot_label', '#seats_label'] as $key) {
      if (isset($source[$key])) {
        $element[$key] = $source[$key];
      }
    }
    if ($default_value !== '') {
      $element['#default_value'] = $default_value;
    }

    return $element;
  }

  private function getReservationCapacity(): array {
    $source = $this->getWebformReservationElement();
    $seats_slot = max(1, (int) ($source['#seats_slot'] ?? 1));
    $max_seats_per_booking = max(1, (int) ($source['#max_seats_per_booking'] ?? 1));

    return [
      'seats_slot' => $seats_slot,
      'max_seats_per_booking' => min($max_seats_per_booking, $seats_slot),
    ];
  }

  private function getWebformReservationElement(): array {
    try {
      if (!$this->entityTypeManager->hasDefinition('webform')) {
        return [];
      }
      $webform = $this->entityTypeManager->getStorage('webform')->load('cours_particuliers_reservation');
      if ($webform && method_exists($webform, 'getElementDecoded')) {
        $element = $webform->getElementDecoded('reservation');
        return is_array($element) ? $element : [];
      }
    }
    catch (\Throwable $e) {
      return [];
    }

    return [];
  }

  private function getCourseOptions(): array {
    $options = [];
    try {
      if ($this->entityTypeManager->hasDefinition('commerce_product')) {
        $storage = $this->entityTypeManager->getStorage('commerce_product');
        $query = $storage->getQuery()
          ->accessCheck(TRUE)
          ->condition('type', self::COURSE_BUNDLES, 'IN')
          ->condition('status', 1)
          ->sort('title', 'ASC');
        $ids = $query->execute();
        if ($ids) {
          foreach ($storage->loadMultiple($ids) as $product) {
            if (method_exists($product, 'label') && method_exists($product, 'id')) {
              $options['product:' . $product->id()] = $product->label();
            }
          }
        }
      }
    }
    catch (\Throwable $e) {
      $options = [];
    }

    return $options ?: [
      'bundle:cours_essai' => $this->t('Cours d’essai'),
      'bundle:cours_deb_inter' => $this->t('Cours débutant / intermédiaire'),
      'bundle:cours_avance' => $this->t('Cours avancé'),
    ];
  }

  private function getCourseLabel(string $course): string {
    $options = $this->getCourseOptions();
    return isset($options[$course]) ? (string) $options[$course] : $course;
  }

  private function normalizeReservationValue($value): string {
    if (is_array($value)) {
      $slot = trim((string) ($value['slot'] ?? ''));
      if ($slot === '') {
        return '';
      }
      $seats = max(1, (int) ($value['seats'] ?? 1));
      return $slot . '|' . $seats;
    }

    $value = trim((string) $value);
    if ($value === '') {
      return '';
    }
    if (preg_match('/^\d{4}-\d{2}-\d{2}\s+\d{2}:\d{2}$/', $value) === 1) {
      return $value . '|1';
    }

    return $value;
  }

  private function formatReservationValue(string $value): string {
    $parsed = unisonges_structure_parse_booking_reservation_value($value);
    if ($parsed === NULL) {
      return $value;
    }

    return $parsed['start']->format('d/m/Y H:i');
  }

  private function getOnlinePaymentUrl(array $stored): Url {
    $course = (string) ($stored['course'] ?? '');
    if (strpos($course, 'product:') === 0) {
      $product_id = (int) substr($course, strlen('product:'));
      try {
        $product = $this->entityTypeManager->getStorage('commerce_product')->load($product_id);
        if ($product && method_exists($product, 'toUrl')) {
          return $product->toUrl('canonical', ['query' => ['reservation-first' => '1']]);
        }
      }
      catch (\Throwable $e) {
        // Fall back to the public course listing.
      }
    }

    return Url::fromUserInput('/cours', ['query' => ['reservation-first' => '1']]);
  }

  private function resolveStep(FormStateInterface $form_state, array $stored): string {
    $step = (string) ($form_state->get('step') ?: ($stored['step'] ?? 'course'));
    return in_array($step, ['course', 'slot', 'payment', 'pay_on_site_pending'], TRUE) ? $step : 'course';
  }

  private function getStoredSelection(): array {
    try {
      $stored = $this->tempStoreFactory->get('unisonges_structure')->get(self::TEMPSTORE_KEY);
      return is_array($stored) ? $stored : [];
    }
    catch (\Throwable $e) {
      return [];
    }
  }

  private function setStoredSelection(array $selection): void {
    try {
      $this->tempStoreFactory->get('unisonges_structure')->set(self::TEMPSTORE_KEY, $selection);
    }
    catch (\Throwable $e) {
      $this->messenger()->addWarning($this->t('La sélection n’a pas pu être mémorisée. Réessayez si la page se recharge.'));
    }
  }

  public function submitBackToCourse(array &$form, FormStateInterface $form_state): void {
    $stored = $this->getStoredSelection();
    $stored['step'] = 'course';
    $this->setStoredSelection($stored);
    $form_state->set('step', 'course');
    $form_state->setRebuild(TRUE);
  }

  public function submitBackToSlot(array &$form, FormStateInterface $form_state): void {
    $stored = $this->getStoredSelection();
    $stored['step'] = 'slot';
    $this->setStoredSelection($stored);
    $form_state->set('step', 'slot');
    $form_state->setRebuild(TRUE);
  }

  public function submitBackToPayment(array &$form, FormStateInterface $form_state): void {
    $stored = $this->getStoredSelection();
    $stored['step'] = 'payment';
    $this->setStoredSelection($stored);
    $form_state->set('step', 'payment');
    $form_state->setRebuild(TRUE);
  }

  public function submitRestart(array &$form, FormStateInterface $form_state): void {
    try {
      $this->tempStoreFactory->get('unisonges_structure')->delete(self::TEMPSTORE_KEY);
    }
    catch (\Throwable $e) {
      // Ignore tempstore cleanup failures; rebuilding the first step is enough.
    }
    $form_state->set('step', 'course');
    $form_state->setRebuild(TRUE);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    // Step-specific submit handlers are used.
  }

}
