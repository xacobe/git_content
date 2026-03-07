<?php

namespace Drupal\git_content\Commands;

use Drush\Commands\DrushCommands;

class GitContentCommands extends DrushCommands {

  /**
   * Comando de prueba.
   *
   * @command git-content:test
   * @aliases gct
   */
  public function test() {
    $this->output()->writeln("Git Content Drush commands funcionando!");
  }

}