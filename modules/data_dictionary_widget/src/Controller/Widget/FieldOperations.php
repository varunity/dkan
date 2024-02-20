<?php

namespace Drupal\data_dictionary_widget\Controller\Widget;

use Drupal\Core\Controller\ControllerBase;

/**
 * Various operations for the Data Dictionary Widget.
 */
class FieldOperations extends ControllerBase {

  /**
   * Get a list of data dictionaries.
   */
  public static function getDataDictionaries() {
    $existing_identifiers = [];
    $node_storage = \Drupal::entityTypeManager()->getStorage('node');
    $query = $node_storage
      ->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'data')
      ->condition('field_data_type', 'data-dictionary', '=');
    $nodes_ids = $query->execute();
    $nodes = $node_storage->loadMultiple($nodes_ids);
    foreach ($nodes as $node) {
      $existing_identifiers[] = [
        'nid' => $node->id(),
        'identifier' => $node->uuid(),
      ];
    }

    return $existing_identifiers;
  }

  /**
   * Setting ajax elements.
   */
  public static function setAjaxElements(array $dictionaryFields) {
    foreach ($dictionaryFields['data']['#rows'] as $row => $data) {
      $edit_button = $dictionaryFields['edit_buttons'][$row] ?? NULL;
      $edit_fields = $dictionaryFields['edit_fields'][$row] ?? NULL;
      // Setting the ajax fields if they exsist.
      if ($edit_button) {
        $dictionaryFields['data']['#rows'][$row] = array_merge($data, $edit_button);
        unset($dictionaryFields['edit_buttons'][$row]);
      }
      elseif ($edit_fields) {
        unset($dictionaryFields['data']['#rows'][$row]);
        $dictionaryFields['data']['#rows'][$row]['field_collection'] = $edit_fields;
        // Remove the buttons so they don't show up twice.
        unset($dictionaryFields['edit_fields'][$row]);
        ksort($dictionaryFields['data']['#rows']);
      }

    }

    return $dictionaryFields;
  }

  /**
   * Function to generate the description for the "Format" field.
   *
   * @param string $dataType
   *   Field data type.
   *
   * @return string
   *   Description information.
   */
  public static function generateFormatDescription($dataType) {
    $description = "<p>The format of the data in this field. Supported formats depend on the specified field type:</p>";

    if ($dataType === 'string') {
      $description .= FieldValues::returnStringInfo('description');
    }

    if ($dataType === 'date') {
      $description = FieldValues::returnDateInfo('description');
    }

    if ($dataType === 'integer') {
      $description .= FieldValues::returnIntegerInfo('description');

    }

    if ($dataType === 'number') {
      $description .= FieldValues::returnNumberInfo('description');
    }
    return $description;
  }

  /**
   * Function to generate the options for the "Format" field.
   *
   * @param string $dataType
   *   Field data type.
   *
   * @return array
   *   List of format options.
   */
  public static function setFormatOptions($dataType) {

    if ($dataType === 'string') {
      $options = FieldValues::returnStringInfo('options');
    }

    if ($dataType === 'date') {
      $options = FieldValues::returnDateInfo('options');
    }

    if ($dataType === 'integer') {
      $options = FieldValues::returnIntegerInfo('options');

    }

    if ($dataType === 'number') {
      $options = FieldValues::returnNumberInfo('options');
    }

    return $options;

  }

  /**
   * Cleaning the data up.
   */
  public static function processDataResults($data_results, $current_fields, $field_values, $op) {
    if (isset($current_fields)) {
      $data_results = $current_fields;
    }

    if (isset($field_values["field_json_metadata"][0]["dictionary_fields"]["field_collection"])) {
      $field_group = $field_values["field_json_metadata"][0]["dictionary_fields"]["field_collection"]["group"];
      $field_format = $field_group["format"] == 'other' ? $field_group["format_other"] : $field_group["format"];

      $data_pre = [
        [
          "name" => $field_group["name"],
          "title" => $field_group["title"],
          "type" => $field_group["type"],
          "format" => $field_format,
          "description" => $field_group["description"],
        ],
      ];

    }

    if (isset($data_pre) && $op === "add") {
      $data_results = isset($current_fields) ? array_merge($current_fields, $data_pre) : $data_pre;
    }

    return $data_results;
  }

  /**
   * Return acceptable edit actions.
   */
  public static function editActions() {
    return [
      'format',
      'edit',
      'update',
      'abort',
      'delete',
    ];
  }

  /**
   * Set Field Type Options.
   */
  public static function setTypeOptions() {
    return [
      'string' => t('String'),
      'date' => t('Date'),
      'integer' => t('Integer'),
      'number' => t('Number'),
    ];
  }

  /**
   * Return true if field is being edited.
   */
  public static function checkEditingField($key, $op_index, $fields_being_modified) {
    $action_list = FieldOperations::editActions();
    if (isset($op_index[0]) && in_array($op_index[0], $action_list) && array_key_exists($key, $fields_being_modified)) {
      return TRUE;
    }
    else {
      return FALSE;
    }
  }

  /**
   * Return true if field collection is present.
   */
  public static function checkFieldCollection($data_pre, $op) {
    if (isset($data_pre) && $op === "add") {
      return TRUE;
    }
    else {
      return FALSE;
    }
  }

  /**
   * Set the elements associated with adding a new field.
   */
  public static function setAddFormState($add_new_field, $element) {
    if ($add_new_field) {

      $element['dictionary_fields']['field_collection'] = $add_new_field;
      $element['dictionary_fields']['field_collection']['#access'] = TRUE;
      $element['dictionary_fields']['add_row_button']['#access'] = FALSE;
      $element['identifier']['#required'] = FALSE;
      $element['title']['#required'] = FALSE;
    }
    return $element;
  }

  /**
   * Create edit and update fields where needed.
   */
  public static function createDictionaryFieldOptions($op_index, $data_results, $fields_being_modified, $element) {
    $current_fields = $element['current_fields'];
    // Creating ajax buttons/fields to be placed in correct location later.
    foreach ($data_results as $key => $data) {
      if (self::checkEditingField($key, $op_index, $fields_being_modified)) {
        $element['edit_fields'][$key] = FieldEditCreation::editFields($key, $current_fields, $fields_being_modified);
      }
      else {
        $element['edit_buttons'][$key]['edit_button'] = FieldButtons::editButtons($key);
      }
    }
    $element['add_row_button'] = FieldButtons::addButton();

    return $element;
  }

}
