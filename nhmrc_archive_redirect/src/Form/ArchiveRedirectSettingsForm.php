<?php

namespace Drupal\nhmrc_archive_redirect\Form;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Path\PathValidatorInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Administration form for configuring the NHMRC archive redirect module.
 */
final class ArchiveRedirectSettingsForm extends ConfigFormBase {

  /**
   * The path validator service.
   *
   * @var \Drupal\Core\Path\PathValidatorInterface
   */
  protected PathValidatorInterface $pathValidator;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * Constructs an ArchiveRedirectSettingsForm object.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   The config factory.
   * @param \Drupal\Core\Path\PathValidatorInterface $pathValidator
   *   The path validator.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   */
  public function __construct(
    ConfigFactoryInterface $configFactory,
    PathValidatorInterface $pathValidator,
    EntityTypeManagerInterface $entityTypeManager,
  ) {
    parent::__construct($configFactory);
    $this->pathValidator = $pathValidator;
    $this->entityTypeManager = $entityTypeManager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('config.factory'),
      $container->get('path.validator'),
      $container->get('entity_type.manager'),
    );
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames(): array {
    return ['nhmrc_archive_redirect.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'nhmrc_archive_redirect_settings_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $config = $this->config('nhmrc_archive_redirect.settings');

    // ----------------------------------------------------------------
    // Behaviour
    // ----------------------------------------------------------------
    $form['behaviour'] = [
      '#type' => 'details',
      '#title' => $this->t('Behaviour'),
      '#open' => TRUE,
      '#tree' => TRUE,
    ];

    $form['behaviour']['status_code'] = [
      '#type' => 'radios',
      '#title' => $this->t('Redirect status code'),
      '#options' => [
        301 => $this->t('301 (Permanent)'),
        302 => $this->t('302 (Temporary)'),
      ],
      '#default_value' => (int) ($config->get('status_code') ?? 302),
      '#description' => $this->t('Use 302 during testing. Switch to 301 once confirmed.'),
    ];

    $form['behaviour']['fallback_path'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Default fallback path'),
      '#default_value' => $config->get('fallback_path') ?? '',
      '#description' => $this->t('Internal path (e.g. /about-us) or <em>&lt;front&gt;</em> to redirect to the site front page. Used as a last-resort fallback for content-type mappings only.'),
    ];

    $form['behaviour']['log_redirects'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Log when redirects occur'),
      '#default_value' => (bool) ($config->get('log_redirects') ?? FALSE),
    ];

    $form['behaviour']['bypass_for_editors'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Allow editors to preview unpublished content'),
      '#description' => $this->t('When enabled, users with the <em>Bypass node access</em> or <em>Preview unpublished content</em> permission will not be redirected and can reach the unpublished page URL normally. Anonymous visitors and users without those permissions are still redirected. Note: the <em>Preview unpublished content</em> permission only bypasses this redirect — users must also have Drupal node-view access for unpublished content (e.g. <em>Bypass content access control</em>) to actually see the page.'),
      '#default_value' => (bool) ($config->get('bypass_for_editors') ?? TRUE),
    ];

    $form['behaviour']['preview_token_param'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Preview token parameter name'),
      '#default_value' => $config->get('preview_token_param') ?? 'auNHMRC',
      '#size' => 40,
      '#description' => $this->t('The URL query parameter that grants anonymous users access to unpublished content. For example, with the default value <code>auNHMRC</code>, a URL like <code>?auNHMRC=sometoken</code> will bypass the redirect. Use <code>*</code> to bypass the redirect for <strong>all</strong> requests regardless of query parameters (useful during testing or migration). Leave blank to disable the token bypass entirely.'),
    ];

    // ----------------------------------------------------------------
    // Delete path rules
    // ----------------------------------------------------------------
    $form['path_rules'] = [
      '#type' => 'details',
      '#title' => $this->t('Delete path rules'),
      '#open' => TRUE,
      '#tree' => TRUE,
      '#prefix' => '<div id="path-rules-wrapper">',
      '#suffix' => '</div>',
      '#description' => $this->t(
        'When a node is <strong>deleted or unpublished</strong> and its URL alias starts with a configured prefix, the visitor is redirected to the specified destination. The longest matching prefix wins. Destinations must be internal paths (e.g. <code>/about-us/news-centre</code>) or <code>&lt;front&gt;</code>.'
      ),
    ];

    // Initialise rules from form state (add/remove clicks) or saved config.
    $rules = $form_state->get('path_rules');
    if ($rules === NULL) {
      $rules = array_values($config->get('delete_path_rules') ?? []);
      $form_state->set('path_rules', $rules);
    }
    // Ensure $rules is a sequential array so that $i is always int.
    $rules = is_array($rules) ? array_values($rules) : [];

    $form['path_rules']['table'] = [
      '#type' => 'table',
      '#header' => [
        $this->t('Path prefix'),
        $this->t('Redirect destination'),
        $this->t('Action'),
      ],
      '#empty' => $this->t('No rules configured. Click "Add rule" to create one.'),
    ];

    foreach ($rules as $i => $rule) {
      // @phpstan-ignore-next-line (Drupal render array: dynamic int keys cannot be statically typed)
      $form['path_rules']['table'][$i]['source_prefix'] = [
        '#type' => 'textfield',
        '#default_value' => $rule['source_prefix'] ?? '',
        '#placeholder' => '/about-us/news-centre/',
        '#description' => $this->t('Path prefix including trailing slash (e.g. /about-us/news-centre/).'),
        '#size' => 40,
      ];

      $form['path_rules']['table'][$i]['destination'] = [
        '#type' => 'textfield',
        '#default_value' => $rule['destination'] ?? '',
        '#placeholder' => '/about-us/news-centre',
        '#description' => $this->t('Internal path or &lt;front&gt;.'),
        '#size' => 40,
      ];

      $form['path_rules']['table'][$i]['remove'] = [
        '#type' => 'submit',
        '#value' => $this->t('Remove'),
        '#name' => 'remove_rule_' . $i,
        '#submit' => ['::removePathRule'],
        '#ajax' => [
          'callback' => '::pathRulesCallback',
          'wrapper' => 'path-rules-wrapper',
        ],
        '#limit_validation_errors' => [],
      ];
    }

    $form['path_rules']['add_rule'] = [
      '#type' => 'submit',
      '#value' => $this->t('Add rule'),
      '#name' => 'add_path_rule',
      '#submit' => ['::addPathRule'],
      '#ajax' => [
        'callback' => '::pathRulesCallback',
        'wrapper' => 'path-rules-wrapper',
      ],
      '#limit_validation_errors' => [],
    ];

    // ----------------------------------------------------------------
    // Content type mappings (unpublished fallback per bundle)
    // ----------------------------------------------------------------
    $enabled = $config->get('enabled_bundles') ?? [];
    $paths = $config->get('content_type_paths') ?? [];

    $form['mappings'] = [
      '#type' => 'details',
      '#title' => $this->t('Content type mappings (unpublished fallback)'),
      '#open' => TRUE,
      '#tree' => TRUE,
      '#description' => $this->t(
        'When an <strong>unpublished</strong> node is visited and no delete path rule matches, these per-content-type destinations are used. Use an internal path (e.g. /about-us) or <em>&lt;front&gt;</em>.'
      ),
    ];

    $form['mappings']['bundles'] = [
      '#type' => 'table',
      '#header' => [
        $this->t('Enable'),
        $this->t('Content type'),
        $this->t('Redirect path'),
      ],
    ];

    $types = $this->entityTypeManager->getStorage('node_type')->loadMultiple();

    foreach ($types as $bundle => $type) {
      // @phpstan-ignore-next-line (Drupal render array: dynamic string keys cannot be statically typed)
      $form['mappings']['bundles'][$bundle]['enabled'] = [
        '#type' => 'checkbox',
        '#default_value' => !empty($enabled[$bundle]),
      ];

      $form['mappings']['bundles'][$bundle]['label'] = [
        '#markup' => $type->label(),
      ];

      $form['mappings']['bundles'][$bundle]['path'] = [
        '#type' => 'textfield',
        '#default_value' => $paths[$bundle] ?? '',
        '#description' => $this->t('Internal path or &lt;front&gt;.'),
        '#states' => [
          'enabled' => [
            ':input[name="mappings[bundles][' . $bundle . '][enabled]"]' => ['checked' => TRUE],
          ],
        ],
      ];
    }

    return parent::buildForm($form, $form_state);
  }

  // ----------------------------------------------------------------
  // Ajax callbacks and submit handlers for path rules table
  // ----------------------------------------------------------------

  /**
   * Ajax callback: returns the path-rules section for in-place replacement.
   */
  public function pathRulesCallback(array &$form, FormStateInterface $form_state): array {
    return $form['path_rules'];
  }

  /**
   * Ajax submit handler: appends an empty row to the path rules table.
   */
  public function addPathRule(array &$form, FormStateInterface $form_state): void {
    $raw = $form_state->getUserInput();
    $input = (isset($raw['path_rules']['table']) && is_array($raw['path_rules']['table']))
      ? $raw['path_rules']['table']
      : [];
    $rules = $this->extractRulesFromInput($input);
    $rules[] = ['source_prefix' => '', 'destination' => ''];
    $form_state->set('path_rules', $rules);
    $form_state->setRebuild();
  }

  /**
   * Ajax submit handler: removes the path rule row that was clicked.
   *
   * The row to remove is identified directly from the Ajax POST parameter
   * _triggering_element_name, which Drupal's JavaScript always sets to the
   * HTML name attribute of the button the user clicked. Our Remove buttons
   * are named remove_rule_N where N is the same key used for that row in the
   * path_rules[table][N] POST data, so unset($input[N]) is guaranteed to
   * target the correct row with no dependency on form rebuild ordering.
   */
  public function removePathRule(array &$form, FormStateInterface $form_state): void {
    $raw = $form_state->getUserInput();
    // _triggering_element_name is 'remove_rule_N'; parse N to get the POST key.
    $button_name = (string) ($raw['_triggering_element_name'] ?? '');
    $prefix = 'remove_rule_';
    $suffix = str_starts_with($button_name, $prefix)
      ? substr($button_name, strlen($prefix))
      : '';
    // Only accept a pure-digit suffix to guard against tampered input.
    $row_key = (strlen($suffix) > 0 && ctype_digit($suffix))
      ? (int) $suffix
      : -1;
    $input = (is_array($raw['path_rules']['table'] ?? NULL))
      ? $raw['path_rules']['table']
      : [];
    // Remove the exact POST key, then re-index before extracting rules.
    if ($row_key >= 0) {
      unset($input[$row_key]);
    }
    $input = array_values($input);
    $rules = $this->extractRulesFromInput($input);
    $form_state->set('path_rules', $rules);

    // Synchronise raw user input so Drupal's form rebuild uses the corrected
    // row values instead of stale positional POST data.
    $raw['path_rules']['table'] = $input;
    $form_state->setUserInput($raw);

    $form_state->setRebuild();
  }

  /**
   * Builds a sequentially re-indexed rules array from raw table input.
   *
   * @param array $input
   *   Raw user input for the path_rules table rows.
   *
   * @return array
   *   Sequential list of ['source_prefix' => ..., 'destination' => ...] entries.
   */
  private function extractRulesFromInput(array $input): array {
    $rules = [];
    foreach ($input as $row) {
      $rules[] = [
        'source_prefix' => $row['source_prefix'] ?? '',
        'destination'   => $row['destination'] ?? '',
      ];
    }
    return $rules;
  }

  // ----------------------------------------------------------------
  // Validate
  // ----------------------------------------------------------------

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    parent::validateForm($form, $form_state);

    // Validate preview token parameter name.
    $token_param = trim((string) $form_state->getValue(['behaviour', 'preview_token_param']));
    if ($token_param !== '' && $token_param !== '*') {
      // Reject characters that are structurally invalid in a URL query-string
      // key: equals sign, ampersand, hash, and whitespace. The asterisk is
      // intentionally allowed as a special wildcard value.
      if (preg_match('/[=&#\s]/', $token_param)) {
        $form_state->setErrorByName(
          'behaviour][preview_token_param',
          $this->t('The parameter name may not contain <code>=</code>, <code>&amp;</code>, <code>#</code>, or whitespace characters.')
        );
      }
    }

    // Validate fallback path.
    $fallback = trim((string) $form_state->getValue(['behaviour', 'fallback_path']));
    if ($fallback !== '' && $fallback !== '<front>') {
      if (!str_starts_with($fallback, '/')) {
        $fallback = '/' . $fallback;
      }
      if (!$this->pathValidator->isValid($fallback)) {
        $form_state->setErrorByName('behaviour][fallback_path', $this->t('Fallback path must be a valid internal path (e.g. /about-us) or <em>&lt;front&gt;</em>.'));
      }
    }

    // Validate content-type bundle paths.
    $bundle_rows = $form_state->getValue(['mappings', 'bundles']) ?: [];
    foreach ($bundle_rows as $bundle => $row) {
      if (!empty($row['enabled']) && !empty($row['path'])) {
        $path = trim((string) $row['path']);
        if ($path === '<front>') {
          continue;
        }
        if (!str_starts_with($path, '/')) {
          $path = '/' . $path;
        }
        if (!$this->pathValidator->isValid($path)) {
          $form_state->setErrorByName("mappings][bundles][$bundle][path", $this->t('Invalid internal path for %bundle: %path', [
            '%bundle' => $bundle,
            '%path' => $path,
          ]));
        }
      }
    }

    // Validate delete path rules.
    $rule_rows = $form_state->getValue(['path_rules', 'table']) ?: [];
    foreach ($rule_rows as $i => $row) {
      $prefix = trim((string) ($row['source_prefix'] ?? ''));
      $dest   = trim((string) ($row['destination'] ?? ''));

      if ($prefix === '' && $dest === '') {
        continue;
      }

      // Prefix must start with /.
      if ($prefix !== '' && !str_starts_with($prefix, '/')) {
        $form_state->setErrorByName("path_rules][table][$i][source_prefix", $this->t('Path prefix must start with / (e.g. /about-us/news-centre/).'));
      }

      // Reject external URLs.
      if ($dest !== '' && (str_starts_with($dest, 'http://') || str_starts_with($dest, 'https://'))) {
        $form_state->setErrorByName("path_rules][table][$i][destination", $this->t('Destination must be an internal path or &lt;front&gt;, not an external URL.'));
        continue;
      }

      // Destination: internal path or <front>.
      if ($dest !== '' && $dest !== '<front>') {
        if (!str_starts_with($dest, '/')) {
          $dest = '/' . $dest;
        }
        if (!$this->pathValidator->isValid($dest)) {
          $form_state->setErrorByName("path_rules][table][$i][destination", $this->t('Destination must be a valid internal path (e.g. /about-us/news-centre) or &lt;front&gt;.'));
        }
      }

      // Both fields must be filled if either is present.
      if ($prefix === '' && $dest !== '') {
        $form_state->setErrorByName("path_rules][table][$i][source_prefix", $this->t('Path prefix is required when a destination is set.'));
      }
      if ($prefix !== '' && $dest === '') {
        $form_state->setErrorByName("path_rules][table][$i][destination", $this->t('Destination is required when a path prefix is set.'));
      }
    }
  }

  // ----------------------------------------------------------------
  // Submit
  // ----------------------------------------------------------------

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    // Content-type bundles.
    $bundle_rows = $form_state->getValue(['mappings', 'bundles']) ?: [];
    $enabled_bundles = [];
    $content_type_paths = [];
    foreach ($bundle_rows as $bundle => $row) {
      $enabled_bundles[$bundle] = !empty($row['enabled']);
      if (!empty($row['path'])) {
        $p = trim((string) $row['path']);
        $content_type_paths[$bundle] = ($p === '<front>') ? $p : (str_starts_with($p, '/') ? $p : '/' . $p);
      }
    }

    // Fallback path.
    $fallback = trim((string) $form_state->getValue(['behaviour', 'fallback_path']));
    if ($fallback !== '' && $fallback !== '<front>') {
      $fallback = str_starts_with($fallback, '/') ? $fallback : '/' . $fallback;
    }

    // Delete path rules — filter out empty rows.
    $rule_rows = $form_state->getValue(['path_rules', 'table']) ?: [];
    $delete_path_rules = [];
    foreach ($rule_rows as $row) {
      $prefix = trim((string) ($row['source_prefix'] ?? ''));
      $dest   = trim((string) ($row['destination'] ?? ''));
      if ($prefix === '' || $dest === '') {
        continue;
      }
      // Ensure prefix starts with /.
      if (!str_starts_with($prefix, '/')) {
        $prefix = '/' . $prefix;
      }
      // Normalise destination.
      if ($dest !== '<front>' && !str_starts_with($dest, '/')) {
        $dest = '/' . $dest;
      }
      $delete_path_rules[] = [
        'source_prefix' => $prefix,
        'destination'   => $dest,
      ];
    }

    $this->config('nhmrc_archive_redirect.settings')
      ->set('enabled_bundles', $enabled_bundles)
      ->set('content_type_paths', $content_type_paths)
      ->set('fallback_path', $fallback)
      ->set('status_code', (int) $form_state->getValue(['behaviour', 'status_code']))
      ->set('log_redirects', (bool) $form_state->getValue(['behaviour', 'log_redirects']))
      ->set('bypass_for_editors', (bool) $form_state->getValue(['behaviour', 'bypass_for_editors']))
      ->set('preview_token_param', trim((string) $form_state->getValue(['behaviour', 'preview_token_param'])))
      ->set('delete_path_rules', $delete_path_rules)
      ->save();

    parent::submitForm($form, $form_state);
  }

}
