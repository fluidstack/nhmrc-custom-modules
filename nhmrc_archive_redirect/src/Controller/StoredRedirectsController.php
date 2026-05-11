<?php

declare(strict_types=1);

namespace Drupal\nhmrc_archive_redirect\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Database\Connection;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Provides a paginated listing of stored redirect records.
 */
final class StoredRedirectsController extends ControllerBase {

  public function __construct(
    protected Connection $database,
    protected DateFormatterInterface $dateFormatter,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('database'),
      $container->get('date.formatter'),
    );
  }

  /**
   * Renders the stored redirects listing page.
   */
  public function listing(Request $request): array {
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

    // Filter by source prefix if provided.
    $filter = trim((string) $request->query->get('filter', ''));
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

    $build = [
      '#cache' => ['max-age' => 0],
    ];

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
    $build['filter']['form']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Filter'),
    ];
    if ($filter !== '') {
      $build['filter']['form']['reset'] = [
        '#type' => 'link',
        '#title' => $this->t('Reset'),
        '#url' => Url::fromRoute('nhmrc_archive_redirect.stored_redirects'),
        '#attributes' => ['class' => ['button']],
      ];
    }

    // Summary.
    $total = 0;
    try {
      $count_query = $this->database->select('nhmrc_archive_redirect_records', 'r')
        ->countQuery();
      if ($filter !== '') {
        $count_query->condition('source', '%' . $this->database->escapeLike($filter) . '%', 'LIKE');
      }
      $total = (int) $count_query->execute()->fetchField();
    }
    catch (\Exception $e) {
    }

    $build['summary'] = [
      '#markup' => '<p>' . $this->t('@count redirect record(s) found.', ['@count' => $total]) . '</p>',
    ];

    // Table.
    $build['table'] = [
      '#type' => 'table',
      '#header' => $header,
      '#rows' => $rows,
      '#empty' => $this->t('No stored redirect records found.'),
    ];

    // Pager.
    $build['pager'] = [
      '#type' => 'pager',
    ];

    return $build;
  }

}
