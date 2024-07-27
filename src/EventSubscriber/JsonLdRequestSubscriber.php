<?php

namespace Drupal\dll_json_ld\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpFoundation\JsonResponse;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\path_alias\AliasManagerInterface;
use Drupal\node\NodeInterface;
use Drupal\dll_json_ld\Service\Formatter\AuthorAuthoritiesFormatter;
use Drupal\dll_json_ld\Service\Formatter\DllWorkFormatter;
use Drupal\dll_json_ld\Service\Formatter\ItemRecordFormatter;
use Drupal\dll_json_ld\Service\Formatter\WebPageFormatter;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Render\RendererInterface;

/**
 * JSON-LD request subscriber.
 */
class JsonLdRequestSubscriber implements EventSubscriberInterface {

  protected $entityTypeManager;
  protected $authorAuthoritiesFormatter;
  protected $dllWorkFormatter;
  protected $itemRecordFormatter;
  protected $webPageFormatter;
  protected $aliasManager;
  protected $logger;
  protected $renderer;

  public function __construct(EntityTypeManagerInterface $entity_type_manager, AuthorAuthoritiesFormatter $author_authorities_formatter, DllWorkFormatter $dll_work_formatter, ItemRecordFormatter $item_record_formatter, WebPageFormatter $web_page_formatter, AliasManagerInterface $alias_manager, LoggerChannelFactoryInterface $logger_factory, RendererInterface $renderer) {
    $this->entityTypeManager = $entity_type_manager;
    $this->authorAuthoritiesFormatter = $author_authorities_formatter;
    $this->dllWorkFormatter = $dll_work_formatter;
    $this->itemRecordFormatter = $item_record_formatter;
    $this->webPageFormatter = $web_page_formatter;
    $this->aliasManager = $alias_manager;
    $this->logger = $logger_factory->get('dll_json_ld');
    $this->renderer = $renderer;
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents() {
    $events[KernelEvents::REQUEST][] = ['onRequest', 0];
    return $events;
  }

  /**
   * Responds to the request event.
   *
   * @param \Symfony\Component\HttpKernel\Event\RequestEvent $event
   *   The event to process.
   */
  public function onRequest(RequestEvent $event) {
    $request = $event->getRequest();
    $this->logger->info('Request received with query parameters: @params', ['@params' => $request->query->all()]);

    if ($request->query->get('format') === 'json-ld') {
      $this->logger->info('format=json-ld detected');
      $node = $this->getNodeFromRequest($request);
      if ($node) {
        $this->logger->info('Node found: @nid', ['@nid' => $node->id()]);
        $this->logger->info('Node bundle: @bundle', ['@bundle' => $node->bundle()]);
        $response_data = $this->formatNode($node);
        $this->logger->info('Formatted JSON-LD response: @response', ['@response' => json_encode($response_data)]);

        // Create the JSON response
        $response = new JsonResponse($response_data);

        // Add cache metadata
        $cache_metadata = new CacheableMetadata();
        $cache_metadata->addCacheTags(['node:' . $node->id()]);
        $cache_metadata->addCacheContexts(['url.query_args:format']);
        $cache_metadata->setCacheMaxAge(0); // Disable caching

        // Log cache metadata details
        $this->logger->info('Cache Tags: @tags', ['@tags' => implode(', ', $cache_metadata->getCacheTags())]);
        $this->logger->info('Cache Contexts: @contexts', ['@contexts' => implode(', ', $cache_metadata->getCacheContexts())]);
        $this->logger->info('Cache Max-Age: @maxage', ['@maxage' => $cache_metadata->getCacheMaxAge()]);

        // Attach cache metadata to the response
        $response->headers->set('X-Drupal-Cache-Tags', implode(' ', $cache_metadata->getCacheTags()));
        $response->headers->set('X-Drupal-Cache-Contexts', implode(' ', $cache_metadata->getCacheContexts()));
        $response->headers->set('X-Drupal-Cache-Max-Age', $cache_metadata->getCacheMaxAge());

        // Set the response
        $event->setResponse($response);
      } else {
        $this->logger->warning('No node found for the given request.');
      }
    } else {
      $this->logger->info('format=json-ld not detected');
    }
  }

  /**
   * Retrieves the node from the request.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request object.
   *
   * @return \Drupal\node\NodeInterface|null
   *   The node entity or NULL if not found.
   */
  protected function getNodeFromRequest($request) {
    $path = $request->getPathInfo();
    $this->logger->info('Processing path: @path', ['@path' => $path]);

    // Try to resolve the node from the alias
    $alias = $this->aliasManager->getPathByAlias($path);
    if (strpos($alias, '/node/') === 0) {
      $nid = str_replace('/node/', '', $alias);
      $node = $this->entityTypeManager->getStorage('node')->load($nid);
      if ($node instanceof NodeInterface) {
        return $node;
      }
    }
    return NULL;
  }

  /**
   * Formats the node as JSON-LD based on its content type.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The node entity.
   *
   * @return array
   *   The JSON-LD formatted data.
   */
  protected function formatNode(NodeInterface $node) {
    switch ($node->bundle()) {
      case 'author_authorities':
        return $this->authorAuthoritiesFormatter->format($node);

      case 'dll_work':
        return $this->dllWorkFormatter->format($node);

      case 'repository_item':
        return $this->itemRecordFormatter->format($node);

      case 'web_page':
        return $this->webPageFormatter->format($node);

      default:
        return [];
    }
  }
}
