<?php

namespace Drupal\nhmrc_archive_redirect\EventSubscriber;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Url;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Serves stored redirects for previously deleted node URLs.
 *
 * A configurable query parameter (default `auNHMRC`, set via the module's
 * admin settings form) bypasses stored redirects for anonymous preview URLs,
 * matching the same bypass logic as ArchivedNodeRedirectSubscriber. Setting
 * the parameter name to `*` bypasses all requests; leaving it blank disables
 * the bypass entirely.
 */
final class DeletedPageRedirectSubscriber implements EventSubscriberInterface {

  public function __construct(
    private readonly Connection $database,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly LoggerChannelFactoryInterface $loggerFactory,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    // Priority 50: run before Drupal's router (priority 32) so deleted pages
    // are caught before a 404 is generated.
    return [KernelEvents::REQUEST => ['onRequest', 50]];
  }

  /**
   * Issues a stored redirect if the requested path matches a deleted-page record.
   */
  public function onRequest(RequestEvent $event): void {
    if (!$event->isMainRequest()) {
      return;
    }

    $request = $event->getRequest();

    if ($this->requestHasAuNhmrcToken($request)) {
      return;
    }

    $source = ltrim($request->getPathInfo(), '/');
    if ($source === '') {
      return;
    }

    // If the path is currently an active URL alias (reused by new content),
    // skip the stored redirect so new content is served normally.
    try {
      $alias_active = $this->database
        ->select('path_alias', 'pa')
        ->fields('pa', ['id'])
        ->condition('alias', '/' . $source)
        ->condition('status', 1)
        ->execute()
        ->fetchField();

      if ($alias_active) {
        return;
      }
    }
    catch (\Exception $e) {
      // path_alias table unavailable; proceed to stored-redirect lookup.
    }

    try {
      $record = $this->database
        ->select('nhmrc_archive_redirect_records', 'r')
        ->fields('r', ['destination', 'status_code'])
        ->condition('source', $source)
        ->execute()
        ->fetchAssoc();
    }
    catch (\Exception $e) {
      // Table may not exist yet (module just installed, update hook not run).
      return;
    }

    if (!is_array($record) || empty($record)) {
      return;
    }

    $destination = $record['destination'];

    // Resolve <front> to the real front-page URL.
    if ($destination === '<front>') {
      $destination = Url::fromRoute('<front>')->toString();
    }

    $status = (int) $record['status_code'];

    $config = $this->configFactory->get('nhmrc_archive_redirect.settings');
    if ($config->get('log_redirects')) {
      $this->loggerFactory->get('nhmrc_archive_redirect')->notice(
        'Served stored redirect for deleted page @source → @dest (@status).',
        [
          '@source' => '/' . $source,
          '@dest'   => $destination,
          '@status' => $status,
        ]
      );
    }

    $event->setResponse(new RedirectResponse($destination, $status));
  }

  /**
   * Returns TRUE if this request should bypass the redirect.
   *
   * Mirrors the identical helper in ArchivedNodeRedirectSubscriber so that
   * token semantics stay consistent if the bypass logic ever changes.
   *
   * The parameter name is read from the `preview_token_param` config key.
   * Three modes are supported:
   *   - Empty string: bypass disabled; always returns FALSE.
   *   - `*` (asterisk): wildcard; always returns TRUE regardless of query
   *     parameters. Useful for temporarily disabling all redirects.
   *   - Any other value: returns TRUE only when that query parameter is
   *     present in the request with a non-empty value.
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

}
