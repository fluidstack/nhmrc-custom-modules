<?php

namespace Drupal\Tests\nhmrc_archive_redirect\FunctionalJavascript;

use Behat\Mink\Element\DocumentElement;
use Drupal\Core\Session\AccountInterface;
use Drupal\FunctionalJavascriptTests\JSWebAssert;
use Drupal\FunctionalJavascriptTests\WebDriverTestBase;

/**
 * Tests the AJAX Add / Remove behaviour on the archive-redirect settings form.
 *
 * Each test starts from a clean configuration state so that the path-rules
 * table is empty at the beginning. Rules are then added and removed entirely
 * through the browser (JavaScript / AJAX), matching exactly the interaction a
 * real administrator would perform.
 *
 * @group nhmrc_archive_redirect
 */
class ArchiveRedirectSettingsFormTest extends WebDriverTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'node',
    'nhmrc_archive_redirect',
  ];

  /**
   * {@inheritdoc}
   *
   * Use the stark theme so test selectors are not obscured by theme markup.
   */
  protected $defaultTheme = 'stark';

  /**
   * Admin user created for each test.
   *
   * @var \Drupal\Core\Session\AccountInterface
   */
  protected AccountInterface $adminUser;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $adminUser = $this->drupalCreateUser(['administer site configuration']);
    assert($adminUser instanceof AccountInterface, 'drupalCreateUser() must return a user.');
    $this->adminUser = $adminUser;
    $this->drupalLogin($this->adminUser);
  }

  // ---------------------------------------------------------------------------
  // Helper methods
  // ---------------------------------------------------------------------------

  /**
   * Returns the Mink page object.
   */
  private function getPage(): DocumentElement {
    return $this->getSession()->getPage();
  }

  /**
   * Returns the JS-aware assertion helper.
   *
   * WebDriverTestBase::assertSession() returns JSWebAssert at runtime, but the
   * parent BrowserTestBase declares the return type as WebAssert. This wrapper
   * provides the correct type so that PHPStan resolves JS-only methods such as
   * assertWaitOnAjaxRequest() without errors.
   */
  private function jsAssertSession(): JSWebAssert {
    /** @var \Drupal\FunctionalJavascriptTests\JSWebAssert $session */
    $session = $this->assertSession();
    return $session;
  }

  /**
   * Navigates to the archive-redirect settings form.
   */
  private function openSettingsForm(): void {
    $this->drupalGet('admin/config/content/archive-redirects');
    $this->assertSession()->statusCodeEquals(200);
  }

  /**
   * Clicks "Add rule" and waits for the AJAX response.
   *
   * @return int
   *   The zero-based index of the newly added row.
   */
  private function clickAddRule(): int {
    $page = $this->getPage();
    $before = $this->countRuleRows();
    $page->pressButton('Add rule');
    $this->jsAssertSession()->assertWaitOnAjaxRequest();
    $after = $this->countRuleRows();
    $this->assertGreaterThan($before, $after, 'A new row should have appeared after "Add rule".');
    return $after - 1;
  }

  /**
   * Fills in source_prefix and destination for the given row index.
   *
   * @param int $rowIndex
   *   Zero-based row index.
   * @param string $prefix
   *   Value for the source_prefix field.
   * @param string $destination
   *   Value for the destination field.
   */
  private function fillRule(int $rowIndex, string $prefix, string $destination): void {
    $page = $this->getPage();
    $page->fillField("path_rules[table][{$rowIndex}][source_prefix]", $prefix);
    $page->fillField("path_rules[table][{$rowIndex}][destination]", $destination);
  }

  /**
   * Clicks the "Remove" button for the given row and waits for AJAX.
   *
   * @param int $rowIndex
   *   Zero-based row index matching the remove button name remove_rule_N.
   */
  private function clickRemoveRule(int $rowIndex): void {
    $page = $this->getPage();
    $button = $page->findButton('remove_rule_' . $rowIndex);
    $this->assertNotNull($button, "Remove button for row {$rowIndex} must exist.");
    $button->click();
    $this->jsAssertSession()->assertWaitOnAjaxRequest();
  }

  /**
   * Returns the number of path-rule rows currently visible in the table.
   *
   * Counts source_prefix fields since every rule row has exactly one.
   */
  private function countRuleRows(): int {
    $page = $this->getPage();
    $fields = $page->findAll('css', 'input[name^="path_rules[table]"][name$="[source_prefix]"]');
    return count($fields);
  }

  /**
   * Returns the value of source_prefix for the given displayed row index.
   *
   * After an AJAX rebuild the row indices in the DOM correspond to the
   * re-indexed form state, so passing the sequential display index (0, 1, …)
   * is correct.
   *
   * @param int $rowIndex
   *   Zero-based sequential index as rebuilt by the form.
   */
  private function getPrefixValue(int $rowIndex): string {
    $field = $this->getPage()->findField("path_rules[table][{$rowIndex}][source_prefix]");
    $this->assertNotNull($field, "source_prefix field for row {$rowIndex} must exist.");
    $value = $field->getValue();
    $this->assertIsString($value, "source_prefix field value for row {$rowIndex} must be a string.");
    return $value;
  }

  /**
   * Returns the value of destination for the given displayed row index.
   */
  private function getDestinationValue(int $rowIndex): string {
    $field = $this->getPage()->findField("path_rules[table][{$rowIndex}][destination]");
    $this->assertNotNull($field, "destination field for row {$rowIndex} must exist.");
    $value = $field->getValue();
    $this->assertIsString($value, "destination field value for row {$rowIndex} must be a string.");
    return $value;
  }

  // ---------------------------------------------------------------------------
  // Tests
  // ---------------------------------------------------------------------------

  /**
   * Tests adding two rules and removing each one in turn.
   *
   * Scenario:
   *   1. Open the form (no rules).
   *   2. Add rule A (/news/ → /archive/news).
   *   3. Add rule B (/events/ → /archive/events).
   *   4. Remove rule A (row 0).
   *   5. Assert rule B is now row 0.
   *   6. Remove rule B (now row 0).
   *   7. Assert no rules remain.
   */
  public function testAddTwoRulesThenRemoveEachInTurn(): void {
    $this->openSettingsForm();
    $this->assertEquals(0, $this->countRuleRows(), 'No rules should be present initially.');

    // Add rule A.
    $rowA = $this->clickAddRule();
    $this->fillRule($rowA, '/news/', '/archive/news');

    // Add rule B.
    $rowB = $this->clickAddRule();
    $this->fillRule($rowB, '/events/', '/archive/events');

    $this->assertEquals(2, $this->countRuleRows(), 'Two rows should exist after adding two rules.');

    // Remove rule A (row 0).
    $this->clickRemoveRule(0);

    $this->assertEquals(1, $this->countRuleRows(), 'One row should remain after removing rule A.');
    $this->assertEquals('/events/', $this->getPrefixValue(0), 'Rule B prefix should now be at row 0.');
    $this->assertEquals('/archive/events', $this->getDestinationValue(0), 'Rule B destination should now be at row 0.');

    // Remove rule B (now at row 0).
    $this->clickRemoveRule(0);

    $this->assertEquals(0, $this->countRuleRows(), 'No rows should remain after removing both rules.');
  }

  /**
   * Tests that removing a newly added row does not disturb existing rows.
   *
   * Scenario:
   *   1. Open the form (no rules).
   *   2. Add rule A (/research/ → /archive/research).
   *   3. Add rule B (/grants/ → /archive/grants).
   *   4. Click "Add rule" to create an empty rule C (row 2).
   *   5. Click "Remove" on the empty rule C.
   *   6. Assert that rules A and B are unchanged.
   */
  public function testRemoveNewRowDoesNotDisturbExistingRows(): void {
    $this->openSettingsForm();
    $this->assertEquals(0, $this->countRuleRows(), 'No rules should be present initially.');

    // Add rule A.
    $rowA = $this->clickAddRule();
    $this->fillRule($rowA, '/research/', '/archive/research');

    // Add rule B.
    $rowB = $this->clickAddRule();
    $this->fillRule($rowB, '/grants/', '/archive/grants');

    $this->assertEquals(2, $this->countRuleRows(), 'Two rows should exist before adding the temporary row.');

    // Add an empty rule C that will be removed immediately.
    $rowC = $this->clickAddRule();
    $this->assertEquals(3, $this->countRuleRows(), 'Three rows should exist after adding the temporary row.');

    // Remove rule C (empty row).
    $this->clickRemoveRule($rowC);

    $this->assertEquals(2, $this->countRuleRows(), 'Two rows should remain after removing the temporary row.');

    // Rules A and B must be intact in their original order.
    $this->assertEquals('/research/', $this->getPrefixValue(0), 'Rule A prefix must be unchanged at row 0.');
    $this->assertEquals('/archive/research', $this->getDestinationValue(0), 'Rule A destination must be unchanged at row 0.');
    $this->assertEquals('/grants/', $this->getPrefixValue(1), 'Rule B prefix must be unchanged at row 1.');
    $this->assertEquals('/archive/grants', $this->getDestinationValue(1), 'Rule B destination must be unchanged at row 1.');
  }

  /**
   * Tests that saved rules survive a page reload and can still be removed.
   *
   * Destinations use '<front>' so they pass the form's path validator in any
   * Drupal test environment without requiring custom routes to be registered.
   *
   * Scenario:
   *   1. Set two rules in config directly (both with destination '<front>').
   *   2. Open the form – both rows must appear pre-populated.
   *   3. Remove the second rule.
   *   4. Save the form.
   *   5. Assert config now contains only one rule.
   */
  public function testSavedRulesCanBeRemoved(): void {
    // Seed the configuration with two rules.
    // '<front>' is always a valid destination per the form's validateForm()
    // so form submission will not be blocked by the path validator.
    $this->config('nhmrc_archive_redirect.settings')
      ->set('delete_path_rules', [
        ['source_prefix' => '/policy/', 'destination' => '<front>'],
        ['source_prefix' => '/funding/', 'destination' => '<front>'],
      ])
      ->save();

    $this->openSettingsForm();
    $this->assertEquals(2, $this->countRuleRows(), 'Both seeded rules must appear in the form.');

    // Verify pre-populated values.
    $this->assertEquals('/policy/', $this->getPrefixValue(0));
    $this->assertEquals('<front>', $this->getDestinationValue(0));
    $this->assertEquals('/funding/', $this->getPrefixValue(1));
    $this->assertEquals('<front>', $this->getDestinationValue(1));

    // Remove the second rule (row 1).
    $this->clickRemoveRule(1);

    $this->assertEquals(1, $this->countRuleRows(), 'One rule should remain after removing the second.');
    $this->assertEquals('/policy/', $this->getPrefixValue(0), 'First rule prefix must be intact.');
    $this->assertEquals('<front>', $this->getDestinationValue(0), 'First rule destination must be intact.');

    // Save the form and verify config is persisted correctly.
    // Prefix '/policy/' is valid (starts with '/'); destination '<front>' is
    // always accepted by the validator, so no validation errors should appear.
    $this->getPage()->pressButton('Save configuration');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextNotContains('error has been found');

    $saved = $this->config('nhmrc_archive_redirect.settings')->get('delete_path_rules');
    $this->assertCount(1, $saved, 'Config must contain exactly one rule after save.');
    $this->assertEquals('/policy/', $saved[0]['source_prefix']);
    $this->assertEquals('<front>', $saved[0]['destination']);
  }

  /**
   * Tests the preview token parameter name field validation and persistence.
   *
   * Scenario:
   *   1. Open the form and verify the field renders with the default 'auNHMRC'.
   *   2. Save a custom valid value ('myToken') and confirm it persists in config.
   *   3. Save the wildcard value ('*') and confirm it is accepted.
   *   4. Save a blank value and confirm it is accepted.
   *   5. Try each invalid character (=, &, #, space) and confirm a validation
   *      error appears for each.
   */
  public function testPreviewTokenParamFieldValidationAndPersistence(): void {
    $this->openSettingsForm();

    // 1. Default value must be 'auNHMRC'.
    $field = $this->getPage()->findField('behaviour[preview_token_param]');
    $this->assertNotNull($field, 'The preview token parameter field must be present.');
    $this->assertEquals('auNHMRC', $field->getValue(), 'The field must default to "auNHMRC".');

    // 2. Save a custom valid value and confirm it persists.
    $field->setValue('myToken');
    $this->getPage()->pressButton('Save configuration');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextNotContains('error has been found');
    $this->assertEquals(
      'myToken',
      $this->config('nhmrc_archive_redirect.settings')->get('preview_token_param'),
      'Custom valid value must be persisted to config.'
    );

    // 3. Wildcard '*' must be accepted.
    $this->openSettingsForm();
    $this->getPage()->fillField('behaviour[preview_token_param]', '*');
    $this->getPage()->pressButton('Save configuration');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextNotContains('error has been found');
    $this->assertEquals(
      '*',
      $this->config('nhmrc_archive_redirect.settings')->get('preview_token_param'),
      'Wildcard "*" must be persisted to config.'
    );

    // 4. Blank value must be accepted.
    $this->openSettingsForm();
    $this->getPage()->fillField('behaviour[preview_token_param]', '');
    $this->getPage()->pressButton('Save configuration');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextNotContains('error has been found');
    $this->assertEquals(
      '',
      $this->config('nhmrc_archive_redirect.settings')->get('preview_token_param'),
      'Blank value must be persisted to config.'
    );

    // 5. Invalid characters must trigger a validation error.
    $invalidValues = ['bad=value', 'bad&value', 'bad#value', 'bad value'];
    foreach ($invalidValues as $invalidValue) {
      $this->openSettingsForm();
      $this->getPage()->fillField('behaviour[preview_token_param]', $invalidValue);
      $this->getPage()->pressButton('Save configuration');
      $this->assertSession()->statusCodeEquals(200);
      $this->assertSession()->pageTextContains('The parameter name may not contain');
    }
  }

}
