<?php

namespace Drupal\Tests\json_form_widget\Unit;

use PHPUnit\Framework\TestCase;
use Drupal\json_form_widget\FormBuilder;
use Drupal\json_form_widget\ArrayHelper;
use MockChain\Chain;
use Drupal\Component\DependencyInjection\Container;
use Drupal\Component\Utility\EmailValidator;
use Drupal\Core\Form\FormState;
use Drupal\Core\Logger\LoggerChannelFactory;
use Drupal\Core\StringTranslation\TranslationManager;
use Drupal\json_form_widget\FieldTypeRouter;
use Drupal\json_form_widget\ObjectHelper;
use Drupal\json_form_widget\SchemaUiHandler;
use Drupal\json_form_widget\StringHelper;
use Drupal\metastore\FileSchemaRetriever;
use MockChain\Options;
use stdClass;

/**
 * Test class for JsonFormWidget.
 */
class JsonFormBuilderTest extends TestCase {

  /**
   * Test.
   */
  public function testNoSchema() {
    $options = (new Options())
      ->add('dkan.metastore.schema_retriever', FileSchemaRetriever::class)
      ->add('json_form.string_helper', StringHelper::class)
      ->add('json_form.object_helper', ObjectHelper::class)
      ->add('json_form.array_helper', ArrayHelper::class)
      ->add('json_form.schema_ui_handler', SchemaUiHandler::class)
      ->add('logger.factory', LoggerChannelFactory::class)
      ->add('json_form.router', FieldTypeRouter::class)
      ->index(0);

    $container_chain = (new Chain($this))
      ->add(Container::class, 'get', $options)
      ->add(FileSchemaRetriever::class, 'retrieve', '')
      ->add(SchemaUiHandler::class, 'setSchemaUi');

    $container = $container_chain->getMock();

    \Drupal::setContainer($container);

    $form_builder = FormBuilder::create($container);

    $form_builder->setSchema('dataset');
    $this->assertEquals($form_builder->getJsonForm([]), []);
  }

  /**
   * Test.
   */
  public function testSchema() {
    $router = $this->getRouter();

    $options = (new Options())
      ->add('dkan.metastore.schema_retriever', FileSchemaRetriever::class)
      ->add('json_form.router', $router)
      ->add('json_form.schema_ui_handler', SchemaUiHandler::class)
      ->add('logger.factory', LoggerChannelFactory::class)
      ->add('string_translation', TranslationManager::class)
      ->index(0);

    $container_chain = (new Chain($this))
      ->add(Container::class, 'get', $options)
      ->add(FileSchemaRetriever::class, 'retrieve', '
      {
        "required": [
          "accessLevel"
        ],
        "properties":{
          "test":{
            "type":"string",
            "title":"Test field"
          },
          "downloadURL":{
            "title":"Download URL",
            "description":"This is an URL field.",
            "type":"string",
            "format":"uri"
          },
          "accessLevel": {
            "description": "Description.",
            "title": "Public Access Level",
            "type": "string",
            "enum": [
              "public",
              "restricted public",
              "non-public"
            ],
            "default": "public"
          },
          "accrualPeriodicity": {
            "title": "Frequency",
            "description": "Description.",
            "type": "string",
            "enum": [
              "R/P10Y",
              "R/P4Y"
            ],
            "enumNames": [
              "Decennial",
              "Quadrennial"
            ]
          }
        },
        "type":"object"
      }')
      ->add(SchemaUiHandler::class, 'setSchemaUi');

    $container = $container_chain->getMock();
    \Drupal::setContainer($container);

    $form_builder = FormBuilder::create($container);
    $form_builder->setSchema('dataset');
    $this->assertIsObject($form_builder->getSchema());
    $expected = [
      "test" => [
        "#type" => "textfield",
        "#title" => "Test field",
        "#description" => "",
        "#default_value" => "Some value.",
        "#required" => FALSE,
      ],
      "downloadURL" => [
        "#type" => "url",
        "#title" => "Download URL",
        "#description" => "This is an URL field.",
        "#default_value" => NULL,
        "#required" => FALSE,
      ],
      "accessLevel" => [
        "#type" => "select",
        "#title" => "Public Access Level",
        "#description" => "Description.",
        "#default_value" => "public",
        "#required" => TRUE,
        "#options" => [
          "public" => "public",
          "restricted public" => "restricted public",
          "non-public" => "non-public",
        ],
      ],
      "accrualPeriodicity" => [
        "#type" => "select",
        "#title" => "Frequency",
        "#description" => "Description.",
        "#default_value" => NULL,
        "#required" => FALSE,
        "#options" => [
          "R/P10Y" => "Decennial",
          "R/P4Y" => "Quadrennial",
        ],
      ],
    ];
    $default_data = new stdClass();
    $default_data->test = "Some value.";
    $this->assertEquals($form_builder->getJsonForm($default_data), $expected);

    // Test email.
    $container_chain = (new Chain($this))
      ->add(Container::class, 'get', $options)
      ->add(FileSchemaRetriever::class, 'retrieve', '
      {
        "properties":{
          "hasEmail": {
            "title": "Email",
            "description": "Email address for the contact name.",
            "pattern": "^mailto:",
            "type": "string"
          }
        },
        "type":"object"
      }')
      ->add(SchemaUiHandler::class, 'setSchemaUi');

    $container = $container_chain->getMock();
    \Drupal::setContainer($container);

    $form_builder = FormBuilder::create($container);
    $form_builder->setSchema('dataset');
    $result = $form_builder->getJsonForm($default_data);
    $this->assertEquals($result["hasEmail"]['#type'], "email");
    $this->assertArrayHasKey("#element_validate", $result["hasEmail"]);

    // Test object.
    $container_chain->add(FileSchemaRetriever::class, 'retrieve', '{"properties":{"publisher": {
      "$schema": "http://json-schema.org/draft-04/schema#",
      "id": "https://project-open-data.cio.gov/v1.1/schema/organization.json#",
      "title": "Organization",
      "description": "A Dataset Publisher Organization.",
      "type": "object",
      "required": [
        "name"
      ],
      "properties": {
        "@type": {
          "title": "Metadata Context",
          "description": "IRI for the JSON-LD data type. This should be org:Organization for each publisher",
          "type": "string",
          "default": "org:Organization"
        },
        "name": {
          "title": "Publisher Name",
          "description": "",
          "type": "string",
          "minLength": 1
        }
      }
    }},"type":"object"}');
    $container = $container_chain->getMock();
    \Drupal::setContainer($container);

    $form_builder = FormBuilder::create($container);
    $form_builder->setSchema('dataset');
    $expected = [
      "publisher" => [
        "publisher" => [
          "#type" => "details",
          "#open" => TRUE,
          "#title" => "Organization",
          "#description" => "A Dataset Publisher Organization.",
          "@type" => [
            "#type" => "textfield",
            "#title" => "Metadata Context",
            "#description" => "IRI for the JSON-LD data type. This should be org:Organization for each publisher",
            "#default_value" => "org:Organization",
            "#required" => FALSE,
          ],
          "name" => [
            "#type" => "textfield",
            "#title" => "Publisher Name",
            "#description" => "",
            "#default_value" => NULL,
            "#required" => TRUE,
          ],
        ],
      ],
    ];
    $this->assertEquals($form_builder->getJsonForm([]), $expected);

    // Test array.
    $container_chain->add(FileSchemaRetriever::class, 'retrieve', '
    {
      "properties":{
        "keyword": {
          "title": "Tags",
          "description": "Tags (or keywords).",
          "type": "array",
          "items": {
            "type": "string",
            "title": "Tag"
          }
        }
      },
      "type":"object"
    }');
    $container = $container_chain->getMock();
    \Drupal::setContainer($container);

    $form_builder = FormBuilder::create($container);
    $form_builder->setSchema('dataset');
    $expected = [
      "keyword" => [
        "#type" => "fieldset",
        "#title" => "Tags",
        "#prefix" => '<div id="keyword-fieldset-wrapper">',
        "#suffix" => "</div>",
        "#tree" => TRUE,
        "#description" => "Tags (or keywords).",
        "keyword" => [
          0 => [
            "#type" => "textfield",
            "#title" => "Tag",
          ],
        ],
      ],
    ];
    $form_state = new FormState();
    $result = $form_builder->getJsonForm([], $form_state);
    unset($result['keyword']['actions']);
    $this->assertEquals($result, $expected);

    // Test array required.
    $container_chain->add(SchemaRetriever::class, 'retrieve', '
    {
      "required": [
        "keyword"
      ],
      "properties":{
        "keyword": {
          "title": "Tags",
          "description": "Tags (or keywords).",
          "type": "array",
          "items": {
            "type": "string",
            "title": "Tag"
          },
          "minItems": 1
        }
      },
      "type":"object"
    }');
    $container = $container_chain->getMock();
    \Drupal::setContainer($container);

    $form_builder = FormBuilder::create($container);
    $form_builder->setSchema('dataset');
    $expected = [
      "keyword" => [
        "#type" => "fieldset",
        "#title" => "Tags",
        "#prefix" => '<div id="keyword-fieldset-wrapper">',
        "#suffix" => "</div>",
        "#tree" => TRUE,
        "#description" => "Tags (or keywords).",
        "keyword" => [
          0 => [
            "#type" => "textfield",
            "#title" => "Tag",
            "#required" => TRUE,
          ],
        ],
      ],
    ];
    $form_state = new FormState();
    $result = $form_builder->getJsonForm([], $form_state);
    unset($result['keyword']['actions']);
    $this->assertEquals($result, $expected);
  }

  /**
   * Return FieldTypeRouter object.
   */
  private function getRouter() {
    $email_validator = new EmailValidator();
    $string_helper = new StringHelper($email_validator);
    $object_helper = new ObjectHelper();

    $options = (new Options())
      ->add('json_form.string_helper', $string_helper)
      ->add('json_form.object_helper', $object_helper)
      ->add('json_form.array_helper', ArrayHelper::class)
      ->add('string_translation', TranslationManager::class)
      ->index(0);

    $container_chain = (new Chain($this))
      ->add(Container::class, 'get', $options);

    $container = $container_chain->getMock();
    \Drupal::setContainer($container);

    $router = FieldTypeRouter::create($container);
    return $router;
  }

}
