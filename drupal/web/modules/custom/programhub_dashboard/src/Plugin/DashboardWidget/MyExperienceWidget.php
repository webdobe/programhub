<?php

declare(strict_types=1);

namespace Drupal\programhub_dashboard\Plugin\DashboardWidget;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Url;
use Drupal\profile\Entity\ProfileInterface;
use Drupal\programhub_dashboard\DashboardWidgetBase;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * @DashboardWidget(
 *   id = "my_experience",
 *   label = @Translation("Your career & experience"),
 *   description = @Translation("Self-reported jobs, internships, and roles. Edit on your profile."),
 *   weight = -85,
 *   category = "graduate"
 * )
 *
 * Surfaces the graduate's own `field_experience` paragraph entries with
 * an "edit profile" link. Visible only to users who have a graduate
 * profile.
 *
 * See ACCESS.md §"Career Outcome" — graduate-self-reported career data
 * lives in this paragraph field, NOT on BLS-imported `career_outcome`
 * nodes.
 */
final class MyExperienceWidget extends DashboardWidgetBase implements ContainerFactoryPluginInterface {

  public function __construct(
    array $configuration,
    string $plugin_id,
    array $plugin_definition,
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): self {
    return new self(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('entity_type.manager'),
    );
  }

  public function access(AccountInterface $user): AccessResultInterface {
    // Only render for users who have a graduate profile.
    return AccessResult::allowedIf($this->loadGraduateProfile($user) !== NULL)
      ->addCacheContexts(['user']);
  }

  public function build(AccountInterface $user): array {
    $profile = $this->loadGraduateProfile($user);
    if ($profile === NULL) {
      return [];
    }

    $editUrl = Url::fromRoute('entity.profile.edit_form', [
      'profile' => $profile->id(),
      'user' => $user->id(),
    ])->toString();

    $entries = [];
    if ($profile->hasField('field_experience')) {
      foreach ($profile->get('field_experience')->referencedEntities() as $paragraph) {
        $title = $paragraph->hasField('field_title') && !$paragraph->get('field_title')->isEmpty()
          ? $paragraph->get('field_title')->value
          : NULL;
        $location = $paragraph->hasField('field_location') && !$paragraph->get('field_location')->isEmpty()
          ? $paragraph->get('field_location')->value
          : NULL;
        if (!$title) {
          continue;
        }
        $entries[] = [
          'title' => $title,
          'location' => $location,
        ];
      }
    }

    return [
      '#type' => 'inline_template',
      '#template' => '
        {% if entries %}
          <ul>
            {% for e in entries %}
              <li><strong>{{ e.title }}</strong>{% if e.location %} <span class="programhub-widget__meta">— {{ e.location }}</span>{% endif %}</li>
            {% endfor %}
          </ul>
        {% else %}
          <p class="programhub-widget__meta">{{ "Nothing added yet."|t }}</p>
        {% endif %}
        <p><a href="{{ edit_url }}" class="button button--small">{{ "Edit profile"|t }}</a></p>
      ',
      '#context' => [
        'entries' => $entries,
        'edit_url' => $editUrl,
      ],
      '#cache' => [
        'contexts' => ['user'],
        'tags' => $profile->getCacheTags(),
      ],
    ];
  }

  /**
   * Load the user's graduate profile, or NULL if they don't have one.
   */
  private function loadGraduateProfile(AccountInterface $user): ?ProfileInterface {
    $storage = $this->entityTypeManager->getStorage('profile');
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('uid', $user->id())
      ->condition('type', 'graduate')
      ->range(0, 1)
      ->execute();
    if (empty($ids)) {
      return NULL;
    }
    $profile = $storage->load(reset($ids));
    return $profile instanceof ProfileInterface ? $profile : NULL;
  }

}
