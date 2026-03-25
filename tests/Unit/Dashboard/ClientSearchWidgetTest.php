<?php
declare(strict_types=1);
namespace OCA\Pipelinq\Tests\Unit\Dashboard;

use OCA\Pipelinq\Dashboard\ClientSearchWidget;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;

class ClientSearchWidgetTest extends TestCase
{
    private ClientSearchWidget $widget;
    protected function setUp(): void
    {
        $l10n = $this->createMock(IL10N::class);
        $l10n->method('t')->willReturnArgument(0);
        $this->widget = new ClientSearchWidget($l10n);
    }
    public function testGetIdReturnsCorrectId(): void
    {
        $this->assertSame('pipelinq_client_search_widget', $this->widget->getId());
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
