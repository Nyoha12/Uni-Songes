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
 *
 * Injected services stay protected so FormBase can serialize their service IDs
 * and rehydrate them during Form API rebuilds.
 */
final class ReservationFirstCourseTunnelForm extends FormBase {

  private const TEMPSTORE_KEY = 'course_reservation_first_tunnel';

  private const LEGACY_LEVEL_FIELD = 'niveau_cours';

  private const PRODUCTION_COURSE_SELECTIONS = [
    'essai' => [
      'sku' => 'COURS-ESSAI-10',
      'bundle' => 'cours_essai',
    ],
    'didgeridoo:normal' => [
      'sku' => 'COURS-DIDGERIDOO-1H-25',
      'bundle' => 'cours_deb_inter',
    ],
    'didgeridoo:etudiant' => [
      'sku' => 'COURS-DIDGERIDOO-1H-ETUDIANT-15',
      'bundle' => 'cours_deb_inter',
    ],
    'guimbarde:normal' => [
      'sku' => 'COURS-GUIMBARDE-1H-25',
      'bundle' => 'cours_deb_inter',
    ],
    'guimbarde:etudiant' => [
      'sku' => 'COURS-GUIMBARDE-1H-ETUDIANT-15',
      'bundle' => 'cours_deb_inter',
    ],
    'meditation-improvisation:normal' => [
      'sku' => 'COURS-MEDITATION-IMPRO-1H-25',
      'bundle' => 'cours_deb_inter',
    ],
    'meditation-improvisation:etudiant' => [
      'sku' => 'COURS-MEDITATION-IMPRO-1H-ETUDIANT-15',
      'bundle' => 'cours_deb_inter',
    ],
  ];

  /**
   * Local-only fixture fallback, enabled only without a production SKU match.
   */
  private const LOCAL_FIXTURE_COURSE_SELECTIONS = [
    'essai' => [
      'sku' => 'LOCAL-FIXTURE-COURS-ESSAI',
      'bundle' => 'cours_essai',
    ],
    'didgeridoo:normal' => [
      'sku' => 'LOCAL-FIXTURE-COURS-DEB-INTER',
      'bundle' => 'cours_deb_inter',
    ],
    'didgeridoo:etudiant' => [
      'sku' => 'LOCAL-FIXTURE-COURS-DEB-INTER',
      'bundle' => 'cours_deb_inter',
    ],
    'guimbarde:normal' => [
      'sku' => 'LOCAL-FIXTURE-COURS-DEB-INTER',
      'bundle' => 'cours_deb_inter',
    ],
    'guimbarde:etudiant' => [
      'sku' => 'LOCAL-FIXTURE-COURS-DEB-INTER',
      'bundle' => 'cours_deb_inter',
    ],
    'meditation-improvisation:normal' => [
      'sku' => 'LOCAL-FIXTURE-COURS-DEB-INTER',
      'bundle' => 'cours_deb_inter',
    ],
    'meditation-improvisation:etudiant' => [
      'sku' => 'LOCAL-FIXTURE-COURS-DEB-INTER',
      'bundle' => 'cours_deb_inter',
    ],
  ];

  private const DISCIPLINE_QUERY_VALUES = [
    'essai',
    'didgeridoo',
    'guimbarde',
    'meditation-improvisation',
  ];

  private const DETAIL_FIELDS = [
    'mode_cours',
    'plateforme_visio',
    'adresse_domicile',
    'code_postal_domicile',
    'telephone',
    'instrument',
    'didgeridoo_pret',
    'notes_supplementaires',
  ];

  private const ALWAYS_REQUIRED_DETAIL_FIELDS = [
    'mode_cours',
    'telephone',
    'instrument',
  ];

  private const OPTION_DETAIL_FIELDS = [
    'mode_cours',
    'plateforme_visio',
    'instrument',
    'didgeridoo_pret',
  ];

  /**
   * The current user.
   *
   * @var \Drupal\Core\Session\AccountProxyInterface
   */
  protected $currentAccount;

  /**
   * Private tempstore factory.
   *
   * @var \Drupal\Core\TempStore\PrivateTempStoreFactory
   */
  protected $tempStoreFactory;

  /**
   * Entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * Module handler.
   *
   * @var \Drupal\Core\Extension\ModuleHandlerInterface
   */
  protected $moduleHandler;

  /**
   * The Webform element plugin manager, when Webform is available.
   *
   * @var \Drupal\webform\Plugin\WebformElementManagerInterface|null
   */
  protected $webformElementManager;

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
    $this->discardLegacyLevelFormState($form_state);
    $stored = $this->getStoredSelection();
    $stored = $this->migrateLegacyStoredCourseSelection($stored);
    $stored = $this->ensureStoredCourseIsAvailable($stored, $form_state);
    $deep_link_discipline = $this->getDeepLinkDiscipline();
    if (!$this->currentAccount->isAnonymous()) {
      $stored = $this->applyDisciplineDeepLink($stored, $form_state, $deep_link_discipline);
    }
    $step = $this->resolveStep($form_state, $stored);

    $form['#attributes']['class'][] = 'reservation-first-course';
    $form['#attached']['library'][] = 'unisonges_structure/reservation-first-tunnel';
    if ($step !== 'confirmed') {
      $form['intro'] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['reservation-first-course__intro']],
        'copy' => [
          '#type' => 'html_tag',
          '#tag' => 'p',
          '#value' => $this->t('Choisissez votre cours, puis un créneau. Vous pourrez ensuite sélectionner votre mode de paiement.'),
        ],
      ];
      $form['progress'] = $this->buildProgress($step);
    }

    if ($this->currentAccount->isAnonymous()) {
      $form['anonymous'] = $this->buildAnonymousNotice($deep_link_discipline);
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

  private function buildAnonymousNotice(string $discipline): array {
    $destination_options = $discipline === '' ? [] : ['query' => ['discipline' => $discipline]];
    $destination = Url::fromRoute('unisonges_structure.reservation_course_tunnel', [], $destination_options)->toString();
    return [
      '#type' => 'container',
      '#attributes' => ['class' => ['reservation-first-course__panel']],
      'message' => [
        'title' => [
          '#type' => 'html_tag',
          '#tag' => 'h2',
          '#value' => $this->t('Compte / identification'),
        ],
        'copy' => [
          '#type' => 'html_tag',
          '#tag' => 'p',
          '#value' => $this->t('Connectez-vous pour choisir un cours, un créneau, puis le paiement. La réservation ne peut pas être confirmée sans compte.'),
        ],
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
    $selections = $this->getCourseSelections();
    $discipline_options = $this->getDisciplineOptions($selections);
    $stored_discipline = (string) ($stored['discipline'] ?? '');
    $stored_tariff = (string) ($stored['tariff'] ?? '');
    $form['step'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['reservation-first-course__panel']],
      'title' => [
        '#type' => 'html_tag',
        '#tag' => 'h2',
        '#value' => $this->t('1. Choix du cours'),
      ],
    ];

    $selection_invalidated = !empty($stored['_course_selection_invalidated']);
    if (!$discipline_options) {
      $message = $selection_invalidated
        ? $this->t('L’offre précédemment choisie n’est plus disponible et aucun cours n’est actuellement proposé à la réservation.')
        : $this->t('Aucun cours n’est disponible à la réservation pour le moment.');
      $form['step']['unavailable'] = [
        '#markup' => '<p class="messages messages--warning">' . $message . '</p>',
      ];
      return;
    }

    if ($selection_invalidated) {
      $form['step']['selection_unavailable'] = [
        '#markup' => '<p class="messages messages--warning">' . $this->t('L’offre précédemment choisie n’est plus disponible. Choisissez un autre cours.') . '</p>',
      ];
    }

    $form['step']['discipline'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['reservation-first-course__choices', 'reservation-first-course__discipline']],
      'choices' => [
        '#type' => 'radios',
        '#title' => $this->t('Discipline'),
        '#parents' => ['discipline'],
        '#options' => $discipline_options,
        '#default_value' => array_key_exists($stored_discipline, $discipline_options) ? $stored_discipline : NULL,
        '#required' => TRUE,
      ],
    ];

    $form['step']['trial_price'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['reservation-first-course__trial-price']],
      '#states' => [
        'visible' => [
          ':input[name="discipline"]' => ['value' => 'essai'],
        ],
      ],
      'copy' => [
        '#markup' => '<p>' . $this->t('Tarif unique — 10 EUR') . '</p>',
      ],
    ];

    $tariff_states = [
      [':input[name="discipline"]' => ['value' => 'didgeridoo']],
      'or',
      [':input[name="discipline"]' => ['value' => 'guimbarde']],
      'or',
      [':input[name="discipline"]' => ['value' => 'meditation-improvisation']],
    ];
    $form['step']['tariff'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['reservation-first-course__choices', 'reservation-first-course__tariff']],
      '#states' => [
        'visible' => $tariff_states,
      ],
      'choices' => [
        '#type' => 'radios',
        '#title' => $this->t('Tarif'),
        '#parents' => ['tariff'],
        '#options' => $this->getTariffOptions(),
        '#default_value' => in_array($stored_tariff, ['normal', 'etudiant'], TRUE) ? $stored_tariff : NULL,
        '#states' => [
          'required' => $tariff_states,
        ],
      ],
    ];

    $form['actions'] = [
      '#type' => 'actions',
      '#attributes' => ['class' => ['reservation-first-course__actions']],
      'next' => [
        '#type' => 'submit',
        '#value' => $this->t('Continuer vers les créneaux'),
        '#button_type' => 'primary',
        '#weight' => -10,
        '#attributes' => ['class' => ['reservation-first-course__action--next']],
        '#validate' => ['::validateCourseStep'],
        '#submit' => ['::submitCourseStep'],
      ],
    ];
  }

  public function validateCourseStep(array &$form, FormStateInterface $form_state): void {
    $discipline = (string) $form_state->getValue('discipline');
    $selections = $this->getCourseSelections();
    if (!array_key_exists($discipline, $this->getDisciplineOptions($selections))) {
      $form_state->setErrorByName('discipline', $this->t('Choisissez une discipline.'));
      return;
    }

    $tariff = $discipline === 'essai' ? '' : (string) $form_state->getValue('tariff');
    if ($discipline !== 'essai' && !array_key_exists($tariff, $this->getTariffOptions())) {
      $form_state->setErrorByName('tariff', $this->t('Choisissez un tarif.'));
      return;
    }

    $selection = $this->getCourseSelection($discipline, $tariff, $selections);
    if (!$selection) {
      $form_state->setErrorByName($discipline === 'essai' ? 'discipline' : 'tariff', $this->t('Cette offre n’est plus disponible. Choisissez une autre option.'));
      return;
    }

    $form_state->set('course_selection', $selection);
  }

  public function submitCourseStep(array &$form, FormStateInterface $form_state): void {
    $selection = $form_state->get('course_selection');
    if (!is_array($selection)) {
      return;
    }
    $stored = $this->getStoredSelection();
    if (($stored['discipline'] ?? '') !== $selection['discipline']
      || ($stored['tariff'] ?? '') !== $selection['tariff']
      || ($stored['course'] ?? '') !== $selection['course']) {
      $this->invalidateCourseDependentSelection($stored);
    }
    $stored['discipline'] = $selection['discipline'];
    if ($selection['tariff'] === '') {
      unset($stored['tariff']);
    }
    else {
      $stored['tariff'] = $selection['tariff'];
    }
    $stored['course'] = $selection['course'];
    $stored['course_label'] = $selection['course_label'];
    $stored['course_display_label'] = $selection['course_display_label'];
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
        '#type' => 'html_tag',
        '#tag' => 'h2',
        '#value' => $this->t('2. Choix du créneau'),
      ],
      'reservation' => $reservation_element,
    ];

    $form['actions'] = [
      '#type' => 'actions',
      '#attributes' => ['class' => ['reservation-first-course__actions']],
      'next' => [
        '#type' => 'submit',
        '#value' => $this->t('Continuer vers les détails'),
        '#button_type' => 'primary',
        '#weight' => -10,
        '#attributes' => ['class' => ['reservation-first-course__action--next']],
        '#disabled' => !$reservation_available,
        '#validate' => ['::validateSlotStep'],
        '#submit' => ['::submitSlotStep'],
      ],
      'previous' => [
        '#type' => 'submit',
        '#value' => $this->t('Retour au cours'),
        '#weight' => 10,
        '#attributes' => ['class' => ['reservation-first-course__action--previous']],
        '#submit' => ['::submitBackToCourse'],
        '#limit_validation_errors' => [],
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
        '#type' => 'html_tag',
        '#tag' => 'h2',
        '#value' => $this->t('3. Détails du cours'),
      ],
      'all_levels' => [
        '#markup' => '<p class="reservation-first-course__note">' . $this->t('Les cours particuliers sont ouverts à tous les niveaux.') . '</p>',
      ],
      'mode_cours' => $this->buildDetailElement('mode_cours', $details),
      'plateforme_visio' => $this->buildDetailElement('plateforme_visio', $details),
      'adresse_domicile' => $this->buildDetailElement('adresse_domicile', $details),
      'code_postal_domicile' => $this->buildDetailElement('code_postal_domicile', $details),
      'telephone' => $this->buildDetailElement('telephone', $details),
      'instrument' => $this->buildDetailElement('instrument', $details),
      'didgeridoo_pret' => $this->buildDetailElement('didgeridoo_pret', $details),
      'notes_supplementaires' => $this->buildDetailElement('notes_supplementaires', $details),
    ];

    $form['actions'] = [
      '#type' => 'actions',
      '#attributes' => ['class' => ['reservation-first-course__actions']],
      'next' => [
        '#type' => 'submit',
        '#value' => $this->t('Continuer vers le paiement'),
        '#button_type' => 'primary',
        '#weight' => -10,
        '#attributes' => ['class' => ['reservation-first-course__action--next']],
        '#validate' => ['::validateDetailsStep'],
        '#submit' => ['::submitDetailsStep'],
      ],
      'previous' => [
        '#type' => 'submit',
        '#value' => $this->t('Retour au créneau'),
        '#weight' => 10,
        '#attributes' => ['class' => ['reservation-first-course__action--previous']],
        '#submit' => ['::submitBackToSlot'],
        '#limit_validation_errors' => [],
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
        $this->t('Retour aux détails'),
        '::submitBackToDetails'
      );
      return;
    }

    $form['summary'] = $this->buildSummary($stored);
    $form['step'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['reservation-first-course__panel']],
      'title' => [
        '#type' => 'html_tag',
        '#tag' => 'h2',
        '#value' => $this->t('4. Choix du paiement'),
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
        'pay_on_site' => [
          '#type' => 'html_tag',
          '#tag' => 'p',
          '#attributes' => ['class' => ['reservation-first-course__note']],
          '#value' => $this->t('Paiement sur place : votre créneau sera réservé et le règlement aura lieu le jour du cours.'),
        ],
        'online' => [
          '#type' => 'html_tag',
          '#tag' => 'p',
          '#attributes' => ['class' => ['reservation-first-course__note']],
          '#value' => $this->t('Paiement en ligne : vous continuez vers le parcours d’achat classique ; le créneau sélectionné n’est pas réservé.'),
        ],
      ],
    ];

    $form['actions'] = [
      '#type' => 'actions',
      '#attributes' => ['class' => ['reservation-first-course__actions']],
      'next' => [
        '#type' => 'submit',
        '#value' => $this->t('Confirmer la réservation'),
        '#button_type' => 'primary',
        '#weight' => -10,
        '#attributes' => ['class' => ['reservation-first-course__action--next']],
        '#validate' => ['::validatePaymentStep'],
        '#submit' => ['::submitPaymentStep'],
      ],
      'previous' => [
        '#type' => 'submit',
        '#value' => $this->t('Retour aux détails'),
        '#weight' => 10,
        '#attributes' => ['class' => ['reservation-first-course__action--previous']],
        '#submit' => ['::submitBackToDetails'],
        '#limit_validation_errors' => [],
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
    $form['step'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['reservation-first-course__panel', 'reservation-first-course__panel--success']],
      'title' => [
        '#type' => 'html_tag',
        '#tag' => 'h2',
        '#value' => $this->t('Réservation confirmée'),
      ],
      'status' => [
        '#type' => 'html_tag',
        '#tag' => 'p',
        '#attributes' => [
          'class' => ['reservation-portal__status'],
          'role' => 'status',
        ],
        '#value' => $this->t('À régler sur place'),
      ],
      'message' => [
        '#type' => 'html_tag',
        '#tag' => 'p',
        '#value' => $this->t('Votre créneau est réservé. Le règlement sera effectué sur place le jour du cours.'),
      ],
      'summary' => $this->buildSummary($stored, TRUE),
      'actions' => [
        '#type' => 'actions',
        '#attributes' => ['class' => ['reservation-portal__actions']],
        'restart' => [
          '#type' => 'submit',
          '#value' => $this->t('Réserver un autre cours'),
          '#submit' => ['::submitRestart'],
          '#limit_validation_errors' => [],
          '#button_type' => 'primary',
          '#attributes' => ['class' => ['btn', 'btn--cta']],
        ],
        'account' => [
          '#type' => 'link',
          '#title' => $this->t('Retour à mon compte'),
          '#url' => Url::fromRoute('user.page'),
          '#attributes' => ['class' => ['btn']],
        ],
      ],
    ];
  }

  private function buildProgress(string $step): array {
    $steps = [
      'course' => $this->t('Cours'),
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
      $aria_current = $index === $current_index ? ' aria-current="step"' : '';
      $items[] = '<li class="' . $class . '"' . $aria_current . '>' . Html::escape((string) $label) . '</li>';
    }

    return [
      '#markup' => '<ol class="reservation-first-course__steps">' . implode('', $items) . '</ol>',
    ];
  }

  private function buildSummary(array $stored, bool $include_details = FALSE): array {
    $rows = [];
    $course_label = trim((string) ($stored['course_display_label'] ?? ''));
    if ($course_label !== '') {
      $rows['course'] = $this->buildSummaryRow($this->t('Cours'), $course_label);
    }
    $slot_label = $this->getStoredSlotDisplayLabel($stored);
    if ($slot_label !== '') {
      $rows['slot'] = $this->buildSummaryRow($this->t('Créneau'), $slot_label);
    }

    if ($include_details) {
      $details = is_array($stored['details'] ?? NULL) ? $stored['details'] : [];
      $mode = $this->confirmationDetailLabel('mode_cours', (string) ($details['mode_cours'] ?? ''));
      if ($mode !== '') {
        $rows['mode'] = $this->buildSummaryRow($this->t('Mode'), $mode);
      }
      $instrument = $this->confirmationDetailLabel('instrument', (string) ($details['instrument'] ?? ''));
      if ($instrument !== '') {
        $rows['instrument'] = $this->buildSummaryRow($this->t('Instrument'), $instrument);
      }
    }

    return [
      '#type' => 'html_tag',
      '#tag' => 'dl',
      '#attributes' => ['class' => ['reservation-first-course__summary']],
      '#access' => (bool) $rows,
    ] + $rows;
  }

  private function buildSummaryRow($label, string $value): array {
    return [
      '#type' => 'container',
      'label' => [
        '#type' => 'html_tag',
        '#tag' => 'dt',
        '#value' => $label,
      ],
      'value' => [
        '#type' => 'html_tag',
        '#tag' => 'dd',
        '#value' => Html::escape($value),
      ],
    ];
  }

  private function confirmationDetailLabel(string $key, string $value): string {
    $labels = [
      'mode_cours' => [
        'studio' => $this->t('Studio'),
        'visio' => $this->t('Visio'),
        'domicile' => $this->t('À domicile'),
      ],
      'instrument' => [
        'didgeridoo' => $this->t('Didgeridoo'),
        'guimbarde' => $this->t('Guimbarde'),
      ],
    ];

    return isset($labels[$key][$value]) ? (string) $labels[$key][$value] : '';
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
        '#attributes' => ['class' => ['reservation-first-course__actions']],
        'previous' => [
          '#type' => 'submit',
          '#value' => $button_label,
          '#weight' => 10,
          '#attributes' => ['class' => ['reservation-first-course__action--previous']],
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

  private function getWebformElement(string $key, ?string &$failure_reason = NULL): array {
    $failure_reason = NULL;
    try {
      if (!$this->entityTypeManager->hasDefinition('webform')) {
        $failure_reason = 'The webform entity type definition is unavailable.';
        return [];
      }
      $webform = $this->entityTypeManager->getStorage('webform')->load('cours_particuliers_reservation');
      if (!$webform) {
        $failure_reason = 'The cours_particuliers_reservation Webform could not be loaded.';
        return [];
      }
      if (!method_exists($webform, 'getElementDecoded')) {
        $failure_reason = 'The Webform does not expose getElementDecoded().';
        return [];
      }

      $element = $webform->getElementDecoded($key);
      if (!is_array($element)) {
        $failure_reason = 'The Webform element is missing or is not an array.';
        return [];
      }

      return $element;
    }
    catch (\Throwable $e) {
      $failure_reason = get_class($e) . ': ' . $e->getMessage();
    }

    return [];
  }

  private function detailFieldFallbackTitle(string $key): string {
    $titles = [
      'mode_cours' => $this->t('Mode du cours'),
      'plateforme_visio' => $this->t('Plateforme de visio'),
      'adresse_domicile' => $this->t('Adresse complète'),
      'code_postal_domicile' => $this->t('Code postal'),
      'telephone' => $this->t('Téléphone'),
      'instrument' => $this->t('Instrument'),
      'didgeridoo_pret' => $this->t('Le professeur doit-il fournir un didgeridoo ?'),
      'notes_supplementaires' => $this->t('Notes supplémentaires'),
    ];

    return isset($titles[$key]) ? (string) $titles[$key] : (string) $this->t('Détail du cours');
  }

  private function detailFieldIsConditionallyRequired(string $key): bool {
    return in_array($key, ['plateforme_visio', 'adresse_domicile', 'code_postal_domicile', 'didgeridoo_pret'], TRUE);
  }

  private function normalizeDetailsValues(array $values): array {
    unset($values[self::LEGACY_LEVEL_FIELD]);
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
    $option_sources = [];
    $metadata_errors = [];
    foreach ($this->applicableOptionFields($details) as $key) {
      $source = $this->getWebformElement($key, $failure_reason);
      $options = $source['#options'] ?? NULL;
      if (!is_array($options) || $options === []) {
        $metadata_errors[$key] = $failure_reason ?: 'The #options property is missing or empty.';
        continue;
      }
      $option_sources[$key] = $options;
    }

    if ($metadata_errors) {
      $causes = [];
      foreach ($metadata_errors as $key => $reason) {
        $causes[] = $key . ': ' . $reason;
      }
      $this->logger('unisonges_structure')->error('Unable to validate reservation course details because Webform option metadata is unavailable: @causes', [
        '@causes' => implode('; ', $causes),
      ]);
      return [
        'course_details' => $this->t('Les options du formulaire sont temporairement indisponibles. Rechargez la page et réessayez.'),
      ];
    }

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
      if (isset($option_sources[$key]) && !array_key_exists($value, $option_sources[$key])) {
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

  private function applicableOptionFields(array $details): array {
    return array_values(array_intersect(
      self::OPTION_DETAIL_FIELDS,
      $this->detailFieldsToValidate($details)
    ));
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

  private function getCourseSelections(?bool &$catalog_loaded = NULL): array {
    $contexts = $this->loadPublishedCourseContexts(array_keys($this->getAllowedCourseSkuBundles()), $catalog_loaded);
    if (!$catalog_loaded) {
      return [];
    }

    $production_skus = array_column(self::PRODUCTION_COURSE_SELECTIONS, 'sku');
    $has_production_selection = (bool) array_intersect($production_skus, array_keys($contexts));
    if ($has_production_selection) {
      $definitions = self::PRODUCTION_COURSE_SELECTIONS;
    }
    else {
      if (!$this->localFixtureProductsAreAllowed()) {
        return [];
      }
      $fixture_skus = array_values(array_unique(array_column(self::LOCAL_FIXTURE_COURSE_SELECTIONS, 'sku')));
      if (array_diff($fixture_skus, array_keys($contexts))) {
        return [];
      }
      $definitions = self::LOCAL_FIXTURE_COURSE_SELECTIONS;
    }

    $selections = [];
    foreach ($definitions as $key => $definition) {
      $sku = $definition['sku'];
      if (!isset($contexts[$sku])) {
        continue;
      }
      [$discipline, $tariff] = $this->parseCourseSelectionKey($key);
      $selections[$key] = [
        'discipline' => $discipline,
        'tariff' => $tariff,
        'course' => 'sku:' . $sku,
        'course_label' => method_exists($contexts[$sku]['product'], 'label') ? (string) $contexts[$sku]['product']->label() : '',
        'course_display_label' => $this->getCourseSelectionLabel($discipline, $tariff),
      ];
    }

    return $selections;
  }

  private function localFixtureProductsAreAllowed(): bool {
    return getenv('IS_DDEV_PROJECT') === 'true';
  }

  private function getAllowedCourseSkuBundles(): array {
    $bundles = [];
    foreach ([self::PRODUCTION_COURSE_SELECTIONS, self::LOCAL_FIXTURE_COURSE_SELECTIONS] as $definitions) {
      foreach ($definitions as $definition) {
        $bundles[$definition['sku']] = $definition['bundle'];
      }
    }
    return $bundles;
  }

  private function loadPublishedCourseContexts(array $skus, ?bool &$catalog_loaded = NULL): array {
    $catalog_loaded = FALSE;
    $contexts = [];
    try {
      if (!$this->entityTypeManager->hasDefinition('commerce_product_variation')) {
        return [];
      }

      $storage = $this->entityTypeManager->getStorage('commerce_product_variation');
      $ids = $storage->getQuery()
        ->accessCheck(TRUE)
        ->addTag('commerce_product_variation_access')
        ->condition('sku', $skus, 'IN')
        ->condition('status', 1)
        ->execute();
      $catalog_loaded = TRUE;
      $allowed_bundles = $this->getAllowedCourseSkuBundles();
      foreach ($storage->loadMultiple($ids) as $variation) {
        if (!method_exists($variation, 'getSku') || !method_exists($variation, 'getProduct')) {
          continue;
        }
        $sku = (string) $variation->getSku();
        $expected_bundle = $allowed_bundles[$sku] ?? '';
        $product = $variation->getProduct();
        if ($expected_bundle === '' || !$product || !method_exists($product, 'bundle') || !method_exists($product, 'label')) {
          continue;
        }
        if ((string) $product->bundle() !== $expected_bundle
          || (method_exists($variation, 'bundle') && (string) $variation->bundle() !== $expected_bundle)
          || (method_exists($product, 'isPublished') && !$product->isPublished())
          || (method_exists($variation, 'isPublished') && !$variation->isPublished())
          || (method_exists($product, 'access') && !$product->access('view'))) {
          continue;
        }
        $contexts[$sku] = [
          'product' => $product,
          'variation' => $variation,
          'bundle' => $expected_bundle,
        ];
      }
    }
    catch (\Throwable $e) {
      $catalog_loaded = FALSE;
      return [];
    }

    return $contexts;
  }

  private function getDisciplineOptions(array $selections): array {
    if (!$selections) {
      return [];
    }

    $labels = [
      'essai' => $this->t('Cours d’essai'),
      'didgeridoo' => $this->t('Didgeridoo'),
      'guimbarde' => $this->t('Guimbarde'),
      'meditation-improvisation' => $this->t('Méditation / improvisation'),
    ];
    $options = [];
    foreach (array_keys($labels) as $discipline) {
      if ($discipline === 'essai') {
        if (isset($selections['essai'])) {
          $options[$discipline] = $labels[$discipline];
        }
        continue;
      }
      if (isset($selections[$discipline . ':normal'], $selections[$discipline . ':etudiant'])) {
        $options[$discipline] = $labels[$discipline];
      }
    }

    return $options;
  }

  private function getTariffOptions(): array {
    return [
      'normal' => $this->t('Tarif normal — 25 EUR'),
      'etudiant' => $this->t('Tarif étudiant — 15 EUR'),
    ];
  }

  private function getCourseSelection(string $discipline, string $tariff, array $selections): array {
    $key = $this->getCourseSelectionKey($discipline, $tariff);
    return is_array($selections[$key] ?? NULL) ? $selections[$key] : [];
  }

  private function getCourseSelectionKey(string $discipline, string $tariff): string {
    return $discipline === 'essai' ? 'essai' : $discipline . ':' . $tariff;
  }

  private function parseCourseSelectionKey(string $key): array {
    if ($key === 'essai') {
      return ['essai', ''];
    }
    $parts = explode(':', $key, 2);
    return [$parts[0] ?? '', $parts[1] ?? ''];
  }

  private function getCourseSelectionLabel(string $discipline, string $tariff): string {
    if ($discipline === 'essai') {
      return (string) $this->t('Cours d’essai — 10 EUR');
    }

    $disciplines = [
      'didgeridoo' => $this->t('Didgeridoo'),
      'guimbarde' => $this->t('Guimbarde'),
      'meditation-improvisation' => $this->t('Méditation / improvisation'),
    ];
    $tariffs = $this->getTariffOptions();
    if (!isset($disciplines[$discipline], $tariffs[$tariff])) {
      return '';
    }

    return (string) $this->t('@discipline — @tariff', [
      '@discipline' => $disciplines[$discipline],
      '@tariff' => $tariffs[$tariff],
    ]);
  }

  private function getDeepLinkDiscipline(): string {
    if (!$this->getRequest()->isMethod('GET')) {
      return '';
    }

    $query = $this->getRequest()->query->all();
    $discipline = $query['discipline'] ?? NULL;
    return is_string($discipline) && in_array($discipline, self::DISCIPLINE_QUERY_VALUES, TRUE)
      ? $discipline
      : '';
  }

  private function applyDisciplineDeepLink(array $stored, FormStateInterface $form_state, string $discipline): array {
    if ($discipline === '') {
      return $stored;
    }

    unset($stored['_course_selection_invalidated'], $stored['tariff'], $stored['course'], $stored['course_label'], $stored['course_display_label']);
    $this->invalidateCourseDependentSelection($stored);
    $stored['discipline'] = $discipline;
    $stored['step'] = 'course';
    $this->setStoredSelection($stored);
    $form_state->set('step', 'course');

    return $stored;
  }

  private function migrateLegacyStoredCourseSelection(array $stored): array {
    $course = (string) ($stored['course'] ?? '');
    if (preg_match('/^product:(\d+)$/', $course, $matches) !== 1) {
      return $stored;
    }

    try {
      $product = $this->entityTypeManager->getStorage('commerce_product')->load((int) $matches[1]);
      if (!$product || !method_exists($product, 'getVariations') || !method_exists($product, 'label')) {
        return $stored;
      }

      $selections_by_course = [];
      foreach ($this->getCourseSelections($catalog_loaded) as $selection) {
        $selections_by_course[$selection['course']][] = $selection;
      }
      if (!$catalog_loaded) {
        return $stored;
      }

      $matches = [];
      foreach ($product->getVariations() as $variation) {
        if (!$variation || !method_exists($variation, 'getSku')) {
          continue;
        }
        $selection_course = 'sku:' . $variation->getSku();
        foreach ($selections_by_course[$selection_course] ?? [] as $selection) {
          $matches[$selection['discipline'] . ':' . $selection['tariff']] = $selection;
        }
      }
      if (count($matches) !== 1) {
        return $stored;
      }

      $selection = reset($matches);
      $stored['discipline'] = $selection['discipline'];
      if ($selection['tariff'] === '') {
        unset($stored['tariff']);
      }
      else {
        $stored['tariff'] = $selection['tariff'];
      }
      $stored['course'] = $selection['course'];
      $stored['course_label'] = (string) $product->label();
      $stored['course_display_label'] = $selection['course_display_label'];
      $this->setStoredSelection($stored);
    }
    catch (\Throwable $e) {
      // The normal availability guard will safely invalidate this selection.
    }

    return $stored;
  }

  private function ensureStoredCourseIsAvailable(array $stored, FormStateInterface $form_state): array {
    $course = (string) ($stored['course'] ?? '');
    if ($course === '') {
      return $stored;
    }

    $step = (string) ($form_state->get('step') ?: ($stored['step'] ?? 'course'));
    $discipline = (string) ($stored['discipline'] ?? '');
    $tariff = $discipline === 'essai' ? '' : (string) ($stored['tariff'] ?? '');
    $supported_selection = $this->storedCourseMatchesKnownSelection($discipline, $tariff, $course);
    if ($step === 'confirmed' && $supported_selection) {
      return $stored;
    }

    $selections = $this->getCourseSelections($catalog_loaded);
    $selection = $this->getCourseSelection($discipline, $tariff, $selections);
    if ($supported_selection && ($selection['course'] ?? '') === $course) {
      return $stored;
    }
    $is_legacy_selection = preg_match('/^product:\\d+$/', $course) === 1;
    if (($supported_selection || $is_legacy_selection) && !$catalog_loaded) {
      // Do not discard a valid selection because Commerce was temporarily
      // unavailable. A successful SKU query that omits it is authoritative;
      // legacy product IDs will be migrated after the catalog recovers.
      return $stored;
    }

    unset($stored['discipline'], $stored['tariff'], $stored['course'], $stored['course_label'], $stored['course_display_label']);
    $this->invalidateCourseDependentSelection($stored);
    $stored['step'] = 'course';
    $this->setStoredSelection($stored);
    $form_state->set('step', 'course');
    // This transient flag is returned only for the current build and is not
    // persisted by setStoredSelection() above.
    $stored['_course_selection_invalidated'] = TRUE;

    return $stored;
  }

  private function storedCourseMatchesKnownSelection(string $discipline, string $tariff, string $course): bool {
    $key = $this->getCourseSelectionKey($discipline, $tariff);
    foreach ([self::PRODUCTION_COURSE_SELECTIONS, self::LOCAL_FIXTURE_COURSE_SELECTIONS] as $definitions) {
      if (isset($definitions[$key]) && $course === 'sku:' . $definitions[$key]['sku']) {
        return TRUE;
      }
    }
    return FALSE;
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
      return '';
    }

    return (string) $this->t('@date à @time', [
      '@date' => $parsed['start']->format('d/m/Y'),
      '@time' => $parsed['start']->format('H:i'),
    ]);
  }

  private function getStoredSlotDisplayLabel(array $stored): string {
    $reservation_value = (string) ($stored['reservation_value'] ?? '');
    if ($reservation_value !== '') {
      $formatted = $this->formatReservationValue($reservation_value);
      if ($formatted !== '') {
        return $formatted;
      }
    }

    $stored_label = trim((string) ($stored['slot_label'] ?? ''));
    if (preg_match('/^(\d{2}\/\d{2}\/\d{4})(?:\s+à)?\s+(\d{2}:\d{2})$/u', $stored_label, $matches) === 1) {
      return (string) $this->t('@date à @time', [
        '@date' => $matches[1],
        '@time' => $matches[2],
      ]);
    }

    return '';
  }

  private function getOnlinePaymentUrl(array $stored): Url {
    try {
      $context = $this->getSelectedCourseCommerceContext((string) ($stored['course'] ?? ''));
      $product = $context['product'];
      if (method_exists($product, 'toUrl')) {
        return $product->toUrl('canonical', ['query' => ['reservation-first' => '1']]);
      }
    }
    catch (\Throwable $e) {
      // Fall back to the public course listing.
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
    if (strpos($course, 'sku:') !== 0) {
      throw new \RuntimeException('Selected course SKU is invalid.');
    }

    $active_courses = array_column($this->getCourseSelections($catalog_loaded), 'course');
    if (!$catalog_loaded || !in_array($course, $active_courses, TRUE)) {
      throw new \RuntimeException('Selected course SKU is unavailable.');
    }

    $sku = substr($course, strlen('sku:'));
    $contexts = $this->loadPublishedCourseContexts([$sku], $context_loaded);
    if (!$context_loaded || !isset($contexts[$sku])) {
      throw new \RuntimeException('Selected course product could not be resolved by SKU.');
    }

    $context = $contexts[$sku];
    $context['store_id'] = $this->getProductStoreId($context['product']);
    return $context;
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

    $course_label = trim((string) ($stored['course_label'] ?? ''));
    if ($course_label === '') {
      $context = $this->getSelectedCourseCommerceContext((string) ($stored['course'] ?? ''));
      $course_label = method_exists($context['product'], 'label') ? trim((string) $context['product']->label()) : '';
    }
    if ($course_label === '') {
      throw new \RuntimeException('Stored course label is unavailable.');
    }
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
      $temp_store = $this->tempStoreFactory->get('unisonges_structure');
      $stored = $temp_store->get(self::TEMPSTORE_KEY);
    }
    catch (\Throwable $e) {
      return [];
    }

    if (!is_array($stored)) {
      return [];
    }

    $had_legacy_level = array_key_exists(self::LEGACY_LEVEL_FIELD, $stored);
    unset($stored[self::LEGACY_LEVEL_FIELD]);
    if (is_array($stored['details'] ?? NULL) && array_key_exists(self::LEGACY_LEVEL_FIELD, $stored['details'])) {
      unset($stored['details'][self::LEGACY_LEVEL_FIELD]);
      $had_legacy_level = TRUE;
    }

    if ($had_legacy_level) {
      try {
        $temp_store->set(self::TEMPSTORE_KEY, $stored);
      }
      catch (\Throwable $e) {
        $this->logger('unisonges_structure')->error('Unable to remove the legacy course level from reservation tunnel tempstore: @message', [
          '@message' => $e->getMessage(),
        ]);
      }
    }

    return $stored;
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
      ['discipline', 'tariff', 'course', 'reservation', 'payment_choice', self::LEGACY_LEVEL_FIELD, 'op', '_triggering_element_name', '_triggering_element_value'],
      self::DETAIL_FIELDS
    );

    $user_input = $form_state->getUserInput();
    foreach ($keys as $key) {
      $form_state->unsetValue($key);
      unset($user_input[$key]);
    }
    $form_state->setUserInput($user_input);

    $storage = $form_state->getStorage();
    unset($storage['course_selection'], $storage['reservation_value'], $storage['course_details']);
    $form_state->setStorage($storage);
    $form_state->set('step', $step);
    $form_state->setRebuild(TRUE);
  }

  private function discardLegacyLevelFormState(FormStateInterface $form_state): void {
    $form_state->unsetValue(self::LEGACY_LEVEL_FIELD);

    $user_input = $form_state->getUserInput();
    unset($user_input[self::LEGACY_LEVEL_FIELD]);
    $form_state->setUserInput($user_input);

    $storage = $form_state->getStorage();
    $storage_changed = array_key_exists(self::LEGACY_LEVEL_FIELD, $storage);
    unset($storage[self::LEGACY_LEVEL_FIELD]);
    if (is_array($storage['course_details'] ?? NULL)) {
      $storage_changed = $storage_changed || array_key_exists(self::LEGACY_LEVEL_FIELD, $storage['course_details']);
      unset($storage['course_details'][self::LEGACY_LEVEL_FIELD]);
    }
    if ($storage_changed) {
      $form_state->setStorage($storage);
    }
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
