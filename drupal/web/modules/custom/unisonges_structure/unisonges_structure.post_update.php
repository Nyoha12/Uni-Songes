<?php

use Drupal\menu_link_content\Entity\MenuLinkContent;
use Drupal\node\Entity\Node;

/**
 * Create core site structure pages and menu links for Uni-Songes.
 */
function unisonges_structure_post_update_create_pages_and_navigation(array &$sandbox): void {
  $pages = [
    'cours' => [
      'title' => 'Cours',
      'alias' => '/cours',
    ],
    'cours_debutant' => [
      'title' => 'Débutant',
      'alias' => '/cours/debutant',
    ],
    'cours_intermediaire' => [
      'title' => 'Intermédiaire',
      'alias' => '/cours/intermediaire',
    ],
    'stages' => [
      'title' => 'Stages',
      'alias' => '/stages',
    ],
    'stages_debutant' => [
      'title' => 'Débutant',
      'alias' => '/stages/debutant',
    ],
    'stages_intermediaire' => [
      'title' => 'Intermédiaire',
      'alias' => '/stages/intermediaire',
    ],
    'stages_masterclass' => [
      'title' => 'Masterclass',
      'alias' => '/stages/masterclass',
    ],
    'concerts' => [
      'title' => 'Concerts / dates',
      'alias' => '/concerts',
    ],
    'contact' => [
      'title' => 'Contact',
      'alias' => '/contact',
    ],
    'reserver' => [
      'title' => 'Réserver un cours',
      'alias' => '/reserver',
    ],
    'orchestre' => [
      'title' => 'Orchestre de didgeridoo',
      'alias' => '/orchestre-de-didgeridoo',
    ],
    'djam' => [
      'title' => 'D’jam',
      'alias' => '/djam',
    ],
  ];

  $nodes = [];
  foreach ($pages as $key => $page) {
    $nodes[$key] = _unisonges_structure_ensure_basic_page($page['title'], $page['alias']);
  }

  $menu_links = [];
  $menu_links['cours'] = _unisonges_structure_ensure_menu_link('Cours', $nodes['cours']->id());
  _unisonges_structure_ensure_menu_link('Débutant', $nodes['cours_debutant']->id(), $menu_links['cours']->getPluginId());
  _unisonges_structure_ensure_menu_link('Intermédiaire', $nodes['cours_intermediaire']->id(), $menu_links['cours']->getPluginId());

  $menu_links['stages'] = _unisonges_structure_ensure_menu_link('Stages', $nodes['stages']->id());
  _unisonges_structure_ensure_menu_link('Débutant', $nodes['stages_debutant']->id(), $menu_links['stages']->getPluginId());
  _unisonges_structure_ensure_menu_link('Intermédiaire', $nodes['stages_intermediaire']->id(), $menu_links['stages']->getPluginId());
  _unisonges_structure_ensure_menu_link('Masterclass', $nodes['stages_masterclass']->id(), $menu_links['stages']->getPluginId());

  _unisonges_structure_ensure_menu_link('Concerts/dates', $nodes['concerts']->id());
  _unisonges_structure_ensure_menu_link('Contact', $nodes['contact']->id());
  _unisonges_structure_ensure_menu_link('Réserver un cours', $nodes['reserver']->id());
}

/**
 * Ensure stage beginner/intermediate pages are distinct from course pages.
 */
function unisonges_structure_post_update_fix_stage_pages_distinct(array &$sandbox): void {
  $logger = \Drupal::logger('unisonges_structure');

  $stages_debutant_nid = _unisonges_structure_ensure_stage_page_distinct(
    '/cours/debutant',
    '/stages/debutant',
    'Débutant',
    $logger
  );
  $stages_intermediaire_nid = _unisonges_structure_ensure_stage_page_distinct(
    '/cours/intermediaire',
    '/stages/intermediaire',
    'Intermédiaire',
    $logger
  );

  _unisonges_structure_ensure_stages_menu_child_link('Débutant', $stages_debutant_nid, $logger);
  _unisonges_structure_ensure_stages_menu_child_link('Intermédiaire', $stages_intermediaire_nid, $logger);
}

/**
 * Ensure a published basic page exists and receives the requested alias when possible.
 */
function _unisonges_structure_ensure_basic_page(string $title, string $alias): Node {
  $node_storage = \Drupal::entityTypeManager()->getStorage('node');

  $node_ids = \Drupal::entityQuery('node')
    ->accessCheck(FALSE)
    ->condition('type', 'page')
    ->condition('title', $title)
    ->range(0, 1)
    ->execute();

  if ($node_ids) {
    /** @var \Drupal\node\Entity\Node $node */
    $node = $node_storage->load(reset($node_ids));
  }
  else {
    $node = _unisonges_structure_find_node_by_alias($alias);
  }

  if (!$node) {
    $node = Node::create([
      'type' => 'page',
      'title' => $title,
      'status' => Node::PUBLISHED,
    ]);
    $node->save();
  }
  elseif (!$node->isPublished()) {
    $node->setPublished(TRUE);
    $node->save();
  }

  _unisonges_structure_ensure_alias($node, $alias);

  return $node;
}

/**
 * Try to find a page node using its alias if alias support is available.
 */
function _unisonges_structure_find_node_by_alias(string $alias): ?Node {
  if (!\Drupal::moduleHandler()->moduleExists('path_alias')) {
    return NULL;
  }

  $alias_storage = \Drupal::entityTypeManager()->getStorage('path_alias');
  $aliases = $alias_storage->loadByProperties(['alias' => $alias]);
  foreach ($aliases as $path_alias) {
    $path = $path_alias->getPath();
    if (preg_match('/^\/node\/(\d+)$/', $path, $matches)) {
      $node = Node::load((int) $matches[1]);
      if ($node && $node->bundle() === 'page') {
        return $node;
      }
    }
  }

  return NULL;
}

/**
 * Ensure the requested alias exists for the node when alias entities are available.
 */
function _unisonges_structure_ensure_alias(Node $node, string $alias): void {
  if (!\Drupal::moduleHandler()->moduleExists('path_alias')) {
    return;
  }

  $path = '/node/' . $node->id();
  $alias_storage = \Drupal::entityTypeManager()->getStorage('path_alias');

  $matching_aliases = $alias_storage->loadByProperties([
    'path' => $path,
    'alias' => $alias,
  ]);
  if ($matching_aliases) {
    return;
  }

  $existing_alias = $alias_storage->loadByProperties(['alias' => $alias]);
  if ($existing_alias) {
    return;
  }

  $alias_storage->create([
    'path' => $path,
    'alias' => $alias,
  ])->save();
}

/**
 * Ensure a main menu link exists for a node.
 */
function _unisonges_structure_ensure_menu_link(string $title, int $node_id, string $parent = ''): MenuLinkContent {
  $menu_link_storage = \Drupal::entityTypeManager()->getStorage('menu_link_content');

  $properties = [
    'menu_name' => 'main',
    'title' => $title,
  ];

  if ($parent !== '') {
    $properties['parent'] = $parent;
  }

  $links = $menu_link_storage->loadByProperties($properties);
  foreach ($links as $link) {
    $link_uri = (string) ($link->get('link')->first()->getValue()['uri'] ?? '');
    if ($link_uri === 'entity:node/' . $node_id) {
      if (!(bool) $link->get('enabled')->value) {
        $link->set('enabled', TRUE);
        $link->save();
      }
      return $link;
    }
  }

  $link = MenuLinkContent::create([
    'title' => $title,
    'menu_name' => 'main',
    'link' => ['uri' => 'entity:node/' . $node_id],
    'enabled' => TRUE,
    'parent' => $parent,
    'expanded' => TRUE,
  ]);
  $link->save();

  return $link;
}

/**
 * Ensure a stages page alias points to a node distinct from the corresponding course alias.
 */
function _unisonges_structure_ensure_stage_page_distinct(string $course_alias, string $stage_alias, string $title, $logger): int {
  $course_path = _unisonges_structure_get_path_from_alias($course_alias);
  $existing_stage_alias = _unisonges_structure_get_alias_entity($stage_alias);
  $existing_stage_path = $existing_stage_alias ? $existing_stage_alias->getPath() : NULL;

  if ($existing_stage_path && $existing_stage_path !== $course_path && preg_match('/^\/node\/(\d+)$/', $existing_stage_path, $matches)) {
    $existing_node = Node::load((int) $matches[1]);
    if ($existing_node && $existing_node->bundle() === 'page') {
      if (!$existing_node->isPublished()) {
        $existing_node->setPublished(TRUE);
        $existing_node->save();
        $logger->notice('Published existing stage node @nid for @alias.', [
          '@nid' => $existing_node->id(),
          '@alias' => $stage_alias,
        ]);
      }
      $logger->notice('Stage alias @alias already points to distinct node @nid.', [
        '@alias' => $stage_alias,
        '@nid' => $existing_node->id(),
      ]);
      return (int) $existing_node->id();
    }
  }

  $node = Node::create([
    'type' => 'page',
    'title' => $title,
    'status' => Node::PUBLISHED,
  ]);
  $node->save();
  $node_path = '/node/' . $node->id();

  if ($existing_stage_alias) {
    if ($existing_stage_alias->getPath() !== $node_path) {
      $existing_stage_alias->set('path', $node_path);
      $existing_stage_alias->save();
      $logger->notice('Updated alias @alias to @path (new node created).', [
        '@alias' => $stage_alias,
        '@path' => $node_path,
      ]);
    }
    else {
      $logger->notice('Alias @alias already pointed to @path after node creation.', [
        '@alias' => $stage_alias,
        '@path' => $node_path,
      ]);
    }
  }
  else {
    _unisonges_structure_create_alias($stage_alias, $node_path);
    $logger->notice('Created alias @alias to @path.', [
      '@alias' => $stage_alias,
      '@path' => $node_path,
    ]);
  }

  $logger->notice('Created new distinct stage node @nid for @alias.', [
    '@nid' => $node->id(),
    '@alias' => $stage_alias,
  ]);

  return (int) $node->id();
}

/**
 * Return the source path for an alias if available.
 */
function _unisonges_structure_get_path_from_alias(string $alias): ?string {
  $alias_entity = _unisonges_structure_get_alias_entity($alias);
  return $alias_entity ? $alias_entity->getPath() : NULL;
}

/**
 * Return one alias entity matching the alias.
 */
function _unisonges_structure_get_alias_entity(string $alias) {
  if (!\Drupal::moduleHandler()->moduleExists('path_alias')) {
    return NULL;
  }

  $alias_storage = \Drupal::entityTypeManager()->getStorage('path_alias');
  $aliases = $alias_storage->loadByProperties(['alias' => $alias]);

  return $aliases ? reset($aliases) : NULL;
}

/**
 * Create an alias entity when path_alias is enabled.
 */
function _unisonges_structure_create_alias(string $alias, string $path): void {
  if (!\Drupal::moduleHandler()->moduleExists('path_alias')) {
    return;
  }

  \Drupal::entityTypeManager()->getStorage('path_alias')->create([
    'path' => $path,
    'alias' => $alias,
  ])->save();
}

/**
 * Ensure stages submenu links point to expected nodes.
 */
function _unisonges_structure_ensure_stages_menu_child_link(string $title, int $node_id, $logger): void {
  if (!\Drupal::moduleHandler()->moduleExists('menu_link_content')) {
    $logger->notice('menu_link_content module is not enabled; skip menu update for @title.', ['@title' => $title]);
    return;
  }

  $menu_link_storage = \Drupal::entityTypeManager()->getStorage('menu_link_content');
  $stages_parent = _unisonges_structure_get_stages_parent_menu_link();

  if (!$stages_parent) {
    $logger->notice('No parent "Stages" menu link found in main menu; skip child update for @title.', ['@title' => $title]);
    return;
  }

  $parent_plugin_id = $stages_parent->getPluginId();
  $expected_uri = 'entity:node/' . $node_id;
  $links = $menu_link_storage->loadByProperties([
    'menu_name' => 'main',
    'title' => $title,
    'parent' => $parent_plugin_id,
  ]);

  /** @var \Drupal\menu_link_content\Entity\MenuLinkContent|null $link */
  $link = $links ? reset($links) : NULL;
  if (!$link) {
    $link = MenuLinkContent::create([
      'title' => $title,
      'menu_name' => 'main',
      'link' => ['uri' => $expected_uri],
      'enabled' => TRUE,
      'parent' => $parent_plugin_id,
      'expanded' => TRUE,
    ]);
    $link->save();
    $logger->notice('Created menu link "@title" under Stages to @uri.', [
      '@title' => $title,
      '@uri' => $expected_uri,
    ]);
    return;
  }

  $current_uri = (string) ($link->get('link')->first()->getValue()['uri'] ?? '');
  $has_change = FALSE;

  if ($current_uri !== $expected_uri) {
    $link->set('link', ['uri' => $expected_uri]);
    $has_change = TRUE;
  }
  if (!(bool) $link->get('enabled')->value) {
    $link->set('enabled', TRUE);
    $has_change = TRUE;
  }

  if ($has_change) {
    $link->save();
    $logger->notice('Updated menu link "@title" under Stages to @uri.', [
      '@title' => $title,
      '@uri' => $expected_uri,
    ]);
  }
}

/**
 * Find the top-level main-menu link titled "Stages".
 */
function _unisonges_structure_get_stages_parent_menu_link(): ?MenuLinkContent {
  if (!\Drupal::moduleHandler()->moduleExists('menu_link_content')) {
    return NULL;
  }

  $menu_link_storage = \Drupal::entityTypeManager()->getStorage('menu_link_content');
  $links = $menu_link_storage->loadByProperties([
    'menu_name' => 'main',
    'title' => 'Stages',
    'parent' => '',
  ]);

  return $links ? reset($links) : NULL;
}
