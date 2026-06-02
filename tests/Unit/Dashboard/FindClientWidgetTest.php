<?php
declare(strict_types=1);
namespace OCA\Pipelinq\Tests\Unit\Dashboard;

use OCA\Pipelinq\Dashboard\FindClientWidget;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;

class FindClientWidgetTest extends TestCase
{
    private FindClientWidget $widget;
    protected function setUp(): void
    {
        $l10n = $this->createMock(IL10N::class);
        $l10n->method('t')->willReturnArgument(0);
        $this->widget = new FindClientWidget($l10n);
    }
    public function testGetIdReturnsCorrectId(): void
    {
        $this->assertSame('pipelinq_find_client_widget', $this->widget->getId());
    }
    public function testGetTitleReturnsString(): void
    {
        $this->assertIsString($this->widget->getTitle());
    }
    public function testGetOrderReturnsInt(): void
    {
        $this->assertIsInt($this->widget->getOrder());
    }
}
