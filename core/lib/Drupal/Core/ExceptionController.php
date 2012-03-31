<?php

/**
 * @file
 *
 * Definition of Drupal\Core\ExceptionController.
 */

namespace Drupal\Core;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\JsonResponse;

use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpKernel\Controller\ControllerResolver;
use Symfony\Component\HttpKernel\Exception\FlattenException;

use Exception;

/**
 * This controller handles HTTP errors generated by the routing system.
 */
class ExceptionController {

  /**
   * The kernel that spawned this controller.
   *
   * We will use this to fire subrequests as needed.
   *
   * @var HttpKernelInterface
   */
  protected $kernel;

  /**
   * The
   *
   * @var ContentNegotiation
   */
  protected $negotiation;

  /**
   * Constructor
   *
   * @param HttpKernelInterface $kernel
   *   The kernel that spawned this controller, so that it can be reused
   *   for subrequests.
   * @param ContentNegotiation $negotiation
   *   The content negotiation library to use to determine the correct response
   *   format.
   */
  public function __construct(HttpKernelInterface $kernel, ContentNegotiation $negotiation) {
    $this->kernel = $kernel;
    $this->negotiation = $negotiation;
  }

  /**
   * Handles an exception on a request.
   *
   * @param FlattenException $exception
   *   The flattened exception.
   * @param Request $request
   *   The request that generated the exception.
   * @return \Symfony\Component\HttpFoundation\Response
   *   A response object to be sent to the server.
   */
  public function execute(FlattenException $exception, Request $request) {

    $method = 'on' . $exception->getStatusCode() . $this->negotiation->getContentType($request);

    if (method_exists($this, $method)) {
      return $this->$method($exception, $request);
    }

    return new Response('A fatal error occurred: ' . $exception->getMessage(), $exception->getStatusCode());

  }

  /**
   * Processes a MethodNotAllowed exception into an HTTP 405 response.
   *
   * @param GetResponseEvent $event
   *   The Event to process.
   */
  public function on405Html(FlattenException $exception, Request $request) {
    $event->setResponse(new Response('Method Not Allowed', 405));
  }

  /**
   * Processes an AccessDenied exception into an HTTP 403 response.
   *
   * @param GetResponseEvent $event
   *   The Event to process.
   */
  public function on403Html(FlattenException $exception, Request $request) {
    $system_path = $request->attributes->get('system_path');
    $path = drupal_get_normal_path(variable_get('site_403', ''));
    if ($path && $path != $system_path) {
      // Keep old path for reference, and to allow forms to redirect to it.
      if (!isset($_GET['destination'])) {
        $_GET['destination'] = $system_path;
      }

      $subrequest = Request::create('/' . $path, 'get', array('destination' => $system_path), $request->cookies->all(), array(), $request->server->all());

      $response = $this->kernel->handle($subrequest, DrupalKernel::SUB_REQUEST);
      $response->setStatusCode(403, 'Access denied');
    }
    else {
      $response = new Response('Access Denied', 403);

      // @todo Replace this block with something cleaner.
      $return = t('You are not authorized to access this page.');
      drupal_set_title(t('Access denied'));
      drupal_set_page_content($return);
      $page = element_info('page');
      $content = drupal_render_page($page);

      $response->setContent($content);
    }

    return $response;
  }

  /**
   * Processes a NotFound exception into an HTTP 403 response.
   *
   * @param GetResponseEvent $event
   *   The Event to process.
   */
  public function on404Html(FlattenException $exception, Request $request) {
    watchdog('page not found', check_plain($_GET['q']), NULL, WATCHDOG_WARNING);

    // Check for and return a fast 404 page if configured.
    // @todo Inline this rather than using a function.
    drupal_fast_404();

    $system_path = $request->attributes->get('system_path');

    // Keep old path for reference, and to allow forms to redirect to it.
    if (!isset($_GET['destination'])) {
      $_GET['destination'] = $system_path;
    }

    $path = drupal_get_normal_path(variable_get('site_404', ''));
    if ($path && $path != $system_path) {
      // @TODO: Um, how do I specify an override URL again? Totally not clear.
      // Do that and sub-call the kernel rather than using meah().
      // @TODO: The create() method expects a slash-prefixed path, but we
      // store a normal system path in the site_404 variable.
      $subrequest = Request::create('/' . $path, 'get', array(), $request->cookies->all(), array(), $request->server->all());

      $response = $this->kernel->handle($subrequest, HttpKernelInterface::SUB_REQUEST);
      $response->setStatusCode(404, 'Not Found');
    }
    else {
      $response = new Response('Not Found', 404);

      // @todo Replace this block with something cleaner.
      $return = t('The requested page "@path" could not be found.', array('@path' => $request->getPathInfo()));
      drupal_set_title(t('Page not found'));
      drupal_set_page_content($return);
      $page = element_info('page');
      $content = drupal_render_page($page);

      $response->setContent($content);
    }

    return $response;
  }

  /**
   * Processes an AccessDenied exception into an HTTP 403 response.
   *
   * @param GetResponseEvent $event
   *   The Event to process.
   */
  public function on403Json(FlattenException $exception, Request $request) {
    $response = new JsonResponse();
    $response->setStatusCode(403, 'Access Denied');
    return $response;
  }

  /**
   * Processes a NotFound exception into an HTTP 404 response.
   *
   * @param GetResponseEvent $event
   *   The Event to process.
   */
  public function on404Json(FlattenException $exception, Request $request) {
    $response = new JsonResponse();
    $response->setStatusCode(404, 'Not Found');
    return $response;
  }

  /**
   * Processes a MethodNotAllowed exception into an HTTP 405 response.
   *
   * @param GetResponseEvent $event
   *   The Event to process.
   */
  public function on405Json(FlattenException $exception, Request $request) {
    $response = new JsonResponse();
    $response->setStatusCode(405, 'Method Not Allowed');
    return $response;
  }

}
