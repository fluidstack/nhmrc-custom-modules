# NHMRC Archive Redirect - Test Suite

This document describes all tests within the module and how to run them.

---

## Table of Contents

1. [Standalone Verification Script](#standalone-verification-script)
2. [Unit Tests](#unit-tests)
3. [Functional Tests](#functional-tests)
4. [FunctionalJavascript Tests](#functionaljavascript-tests)
5. [Running All Tests](#running-all-tests)

---

## Standalone Verification Script

**File:** `tests/verify_remove_logic.php`

A self-contained PHP script that verifies the `removePathRule` fix without requiring Drupal or PHPUnit. It extracts the core removal logic and runs assertions against it.

### How to run

```bash
php tests/verify_remove_logic.php
```

### Requirements

- PHP 8.1+

### What it tests

| Test Case | Description |
|-----------|-------------|
| Remove middle row | Removes index 1 from 3 rows, verifies rows 0 and 2 remain re-indexed as 0 and 1 |
| Remove first row | Removes index 0 from 3 rows, verifies rows 1 and 2 shift down |
| Remove last row | Removes index 2 from 3 rows, verifies rows 0 and 1 remain unchanged |
| Remove only row | Removes the sole row, verifies empty result |
| Invalid button name | Passes a non-numeric suffix, verifies nothing is removed |

Each test also asserts that the raw user input array is synchronised (the fix under test).

---

## Unit Tests

**File:** `tests/src/Unit/RemovePathRuleTest.php`

PHPUnit-based unit tests that mock `FormStateInterface` and verify the `removePathRule` method on the real `ArchiveRedirectSettingsForm` class.

### How to run

```bash
cd web/core
../vendor/bin/phpunit ../modules/custom/nhmrc_archive_redirect/tests/src/Unit/RemovePathRuleTest.php
```

### Requirements

- Full Drupal 10 project with Composer dependencies installed
- Module placed at `web/modules/custom/nhmrc_archive_redirect/`

### Test methods

| Method | Description |
|--------|-------------|
| `testRemoveMiddleRow` | Removes row 1 from 3 rows; asserts correct rules and synchronised user input |
| `testRemoveFirstRow` | Removes row 0 from 3 rows; asserts remaining rows shift correctly |
| `testRemoveLastRow` | Removes row 2 from 3 rows; asserts first two rows remain |
| `testRemoveOnlyRow` | Removes the sole row; asserts empty state |
| `testInvalidTriggeringElementRemovesNothing` | Non-digit suffix in button name; asserts all rows preserved |

---

## Functional Tests

**File:** `tests/src/Functional/ArchiveRedirectBehaviorTest.php`

Browser-based tests (no JavaScript) that verify the redirect behaviour of `ArchivedNodeRedirectSubscriber` and `DeletedPageRedirectSubscriber`.

### How to run

```bash
cd web/core
../vendor/bin/phpunit ../modules/custom/nhmrc_archive_redirect/tests/src/Functional/ArchiveRedirectBehaviorTest.php
```

### Requirements

- Full Drupal 10 project with Composer dependencies installed
- `phpunit.xml` configured with:
  - `SIMPLETEST_DB` (e.g. `sqlite://localhost/sites/default/files/.ht.sqlite`)
  - `SIMPLETEST_BASE_URL` (e.g. `http://localhost:8888`)

### Test methods

| Method | Description |
|--------|-------------|
| `testAnonymousVisitToUnpublishedAliasUrlRedirects` | Anonymous visits alias of unpublished node matching a prefix rule; expects redirect |
| `testAnonymousVisitViaNodePathWithAliasPrefixRuleRedirects` | Anonymous visits /node/{nid}; alias lookup matches prefix rule; expects redirect |
| `testNodePathAndAliasPathRedirectToSameDestination` | Visiting via /node/{nid} and via alias produce same redirect destination |
| `testEditorWithBypassPermissionIsNotRedirected` | Editor with "bypass node access" and bypass_for_editors=TRUE is not redirected |
| `testEditorIsRedirectedWhenBypassIsDisabled` | Editor is redirected when bypass_for_editors=FALSE regardless of permissions |
| `testAnonymousVisitWithAuNhmrcTokenIsNotRedirected` | Request with auNHMRC query parameter bypasses the redirect (gets 403 instead) |
| `testCustomPreviewTokenParamBypasses` | Custom preview_token_param name works; old default name no longer bypasses |
| `testWildcardBypassesAllRequests` | preview_token_param=* disables all redirects for all requests |
| `testBlankPreviewTokenParamDisablesBypass` | preview_token_param='' disables the bypass entirely; redirects always fire |
| `testDeletedPageStoredRedirectBypassedByAuNhmrcToken` | Stored deleted-page redirect is bypassed when token is present |
| `testDeletedPageWildcardParamBypasses` | Stored deleted-page redirect is bypassed with wildcard param |
| `testDeletedPageBlankParamDoesNotBypass` | Stored deleted-page redirect fires when param is blank |
| `testPublishedNodeIsNotRedirected` | Published node is never redirected even with matching prefix rule |

---

## FunctionalJavascript Tests

**File:** `tests/src/FunctionalJavascript/ArchiveRedirectSettingsFormTest.php`

WebDriver-based tests that exercise the AJAX Add/Remove behaviour on the admin settings form through a real browser.

### How to run

```bash
# Start ChromeDriver first:
chromedriver --port=9515

# Then run the test:
cd web/core
../vendor/bin/phpunit ../modules/custom/nhmrc_archive_redirect/tests/src/FunctionalJavascript/ArchiveRedirectSettingsFormTest.php
```

### Requirements

- Full Drupal 10 project with Composer dependencies installed
- `phpunit.xml` configured with `SIMPLETEST_DB` and `SIMPLETEST_BASE_URL`
- ChromeDriver (or Selenium with Chrome) running on port 9515
- `MINK_DRIVER_ARGS_WEBDRIVER` set in `phpunit.xml` (e.g. `'["chrome", {"browserName":"chrome","goog:chromeOptions":{"args":["--headless","--no-sandbox"]}}, "http://localhost:9515"]'`)

### Test methods

| Method | Description |
|--------|-------------|
| `testAddTwoRulesThenRemoveEachInTurn` | Adds two rules via AJAX, removes row 0, verifies row 1 shifts to row 0, then removes remaining row |
| `testRemoveNewRowDoesNotDisturbExistingRows` | Adds two rules plus a temporary third, removes the third, verifies original two are intact |
| `testSavedRulesCanBeRemoved` | Seeds config with two rules, opens form, removes one, saves, verifies config persists correctly |
| `testPreviewTokenParamFieldValidationAndPersistence` | Tests the preview token parameter field: default value, custom valid values, wildcard, blank, and invalid characters |

---

## Running All Tests

To run the entire test suite at once (requires full Drupal + ChromeDriver):

```bash
cd web/core
../vendor/bin/phpunit --group nhmrc_archive_redirect
```

This runs all tests tagged with `@group nhmrc_archive_redirect` across Unit, Functional, and FunctionalJavascript categories.

---

## PHPUnit Configuration

Copy and configure `phpunit.xml` from the Drupal core template:

```bash
cd web/core
cp phpunit.xml.dist phpunit.xml
```

Then edit `phpunit.xml` and set the following environment variables:

```xml
<env name="SIMPLETEST_DB" value="sqlite://localhost/sites/default/files/.ht.sqlite"/>
<env name="SIMPLETEST_BASE_URL" value="http://localhost:8888"/>
<env name="MINK_DRIVER_ARGS_WEBDRIVER" value='["chrome", {"browserName":"chrome","goog:chromeOptions":{"args":["--headless","--no-sandbox"]}}, "http://localhost:9515"]'/>
```
