<?php

namespace Drupal\openy_repeat\Controller;

use Drupal\Component\Utility\UrlHelper;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Datetime\DrupalDateTime;
use Drupal\node\Entity\Node;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\JsonResponse;
use Drupal\Core\Cache\CacheBackendInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManager;
use Drupal\Core\Entity\Query\QueryFactory;

/**
 * {@inheritdoc}
 */
class RepeatController extends ControllerBase {

  /**
   * Cache default.
   *
   * @var \Drupal\Core\Cache\CacheBackendInterface
   */
  protected $cache;

  /**
   * The Database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected $database;

  /**
   * The entity query factory.
   *
   * @var \Drupal\Core\Entity\Query\QueryFactory
   */
  protected $entityQuery;

  /**
   * The EntityTypeManager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManager
   */
  protected $entity_type_manager;

  /**
   * The date formatter service.
   *
   * @var \Drupal\Core\Datetime\DateFormatterInterface
   */
  protected $dateFormatter;

  /**
   * Creates a new RepeatController.
   *
   * @param CacheBackendInterface $cache
   *   Cache default.
   * @param Connection $database
   *   The Database connection.
   * @param EntityTypeManager $entity_type_manager
   *   The EntityTypeManager.
   * @param DateFormatterInterface $date_formatter
   *   The Date formatter.
   */
  public function __construct(CacheBackendInterface $cache, Connection $database, QueryFactory $entity_query, EntityTypeManager $entity_type_manager, DateFormatterInterface $date_formatter) {
    $this->cache = $cache;
    $this->database = $database;
    $this->entityQuery = $entity_query;
    $this->entityTypeManager = $entity_type_manager;
    $this->dateFormatter = $date_formatter;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('cache.default'),
      $container->get('database'),
      $container->get('entity.query'),
      $container->get('entity_type.manager'),
      $container->get('date.formatter')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function ajaxScheduler(Request $request, $location, $date, $category) {
    $result = $this->getData($request, $location, $date, $category);
    return new JsonResponse($result);
  }

  /**
   * {@inheritdoc}
   */
  public function getData($request, $location, $date, $category) {
    if (empty($date)) {
      $date = date('F j, l 00:00:00');
    }
    $date = strtotime($date);

    $year = date('Y', $date);
    $month = date('m', $date);
    $day = date('d', $date);
    $week = date('W', $date);
    $weekday = date('N', $date);

    $timestamp_start = $date;
    // Next day.
    $timestamp_end = $date + 24 * 60 * 60;

    $query = $this->database->select('node', 'n');
    $query->rightJoin('repeat_event', 're', 're.session = n.nid');
    $query->innerJoin('node_field_data', 'nd', 're.location = nd.nid');
    $query->innerJoin('node_field_data', 'nds', 'n.nid = nds.nid');
    $query->addField('n', 'nid');
    $query->addField('nd', 'title', 'location');
    $query->addField('nds', 'title', 'name');
    $query->fields('re', [
      'class',
      'session',
      'room',
      'instructor',
      'category',
      'register_url',
      'register_text',
      'duration',
    ]);
    $query->addField('re', 'start', 'start_timestamp');
    $query->addField('re', 'end', 'end_timestamp');
    // Query conditions.
    $query->distinct();
    $year_condition_group = $query->orConditionGroup()
      ->condition('re.year', $year)
      ->condition('re.year', '*');
    $month_condition_group = $query->orConditionGroup()
      ->condition('re.month', $month)
      ->condition('re.month', '*');
    $day_condition_group = $query->orConditionGroup()
      ->condition('re.day', $day)
      ->condition('re.day', '*');
    $week_condition_group = $query->orConditionGroup()
      ->condition('re.week', $week)
      ->condition('re.week', '*');
    $weekday_condition_group = $query->orConditionGroup()
      ->condition('re.weekday', $weekday)
      ->condition('re.weekday', '*');
    $query->condition('n.type', 'session');
    $query->condition($year_condition_group);
    $query->condition($month_condition_group);
    $query->condition($day_condition_group);
    $query->condition($week_condition_group);
    $query->condition($weekday_condition_group);
    $query->condition('re.start', $timestamp_end, '<=');
    $query->condition('re.end', $timestamp_start, '>=');
    if (!empty($category)) {
      $query->condition('re.category', explode(',', $category), 'IN');
    }
    if (!empty($location)) {
      $query->condition('nd.title', explode(',', $location), 'IN');
    }
    $exclusions = $request->get('excl');
    if (!empty($exclusions)) {
      $query->condition('re.category', explode(',', $exclusions), 'NOT IN');
    }
    $limit = $request->get('limit');
    if (!empty($limit)) {
      $query->condition('re.category', explode(',', $limit), 'IN');
    }
    $query->addTag('openy_repeat_get_data');
    $result = $query->execute()->fetchAll();

    $locations_info = $this->getLocationsInfo();

    $classesIds = [];
    foreach ($result as $key => $item) {
      $classesIds[$item->class] = $item->class;
    }
    $classes_info = $this->getClassesInfo($classesIds);

    foreach ($result as $key => $item) {
      $result[$key]->location_info = $locations_info[$item->location];

      if (isset($classes_info[$item->class]['path'])) {
        $query = UrlHelper::buildQuery([
          'session' => $item->session,
          'location' => $locations_info[$item->location]['nid'],
        ]);
        $classes_info[$item->class]['path'] .= '?' . $query;
      }

      $result[$key]->class_info = $classes_info[$item->class];

      $result[$key]->time_start_sort = $this->dateFormatter->format((int)$item->start_timestamp, 'custom', 'Hi');

      // Convert timezones for start_time and end_time.
      $result[$key]->time_start = $this->dateFormatter->format((int)$item->start_timestamp, 'custom', 'g:i');
      $result[$key]->time_end = $this->dateFormatter->format((int)$item->start_timestamp + $item->duration * 60, 'custom', 'g:iA');

      // Example of calendar format 2018-08-21 14:15:00.
      $result[$key]->time_start_calendar = $this->dateFormatter->format((int)$item->start_timestamp, 'custom', 'Y-m-d H:i:s');
      $result[$key]->time_end_calendar = $this->dateFormatter->format((int)$item->start_timestamp + $item->duration * 60, 'custom', 'Y-m-d H:i:s');
      $result[$key]->timezone = drupal_get_user_timezone();

      // Durations.
      $result[$key]->duration_minutes = $item->duration % 60;
      $result[$key]->duration_hours = ($item->duration - $result[$key]->duration_minutes) / 60;
    }

    usort($result, function($item1, $item2){
      if ((int) $item1->time_start_sort == (int) $item2->time_start_sort) {
        return 0;
      }
      return (int) $item1->time_start_sort < (int) $item2->time_start_sort ? -1 : 1;
    });

    $this->moduleHandler()->alter('openy_repeat_results', $result, $request);

    return $result;
  }

  /**
   * Return Location from "Session" node type.
   *
   * @return array
   */
  public function getLocations() {
    $sql = "SELECT DISTINCT nd.title as location
            FROM {node} n
            INNER JOIN node__field_session_location l ON n.nid = l.entity_id AND l.bundle = 'session'
            INNER JOIN node_field_data nd ON l.field_session_location_target_id = nd.nid
            WHERE n.type = 'session'";

    $query = $this->database->query($sql);

    return $query->fetchCol();
  }

  /**
   * Get detailed info about Location (aka branch).
   */
  public function getLocationsInfo() {
    $data = [];
    $tags = ['node_list'];
    $cid = 'openy_repeat:locations_info';
    if ($cache = $this->cache->get($cid)) {
      $data = $cache->data;
    }
    else {
      $nids = $this->entityQuery
        ->get('node')
        ->condition('type', ['branch', 'location'], 'IN')
        ->execute();
      $nids_chunked = array_chunk($nids, 20, TRUE);
      foreach ($nids_chunked as $chunk) {
        $branches = $this->entityTypeManager->getStorage('node')->loadMultiple($chunk);
        if (!empty($branches)) {
          foreach ($branches as $node) {
            $days = $node->get('field_branch_hours')->getValue();
            $address = $node->get('field_location_address')->getValue();
            if (!empty($address[0])) {
              $address = array_filter($address[0]);
              $address = implode(', ', $address);
            }
            $data[$node->title->value] = [
              'nid' => $node->nid->value,
              'title' => $node->title->value,
              'email' => $node->field_location_email->value,
              'phone' => $node->field_location_phone->value,
              'address' => $address,
              'days' => !empty($days[0]) ? $this->getFormattedHours($days[0]) : [],
            ];
            $tags[] = 'node:' . $node->nid->value;
          }
        }
      }
      $this->cache->set($cid, $data, CacheBackendInterface::CACHE_PERMANENT, $tags);
    }

    return $data;
  }

  /**
   * Get detailed info about Class.
   */
  public function getClassesInfo($nids) {
    $data = [];
    $tags = [];
    $cid = 'openy_repeat:classes_info' . md5(json_encode($nids));
    if ($cache = $this->cache->get($cid)) {
      $data = $cache->data;
    }
    else {
      $nids_chunked = array_chunk($nids, 20, TRUE);
      foreach ($nids_chunked as $chunk) {
        $classes = $this->entityTypeManager->getStorage('node')->loadMultiple($chunk);
        if (!empty($classes)) {
          foreach ($classes as $node) {
            $data[$node->nid->value] = [
              'nid' => $node->nid->value,
              'path' => $node->toUrl()->setAbsolute()->toString(),
              'title' => $node->title->value,
              'description' => html_entity_decode(strip_tags(text_summary($node->field_class_description->value, $node->field_class_description->format, 600))),
            ];
            $tags[] = 'node:' . $node->nid->value;
          }
        }
      }
      $this->cache->set($cid, $data, CacheBackendInterface::CACHE_PERMANENT, $tags);
    }

    return $data;
  }


  public function getFormattedHours($data) {
    $lazy_hours = $groups = $rows = [];
    foreach ($data as $day => $value) {
      // Do not process label. Store it name for later usage.
      if ($day == 'hours_label') {
        continue;
      }

      $day = str_replace('hours_', '', $day);
      $value = $value ? $value : 'closed';
      $lazy_hours[$day] = $value;
      if ($groups && end($groups)['value'] == $value) {
        $array_keys = array_keys($groups);
        $group = &$groups[end($array_keys)];
        $group['days'][] = $day;
      }
      else {
        $groups[] = [
          'value' => $value,
          'days' => [$day],
        ];
      }
    }

    foreach ($groups as $group_item) {
      $title = sprintf('%s - %s', ucfirst(reset($group_item['days'])), ucfirst(end($group_item['days'])));
      if (count($group_item['days']) == 1) {
        $title = ucfirst(reset($group_item['days']));
      }
      $hours = $group_item['value'];
      $rows[] = [$title . ':', $hours];
    }

    return $rows;
  }

  /**
   * Returns PDF for specific parameters.
   */
  public function getPdf(Request $request) {
    $content = $this->getPdfContent($request);
    $settings = [
      'body' => [
        '#content' => [
          'logo_url' => drupal_get_path('module', 'openy_repeat') . '/img/ymca_logo_black.png',
          'result' => $content['content']['content'],
          'header' => $content['content']['header']
        ],
        '#theme' => $content['theme'],
        '#cache' => [
          'max-age' => 0
        ],
      ],
      'title' => $this->t("Download PDF schedule"),
      '#cache' => [
        'max-age' => 0
      ],
    ];
    \Drupal::service('openy_repeat_pdf_generator')->generatePDF($settings);
  }

  /**
   * Returns content for a PDF.
   */
  public function getPdfContent($request) {
    // Get all parameters from query.
    $parameters = $request->query->all();
    $category = !empty($parameters['categories']) ? $parameters['categories'] : '0';
    $rooms = !empty($parameters['rooms']) ? $parameters['rooms'] : '';
    $classnames = !empty($parameters['cn']) ? $parameters['cn'] : [];
    $location = !empty($parameters['locations']) ? $parameters['locations'] : '0';
    $date = !empty($parameters['date']) ? $parameters['date'] : '';
    $mode = !empty($parameters['mode']) ? $parameters['mode'] : 'activity';

    if (empty($date)) {
      $date = time();
    }
    else {
      $date = strtotime($date);
    }

    // Calculate first day of a week.
    $monday_timestamp = strtotime("last Monday", $date);
    if (date('D', $date) === 'Mon') {
      $monday_timestamp = $date;
    }
    $timestamp_start = $monday_timestamp;

    $result = [];
    // Create weekly schedule by getting results for every weekday.
    for ($i = 1; $i <= 7; $i++) {
      $date = DrupalDateTime::createFromTimestamp($timestamp_start);
      $result[$date->format('Y-m-d')] = $this->getData($request, $location, $date->format('F j, l 00:00:00'), $category);
      $timestamp_start += 86400;
    }
    if (!empty($rooms)) {
      $rooms = explode(',', $rooms);
    }
    // Group by activity.
    if ($mode == 'activity') {
      $result = $this->groupByActivity($result, $rooms, $classnames);
      $theme = 'openy_repeat__pdf__table__activity';
    }
    // Group by day.
    if ($mode == 'day') {
      $result = $this->groupByDay($result, $rooms, $classnames);
      $theme = 'openy_repeat__pdf__table__day';
    }

    $content = [
      'content' => $result,
      'theme' => $theme,
    ];

    return $content;
  }

  /**
   * Group results by Activity & Location.
   */
  public function groupByActivity($result, $rooms, $classnames = []) {
    if (empty($result)) {
      return FALSE;
    }
    $date_keys = $formatted_result = [];

    // Create weekdays array.
    foreach ($result as $day => $data) {
      $date_keys[$day] = [];
    }
    $arr_date_keys = array_keys($date_keys);
    $first = reset($arr_date_keys);
    $last = end($arr_date_keys);
    $first = DrupalDateTime::createFromFormat('Y-m-d', $first)->format('F jS');
    $last = DrupalDateTime::createFromFormat('Y-m-d', $last)->format('F jS');
    $formatted_result['header'] = [
      'dates' => $first . ' - ' . $last,
    ];

    // Create activities array pass weekdays array to each.
    foreach ($result as $day => $data) {
      foreach ($data as $key => $session) {
        // Additionally filter by room.
        if (!empty($rooms) && !in_array($session->location . '||' . $session->room, $rooms)) {
          $location_found = FALSE;
          foreach ($rooms as $room) {
            // Keep locations with no selected rooms (means all are selected).
            if (strpos($room, $session->location) !== FALSE) {
              $location_found = TRUE;
            }
          }
          if ($location_found) {
            unset($result[$day][$key]);
            continue;
          }
        }
        if ($classnames && !in_array($session->name, $classnames)) {
          unset($result[$day][$key]);
          continue;
        }

        $formatted_result['content'][$session->location][$session->name] = [
          'room' => $session->room,
          'dates' => $date_keys
        ];
      }
    }
    foreach ($result as $day => $data) {
      foreach ($data as $session) {
        $formatted_result['content'][$session->location][$session->name]['dates'][$day][] = [
          'time' => $session->time_start . '-' . $session->time_end,
          'category' => $session->category,
        ];
      }
    }
    return $formatted_result;
  }


  /**
   * Group results by day.
   */
  public function groupByDay($result, $rooms, $classnames = []) {
    if (empty($result)) {
      return FALSE;
    }
    $date_keys = $formatted_result = [];

    // Create weekdays array.
    foreach ($result as $day => $data) {
      $date_keys[$day] = [];
    }
    $arr_date_keys = array_keys($date_keys);
    $first = reset($arr_date_keys);
    $last = end($arr_date_keys);
    $first = DrupalDateTime::createFromFormat('Y-m-d', $first)->format('F jS');
    $last = DrupalDateTime::createFromFormat('Y-m-d', $last)->format('F jS');
    $formatted_result['header'] = [
      'dates' => $first . ' - ' . $last,
    ];

    foreach ($result as $day => $data) {
      foreach ($data as $key => $session) {
        // Additionally filter by room.
        if (!empty($rooms) && !in_array($session->location . '||' . $session->room, $rooms)) {
          $location_found = FALSE;
          foreach ($rooms as $room) {
            // Keep locations with no selected rooms (means all are selected).
            if (strpos($room, $session->location) !== FALSE) {
              $location_found = TRUE;
            }
          }
          if ($location_found) {
            unset($result[$day][$key]);
            continue;
          }
        }
        if ($classnames && !in_array($session->name, $classnames)) {
          unset($result[$day][$key]);
          continue;
        }

        $weekday = DrupalDateTime::createFromFormat('Y-m-d', $day)->format('l');
        $formatted_result['content'][$session->category . '|' .$session->location][$weekday][$session->time_start . '-' . $session->time_end][] = [
          'room' => $session->room,
          'name' => $session->name,
          'category' => $session->category,
          'instructor' => $session->instructor,
        ];
      }
    }
    return $formatted_result;
  }

}
