<?php

/**
 * Unit tests for DefaultSkillService.
 *
 * Vergunningen and WMO / Zorg are example skills (pipelinq-audit-admin-forms-pos,
 * Ruben 7 October): a fresh install must not create them.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service;

use OCA\OpenRegister\Contract\ObjectEntityInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Pipelinq\Service\DefaultSkillService;
use OCA\Pipelinq\Service\RegisterResolverService;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\Pipelinq\Service\DefaultSkillService
 */
class DefaultSkillServiceTest extends TestCase {
	/**
	 * A fresh install gets generic skills only, never the example ones.
	 *
	 * @return void
	 */
	public function testAFreshInstallCreatesNoExampleSkills(): void {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn('37');

		$resolver = $this->createMock(RegisterResolverService::class);
		$resolver->method('resolve')->willReturn('20');

		$titles = [];
		$objects = $this->createMock(ObjectServiceInterface::class);
		$objects->method('findAll')->willReturn([]);
		$objects->method('saveObject')->willReturnCallback(
			function (...$args) use (&$titles) {
				$titles[] = $args[0]['title'];
				return $this->createMock(ObjectEntityInterface::class);
			}
		);

		(new DefaultSkillService($appConfig, $this->createMock(LoggerInterface::class), $resolver, $objects))->createDefaultSkills();

		$this->assertNotEmpty($titles);
		$this->assertNotContains('Vergunningen', $titles);
		$this->assertNotContains('WMO / Zorg', $titles);
	}//end testAFreshInstallCreatesNoExampleSkills()
}//end class
