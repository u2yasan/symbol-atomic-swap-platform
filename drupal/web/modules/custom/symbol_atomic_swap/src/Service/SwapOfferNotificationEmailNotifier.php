<?php

declare(strict_types=1);

namespace Drupal\symbol_atomic_swap\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Mail\MailManagerInterface;

final class SwapOfferNotificationEmailNotifier {

  public function __construct(
    private readonly MailManagerInterface $mailManager,
    private readonly LanguageManagerInterface $languageManager,
    private readonly LoggerChannelFactoryInterface $loggerFactory,
    private readonly ConfigFactoryInterface $configFactory,
  ) {}

  /**
   * Sends a best-effort email for a user-facing offer notification.
   *
   * @param array<string, mixed> $notification
   */
  public function notify(array $notification): void {
    $to = trim((string) (getenv('SYMBOL_ATOMIC_SWAP_NOTIFICATION_EMAIL') ?: $this->configFactory->get('symbol_atomic_swap.settings')->get('notification_email')));
    if ($to === '') {
      return;
    }

    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
      $this->loggerFactory->get('symbol_atomic_swap')->warning('Swap notification email recipient is invalid.');
      return;
    }

    $message = $this->mailManager->mail(
      'symbol_atomic_swap',
      'offer_notification',
      $to,
      $this->languageManager->getDefaultLanguage()->getId(),
      [
        'notification' => [
          'offer_id' => (int) $notification['offer_id'],
          'type' => (string) $notification['type'],
          'severity' => (string) $notification['severity'],
          'message' => (string) $notification['message'],
          'created' => (int) $notification['created'],
        ],
      ],
      NULL,
      TRUE,
    );

    if (($message['result'] ?? TRUE) !== TRUE) {
      $this->loggerFactory->get('symbol_atomic_swap')->warning('Swap notification email delivery failed for offer @offer_id.', [
        '@offer_id' => (string) $notification['offer_id'],
      ]);
    }
  }

}
