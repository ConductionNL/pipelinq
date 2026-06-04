<?php
declare(strict_types=1);
namespace OCA\Pipelinq\Tests\Unit\Activity\Setting;

use OCA\Pipelinq\Activity\Setting\StageStatusSetting;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;

class StageStatusSettingTest extends TestCase
{
    public function testGetIdentifierReturnsString(): void
    {
        $l10n = $this->createMock(IL10N::class);
        $l10n->method('t')->willReturnArgument(0);
        $setting = new StageStatusSetting($l10n);
        $this->assertIsString($setting->getIdentifier());
    }
    public function testGetNameReturnsString(): void
    {
        $l10n = $this->createMock(IL10N::class);
        $l10n->method('t')->willReturnArgument(0);
        $setting = new StageStatusSetting($l10n);
        $this->assertIsString($setting->getName());
    }
}
