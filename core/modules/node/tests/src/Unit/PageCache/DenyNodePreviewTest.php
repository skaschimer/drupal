<?php

declare(strict_types=1);

namespace Drupal\Tests\node\Unit\PageCache;

use Drupal\Core\PageCache\ResponsePolicyInterface;
use Drupal\node\PageCache\DenyNodePreview;
use Drupal\Tests\UnitTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * @coversDefaultClass \Drupal\node\PageCache\DenyNodePreview
 * @group node
 */
class DenyNodePreviewTest extends UnitTestCase {

  /**
   * The response policy under test.
   *
   * @var \Drupal\node\PageCache\DenyNodePreview
   */
  protected $policy;

  /**
   * A request object.
   *
   * @var \Symfony\Component\HttpFoundation\Request
   */
  protected $request;

  /**
   * A response object.
   *
   * @var \Symfony\Component\HttpFoundation\Response
   */
  protected $response;

  /**
   * The current route match.
   *
   * @var \Drupal\Core\Routing\RouteMatch|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $routeMatch;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->routeMatch = $this->createMock('Drupal\Core\Routing\RouteMatchInterface');
    $this->policy = new DenyNodePreview($this->routeMatch);
    $this->response = new Response();
    $this->request = new Request();
  }

  /**
   * Asserts that caching is denied on the node preview route.
   *
   * @dataProvider providerPrivateImageStyleDownloadPolicy
   * @covers ::check
   */
  public function testPrivateImageStyleDownloadPolicy($expected_result, $route_name): void {
    $this->routeMatch->expects($this->once())
      ->method('getRouteName')
      ->willReturn($route_name);

    $actual_result = $this->policy->check($this->response, $this->request);
    $this->assertSame($expected_result, $actual_result);
  }

  /**
   * Provides data and expected results for the test method.
   *
   * @return array
   *   Data and expected results.
   */
  public static function providerPrivateImageStyleDownloadPolicy() {
    return [
      [ResponsePolicyInterface::DENY, 'entity.node.preview'],
      [NULL, 'some.other.route'],
      [NULL, NULL],
      [NULL, FALSE],
      [NULL, TRUE],
      [NULL, new \stdClass()],
      [NULL, [1, 2, 3]],
    ];
  }

}
