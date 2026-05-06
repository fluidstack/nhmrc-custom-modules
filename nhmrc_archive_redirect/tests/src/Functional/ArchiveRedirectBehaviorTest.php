<?php

namespace Drupal\Tests\nhmrc_archive_redirect\Functional;

use Drupal\Core\Session\AccountInterface;
use Drupal\node\Entity\Node;
use Drupal\node\NodeInterface;
use Drupal\path_alias\Entity\PathAlias;
use Drupal\Tests\BrowserTestBase;
use Drupal\user\RoleInterface;

/**
 * Tests the redirect behaviour of ArchivedNodeRedirectSubscriber.
 *
 * Covers the regression-prone scenarios identified in the task:
 *  1. Anonymous visit to an unpublished node whose alias matches a path-prefix
 *     rule → subscriber intercepts the 403 via onException() and redirects.
 *  2. Anonymous visit via /node/{nid} when there is no direct prefix match but
 *     the node's URL alias does match a rule → same destination as alias visit.
 *  3. Sanity check: a published node is never redirected.
 *  4. Editor with "bypass node access" and bypass_for_editors=TRUE can view
 *     an unpublished node without being redirected.
 *  5. Editor with "bypass node access" is still redirected when
 *     bypass_for_editors=FALSE, because the global flag takes precedence.
 *
 * Tests 1–3 run as the anonymous user (no drupalLogin) so that the
 * onException() code path is exercised (Drupal's AccessAwareRouter throws
 * before the lower-priority REQUEST listener fires for anonymous visitors of
 * unpublished content). Tests 4–5 log in as a privileged editor and exercise
 * the onRequest() code path.
 *
 * @group nhmrc_archive_redirect
 */
class ArchiveRedirectBehaviorTest extends BrowserTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'node',
    'path_alias',
    'nhmrc_archive_redirect',
  ];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->drupalCreateContentType(['type' => 'page', 'name' => 'Basic page']);

    // Allow anonymous users to read published content so that the
    // "published node — no redirect" sanity-check test can reach the node page.
    user_role_grant_permissions(RoleInterface::ANONYMOUS_ID, ['access content']);
  }

  // ---------------------------------------------------------------------------
  // Helper methods
  // ---------------------------------------------------------------------------

  /**
   * Writes path-prefix rules into the module's active configuration.
   *
   * Also forces bypass_for_editors to FALSE so that no permission check can
   * accidentally skip the redirect, regardless of how the test account is set
   * up. (For anonymous users this is moot, but it makes the intent explicit.)
   *
   * @param array $rules
   *   List of ['source_prefix' => ..., 'destination' => ...] arrays.
   */
  private function setPathPrefixRules(array $rules): void {
    $this->config('nhmrc_archive_redirect.settings')
      ->set('delete_path_rules', $rules)
      ->set('bypass_for_editors', FALSE)
      ->save();
  }

  /**
   * Creates an unpublished node and, optionally, a URL alias for it.
   *
   * @param string|null $alias
   *   The alias to register (e.g. '/funding/find-funding/test-grant'), or NULL
   *   if no alias should be created.
   *
   * @return \Drupal\node\NodeInterface
   *   The saved, unpublished node.
   */
  private function createUnpublishedNode(?string $alias = NULL): NodeInterface {
    $node = $this->drupalCreateNode([
      'type'   => 'page',
      'status' => Node::NOT_PUBLISHED,
      'title'  => 'Unpublished Test Page',
    ]);

    if ($alias !== NULL) {
      PathAlias::create([
        'path'     => '/node/' . $node->id(),
        'alias'    => $alias,
        'langcode' => 'en',
      ])->save();
    }

    return $node;
  }

  // ---------------------------------------------------------------------------
  // Tests
  // ---------------------------------------------------------------------------

  /**
   * Anonymous visit to an unpublished node's alias URL triggers a redirect.
   *
   * Scenario:
   *   - Node has alias /funding/find-funding/test-grant.
   *   - Rule: source_prefix=/funding/ → destination=/ (front page).
   *   - Anonymous user visits /funding/find-funding/test-grant.
   *   Expected: redirect to /, ending on the front page (HTTP 200).
   *
   * This exercises the onException() code path: Drupal's access check throws
   * before any REQUEST listener fires for anonymous visitors.
   */
  public function testAnonymousVisitToUnpublishedAliasUrlRedirects(): void {
    $this->createUnpublishedNode('/funding/find-funding/test-grant');

    $this->setPathPrefixRules([
      ['source_prefix' => '/funding/', 'destination' => '/'],
    ]);

    // Visit the alias URL as anonymous. BrowserTestBase follows redirects, so
    // after the 302 the session ends on the destination page.
    $this->drupalGet('/funding/find-funding/test-grant');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->addressEquals('/');
  }

  /**
   * Visiting via /node/{nid} matches an alias-based prefix rule and redirects.
   *
   * Scenario:
   *   - Node has alias /funding/find-funding/test-grant-nid.
   *   - Rule: source_prefix=/funding/ → destination=/ (front page).
   *   - Anonymous user visits /node/{nid} (no /node/ prefix rule exists).
   *   Expected: redirect to / because the alias-lookup path kicks in.
   *
   * This is the regression-prone alias-lookup path described in the task:
   * buildRedirectResponse() appends the alias to the candidate paths list when
   * the raw request path differs from the alias, so the /funding/ rule fires
   * even though the visitor used /node/{nid}.
   */
  public function testAnonymousVisitViaNodePathWithAliasPrefixRuleRedirects(): void {
    $node = $this->createUnpublishedNode('/funding/find-funding/test-grant-nid');

    $this->setPathPrefixRules([
      ['source_prefix' => '/funding/', 'destination' => '/'],
    ]);

    // Visit /node/{nid}. The /node/ prefix does not appear in the rules, but
    // the subscriber should look up the alias and match /funding/.
    $this->drupalGet('/node/' . $node->id());
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->addressEquals('/');
  }

  /**
   * Both /node/{nid} and the alias URL redirect to the same destination.
   *
   * This asserts the equivalence property: visiting via /node/{nid} produces
   * the same outcome as visiting via the alias URL directly.
   */
  public function testNodePathAndAliasPathRedirectToSameDestination(): void {
    $node = $this->createUnpublishedNode('/research/programs/test-project');

    $this->setPathPrefixRules([
      ['source_prefix' => '/research/', 'destination' => '/'],
    ]);

    // Alias visit.
    $this->drupalGet('/research/programs/test-project');
    $aliasDestination = $this->getSession()->getCurrentUrl();

    // /node/{nid} visit.
    $this->drupalGet('/node/' . $node->id());
    $nidDestination = $this->getSession()->getCurrentUrl();

    $this->assertSame(
      $aliasDestination,
      $nidDestination,
      'Visiting via /node/{nid} must redirect to the same destination as visiting via the alias URL.'
    );
  }

  /**
   * Editor with bypass permission views an unpublished node without redirect.
   *
   * Scenario:
   *   - bypass_for_editors is TRUE.
   *   - A matching path-prefix rule exists for /funding/.
   *   - The editor holds "bypass node access", which grants both node-view
   *     access and the redirect bypass in userCanBypass().
   *   - Editor visits /node/{nid}.
   *   Expected: 200 on the node canonical URL — the subscriber returns early
   *   in onRequest() because userCanBypass() is TRUE.
   *
   * This exercises the onRequest() code path (not onException()) because
   * a user with "bypass node access" can load the node without triggering an
   * access-denied exception.
   */
  public function testEditorWithBypassPermissionIsNotRedirected(): void {
    $node = $this->createUnpublishedNode('/funding/find-funding/editor-bypass-test');

    $this->config('nhmrc_archive_redirect.settings')
      ->set('delete_path_rules', [
        ['source_prefix' => '/funding/', 'destination' => '/'],
      ])
      ->set('bypass_for_editors', TRUE)
      ->save();

    // "bypass node access" grants node-view access for unpublished nodes AND
    // satisfies the userCanBypass() permission check.
    $editor = $this->drupalCreateUser(['bypass node access']);
    assert($editor instanceof AccountInterface, 'drupalCreateUser() must return a user.');
    $this->drupalLogin($editor);

    $this->drupalGet('/node/' . $node->id());
    $this->assertSession()->statusCodeEquals(200);
    // Must still be on the node canonical URL — no redirect fired.
    $this->assertSession()->addressEquals('/node/' . $node->id());
  }

  /**
   * Redirect fires for all users when bypass_for_editors is globally disabled.
   *
   * Scenario:
   *   - bypass_for_editors is FALSE (set explicitly via setPathPrefixRules()).
   *   - A matching path-prefix rule exists for /funding/.
   *   - The editor holds "bypass node access".
   *   - Editor visits /node/{nid}.
   *   Expected: redirect to the configured destination, because the global
   *   bypass_for_editors=FALSE flag causes userCanBypass() to return FALSE
   *   regardless of the user's individual permissions.
   *
   * This is the complementary regression guard: verifying that disabling
   * bypass_for_editors in config re-enables the redirect even for privileged
   * editors.
   */
  public function testEditorIsRedirectedWhenBypassIsDisabled(): void {
    $node = $this->createUnpublishedNode('/funding/find-funding/bypass-disabled-test');

    // setPathPrefixRules() explicitly sets bypass_for_editors to FALSE.
    $this->setPathPrefixRules([
      ['source_prefix' => '/funding/', 'destination' => '/'],
    ]);

    $editor = $this->drupalCreateUser(['bypass node access']);
    assert($editor instanceof AccountInterface, 'drupalCreateUser() must return a user.');
    $this->drupalLogin($editor);

    $this->drupalGet('/node/' . $node->id());
    $this->assertSession()->statusCodeEquals(200);
    // Must have been redirected to the destination — not remain on the node URL.
    $this->assertSession()->addressEquals('/');
  }

  /**
   * Anonymous visit with the auNHMRC token is not redirected.
   *
   * Scenario:
   *   - Node has alias /funding/find-funding/token-bypass-test.
   *   - Rule: source_prefix=/funding/ → destination=/ (front page).
   *   - Anonymous user visits the alias URL with ?auNHMRC=<token>.
   *   Expected: the request passes through (Drupal serves a 403 or whatever
   *   its normal access handling produces) — critically it must NOT be
   *   redirected to the configured destination (/), so the final URL still
   *   contains /funding/ rather than /.
   *
   * In BrowserTestBase the full Drupal stack runs, so an anonymous user
   * visiting an unpublished node will normally receive a 403 (access denied).
   * What we are asserting is that the module's redirect does NOT fire — the
   * subscriber returns early on the auNHMRC token — leaving normal Drupal
   * access handling to produce whatever response it would produce (403 here).
   */
  public function testAnonymousVisitWithAuNhmrcTokenIsNotRedirected(): void {
    $this->createUnpublishedNode('/funding/find-funding/token-bypass-test');

    $this->setPathPrefixRules([
      ['source_prefix' => '/funding/', 'destination' => '/'],
    ]);

    // Visit the alias URL with the auNHMRC preview token as anonymous.
    // BrowserTestBase follows redirects; if the module fires a redirect to /,
    // the final URL would be / and the status code would be 200. A 403 (no
    // redirect) means the test passes.
    $this->drupalGet('/funding/find-funding/token-bypass-test', [
      'query' => ['auNHMRC' => 'wdU-ujHIO_DkXUS39Mao24WAdu-mcBLbZrI9ik6Ll8Q'],
    ]);

    // The subscriber must not have issued a redirect to /. Drupal returns 403
    // for anonymous access to unpublished content when no redirect fires.
    $this->assertSession()->statusCodeEquals(403);

    // The session must not have been redirected to the configured destination.
    $this->assertSession()->addressNotEquals('/');
  }

  /**
   * A custom parameter name configured in settings bypasses the redirect.
   *
   * Scenario:
   *   - preview_token_param is set to 'previewKey' (not the default 'auNHMRC').
   *   - Unpublished node has alias /funding/token-custom-test.
   *   - Rule: source_prefix=/funding/ → destination=/.
   *   - Anonymous user visits the alias with ?previewKey=abc → expects 403 (no redirect).
   *   - Same visit with ?auNHMRC=abc (the old default) → expects 200 at / (redirect fires).
   */
  public function testCustomPreviewTokenParamBypasses(): void {
    $this->createUnpublishedNode('/funding/token-custom-test');

    $this->setPathPrefixRules([
      ['source_prefix' => '/funding/', 'destination' => '/'],
    ]);

    $this->config('nhmrc_archive_redirect.settings')
      ->set('preview_token_param', 'previewKey')
      ->save();

    // Custom parameter name bypasses the redirect.
    $this->drupalGet('/funding/token-custom-test', [
      'query' => ['previewKey' => 'sometoken'],
    ]);
    $this->assertSession()->statusCodeEquals(403);
    $this->assertSession()->addressNotEquals('/');

    // Old default parameter no longer bypasses; redirect fires normally.
    $this->drupalGet('/funding/token-custom-test', [
      'query' => ['auNHMRC' => 'sometoken'],
    ]);
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->addressEquals('/');
  }

  /**
   * The asterisk wildcard bypasses the redirect for all requests.
   *
   * Scenario:
   *   - preview_token_param is set to '*'.
   *   - Unpublished node has alias /funding/token-wildcard-test.
   *   - Rule: source_prefix=/funding/ → destination=/.
   *   - Anonymous user visits the alias with no query parameters at all.
   *   Expected: 403 (no redirect) because the wildcard mode bypasses before
   *   Drupal can redirect — Drupal still enforces access, yielding 403.
   */
  public function testWildcardBypassesAllRequests(): void {
    $this->createUnpublishedNode('/funding/token-wildcard-test');

    $this->setPathPrefixRules([
      ['source_prefix' => '/funding/', 'destination' => '/'],
    ]);

    $this->config('nhmrc_archive_redirect.settings')
      ->set('preview_token_param', '*')
      ->save();

    // No query parameters at all — wildcard means the redirect never fires.
    $this->drupalGet('/funding/token-wildcard-test');
    $this->assertSession()->statusCodeEquals(403);
    $this->assertSession()->addressNotEquals('/');
  }

  /**
   * A blank preview_token_param disables the token bypass entirely.
   *
   * Scenario:
   *   - preview_token_param is set to '' (empty string).
   *   - Unpublished node has alias /funding/token-blank-test.
   *   - Rule: source_prefix=/funding/ → destination=/.
   *   - Anonymous user visits with ?auNHMRC=abc.
   *   Expected: redirect still fires (200 at /) because empty param disables bypass.
   */
  public function testBlankPreviewTokenParamDisablesBypass(): void {
    $this->createUnpublishedNode('/funding/token-blank-test');

    $this->setPathPrefixRules([
      ['source_prefix' => '/funding/', 'destination' => '/'],
    ]);

    $this->config('nhmrc_archive_redirect.settings')
      ->set('preview_token_param', '')
      ->save();

    // Token param in URL is ignored when config is blank — redirect fires.
    $this->drupalGet('/funding/token-blank-test', [
      'query' => ['auNHMRC' => 'sometoken'],
    ]);
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->addressEquals('/');
  }

  /**
   * Deleted-page stored redirect is not served when the auNHMRC token is present.
   *
   * Scenario:
   *   - A record exists in nhmrc_archive_redirect_records mapping
   *     'old-deleted-page' → '/' with status 302.
   *   - Anonymous user visits /old-deleted-page?auNHMRC=<token>.
   *   Expected: the stored redirect is not served (subscriber exits early);
   *   Drupal's router produces a 404 since the path matches no content.
   */
  public function testDeletedPageStoredRedirectBypassedByAuNhmrcToken(): void {
    // Seed a stored redirect record for a "deleted" page path.
    $this->container->get('database')
      ->insert('nhmrc_archive_redirect_records')
      ->fields([
        'source'      => 'old-deleted-page',
        'destination' => '/',
        'status_code' => 302,
        'created'     => \Drupal::time()->getRequestTime(),
      ])
      ->execute();

    // Visit without the token — expect a redirect to / (confirm the record works).
    $this->drupalGet('/old-deleted-page');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->addressEquals('/');

    // Now visit with the token — the stored redirect must not fire.
    $this->drupalGet('/old-deleted-page', [
      'query' => ['auNHMRC' => 'wdU-ujHIO_DkXUS39Mao24WAdu-mcBLbZrI9ik6Ll8Q'],
    ]);
    $this->assertSession()->statusCodeEquals(404);
    $this->assertSession()->addressNotEquals('/');
  }

  /**
   * Wildcard preview_token_param bypasses deleted-page stored redirects.
   *
   * When preview_token_param is `*`, every request bypasses the redirect,
   * including requests with no query parameters at all. The stored redirect
   * record must not be served.
   */
  public function testDeletedPageWildcardParamBypasses(): void {
    $this->container->get('database')
      ->insert('nhmrc_archive_redirect_records')
      ->fields([
        'source'      => 'old-wildcard-test-page',
        'destination' => '/',
        'status_code' => 302,
        'created'     => \Drupal::time()->getRequestTime(),
      ])
      ->execute();

    $this->config('nhmrc_archive_redirect.settings')
      ->set('preview_token_param', '*')
      ->save();

    // Wildcard — redirect must not fire even with no query parameter.
    $this->drupalGet('/old-wildcard-test-page');
    $this->assertSession()->statusCodeEquals(404);
    $this->assertSession()->addressNotEquals('/');
  }

  /**
   * Blank preview_token_param does not bypass deleted-page stored redirects.
   *
   * When preview_token_param is blank, the bypass is disabled, so the stored
   * redirect fires even when the legacy auNHMRC parameter is present in the URL.
   */
  public function testDeletedPageBlankParamDoesNotBypass(): void {
    $this->container->get('database')
      ->insert('nhmrc_archive_redirect_records')
      ->fields([
        'source'      => 'old-blank-test-page',
        'destination' => '/',
        'status_code' => 302,
        'created'     => \Drupal::time()->getRequestTime(),
      ])
      ->execute();

    $this->config('nhmrc_archive_redirect.settings')
      ->set('preview_token_param', '')
      ->save();

    // Blank param — redirect fires regardless of query parameters.
    $this->drupalGet('/old-blank-test-page', [
      'query' => ['auNHMRC' => 'sometoken'],
    ]);
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->addressEquals('/');
  }

  /**
   * Re-publishing a node invalidates cached redirects so the page is served.
   *
   * Scenario:
   *   - Unpublished node with alias /funding/cache-test.
   *   - Rule: source_prefix=/funding/ → destination=/.
   *   - Anonymous visit triggers redirect (cached by Internal Page Cache).
   *   - Node is re-published.
   *   - Next anonymous visit must reach the published page (200), not the
   *     stale cached redirect.
   *
   * This exercises hook_entity_update() cache tag invalidation.
   */
  public function testRepublishedNodeIsNotRedirectedFromCache(): void {
    $node = $this->createUnpublishedNode('/funding/cache-test');

    $this->setPathPrefixRules([
      ['source_prefix' => '/funding/', 'destination' => '/'],
    ]);

    // First visit: redirect fires (and response is cached).
    $this->drupalGet('/funding/cache-test');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->addressEquals('/');

    // Re-publish the node. This should invalidate the cached redirect via
    // hook_entity_update() calling Cache::invalidateTags().
    $node->setPublished();
    $node->save();

    // Grant anonymous access to content so the published page is viewable.
    // (Already granted in setUp, but re-affirm the expectation.)
    $this->drupalGet('/funding/cache-test');
    $this->assertSession()->statusCodeEquals(200);
    // Must be on the node's alias URL — NOT redirected to /.
    $this->assertSession()->addressEquals('/funding/cache-test');
  }

  /**
   * A published node is never redirected, even when a prefix rule would match.
   *
   * Sanity check: the subscriber must exit early for published nodes, so an
   * anonymous user landing on a published node sees the normal node page
   * without any redirect.
   */
  public function testPublishedNodeIsNotRedirected(): void {
    $node = $this->drupalCreateNode([
      'type'   => 'page',
      'status' => Node::PUBLISHED,
      'title'  => 'Published Test Page',
    ]);

    // Deliberately add a matching rule for /node/ to confirm it is not applied.
    $this->setPathPrefixRules([
      ['source_prefix' => '/node/', 'destination' => '/'],
    ]);

    $this->drupalGet('/node/' . $node->id());
    $this->assertSession()->statusCodeEquals(200);
    // Must still be on the node canonical URL, not on the redirect destination.
    $this->assertSession()->addressEquals('/node/' . $node->id());
  }

}
