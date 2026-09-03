<?php

declare(strict_types=1);

namespace Drupal\unisonges_editorial_home\Plugin\Block;

use Drupal\Core\Block\Attribute\Block;
use Drupal\Core\Block\BlockBase;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\unisonges_editorial_home\EditorialHomeBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RequestStack;

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
   * @param \Symfony\Component\HttpFoundation\RequestStack $request_stack
   *   The current request stack.
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    protected EditorialHomeBuilder $builder,
    private readonly RequestStack $requestStack,
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
      $container->get('request_stack'),
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
  public function getCacheMaxAge(): int {
    // PHP/Symfony normalize duplicate plain query keys to the last value.
    // Bypass render caching for every filtered state so the builder can
    // validate the original query string on every request and reject repeats.
    return $this->requestStack->getCurrentRequest()?->query->has('theme')
      ? 0
      : parent::getCacheMaxAge();
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
