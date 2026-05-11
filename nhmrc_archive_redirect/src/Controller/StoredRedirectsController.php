<?php

declare(strict_types=1);

namespace Drupal\nhmrc_archive_redirect\Controller;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Database\Connection;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Url;
use Drupal\path_alias\AliasManagerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Provides a paginated listing of stored and active redirect records.
 */
final class StoredRedirectsController extends ControllerBase {

  public function __construct(
    protected Connection $database,
    protected DateFormatterInterface $dateFormatter,
    protected ConfigFactoryInterface $configFactory,
    protected AliasManagerInterface $aliasManager,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('database'),
      $container->get('date.formatter'),
      $container->get('config.factory'),
      $container->get('path_alias.manager'),
    );
  }

  /**
   * Renders the redirects listing page with both stored and archived records.
   */
  public function listing(Request $request): array {
    $build = [
      '#cache' => ['max-age' => 0],
    ];

    $filter = trim((string) $request->query->get('filter', ''));
    $type_filter = (string) $request->query->get('type', '');

    // Filter form.
    $build['filter'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['form--inline', 'clearfix']],
    ];
    $build['filter']['form'] = [
      '#type' => 'html_tag',
      '#tag' => 'form',
      '#attributes' => [
        'method' => 'get',
        'action' => Url::fromRoute('nhmrc_archive_redirect.stored_redirects')->toString(),
        'class' => ['form--inline'],
      ],
    ];
    $build['filter']['form']['filter'] = [
      '#type' => 'search',
      '#title' => $this->t('Filter by path'),
      '#default_value' => $filter,
      '#attributes' => [
        'name' => 'filter',
        'placeholder' => $this->t('e.g. about-us/news'),
      ],
      '#size' => 40,
    ];
    $build['filter']['form']['type'] = [
      '#type' => 'select',
      '#title' => $this->t('Type'),
      '#options' => [
        '' => $this->t('All'),
        'archived' => $this->t('Archived (unpublished)'),
        'deleted' => $this->t('Deleted'),
      ],
      '#default_value' => $type_filter,
      '#attributes' => ['name' => 'type'],
    ];
    $build['filter']['form']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Filter'),
    ];
    if ($filter !== '' || $type_filter !== '') {
      $build['filter']['form']['reset'] = [
        '#type' => 'link',
        '#title' => $this->t('Reset'),
        '#url' => Url::fromRoute('nhmrc_archive_redirect.stored_redirects'),
        '#attributes' => ['class' => ['button']],
      ];
    }

    // Archived (unpublished) nodes section.
    if ($type_filter === '' || $type_filter === 'archived') {
      $archived_rows = $this->buildArchivedRows($filter);
      $build['archived_heading'] = [
        '#markup' => '<h3>' . $this->t('Archived (unpublished) redirects') . '</h3>',
      ];
      $build['archived_summary'] = [
        '#markup' => '<p>' . $this->t('@count active archived redirect(s).', ['@count' => count($archived_rows)]) . '</p>',
      ];
      $build['archived_table'] = [
        '#type' => 'table',
        '#header' => [
          $this->t('Source path'),
          $this->t('Destination'),
          $this->t('Status code'),
          $this->t('Content type'),
          $this->t('Rule type'),
        ],
        '#rows' => $archived_rows,
        '#empty' => $this->t('No active archived redirects. Unpublished nodes matching configured rules will appear here.'),
      ];
    }

    // Stored (deleted) records section.
    if ($type_filter === '' || $type_filter === 'deleted') {
      $header = [
        ['data' => $this->t('Source path'), 'field' => 'source', 'sort' => 'asc'],
        ['data' => $this->t('Destination'), 'field' => 'destination'],
        ['data' => $this->t('Status code'), 'field' => 'status_code'],
        ['data' => $this->t('Created'), 'field' => 'created', 'sort' => 'desc'],
      ];

      $select = $this->database->select('nhmrc_archive_redirect_records', 'r')
        ->fields('r', ['source', 'destination', 'status_code', 'created']);

      /** @var \Drupal\Core\Database\Query\PagerSelectExtender $query */
      $query = $select
        ->extend('\Drupal\Core\Database\Query\PagerSelectExtender')
        ->extend('\Drupal\Core\Database\Query\TableSortExtender');
      $query->limit(50)
        ->orderByHeader($header);

      if ($filter !== '') {
        $query->condition('source', '%' . $this->database->escapeLike($filter) . '%', 'LIKE');
      }

      $results = $query->execute();
      $rows = [];
      foreach ($results as $record) {
        $rows[] = [
          '/' . $record->source,
          $record->destination,
          $record->status_code,
          $this->dateFormatter->format((int) $record->created, 'short'),
        ];
      }

      // Summary.
      $total = 0;
      try {
        $count_select = $this->database->select('nhmrc_archive_redirect_records', 'r');
        if ($filter !== '') {
          $count_select->condition('source', '%' . $this->database->escapeLike($filter) . '%', 'LIKE');
        }
        $total = (int) $count_select->countQuery()->execute()->fetchField();
      }
      catch (\Exception $e) {
      }

      $build['deleted_heading'] = [
        '#markup' => '<h3>' . $this->t('Stored redirects (deleted pages)') . '</h3>',
      ];
      $build['deleted_summary'] = [
        '#markup' => '<p>' . $this->t('@count stored redirect record(s).', ['@count' => $total]) . '</p>',
      ];
      $build['deleted_table'] = [
        '#type' => 'table',
        '#header' => $header,
        '#rows' => $rows,
        '#empty' => $this->t('No stored redirect records. Records are created when deleted pages match configured path rules.'),
      ];
      $build['pager'] = [
        '#type' => 'pager',
      ];
    }

    return $build;
  }

  /**
   * Builds table rows for currently-unpublished nodes that have active redirects.
   *
   * @param string $filter
   *   Optional path filter string.
   *
   * @return array
   *   Table rows for the archived redirects listing.
   */
  private function buildArchivedRows(string $filter): array {
    $config = $this->configFactory->get('nhmrc_archive_redirect.settings');
    $rules = $config->get('delete_path_rules') ?? [];
    $enabled_bundles = $config->get('enabled_bundles') ?? [];
    $content_type_paths = $config->get('content_type_paths') ?? [];
    $fallback_path = $config->get('fallback_path') ?? '';
    $status_code = (int) ($config->get('status_code') ?? 302);

    $rows = [];

    try {
      $nid_query = $this->database->select('node_field_data', 'n')
        ->fields('n', ['nid', 'type'])
        ->condition('n.status', 0);
      $nids = $nid_query->execute();
    }
    catch (\Exception $e) {
      return $rows;
    }

    foreach ($nids as $record) {
      $nid = (int) $record->nid;
      $bundle = $record->type;
      $alias = $this->aliasManager->getAliasByPath('/node/' . $nid);
      $source = $alias ?: '/node/' . $nid;

      // Apply filter.
      if ($filter !== '' && stripos($source, $filter) === FALSE) {
        continue;
      }

      $destination = NULL;
      $rule_type = '';

      // 1. Try path-prefix rules first.
      if (!empty($rules)) {
        $matched = $this->matchPathRule($source, $rules);
        if ($matched !== NULL) {
          $destination = $matched;
          $rule_type = $this->t('Path prefix rule');
        }
      }

      // 2. Fall back to content-type mapping.
      if ($destination === NULL && !empty($enabled_bundles[$bundle])) {
        $destination = $content_type_paths[$bundle] ?? $fallback_path;
        if ($destination !== '') {
          $rule_type = $this->t('Content type mapping');
        }
        else {
          $destination = NULL;
        }
      }

      if ($destination === NULL) {
        continue;
      }

      $rows[] = [
        $source,
        $destination,
        $status_code,
        $bundle,
        $rule_type,
      ];
    }

    return $rows;
  }

  /**
   * Returns the destination for the longest matching prefix rule, or NULL.
   *
   * @param string $path
   *   The path to match (with leading slash).
   * @param array $rules
   *   List of ['source_prefix' => ..., 'destination' => ...] rule arrays.
   *
   * @return string|null
   *   The destination from the longest matching rule, or NULL.
   */
  private function matchPathRule(string $path, array $rules): ?string {
    $best_dest = NULL;
    $best_len = 0;

    foreach ($rules as $rule) {
      $prefix = $rule['source_prefix'] ?? '';
      if ($prefix === '') {
        continue;
      }
      if (!str_starts_with($prefix, '/')) {
        $prefix = '/' . $prefix;
      }
      if (str_starts_with($path, $prefix) && strlen($prefix) > $best_len) {
        $best_len = strlen($prefix);
        $best_dest = $rule['destination'] ?? '';
      }
    }

    return ($best_dest !== NULL && $best_dest !== '') ? $best_dest : NULL;
  }

}
