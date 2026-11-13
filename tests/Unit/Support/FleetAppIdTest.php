<?php

/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Support;

use OCA\Pipelinq\Support\FleetAppId;
use OCP\App\IAppManager;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use RuntimeException;
use stdClass;

/**
 * Resolving a fleet app by its canonical id when it may still be installed
 * under its former id, and finding its services under either namespace.
 *
 * @covers \OCA\Pipelinq\Support\FleetAppId
 */
class FleetAppIdTest extends TestCase {
	/**
	 * An app manager with these apps installed; any app in $broken throws.
	 *
	 * @param array<int, string> $installed The installed app ids.
	 * @param array<int, string> $broken    App ids whose check throws.
	 * @param array<int, string> $asked     Filled with every id asked about.
	 */
	private function apps(array $installed, array $broken = [], array &$asked = []): IAppManager {
		$apps = $this->createMock(originalClassName: IAppManager::class);
		$apps->method('isInstalled')->willReturnCallback(
			static function (string $id) use ($installed, $broken, &$asked): bool {
				$asked[] = $id;
				if (in_array($id, $broken, true) === true) {
					throw new RuntimeException('cannot tell');
				}

				return in_array($id, $installed, true);
			}
		);
		return $apps;
	}

	public function testTheCanonicalIdWinsOverTheFormerOne(): void {
		$asked = [];
		$this->assertSame('integriq', FleetAppId::resolve(appManager: $this->apps(installed: ['integriq', 'openconnector'], asked: $asked), canonical: 'integriq'));
		$this->assertSame(['integriq'], $asked);
	}

	public function testTheFormerIdIsUsedWhenOnlyItIsInstalled(): void {
		$asked = [];
		$this->assertSame('docudesk', FleetAppId::resolve(appManager: $this->apps(installed: ['docudesk'], asked: $asked), canonical: 'filinq'));
		$this->assertSame(['filinq', 'docudesk'], $asked);
	}

	public function testAnAppOutsideTheFleetMapIsCheckedUnderItsOwnIdOnly(): void {
		$asked = [];
		$this->assertSame('openregister', FleetAppId::resolve(appManager: $this->apps(installed: ['openregister'], asked: $asked), canonical: 'openregister'));
		$this->assertNull(FleetAppId::resolve(appManager: $this->apps(installed: []), canonical: 'mail'));
		$this->assertSame(['openregister'], $asked);
	}

	public function testACandidateThatThrowsDoesNotStopTheSearch(): void {
		$this->assertSame('procest', FleetAppId::resolve(appManager: $this->apps(installed: ['procest'], broken: ['dossiq']), canonical: 'dossiq'));
		$this->assertNull(FleetAppId::resolve(appManager: $this->apps(installed: [], broken: ['dossiq', 'procest']), canonical: 'dossiq'));
	}

	public function testIsInstalledFollowsResolve(): void {
		$this->assertTrue(FleetAppId::isInstalled(appManager: $this->apps(installed: ['doriath']), canonical: 'keepiq'));
		$this->assertFalse(FleetAppId::isInstalled(appManager: $this->apps(installed: ['scholiq']), canonical: 'keepiq'));
	}

	public function testEnabledForUserAsksAboutTheResolvedId(): void {
		$apps = $this->apps(installed: ['decidesk']);
		$apps->expects($this->once())->method('isEnabledForUser')->with('decidesk')->willReturn(true);

		$this->assertTrue(FleetAppId::isEnabledForUser(appManager: $apps, canonical: 'decidiq'));
	}

	public function testEnabledForUserIsFalseWhenAbsentDisabledOrUnanswerable(): void {
		$absent = $this->apps(installed: []);
		$absent->expects($this->never())->method('isEnabledForUser');
		$this->assertFalse(FleetAppId::isEnabledForUser(appManager: $absent, canonical: 'decidiq'));

		$disabled = $this->apps(installed: ['decidiq']);
		$disabled->method('isEnabledForUser')->willReturn(false);
		$this->assertFalse(FleetAppId::isEnabledForUser(appManager: $disabled, canonical: 'decidiq'));

		$failing = $this->apps(installed: ['decidiq']);
		$failing->method('isEnabledForUser')->willThrowException(new RuntimeException('no user'));
		$this->assertFalse(FleetAppId::isEnabledForUser(appManager: $failing, canonical: 'decidiq'));
	}

	public function testAppPathUsesTheResolvedIdAndOneSlashBeforeTheSuffix(): void {
		$apps = $this->apps(installed: ['softwarecatalog']);

		$this->assertSame('/apps/softwarecatalog', FleetAppId::appPath(appManager: $apps, canonical: 'stackiq'));
		$this->assertSame('/apps/softwarecatalog/img/app.svg', FleetAppId::appPath(appManager: $apps, canonical: 'stackiq', suffix: '//img/app.svg'));
		$this->assertSame('/apps/softwarecatalog/js/main.js', FleetAppId::appPath(appManager: $apps, canonical: 'stackiq', suffix: 'js/main.js'));
		$this->assertNull(FleetAppId::appPath(appManager: $apps, canonical: 'larpinq', suffix: 'img'));
	}

	public function testClassCandidatesListTheNewNamespaceFirst(): void {
		$this->assertSame(
			['OCA\Integriq\Service\ConsentService', 'OCA\OpenConnector\Service\ConsentService'],
			FleetAppId::classCandidates(canonical: 'integriq', relative: '\Service\ConsentService')
		);
		$this->assertSame(['OCA\Thematiq\Theme', 'OCA\NLDesign\Theme'], FleetAppId::classCandidates(canonical: 'thematiq', relative: 'Theme'));
		$this->assertSame([], FleetAppId::classCandidates(canonical: 'openregister', relative: 'Service\ObjectService'));
	}

	public function testGetServiceFallsBackToTheFormerNamespace(): void {
		$service   = new stdClass();
		$asked     = [];
		$container = $this->createMock(originalClassName: ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static function (string $id) use ($service, &$asked): mixed {
				$asked[] = $id;
				if ($id === 'OCA\Larpinq\Service\CharacterService') {
					throw new RuntimeException('not registered');
				}

				return $service;
			}
		);

		$this->assertSame($service, FleetAppId::getService(container: $container, canonical: 'larpinq', relative: 'Service\CharacterService'));
		$this->assertSame(['OCA\Larpinq\Service\CharacterService', 'OCA\LarpingApp\Service\CharacterService'], $asked);
	}

	public function testGetServiceSkipsNonObjectsAndGivesUpWithNull(): void {
		$container = $this->createMock(originalClassName: ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static function (string $id): mixed {
				if ($id === 'OCA\Learniq\Service\Course') {
					return 'not a service';
				}

				throw new RuntimeException('not registered');
			}
		);

		$this->assertNull(FleetAppId::getService(container: $container, canonical: 'learniq', relative: 'Service\Course'));

		$never = $this->createMock(originalClassName: ContainerInterface::class);
		$never->expects($this->never())->method('get');
		$this->assertNull(FleetAppId::getService(container: $never, canonical: 'openregister', relative: 'Service\ObjectService'));
	}
}
