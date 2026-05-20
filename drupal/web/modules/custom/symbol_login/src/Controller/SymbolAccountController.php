<?php

declare(strict_types=1);

namespace Drupal\symbol_login\Controller;

use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\HttpFoundation\RedirectResponse;

final class SymbolAccountController extends ControllerBase {

  public function legacyRedirect(): RedirectResponse {
    return $this->redirect('symbol_login.account');
  }

}
