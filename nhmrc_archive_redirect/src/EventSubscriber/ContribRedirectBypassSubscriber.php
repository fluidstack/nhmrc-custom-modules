<?php

namespace Drupal\nhmrc_archive_redirect\EventSubscriber;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\path_alias\AliasManagerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Removes stale contrib Redirect entities when the preview-token bypass is set.
 *
 * The contrib Redirect module (drupal/redirect) issues stored redirects from
 * its own KernelEvents::REQUEST subscriber at priority 33 — earlier than this
 * module's own redirect subscribers and *outside* this module's control. That
 * means a Redirect entity left behind by an older version of this module, a
 * manual editor entry, or a migration import will:
 *
 *   1. Ignore the "bypass_for_editors" setting and node-access permissions.
 *   2. Survive node re-publication unless explicitly invalidated.
 *   3. Fire even when the editor has appended the configured preview-token
 *      query parameter (default `auNHMRC`).
 *
 * This subscriber runs at priority 40 — ahead of contrib Redirect — and, when
 * the preview token is explicitly present (non-empty value, not wildcard), it
 * deletes any contrib Redirect entity whose source path matches the current
 * request path or the node-canonical form of it. The deletion is permanent so
 * the path stops being intercepted on subsequent visits as well.
 *
 * Safety bounds:
 *   - Only runs when the contrib `redirect` module is enabled.
 *   - Only runs when `cleanup_contrib_redirects` is TRUE in module config.
 *   - Only runs when the configured preview token query parameter is present
 *     in the request with a non-empty value. Wildcard mode (`*`) is
 *     intentionally excluded to avoid mass deletion in testing setups.
 *   - Only deletes entities whose destination matches a path currently managed
 *     by this module (a delete_path_rules destination, a content_type_paths
 *     value, or the configured fallback_path). This keeps unrelated contrib
 *     Redirect entries (e.g. SEO-only redirects an editor created by hand)
 *     untouched.
 */
final class ContribRedirectBypassSubscriber implements EventSubscriberInterface {

  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
    private readonly ModuleHandlerInterface $moduleHandler,
    private readonly Connection $database,
    private readonly LoggerChannelFactoryInterface $loggerFactory,
    private readonly AliasManagerInterface $aliasManager,
    private readonly LanguageManagerInterface $languageManager,
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * Service factory.
   */
  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('config.factory'),
      $container->get('module_handler'),
      $container->get('database'),
      $container->get('logger.factory'),
      $container->get('path_alias.manager'),
      $container->get('language_manager'),
      $container->get('entity_type.manager'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    // Priority 40: ahead of contrib Redirect's RedirectRequestSubscriber
    // (priority 33) so deletions take effect before its lookup runs.
    return [KernelEvents::REQUEST => ['onRequest', 40]];
  }

  /**
   * Deletes stale contrib Redirect rows targeting paths this module manages.
   */
  public function onRequest(RequestEvent $event): void {
    if (!$event->isMainRequest()) {
      return;
    }

    if (!$this->moduleHandler->moduleExists('redirect')) {
      return;
    }

    $config = $this->configFactory->get('nhmrc_archive_redirect.settings');
    if ($config->get('cleanup_contrib_redirects_on_republish') === FALSE) {
      return;
    }

    $request = $event->getRequest();
    if (!$this->requestHasExplicitToken($request, $config)) {
      return;
    }

    // Note on threat model for this destructive side-effect on a GET:
    //   - Triggered only when the configured preview-token query parameter is
    //     explicitly present (wildcard `*` mode is excluded). The token value
    //     itself is admin-configured and acts as a shared secret.
    //   - Deletion is further constrained below to entities whose destination
    //     matches a path THIS module currently manages — so an attacker who
    //     learns the token can only remove redirect entries that this module
    //     would have created. Editor-curated redirects to other destinations
    //     are never touched.
    //   - The whole behaviour can be disabled via the
    //     `cleanup_contrib_redirects_on_republish` setting.
    // Earlier iterations gated this on `bypass node access` etc., but that
    // also blocked the legitimate anonymous preview-link use case for which
    // the token exists in the first place.

    // Build the candidate source-path list (no leading slash, as stored by
    // contrib Redirect): the raw request path and, if it resolves to a node,
    // the canonical node/{nid} form too.
    $request_path = ltrim($request->getPathInfo(), '/');
    if ($request_path === '') {
      return;
    }

    $candidates = [$request_path];

    // Resolve the request path's internal system path; if it points to a node,
    // include the node/{nid} form as a candidate. Try each configured language
    // because the alias may belong to a translation other than the current
    // request's language (e.g. an editor opens a French alias on an English
    // session). The default getPathByAlias() call only consults the current
    // language and would miss the alias entirely.
    $internal = '/' . $request_path;
    foreach ($this->languageManager->getLanguages() as $langcode => $language) {
      $resolved = $this->aliasManager->getPathByAlias('/' . $request_path, $langcode);
      if ($resolved !== '/' . $request_path) {
        $internal = $resolved;
        break;
      }
    }
    if ($internal !== '/' . $request_path) {
      $internal_trim = ltrim($internal, '/');
      if ($internal_trim !== '' && !in_array($internal_trim, $candidates, TRUE)) {
        $candidates[] = $internal_trim;
      }
    }

    // If the resolved internal path is a node canonical, also include the
    // alias for every configured language. On multilingual sites the same
    // node has one alias per translation and each may be the source of a
    // stale contrib Redirect entity. Without this we would only clean up
    // the alias for the current request's language.
    if (preg_match('#^/node/(\d+)$#', $internal, $m) === 1) {
      $node_path = '/node/' . $m[1];
      // Per-language aliases.
      foreach ($this->languageManager->getLanguages() as $langcode => $language) {
        $lang_alias = $this->aliasManager->getAliasByPath($node_path, $langcode);
        if ($lang_alias === $node_path) {
          continue;
        }
        $lang_alias_trim = ltrim($lang_alias, '/');
        if ($lang_alias_trim !== '' && !in_array($lang_alias_trim, $candidates, TRUE)) {
          $candidates[] = $lang_alias_trim;
        }
      }
      // Language-neutral safety net for language-neutral installs / aliases
      // not tied to a specific language. Mirrors the module hook behaviour.
      $neutral = $this->aliasManager->getAliasByPath($node_path);
      if ($neutral !== $node_path) {
        $neutral_trim = ltrim($neutral, '/');
        if ($neutral_trim !== '' && !in_array($neutral_trim, $candidates, TRUE)) {
          $candidates[] = $neutral_trim;
        }
      }
    }

    $managed = $this->buildManagedDestinationSet($config);
    if (empty($managed)) {
      // No managed destinations means we can't safely identify our own
      // legacy entries; do nothing rather than deleting unrelated rows.
      return;
    }

    try {
      $rows = $this->database
        ->select('redirect', 'r')
        ->fields('r', ['rid', 'redirect_source__path', 'redirect_redirect__uri'])
        ->condition('redirect_source__path', $candidates, 'IN')
        ->execute()
        ->fetchAll();
    }
    catch (\Exception $e) {
      // Table missing or schema differs; nothing safe to do.
      return;
    }

    if (empty($rows)) {
      return;
    }

    $logger = $this->loggerFactory->get('nhmrc_archive_redirect');
    $deleted_rids = [];

    foreach ($rows as $row) {
      $destination_path = $this->normaliseDestinationUri((string) $row->redirect_redirect__uri);
      if ($destination_path === NULL) {
        continue;
      }
      if (!isset($managed[$destination_path])) {
        continue;
      }
      $deleted_rids[] = (int) $row->rid;
    }

    if (empty($deleted_rids)) {
      return;
    }

    // Delete via the entity API so cache tags and hooks fire correctly.
    try {
      $storage = $this->entityTypeManager->getStorage('redirect');
      $entities = $storage->loadMultiple($deleted_rids);
      if (!empty($entities)) {
        $storage->delete($entities);
      }
    }
    catch (\Exception $e) {
      // Fall back to a raw delete so the request still completes cleanly.
      try {
        $this->database->delete('redirect')
          ->condition('rid', $deleted_rids, 'IN')
          ->execute();
      }
      catch (\Exception $inner) {
        $logger->warning(
          'Could not delete stale contrib Redirect entities @rids on token bypass: @msg.',
          ['@rids' => implode(',', $deleted_rids), '@msg' => $inner->getMessage()]
        );
        return;
      }
    }

    $logger->notice(
      'Preview-token bypass deleted @count stale contrib Redirect entit@y (rid: @rids) for path @path; request continues to node controller.',
      [
        '@count' => count($deleted_rids),
        '@y'    => count($deleted_rids) === 1 ? 'y' : 'ies',
        '@rids' => implode(',', $deleted_rids),
        '@path' => '/' . $request_path,
      ]
    );
  }

  /**
   * Returns TRUE only when the configured token is explicitly present.
   *
   * Wildcard mode (`*`) is intentionally excluded: it is a developer testing
   * convenience and should not trigger destructive deletions.
   */
  private function requestHasExplicitToken(Request $request, ImmutableConfig $config): bool {
    $param = (string) ($config->get('preview_token_param') ?? 'auNHMRC');
    if ($param === '' || $param === '*') {
      return FALSE;
    }
    $token = $request->query->get($param);
    return is_string($token) && $token !== '';
  }

  /**
   * Builds a lookup set of internal paths this module currently manages.
   *
   * Keys are normalised internal paths (with leading slash). Used to confirm
   * a contrib Redirect entity targets a path we own before deleting it.
   */
  private function buildManagedDestinationSet(ImmutableConfig $config): array {
    $set = [];

    $rules = $config->get('delete_path_rules') ?? [];
    foreach ($rules as $rule) {
      $dest = $this->normaliseInternalPath((string) ($rule['destination'] ?? ''));
      if ($dest !== NULL) {
        $set[$dest] = TRUE;
      }
    }

    $bundle_paths = $config->get('content_type_paths') ?? [];
    foreach ($bundle_paths as $dest) {
      $dest = $this->normaliseInternalPath((string) $dest);
      if ($dest !== NULL) {
        $set[$dest] = TRUE;
      }
    }

    $fallback = $this->normaliseInternalPath((string) ($config->get('fallback_path') ?? ''));
    if ($fallback !== NULL) {
      $set[$fallback] = TRUE;
    }

    return $set;
  }

  /**
   * Normalises a stored destination value to a comparable internal path.
   *
   * Returns NULL for empty or unparseable values.
   */
  private function normaliseInternalPath(string $value): ?string {
    $value = trim($value);
    if ($value === '') {
      return NULL;
    }
    if ($value === '<front>') {
      return '/';
    }
    if (!str_starts_with($value, '/')) {
      $value = '/' . $value;
    }
    return $value;
  }

  /**
   * Normalises a contrib Redirect destination URI to a comparable path.
   *
   * Contrib Redirect stores destinations as `internal:/path` for internal
   * targets. Anything else (route:..., entity:..., external URLs) is left to
   * pass through untouched: not our concern.
   */
  private function normaliseDestinationUri(string $uri): ?string {
    $uri = trim($uri);
    if ($uri === '') {
      return NULL;
    }
    if (str_starts_with($uri, 'internal:')) {
      $path = substr($uri, strlen('internal:'));
      return $this->normaliseInternalPath($path);
    }
    return NULL;
  }

}
