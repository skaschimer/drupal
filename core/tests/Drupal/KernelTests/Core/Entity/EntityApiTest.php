<?php

declare(strict_types=1);

namespace Drupal\KernelTests\Core\Entity;

use Drupal\Core\Database\Database;
use Drupal\Core\Entity\EntityStorageException;
use Drupal\entity_test\Entity\EntityTest;
use Drupal\entity_test\EntityTestHelper;
use Drupal\user\UserInterface;

/**
 * Tests basic CRUD functionality.
 *
 * @group Entity
 */
class EntityApiTest extends EntityKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    foreach (EntityTestHelper::getEntityTypes() as $entity_type_id) {
      // The entity_test schema is installed by the parent.
      if ($entity_type_id != 'entity_test') {
        $this->installEntitySchema($entity_type_id);
      }
    }
  }

  /**
   * Tests basic CRUD functionality of the Entity API.
   */
  public function testCRUD(): void {
    // All entity variations have to have the same results.
    foreach (EntityTestHelper::getEntityTypes() as $entity_type) {
      $this->assertCRUD($entity_type, $this->createUser());
    }
  }

  /**
   * Executes a test set for a defined entity type and user.
   *
   * @param string $entity_type
   *   The entity type to run the tests with.
   * @param \Drupal\user\UserInterface $user1
   *   The user to run the tests with.
   *
   * @internal
   */
  protected function assertCRUD(string $entity_type, UserInterface $user1): void {
    // Create some test entities.
    $entity = $this->container->get('entity_type.manager')
      ->getStorage($entity_type)
      ->create(['name' => 'test', 'user_id' => $user1->id()]);
    $entity->save();
    $entity = $this->container->get('entity_type.manager')
      ->getStorage($entity_type)
      ->create(['name' => 'test2', 'user_id' => $user1->id()]);
    $entity->save();
    $entity = $this->container->get('entity_type.manager')
      ->getStorage($entity_type)
      ->create(['name' => 'test', 'user_id' => NULL]);
    $entity->save();

    /** @var \Drupal\Core\Entity\EntityStorageInterface $storage */
    $storage = $this->container->get('entity_type.manager')
      ->getStorage($entity_type);

    $entities = array_values($storage->loadByProperties(['name' => 'test']));
    $this->assertEquals('test', $entities[0]->name->value, "$entity_type: Created and loaded entity");
    $this->assertEquals('test', $entities[1]->name->value, "$entity_type: Created and loaded entity");

    // Test loading a single entity.
    $loaded_entity = $storage->load($entity->id());
    $this->assertEquals($entity->id(), $loaded_entity->id(), "$entity_type: Loaded a single entity by id.");

    // Test deleting an entity.
    $entities = array_values($storage->loadByProperties(['name' => 'test2']));
    $entities[0]->delete();
    $entities = array_values($storage->loadByProperties(['name' => 'test2']));
    $this->assertEquals([], $entities, "$entity_type: Entity deleted.");

    // Test updating an entity.
    $entities = array_values($storage->loadByProperties(['name' => 'test']));
    $entities[0]->name->value = 'test3';
    $entities[0]->save();
    $entity = $storage->load($entities[0]->id());
    $this->assertEquals('test3', $entity->name->value, "$entity_type: Entity updated.");

    // Try deleting multiple test entities by deleting all.
    $entities = $storage->loadMultiple();
    $storage->delete($entities);

    $all = $storage->loadMultiple();
    $this->assertEmpty($all, "All entities of type '$entity_type' should have been deleted.");

    // Verify that all data got deleted.
    $definition = \Drupal::entityTypeManager()->getDefinition($entity_type);
    $connection = Database::getConnection();
    $this->assertEquals(0, (int) $connection->select($definition->getBaseTable())->countQuery()->execute()->fetchField(), 'Base table was emptied');

    if ($data_table = $definition->getDataTable()) {
      $this->assertEquals(0, (int) $connection->select($data_table)->countQuery()->execute()->fetchField(), 'Data table was emptied');
    }
    if ($revision_table = $definition->getRevisionTable()) {
      $this->assertEquals(0, (int) $connection->select($revision_table)->countQuery()->execute()->fetchField(), 'Revision table was emptied');
    }
    if ($revision_data_table = $definition->getRevisionDataTable()) {
      $this->assertEquals(0, (int) $connection->select($revision_data_table)->countQuery()->execute()->fetchField(), 'Revision data table was emptied');
    }

    // Test deleting a list of entities not indexed by entity id.
    $entities = [];
    $entity = $storage->create(['name' => 'test', 'user_id' => $user1->id()]);
    $entity->save();
    $entities['test'] = $entity;
    $entity = $storage->create(['name' => 'test2', 'user_id' => $user1->id()]);
    $entity->save();
    $entities['test2'] = $entity;
    $controller = \Drupal::entityTypeManager()->getStorage($entity_type);
    $controller->delete($entities);

    // Verify that entities got deleted.
    $all = $storage->loadMultiple();
    $this->assertEmpty($all, "All entities of type '$entity_type' should have been deleted.");

    // Verify that all data got deleted from the tables.
    $definition = \Drupal::entityTypeManager()->getDefinition($entity_type);
    $this->assertEquals(0, (int) $connection->select($definition->getBaseTable())->countQuery()->execute()->fetchField(), 'Base table was emptied');

    if ($data_table = $definition->getDataTable()) {
      $this->assertEquals(0, (int) $connection->select($data_table)->countQuery()->execute()->fetchField(), 'Data table was emptied');
    }
    if ($revision_table = $definition->getRevisionTable()) {
      $this->assertEquals(0, (int) $connection->select($revision_table)->countQuery()->execute()->fetchField(), 'Revision table was emptied');
    }
    if ($revision_data_table = $definition->getRevisionDataTable()) {
      $this->assertEquals(0, (int) $connection->select($revision_data_table)->countQuery()->execute()->fetchField(), 'Revision data table was emptied');
    }
  }

  /**
   * Tests that the Entity storage loads the entities in the correct order.
   *
   * Entities should be returned in the same order as the passed IDs.
   */
  public function testLoadMultiple(): void {
    // Entity load.
    $storage = $this->container->get('entity_type.manager')->getStorage('entity_test');

    $ids = [];
    $entity = $storage->create(['name' => 'test']);
    $entity->save();
    $ids[] = $entity->id();

    $entity = $storage->create(['name' => 'test2']);
    $entity->save();
    $ids[] = $entity->id();

    // We load the entities in an initial and reverse order, with both static
    // cache in place and reset, to ensure we always get the same result.
    $entities = $storage->loadMultiple($ids);
    $this->assertEquals($ids, array_keys($entities));
    // Reverse the order and load again.
    $ids = array_reverse($ids);
    $entities = $storage->loadMultiple($ids);
    $this->assertEquals($ids, array_keys($entities));
    // Reverse the order again, reset the cache and load again.
    $storage->resetCache();
    $ids = array_reverse($ids);
    $entities = $storage->loadMultiple($ids);
    $this->assertEquals($ids, array_keys($entities));

    // Entity revision load.
    $storage = $this->container->get('entity_type.manager')->getStorage('entity_test_rev');

    $ids = [];
    $entity = $storage->create(['name' => 'test_rev']);
    $entity->save();
    $ids[] = $entity->getRevisionId();

    $revision = $storage->createRevision($entity, TRUE);
    $revision->save();
    $ids[] = $revision->getRevisionId();

    $entities = $storage->loadMultipleRevisions($ids);
    $this->assertEquals($ids, array_keys($entities));

    // Reverse the order and load again.
    $ids = array_reverse($ids);
    $entities = $storage->loadMultipleRevisions($ids);
    $this->assertEquals($ids, array_keys($entities));

    // Reverse the order again, reset the cache and load again.
    $ids = array_reverse($ids);
    $storage->resetCache();
    $entities = $storage->loadMultipleRevisions($ids);
    $this->assertEquals($ids, array_keys($entities));
  }

  /**
   * Tests that exceptions are thrown when saving or deleting an entity.
   */
  public function testEntityStorageExceptionHandling(): void {
    $entity = EntityTest::create(['name' => 'test']);
    try {
      $GLOBALS['entity_test_throw_exception'] = TRUE;
      $entity->save();
      $this->fail('Entity presave EntityStorageException thrown but not caught.');
    }
    catch (EntityStorageException $e) {
      $this->assertEquals(1, $e->getCode(), 'Entity presave EntityStorageException caught.');
    }

    $entity = EntityTest::create(['name' => 'test2']);
    try {
      unset($GLOBALS['entity_test_throw_exception']);
      $entity->save();
    }
    catch (EntityStorageException $e) {
      $this->assertNotEquals(1, $e->getCode(), 'Entity presave EntityStorageException caught.');
    }

    $entity = EntityTest::create(['name' => 'test3']);
    $entity->save();
    try {
      $GLOBALS['entity_test_throw_exception'] = TRUE;
      $entity->delete();
      $this->fail('Entity predelete EntityStorageException not thrown.');
    }
    catch (EntityStorageException $e) {
      $this->assertEquals(2, $e->getCode(), 'Entity predelete EntityStorageException caught.');
    }

    unset($GLOBALS['entity_test_throw_exception']);
    $entity = EntityTest::create(['name' => 'test4']);
    $entity->save();
    try {
      $entity->delete();
    }
    catch (EntityStorageException $e) {
      $this->assertNotEquals(2, $e->getCode(), 'Entity predelete EntityStorageException thrown.');
    }
  }

  /**
   * Tests that resaving a revision with a different revision ID throws an exception.
   */
  public function testUpdateWithRevisionId(): void {
    $storage = \Drupal::entityTypeManager()->getStorage('entity_test_mulrev');

    // Create a new entity.
    /** @var \Drupal\entity_test\Entity\EntityTestMulRev $entity */
    $entity = $storage->create(['name' => 'revision_test']);
    $entity->save();

    $this->expectException(EntityStorageException::class);
    $this->expectExceptionMessage("Update existing 'entity_test_mulrev' entity revision while changing the revision ID is not supported.");

    $entity->revision_id = 60;
    $entity->save();
  }

  /**
   * Tests that resaving an entity with a different entity ID throws an exception.
   */
  public function testUpdateWithId(): void {
    $storage = \Drupal::entityTypeManager()->getStorage('entity_test_mulrev');

    // Create a new entity.
    /** @var \Drupal\entity_test\Entity\EntityTestMulRev $entity */
    $entity = $storage->create(['name' => 'revision_test']);
    $entity->save();

    $this->expectException(EntityStorageException::class);
    $this->expectExceptionMessage("Update existing 'entity_test_mulrev' entity while changing the ID is not supported.");

    $entity->id = 60;
    $entity->save();
  }

}
