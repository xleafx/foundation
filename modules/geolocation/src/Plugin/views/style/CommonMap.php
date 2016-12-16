<?php

namespace Drupal\geolocation\Plugin\views\style;

use Drupal\views\Plugin\views\style\StylePluginBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\geolocation\Plugin\views\field\GeolocationField;
use Drupal\geolocation\GoogleMapsDisplayTrait;
use Drupal\image\Entity\ImageStyle;

/**
 * Allow to display several field items on a common map.
 *
 * @ingroup views_style_plugins
 *
 * @ViewsStyle(
 *   id = "maps_common",
 *   title = @Translation("Geolocation - CommonMap"),
 *   help = @Translation("Display geolocations on a common map."),
 *   theme = "views_view_list",
 *   display_types = {"normal"},
 * )
 */
class CommonMap extends StylePluginBase {

  use GoogleMapsDisplayTrait;

  protected $usesFields = TRUE;
  protected $usesRowPlugin = TRUE;
  protected $usesRowClass = FALSE;
  protected $usesGrouping = FALSE;

  /**
   * Map update option handling.
   *
   * Dynamic map and client location and potentially others update the view by
   * information determined on the client site. They may want to update the
   * view result as well. So we need to provide the possible ways to do that.
   *
   * @return array
   *   The determined options.
   */
  protected function getMapUpdateOptions() {
    $options = [
      'boundary_filters' => [],
      'boundary_filters_exposed' => [],
      'map_update_options' => [],
    ];

    $filters = $this->displayHandler->getOption('filters');
    foreach ($filters as $filter_name => $filter) {
      if (empty($filter['plugin_id'])) {
        continue;
      }

      /** @var \Drupal\views\Plugin\views\filter\FilterPluginBase $filter_handler */
      $filter_handler = $this->displayHandler->getHandler('filter', $filter_name);

      switch ($filter['plugin_id']) {
        case 'geolocation_filter_boundary':
          $map_update_target_options['boundary_filters'][$filter_name] = $filter_handler;
          if ($filter_handler->isExposed()) {
            $options['boundary_filters_exposed'][$filter_name] = $filter_handler;
          }
          break;
      }
    }

    foreach ($options['boundary_filters_exposed'] as $filter_name => $filter_handler) {
      $options['map_update_options']['boundary_filter_' . $filter_name] = $this->t('Boundary Filter') . ' - ' . $filter_handler->adminLabel();
    }

    return $options;
  }

  /**
   * {@inheritdoc}
   */
  public function evenEmpty() {
    return $this->options['even_empty'] ? TRUE : FALSE;
  }

  /**
   * {@inheritdoc}
   */
  public function render() {

    if (!empty($this->options['geolocation_field'])) {
      $geo_field = $this->options['geolocation_field'];
    }
    else {
      \Drupal::logger('geolocation')->error("The geolocation common map views style was called without a geolocation field defined in the views style settings.");
      return [];
    }

    if (
      !empty($this->options['title_field'])
      && $this->options['title_field'] != 'none'
    ) {
      $title_field = $this->options['title_field'];
    }

    if (
      !empty($this->options['icon_field'])
      && $this->options['icon_field'] != 'none'
    ) {
      $icon_field = $this->options['icon_field'];
    }

    $map_id = $this->view->dom_id;

    $build = [
      '#theme' => 'geolocation_common_map_display',
      '#id' => $map_id,
      '#attached' => [
        'library' => [
          'geolocation/geolocation.commonmap',
        ],
        'drupalSettings' => [
          'geolocation' => [
            'commonMap' => [
              $map_id => [
                'settings' => $this->getGoogleMapsSettings($this->options),
              ],
            ],
            'google_map_api_key' => \Drupal::config('geolocation.settings')->get('google_map_api_key'),
            'google_map_additional_parameters' => \Drupal::config('geolocation.settings')->get('google_map_additional_parameters'),
          ],
        ],
      ],
    ];

    /*
     * Dynamic map handling.
     */
    if (!empty($this->options['dynamic_map']['enabled'])) {

      if (!empty($this->options['dynamic_map']['update_target']) && $this->view->displayHandlers->has($this->options['dynamic_map']['update_target'])) {
        $update_view_id = $this->view->id();
        $update_view_display_id = $this->options['dynamic_map']['update_target'];
        $update_dom_id = NULL;
      }
      else {
        $update_dom_id = $this->view->dom_id;
        $update_view_id = $this->view->id();
        $update_view_display_id = $this->view->current_display;
      }

      $build['#attached']['drupalSettings']['geolocation']['commonMap'][$map_id]['dynamic_map'] = [
        'enable' => TRUE,
        'hide_form' => $this->options['dynamic_map']['hide_form'],
      ];

      if (!empty($update_dom_id)) {
        $build['#attached']['drupalSettings']['geolocation']['commonMap'][$map_id]['dynamic_map']['update_dom_id'] = $update_dom_id;
      }
      else {
        $build['#attached']['drupalSettings']['geolocation']['commonMap'][$map_id]['dynamic_map'] += [
          'update_view_id' => $update_view_id,
          'update_view_display_id' => $update_view_display_id,
        ];
      }

      if (substr($this->options['dynamic_map']['update_handler'], 0, strlen('boundary_filter_')) === 'boundary_filter_') {
        $filter_id = substr($this->options['dynamic_map']['update_handler'], strlen('boundary_filter_'));
        $filters = $this->displayHandler->getOption('filters');
        $filter_options = $filters[$filter_id];
        $build['#attached']['drupalSettings']['geolocation']['commonMap'][$map_id]['dynamic_map'] += [
          'boundary_filter' => TRUE,
          'parameter_identifier' => $filter_options['expose']['identifier'],
        ];
      }
    }

    /*
     * Add locations to output.
     */
    foreach ($this->view->result as $row) {
      if (!empty($title_field)) {
        $title_field_handler = $this->view->field[$title_field];
        $title_build = array(
          '#theme' => $title_field_handler->themeFunctions(),
          '#view' => $title_field_handler->view,
          '#field' => $title_field_handler,
          '#row' => $row,
        );
      }

      if ($this->view->field[$geo_field] instanceof GeolocationField) {
        /** @var \Drupal\geolocation\Plugin\views\field\GeolocationField $geolocation_field */
        $geolocation_field = $this->view->field[$geo_field];
        $geo_items = $geolocation_field->getItems($row);
      }
      else {
        return $build;
      }

      if (!empty($icon_field)) {
        /** @var \Drupal\views\Plugin\views\field\Field $icon_field_handler */
        $icon_field_handler = $this->view->field[$icon_field];
        if (!empty($icon_field_handler)) {
          $image_items = $icon_field_handler->getItems($row);
          if (!empty($image_items[0])) {
            /** @var \Drupal\image\Plugin\Field\FieldType\ImageItem $item */
            $item = $image_items[0]['rendered']['#item'];
            /** @var \Drupal\image\Entity\ImageStyle $style */
            $style = ImageStyle::load($image_items[0]['rendered']['#image_style']);
            if (!empty($style)) {
              $icon_url = $style->buildUrl($item->entity->getFileUri());
            }
          }
        }
      }

      foreach ($geo_items as $delta => $item) {
        $geolocation = $item['raw'];
        $position = [
          'lat' => $geolocation->lat,
          'lng' => $geolocation->lng,
        ];

        $location = [
          '#theme' => 'geolocation_common_map_location',
          '#content' => $this->view->rowPlugin->render($row),
          '#title' => empty($title_build) ? '' : $title_build,
          '#position' => $position,
        ];

        if (!empty($icon_url)) {
          $location['#icon'] = $icon_url;
        }

        $build['#locations'][] = $location;
      }
    }

    $centre = NULL;
    $fitbounds = FALSE;

    // Maps will not load without any centre defined.
    if (!is_array($this->options['centre'])) {
      return $build;
    }

    /*
     * Centre handling.
     */
    foreach ($this->options['centre'] as $id => $option) {
      // Ignore if not enabled.
      if (empty($option['enable'])) {
        continue;
      }

      // Ignore if fitBounds is enabled, as it will supersede any other option.
      if ($fitbounds) {
        break;
      }

      // Ignore if center is already set.
      if (isset($centre['lat']) && isset($centre['lng'])) {
        break;
      }

      switch ($id) {

        case 'fixed_value':
          $centre = [
            'lat' => (float) $option['settings']['latitude'],
            'lng' => (float) $option['settings']['longitude'],
          ];
          break;

        case 'first_row':
          if (!empty($build['#locations'][0]['#position'])) {
            $centre = $build['#locations'][0]['#position'];
          }
          break;

        case 'fit_bounds':
          // fitBounds will only work when at least one result is available.
          if (!empty($build['#locations'][0]['#position'])) {
            $fitbounds = TRUE;
          }
          break;

        case 'client_location':
          $build['#clientlocation'] = TRUE;
          $build['#attached']['drupalSettings']['geolocation']['commonMap'][$map_id]['client_location'] = [
            'enable' => TRUE,
          ];

          if (
            !empty($option['settings']['update_map'])
            && !empty($option['settings']['update_map_option'])
          ) {
            $build['#attached']['drupalSettings']['geolocation']['commonMap'][$map_id]['client_location']['update_map'] = TRUE;
          }
          break;

        /*
         * Handle the dynamic options.
         */
        default:
          if (preg_match('/proximity_filter_*/', $id)) {
            $filter_id = substr($id, 17);
            /** @var \Drupal\geolocation\Plugin\views\filter\ProximityFilter $handler */
            $handler = $this->displayHandler->getHandler('filter', $filter_id);
            if (isset($handler->value['lat']) && isset($handler->value['lng'])) {
              $centre = [
                'lat' => (float) $handler->value['lat'],
                'lng' => (float) $handler->value['lng'],
              ];
            }
            break;
          }
          elseif (preg_match('/boundary_filter_*/', $id)) {
            $filter_id = substr($id, 16);
            /** @var \Drupal\geolocation\Plugin\views\filter\ProximityFilter $handler */
            $handler = $this->displayHandler->getHandler('filter', $filter_id);
            if (
              isset($handler->value['lat_north_east'])
              && isset($handler->value['lng_north_east'])
              && isset($handler->value['lat_south_west'])
              && isset($handler->value['lng_south_west'])
            ) {
              $centre = [
                'lat_north_east' => (float) $handler->value['lat_north_east'],
                'lng_north_east' => (float) $handler->value['lng_north_east'],
                'lat_south_west' => (float) $handler->value['lat_south_west'],
                'lng_south_west' => (float) $handler->value['lng_south_west'],
              ];
            }
            break;
          }
      }
    }

    if (!empty($centre)) {
      $build['#centre'] = $centre ?: ['lat' => 0, 'lng' => 0];
    }
    $build['#fitbounds'] = $fitbounds;

    return $build;
  }

  /**
   * {@inheritdoc}
   */
  protected function defineOptions() {
    $options = parent::defineOptions();

    $options['even_empty'] = ['default' => '0'];
    $options['geolocation_field'] = ['default' => ''];
    $options['title_field'] = ['default' => ''];
    $options['icon_field'] = ['default' => ''];
    $options['dynamic_map'] = [
      'default' => TRUE,
      'enabled' => ['default' => 0],
      'update_handler' => ['default' => ''],
      'hide_form' => ['default' => 0],
    ];
    $options['centre'] = ['default' => ''];

    foreach (self::getGoogleMapDefaultSettings() as $key => $setting) {
      $options[$key] = ['default' => $setting];
    }

    return $options;
  }

  /**
   * {@inheritdoc}
   */
  public function buildOptionsForm(&$form, FormStateInterface $form_state) {

    parent::buildOptionsForm($form, $form_state);

    $labels = $this->displayHandler->getFieldLabels();
    $fieldMap = \Drupal::service('entity_field.manager')->getFieldMap();
    $geo_options = [];
    $title_options = [];
    $icon_options = [];

    $fields = $this->displayHandler->getOption('fields');
    foreach ($fields as $field_name => $field) {
      if ($field['plugin_id'] == 'geolocation_field') {
        $geo_options[$field_name] = $labels[$field_name];
      }

      if (
        $field['plugin_id'] == 'field'
        && !empty($field['entity_type'])
        && !empty($field['entity_field'])
      ) {
        if (
          !empty($fieldMap[$field['entity_type']][$field['entity_field']]['type'])
          && $fieldMap[$field['entity_type']][$field['entity_field']]['type'] == 'geolocation'
        ) {
          $geo_options[$field_name] = $labels[$field_name];
        }
      }

      if (!empty($field['type']) && $field['type'] == 'image') {
        $icon_options[$field_name] = $labels[$field_name];
      }

      if (!empty($field['type']) && $field['type'] == 'string') {
        $title_options[$field_name] = $labels[$field_name];
      }
    }

    $form['even_empty'] = [
      '#title' => $this->t('Display map when no locations are found.'),
      '#type' => 'checkbox',
      '#default_value' => $this->options['even_empty'],
    ];

    $form['geolocation_field'] = [
      '#title' => $this->t('Geolocation source field'),
      '#type' => 'select',
      '#default_value' => $this->options['geolocation_field'],
      '#description' => $this->t("The source of geodata for each entity."),
      '#options' => $geo_options,
      '#required' => TRUE,
    ];

    $form['title_field'] = [
      '#title' => $this->t('Title source field'),
      '#type' => 'select',
      '#default_value' => $this->options['title_field'],
      '#description' => $this->t("The source of the title for each entity. Field type must be 'string'."),
      '#options' => $title_options,
      '#empty_value' => 'none',
    ];

    $form['icon_field'] = [
      '#title' => $this->t('Icon source field'),
      '#type' => 'select',
      '#default_value' => $this->options['icon_field'],
      '#description' => $this->t("Optional image (field) to use as icon."),
      '#options' => $icon_options,
      '#empty_value' => 'none',
    ];

    $map_update_target_options = $this->getMapUpdateOptions();

    /*
     * Dynamic map handling.
     */
    if (!empty($map_update_target_options['map_update_options'])) {
      $form['dynamic_map'] = [
        '#title' => $this->t('Dynamic Map'),
        '#type' => 'fieldset',
      ];
      $form['dynamic_map']['enabled'] = [
        '#title' => $this->t('Update view on map boundary changes. Also known as "AirBnB" style.'),
        '#type' => 'checkbox',
        '#default_value' => $this->options['dynamic_map']['enabled'],
        '#description' => $this->t("If enabled, moving the map will filter results based on current map boundary. This functionality requires an exposed boundary filter. Enabling AJAX is highly recommend for best user experience. If additional views are to be updated with the map change as well, it is highly recommended to use the view containing the map as 'parent' and the additional views as attachments."),
      ];

      $form['dynamic_map']['update_handler'] = [
        '#title' => $this->t('Dynamic map update handler'),
        '#type' => 'select',
        '#default_value' => $this->options['dynamic_map']['update_handler'],
        '#description' => $this->t("The map has to know how to feed back the update boundary data to the view."),
        '#options' => $map_update_target_options['map_update_options'],
        '#states' => [
          'visible' => [
            ':input[name="style_options[dynamic_map][enabled]"]' => ['checked' => TRUE],
          ],
        ],
      ];

      $form['dynamic_map']['hide_form'] = [
        '#title' => $this->t('Hide exposed filter form element if applicable.'),
        '#type' => 'checkbox',
        '#default_value' => $this->options['dynamic_map']['hide_form'],
        '#states' => [
          'visible' => [
            ':input[name="style_options[dynamic_map][enabled]"]' => ['checked' => TRUE],
          ],
        ],
      ];

      if ($this->displayHandler->getPluginId() !== 'page') {
        $update_targets = [
          $this->displayHandler->display['id'] => t('- This display -'),
        ];
        foreach ($this->view->displayHandlers->getInstanceIds() as $instance_id) {
          $display_instance = $this->view->displayHandlers->get($instance_id);
          if ($display_instance->getPluginId() == 'page') {
            $update_targets[$instance_id] = $display_instance->getPluginDefinition()['admin'];
          }
        }
        if (!empty($update_targets)) {
          $form['dynamic_map']['update_target'] = [
            '#title' => $this->t('Dynamic map update target'),
            '#type' => 'select',
            '#default_value' => $this->options['dynamic_map']['update_target'],
            '#description' => $this->t("Non-page displays will only update themselves. Most likely a page view should be updated instead."),
            '#options' => $update_targets,
            '#states' => [
              'visible' => [
                ':input[name="style_options[dynamic_map][enabled]"]' => ['checked' => TRUE],
              ],
            ],
          ];
        }
      }
    }

    /*
     * Centre handling.
     */
    $options = [
      'fit_bounds' => $this->t('Automatically fit map bounds to results. Disregards any set center or zoom.'),
      'first_row' => $this->t('Use first row as centre.'),
      'fixed_value' => $this->t('Provide fixed latitude and longitude.'),
      'client_location' => $this->t('Ask client for location via HTML5 geolocation API.'),
    ];

    $options += $map_update_target_options['map_update_options'];

    $form['centre'] = [
      '#type' => 'table',
      '#prefix' => $this->t('<h3>Centre options</h3>Please note: Each option will, if it can be applied, supersede any following option.'),
      '#header' => [
        t('Enable'),
        t('Option'),
        t('settings'),
        [
          'data' => t('Settings'),
          'colspan' => '1',
        ],
      ],
      '#attributes' => ['id' => 'geolocation-centre-options'],
      '#tabledrag' => [
        [
          'action' => 'order',
          'relationship' => 'sibling',
          'group' => 'geolocation-centre-option-weight',
        ],
      ],
    ];

    foreach ($options as $id => $label) {
      $weight = isset($this->options['centre'][$id]['weight']) ? $this->options['centre'][$id]['weight'] : 0;
      $form['centre'][$id]['#weight'] = $weight;

      $form['centre'][$id]['enable'] = [
        '#type' => 'checkbox',
        '#default_value' => isset($this->options['centre'][$id]['enable']) ? $this->options['centre'][$id]['enable'] : TRUE,
      ];

      $form['centre'][$id]['option'] = [
        '#markup' => $label,
      ];

      // Add tabledrag supprt.
      $form['centre'][$id]['#attributes']['class'][] = 'draggable';
      $form['centre'][$id]['weight'] = [
        '#type' => 'weight',
        '#title' => t('Weight for @option', ['@option' => $label]),
        '#title_display' => 'invisible',
        '#size' => 4,
        '#default_value' => $weight,
        '#attributes' => ['class' => ['geolocation-centre-option-weight']],
      ];
    }

    $form['centre']['client_location']['settings'] = [
      '#type' => 'container',
      'update_map' => [
        '#type' => 'checkbox',
        '#title' => $this->t('Additionally feed clients location back to view via dynamic map settings?'),
        '#default_value' => isset($this->options['centre']['client_location']['settings']['update_map']) ? $this->options['centre']['client_location']['settings']['update_map'] : FALSE,
      ],
      '#states' => [
        'visible' => [
          ':input[name="style_options[centre][client_location][enable]"]' => ['checked' => TRUE],
          ':input[name="style_options[dynamic_map][enabled]"]' => ['checked' => TRUE],
        ],
      ],
    ];

    $form['centre']['fixed_value']['settings'] = [
      '#type' => 'container',
      'latitude' => [
        '#type' => 'textfield',
        '#title' => t('Latitude'),
        '#default_value' => isset($this->options['centre']['fixed_value']['settings']['latitude']) ? $this->options['centre']['fixed_value']['settings']['latitude'] : '',
        '#size' => 60,
        '#maxlength' => 128,
      ],
      'longitude' => [
        '#type' => 'textfield',
        '#title' => t('Longitude'),
        '#default_value' => isset($this->options['centre']['fixed_value']['settings']['longitude']) ? $this->options['centre']['fixed_value']['settings']['longitude'] : '',
        '#size' => 60,
        '#maxlength' => 128,
      ],
      '#states' => [
        'visible' => [
          ':input[name="style_options[centre][fixed_value][enable]"]' => ['checked' => TRUE],
        ],
      ],
    ];

    uasort($form['centre'], 'Drupal\Component\Utility\SortArray::sortByWeightProperty');

    /*
     * Additional map settings.
     */
    $form += $this->getGoogleMapsSettingsForm($this->options);
  }

  /**
   * {@inheritdoc}
   */
  public function validateOptionsForm(&$form, FormStateInterface $form_state) {
    parent::validateOptionsForm($form, $form_state);
    $this->validateGoogleMapsSettingsForm($form, $form_state, 'style_options');
  }

}
