<?php

declare(strict_types=1);

namespace Drupal\programhub_governance\Notification;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Mail\MailManagerInterface;
use Drupal\node\NodeInterface;
use Drupal\og\OgMembershipInterface;

/**
 * Sends moderation-transition emails.
 *
 * Three transitions trigger notifications:
 *   - `* → pending_review`  → reviewers in the node's program (instructor,
 *     manager, administrator OG roles)
 *   - `pending_review → published` → the node's author
 *   - `pending_review → rejected`  → the node's author
 *
 * Other transitions (archive, restore, draft↔draft) are silent — they
 * don't need an inbox notification.
 */
final class ModerationNotifier {

  /**
   * OG roles that grant reviewer authority. Holding any of these in the
   * group means the user receives "ready for review" emails for that
   * group's submissions.
   */
  private const REVIEWER_OG_ROLES = [
    'node-program-instructor',
    'node-program-manager',
    'node-program-administrator',
  ];

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly MailManagerInterface $mailManager,
    private readonly LanguageManagerInterface $languageManager,
    private readonly LoggerChannelFactoryInterface $loggerFactory,
  ) {}

  public function notify(NodeInterface $node, string $from, string $to): void {
    try {
      // Submission → reviewers
      if ($to === 'pending_review') {
        $this->emailReviewers($node);
        return;
      }
      // Approved / rejected → author
      if ($from === 'pending_review' && $to === 'published') {
        $this->emailAuthor($node, 'submission_approved');
        return;
      }
      if ($from === 'pending_review' && $to === 'rejected') {
        $this->emailAuthor($node, 'submission_rejected');
        return;
      }
      // archive / restore / draft↔draft — silent.
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
    $programIds = $this->nodeProgramIds($node);
    if (empty($programIds)) {
      return;
    }

    $recipients = $this->reviewerEmailsForPrograms($programIds);
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

  /**
   * Email the author with an approved/rejected message.
   */
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
   * Programs (node IDs) the given content node is scoped to via
   * og_audience.
   *
   * @return int[]
   */
  private function nodeProgramIds(NodeInterface $node): array {
    if (!$node->hasField('og_audience')) {
      return [];
    }
    $ids = [];
    foreach ($node->get('og_audience')->referencedEntities() as $group) {
      if ($group instanceof NodeInterface && $group->bundle() === 'program') {
        $ids[(int) $group->id()] = (int) $group->id();
      }
    }
    return array_values($ids);
  }

  /**
   * Look up unique reviewer email addresses across all given programs.
   *
   * @param int[] $programIds
   * @return string[]
   */
  private function reviewerEmailsForPrograms(array $programIds): array {
    $membershipStorage = $this->entityTypeManager->getStorage('og_membership');
    $userStorage = $this->entityTypeManager->getStorage('user');

    $membershipIds = $membershipStorage->getQuery()
      ->accessCheck(FALSE)
      ->condition('entity_type', 'node')
      ->condition('entity_id', $programIds, 'IN')
      ->condition('state', OgMembershipInterface::STATE_ACTIVE)
      ->execute();
    if (empty($membershipIds)) {
      return [];
    }

    $emails = [];
    foreach ($membershipStorage->loadMultiple($membershipIds) as $membership) {
      $hasReviewerRole = FALSE;
      foreach ($membership->getRoles() as $role) {
        if (in_array($role->id(), self::REVIEWER_OG_ROLES, TRUE)) {
          $hasReviewerRole = TRUE;
          break;
        }
      }
      if (!$hasReviewerRole) {
        continue;
      }
      $user = $userStorage->load($membership->getOwnerId());
      if ($user && $user->getEmail()) {
        $emails[$user->getEmail()] = $user->getEmail();
      }
    }
    return array_values($emails);
  }

}
