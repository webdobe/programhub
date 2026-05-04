<?php

namespace Drupal\gdes\EventSubscriber;

use Drupal\profile\Event\ProfileEvents;
use Drupal\profile\Event\ProfileLabelEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Sets the profile label to the first and last name from field_name.
 */
class ProfileLabelSubscriber implements EventSubscriberInterface {

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [
      ProfileEvents::PROFILE_LABEL => 'onProfileLabel',
    ];
  }

  /**
   * Sets the profile label to the given + family name.
   */
  public function onProfileLabel(ProfileLabelEvent $event): void {
    $profile = $event->getProfile();
    if (!$profile->hasField('field_name') || $profile->get('field_name')->isEmpty()) {
      return;
    }
    $name = $profile->get('field_name')->first()->getValue();
    $given = $name['given'] ?? '';
    $family = $name['family'] ?? '';
    $full_name = trim("$given $family");
    if ($full_name !== '') {
      $event->setLabel($full_name);
    }
  }

}
