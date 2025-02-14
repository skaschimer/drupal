<?php

declare(strict_types=1);

namespace Drupal\KernelTests\Core\Common;

use Drupal\Component\Utility\UrlHelper;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\KernelTests\KernelTestBase;

/**
 * Tests XSS filtering.
 *
 * @see \Drupal\Component\Utility\Xss::filter()
 * @see \Drupal\Component\Utility\UrlHelper::filterBadProtocol
 * @see \Drupal\Component\Utility\UrlHelper::stripDangerousProtocols
 *
 * @group Common
 */
class XssUnitTest extends KernelTestBase {

  use StringTranslationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['filter', 'system'];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installConfig(['system']);
  }

  /**
   * Tests t() functionality.
   */
  public function testT(): void {
    $text = $this->t('Simple text');
    $this->assertSame('Simple text', (string) $text, 't leaves simple text alone.');
    $text = $this->t('Escaped text: @value', ['@value' => '<script>']);
    $this->assertSame('Escaped text: &lt;script&gt;', (string) $text, 't replaces and escapes string.');
    $text = $this->t('Placeholder text: %value', ['%value' => '<script>']);
    $this->assertSame('Placeholder text: <em class="placeholder">&lt;script&gt;</em>', (string) $text, 't replaces, escapes and themes string.');
  }

  /**
   * Checks that harmful protocols are stripped.
   */
  public function testBadProtocolStripping(): void {
    // Ensure that check_url() strips out harmful protocols, and encodes for
    // HTML.
    // Ensure \Drupal\Component\Utility\UrlHelper::stripDangerousProtocols() can
    // be used to return a plain-text string stripped of harmful protocols.
    $url = 'javascript:http://www.example.com/?x=1&y=2';
    $expected_plain = 'http://www.example.com/?x=1&y=2';
    $expected_html = 'http://www.example.com/?x=1&amp;y=2';
    $this->assertSame($expected_html, UrlHelper::filterBadProtocol($url), '\\Drupal\\Component\\Utility\\UrlHelper::filterBadProtocol() filters a URL and encodes it for HTML.');
    $this->assertSame($expected_plain, UrlHelper::stripDangerousProtocols($url), '\\Drupal\\Component\\Utility\\UrlHelper::stripDangerousProtocols() filters a URL and returns plain text.');

  }

}
