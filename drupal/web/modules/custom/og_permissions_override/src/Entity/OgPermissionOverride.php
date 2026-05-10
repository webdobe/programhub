<?php

declare(strict_types=1);

namespace Drupal\og_permissions_override\Entity;

use Drupal\Core\Config\Entity\ConfigEntityBase;

/**
 * Stores a permission delta for an OG role on a single specific group.
 *
 * One entity = one (og_role × group) override. Stored as YAML in
 * `config/sync/og_permissions_override.<id>.yml` and CMI-tracked just like
 * any other config entity. Adding an override requires a matching row in
 * `ACCESS.md` §5; reviewers should block on that.
 *
 * The override layers on top of the bundle-level OG role permissions:
 *
 *   final = (bundle role permissions ∪ granted) \ revoked
 *
 * @ConfigEntityType(
 *   id = "og_permission_override",
 *   label = @Translation("OG permission override"),
 *   label_singular = @Translation("OG permission override"),
 *   label_plural = @Translation("OG permission overrides"),
 *   config_prefix = "override",
 *   admin_permission = "administer og permission overrides",
 *   entity_keys = {
 *     "id" = "id",
 *     "label" = "label",
 *     "uuid" = "uuid"
 *   },
 *   config_export = {
 *     "id",
 *     "label",
 *     "og_role",
 *     "group_entity_type",
 *     "group_id",
 *     "granted",
 *     "revoked"
 *   }
 * )
 */
final class OgPermissionOverride extends ConfigEntityBase {

  /**
   * Machine name. Convention: `<og_role_id>__<group_entity_type>_<group_id>`.
   *
   * Example: `node-program-instructor__node_42`.
   */
  protected string $id;

  /**
   * Human-readable label, free-form.
   */
  protected string $label;

  /**
   * The OG role this override applies to.
   *
   * Format matches `og.og_role.*` entity IDs, e.g.
   * `node-program-instructor`.
   */
  protected string $og_role = '';

  /**
   * The group's entity type. Currently always `node` since OG groups in
   * ProgramHub are program/division nodes — kept explicit for future-
   * proofing if non-node groups are ever introduced.
   */
  protected string $group_entity_type = 'node';

  /**
   * The specific group's entity ID (the program/division node ID).
   */
  protected int $group_id = 0;

  /**
   * Permissions to grant on top of the bundle role's defaults for this
   * specific group. Each string is a permission machine name.
   *
   * @var string[]
   */
  protected array $granted = [];

  /**
   * Permissions to revoke from the bundle role's defaults for this
   * specific group.
   *
   * @var string[]
   */
  protected array $revoked = [];

  public function getOgRoleId(): string {
    return $this->og_role;
  }

  public function getGroupEntityType(): string {
    return $this->group_entity_type;
  }

  public function getGroupId(): int {
    return $this->group_id;
  }

  /**
   * @return string[]
   */
  public function getGranted(): array {
    return $this->granted;
  }

  /**
   * @return string[]
   */
  public function getRevoked(): array {
    return $this->revoked;
  }

}
