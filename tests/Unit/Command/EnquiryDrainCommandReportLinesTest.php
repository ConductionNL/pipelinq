<?php

/**
 * EnquiryDrainCommand: the per-row report lines.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Command
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Command;

use OCA\Pipelinq\Command\EnquiryDrainCommand;
use OCA\Pipelinq\Service\WebForm\HeldObjectDrain;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * A delivered row that could not be removed says so; a refused row names its
 * status and codes.
 *
 * @covers \OCA\Pipelinq\Command\EnquiryDrainCommand
 */
class EnquiryDrainCommandReportLinesTest extends TestCase {

	/**
	 * Every row kind is printed on its own line, in report order.
	 *
	 * @return void
	 */
	public function testEveryRowKindIsPrinted(): void {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => (['register' => '20', 'enquiry_schema' => '26'][$key] ?? $default)
		);
		$drain = $this->createMock(HeldObjectDrain::class);
		$drain->expects($this->once())->method('drain')->with('20', '26', 'source', ['status' => 'new'], true)->willReturn(
			[
				'delivered' => [
					['id' => 'e-1', 'key' => 'contact-form', 'destination' => 'lead', 'reference' => 'L-9', 'notRemoved' => 'locked'],
					['id' => 'e-2', 'key' => 'contact-form', 'destination' => 'lead', 'reference' => ''],
				],
				'refused' => [['id' => 'e-3', 'status' => 422, 'codes' => ['email_invalid', 'name_missing']]],
				'unmapped' => [],
				'pending' => 1,
			]
		);
		$tester = new CommandTester(new EnquiryDrainCommand($drain, $appConfig));

		$this->assertSame(1, $tester->execute(['--apply' => true]));
		$this->assertSame(
			implode(
				PHP_EOL,
				[
					'enquiry: 2 delivered, 1 refused, 0 unmapped.',
					'  delivered e-1 (contact-form) into lead L-9 (not removed: locked)',
					'  delivered e-2 (contact-form) into lead',
					'  refused e-3: status 422 email_invalid,name_missing',
					'Pending after this run: 1.',
				]
			) . PHP_EOL,
			$tester->getDisplay()
		);
	}//end testEveryRowKindIsPrinted()
}//end class
