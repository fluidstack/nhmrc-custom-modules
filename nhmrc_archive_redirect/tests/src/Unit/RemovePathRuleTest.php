<?php

namespace Drupal\Tests\nhmrc_archive_redirect\Unit;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Path\PathValidatorInterface;
use Drupal\nhmrc_archive_redirect\Form\ArchiveRedirectSettingsForm;
use Drupal\Tests\UnitTestCase;

/**
 * Unit tests for the removePathRule AJAX submit handler.
 *
 * Verifies that clicking "Remove" on a specific row removes the correct entry
 * and that the raw user input is synchronised so Drupal's form rebuild displays
 * the right values.
 *
 * @group nhmrc_archive_redirect
 * @coversDefaultClass \Drupal\nhmrc_archive_redirect\Form\ArchiveRedirectSettingsForm
 */
class RemovePathRuleTest extends UnitTestCase {

  /**
   * Creates a form instance with mocked dependencies.
   */
  private function createForm(): ArchiveRedirectSettingsForm {
    $config = $this->createMock(ImmutableConfig::class);
    $configFactory = $this->createMock(ConfigFactoryInterface::class);
    $configFactory->method('getEditable')->willReturn($config);
    $configFactory->method('get')->willReturn($config);

    $pathValidator = $this->createMock(PathValidatorInterface::class);
    $entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);

    return new ArchiveRedirectSettingsForm(
      $configFactory,
      $pathValidator,
      $entityTypeManager,
    );
  }

  /**
   * Builds a mock FormStateInterface for removePathRule testing.
   *
   * @param array $userInput
   *   The raw user input array (simulates POST data).
   *
   * @return \Drupal\Core\Form\FormStateInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  private function createFormState(array $userInput): FormStateInterface {
    $formState = $this->createMock(FormStateInterface::class);

    $storedInput = $userInput;
    $storedPathRules = NULL;
    $rebuildCalled = FALSE;

    $formState->method('getUserInput')->willReturnCallback(
      function () use (&$storedInput) {
        return $storedInput;
      }
    );

    $formState->method('setUserInput')->willReturnCallback(
      function (array $input) use (&$storedInput) {
        $storedInput = $input;
      }
    );

    $formState->method('set')->willReturnCallback(
      function (string $key, $value) use (&$storedPathRules) {
        if ($key === 'path_rules') {
          $storedPathRules = $value;
        }
      }
    );

    $formState->method('get')->willReturnCallback(
      function (string $key) use (&$storedPathRules) {
        if ($key === 'path_rules') {
          return $storedPathRules;
        }
        return NULL;
      }
    );

    $formState->method('setRebuild')->willReturnCallback(
      function () use (&$rebuildCalled) {
        $rebuildCalled = TRUE;
      }
    );

    return $formState;
  }

  /**
   * Tests removing the middle row from three rows.
   *
   * @covers ::removePathRule
   */
  public function testRemoveMiddleRow(): void {
    $form = [];
    $userInput = [
      '_triggering_element_name' => 'remove_rule_1',
      'path_rules' => [
        'table' => [
          0 => ['source_prefix' => '/news/', 'destination' => '/archive/news'],
          1 => ['source_prefix' => '/events/', 'destination' => '/archive/events'],
          2 => ['source_prefix' => '/grants/', 'destination' => '/archive/grants'],
        ],
      ],
    ];

    $formState = $this->createFormState($userInput);
    $sut = $this->createForm();

    $sut->removePathRule($form, $formState);

    // Verify form state has the correct rules (row 1 removed).
    $rules = $formState->get('path_rules');
    $this->assertCount(2, $rules);
    $this->assertEquals('/news/', $rules[0]['source_prefix']);
    $this->assertEquals('/archive/news', $rules[0]['destination']);
    $this->assertEquals('/grants/', $rules[1]['source_prefix']);
    $this->assertEquals('/archive/grants', $rules[1]['destination']);

    // Verify raw user input was synchronised.
    $updatedInput = $formState->getUserInput();
    $this->assertCount(2, $updatedInput['path_rules']['table']);
    $this->assertEquals('/news/', $updatedInput['path_rules']['table'][0]['source_prefix']);
    $this->assertEquals('/grants/', $updatedInput['path_rules']['table'][1]['source_prefix']);
  }

  /**
   * Tests removing the first row from three rows.
   *
   * @covers ::removePathRule
   */
  public function testRemoveFirstRow(): void {
    $form = [];
    $userInput = [
      '_triggering_element_name' => 'remove_rule_0',
      'path_rules' => [
        'table' => [
          0 => ['source_prefix' => '/news/', 'destination' => '/archive/news'],
          1 => ['source_prefix' => '/events/', 'destination' => '/archive/events'],
          2 => ['source_prefix' => '/grants/', 'destination' => '/archive/grants'],
        ],
      ],
    ];

    $formState = $this->createFormState($userInput);
    $sut = $this->createForm();

    $sut->removePathRule($form, $formState);

    $rules = $formState->get('path_rules');
    $this->assertCount(2, $rules);
    $this->assertEquals('/events/', $rules[0]['source_prefix']);
    $this->assertEquals('/archive/events', $rules[0]['destination']);
    $this->assertEquals('/grants/', $rules[1]['source_prefix']);
    $this->assertEquals('/archive/grants', $rules[1]['destination']);

    $updatedInput = $formState->getUserInput();
    $this->assertCount(2, $updatedInput['path_rules']['table']);
    $this->assertEquals('/events/', $updatedInput['path_rules']['table'][0]['source_prefix']);
    $this->assertEquals('/grants/', $updatedInput['path_rules']['table'][1]['source_prefix']);
  }

  /**
   * Tests removing the last row from three rows.
   *
   * @covers ::removePathRule
   */
  public function testRemoveLastRow(): void {
    $form = [];
    $userInput = [
      '_triggering_element_name' => 'remove_rule_2',
      'path_rules' => [
        'table' => [
          0 => ['source_prefix' => '/news/', 'destination' => '/archive/news'],
          1 => ['source_prefix' => '/events/', 'destination' => '/archive/events'],
          2 => ['source_prefix' => '/grants/', 'destination' => '/archive/grants'],
        ],
      ],
    ];

    $formState = $this->createFormState($userInput);
    $sut = $this->createForm();

    $sut->removePathRule($form, $formState);

    $rules = $formState->get('path_rules');
    $this->assertCount(2, $rules);
    $this->assertEquals('/news/', $rules[0]['source_prefix']);
    $this->assertEquals('/events/', $rules[1]['source_prefix']);

    $updatedInput = $formState->getUserInput();
    $this->assertCount(2, $updatedInput['path_rules']['table']);
    $this->assertEquals('/news/', $updatedInput['path_rules']['table'][0]['source_prefix']);
    $this->assertEquals('/events/', $updatedInput['path_rules']['table'][1]['source_prefix']);
  }

  /**
   * Tests removing the only row results in an empty table.
   *
   * @covers ::removePathRule
   */
  public function testRemoveOnlyRow(): void {
    $form = [];
    $userInput = [
      '_triggering_element_name' => 'remove_rule_0',
      'path_rules' => [
        'table' => [
          0 => ['source_prefix' => '/news/', 'destination' => '/archive/news'],
        ],
      ],
    ];

    $formState = $this->createFormState($userInput);
    $sut = $this->createForm();

    $sut->removePathRule($form, $formState);

    $rules = $formState->get('path_rules');
    $this->assertCount(0, $rules);

    $updatedInput = $formState->getUserInput();
    $this->assertCount(0, $updatedInput['path_rules']['table']);
  }

  /**
   * Tests that a tampered/invalid triggering element name removes nothing.
   *
   * @covers ::removePathRule
   */
  public function testInvalidTriggeringElementRemovesNothing(): void {
    $form = [];
    $userInput = [
      '_triggering_element_name' => 'remove_rule_abc',
      'path_rules' => [
        'table' => [
          0 => ['source_prefix' => '/news/', 'destination' => '/archive/news'],
          1 => ['source_prefix' => '/events/', 'destination' => '/archive/events'],
        ],
      ],
    ];

    $formState = $this->createFormState($userInput);
    $sut = $this->createForm();

    $sut->removePathRule($form, $formState);

    $rules = $formState->get('path_rules');
    $this->assertCount(2, $rules);
    $this->assertEquals('/news/', $rules[0]['source_prefix']);
    $this->assertEquals('/events/', $rules[1]['source_prefix']);
  }

}
