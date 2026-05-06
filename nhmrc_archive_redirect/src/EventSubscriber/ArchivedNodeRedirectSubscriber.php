<?php

namespace Drupal\nhmrc_archive_redirect\EventSubscriber;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Routing\CurrentRouteMatch;
use Drupal\Core\Routing\LocalRedirectResponse;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\Url;
use Drupal\node\NodeInterface;
use Drupal\path_alias\AliasManagerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Redirects visitors away from unpublished nodes based on configured rules.
 *
 * Two entry points cover all visitor types:
 *   - onRequest  — fires for users who CAN load the node (e.g. admins).
 *   - onException — fires for users who CANNOT (anonymous / low-privilege):
 *     Drupal's AccessAwareRouter throws an AccessDeniedException at priority
 *     32, which skips lower-priority REQUEST listeners; the EXCEPTION event
 *     lets us intercept that 403 and issue a redirect instead.
 *
 * When the "bypass_for_editors" setting is enabled, users with the
 * "bypass node access" or "preview unpublished content" permission are
 * allowed to view the unpublished node without being redirected.
 *
 * Path-prefix rules are matched against both the raw request path and the
 * node's primary URL alias, so visiting /node/{nid} triggers the same rules
 * as visiting the alias URL directly.
 *
 * A configurable query parameter (default `auNHMRC`, set via the module's
 * admin settings form) is used to bypass the redirect for anonymous preview
 * URLs. Setting the parameter name to `*` bypasses all requests; leaving it
 * blank disables the bypass entirely.
 */
final class ArchivedNodeRedirectSubscriber implements EventSubscriberInterface {

  public function __construct(
    private readonly CurrentRouteMatch $routeMatch,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly LoggerChannelFactoryInterface $loggerFactory,
    private readonly AccountProxyInterface $currentUser,
    private readonly AliasManagerInterface $aliasManager,
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [
      KernelEvents::REQUEST   => ['onRequest'],
      KernelEvents::EXCEPTION => ['onException', 50],
    ];
  }

  /**
   * Redirects unpublished node requests for users who can access the route.
   *
   * Drupal's router resolves the route at priority 32; this listener runs
   * after that (default priority 0) so CurrentRouteMatch is already
   * populated. Users without node-view permission never reach this handler
   * because the access check throws before priority 0 is reached; they are
   * handled by onException() instead.
   */
  public function onRequest(RequestEvent $event): void {
    if (!$event->isMainRequest()) {
      return;
    }

    if ($this->routeMatch->getRouteName() !== 'entity.node.canonical') {
      return;
    }

    if ($this->requestHasAuNhmrcToken($event->getRequest())) {
      return;
    }

    $node = $this->routeMatch->getParameter('node');
    if (!$node instanceof NodeInterface) {
      return;
    }

    $logger = $this->loggerFactory->get('nhmrc_archive_redirect');
    $logger->debug(
      'onRequest fired for node @nid (published: @pub, path: @path, user: @uid).',
      [
        '@nid'  => $node->id(),
        '@pub'  => $node->isPublished() ? 'yes' : 'no',
        '@path' => $event->getRequest()->getPathInfo(),
        '@uid'  => $this->currentUser->id(),
      ]
    );

    if ($node->isPublished()) {
      return;
    }

    if ($this->userCanBypass()) {
      return;
    }

    $response = $this->buildRedirectResponse($node, $event->getRequest()->getPathInfo());
    if ($response !== NULL) {
      $event->setResponse($response);
    }
  }

  /**
   * Intercepts access-denied exceptions for unpublished node canonical routes.
   *
   * When an anonymous user (or any user without node-view permission) visits
   * an unpublished node URL, Drupal's AccessAwareRouter throws an exception
   * inside the priority-32 RouterListener, bypassing lower-priority REQUEST
   * listeners. This handler catches that exception event and replaces the
   * would-be 403 with the configured redirect.
   *
   * Drupal's param upcaster normally runs before the access check (inside
   * AccessAwareRouter::matchRequest()), so the full node entity is available
   * in the request attributes. A defensive integer-NID fallback is included
   * for configurations where the entity may not yet be upcast at the time the
   * exception fires.
   */
  public function onException(ExceptionEvent $event): void {
    if (!$event->isMainRequest()) {
      return;
    }

    $request = $event->getRequest();

    if ($request->attributes->get('_route') !== 'entity.node.canonical') {
      return;
    }

    if ($this->requestHasAuNhmrcToken($request)) {
      return;
    }

    $node = $request->attributes->get('node');

    // Defensive fallback: if param-upcasting has not yet converted the NID to
    // an entity, load it explicitly. The attribute may hold an integer or a
    // numeric string depending on the Symfony/Drupal version and whether the
    // ParamConverterManager has run; ctype_digit covers both cases.
    if (is_int($node) || (is_string($node) && ctype_digit($node))) {
      $nid = (int) $node;
      if ($nid > 0) {
        $node = $this->entityTypeManager->getStorage('node')->load($nid);
      }
    }

    if (!$node instanceof NodeInterface) {
      return;
    }

    $logger = $this->loggerFactory->get('nhmrc_archive_redirect');
    $logger->debug(
      'onException fired for node @nid (published: @pub, path: @path, user: @uid).',
      [
        '@nid'  => $node->id(),
        '@pub'  => $node->isPublished() ? 'yes' : 'no',
        '@path' => $request->getPathInfo(),
        '@uid'  => $this->currentUser->id(),
      ]
    );

    if ($node->isPublished()) {
      return;
    }

    if ($this->userCanBypass()) {
      return;
    }

    $response = $this->buildRedirectResponse($node, $request->getPathInfo());
    if ($response !== NULL) {
      $event->setResponse($response);
      $event->stopPropagation();
    }
  }

  /**
   * Returns TRUE if this request should bypass the redirect.
   *
   * The parameter name is read from the `preview_token_param` config key.
   * Three modes are supported:
   *   - Empty string: bypass disabled; always returns FALSE.
   *   - `*` (asterisk): wildcard; always returns TRUE regardless of query
   *     parameters. Useful for temporarily disabling all redirects.
   *   - Any other value: returns TRUE only when that query parameter is
   *     present in the request with a non-empty value.
   *
   * The token value itself is not validated — any non-empty string bypasses
   * the redirect. Token validation (if ever required) should be added here.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The current request.
   *
   * @return bool
   *   TRUE when this request should bypass the redirect.
   */
  private function requestHasAuNhmrcToken(Request $request): bool {
    $param = (string) ($this->configFactory
      ->get('nhmrc_archive_redirect.settings')
      ->get('preview_token_param') ?? 'auNHMRC');

    if ($param === '') {
      return FALSE;
    }

    if ($param === '*') {
      return TRUE;
    }

    $token = $request->query->get($param);
    return is_string($token) && $token !== '';
  }

  /**
   * Returns TRUE when the current user should bypass the redirect.
   *
   * Bypass is active unless "bypass_for_editors" is explicitly set to FALSE.
   * A NULL value (key absent from active config — typical of existing sites
   * that have not yet saved the updated settings form) is treated as enabled
   * so that editors are never inadvertently redirected after a module update.
   *
   * The user must also hold either the "bypass node access" or the custom
   * "preview unpublished content" permission. Note that "preview unpublished
   * content" only prevents the redirect; it does not independently grant
   * Drupal node-view access. Users who also need to see unpublished nodes
   * must hold an appropriate node-access permission (e.g. "bypass node access"
   * or a role-specific unpublished-view permission).
   */
  private function userCanBypass(): bool {
    $config = $this->configFactory->get('nhmrc_archive_redirect.settings');
    // Treat NULL (key not yet in active config) identically to TRUE so that
    // existing sites default to the safe, editor-friendly behaviour without
    // requiring a settings-form save or an update hook.
    if ($config->get('bypass_for_editors') === FALSE) {
      return FALSE;
    }
    return $this->currentUser->hasPermission('bypass node access')
      || $this->currentUser->hasPermission('preview unpublished content');
  }

  /**
   * Builds the redirect response for an unpublished node, or NULL if none.
   *
   * Path-prefix rules are matched against both the raw request path and the
   * node's primary URL alias, so that visiting /node/{nid} triggers the same
   * rules as visiting the resolved alias URL directly.
   *
   * Applies path-prefix rules (highest priority), content-type mapping, and
   * fallback path in turn. Returns NULL when no configured destination matches
   * or when loop protection prevents the redirect.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The unpublished node being requested.
   * @param string $current_path
   *   The request path (from getPathInfo()).
   *
   * @return \Symfony\Component\HttpFoundation\RedirectResponse|null
   *   A redirect response, or NULL if no redirect should be issued.
   */
  private function buildRedirectResponse(NodeInterface $node, string $current_path): ?RedirectResponse {
    $config = $this->configFactory->get('nhmrc_archive_redirect.settings');
    $destination = NULL;

    // 1. Try path-prefix rules first (highest priority).
    //    Both the raw request path and the node's primary URL alias are tested
    //    together in a single pass so that the longest matching prefix always
    //    wins regardless of which input path it came from. This means:
    //    - /node/{nid} visits match alias-based rules (e.g. /funding/find-funding/)
    //    - A specific alias rule beats a broader raw-path rule (e.g. /node/)
    $rules = $config->get('delete_path_rules') ?? [];
    if (!empty($rules)) {
      $paths_to_match = [$current_path];
      // Include the alias if it differs from the request path; this avoids a
      // redundant extra lookup when the visitor is already using the alias form.
      $alias = $this->aliasManager->getAliasByPath('/node/' . $node->id());
      if ($alias !== $current_path) {
        $paths_to_match[] = $alias;
      }
      $destination = $this->matchPathRule($paths_to_match, $rules);
    }

    // 2. Fall back to content-type mapping.
    if ($destination === NULL) {
      $bundle  = $node->bundle();
      $enabled = $config->get('enabled_bundles') ?? [];
      if (empty($enabled[$bundle])) {
        return NULL;
      }
      $paths       = $config->get('content_type_paths') ?? [];
      $destination = $paths[$bundle] ?? ($config->get('fallback_path') ?? '');
      if ($destination === '') {
        return NULL;
      }
    }

    // Resolve <front> to the actual front-page URL.
    if ($destination === '<front>') {
      $destination = Url::fromRoute('<front>')->toString();
    }

    // Loop protection.
    if ($current_path === $destination) {
      return NULL;
    }

    $status = (int) ($config->get('status_code') ?? 302);

    if ($config->get('log_redirects')) {
      $this->loggerFactory->get('nhmrc_archive_redirect')->notice(
        'Redirected unpublished node @nid from @from to @to (@status).',
        [
          '@nid'    => $node->id(),
          '@from'   => $current_path,
          '@to'     => $destination,
          '@status' => $status,
        ]
      );
    }

    $response = new LocalRedirectResponse($destination, $status);

    // Attach cache metadata so Internal Page Cache and Dynamic Page Cache
    // invalidate this redirect when the node is re-published.
    $cache_metadata = new CacheableMetadata();
    $cache_metadata->addCacheTags(['node:' . $node->id()]);
    $cache_metadata->addCacheContexts(['url.path']);
    $cache_metadata->setCacheMaxAge(0);
    $response->addCacheableDependency($cache_metadata);

    // Prevent reverse proxies (Varnish, CloudFront, CDN) from caching this
    // redirect -- they don't understand Drupal cache tags and would serve a
    // stale 302 even after the node is re-published.
    $response->headers->set('Cache-Control', 'no-store, must-revalidate');
    $response->headers->set('Surrogate-Control', 'no-store');

    return $response;
  }

  /**
   * Returns the destination for the longest matching prefix rule, or NULL.
   *
   * All candidate paths are evaluated in a single pass so that the longest
   * matching prefix wins regardless of which candidate path it was found on.
   * This allows a more specific alias-based rule (e.g. /funding/find-funding/)
   * to beat a broader raw-path rule (e.g. /node/) even when both match
   * different candidates.
   *
   * @param array $paths
   *   One or more candidate paths to match against (e.g. the raw request path
   *   and the node's primary URL alias).
   * @param array $rules
   *   List of ['source_prefix' => ..., 'destination' => ...] rule arrays.
   *
   * @return string|null
   *   The destination from the longest matching rule across all candidates,
   *   or NULL if no rule matches any candidate.
   */
  private function matchPathRule(array $paths, array $rules): ?string {
    $best_dest = NULL;
    $best_len  = 0;

    foreach ($rules as $rule) {
      $prefix = $rule['source_prefix'] ?? '';
      if ($prefix === '') {
        continue;
      }
      if (!str_starts_with($prefix, '/')) {
        $prefix = '/' . $prefix;
      }
      $prefix_len = strlen($prefix);
      // Skip this rule immediately if it cannot beat the current best match.
      if ($prefix_len <= $best_len) {
        continue;
      }
      foreach ($paths as $path) {
        if (str_starts_with($path, $prefix)) {
          $best_len  = $prefix_len;
          $best_dest = $rule['destination'] ?? '';
          // One matching candidate is sufficient for this prefix; move on.
          break;
        }
      }
    }

    return ($best_dest !== NULL && $best_dest !== '') ? $best_dest : NULL;
  }

}
