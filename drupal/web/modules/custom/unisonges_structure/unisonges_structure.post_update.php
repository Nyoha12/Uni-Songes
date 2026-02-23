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
