<?php

declare(strict_types=1);

namespace Drupal\programhub_webform_simplenews\Plugin\WebformHandler;

use Drupal\Core\Form\FormStateInterface;
use Drupal\webform\Plugin\WebformHandlerBase;
use Drupal\webform\Plugin\WebformHandlerInterface;
use Drupal\webform\WebformSubmissionInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Subscribes a chosen email element value to a chosen simplenews newsletter.
 *
 * @WebformHandler(
 *   id = "programhub_simplenews_subscribe",
 *   label = @Translation("Simplenews subscribe"),
 *   category = @Translation("Notification"),
 *   description = @Translation("Subscribe a submitted email to a simplenews newsletter."),
 *   cardinality = \Drupal\webform\Plugin\WebformHandlerInterface::CARDINALITY_UNLIMITED,
 *   results = \Drupal\webform\Plugin\WebformHandlerInterface::RESULTS_PROCESSED,
 *   submission = \Drupal\webform\Plugin\WebformHandlerInterface::SUBMISSION_OPTIONAL,
 * )
 *
 * Cardinality is unlimited so the same webform can subscribe to several
 * newsletters by attaching the handler multiple times. Each handler
 * instance picks one (email element, newsletter) pair.
 *
 * Subscription goes through `simplenews.subscription_manager`, which
 * honors the newsletter's confirm/double-opt-in settings — we don't
 * second-guess them here.
 */
final class SimplenewsSubscribeWebformHandler extends WebformHandlerBase {

  /**
   * Webform element types whose value is a usable email address.
   * Anything else is rejected when listing the email_element options.
   */
  private const EMAIL_ELEMENT_TYPES = [
    'email',
    'webform_email_confirm',
    'webform_email_multiple',
  ];

  /**
   * @var \Drupal\simplenews\Subscription\SubscriptionManagerInterface
   */
  protected $subscriptionManager;

  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    $instance = parent::create($container, $configuration, $plugin_id, $plugin_definition);
    $instance->subscriptionManager = $container->get('simplenews.subscription_manager');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration(): array {
    return [
      'email_element' => '',
      'newsletter_id' => '',
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function getSummary(): array {
    $email = $this->configuration['email_element'] ?: $this->t('— not set —');
    $newsletterId = $this->configuration['newsletter_id'];
    $newsletterLabel = $newsletterId
      ? ($this->newsletterOptions()[$newsletterId] ?? $newsletterId)
      : $this->t('— not set —');

    return [
      '#markup' => $this->t('Subscribe %email to %newsletter.', [
        '%email' => $email,
        '%newsletter' => $newsletterLabel,
      ]),
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state): array {
    $form['email_element'] = [
      '#type' => 'select',
      '#title' => $this->t('Email element'),
      '#description' => $this->t('Which submitted field carries the email address to subscribe.'),
      '#options' => $this->emailElementOptions(),
      '#default_value' => $this->configuration['email_element'],
      '#required' => TRUE,
      '#empty_option' => $this->t('— Select —'),
    ];

    $form['newsletter_id'] = [
      '#type' => 'select',
      '#title' => $this->t('Newsletter'),
      '#description' => $this->t('Which simplenews subscriber list the email is added to.'),
      '#options' => $this->newsletterOptions(),
      '#default_value' => $this->configuration['newsletter_id'],
      '#required' => TRUE,
      '#empty_option' => $this->t('— Select —'),
    ];

    return $this->setSettingsParents($form);
  }

  /**
   * {@inheritdoc}
   */
  public function submitConfigurationForm(array &$form, FormStateInterface $form_state): void {
    parent::submitConfigurationForm($form, $form_state);
    $values = $form_state->getValue('settings') ?? [];
    foreach (array_keys($this->defaultConfiguration()) as $key) {
      if (isset($values[$key])) {
        $this->configuration[$key] = $values[$key];
      }
    }
  }

  /**
   * {@inheritdoc}
   *
   * Runs after the submission is saved (and only on save, not on
   * validation/preview). Pulls the submitted email and subscribes.
   * Failure is logged but never blocks the submission — a broken
   * mailing list integration shouldn't lose the form data.
   */
  public function postSave(WebformSubmissionInterface $webform_submission, $update = TRUE): void {
    if ($update) {
      // Re-saves of an existing submission shouldn't double-subscribe.
      return;
    }
    $emailKey = (string) $this->configuration['email_element'];
    $newsletterId = (string) $this->configuration['newsletter_id'];
    if ($emailKey === '' || $newsletterId === '') {
      return;
    }

    $value = $webform_submission->getElementData($emailKey);
    $email = is_array($value) ? ($value['value'] ?? '') : (string) $value;
    $email = trim($email);
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
      return;
    }

    try {
      $this->subscriptionManager->subscribe($email, $newsletterId);
    }
    catch (\Throwable $e) {
      $this->getLogger('programhub_webform_simplenews')->error(
        'Subscribe failed for @email → @nid: @msg',
        ['@email' => $email, '@nid' => $newsletterId, '@msg' => $e->getMessage()],
      );
    }
  }

  /**
   * Email-typed elements in the parent webform, keyed by element key.
   *
   * @return array<string, string>
   */
  private function emailElementOptions(): array {
    $webform = $this->getWebform();
    $options = [];
    if (!$webform) {
      return $options;
    }
    foreach ($webform->getElementsInitializedFlattenedAndHasValue() as $key => $element) {
      $type = $element['#type'] ?? '';
      if (in_array($type, self::EMAIL_ELEMENT_TYPES, TRUE)) {
        $title = $element['#title'] ?? $key;
        $options[$key] = sprintf('%s (%s)', $title, $key);
      }
    }
    return $options;
  }

  /**
   * All simplenews newsletters available, keyed by id.
   *
   * @return array<string, string>
   */
  private function newsletterOptions(): array {
    $storage = $this->entityTypeManager->getStorage('simplenews_newsletter');
    $options = [];
    foreach ($storage->loadMultiple() as $id => $newsletter) {
      $options[$id] = (string) $newsletter->label();
    }
    asort($options, SORT_NATURAL | SORT_FLAG_CASE);
    return $options;
  }

}
