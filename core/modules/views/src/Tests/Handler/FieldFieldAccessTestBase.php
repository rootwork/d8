<?php

/**
 * @file
 * Contains \Drupal\views\Tests\Handler\FieldFieldAccessTest.
 */

namespace Drupal\views\Tests\Handler;

use Drupal\user\Entity\Role;
use Drupal\user\Entity\User;
use Drupal\views\Entity\View;
use Drupal\views\Tests\ViewUnitTestBase;
use Drupal\views\Views;

/**
 * Provides a base class for base field access in views.
 */
abstract class FieldFieldAccessTestBase extends ViewUnitTestBase {

  /**
   * Stores an user entity with access to fields.
   *
   * @var \Drupal\user\UserInterface
   */
  protected $userWithAccess;

  /**
   * Stores an user entity without access to fields.
   *
   * @var \Drupal\user\UserInterface
   */
  protected $userWithoutAccess;

  /**
   * {@inheritdoc}
   */
  public static $modules = ['user'];

  /**
   * {@inheritdoc}
   */
  protected function setUp($import_test_views = TRUE) {
    parent::setUp($import_test_views);

    $this->installEntitySchema('user');

    $role_with_access = Role::create([
      'id' => 'with_access',
      'permissions' => ['view test entity field'],
    ]);
    $role_with_access->save();
    $role_without_access = Role::create([
      'id' => 'without_access',
      'permissions' => [],
    ]);
    $role_without_access->save();

    $this->userWithAccess = User::create([
      'name' => $this->randomMachineName(),
      'roles' => [$role_with_access->id()],
    ]);
    $this->userWithAccess->save();
    $this->userWithoutAccess = User::create([
      'name' => $this->randomMachineName(),
      'roles' => [$role_without_access->id()],
    ]);
    $this->userWithoutAccess->save();
  }

  /**
   * Checks views field access for a given entity type and field name.
   *
   * To use this method, set up an entity of type $entity_type_id, with field
   * $field_name. Create an entity instance that contains content $field_content
   * in that field.
   *
   * This method will check that a user with permission can see the content in a
   * view, and a user without access permission on that field cannot.
   *
   * @param string $entity_type_id
   *   The entity type ID.
   * @param string $field_name
   *   The field name.
   * @param string $field_content
   *   The expected field content.
   */
  protected function assertFieldAccess($entity_type_id, $field_name, $field_content) {
    \Drupal::state()->set('views_field_access_test-field', $field_name);

    $entity_type = \Drupal::entityManager()->getDefinition($entity_type_id);
    $view_id = $this->randomMachineName();
    $base_table = $entity_type->getDataTable() ?: $entity_type->getBaseTable();
    $entity = View::create([
      'id' => $view_id,
      'base_table' => $base_table,
      'display' => [
        'default' => [
          'display_plugin' => 'default',
          'id' => 'default',
          'display_options' => [
            'fields' => [
              $field_name => [
                'table' => $base_table,
                'field' => $field_name,
                'id' => $field_name,
                'plugin_id' => 'field',
              ],
            ],
          ],
        ],
      ],
    ]);
    $entity->save();

    /** @var \Drupal\Core\Session\AccountSwitcherInterface $account_switcher */
    $account_switcher = \Drupal::service('account_switcher');

    /** @var \Drupal\Core\Render\RendererInterface $renderer */
    $renderer = \Drupal::service('renderer');

    $account_switcher->switchTo($this->userWithAccess);
    $executable = Views::getView($view_id);
    $build = $executable->preview();
    $this->setRawContent($renderer->render($build));

    $this->assertText($field_content);
    $this->assertTrue(isset($executable->field[$field_name]));

    $account_switcher->switchTo($this->userWithoutAccess);
    $executable = Views::getView($view_id);
    $build = $executable->preview();
    $this->setRawContent($renderer->render($build));

    $this->assertNoText($field_content);
    $this->assertFalse(isset($executable->field[$field_name]));

    \Drupal::state()->delete('views_field_access_test-field');
  }

}
