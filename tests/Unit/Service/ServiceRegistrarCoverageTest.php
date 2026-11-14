<?php

/**
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://github.com/ConductionNL/pipelinq
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service;

use OCA\OpenRegister\Service\Gdpr\Evidence\EvidenceSourceRegistry;
use OCA\Pipelinq\Service\AppointmentCalendarLeafProvider;
use OCA\Pipelinq\Service\AppointmentEmailService;
use OCA\Pipelinq\Service\AppointmentPaymentProvider;
use OCA\Pipelinq\Service\AvailabilityService;
use OCA\Pipelinq\Service\BookingService;
use OCA\Pipelinq\Service\BsnValidationService;
use OCA\Pipelinq\Service\External\Berichtenbox\BerichtenboxAdapterInterface;
use OCA\Pipelinq\Service\External\Berichtenbox\LogBerichtenboxAdapter;
use OCA\Pipelinq\Service\Gdpr\PipelinqApRegulatorEscalateProvider;
use OCA\Pipelinq\Service\Gdpr\PipelinqBsnIdentityVerifyProvider;
use OCA\Pipelinq\Service\HaalCentraalClient;
use OCA\Pipelinq\Service\PipelinqEvidenceSourceProvider;
use OCA\Pipelinq\Service\ServiceRegistrar;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * The Service component's register-time bindings and boot-time seams, and
 * that one unavailable collaborator never stops the others being wired.
 *
 * @covers \OCA\Pipelinq\Service\ServiceRegistrar
 * @uses   \OCA\Pipelinq\Service\ContractBindings
 * @uses   \OCA\Pipelinq\Service\Gdpr\PipelinqBsnIdentityVerifyProvider
 * @uses   \OCA\Pipelinq\Service\Gdpr\PipelinqApRegulatorEscalateProvider
 *
 * @spec openspec/changes/avg-consume-or-workflow/specs/avg-or-seam-bindings/spec.md
 */
class ServiceRegistrarCoverageTest extends TestCase {

	private const IDENTITY_REGISTRY = 'OCA\\OpenRegister\\Service\\Gdpr\\Identity\\IdentityVerifyRegistry';

	private const REGULATOR_REGISTRY = 'OCA\\OpenRegister\\Service\\Gdpr\\Regulator\\RegulatorEscalateRegistry';

	private const EVIDENCE_REGISTRY = 'OCA\\OpenRegister\\Service\\Gdpr\\Evidence\\EvidenceSourceRegistry';

	/**
	 * A container answering the given services and throwing for anything else.
	 *
	 * @param array<string, object> $services Services by id.
	 *
	 * @return ContainerInterface
	 */
	private function container(array $services): ContainerInterface {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static function (string $id) use ($services): object {
				if (isset($services[$id]) === false) {
					throw new RuntimeException('cannot build '.$id);
				}

				return $services[$id];
			}
		);
		return $container;
	}//end container()

	/**
	 * An OpenRegister provider registry that records what is added.
	 *
	 * @return object
	 */
	private function registry(): object {
		return new class {
			/**
			 * The providers added.
			 *
			 * @var array<int, object>
			 */
			public array $added = [];

			/**
			 * Add a provider.
			 *
			 * @param object $provider The provider.
			 *
			 * @return bool
			 */
			public function addProvider(object $provider): bool {
				$this->added[] = $provider;
				return true;
			}
		};
	}//end registry()

	/**
	 * Register aliases Berichtenbox to the log-only adapter and registers both GDPR factories, which build their providers.
	 *
	 * @return void
	 */
	public function testRegisterBindsBerichtenboxAndTheGdprProviderFactories(): void {
		$aliases   = [];
		$factories = [];
		$context   = $this->createMock(IRegistrationContext::class);
		$context->method('registerServiceAlias')->willReturnCallback(
			static function (string $alias, string $target) use (&$aliases): void {
				$aliases[$alias] = $target;
			}
		);
		$context->expects($this->exactly(2))->method('registerService')->willReturnCallback(
			static function (string $name, callable $factory) use (&$factories): void {
				$factories[$name] = $factory;
			}
		);

		(new ServiceRegistrar())->register($context);

		$this->assertSame(LogBerichtenboxAdapter::class, $aliases[BerichtenboxAdapterInterface::class] ?? null);
		$this->assertSame([PipelinqBsnIdentityVerifyProvider::class, PipelinqApRegulatorEscalateProvider::class], array_keys($factories));

		$container = $this->container(
			[
				BsnValidationService::class => $this->createMock(BsnValidationService::class),
				HaalCentraalClient::class => $this->createMock(HaalCentraalClient::class),
				LoggerInterface::class => $this->createMock(LoggerInterface::class),
			]
		);
		$this->assertInstanceOf(PipelinqBsnIdentityVerifyProvider::class, $factories[PipelinqBsnIdentityVerifyProvider::class]($container));
		$this->assertInstanceOf(PipelinqApRegulatorEscalateProvider::class, $factories[PipelinqApRegulatorEscalateProvider::class]($container));
	}//end testRegisterBindsBerichtenboxAndTheGdprProviderFactories()

	/**
	 * With every collaborator available, boot attaches all three appointment seams and all three OpenRegister providers.
	 *
	 * @return void
	 */
	public function testBootWiresEverySeam(): void {
		$email    = $this->createMock(AppointmentEmailService::class);
		$calendar = $this->createMock(AppointmentCalendarLeafProvider::class);
		$payment  = $this->createMock(AppointmentPaymentProvider::class);
		$identity = $this->createMock(PipelinqBsnIdentityVerifyProvider::class);
		$escalate = $this->createMock(PipelinqApRegulatorEscalateProvider::class);
		$evidence = $this->createMock(PipelinqEvidenceSourceProvider::class);

		$booking = $this->createMock(BookingService::class);
		$booking->expects($this->once())->method('setEmailProvider')->with($email);
		$booking->expects($this->once())->method('setCalendarProvider')->with($calendar);
		$booking->expects($this->once())->method('setPaymentProvider')->with($payment);
		$availability = $this->createMock(AvailabilityService::class);
		$availability->expects($this->once())->method('setCalendarProvider')->with($calendar);
		$evidenceRegistry = $this->createMock(EvidenceSourceRegistry::class);
		$evidenceRegistry->expects($this->once())->method('addProvider')->with($evidence)->willReturn(true);

		$identityRegistry  = $this->registry();
		$regulatorRegistry = $this->registry();

		(new ServiceRegistrar())->boot(
			$this->container(
				[
					BookingService::class => $booking,
					AvailabilityService::class => $availability,
					AppointmentEmailService::class => $email,
					AppointmentCalendarLeafProvider::class => $calendar,
					AppointmentPaymentProvider::class => $payment,
					PipelinqBsnIdentityVerifyProvider::class => $identity,
					PipelinqApRegulatorEscalateProvider::class => $escalate,
					PipelinqEvidenceSourceProvider::class => $evidence,
					self::IDENTITY_REGISTRY => $identityRegistry,
					self::REGULATOR_REGISTRY => $regulatorRegistry,
					self::EVIDENCE_REGISTRY => $evidenceRegistry,
				]
			)
		);

		$this->assertSame([$identity], $identityRegistry->added);
		$this->assertSame([$escalate], $regulatorRegistry->added);
	}//end testBootWiresEverySeam()

	/**
	 * A collaborator that cannot be built leaves only its own seam unwired; nothing escapes boot.
	 *
	 * @return void
	 */
	public function testBootKeepsTheOtherSeamsWhenOneCannotBeBuilt(): void {
		$email    = $this->createMock(AppointmentEmailService::class);
		$payment  = $this->createMock(AppointmentPaymentProvider::class);
		$escalate = $this->createMock(PipelinqApRegulatorEscalateProvider::class);

		$booking = $this->createMock(BookingService::class);
		$booking->expects($this->once())->method('setEmailProvider')->with($email);
		$booking->expects($this->never())->method('setCalendarProvider');
		$booking->expects($this->once())->method('setPaymentProvider')->with($payment);
		$availability = $this->createMock(AvailabilityService::class);
		$availability->expects($this->never())->method('setCalendarProvider');

		$regulatorRegistry = $this->registry();

		// No calendar leaf, no identity registry and no DSAR evidence registry.
		(new ServiceRegistrar())->boot(
			$this->container(
				[
					BookingService::class => $booking,
					AvailabilityService::class => $availability,
					AppointmentEmailService::class => $email,
					AppointmentPaymentProvider::class => $payment,
					PipelinqApRegulatorEscalateProvider::class => $escalate,
					self::REGULATOR_REGISTRY => $regulatorRegistry,
				]
			)
		);

		$this->assertSame([$escalate], $regulatorRegistry->added);
	}//end testBootKeepsTheOtherSeamsWhenOneCannotBeBuilt()

	/**
	 * Without OpenRegister or the booking services at all, boot completes and wires nothing.
	 *
	 * @return void
	 */
	public function testBootSurvivesAnEmptyContainer(): void {
		$container = $this->createMock(ContainerInterface::class);
		$container->expects($this->exactly(6))->method('get')->willThrowException(new RuntimeException('absent'));

		(new ServiceRegistrar())->boot($container);
	}//end testBootSurvivesAnEmptyContainer()
}//end class
