<?php

declare(strict_types=1);

use Drupal\unisonges_member_dashboard\DashboardAccessPolicy;
use Drupal\unisonges_member_dashboard\DashboardValueMapper;

$module_root = dirname(__DIR__, 2);
require_once $module_root . '/src/DashboardAccessPolicy.php';
require_once $module_root . '/src/DashboardValueMapper.php';

$checks = 0;
$failures = [];
$assert = static function (bool $condition, string $message) use (&$checks, &$failures): void {
  $checks++;
  if (!$condition) {
    $failures[] = $message;
  }
};
$contains = static function (string $haystack, string $needle) use ($assert): void {
  $assert(str_contains($haystack, $needle), 'Missing required contract: ' . $needle);
};
$not_contains = static function (string $haystack, string $needle) use ($assert): void {
  $assert(!str_contains($haystack, $needle), 'Forbidden contract found: ' . $needle);
};

$policy = new DashboardAccessPolicy();
$assert($policy->allows('entity.user.canonical', 'html', FALSE, TRUE, 7, 7, 7, TRUE), 'Owner canonical profile must pass.');
$assert(!$policy->allows('entity.user.canonical', 'html', FALSE, FALSE, 0, 0, 0, TRUE), 'Anonymous profile must fail.');
$assert(!$policy->allows('entity.user.canonical', 'html', FALSE, TRUE, 7, 8, 8, TRUE), 'Another member profile must fail.');
$assert(!$policy->allows('entity.user.canonical', 'html', FALSE, TRUE, 1, 8, 8, TRUE), 'Administrator viewing another account must fail even with entity access.');
$assert(!$policy->allows('entity.user.edit_form', 'html', FALSE, TRUE, 1, 8, 8, TRUE), 'Administrator editing another account must fail.');
$assert(!$policy->allows('entity.user.edit_form', 'html', FALSE, TRUE, 7, 7, 7, TRUE), 'Edit route must fail.');
$assert(!$policy->allows('entity.user.collection', 'html', FALSE, TRUE, 7, 7, 7, TRUE), 'User listing must fail.');
$assert(!$policy->allows('view.user_search.page_1', 'html', FALSE, TRUE, 7, 7, 7, TRUE), 'User search result must fail.');
$assert(!$policy->allows('jsonapi.user--user.individual', 'api_json', FALSE, TRUE, 7, 7, 7, TRUE), 'Serialization route must fail.');
$assert(!$policy->allows('entity.user.canonical', 'json', FALSE, TRUE, 7, 7, 7, TRUE), 'Canonical non-HTML response must fail.');
$assert(!$policy->allows('entity.user.canonical', 'html', TRUE, TRUE, 7, 7, 7, TRUE), 'Drupal Ajax wrapper serialization must fail.');
$assert(!$policy->allows('entity.user.canonical', 'html', FALSE, TRUE, 7, 7, 8, TRUE), 'A different rendered user must fail.');
$assert(!$policy->allows('entity.user.canonical', 'html', FALSE, TRUE, 7, 7, 7, FALSE), 'Denied entity access must fail.');

$active = DashboardValueMapper::parseReservation('2026-09-15 14:30|1');
$inactive = DashboardValueMapper::parseReservation('2026-09-15 14:30|0');
$ambiguous_flag = DashboardValueMapper::parseReservation('2026-09-15 14:30|2');
$assert($active !== NULL && $active['active'] === TRUE, 'Positive reservation flag must be active.');
$assert($inactive !== NULL && $inactive['active'] === FALSE, 'Zero reservation flag must be inactive.');
$assert($ambiguous_flag !== NULL && $ambiguous_flag['active'] === FALSE, 'Any non-schema reservation flag must be inactive.');
$assert(DashboardValueMapper::reservationState($active) === 'registered', 'Active reservation maps to registered.');
$assert(DashboardValueMapper::reservationState($inactive) === 'inactive', 'Zero reservation maps to inactive.');
$assert(DashboardValueMapper::reservationState(NULL) === 'inactive', 'Ambiguous reservation maps to inactive.');
$assert(DashboardValueMapper::parseReservation('2026-02-30 14:30|1') === NULL, 'Impossible reservation date must fail.');
$assert(DashboardValueMapper::parseReservation('submission-secret') === NULL, 'Raw malformed reservation must fail.');
$assert($active !== NULL && !array_key_exists('raw', $active), 'Parsed reservations must not return raw values.');

$assert(DashboardValueMapper::orderState('completed', TRUE, FALSE, FALSE) === 'paid', 'Paid completed order mapping failed.');
$assert(DashboardValueMapper::orderState('completed', FALSE, TRUE, TRUE) === 'pay_on_site', 'Verified manual order mapping failed.');
$assert(DashboardValueMapper::orderState('completed', FALSE, TRUE, FALSE) === 'in_progress', 'Unverified manual order must remain in progress.');
$assert(DashboardValueMapper::orderState('canceled', FALSE, FALSE, FALSE) === 'cancelled', 'Canceled order mapping failed.');
$assert(DashboardValueMapper::orderState('failed', FALSE, FALSE, FALSE) === 'in_progress', 'Unknown payment state must remain in progress.');
$assert(DashboardValueMapper::positiveInteger('3') === 3, 'Positive integer validation failed.');
$assert(DashboardValueMapper::positiveInteger('0') === NULL, 'Zero must not be a usable aggregate.');
$assert(DashboardValueMapper::positiveInteger('3.5') === NULL, 'Fractional aggregate must fail.');
$boundary_timestamp = (new DateTimeImmutable('2026-09-02T00:30:00+02:00'))->getTimestamp();
$assert(DashboardValueMapper::usableExpiry('2026-09-02', $boundary_timestamp, 'Europe/Paris') !== NULL, 'Current expiry date must remain usable.');
$assert(DashboardValueMapper::usableExpiry('2026-09-01', $boundary_timestamp, 'Europe/Paris') === NULL, 'Previous Paris date must expire.');
$assert(DashboardValueMapper::usableExpiry('2026-09-01', $boundary_timestamp, 'America/New_York') !== NULL, 'Current New York date must remain usable at the same instant.');
$assert(DashboardValueMapper::usableExpiry('2026-02-30', $boundary_timestamp, 'Europe/Paris') === NULL, 'Impossible expiry date must fail.');
$assert(DashboardValueMapper::usableExpiry('2026-09-02', $boundary_timestamp, 'Invalid/Timezone') === NULL, 'Invalid expiry timezone must fail.');
$assert(DashboardValueMapper::allowlistedString('visio', DashboardValueMapper::RESERVATION_MODES) === 'visio', 'Known mode must pass.');
$assert(DashboardValueMapper::allowlistedString('raw_element_key', DashboardValueMapper::RESERVATION_MODES) === NULL, 'Unknown mode must fail closed.');
$assert(DashboardValueMapper::courseCreditCapacity('cours_deb_inter', '2.0') === 2, 'Single-course quantity capacity failed.');
$assert(DashboardValueMapper::courseCreditCapacity('pack_4_deb_inter', '2') === 8, 'Pack capacity failed.');
$assert(DashboardValueMapper::courseCreditCapacity('cours_essai', '9') === 1, 'Trial capacity must remain one.');
$assert(DashboardValueMapper::courseCreditCapacity('unknown', '1') === NULL, 'Unknown product capacity must fail.');
$assert(DashboardValueMapper::courseCreditCapacity('pack_4_deb_inter', 'invalid') === NULL, 'Malformed quantity must fail.');
$assert(DashboardValueMapper::isUsablePayOnSiteShape('pending_payment', 1, NULL, NULL, NULL, NULL, 1, 1), 'Exact pending right shape must pass.');
$assert(!DashboardValueMapper::isUsablePayOnSiteShape('consumed', 1, NULL, NULL, NULL, NULL, 1, 1), 'Consumed right must fail.');
$assert(!DashboardValueMapper::isUsablePayOnSiteShape('pending_payment', 2, NULL, NULL, NULL, NULL, 1, 1), 'Inflated remaining units must fail.');
$assert(!DashboardValueMapper::isUsablePayOnSiteShape('pending_payment', 1, 0, NULL, NULL, NULL, 1, 1), 'Non-null submission reference must fail.');
$assert(!DashboardValueMapper::isUsablePayOnSiteShape('pending_payment', 1, NULL, 123, NULL, NULL, 1, 1), 'Consumed timestamp must fail.');
$assert(!DashboardValueMapper::isUsablePayOnSiteShape('pending_payment', 1, NULL, NULL, 123, NULL, 1, 1), 'Paid timestamp must fail.');
$assert(!DashboardValueMapper::isUsablePayOnSiteShape('pending_payment', 1, NULL, NULL, NULL, 123, 1, 1), 'Cancelled timestamp must fail.');
$assert(!DashboardValueMapper::isUsablePayOnSiteShape('pending_payment', 1, NULL, NULL, NULL, NULL, 2, 1), 'Out-of-bounds credit index must fail.');

$attachment = file_get_contents($module_root . '/src/MemberDashboardAttachment.php');
$builder = file_get_contents($module_root . '/src/MemberDashboardBuilder.php');
$module = file_get_contents($module_root . '/unisonges_member_dashboard.module');
$template = file_get_contents($module_root . '/templates/unisonges-member-dashboard.html.twig');
$css = file_get_contents($module_root . '/css/member-dashboard.css');
$libraries = file_get_contents($module_root . '/unisonges_member_dashboard.libraries.yml');
$services = file_get_contents($module_root . '/unisonges_member_dashboard.services.yml');
$info = file_get_contents($module_root . '/unisonges_member_dashboard.info.yml');
$production = implode("\n", [$attachment, $builder, $module, $template, $css, $libraries, $services, $info]);

$contains($attachment, "\$view_mode !== 'full'");
$contains($attachment, 'CacheableMetadata::createFromRenderArray($build)');
$contains($attachment, '->merge($decision_metadata)');
$contains($attachment, "'unisonges_member_dashboard.builder:build'");
$contains($attachment, "'#create_placeholder' => TRUE");
$contains($builder, 'implements TrustedCallbackInterface');
$contains($builder, "return ['build'];");
$contains($builder, "->condition('webform_id', \$webform_id)");
$contains($builder, "->condition('uid', \$uid)");
$contains($builder, "->condition('in_draft', 0)");
$contains($builder, "->sort('created', 'DESC')");
$contains($builder, "->sort('sid', 'DESC')");
$contains($builder, "->range(0, \$limit)");
$contains($builder, 'getRawData()');
$contains($builder, "self::RESERVATION_WEBFORM");
$contains($builder, "self::PROPOSAL_WEBFORM");
$contains($builder, "(int) \$submission->getOwnerId() !== \$uid");
$contains($builder, "\$submission->isDraft()");
$contains($builder, "\$webform->id() !== \$webform_id");
$contains($builder, "\$uid !== (int) \$this->currentUser->id()");
$contains($builder, "\$limit > self::CANDIDATE_LIMIT");
$assert(substr_count($builder, '->accessCheck(FALSE)') === 2, 'Only the narrow Webform and comment owner queries may bypass generic query access.');
$assert(substr_count($builder, 'getRawData()') === 2, 'Only the two reviewed Webforms may expose raw data to the allowlisting layer.');
$webform_data_keys = [];
preg_match_all("/\\\$data\\['([^']+)'\\]/", $builder, $webform_data_matches);
foreach ($webform_data_matches[1] as $key) {
  $webform_data_keys[$key] = $key;
}
sort($webform_data_keys, SORT_STRING);
$assert(
  $webform_data_keys === [
    'instrument',
    'mode_cours',
    'plateforme_visio',
    'proposal_type',
    'reservation',
    'title',
  ],
  'Webform raw-data reads must remain on the exact reviewed display allowlist.',
);
$submission_loader_start = strpos($builder, 'private function loadOwnedSubmissions(');
$submission_loader_end = strpos($builder, 'private function validUsableExpiry(', $submission_loader_start ?: 0);
$assert($submission_loader_start !== FALSE && $submission_loader_end !== FALSE, 'Owned-submission loader span must remain identifiable.');
$submission_loader = $submission_loader_start === FALSE || $submission_loader_end === FALSE
  ? ''
  : substr($builder, $submission_loader_start, $submission_loader_end - $submission_loader_start);
foreach ([
  '->accessCheck(FALSE)',
  "->condition('webform_id', \$webform_id)",
  "->condition('uid', \$uid)",
  "->condition('in_draft', 0)",
  "->sort('created', 'DESC')",
  "->sort('sid', 'DESC')",
  "->range(0, \$limit)",
  "(int) \$submission->getOwnerId() !== \$uid",
  "\$submission->isDraft()",
  "\$webform->id() !== \$webform_id",
  "\$uid !== (int) \$this->currentUser->id()",
] as $loader_contract) {
  $contains($submission_loader, $loader_contract);
}
$assert(substr_count($submission_loader, '->accessCheck(FALSE)') === 1, 'The owned-submission loader must contain exactly one explicit query-access bypass.');

$drupal_root = dirname($module_root, 4);
$webform_exports = [
  'cours_particuliers_reservation' => file_get_contents($drupal_root . '/config/sync/webform.webform.cours_particuliers_reservation.yml'),
  'forum_blog_proposal' => file_get_contents($drupal_root . '/config/sync/webform.webform.forum_blog_proposal.yml'),
];
foreach ($webform_exports as $webform_id => $export) {
  $assert(is_string($export), 'Reviewed Webform export must exist: ' . $webform_id);
  if (!is_string($export)) {
    continue;
  }
  $contains($export, "id: $webform_id");
  $contains($export, "  draft: none");
  foreach (['view_any', 'view_own'] as $access_operation) {
    $assert(
      preg_match(
        "/  $access_operation:\\n    roles: \\{  \\}\\n    users: \\{  \\}\\n    permissions: \\{  \\}/D",
        $export,
      ) === 1,
      "$webform_id must keep $access_operation empty.",
    );
  }
}
$reservation_export = is_string($webform_exports['cours_particuliers_reservation'])
  ? $webform_exports['cours_particuliers_reservation']
  : '';
foreach ([
  '  reservation:',
  '  mode_cours:',
  '      visio: Visio',
  '      studio:',
  '      domicile:',
  '  plateforme_visio:',
  '      zoom: Zoom',
  '      google_meet:',
  '      skype: Skype',
  '      whatsapp: WhatsApp',
  '      autre: Autre',
  '  instrument:',
  '      guimbarde: Guimbarde',
  '      didgeridoo: Didgeridoo',
] as $reservation_schema_contract) {
  $contains($reservation_export, $reservation_schema_contract);
}
$proposal_export = is_string($webform_exports['forum_blog_proposal'])
  ? $webform_exports['forum_blog_proposal']
  : '';
foreach ([
  '  proposal_type:',
  '      idea: Idée',
  '      discussion_topic:',
  '      article_theme:',
  '  title:',
] as $proposal_schema_contract) {
  $contains($proposal_export, $proposal_schema_contract);
}

$helper = file_get_contents($drupal_root . '/scripts/manage-member-dashboard-module.php');
$assert(is_string($helper), 'Targeted lifecycle helper must exist.');
$helper = is_string($helper) ? $helper : '';
foreach ([
  "const TARGET = 'unisonges_member_dashboard';",
  "\$out = ['apply' => FALSE",
  "\$installer->install([TARGET], FALSE)",
  "\$installer->uninstall([TARGET], FALSE)",
  "->validateUninstall([TARGET])",
  "'Config installer is in syncing/import state.'",
  "'Unknown partial target state",
  "'PLAN ENABLE ONLY '",
  "'PLAN DISABLE/UNINSTALL ONLY '",
  'CLI PHP 8.3+ is required by the locked Drupal project.',
] as $helper_contract) {
  $contains($helper, $helper_contract);
}
foreach ([
  'ConfigImporter',
  'importAll(',
  "getStorage('user')",
  'User::load(',
  '->insert(',
  '->update(',
  '->delete(',
] as $helper_forbidden) {
  $not_contains($helper, $helper_forbidden);
}

$contains($builder, "->condition('status', 'pending_payment')");
$contains($builder, "->condition('remaining_to_pay_credits', 0, '>')");
$contains($builder, 'private function validatePayOnSiteRow(');
$contains($builder, '  ): ?array {');
$contains($builder, "\$status !== 'pending_payment'");
$contains($builder, "(int) \$source_item->getOrderId() !== \$order_id");
$contains($builder, "\$source_item->access('view', \$this->currentUser, TRUE)");
$contains($builder, "\$submission_reference !== NULL");
$contains($builder, 'DashboardValueMapper::isUsablePayOnSiteShape(');
$contains($builder, "'consumed',");
$contains($builder, "'paid',");
$contains($builder, "'cancelled',");
$contains($builder, 'date_default_timezone_get()');
$contains($builder, "\$total->isPositive()");
$contains($builder, '(int) $order->getCustomerId() !== $uid');
$contains($builder, "\$order->access('view', \$this->currentUser, TRUE)");
$contains($builder, "'entity.commerce_order.user_view'");
$contains($builder, "->condition('status', CommentInterface::PUBLISHED)");
$contains($builder, "->condition('field_name', 'comment')");
$contains($builder, "\$comment->access('view', \$this->currentUser, TRUE)");
$contains($builder, "\$parent->access('view', \$this->currentUser, TRUE)");
$contains($builder, "\$parent_comment->access(");
$contains($builder, 'private function fieldsViewable(');
$contains($builder, '->get($field_name)->access(');
$contains($builder, "\$this->fieldsViewable(\$account, ['field_seances_restantes']");
$contains($builder, "\$this->fieldsViewable(\$comment, ['created', 'comment_body']");
$contains($builder, "\$this->fieldsViewable(\$parent, ['title']");
$contains($builder, "->setCacheMaxAge(0)");
$contains($builder, "'user.permissions'");
$contains($builder, "'request_format'");
$contains($builder, "'url.query_args:_wrapper_format'");
$contains($builder, "'languages:language_interface'");
$contains($builder, "'timezone'");

$assert(substr_count(strtolower($template), '<h1') === 0, 'Dashboard template must add no H1.');
$assert(substr_count(strtolower($template), '<h2') === 5, 'Dashboard must provide five data-section H2 headings.');
$not_contains(strtolower($template), '<main');
$contains($template, 'class="visually-hidden"');
$contains($template, "{{ 'Voir la commande'|t }}");
$contains($template, "{{ 'Ouvrir le contenu'|t }}");
$contains($template, "{{ contribution.parent_title }} · {{ contribution.date }}");

$ids = [];
preg_match_all('/\bid="([^"]+)"/', $template, $id_matches);
foreach ($id_matches[1] as $id) {
  $assert(!isset($ids[$id]), 'Dashboard fragment IDs must be unique: ' . $id);
  $ids[$id] = TRUE;
}
$assert(count($ids) === 10, 'Dashboard must expose exactly five section and five heading IDs.');
preg_match_all('/href="#([^"]+)"/', $template, $target_matches);
$assert(count($target_matches[1]) === 5, 'Dashboard navigation must contain exactly five fragment targets.');
foreach ($target_matches[1] as $target) {
  $assert(isset($ids[$target]), 'Dashboard fragment target must resolve: ' . $target);
}
preg_match_all('/aria-labelledby="([^"]+)"/', $template, $label_matches);
$assert(count($label_matches[1]) === 5, 'Every dashboard data section must be labelled.');
foreach ($label_matches[1] as $label_id) {
  $assert(isset($ids[$label_id]), 'Dashboard aria-labelledby must resolve: ' . $label_id);
}
$assert(substr_count($template, 'role="list"') === 5, 'All marker-free dashboard lists must retain explicit list semantics.');
$contains($template, '<aside class="unisonges-member-dashboard__account-context" aria-label=');
$contains($template, '<nav class="unisonges-member-dashboard__nav" aria-label=');

foreach ([
  ':focus-visible',
  'outline: 3px solid',
  'outline-offset: 3px',
  'min-height: 2.75rem',
  '@media (max-width: 32rem)',
  '@media (max-width: 20rem)',
  '@media (forced-colors: active)',
  'overflow-wrap: anywhere',
] as $css_contract) {
  $contains($css, $css_contract);
}
$contains($libraries, 'css/member-dashboard.css: {}');

$page_template = file_get_contents($drupal_root . '/web/themes/custom/unisonges_theme/templates/page.html.twig');
$theme_config = file_get_contents($drupal_root . '/config/sync/system.theme.yml');
$page_title_config = file_get_contents($drupal_root . '/config/sync/block.block.unisonges_theme_page_title.yml');
$assert(is_string($page_template), 'Active theme page template must exist.');
$assert(is_string($theme_config), 'Active theme configuration must exist.');
$assert(is_string($page_title_config), 'Active page-title block configuration must exist.');
$assert(is_string($page_template) && substr_count(strtolower($page_template), '<main') === 1, 'Active account page template must preserve one main landmark.');
$contains((string) $theme_config, 'default: unisonges_theme');
$contains((string) $page_title_config, 'status: true');
$contains((string) $page_title_config, 'theme: unisonges_theme');
$contains((string) $page_title_config, 'plugin: page_title_block');
$not_contains((string) $page_title_config, "\n      /user\n");

$active_message_blocks = 0;
foreach (glob($drupal_root . '/config/sync/block.block.*.yml') ?: [] as $block_file) {
  $block_config = file_get_contents($block_file);
  if (is_string($block_config)
    && str_contains($block_config, "\nstatus: true\n")
    && str_contains($block_config, "\ntheme: unisonges_theme\n")
    && str_contains($block_config, "\nplugin: system_messages_block\n")) {
    $active_message_blocks++;
  }
}
$assert($active_message_blocks === 1, 'Active public theme must retain exactly one system-messages block.');
foreach ([
  'Aucune réservation affichable pour le moment.',
  'Aucun droit utilisable actuellement.',
  'Aucune commande à afficher.',
  'Aucune proposition envoyée.',
  'Aucune contribution publiée.',
  'Enregistrée',
  'Non active',
  'À régler sur place',
  'Payée',
  'Annulée',
  'En cours',
  'Reçue',
] as $required_copy) {
  $contains($production, $required_copy);
}

foreach ([
  'view own webform submission',
  'view any webform submission',
  'administer users',
  'administer comments',
  'administer commerce_payment',
  'booking_gcal_sync',
  'google_event_id',
  'sync_status',
  'last_error',
  'ConfigImporter',
  'importAll',
  'COURS À PAYER',
  "\$data['adresse_domicile']",
  "\$data['code_postal_domicile']",
  "\$data['description']",
  "\$data['didgeridoo_pret']",
  "\$data['niveau_cours']",
  "\$data['notes_supplementaires']",
  "\$data['telephone']",
  "\$data['unisonges_pay_on_site_order_id']",
  "\$data['unisonges_payment_choice']",
] as $forbidden_value) {
  $not_contains($production, $forbidden_value);
}
$not_contains($builder, '->query(');
$not_contains($template, 'submission_id');
$not_contains($template, 'order_id');
$assert(!file_exists($module_root . '/unisonges_member_dashboard.routing.yml'), 'Module must add no public route.');
$assert(!file_exists($module_root . '/unisonges_member_dashboard.permissions.yml'), 'Module must add no broad permission.');
$assert(!file_exists($module_root . '/unisonges_member_dashboard.install'), 'Read-only module must own no schema.');

if ($failures !== []) {
  fwrite(STDERR, "Member dashboard contract FAILED:\n- " . implode("\n- ", $failures) . "\n");
  exit(1);
}

echo 'Member dashboard contract PASS (' . $checks . ' assertions).' . PHP_EOL;
