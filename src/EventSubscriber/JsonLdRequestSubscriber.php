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
use Psr\Log\LoggerInterface;

class JsonLdRequestSubscriber implements EventSubscriberInterface {

  protected $entityTypeManager;
  protected $authorAuthoritiesFormatter;
  protected $dllWorkFormatter;
  protected $itemRecordFormatter;
  protected $webPageFormatter;
  protected $aliasManager;
  protected $logger;

  public function __construct(EntityTypeManagerInterface $entity_type_manager, AuthorAuthoritiesFormatter $author_authorities_formatter, DllWorkFormatter $dll_work_formatter, ItemRecordFormatter $item_record_formatter, WebPageFormatter $web_page_formatter, AliasManagerInterface $alias_manager, LoggerInterface $logger) {
    $this->entityTypeManager = $entity_type_manager;
    $this->authorAuthoritiesFormatter = $author_authorities_formatter;
    $this->dllWorkFormatter = $dll_work_formatter;
    $this->itemRecordFormatter = $item_record_formatter;
    $this->webPageFormatter = $web_page_formatter;
    $this->aliasManager = $alias_manager;
    $this->logger = $logger;
  }

  public static function getSubscribedEvents() {
    $events[KernelEvents::REQUEST][] = ['onRequest', 0];
    return $events;
  }

  public function onRequest(RequestEvent $event) {
    $request = $event->getRequest();
    $queryParams = $request->query->all();
    $this->logger->info('Request received with query parameters: @params', ['@params' => json_encode($queryParams)]);
    
    if (isset($queryParams['format']) && $queryParams['format'] === 'json-ld') {
      $this->logger->info('format=json-ld detected');
      $node = $this->getNodeFromRequest($request);
      if ($node) {
        $this->logger->info('Node found: @nid', ['@nid' => $node->id()]);
        $this->logger->info('Node bundle: @bundle', ['@bundle' => $node->bundle()]);
        $response_data = $this->formatNode($node);
        $this->logger->info('Formatted JSON-LD response: @response', ['@response' => json_encode($response_data)]);
        $event->setResponse(new JsonResponse($response_data));
      } else {
        $this->logger->warning('No node found for the given request.');
      }
    } else {
      $this->logger->info('format=json-ld not detected');
    }
  }

  protected function getNodeFromRequest($request) {
    $path = $request->getPathInfo();
    $this->logger->info('Processing path: @path', ['@path' => $path]);

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

  protected function formatNode(NodeInterface $node) {
    switch ($node->bundle()) {
      case 'author_authorities':
        return $this->authorAuthoritiesFormatter->format($node);
      case 'dll_work':
        return $this->dllWorkFormatter->format($node);
      case 'item_record':
        return $this->itemRecordFormatter->format($node);
      case 'web_page':
        return $this->webPageFormatter->format($node);
      default:
        return [];
    }
  }
}
