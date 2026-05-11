<?php

declare(strict_types=1);

namespace Drupal\programhub_governance\Notification;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Mail\MailManagerInterface;
use Drupal\group\Entity\GroupRelationship;
use Drupal\node\NodeInterface;

/**
 * Sends moderation-transition emails.
 *
 * Three transitions trigger notifications:
 *   - `* → pending_review`  → reviewers in the node's program (instructor,
 *     manager, administrator Group roles)
 *   - `pending_review → published` → the node's author
 *   - `pending_review → rejected`  → the node's author
 *
 * Other transitions (archive, restore, draft↔draft) are silent — they
 * don't need an inbox notification.
 */
final class ModerationNotifier {

  /**
   * Group role-name suffixes that grant reviewer authority. Expanded to
   * full IDs (program-instructor, program_design-instructor, …) at
   * lookup time so a notification fires for any program subtype.
   */
  private const REVIEWER_ROLE_SUFFIXES = ['instructor', 'manager', 'administrator'];

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly MailManagerInterface $mailManager,
    private readonly LanguageManagerInterface $languageManager,
    private readonly LoggerChannelFactoryInterface $loggerFactory,
  ) {}

  public function notify(NodeInterface $node, string $from, string $to): void {
    try {
      if ($to === 'pending_review') {
        $this->emailReviewers($node);
        return;
      }
      if ($from === 'pending_review' && $to === 'published') {
        $this->emailAuthor($node, 'submission_approved');
        return;
      }
      if ($from === 'pending_review' && $to === 'rejected') {
        $this->emailAuthor($node, 'submission_rejected');
        return;
      }
    }
    catch (\Throwable $e) {
      // Never block a content save because email failed. Log + carry on.
      $this->loggerFactory->get('programhub_governance')->error(
        'Moderation notification failed for node @nid (@from → @to): @msg',
        [
          '@nid' => $node->id(),
          '@from' => $from,
          '@to' => $to,
          '@msg' => $e->getMessage(),
        ],
      );
    }
  }

  /**
   * Send a "ready for review" email to every reviewer in the node's
   * program(s). De-dupes recipients so a user with two reviewer roles
   * gets one message.
   */
  private function emailReviewers(NodeInterface $node): void {
    $groups = $this->groupsForNode($node);
    if (empty($groups)) {
      return;
    }
    $recipients = $this->reviewerEmailsForGroups($groups);
    if (empty($recipients)) {
      return;
    }

    $author = $node->getOwner();
    $params = [
      'title' => $node->label(),
      'bundle' => $node->bundle(),
      'author' => $author ? $author->getDisplayName() : 'A user',
      'url' => $node->toUrl('edit-form', ['absolute' => TRUE])->toString(),
    ];

    foreach ($recipients as $email) {
      $this->mailManager->mail(
        'programhub_governance',
        'submission_pending',
        $email,
        $this->languageManager->getDefaultLanguage()->getId(),
        $params,
      );
    }
  }

  private function emailAuthor(NodeInterface $node, string $mailKey): void {
    $author = $node->getOwner();
    if (!$author || $author->isAnonymous() || !$author->getEmail()) {
      return;
    }
    $params = [
      'title' => $node->label(),
      'bundle' => $node->bundle(),
      'reviewer' => '',
      'reason' => '',
      'url' => $node->toUrl('canonical', ['absolute' => TRUE])->toString(),
    ];
    $this->mailManager->mail(
      'programhub_governance',
      $mailKey,
      $author->getEmail(),
      $author->getPreferredLangcode(),
      $params,
    );
  }

  /**
   * Program groups owning this content node, via group_relationship.
   *
   * @return \Drupal\group\Entity\GroupInterface[]
   */
  private function groupsForNode(NodeInterface $node): array {
    $groups = [];
    foreach (GroupRelationship::loadByEntity($node) as $relationship) {
      $group = $relationship->getGroup();
      if ($group && in_array($group->bundle(), \Drupal\programhub_dashboard\Service\GroupContext::PROGRAM_GROUP_TYPES, TRUE)) {
        $groups[(int) $group->id()] = $group;
      }
    }
    return array_values($groups);
  }

  /**
   * Look up unique reviewer email addresses across all given groups.
   *
   * @param \Drupal\group\Entity\GroupInterface[] $groups
   * @return string[]
   */
  private function reviewerEmailsForGroups(array $groups): array {
    $emails = [];
    foreach ($groups as $group) {
      // Expand the role-suffix list into the full role IDs that exist
      // on THIS group's bundle. `$group->getMembers()` needs full IDs.
      $roles = array_map(
        static fn(string $suffix): string => $group->bundle() . '-' . $suffix,
        self::REVIEWER_ROLE_SUFFIXES,
      );
      foreach ($group->getMembers($roles) as $membership) {
        $user = $membership->getUser();
        if ($user && $user->getEmail()) {
          $emails[$user->getEmail()] = $user->getEmail();
        }
      }
    }
    return array_values($emails);
  }

}
