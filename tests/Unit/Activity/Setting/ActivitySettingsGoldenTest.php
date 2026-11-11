<?php

/**
 * Golden test for every value the four activity settings report.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Activity\Setting
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://pipelinq.nl
 *
 * @spec openspec/specs/notifications-activity/spec.md#requirement-per-category-notification-preferences-v1
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Activity\Setting;

use OCA\Pipelinq\Activity\Setting\AssignmentSetting;
use OCA\Pipelinq\Activity\Setting\DealSetting;
use OCA\Pipelinq\Activity\Setting\NoteSetting;
use OCA\Pipelinq\Activity\Setting\StageStatusSetting;
use OCP\Activity\ActivitySettings;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;

/**
 * Identifier, names, group, priority and stream/mail defaults of each setting.
 */
class ActivitySettingsGoldenTest extends TestCase {
	/**
	 * The settings and what each reports.
	 *
	 * @return array<string, array{0: class-string<ActivitySettings>, 1: array<string, mixed>}>
	 */
	public static function settingProvider(): array {
		$shared = ['group' => 'pipelinq', 'groupName' => 'T:Pipelinq', 'canChangeStream' => true, 'stream' => true, 'canChangeMail' => true];
		return [
			'assignments' => [AssignmentSetting::class, ['id' => 'pipelinq_assignment', 'name' => 'T:Lead & request assignments', 'priority' => 50, 'mail' => false] + $shared],
			'notes' => [NoteSetting::class, ['id' => 'pipelinq_notes', 'name' => 'T:Notes & comments', 'priority' => 52, 'mail' => false] + $shared],
			'stages' => [StageStatusSetting::class, ['id' => 'pipelinq_stage_status', 'name' => 'T:Pipeline stage & status changes', 'priority' => 51, 'mail' => false] + $shared],
			'deals' => [DealSetting::class, ['id' => 'pipelinq_deals', 'name' => 'T:Deal won & lost', 'priority' => 51, 'mail' => true] + $shared],
		];
	}//end settingProvider()

	/**
	 * Every value of the setting.
	 *
	 * @param class-string<ActivitySettings> $class    The setting.
	 * @param array<string, mixed>           $expected What it reports.
	 *
	 * @return void
	 *
	 * @dataProvider settingProvider
	 */
	public function testEveryValue(string $class, array $expected): void {
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text): string => 'T:' . $text);
		$setting = new $class($l10n);

		$this->assertInstanceOf(ActivitySettings::class, $setting);
		$this->assertSame(
			$expected,
			[
				'id' => $setting->getIdentifier(),
				'name' => $setting->getName(),
				'priority' => $setting->getPriority(),
				'mail' => $setting->isDefaultEnabledMail(),
				'group' => $setting->getGroupIdentifier(),
				'groupName' => $setting->getGroupName(),
				'canChangeStream' => $setting->canChangeStream(),
				'stream' => $setting->isDefaultEnabledStream(),
				'canChangeMail' => $setting->canChangeMail(),
			]
		);
	}//end testEveryValue()
}//end class
