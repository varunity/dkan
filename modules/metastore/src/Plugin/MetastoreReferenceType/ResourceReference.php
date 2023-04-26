<?php

namespace Drupal\metastore\Plugin\MetastoreReferenceType;

use Drupal\common\DataResource;
use Drupal\common\UrlHostTokenResolver;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\file\Entity\File;
use Drupal\metastore\Exception\AlreadyRegistered;
use Drupal\metastore\Reference\ReferenceTypeBase;
use Drupal\metastore\ResourceMapper;
use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * API Docs common base.
 *
 * @MetastoreReferenceType(
 *  id = "resource",
 *  description = @Translation("Datastore resource definition.")
 * )
 */
class ResourceReference extends ReferenceTypeBase {

  /**
   * Default Mime Type to use when mime type detection fails.
   *
   * @var string
   */
  protected const DEFAULT_MIME_TYPE = 'text/plain';

  /**
   * Resource mapper service.
   *
   * @var \Drupal\metastore\ResourceMapper
   */
  protected ResourceMapper $resourceMapper;

  /**
   * Drupal file system.
   *
   * @var \Drupal\Core\File\FileSystemInterface
   */
  protected FileSystemInterface $fileSystem;

  /**
   * Drupal entity type manager service.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * Guzzle HTTP Client.
   *
   * @var \GuzzleHttp\Client
   */
  protected Client $client;

  /**
   * Constructs a ReferenceType object.
   *
   * @param array $config
   *   Details for reference definition. Possible keys:
   *   - schemaId: For some reference definitions, a schemaId must be specified.
   * @param string $pluginId
   *   The plugin_id for the plugin instance.
   * @param mixed $pluginDefinition
   *   The plugin implementation definition.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $loggerFactory
   *   Logger factory service.
   * @param \Drupal\metastore\ResourceMapper $resourceMapper
   *   Metastore storage factory.
   * @param \Drupal\Core\File\FileSystemInterface $fileSystem
   *   Core filesystem service.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   Entity type manager service.
   * @param \GuzzleHttp\Client $client
   *   Guzzle HTTP client.
   */
  public function __construct(
    array $config,
    $pluginId,
    $pluginDefinition,
    LoggerChannelFactoryInterface $loggerFactory,
    ResourceMapper $resourceMapper,
    FileSystemInterface $fileSystem,
    EntityTypeManagerInterface $entityTypeManager,
    Client $client
  ) {
    $this->resourceMapper = $resourceMapper;
    $this->fileSystem = $fileSystem;
    $this->entityTypeManager = $entityTypeManager;
    $this->client = $client;
    parent::__construct($config, $pluginDefinition, $pluginId, $loggerFactory);
  }

  /**
   * Container injection.
   *
   * @param \Drupal\common\Plugin\ContainerInterface $container
   *   The service container.
   * @param array $config
   *   A configuration array containing information about the plugin instance.
   * @param string $pluginId
   *   The plugin_id for the plugin instance.
   * @param mixed $pluginDefinition
   *   The plugin implementation definition.
   *
   * @return static
   */
  public static function create(
    ContainerInterface $container,
    array $config,
    $pluginId,
    $pluginDefinition
  ) {
    return new static(
      $config,
      $pluginId,
      $pluginDefinition,
      $container->get('logger.factory'),
      $container->get('dkan.metastore.resource_mapper'),
      $container->get('file_system'),
      $container->get('entity_type.manager'),
      $container->get('http_client')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function reference($value): string {
    return $this->registerWithResourceMapper(
      static::hostify($value),
      $this->getMimeType($this->context)
    );
  }

  /**
   * {@inheritdoc}
   */
  public function dereference(string $identifier, bool $showId = FALSE) {
    // If simple identifier, convert to URL.
    if (filter_var($identifier, FILTER_VALIDATE_URL)) {
      return $identifier;
    }

    $resource = $this->resourceLookup($identifier);
    if ($resource && $showId) {
      return [$this->createResourceReference($resource)];
    }
    return $resource ? UrlHostTokenResolver::resolve($resource->getFilePath()) : $identifier;
  }

  /**
   * Build object with identifier/data structure for reference.
   *
   * @param Drupal\common\DataResource $resource
   *   A DKAN resource object.
   *
   * @return object
   *   The same resource object, wrapped in a stdClass object with an identifier
   *   property.
   */
  private function createResourceReference(DataResource $resource): object {
    return (object) [
      "identifier" => $resource->getUniqueIdentifier(),
      "data" => $resource,
    ];
  }

  /**
   * Register the supplied resource details with the resource mapper.
   *
   * @param string $downloadUrl
   *   The download URL for the resource being registered.
   * @param string $mimeType
   *   The mime type for the resource being registered.
   *
   * @return string
   *   A unique ID for the resource generated using the supplied details.
   */
  protected function registerWithResourceMapper(string $downloadUrl, string $mimeType): string {
    try {
      // Create a new resource using the supplied resource details.
      $resource = new DataResource($downloadUrl, $mimeType);

      // Attempt to register the url with the resource file mapper.
      if ($this->resourceMapper->register($resource)) {
        // Upon successful registration, replace the download URL with a unique
        // ID generated by the resource mapper.
        $downloadUrl = $resource->getUniqueIdentifier();
      }
    }
    catch (AlreadyRegistered $e) {
      $info = json_decode($e->getMessage());

      // If resource mapper registration failed due to this resource already
      // being registered, generate a new version of the resource and update the
      // download URL with the new version ID.
      if (isset($info[0]->identifier)) {
        $stored = $this->resourceMapper->get($info[0]->identifier, DataResource::DEFAULT_SOURCE_PERSPECTIVE);
        $downloadUrl = $this->handleExistingResource($info, $stored, $mimeType);
      }
    }

    return $downloadUrl;
  }

  /**
   * Private.
   */
  protected function handleExistingResource($info, $stored, $mimeType) {
    if ($info[0]->perspective == DataResource::DEFAULT_SOURCE_PERSPECTIVE &&
      ($this->resourceMapper->newRevision() == 1 || $stored->getMimeType() != $mimeType)) {
      $new = $stored->createNewVersion();
      // Update the MIME type, since this may be updated by the user.
      $new->changeMimeType($mimeType);

      $this->resourceMapper->registerNewVersion($new);
      $downloadUrl = $new->getUniqueIdentifier();
    }
    else {
      $downloadUrl = $stored->getUniqueIdentifier();
    }
    return $downloadUrl;
  }

  /**
   * Substitute the host for local URLs with a custom localhost token.
   *
   * @param string $resourceUrl
   *   The URL of the resource being substituted.
   *
   * @return string
   *   The resource URL with the custom localhost token.
   */
  public static function hostify(string $resourceUrl): string {
    // Get HTTP server public files URL and extract the host.
    $serverPublicFilesUrl = UrlHostTokenResolver::getServerPublicFilesUrl();
    $serverPublicFilesUrl = isset($serverPublicFilesUrl) ? parse_url($serverPublicFilesUrl) : NULL;
    $serverHost = $serverPublicFilesUrl['host'] ?? \Drupal::request()->getHost();
    // Determine whether the resource URL has the same host as this server.
    $resourceParsedUrl = parse_url($resourceUrl);
    if (isset($resourceParsedUrl['host']) && $resourceParsedUrl['host'] == $serverHost) {
      // Swap out the host portion of the resource URL with the localhost token.
      $resourceParsedUrl['host'] = UrlHostTokenResolver::TOKEN;
      $resourceUrl = self::unparseUrl($resourceParsedUrl);
    }
    return $resourceUrl;
  }

  /**
   * Process URL.
   *
   * @param mixed $parsedUrl
   *   Outut of parse_url()
   *
   * @return string
   *   A resource URL
   *
   * @todo Clean all this URL/file logic up!
   */
  protected static function unparseUrl($parsedUrl) {
    $url = '';
    $urlParts = [
      'scheme',
      'host',
      'port',
      'user',
      'pass',
      'path',
      'query',
      'fragment',
    ];

    foreach ($urlParts as $part) {
      if (!isset($parsedUrl[$part])) {
        continue;
      }
      $url .= ($part == "port") ? ':' : '';
      $url .= ($part == "query") ? '?' : '';
      $url .= ($part == "fragment") ? '#' : '';
      $url .= $parsedUrl[$part];
      $url .= ($part == "scheme") ? '://' : '';
    }

    return $url;
  }

  /**
   * Determine the mime type of the supplied local file.
   *
   * @param string $downloadUrl
   *   Local resource file path.
   *
   * @return string|null
   *   The detected mime type or NULL on failure.
   */
  private function getLocalMimeType(string $downloadUrl): ?string {
    $mime_type = NULL;

    // Retrieve and decode the file name from the supplied download URL's path.
    $filename = $this->fileSystem->basename($downloadUrl);
    $filename = urldecode($filename);

    // Attempt to load the file by file name.
    $files = $this->entityTypeManager->getStorage('file')->loadByProperties(['filename' => $filename]);
    $file = reset($files);

    // If a valid file was found for the given file name, extract the file's
    // mime type.
    if ($file instanceof File) {
      $mime_type = $file->getMimeType();
    }
    // Otherwise, log an error notifying the user that a file was not found.
    else {
      $this->logger->notice(
        'Unable to determine mime type of "@name"; file not found.',
        ['@name' => $filename]
      );
    }

    return $mime_type;
  }

  /**
   * Determine the mime type of the supplied remote file.
   *
   * @param string $downloadUrl
   *   Remote resource file URL.
   *
   * @return string|null
   *   The detected mime type, or NULL on failure.
   */
  private function getRemoteMimeType(string $downloadUrl): ?string {
    $mime_type = NULL;

    // Perform HTTP Head request against the supplied URL in order to determine
    // the content type of the remote resource.
    $response = $this->client->head($downloadUrl);
    // Extract the full value of the content type header.
    $content_type = $response->getHeader('Content-Type');
    // Attempt to extract the mime type from the content type header.
    if (isset($content_type[0])) {
      $mime_type = $content_type[0];
    }

    return $mime_type;
  }

  /**
   * Determine the mime type of the supplied distribution's resource.
   *
   * @param object $distribution
   *   A dataset distribution object.
   *
   * @return string
   *   The detected mime type, or DEFAULT_MIME_TYPE on failure.
   *
   * @todo Update the UI to set mediaType when a format is selected.
   */
  private function getMimeType($distribution): string {
    $mimeType = "text/plain";

    // If we have a mediaType set, use that.
    if (isset($distribution->mediaType)) {
      $mimeType = $distribution->mediaType;
    }
    // Fall back if we have an importable format set.
    elseif (isset($distribution->format) && $distribution->format == 'csv') {
      $mimeType = 'text/csv';
    }
    elseif (isset($distribution->format) && $distribution->format == 'tsv') {
      $mimeType = 'text/tab-separated-values';
    }
    // Otherwise, determine the proper mime type using the distribution's
    // download URL.
    elseif (isset($distribution->downloadURL)) {
      // Determine whether the supplied distribution has a local or remote
      // resource.
      $is_local = $distribution->downloadURL !== $this->hostify($distribution->downloadURL);
      $mimeType = $is_local ?
        $this->getLocalMimeType($distribution->downloadURL) :
        $this->getRemoteMimeType($distribution->downloadURL);
    }

    return $mimeType ?? self::DEFAULT_MIME_TYPE;
  }

  /**
   * Get a file resource object.
   *
   * @param string $resourceIdentifier
   *   Identifier for resource.
   *
   * @return \Drupal\common\DataResource|null
   *   URL value or null if none found.
   */
  protected function resourceLookup(string $resourceIdentifier) {
    $info = DataResource::parseUniqueIdentifier($resourceIdentifier);

    // Load resource object.
    $resource = $this->resourceMapper->get(
      $info['identifier'],
      DataResource::DEFAULT_SOURCE_PERSPECTIVE,
      $info['version']
    );

    if (!$resource) {
      return NULL;
    }

    $perspective = $this->resourceMapper->display();

    if (
      $perspective != DataResource::DEFAULT_SOURCE_PERSPECTIVE &&
      $new = $this->resourceMapper->get($info['identifier'], $perspective, $info['version'])
    ) {
      $resource = $new;
    }
    return $resource;
  }

}