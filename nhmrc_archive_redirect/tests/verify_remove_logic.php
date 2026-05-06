<?php

/**
 * @file
 * Standalone verification script for the removePathRule fix.
 *
 * Simulates the removal logic extracted from ArchiveRedirectSettingsForm and
 * asserts that the correct row is removed and user input is synchronised.
 *
 * Run with: php tests/verify_remove_logic.php
 */

$_nhmrc_archive_redirect_results = ['pass' => 0, 'fail' => 0];

/**
 * Asserts that two values are strictly equal, tracking pass/fail counts.
 *
 * @param mixed $expected
 *   The expected value.
 * @param mixed $actual
 *   The actual value to compare.
 * @param string $message
 *   A description of the assertion.
 */
function assert_equals($expected, $actual, string $message): void {
  global $_nhmrc_archive_redirect_results;
  if ($expected === $actual) {
    $_nhmrc_archive_redirect_results['pass']++;
  }
  else {
    $_nhmrc_archive_redirect_results['fail']++;
    echo "FAIL: {$message}\n";
    echo "  Expected: " . var_export($expected, TRUE) . "\n";
    echo "  Actual:   " . var_export($actual, TRUE) . "\n";
  }
}

/**
 * Simulates the fixed removePathRule logic.
 *
 * @param array $raw
 *   Simulated getUserInput() return (modified by reference as setUserInput would).
 * @param string $buttonName
 *   The _triggering_element_name value.
 *
 * @return array
 *   The resulting rules array (what would be stored in form_state 'path_rules').
 */
function simulate_remove(array &$raw, string $buttonName): array {
  $prefix = 'remove_rule_';
  $suffix = str_starts_with($buttonName, $prefix)
    ? substr($buttonName, strlen($prefix))
    : '';
  $row_key = (strlen($suffix) > 0 && ctype_digit($suffix))
    ? (int) $suffix
    : -1;
  $input = (is_array($raw['path_rules']['table'] ?? NULL))
    ? $raw['path_rules']['table']
    : [];
  if ($row_key >= 0) {
    unset($input[$row_key]);
  }
  $input = array_values($input);

  // Extract rules.
  $rules = [];
  foreach ($input as $row) {
    $rules[] = [
      'source_prefix' => $row['source_prefix'] ?? '',
      'destination'   => $row['destination'] ?? '',
    ];
  }

  // Synchronise raw user input (the fix).
  $raw['path_rules']['table'] = $input;

  return $rules;
}

// ---- Test: Remove middle row (index 1) from 3 rows ----
echo "Test: Remove middle row (index 1) from 3 rows\n";
$raw = [
  '_triggering_element_name' => 'remove_rule_1',
  'path_rules' => [
    'table' => [
      0 => ['source_prefix' => '/news/', 'destination' => '/archive/news'],
      1 => ['source_prefix' => '/events/', 'destination' => '/archive/events'],
      2 => ['source_prefix' => '/grants/', 'destination' => '/archive/grants'],
    ],
  ],
];
$rules = simulate_remove($raw, 'remove_rule_1');
assert_equals(2, count($rules), 'Two rules remain');
assert_equals('/news/', $rules[0]['source_prefix'], 'First rule prefix is /news/');
assert_equals('/archive/news', $rules[0]['destination'], 'First rule destination');
assert_equals('/grants/', $rules[1]['source_prefix'], 'Second rule prefix is /grants/');
assert_equals('/archive/grants', $rules[1]['destination'], 'Second rule destination');
// Verify user input synchronised.
assert_equals(2, count($raw['path_rules']['table']), 'User input has 2 rows');
assert_equals('/news/', $raw['path_rules']['table'][0]['source_prefix'], 'User input row 0 prefix');
assert_equals('/grants/', $raw['path_rules']['table'][1]['source_prefix'], 'User input row 1 prefix');

// ---- Test: Remove first row (index 0) from 3 rows ----
echo "Test: Remove first row (index 0) from 3 rows\n";
$raw = [
  '_triggering_element_name' => 'remove_rule_0',
  'path_rules' => [
    'table' => [
      0 => ['source_prefix' => '/news/', 'destination' => '/archive/news'],
      1 => ['source_prefix' => '/events/', 'destination' => '/archive/events'],
      2 => ['source_prefix' => '/grants/', 'destination' => '/archive/grants'],
    ],
  ],
];
$rules = simulate_remove($raw, 'remove_rule_0');
assert_equals(2, count($rules), 'Two rules remain');
assert_equals('/events/', $rules[0]['source_prefix'], 'First rule is /events/');
assert_equals('/grants/', $rules[1]['source_prefix'], 'Second rule is /grants/');
assert_equals('/events/', $raw['path_rules']['table'][0]['source_prefix'], 'User input row 0');
assert_equals('/grants/', $raw['path_rules']['table'][1]['source_prefix'], 'User input row 1');

// ---- Test: Remove last row (index 2) from 3 rows ----
echo "Test: Remove last row (index 2) from 3 rows\n";
$raw = [
  '_triggering_element_name' => 'remove_rule_2',
  'path_rules' => [
    'table' => [
      0 => ['source_prefix' => '/news/', 'destination' => '/archive/news'],
      1 => ['source_prefix' => '/events/', 'destination' => '/archive/events'],
      2 => ['source_prefix' => '/grants/', 'destination' => '/archive/grants'],
    ],
  ],
];
$rules = simulate_remove($raw, 'remove_rule_2');
assert_equals(2, count($rules), 'Two rules remain');
assert_equals('/news/', $rules[0]['source_prefix'], 'First rule is /news/');
assert_equals('/events/', $rules[1]['source_prefix'], 'Second rule is /events/');
assert_equals('/news/', $raw['path_rules']['table'][0]['source_prefix'], 'User input row 0');
assert_equals('/events/', $raw['path_rules']['table'][1]['source_prefix'], 'User input row 1');

// ---- Test: Remove the only row ----
echo "Test: Remove the only row\n";
$raw = [
  '_triggering_element_name' => 'remove_rule_0',
  'path_rules' => [
    'table' => [
      0 => ['source_prefix' => '/news/', 'destination' => '/archive/news'],
    ],
  ],
];
$rules = simulate_remove($raw, 'remove_rule_0');
assert_equals(0, count($rules), 'No rules remain');
assert_equals(0, count($raw['path_rules']['table']), 'User input is empty');

// ---- Test: Invalid button name removes nothing ----
echo "Test: Invalid button name removes nothing\n";
$raw = [
  '_triggering_element_name' => 'remove_rule_abc',
  'path_rules' => [
    'table' => [
      0 => ['source_prefix' => '/news/', 'destination' => '/archive/news'],
      1 => ['source_prefix' => '/events/', 'destination' => '/archive/events'],
    ],
  ],
];
$rules = simulate_remove($raw, 'remove_rule_abc');
assert_equals(2, count($rules), 'Both rules remain with invalid button name');
assert_equals('/news/', $rules[0]['source_prefix'], 'First rule unchanged');
assert_equals('/events/', $rules[1]['source_prefix'], 'Second rule unchanged');

// ---- Summary ----
echo "\n";
echo "Results: {$_nhmrc_archive_redirect_results['pass']} passed, {$_nhmrc_archive_redirect_results['fail']} failed\n";
exit($_nhmrc_archive_redirect_results['fail'] > 0 ? 1 : 0);
