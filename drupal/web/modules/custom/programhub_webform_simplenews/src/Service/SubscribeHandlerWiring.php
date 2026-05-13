<?php

declare(strict_types=1);

namespace Drupal\programhub_webform_simplenews\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\webform\Plugin\WebformHandlerManagerInterface;
use Drupal\webform\WebformInterface;

/**
 * Wires the simplenews subscribe handler to per-group `{abbr}_subscribe`
 * webforms, pointing at `{abbr}_newsletter`.
 *
 * Convention: a webform whose id matches `^([a-z0-9_]+)_subscribe$`
 * gets the `programhub_simplenews_subscribe` handler attached, with
 * its email_element pinned to `email` and its newsletter_id set to
 * the matching `{abbr}_newsletter`.
 *
 * Idempotent: if any handler instance with that plugin id is already
 * present we leave the form alone (admins are free to retune the
 * email element / newsletter / handler id without us re-overwriting).
 */
final class SubscribeHandlerWiring {

  private const HANDLER_PLUGIN_ID = 'programhub_simplenews_subscribe';
  private const EMAIL_ELEMENT_KEY = 'email';

  public function __construct(
    private readonly EntityTypeManagerInterface $etm,
    private readonly WebformHandlerManagerInterface $handlerManager,
    private readonly LoggerChannelFactoryInterface $loggerFactory,
  ) {}

  /**
   * Wire the handler if this webform fits the subscribe pattern and
   * doesn't already have one. Returns TRUE when the form was modified.
   */
  public function wireForWebform(WebformInterface $webform): bool {
    $abbr = $this->extractAbbreviation($webform->id());
    if ($abbr === NULL) {
      return FALSE;
    }
    if ($this->hasHandler($webform)) {
      return FALSE;
    }

    $newsletterId = $abbr . '_newsletter';
    if (!$this->etm->getStorage('simplenews_newsletter')->load($newsletterId)) {
      $this->loggerFactory->get('programhub_webform_simplenews')->warning(
        'Skipping handler wiring for @id — newsletter @nid not found.',
        ['@id' => $webform->id(), '@nid' => $newsletterId],
      );
      return FALSE;
    }

    $handler = $this->handlerManager->createInstance(self::HANDLER_PLUGIN_ID, [
      'id' => self::HANDLER_PLUGIN_ID,
      'handler_id' => 'simplenews_subscribe',
      'label' => 'Simplenews subscribe',
      'status' => TRUE,
      'weight' => 0,
      'conditions' => [],
      'settings' => [
        'email_element' => self::EMAIL_ELEMENT_KEY,
        'newsletter_id' => $newsletterId,
      ],
    ]);
    $webform->addWebformHandler($handler);
    $webform->save();

    $this->loggerFactory->get('programhub_webform_simplenews')->notice(
      'Attached simplenews subscribe handler to @id → @nid.',
      ['@id' => $webform->id(), '@nid' => $newsletterId],
    );
    return TRUE;
  }

  /**
   * Walk every webform whose id ends with `_subscribe` and ensure the
   * handler is attached. Returns the count modified.
   */
  public function wireAll(): int {
    $storage = $this->etm->getStorage('webform');
    // Config entity queries don't support LIKE — load all and filter
    // by id suffix in PHP. Webform count per site is in the dozens.
    $count = 0;
    foreach ($storage->loadMultiple() as $webform) {
      if (!$webform instanceof WebformInterface) {
        continue;
      }
      if (!str_ends_with($webform->id(), '_subscribe')) {
        continue;
      }
      if ($this->wireForWebform($webform)) {
        $count++;
      }
    }
    return $count;
  }

  /**
   * Pull the abbreviation out of the webform id, or NULL if it
   * doesn't fit the `{abbr}_subscribe` shape.
   */
  private function extractAbbreviation(string $webformId): ?string {
    if (!preg_match('/^([a-z0-9_]+)_subscribe$/', $webformId, $m)) {
      return NULL;
    }
    return $m[1] === '' ? NULL : $m[1];
  }

  /**
   * Whether the webform already carries a handler with our plugin id.
   * Any pre-existing instance is treated as the source of truth — we
   * don't overwrite admin tweaks.
   */
  private function hasHandler(WebformInterface $webform): bool {
    foreach ($webform->getHandlers() as $handler) {
      if ($handler->getPluginId() === self::HANDLER_PLUGIN_ID) {
        return TRUE;
      }
    }
    return FALSE;
  }

}
