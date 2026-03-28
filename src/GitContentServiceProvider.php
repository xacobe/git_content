<?php

namespace Drupal\git_content;

use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\DependencyInjection\ServiceProviderBase;

/**
 * Conditionally removes importer/exporter services for absent modules.
 *
 * Media, block_content, and menu_link_content are optional. If their Drupal
 * modules are not installed, the corresponding services are removed from the
 * container so they are never instantiated or iterated by the orchestrators.
 */
class GitContentServiceProvider extends ServiceProviderBase {

  public function alter(ContainerBuilder $container): void {
    $optional = [
      'media' => [
        'git_content.media_exporter',
        'git_content.media_importer',
      ],
      'block_content' => [
        'git_content.block_content_exporter',
        'git_content.block_content_importer',
      ],
      'menu_link_content' => [
        'git_content.menu_link_exporter',
        'git_content.menu_link_importer',
      ],
    ];

    $modules = array_keys($container->getParameter('container.modules'));

    foreach ($optional as $module => $services) {
      if (!in_array($module, $modules, TRUE)) {
        foreach ($services as $service_id) {
          if ($container->hasDefinition($service_id)) {
            $container->removeDefinition($service_id);
          }
        }
      }
    }
  }

}
