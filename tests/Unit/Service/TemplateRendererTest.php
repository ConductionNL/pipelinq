<?php

/**
 * Unit tests for TemplateRenderer.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://codeberg.org/Conduction/pipelinq
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service;

use OCA\Pipelinq\Exception\TemplateRenderException;
use OCA\Pipelinq\Service\TemplateRenderer;
use PHPUnit\Framework\TestCase;

/**
 * Tests for TemplateRenderer.
 */
class TemplateRendererTest extends TestCase
{
    /**
     * The renderer under test.
     *
     * @var TemplateRenderer
     */
    private TemplateRenderer $renderer;

    /**
     * Set up the renderer.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->renderer = new TemplateRenderer();
    }//end setUp()

    /**
     * Variables are substituted into subject and body.
     *
     * @return void
     */
    public function testRendersVariables(): void
    {
        $template = [
            'subject' => 'Zaak {{zaakId}} afgehandeld',
            'body'    => '<p>Beste burger, zaak {{zaakId}} is klaar.</p>',
        ];

        $result = $this->renderer->render($template, ['zaakId' => 'Z-2026-0042']);

        $this->assertSame('Zaak Z-2026-0042 afgehandeld', $result['subject']);
        $this->assertStringContainsString('Z-2026-0042', $result['body']);
    }//end testRendersVariables()

    /**
     * Missing variables render as empty (Mustache-compatible).
     *
     * @return void
     */
    public function testMissingVariableRendersEmpty(): void
    {
        $result = $this->renderer->render(
            ['subject' => 'Hi {{unknown}}', 'body' => '<p>ok</p>'],
            []
        );

        $this->assertSame('Hi ', $result['subject']);
    }//end testMissingVariableRendersEmpty()

    /**
     * Variable values are HTML-escaped to prevent body injection.
     *
     * @return void
     */
    public function testVariableValuesAreEscaped(): void
    {
        $result = $this->renderer->render(
            ['subject' => 's', 'body' => '<p>{{x}}</p>'],
            ['x' => '<script>alert(1)</script>']
        );

        $this->assertStringNotContainsString('<script>', $result['body']);
        $this->assertStringContainsString('&lt;script&gt;', $result['body']);
    }//end testVariableValuesAreEscaped()

    /**
     * The subject is truncated to 200 characters.
     *
     * @return void
     */
    public function testSubjectTruncatedTo200(): void
    {
        $long   = str_repeat('a', 250);
        $result = $this->renderer->render(['subject' => $long, 'body' => '<p>ok</p>'], []);

        $this->assertSame(200, mb_strlen($result['subject']));
    }//end testSubjectTruncatedTo200()

    /**
     * Well-formed XHTML with a void element (br) passes validation.
     *
     * @return void
     */
    public function testWellFormedBodyWithVoidElementPasses(): void
    {
        $result = $this->renderer->render(
            ['subject' => 's', 'body' => '<p>line one<br>line two</p>'],
            []
        );

        $this->assertStringContainsString('line two', $result['body']);
    }//end testWellFormedBodyWithVoidElementPasses()

    /**
     * Malformed XHTML (unclosed tag) is rejected.
     *
     * @return void
     */
    public function testMalformedBodyThrows(): void
    {
        $this->expectException(TemplateRenderException::class);
        $this->renderer->render(['subject' => 's', 'body' => '<p>oops</div>'], []);
    }//end testMalformedBodyThrows()

    /**
     * An empty body is rejected.
     *
     * @return void
     */
    public function testEmptyBodyThrows(): void
    {
        $this->expectException(TemplateRenderException::class);
        $this->renderer->render(['subject' => 's', 'body' => '   '], []);
    }//end testEmptyBodyThrows()
}//end class
