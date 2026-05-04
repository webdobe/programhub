<?php

namespace Drupal\Tests\fieldable_path\Functional;

use Drupal\field\Entity\FieldConfig;
use Drupal\Tests\BrowserTestBase;
use Drupal\field\Entity\FieldStorageConfig;

/**
 * Provides basic test coverage for CRUD functionality.
 *
 * @group fieldable_path
 */
class FieldablePathBasicTest extends BrowserTestBase {

  /**
   * Modules to enable.
   *
   * @var array
   */
  protected static $modules = [
    'path',
    'fieldable_path',
    'node',
  ];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'classy';

  /**
   * Describes a user with necessary permissions.
   *
   * @var \Drupal\user\Entity\User
   */
  protected $user;

  /**
   * Describes a general field name.
   *
   * @var string
   */
  protected $fieldName = 'field_path';

  /**
   * Describes field type name.
   *
   * @var string
   */
  protected $fieldType = 'fieldable_path';

  /**
   * Describes field type widget.
   *
   * @var string
   */
  protected $fieldWidget = 'fieldable_path_widget';

  /**
   * {@inheritdoc}
   */
  protected function setUp() : void {
    parent::setUp();

    // Create a new Article node type.
    $this->drupalCreateContentType([
      'type' => 'article',
      'name' => 'Article',
    ]);

    FieldStorageConfig::create([
      'entity_type' => 'node',
      'field_name' => $this->fieldName,
      'type' => $this->fieldType,
      'settings' => [],
      'cardinality' => 1,
    ])->save();

    // Add field to the article node type.
    FieldConfig::create([
      'entity_type' => 'node',
      'bundle' => 'article',
      'field_name' => $this->fieldName,
      'label' => 'Path',
      'required' => FALSE,
      'settings' => [],
      'description' => '',
    ])
      ->save();

    // Add the field to the node article form.
    \Drupal::service('entity_display.repository')->getFormDisplay('node', 'article', 'default')
      ->setComponent($this->fieldName, [
        'type' => $this->fieldWidget,
      ])
      ->save();

    \Drupal::service('entity_display.repository')->getViewDisplay('node', 'article', 'full')
      ->setComponent($this->fieldName)
      ->save();

    $this->user = $this->drupalCreateUser([
      'create article content',
      'edit any article content',
      'create url aliases',
    ]);

    $this->drupalLogin($this->user);
  }

  /**
   * Tests basic Create, Read, Update, Delete operations for nodes.
   */
  public function testNodePath() : void {

    /*
     * Test creation of a new node with new path alias.
     */

    $pathAlias = '/' . $this->randomMachineName();
    $edit = [
      'title[0][value]' => $this->randomMachineName(),
      'path[0][alias]' => $pathAlias,
    ];
    $this->drupalGet('node/add/article');

    // Create a new node with path alias set.
    $this->submitForm($edit, 'Save');
    $this->assertSession()->statusCodeEquals(200);

    // Make sure field formatter works properly and displays
    // the correct alias.
    $xpath = $this->xpath('//div[@class="node__content"]//div[@class="field__item"]/p');
    $this->assertCount(1, $xpath);
    $this->assertEquals($pathAlias, $xpath[0]->getText());

    // Get node edit url from the page header.
    $xpath = $this->xpath('//link[@rel=\'edit-form\']');
    $editPage = (string) $xpath[0]->getAttribute('href');

    // Make sure path field widget shows the right value.
    $this->drupalGet($editPage);
    $this->assertSession()->fieldValueEquals($this->fieldName . '[0][value]', $pathAlias);

    // Make sure field widget does not let anyone edit the field.
    $xpath = $this->xpath('//input[@name=\'' . $this->fieldName . '[0][value]\']');
    $disabledValue = (string) $xpath[0]->getAttribute('disabled');
    $this->assertEquals($disabledValue, 'disabled');

    /*
     * Test updating of path alias.
     */

    // Set the new value to the path alias.
    $pathAlias = '/' . $this->randomMachineName();
    $edit = ['path[0][alias]' => $pathAlias];
    $this->drupalGet($editPage);
    $this->submitForm($edit, 'Save');

    // Make sure path field got the right value.
    $this->drupalGet($editPage);
    $this->assertSession()->fieldValueEquals($this->fieldName . '[0][value]', $pathAlias);

    /*
     * Test deletion of path alias.
     */

    // Set the new value to the path alias.
    $edit = ['path[0][alias]' => ''];
    $this->drupalGet($editPage);
    $this->submitForm($edit, 'Save');

    // Make sure path field has empty value.
    $this->drupalGet($editPage);
    $this->assertSession()->fieldValueEquals($this->fieldName . '[0][value]', '');
  }

}
