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

  private const DETAIL_FIELDS = [
    'mode_cours',
    'plateforme_visio',
    'adresse_domicile',
    'code_postal_domicile',
    'telephone',
    'instrument',
    'didgeridoo_pret',
    'niveau_cours',
    'notes_supplementaires',
  ];

  private const ALWAYS_REQUIRED_DETAIL_FIELDS = [
    'mode_cours',
    'telephone',
    'instrument',
    'niveau_cours',
  ];

  private const OPTION_DETAIL_FIELDS = [
    'mode_cours',
    'plateforme_visio',
    'instrument',
    'didgeridoo_pret',
    'niveau_cours',
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

  /**
   * The Webform element plugin manager, when Webform is available.
   *
   * @var \Drupal\webform\Plugin\WebformElementManagerInterface|null
   */
  private $webformElementManager;

  public function __construct(AccountProxyInterface $current_account, PrivateTempStoreFactory $temp_store_factory, EntityTypeManagerInterface $entity_type_manager, ModuleHandlerInterface $module_handler, $webform_element_manager = NULL) {
    $this->currentAccount = $current_account;
    $this->tempStoreFactory = $temp_store_factory;
    $this->entityTypeManager = $entity_type_manager;
    $this->moduleHandler = $module_handler;
    $this->webformElementManager = $webform_element_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('current_user'),
      $container->get('tempstore.private'),
      $container->get('entity_type.manager'),
      $container->get('module_handler'),
      $container->has('plugin.manager.webform.element') ? $container->get('plugin.manager.webform.element') : NULL
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
    $stored = $this->ensureStoredCourseIsAvailable($stored, $form_state);
    $step = $this->resolveStep($form_state, $stored);

    $form['#attributes']['class'][] = 'reservation-first-course';
    $form['intro'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['reservation-first-course__intro']],
      'title' => [
        '#markup' => '<h2>' . $this->t('Réserver un cours') . '</h2>',
      ],
      'copy' => [
        '#markup' => '<p>' . $this->t('Choisissez le cours et le créneau avant le paiement. La réservation est confirmée uniquement après validation du paiement choisi.') . '</p>',
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

    if ($step === 'details') {
      $this->buildDetailsStep($form, $stored);
      return $form;
    }

    if ($step === 'payment') {
      $this->buildPaymentStep($form, $stored);
      return $form;
    }

    if ($step === 'confirmed') {
      $this->buildConfirmedStep($form, $stored);
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
    $options = $this->getCourseOptions();
    $stored_course = (string) ($stored['course'] ?? '');
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
        '#options' => $options,
        '#default_value' => array_key_exists($stored_course, $options) ? $stored_course : NULL,
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
    if (!array_key_exists($course, $this->getCourseOptions())) {
      $form_state->setErrorByName('course', $this->t('Choisissez un cours.'));
    }
  }

  public function submitCourseStep(array &$form, FormStateInterface $form_state): void {
    $course = (string) $form_state->getValue('course');
    $stored = $this->getStoredSelection();
    if (($stored['course'] ?? '') !== $course) {
      $this->invalidateCourseDependentSelection($stored);
    }
    $stored['course'] = $course;
    $stored['course_label'] = $this->getCourseLabel($course);
    $stored['step'] = 'slot';
    $this->setStoredSelection($stored);

    $this->prepareStepRebuild($form_state, 'slot');
  }

  private function buildSlotStep(array &$form, array $stored): void {
    if (empty($stored['course'])) {
      $form['empty'] = $this->buildResetNotice($this->t('Choisissez d’abord un cours.'));
      return;
    }

    $reservation_element = $this->buildReservationElement($stored['reservation_value'] ?? '');
    $reservation_available = ($reservation_element['#type'] ?? '') === 'webform_booking';
    $form['summary'] = $this->buildSummary($stored);
    $form['step'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['reservation-first-course__panel']],
      'title' => [
        '#markup' => '<h3>' . $this->t('2. Choix du créneau') . '</h3>',
      ],
      'reservation' => $reservation_element,
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
        '#value' => $this->t('Renseigner les détails'),
        '#button_type' => 'primary',
        '#disabled' => !$reservation_available,
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

    try {
      if (!$this->reservationSlotIsAvailable($booking['slot'], $booking['seats'])) {
        $form_state->setErrorByName('reservation', $this->t('Ce créneau n’est plus disponible. Choisissez-en un autre.'));
        return;
      }
    }
    catch (\Throwable $e) {
      $this->logger('unisonges_structure')->error('Unable to verify reservation slot availability: @message', [
        '@message' => $e->getMessage(),
      ]);
      $form_state->setErrorByName('reservation', $this->t('La disponibilité du créneau n’a pas pu être vérifiée. Réessayez dans quelques instants.'));
      return;
    }

    $form_state->set('reservation_value', $normalized);
  }

  public function submitSlotStep(array &$form, FormStateInterface $form_state): void {
    $reservation_value = (string) $form_state->get('reservation_value');
    $stored = $this->getStoredSelection();
    $stored['reservation_value'] = $reservation_value;
    $stored['slot_label'] = $this->formatReservationValue($reservation_value);
    $stored['step'] = 'details';
    unset($stored['payment_choice']);
    $this->setStoredSelection($stored);

    $this->prepareStepRebuild($form_state, 'details');
  }

  private function buildDetailsStep(array &$form, array $stored): void {
    if (empty($stored['course']) || empty($stored['reservation_value'])) {
      $form['empty'] = $this->buildResetNotice($this->t('Choisissez un cours et un créneau avant de renseigner les détails.'));
      return;
    }

    $details = is_array($stored['details'] ?? NULL) ? $stored['details'] : [];
    $form['summary'] = $this->buildSummary($stored);
    $form['step'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['reservation-first-course__panel']],
      'title' => [
        '#markup' => '<h3>' . $this->t('3. Détails du cours') . '</h3>',
      ],
      'mode_cours' => $this->buildDetailElement('mode_cours', $details),
      'plateforme_visio' => $this->buildDetailElement('plateforme_visio', $details),
      'adresse_domicile' => $this->buildDetailElement('adresse_domicile', $details),
      'code_postal_domicile' => $this->buildDetailElement('code_postal_domicile', $details),
      'telephone' => $this->buildDetailElement('telephone', $details),
      'instrument' => $this->buildDetailElement('instrument', $details),
      'didgeridoo_pret' => $this->buildDetailElement('didgeridoo_pret', $details),
      'niveau_cours' => $this->buildDetailElement('niveau_cours', $details),
      'notes_supplementaires' => $this->buildDetailElement('notes_supplementaires', $details),
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
        '#value' => $this->t('Choisir le paiement'),
        '#button_type' => 'primary',
        '#validate' => ['::validateDetailsStep'],
        '#submit' => ['::submitDetailsStep'],
      ],
    ];
  }

  public function validateDetailsStep(array &$form, FormStateInterface $form_state): void {
    $details = $this->normalizeDetailsValues($form_state->getValues());
    $errors = $this->validateDetailsValues($details);
    foreach ($errors as $key => $message) {
      $form_state->setErrorByName($key, $message);
    }

    if (!$errors) {
      $form_state->set('course_details', $details);
    }
  }

  public function submitDetailsStep(array &$form, FormStateInterface $form_state): void {
    $stored = $this->getStoredSelection();
    $stored['details'] = $form_state->get('course_details');
    $stored['step'] = 'payment';
    unset($stored['payment_choice']);
    $this->setStoredSelection($stored);

    $this->prepareStepRebuild($form_state, 'payment');
  }

  private function buildPaymentStep(array &$form, array $stored): void {
    if (empty($stored['course']) || empty($stored['reservation_value'])) {
      $form['empty'] = $this->buildResetNotice($this->t('Choisissez un cours et un créneau avant le paiement.'));
      return;
    }
    if (!$this->storedDetailsAreComplete($stored)) {
      $form['empty'] = $this->buildStepNotice(
        $this->t('Renseignez les détails du cours avant le paiement.'),
        $this->t('Renseigner les détails'),
        '::submitBackToDetails'
      );
      return;
    }

    $form['summary'] = $this->buildSummary($stored);
    $form['step'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['reservation-first-course__panel']],
      'title' => [
        '#markup' => '<h3>' . $this->t('4. Choix du paiement') . '</h3>',
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
      'payment_notes' => [
        '#markup' => '<p class="reservation-first-course__note">' . $this->t('Paiement sur place : le créneau est confirmé après validation et marqué COURS À PAYER.') . '</p><p class="reservation-first-course__note">' . $this->t('Paiement en ligne : vous continuez vers le parcours d’achat classique ; le créneau sélectionné n’est pas réservé.') . '</p>',
      ],
    ];

    $form['actions'] = [
      '#type' => 'actions',
      'previous' => [
        '#type' => 'submit',
        '#value' => $this->t('Modifier les détails'),
        '#submit' => ['::submitBackToDetails'],
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
    $stored['step'] = 'payment';
    $this->setStoredSelection($stored);

    if ($choice === 'online') {
      $this->messenger()->addStatus($this->t('Le paiement en ligne avec réservation de créneau sera finalisé prochainement. Vous pouvez continuer vers le parcours d’achat classique ; le créneau sélectionné n’est pas réservé.'));
      $form_state->setRedirectUrl($this->getOnlinePaymentUrl($stored));
      return;
    }

    try {
      $confirmation = $this->confirmPayOnSiteReservation($stored);
    }
    catch (\Throwable $e) {
      $this->logger('unisonges_structure')->error('Reservation-first pay-on-site confirmation failed for user @uid: @message', [
        '@uid' => $this->currentAccount->id(),
        '@message' => $e->getMessage(),
      ]);
      $this->messenger()->addError($this->t('La réservation n’a pas pu être confirmée. Merci de réessayer ou de nous contacter.'));
      $stored['step'] = 'payment';
      $this->setStoredSelection($stored);
      $form_state->set('step', 'payment');
      $form_state->setRebuild(TRUE);
      return;
    }

    $stored = $confirmation + $stored;
    $stored['step'] = 'confirmed';
    $this->setStoredSelection($stored);
    $form_state->set('step', 'confirmed');
    $form_state->setRebuild(TRUE);
  }

  private function buildConfirmedStep(array &$form, array $stored): void {
    $form['summary'] = $this->buildSummary($stored);
    $form['step'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['reservation-first-course__panel', 'reservation-first-course__panel--success']],
      'title' => [
        '#markup' => '<h3>' . $this->t('Réservation confirmée') . '</h3>',
      ],
      'message' => [
        '#markup' => '<p><strong>' . $this->t('COURS À PAYER') . '</strong> — ' . $this->t('votre créneau est confirmé avec paiement sur place.') . '</p>',
      ],
    ];
  }

  private function buildProgress(string $step): array {
    $steps = [
      'course' => $this->t('Compte / cours'),
      'slot' => $this->t('Créneau'),
      'details' => $this->t('Détails'),
      'payment' => $this->t('Paiement'),
      'confirmed' => $this->t('Confirmation'),
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

  private function buildStepNotice($message, $button_label, string $submit_handler): array {
    return [
      '#type' => 'container',
      '#attributes' => ['class' => ['reservation-first-course__panel']],
      'message' => [
        '#markup' => '<p>' . Html::escape((string) $message) . '</p>',
      ],
      'actions' => [
        '#type' => 'actions',
        'next' => [
          '#type' => 'submit',
          '#value' => $button_label,
          '#submit' => [$submit_handler],
          '#limit_validation_errors' => [],
        ],
      ],
    ];
  }

  private function buildReservationElement(string $default_value): array {
    if (!$this->moduleHandler->moduleExists('webform_booking') || !$this->webformElementManager || !method_exists($this->webformElementManager, 'processElement')) {
      return $this->buildUnavailableReservationElement();
    }

    try {
      $element = $this->getWebformReservationElement();
      if (($element['#type'] ?? '') !== 'webform_booking'
        || ($element['#webform_key'] ?? '') !== 'reservation'
        || ($element['#webform'] ?? '') !== 'cours_particuliers_reservation') {
        throw new \RuntimeException('The initialized reservation Webform element is unavailable.');
      }

      $element['#title'] = $this->t('Choisir un créneau');
      $element['#parents'] = ['reservation'];
      $element['#description'] = $this->t('Le créneau est choisi avant le paiement. Il sera confirmé après validation du paiement choisi.');
      $element['#required'] = TRUE;

      $parsed_default = unisonges_structure_parse_booking_reservation_value($default_value);
      if ($parsed_default !== NULL && empty($parsed_default['cancelled'])) {
        // The widget input expects the slot without the persisted "|N" suffix.
        $element['#default_value'] = $parsed_default['date'] . ' ' . $parsed_default['time'];
      }
      else {
        unset($element['#default_value']);
      }

      // Apply the Webform plugin's supported Form API preparation. This adds
      // the booking library, drupalSettings, slot/seats children and callbacks.
      $this->webformElementManager->processElement($element);
      return $element;
    }
    catch (\Throwable $e) {
      $this->logger('unisonges_structure')->error('Unable to prepare the reservation Webform element: @message', [
        '@message' => $e->getMessage(),
      ]);
      return $this->buildUnavailableReservationElement();
    }
  }

  private function buildUnavailableReservationElement(): array {
    return [
      '#type' => 'item',
      '#title' => $this->t('Choisir un créneau'),
      '#markup' => '<p class="messages messages--error">' . $this->t('Le sélecteur de créneaux est temporairement indisponible. Rechargez la page ou contactez-nous si le problème persiste.') . '</p>',
    ];
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

  private function reservationSlotIsAvailable(string $slot, int $seats): bool {
    if (!class_exists('Drupal\webform_booking\Controller\WebformBookingController')) {
      throw new \RuntimeException('The Webform Booking availability controller is unavailable.');
    }

    [$date, $time] = explode(' ', $slot, 2);
    $controller = new \Drupal\webform_booking\Controller\WebformBookingController();
    $days_response = $controller->getAvailableDays(
      'cours_particuliers_reservation',
      'reservation',
      substr($date, 0, 8) . '01'
    );
    $days = json_decode((string) $days_response->getContent(), TRUE);
    $day_is_available = FALSE;
    foreach (is_array($days) ? $days : [] as $day) {
      if (($day['date'] ?? '') === $date && !empty($day['hasSlots'])) {
        $day_is_available = TRUE;
        break;
      }
    }
    if (!$day_is_available) {
      return FALSE;
    }

    $slots_response = $controller->getAvailableSlots(
      'cours_particuliers_reservation',
      'reservation',
      $date
    );
    $slots = json_decode((string) $slots_response->getContent(), TRUE);
    foreach (is_array($slots) ? $slots : [] as $available_slot) {
      $slot_start = explode('-', (string) ($available_slot['time'] ?? ''), 2)[0];
      if ($slot_start === $time
        && ($available_slot['status'] ?? '') === 'available'
        && (int) ($available_slot['availableSeats'] ?? 0) >= $seats) {
        return TRUE;
      }
    }

    return FALSE;
  }

  private function getWebformReservationElement(): array {
    try {
      if (!$this->entityTypeManager->hasDefinition('webform')) {
        return [];
      }
      $webform = $this->entityTypeManager->getStorage('webform')->load('cours_particuliers_reservation');
      if ($webform && method_exists($webform, 'getElement')) {
        // Unlike getElementDecoded(), getElement() includes the Webform runtime
        // metadata required by webform_booking (#webform and #webform_key).
        $element = $webform->getElement('reservation');
        return is_array($element) ? $element : [];
      }
    }
    catch (\Throwable $e) {
      return [];
    }

    return [];
  }

  private function buildDetailElement(string $key, array $stored_details): array {
    $source = $this->getWebformElement($key);
    $type = (string) ($source['#type'] ?? 'textfield');
    if (!in_array($type, ['radios', 'select', 'tel', 'textarea', 'textfield'], TRUE)) {
      $type = 'textfield';
    }

    $element = [
      '#type' => $type,
      '#title' => $source['#title'] ?? $this->detailFieldFallbackTitle($key),
      '#parents' => [$key],
      '#default_value' => (string) ($stored_details[$key] ?? ''),
      '#required' => in_array($key, self::ALWAYS_REQUIRED_DETAIL_FIELDS, TRUE),
    ];

    foreach (['#options', '#empty_option', '#description', '#placeholder', '#pattern', '#pattern_error', '#attributes', '#size', '#maxlength', '#states'] as $property) {
      if (array_key_exists($property, $source)) {
        $element[$property] = $source[$property];
      }
    }

    if ($this->detailFieldIsConditionallyRequired($key)) {
      $element['#required'] = FALSE;
    }

    return $element;
  }

  private function getWebformElement(string $key): array {
    try {
      if (!$this->entityTypeManager->hasDefinition('webform')) {
        return [];
      }
      $webform = $this->entityTypeManager->getStorage('webform')->load('cours_particuliers_reservation');
      if ($webform && method_exists($webform, 'getElementDecoded')) {
        $element = $webform->getElementDecoded($key);
        return is_array($element) ? $element : [];
      }
    }
    catch (\Throwable $e) {
      return [];
    }

    return [];
  }

  private function detailFieldFallbackTitle(string $key): string {
    $titles = [
      'mode_cours' => 'Mode du cours',
      'plateforme_visio' => 'Plateforme de visio',
      'adresse_domicile' => 'Adresse complète',
      'code_postal_domicile' => 'Code postal',
      'telephone' => 'Téléphone',
      'instrument' => 'Instrument',
      'didgeridoo_pret' => 'Le professeur doit-il fournir un didgeridoo ?',
      'niveau_cours' => 'Niveau du cours',
      'notes_supplementaires' => 'Notes supplémentaires',
    ];

    return $titles[$key] ?? $key;
  }

  private function detailFieldIsConditionallyRequired(string $key): bool {
    return in_array($key, ['plateforme_visio', 'adresse_domicile', 'code_postal_domicile', 'didgeridoo_pret'], TRUE);
  }

  private function normalizeDetailsValues(array $values): array {
    $details = [];
    foreach (self::DETAIL_FIELDS as $key) {
      $value = $values[$key] ?? '';
      if (is_array($value)) {
        $value = implode(', ', array_filter(array_map('strval', $value), 'strlen'));
      }
      $details[$key] = trim((string) $value);
    }

    return $details;
  }

  private function validateDetailsValues(array $details): array {
    $errors = [];
    foreach ($this->requiredDetailFields($details) as $key) {
      if (($details[$key] ?? '') === '') {
        $errors[$key] = $this->t('Renseignez @field.', [
          '@field' => mb_strtolower($this->detailFieldLabel($key)),
        ]);
      }
    }

    foreach ($this->detailFieldsToValidate($details) as $key) {
      $value = (string) ($details[$key] ?? '');
      if ($value === '') {
        continue;
      }
      if (!$this->detailValueIsAllowedOption($key, $value)) {
        $errors[$key] = $this->t('Choisissez une valeur valide pour @field.', [
          '@field' => mb_strtolower($this->detailFieldLabel($key)),
        ]);
        continue;
      }
      $pattern_error = $this->validateDetailPattern($key, $value);
      if ($pattern_error !== '') {
        $errors[$key] = $pattern_error;
      }
    }

    return $errors;
  }

  private function detailFieldsToValidate(array $details): array {
    return array_values(array_unique(array_merge(
      $this->requiredDetailFields($details),
      ['notes_supplementaires']
    )));
  }

  private function requiredDetailFields(array $details): array {
    $required = self::ALWAYS_REQUIRED_DETAIL_FIELDS;
    if (($details['mode_cours'] ?? '') === 'visio') {
      $required[] = 'plateforme_visio';
    }
    if (($details['mode_cours'] ?? '') === 'domicile') {
      $required[] = 'adresse_domicile';
      $required[] = 'code_postal_domicile';
    }
    if (($details['instrument'] ?? '') === 'didgeridoo') {
      $required[] = 'didgeridoo_pret';
    }

    return $required;
  }

  private function detailValueIsAllowedOption(string $key, string $value): bool {
    $source = $this->getWebformElement($key);
    $options = $source['#options'] ?? NULL;
    if (!is_array($options) || $options === []) {
      return !in_array($key, self::OPTION_DETAIL_FIELDS, TRUE);
    }

    return array_key_exists($value, $options);
  }

  private function validateDetailPattern(string $key, string $value): string {
    $source = $this->getWebformElement($key);
    $pattern = trim((string) ($source['#pattern'] ?? ''));
    if ($pattern === '') {
      return '';
    }

    $regex = '~^(?:' . str_replace('~', '\\~', $pattern) . ')$~u';
    $match = @preg_match($regex, $value);
    if ($match === 1) {
      return '';
    }

    return (string) ($source['#pattern_error'] ?? $this->t('La valeur saisie pour @field n’est pas valide.', [
      '@field' => mb_strtolower($this->detailFieldLabel($key)),
    ]));
  }

  private function detailFieldLabel(string $key): string {
    $source = $this->getWebformElement($key);
    return (string) ($source['#title'] ?? $this->detailFieldFallbackTitle($key));
  }

  private function storedDetailsAreComplete(array $stored): bool {
    try {
      $this->validateStoredDetails($stored);
    }
    catch (\Throwable $e) {
      return FALSE;
    }

    return TRUE;
  }

  private function validateStoredDetails(array $stored): array {
    $details = $this->normalizeDetailsValues(is_array($stored['details'] ?? NULL) ? $stored['details'] : []);
    $errors = $this->validateDetailsValues($details);
    if ($errors) {
      throw new \RuntimeException('Stored course details are missing or invalid: ' . implode(', ', array_keys($errors)));
    }

    return $details;
  }

  private function filterSubmissionDetails(array $details): array {
    $data = [];
    foreach (self::ALWAYS_REQUIRED_DETAIL_FIELDS as $key) {
      $data[$key] = $details[$key];
    }

    if (($details['mode_cours'] ?? '') === 'visio') {
      $data['plateforme_visio'] = $details['plateforme_visio'];
    }
    if (($details['mode_cours'] ?? '') === 'domicile') {
      $data['adresse_domicile'] = $details['adresse_domicile'];
      $data['code_postal_domicile'] = $details['code_postal_domicile'];
    }
    if (($details['instrument'] ?? '') === 'didgeridoo') {
      $data['didgeridoo_pret'] = $details['didgeridoo_pret'];
    }
    if (($details['notes_supplementaires'] ?? '') !== '') {
      $data['notes_supplementaires'] = $details['notes_supplementaires'];
    }

    return $data;
  }

  private function getCourseOptions(?bool &$product_options_loaded = NULL): array {
    $options = [];
    $product_options_loaded = FALSE;
    try {
      if ($this->entityTypeManager->hasDefinition('commerce_product')) {
        $storage = $this->entityTypeManager->getStorage('commerce_product');
        $query = $storage->getQuery()
          ->accessCheck(TRUE)
          ->condition('type', self::COURSE_BUNDLES, 'IN')
          ->condition('status', 1)
          ->sort('title', 'ASC');
        $ids = $query->execute();
        $product_options_loaded = TRUE;
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
      $product_options_loaded = FALSE;
    }

    return $options ?: [
      'bundle:cours_essai' => $this->t('Cours d’essai'),
      'bundle:cours_deb_inter' => $this->t('Cours débutant / intermédiaire'),
      'bundle:cours_avance' => $this->t('Cours avancé'),
    ];
  }

  private function ensureStoredCourseIsAvailable(array $stored, FormStateInterface $form_state): array {
    $course = (string) ($stored['course'] ?? '');
    $step = (string) ($form_state->get('step') ?: ($stored['step'] ?? 'course'));
    if ($course === '' || $step === 'confirmed') {
      return $stored;
    }

    $options = $this->getCourseOptions($product_options_loaded);
    if (array_key_exists($course, $options)) {
      return $stored;
    }
    if (strpos($course, 'product:') === 0 && !$product_options_loaded) {
      // Do not discard a valid selection because Commerce was temporarily
      // unavailable. A successful query that omits the product is authoritative.
      return $stored;
    }

    unset($stored['course'], $stored['course_label']);
    $this->invalidateCourseDependentSelection($stored);
    $stored['step'] = 'course';
    $this->setStoredSelection($stored);
    $form_state->set('step', 'course');
    $this->messenger()->addWarning($this->t('Le cours précédemment choisi n’est plus disponible. Choisissez un autre cours.'));

    return $stored;
  }

  private function invalidateCourseDependentSelection(array &$stored): void {
    unset(
      $stored['reservation_value'],
      $stored['slot_label'],
      $stored['details'],
      $stored['payment_choice'],
      $stored['submission_id'],
      $stored['order_id'],
      $stored['payment_label']
    );
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

  private function confirmPayOnSiteReservation(array $stored): array {
    $booking = $this->validateStoredBooking($stored);
    $this->validateStoredDetails($stored);
    if (!_unisonges_structure_acquire_booking_slot_lock($booking['slot'])) {
      throw new \RuntimeException('Unable to acquire booking slot lock.');
    }

    $transaction = \Drupal::database()->startTransaction();
    try {
      $conflict_message = _unisonges_structure_get_booking_conflict_message($booking['slot'], $booking['seats'], (int) $this->currentAccount->id(), $this->getReservationCapacity()['seats_slot']);
      if ($conflict_message !== '') {
        throw new \RuntimeException('Booking slot conflict before pay-on-site confirmation.');
      }

      $order = $this->createPayOnSiteOrder($stored);
      _unisonges_structure_ensure_to_pay_course_rights_from_order($order);
      if (!$this->orderHasPendingToPayRight((int) $order->id())) {
        throw new \RuntimeException('Pay-on-site right was not created for the order.');
      }

      $submission = $this->createReservationSubmission($stored, (int) $order->id());
      $payment = _unisonges_structure_get_submission_payment_context($submission);
      if (($payment['status'] ?? '') !== 'to_pay') {
        throw new \RuntimeException('Reservation submission was not marked COURS À PAYER.');
      }
    }
    catch (\Throwable $e) {
      if (method_exists($transaction, 'rollBack')) {
        $transaction->rollBack();
      }
      throw $e;
    }
    finally {
      _unisonges_structure_release_booking_slot_lock($booking['slot']);
    }

    return [
      'submission_id' => (int) $submission->id(),
      'order_id' => (int) $order->id(),
      'payment_label' => 'COURS À PAYER',
    ];
  }

  private function validateStoredBooking(array $stored): array {
    $reservation_value = (string) ($stored['reservation_value'] ?? '');
    $booking = _unisonges_structure_parse_booking_form_value($reservation_value);
    if ($booking['error'] !== '' || $booking['slot'] === '') {
      throw new \RuntimeException('Stored reservation value is invalid.');
    }

    $capacity = $this->getReservationCapacity();
    if ($booking['seats'] > $capacity['max_seats_per_booking']) {
      throw new \RuntimeException('Stored reservation exceeds capacity.');
    }

    return $booking;
  }

  private function createPayOnSiteOrder(array $stored) {
    $context = $this->getSelectedCourseCommerceContext((string) ($stored['course'] ?? ''));
    $uid = (int) $this->currentAccount->id();
    if ($uid <= 0) {
      throw new \RuntimeException('Missing user for pay-on-site order.');
    }

    $order_storage = $this->entityTypeManager->getStorage('commerce_order');
    $order_item_storage = $this->entityTypeManager->getStorage('commerce_order_item');
    $order = $order_storage->create([
      'type' => 'default',
      'uid' => $uid,
      'mail' => $this->getCurrentUserMail(),
      'store_id' => $context['store_id'],
      'state' => 'draft',
    ]);
    if (method_exists($order, 'hasField') && $order->hasField('payment_gateway')) {
      $order->set('payment_gateway', 'manual');
    }
    $order->save();

    $variation = $context['variation'];
    $order_item_values = [
      'type' => method_exists($variation, 'getOrderItemTypeId') ? $variation->getOrderItemTypeId() : 'default',
      'purchased_entity' => $variation,
      'quantity' => '1',
      'order_id' => $order->id(),
    ];
    if (method_exists($variation, 'getPrice')) {
      $order_item_values['unit_price'] = $variation->getPrice();
    }
    $order_item = $order_item_storage->create($order_item_values);
    $order_item->save();

    $order->set('order_items', [['target_id' => $order_item->id()]]);
    if (method_exists($order, 'getState')) {
      try {
        $state = $order->getState();
        if (method_exists($state, 'isTransitionAllowed') && method_exists($state, 'applyTransitionById') && $state->isTransitionAllowed('place')) {
          $state->applyTransitionById('place');
        }
        else {
          $order->set('state', 'completed');
        }
      }
      catch (\Throwable $e) {
        $order->set('state', 'completed');
      }
    }
    else {
      $order->set('state', 'completed');
    }
    $order->save();

    return $order;
  }

  private function getSelectedCourseCommerceContext(string $course): array {
    if (!$this->entityTypeManager->hasDefinition('commerce_product')) {
      throw new \RuntimeException('Commerce product storage is unavailable.');
    }

    $product_storage = $this->entityTypeManager->getStorage('commerce_product');
    $product = NULL;
    if (strpos($course, 'product:') === 0) {
      $product = $product_storage->load((int) substr($course, strlen('product:')));
    }
    elseif (strpos($course, 'bundle:') === 0) {
      $bundle = substr($course, strlen('bundle:'));
      $ids = $product_storage->getQuery()
        ->accessCheck(FALSE)
        ->condition('type', $bundle)
        ->condition('status', 1)
        ->range(0, 1)
        ->execute();
      $product = $ids ? $product_storage->load((int) reset($ids)) : NULL;
    }

    if (!$product || !method_exists($product, 'getVariations') || !method_exists($product, 'bundle')) {
      throw new \RuntimeException('Selected course product could not be resolved.');
    }

    foreach ($product->getVariations() as $variation) {
      if ($variation && (!method_exists($variation, 'isPublished') || $variation->isPublished())) {
        return [
          'product' => $product,
          'variation' => $variation,
          'store_id' => $this->getProductStoreId($product),
          'bundle' => (string) $product->bundle(),
        ];
      }
    }

    throw new \RuntimeException('Selected course product has no purchasable variation.');
  }

  private function getProductStoreId($product): int {
    if (method_exists($product, 'getStores')) {
      $stores = $product->getStores();
      $store = $stores ? reset($stores) : NULL;
      if ($store && method_exists($store, 'id')) {
        return (int) $store->id();
      }
    }
    if (method_exists($product, 'hasField') && $product->hasField('stores') && !$product->get('stores')->isEmpty()) {
      return (int) $product->get('stores')->target_id;
    }

    $ids = $this->entityTypeManager->getStorage('commerce_store')->getQuery()
      ->accessCheck(FALSE)
      ->condition('status', 1)
      ->range(0, 1)
      ->execute();
    if ($ids) {
      return (int) reset($ids);
    }

    throw new \RuntimeException('No store found for selected course product.');
  }

  private function getCurrentUserMail(): string {
    if (method_exists($this->currentAccount, 'getEmail')) {
      return (string) $this->currentAccount->getEmail();
    }

    return '';
  }

  private function orderHasPendingToPayRight(int $order_id): bool {
    if ($order_id <= 0 || !_unisonges_structure_to_pay_rights_table_exists()) {
      return FALSE;
    }

    return (bool) \Drupal::database()->select(_unisonges_structure_to_pay_rights_table_name(), 'r')
      ->condition('order_id', $order_id)
      ->condition('status', 'pending_payment')
      ->condition('remaining_to_pay_credits', 0, '>')
      ->countQuery()
      ->execute()
      ->fetchField();
  }

  private function createReservationSubmission(array $stored, int $order_id) {
    if (!$this->entityTypeManager->hasDefinition('webform_submission')) {
      throw new \RuntimeException('Webform submission storage is unavailable.');
    }

    $course_label = (string) ($stored['course_label'] ?? $this->getCourseLabel((string) ($stored['course'] ?? '')));
    $details = $this->validateStoredDetails($stored);
    $data = $this->filterSubmissionDetails($details) + [
      'reservation' => (string) $stored['reservation_value'],
      'unisonges_payment_choice' => 'pay_on_site',
      'unisonges_pay_on_site_order_id' => (string) $order_id,
      'unisonges_course_label' => $course_label,
    ];
    $payment_note = 'Cours sélectionné : ' . $course_label . '. Paiement : sur place.';
    $data['notes_supplementaires'] = trim((string) ($data['notes_supplementaires'] ?? '')) !== ''
      ? trim((string) $data['notes_supplementaires']) . "\n\n" . $payment_note
      : $payment_note;

    $submission = $this->entityTypeManager->getStorage('webform_submission')->create([
      'webform_id' => 'cours_particuliers_reservation',
      'uid' => (int) $this->currentAccount->id(),
      'in_draft' => FALSE,
      'data' => $data,
    ]);
    $submission->save();

    return $submission;
  }

  private function resolveStep(FormStateInterface $form_state, array $stored): string {
    $step = (string) ($form_state->get('step') ?: ($stored['step'] ?? 'course'));
    return in_array($step, ['course', 'slot', 'details', 'payment', 'confirmed'], TRUE) ? $step : 'course';
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

  private function prepareStepRebuild(FormStateInterface $form_state, string $step): void {
    $keys = array_merge(
      ['course', 'reservation', 'payment_choice', 'op', '_triggering_element_name', '_triggering_element_value'],
      self::DETAIL_FIELDS
    );

    $user_input = $form_state->getUserInput();
    foreach ($keys as $key) {
      $form_state->unsetValue($key);
      unset($user_input[$key]);
    }
    $form_state->setUserInput($user_input);

    $storage = $form_state->getStorage();
    unset($storage['reservation_value'], $storage['course_details']);
    $form_state->setStorage($storage);
    $form_state->set('step', $step);
    $form_state->setRebuild(TRUE);
  }

  public function submitBackToCourse(array &$form, FormStateInterface $form_state): void {
    $stored = $this->getStoredSelection();
    $stored['step'] = 'course';
    $this->setStoredSelection($stored);
    $this->prepareStepRebuild($form_state, 'course');
  }

  public function submitBackToSlot(array &$form, FormStateInterface $form_state): void {
    $stored = $this->getStoredSelection();
    $stored['step'] = 'slot';
    $this->setStoredSelection($stored);
    $this->prepareStepRebuild($form_state, 'slot');
  }

  public function submitBackToDetails(array &$form, FormStateInterface $form_state): void {
    $stored = $this->getStoredSelection();
    $stored['step'] = 'details';
    $this->setStoredSelection($stored);
    $this->prepareStepRebuild($form_state, 'details');
  }

  public function submitBackToPayment(array &$form, FormStateInterface $form_state): void {
    $stored = $this->getStoredSelection();
    $stored['step'] = 'payment';
    $this->setStoredSelection($stored);
    $this->prepareStepRebuild($form_state, 'payment');
  }

  public function submitRestart(array &$form, FormStateInterface $form_state): void {
    try {
      $this->tempStoreFactory->get('unisonges_structure')->delete(self::TEMPSTORE_KEY);
    }
    catch (\Throwable $e) {
      $this->logger('unisonges_structure')->error('Unable to clear the reservation tunnel tempstore: @message', [
        '@message' => $e->getMessage(),
      ]);
      $this->messenger()->addWarning($this->t('La sélection n’a pas pu être effacée complètement. Rechargez la page avant de recommencer.'));
    }
    $this->prepareStepRebuild($form_state, 'course');
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    // Step-specific submit handlers are used.
  }

}
