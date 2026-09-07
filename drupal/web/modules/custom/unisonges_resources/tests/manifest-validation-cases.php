<?php

declare(strict_types=1);

/**
 * Data-driven adversarial matrix for the Resources manifest.
 */

function resources_test_resource(
  string $id = 'resource-one',
  array $replace = [],
  array $omit = [],
): array {
  $resource = array_replace([
    'id' => $id,
    'title' => 'Ressource fictive',
    'url' => 'https://example.invalid/' . $id,
    'description' => 'Description factuelle réservée aux tests statiques.',
    'theme' => 'Thème A',
    'type' => 'Guide',
    'language' => 'fr',
    'published' => TRUE,
  ], $replace);
  foreach ($omit as $key) {
    unset($resource[$key]);
  }
  return $resource;
}

function resources_test_manifest(array $resources, bool $approved = TRUE): array {
  return [
    'schema_version' => 1,
    'catalogue_approved' => $approved,
    'resources' => $resources,
  ];
}

$four = [
  resources_test_resource('four-b-two', ['title' => 'Bêta', 'theme' => 'Thème B']),
  resources_test_resource('four-a-two', ['title' => 'Zulu', 'theme' => 'Thème A']),
  resources_test_resource('four-a-one', ['title' => 'Alpha', 'theme' => 'Thème A']),
  resources_test_resource('four-b-one', ['title' => 'Alpha', 'theme' => 'Thème B']),
];
$twenty = [];
for ($index = 1; $index <= 20; $index++) {
  $twenty[] = resources_test_resource(sprintf('resource-%02d', $index));
}

$cases = [
  [
    'id' => 'empty',
    'input' => resources_test_manifest([], FALSE),
    'valid' => TRUE,
    'errors' => [],
    'published' => 0,
    'themes' => [],
  ],
  [
    'id' => 'one-resource',
    'input' => resources_test_manifest([resources_test_resource('resource-one', [
      'audience' => 'Tout public',
      'editorial_note' => 'Note interne non rendue',
      'last_verified' => '2026-09-01',
      'order' => 10,
    ])]),
    'valid' => TRUE,
    'errors' => [],
    'published' => 1,
    'themes' => ['Thème A'],
  ],
  [
    'id' => 'four-resources-several-themes',
    'input' => resources_test_manifest($four),
    'valid' => TRUE,
    'errors' => [],
    'published' => 4,
    'themes' => ['Thème A', 'Thème B'],
    'order' => ['four-a-one', 'four-a-two', 'four-b-one', 'four-b-two'],
  ],
  [
    'id' => 'twenty-resources',
    'input' => resources_test_manifest($twenty),
    'valid' => TRUE,
    'errors' => [],
    'published' => 20,
  ],
  [
    'id' => 'twenty-one-requires-model-b',
    'input' => resources_test_manifest([...$twenty, resources_test_resource('resource-21')]),
    'valid' => FALSE,
    'errors' => ['model_b_required'],
  ],
  [
    'id' => 'published-and-unpublished',
    'input' => resources_test_manifest([
      resources_test_resource('published'),
      resources_test_resource('unpublished', ['published' => FALSE, 'theme' => 'Thème masqué'], ['url']),
    ]),
    'valid' => TRUE,
    'errors' => [],
    'published' => 1,
    'themes' => ['Thème A'],
  ],
  [
    'id' => 'unicode-title-theme',
    'input' => resources_test_manifest([
      resources_test_resource('unicode', [
        'title' => 'Écoute & création',
        'theme' => 'Créativité partagée',
        'url' => 'https://example.invalid/café',
      ]),
    ]),
    'valid' => TRUE,
    'errors' => [],
    'themes' => ['Créativité partagée'],
  ],
  [
    'id' => 'implicit-https-port',
    'input' => resources_test_manifest([resources_test_resource('implicit', ['url' => 'https://example.invalid/path'])]),
    'valid' => TRUE,
    'errors' => [],
  ],
  [
    'id' => 'explicit-443-port',
    'input' => resources_test_manifest([resources_test_resource('port-443', ['url' => 'https://example.invalid:443/path'])]),
    'valid' => TRUE,
    'errors' => [],
  ],
  [
    'id' => 'useful-query-and-fragment',
    'input' => resources_test_manifest([resources_test_resource('useful-query', ['url' => 'https://example.invalid/search?q=music#section'])]),
    'valid' => TRUE,
    'errors' => [],
  ],
  [
    'id' => 'long-url-display',
    'input' => resources_test_manifest([resources_test_resource('long-url', [
      'url' => 'https://example.invalid/' . str_repeat('a', 1800),
    ])]),
    'valid' => TRUE,
    'errors' => [],
  ],
  [
    'id' => 'mobile-unbroken-token',
    'input' => resources_test_manifest([resources_test_resource('mobile-token', [
      'title' => str_repeat('M', 120),
      'url' => 'https://example.invalid/' . str_repeat('x', 180),
    ])]),
    'valid' => TRUE,
    'errors' => [],
  ],
  [
    'id' => 'explicit-order',
    'input' => resources_test_manifest([
      resources_test_resource('late-a', ['theme' => 'Thème A', 'order' => 30]),
      resources_test_resource('first-b', ['theme' => 'Thème B', 'order' => 10]),
      resources_test_resource('middle-a', ['theme' => 'Thème A', 'order' => 20]),
    ]),
    'valid' => TRUE,
    'errors' => [],
    'order' => ['first-b', 'middle-a', 'late-a'],
    'themes' => ['Thème B', 'Thème A'],
  ],
  [
    'id' => 'deterministic-order-a',
    'input' => resources_test_manifest($four),
    'valid' => TRUE,
    'errors' => [],
    'fingerprint_group' => 'fallback-order',
  ],
  [
    'id' => 'deterministic-order-b',
    'input' => resources_test_manifest(array_reverse($four)),
    'valid' => TRUE,
    'errors' => [],
    'fingerprint_group' => 'fallback-order',
  ],
  [
    'id' => 'duplicate-id',
    'input' => resources_test_manifest([
      resources_test_resource('same-id'),
      resources_test_resource('same-id', ['url' => 'https://example.invalid/other']),
    ]),
    'valid' => FALSE,
    'errors' => ['duplicate_id'],
  ],
  [
    'id' => 'duplicate-normalized-url',
    'input' => resources_test_manifest([
      resources_test_resource('duplicate-a', ['url' => 'https://EXAMPLE.invalid']),
      resources_test_resource('duplicate-b', ['url' => 'https://example.invalid:443/']),
    ]),
    'valid' => FALSE,
    'errors' => ['duplicate_url'],
  ],
  [
    'id' => 'duplicate-unicode-url',
    'input' => resources_test_manifest([
      resources_test_resource('unicode-a', ['url' => 'https://example.invalid/caf%C3%A9']),
      resources_test_resource('unicode-b', ['url' => 'https://example.invalid/café']),
    ]),
    'valid' => FALSE,
    'errors' => ['duplicate_url'],
  ],
  [
    'id' => 'encoded-slash-remains-distinct',
    'input' => resources_test_manifest([
      resources_test_resource('encoded-slash', ['url' => 'https://example.invalid/caf%C3%A9%2Fx']),
      resources_test_resource('literal-slash', ['url' => 'https://example.invalid/café/x']),
    ]),
    'valid' => TRUE,
    'errors' => [],
  ],
  [
    'id' => 'encoded-query-separator-remains-distinct',
    'input' => resources_test_manifest([
      resources_test_resource('encoded-ampersand', ['url' => 'https://example.invalid/path?q=%C3%A9%26x']),
      resources_test_resource('literal-ampersand', ['url' => 'https://example.invalid/path?q=é&x']),
    ]),
    'valid' => TRUE,
    'errors' => [],
  ],
  [
    'id' => 'encoded-fragment-marker-remains-distinct',
    'input' => resources_test_manifest([
      resources_test_resource('encoded-hash', ['url' => 'https://example.invalid/path#%C3%A9%23x']),
      resources_test_resource('literal-hash', ['url' => 'https://example.invalid/path#é#x']),
    ]),
    'valid' => TRUE,
    'errors' => [],
  ],
  [
    'id' => 'duplicate-dot-segment-url',
    'input' => resources_test_manifest([
      resources_test_resource('dot-a', ['url' => 'https://example.invalid/a/']),
      resources_test_resource('dot-b', ['url' => 'https://example.invalid/a/.']),
    ]),
    'valid' => FALSE,
    'errors' => ['duplicate_url'],
  ],
  [
    'id' => 'duplicate-order',
    'input' => resources_test_manifest([
      resources_test_resource('order-a', ['order' => 1]),
      resources_test_resource('order-b', ['order' => 1]),
    ]),
    'valid' => FALSE,
    'errors' => ['duplicate_order'],
  ],
  [
    'id' => 'partial-order',
    'input' => resources_test_manifest([
      resources_test_resource('ordered', ['order' => 1]),
      resources_test_resource('unordered'),
    ]),
    'valid' => FALSE,
    'errors' => ['partial_order'],
  ],
  [
    'id' => 'missing-published-url',
    'input' => resources_test_manifest([resources_test_resource('missing-url', [], ['url'])]),
    'valid' => FALSE,
    'errors' => ['required'],
  ],
  [
    'id' => 'invalid-date',
    'input' => resources_test_manifest([resources_test_resource('bad-date', ['last_verified' => '2026-02-30'])]),
    'valid' => FALSE,
    'errors' => ['date'],
  ],
  [
    'id' => 'invalid-language',
    'input' => resources_test_manifest([resources_test_resource('bad-language', ['language' => 'fr_fr'])]),
    'valid' => FALSE,
    'errors' => ['language'],
  ],
  [
    'id' => 'schema-version-must-be-integer',
    'input' => ['schema_version' => '1', 'catalogue_approved' => TRUE, 'resources' => []],
    'valid' => FALSE,
    'errors' => ['schema_version'],
  ],
  [
    'id' => 'approval-must-be-boolean',
    'input' => ['schema_version' => 1, 'catalogue_approved' => 'true', 'resources' => []],
    'valid' => FALSE,
    'errors' => ['boolean'],
  ],
  [
    'id' => 'published-must-be-boolean',
    'input' => resources_test_manifest([resources_test_resource('published-string', ['published' => 'true'])]),
    'valid' => FALSE,
    'errors' => ['boolean'],
  ],
  [
    'id' => 'unknown-resource-field',
    'input' => resources_test_manifest([resources_test_resource('unknown', ['unexpected' => 'value'])]),
    'valid' => FALSE,
    'errors' => ['unknown_key'],
  ],
  [
    'id' => 'unknown-top-level-field',
    'input' => resources_test_manifest([resources_test_resource()]) + ['unexpected' => TRUE],
    'valid' => FALSE,
    'errors' => ['unknown_key'],
  ],
  [
    'id' => 'empty-title',
    'input' => resources_test_manifest([resources_test_resource('empty-title', ['title' => ''])]),
    'valid' => FALSE,
    'errors' => ['empty'],
  ],
  [
    'id' => 'long-title',
    'input' => resources_test_manifest([resources_test_resource('long-title', ['title' => str_repeat('T', 161)])]),
    'valid' => FALSE,
    'errors' => ['length'],
  ],
  [
    'id' => 'long-description',
    'input' => resources_test_manifest([resources_test_resource('long-description', ['description' => str_repeat('D', 501)])]),
    'valid' => FALSE,
    'errors' => ['length'],
  ],
  [
    'id' => 'non-nfc-title',
    'input' => resources_test_manifest([resources_test_resource('non-nfc', ['title' => "Cafe\u{0301}"])]),
    'valid' => FALSE,
    'errors' => ['not_nfc'],
  ],
  [
    'id' => 'control-character',
    'input' => resources_test_manifest([resources_test_resource('control', ['description' => "bad\x01text"])]),
    'valid' => FALSE,
    'errors' => ['control_character'],
  ],
  [
    'id' => 'unsafe-html',
    'input' => resources_test_manifest([resources_test_resource('html', ['title' => '<em>Unsafe</em>'])]),
    'valid' => FALSE,
    'errors' => ['html'],
  ],
  [
    'id' => 'url-too-long',
    'input' => resources_test_manifest([resources_test_resource('url-long', [
      'url' => 'https://example.invalid/' . str_repeat('a', 2049 - strlen('https://example.invalid/')),
    ])]),
    'valid' => FALSE,
    'errors' => ['url_length'],
  ],
  [
    'id' => 'userinfo-url',
    'input' => resources_test_manifest([resources_test_resource('userinfo', ['url' => 'https://user:pass@example.invalid/path'])]),
    'valid' => FALSE,
    'errors' => ['userinfo'],
  ],
  [
    'id' => 'localhost',
    'input' => resources_test_manifest([resources_test_resource('localhost', ['url' => 'https://localhost/path'])]),
    'valid' => FALSE,
    'errors' => ['reserved_host'],
  ],
  [
    'id' => 'private-ip-literal',
    'input' => resources_test_manifest([resources_test_resource('private-ip', ['url' => 'https://192.168.1.10/path'])]),
    'valid' => FALSE,
    'errors' => ['ip_literal'],
  ],
  [
    'id' => 'loopback-ipv6-literal',
    'input' => resources_test_manifest([resources_test_resource('loopback-ipv6', ['url' => 'https://[::1]/path'])]),
    'valid' => FALSE,
    'errors' => ['ip_literal'],
  ],
  [
    'id' => 'malformed-idn',
    'input' => resources_test_manifest([resources_test_resource('bad-idn', ['url' => "https://\u{0301}a.invalid/path"])]),
    'valid' => FALSE,
    'errors' => ['idna'],
  ],
  [
    'id' => 'tracked-utm',
    'input' => resources_test_manifest([resources_test_resource('utm', ['url' => 'https://example.invalid/path?utm_source=test'])]),
    'valid' => FALSE,
    'errors' => ['tracking_query'],
  ],
  [
    'id' => 'benign-utm-prefix',
    'input' => resources_test_manifest([resources_test_resource('utmology', ['url' => 'https://example.invalid/path?utmology=study'])]),
    'valid' => TRUE,
    'errors' => [],
  ],
  [
    'id' => 'tracked-camel-case',
    'input' => resources_test_manifest([resources_test_resource('utm-camel', ['url' => 'https://example.invalid/path?utmSource=test'])]),
    'valid' => FALSE,
    'errors' => ['tracking_query'],
  ],
  [
    'id' => 'tracked-double-encoded',
    'input' => resources_test_manifest([resources_test_resource('utm-encoded', ['url' => 'https://example.invalid/path?%2575tm_source=test'])]),
    'valid' => FALSE,
    'errors' => ['tracking_query'],
  ],
  [
    'id' => 'tracked-nested-query',
    'input' => resources_test_manifest([resources_test_resource('utm-nested', ['url' => 'https://example.invalid/path?next=https%3A%2F%2Fexample.invalid%2F%3Futm_source%3Dx'])]),
    'valid' => FALSE,
    'errors' => ['tracking_query'],
  ],
  [
    'id' => 'tracked-nested-affiliate-after-punctuation',
    'input' => resources_test_manifest([resources_test_resource('affiliate-nested', ['url' => 'https://example.invalid/path?next=route%2Caffiliate_id%3Dx'])]),
    'valid' => FALSE,
    'errors' => ['tracking_query'],
  ],
  [
    'id' => 'credential-nested-after-punctuation',
    'input' => resources_test_manifest([resources_test_resource('token-nested', ['url' => 'https://example.invalid/path?next=route%21token%3Dx'])]),
    'valid' => FALSE,
    'errors' => ['credential_query'],
  ],
  [
    'id' => 'credential-structured-compact-key',
    'input' => resources_test_manifest([resources_test_resource('structured-access-token', ['url' => 'https://example.invalid/path?route_accesstoken=x'])]),
    'valid' => FALSE,
    'errors' => ['credential_query'],
  ],
  [
    'id' => 'benign-compact-credential-suffix',
    'input' => resources_test_manifest([resources_test_resource('benign-access-token', ['url' => 'https://example.invalid/path?q=notaccesstoken%3Dx'])]),
    'valid' => TRUE,
    'errors' => [],
  ],
  [
    'id' => 'credential-nested-long-key',
    'input' => resources_test_manifest([resources_test_resource('long-credential-key', [
      'url' => 'https://example.invalid/path?next=' . rawurlencode('access_token_' . str_repeat('a', 116) . '=x'),
    ])]),
    'valid' => FALSE,
    'errors' => ['credential_query'],
  ],
  [
    'id' => 'tracking-nested-long-key',
    'input' => resources_test_manifest([resources_test_resource('long-tracking-key', [
      'url' => 'https://example.invalid/path?next=' . rawurlencode('affiliate_id_' . str_repeat('a', 116) . '=x'),
    ])]),
    'valid' => FALSE,
    'errors' => ['tracking_query'],
  ],
  [
    'id' => 'benign-sensitive-suffix',
    'input' => resources_test_manifest([resources_test_resource('benign-suffix', ['url' => 'https://example.invalid/path?q=notaffiliate_id%3Dx'])]),
    'valid' => TRUE,
    'errors' => [],
  ],
  [
    'id' => 'tracked-structured-bracket-key',
    'input' => resources_test_manifest([resources_test_resource('bracket-affiliate', ['url' => 'https://example.invalid/path?route[affiliate_id]=x'])]),
    'valid' => FALSE,
    'errors' => ['tracking_query'],
  ],
  [
    'id' => 'tracked-structured-dot-key',
    'input' => resources_test_manifest([resources_test_resource('dot-partner', ['url' => 'https://example.invalid/path?route.partner_id=x'])]),
    'valid' => FALSE,
    'errors' => ['tracking_query'],
  ],
  [
    'id' => 'tracked-compact-key',
    'input' => resources_test_manifest([resources_test_resource('compact-affiliate', ['url' => 'https://example.invalid/path?affiliateid=x'])]),
    'valid' => FALSE,
    'errors' => ['tracking_query'],
  ],
  [
    'id' => 'tracked-structured-compact-key',
    'input' => resources_test_manifest([resources_test_resource('structured-compact', ['url' => 'https://example.invalid/path?route_affiliateid=x'])]),
    'valid' => FALSE,
    'errors' => ['tracking_query'],
  ],
  [
    'id' => 'benign-compact-sensitive-suffix',
    'input' => resources_test_manifest([resources_test_resource('benign-compact', ['url' => 'https://example.invalid/path?q=notaffiliateid%3Dx'])]),
    'valid' => TRUE,
    'errors' => [],
  ],
  [
    'id' => 'tracked-nested-structured-key',
    'input' => resources_test_manifest([resources_test_resource('nested-structured', ['url' => 'https://example.invalid/path?next=route.affiliate_id%3Dx'])]),
    'valid' => FALSE,
    'errors' => ['tracking_query'],
  ],
  [
    'id' => 'credential-query',
    'input' => resources_test_manifest([resources_test_resource('credential', ['url' => 'https://example.invalid/path?access_token=secret'])]),
    'valid' => FALSE,
    'errors' => ['credential_query'],
  ],
  [
    'id' => 'credential-fragment',
    'input' => resources_test_manifest([resources_test_resource('credential-fragment', ['url' => 'https://example.invalid/path#token=value'])]),
    'valid' => FALSE,
    'errors' => ['credential_query'],
  ],
  [
    'id' => 'encoded-space',
    'input' => resources_test_manifest([resources_test_resource('space', ['url' => 'https://example.invalid/a%20b'])]),
    'valid' => FALSE,
    'errors' => ['encoded_character'],
  ],
  [
    'id' => 'encoded-control',
    'input' => resources_test_manifest([resources_test_resource('encoded-control', ['url' => 'https://example.invalid/a%00b'])]),
    'valid' => FALSE,
    'errors' => ['encoded_character'],
  ],
  [
    'id' => 'unicode-url-space',
    'input' => resources_test_manifest([resources_test_resource('unicode-space', ['url' => "https://example.invalid/a\u{00A0}b"])]),
    'valid' => FALSE,
    'errors' => ['url_character'],
  ],
  [
    'id' => 'trailing-dot-host',
    'input' => resources_test_manifest([resources_test_resource('trailing-dot', ['url' => 'https://example.invalid./path'])]),
    'valid' => FALSE,
    'errors' => ['malformed_host'],
  ],
  [
    'id' => 'encoded-host',
    'input' => resources_test_manifest([resources_test_resource('encoded-host', ['url' => 'https://ex%61mple.invalid/path'])]),
    'valid' => FALSE,
    'errors' => ['malformed_host'],
  ],
  [
    'id' => 'production-rejects-fixture-domain',
    'input' => resources_test_manifest([resources_test_resource()]),
    'production_validator' => TRUE,
    'valid' => FALSE,
    'errors' => ['reserved_host'],
  ],
  [
    'id' => 'resources-must-be-list',
    'yaml' => "schema_version: 1\ncatalogue_approved: false\nresources: {}\n",
    'valid' => FALSE,
    'errors' => ['list'],
  ],
  [
    'id' => 'root-must-be-mapping',
    'yaml' => "[]\n",
    'valid' => FALSE,
    'errors' => ['top_level_mapping'],
  ],
  [
    'id' => 'resource-must-be-mapping',
    'yaml' => "schema_version: 1\ncatalogue_approved: true\nresources:\n  - []\n",
    'valid' => FALSE,
    'errors' => ['resource_mapping'],
  ],
  [
    'id' => 'yaml-alias',
    'yaml' => "schema_version: 1\ncatalogue_approved: true\nresources: [*resource]\n",
    'valid' => FALSE,
    'errors' => ['yaml_indirection'],
  ],
  [
    'id' => 'yaml-anchor',
    'yaml' => "schema_version: 1\ncatalogue_approved: true\nresources:\n  - &resource {id: one}\n",
    'valid' => FALSE,
    'errors' => ['yaml_indirection'],
  ],
  [
    'id' => 'yaml-merge-key',
    'yaml' => "schema_version: 1\ncatalogue_approved: true\nresources:\n  - <<: {id: one}\n",
    'valid' => FALSE,
    'errors' => ['yaml_indirection'],
  ],
  [
    'id' => 'yaml-duplicate-key',
    'yaml' => "schema_version: 1\ncatalogue_approved: true\ncatalogue_approved: false\nresources: []\n",
    'valid' => FALSE,
    'errors' => ['yaml_duplicate_key'],
  ],
];

foreach (['http', 'javascript', 'data', 'file', 'ftp'] as $scheme) {
  $url = match ($scheme) {
    'http' => 'http://example.invalid/path',
    'javascript' => 'javascript:alert(1)',
    'data' => 'data:text/plain,test',
    'file' => 'file:///tmp/test',
    default => 'ftp://example.invalid/path',
  };
  $cases[] = [
    'id' => 'unsafe-scheme-' . $scheme,
    'input' => resources_test_manifest([resources_test_resource('scheme-' . $scheme, ['url' => $url])]),
    'valid' => FALSE,
    'errors' => ['https_only'],
  ];
}

foreach ([
  'protocol-relative' => '//example.invalid/path',
  'fragment-only' => '#section',
] as $id => $url) {
  $cases[] = [
    'id' => $id,
    'input' => resources_test_manifest([resources_test_resource($id, ['url' => $url])]),
    'valid' => FALSE,
    'errors' => [$id === 'protocol-relative' ? 'protocol_relative' : 'fragment_only'],
  ];
}

foreach ([
  'non-default-port' => 'https://example.invalid:444/path',
  'empty-port' => 'https://example.invalid:/path',
  'signed-port' => 'https://example.invalid:+443/path',
  'decimal-port' => 'https://example.invalid:443.0/path',
  'suffix-port' => 'https://example.invalid:443x/path',
] as $id => $url) {
  $cases[] = [
    'id' => $id,
    'input' => resources_test_manifest([resources_test_resource($id, ['url' => $url])]),
    'valid' => FALSE,
    'errors' => ['port'],
  ];
}

foreach (['fbclid', 'gclid', 'affiliate_id', 'partner_id', 'mc_cid'] as $key) {
  $cases[] = [
    'id' => 'tracking-key-' . str_replace('_', '-', $key),
    'input' => resources_test_manifest([resources_test_resource('tracking-' . str_replace('_', '-', $key), [
      'url' => 'https://example.invalid/path?' . $key . '=value',
    ])]),
    'valid' => FALSE,
    'errors' => ['tracking_query'],
  ];
}

foreach (['api_key', 'authorization', 'password', 'session_id', 'signature'] as $key) {
  $cases[] = [
    'id' => 'credential-key-' . str_replace('_', '-', $key),
    'input' => resources_test_manifest([resources_test_resource('credential-' . str_replace('_', '-', $key), [
      'url' => 'https://example.invalid/path?' . $key . '=value',
    ])]),
    'valid' => FALSE,
    'errors' => ['credential_query'],
  ];
}

return $cases;
