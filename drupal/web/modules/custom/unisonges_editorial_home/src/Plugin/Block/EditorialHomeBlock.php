<?php

declare(strict_types=1);

namespace Drupal\unisonges_editorial_home\Plugin\Block;

use Drupal\Core\Block\Attribute\Block;
use Drupal\Core\Block\BlockBase;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\unisonges_editorial_home\EditorialHomeBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides the Uni-Songes editorial homepage block.
 */
#[Block(
  id: 'unisonges_editorial_home',
  admin_label: new TranslatableMarkup('Accueil éditorial'),
  category: new TranslatableMarkup('Uni-Songes'),
)]
final class EditorialHomeBlock extends BlockBase implements ContainerFactoryPluginInterface {

  /**
   * Constructs an EditorialHomeBlock object.
   *
   * @param array<string, mixed> $configuration
   *   The plugin configuration.
   * @param string $plugin_id
   *   The plugin ID.
   * @param mixed $plugin_definition
   *   The plugin definition.
   * @param \Drupal\unisonges_editorial_home\EditorialHomeBuilder $builder
   *   The editorial homepage render builder.
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    protected EditorialHomeBuilder $builder,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(
    ContainerInterface $container,
    array $configuration,
    $plugin_id,
    $plugin_definition,
  ): static {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('unisonges_editorial_home.builder'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function build(): array {
    return $this->builder->build();
  }

  /**
   * {@inheritdoc}
   */
  public function calculateDependencies(): array {
    $dependencies = parent::calculateDependencies();
    $dependencies['config'][] = 'views.view.blog_posts';
    $dependencies['config'] = array_values(array_unique(
      $dependencies['config'],
    ));
    return $dependencies;
  }

}
