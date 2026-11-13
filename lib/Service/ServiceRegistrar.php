<?php

/**
 * Pipelinq Service Registrar
 *
 * @category  Service
 * @package   OCA\Pipelinq\Service
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://pipelinq.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use OCA\Pipelinq\Service\Gdpr\PipelinqApRegulatorEscalateProvider;
use OCA\Pipelinq\Service\Gdpr\PipelinqBsnIdentityVerifyProvider;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The Service component's own wiring: its interface bindings and factories at
 * register time, and the seams it attaches at boot.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://pipelinq.nl
 *
 * @spec exclude Composition root wiring, no functional requirement.
 */
class ServiceRegistrar {
	/**
	 * Register the service bindings and factories.
	 *
	 * @param IRegistrationContext $context The registration context.
	 *
	 * @return void
	 *
	 * @spec exclude Composition root wiring, no functional requirement.
	 */
	public function register(IRegistrationContext $context): void {
		// Wave-4 external-API ports (low-volume families).
		//
		// - Logius Berichtenbox (burgerportaal-mijnoverheid-bridge):
		// the BBK 1.7 dispatch/verify/mailbox-check seam. The
		// existing concrete `LogiusConnector` HTTP client is
		// intentionally NOT bound here — it stays available for a
		// downstream activation step to wire in. The default
		// binding is the dormant log-only adapter so test +
		// staging environments never contact Logius.
		$context->registerServiceAlias(
			\OCA\Pipelinq\Service\External\Berichtenbox\BerichtenboxAdapterInterface::class,
			\OCA\Pipelinq\Service\External\Berichtenbox\LogBerichtenboxAdapter::class
		);

		$this->registerGdprSeamProviders(context: $context);
	}//end register()

	/**
	 * Register pipelinq's two AVG/DSAR integration-seam providers as services
	 * (ADR-047 Phase-3 / ADR-019). The providers implement OpenRegister's
	 * `IdentityVerifyProvider` / `RegulatorEscalateProvider` contracts; they are
	 * registered here as lazy service factories and are added to OR's registries
	 * from {@see wireGdprSeamProviders()} in `boot()` — matching the OR registry
	 * contract's "each app registers its provider from its own boot hook"
	 * guidance, where the cross-app OR registry can be safely resolved. Both
	 * factories only construct the OR-interface-implementing class on resolve, so
	 * a disabled / absent OpenRegister never fatals bootstrap.
	 *
	 * @param IRegistrationContext $context The registration context.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/avg-consume-or-workflow/specs/avg-or-seam-bindings/spec.md
	 */
	private function registerGdprSeamProviders(IRegistrationContext $context): void {
		$context->registerService(
			PipelinqBsnIdentityVerifyProvider::class,
			static function ($c): PipelinqBsnIdentityVerifyProvider {
				return new PipelinqBsnIdentityVerifyProvider(
					bsnValidation: $c->get(BsnValidationService::class),
					brpClient: $c->get(HaalCentraalClient::class),
					logger: $c->get(LoggerInterface::class),
				);
			}
		);

		$context->registerService(
			PipelinqApRegulatorEscalateProvider::class,
			static function ($c): PipelinqApRegulatorEscalateProvider {
				return new PipelinqApRegulatorEscalateProvider(logger: $c->get(LoggerInterface::class));
			}
		);
	}//end registerGdprSeamProviders()

	/**
	 * Attach the services' boot-time seams: the appointment collaborators, the
	 * AVG/DSAR seam providers and the DSAR evidence source.
	 *
	 * @param ContainerInterface $container The app container.
	 *
	 * @return void
	 *
	 * @spec exclude Composition root wiring, no functional requirement.
	 */
	public function boot(ContainerInterface $container): void {
		$this->wireAppointmentSeams(container: $container);
		$this->wireGdprSeamProviders(container: $container);
		$this->registerDsarEvidenceSource(container: $container);
	}//end boot()

	/**
	 * Add pipelinq's two AVG/DSAR seam providers to OpenRegister's registries
	 * (ADR-047 Phase-3 / ADR-019, first-wins). The registries are OR-owned
	 * shared services resolved from the app container at boot; the whole wiring
	 * is wrapped per registry so a disabled / absent OpenRegister (or an OR build
	 * predating the Gdpr seams) never fatals boot — in that case the NL pack's
	 * selector simply resolves to OR's fail-closed default provider and identity
	 * stays unverified / escalation stays refused (ADR-005 / CWE-863).
	 *
	 * @param ContainerInterface $container The app container.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/avg-consume-or-workflow/specs/avg-or-seam-bindings/spec.md
	 */
	private function wireGdprSeamProviders(ContainerInterface $container): void {
		$identityClass = 'OCA\\OpenRegister\\Service\\Gdpr\\Identity\\IdentityVerifyRegistry';
		$regulatorClass = 'OCA\\OpenRegister\\Service\\Gdpr\\Regulator\\RegulatorEscalateRegistry';
		
		try {
			$identityRegistry = $container->get($identityClass);
			$identityRegistry->addProvider($container->get(PipelinqBsnIdentityVerifyProvider::class));
		} catch (Throwable $e) {
			// OpenRegister absent or the identity-verify seam not present — the
			// NL pack selector resolves OR's fail-closed default (unverified).
		}

		try {
			$regulatorRegistry = $container->get($regulatorClass);
			$regulatorRegistry->addProvider($container->get(PipelinqApRegulatorEscalateProvider::class));
		} catch (Throwable $e) {
			// OpenRegister absent or the regulator-escalate seam not present — the
			// NL pack selector resolves OR's fail-closed default (refused).
		}
	}//end wireGdprSeamProviders()

	/**
	 * Register pipelinq's CRM objects as an evidence source in OpenRegister's
	 * DSAR case engine (ADR-047 Phase 3 / ADR-019 seam).
	 *
	 * OpenRegister's EvidenceHarvestService enumerates only providers added to
	 * its EvidenceSourceRegistry, so this registration is what makes pipelinq
	 * data reachable during a data-subject request. The registry is resolved
	 * lazily through the container so a disabled / absent OpenRegister never
	 * fatals bootstrap — the registration is simply skipped.
	 *
	 * @param ContainerInterface $container The app container.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/avg-verzoeken-workflow/spec.md#requirement-req-avg-016-pipelinq-evidence-source-registration
	 */
	private function registerDsarEvidenceSource(ContainerInterface $container): void {
		try {
			$registry = $container->get('OCA\\OpenRegister\\Service\\Gdpr\\Evidence\\EvidenceSourceRegistry');
			$provider = $container->get(\OCA\Pipelinq\Service\PipelinqEvidenceSourceProvider::class);
			$registry->addProvider($provider);
		} catch (\Throwable $e) {
			// OpenRegister absent or DSAR engine not present — pipelinq boots
			// without contributing DSAR evidence. No Throwable escapes.
		}
	}//end registerDsarEvidenceSource()

	/**
	 * Wire the three optional appointment-booking seams onto their consumers.
	 *
	 * BookingService and AvailabilityService are constructed WITHOUT these
	 * collaborators and take them through setters, so the lifecycle code never
	 * depends on an email, calendar or payment transport. Boot is where they are
	 * attached, which is why they are attached here:
	 *
	 *  - confirmation email (chain member 07): confirmation mail goes out on
	 *    booking create and confirm.
	 *  - calendar leaf (member 10): AvailabilityService merges leaf-synced staff
	 *    VEVENTs into slot computation, and BookingService pushes a confirmed
	 *    booking back to those calendars (REQ-APT-018).
	 *  - fee payment (member 08): no-show and late-cancellation fees are routed
	 *    to Integriq instead of only being recorded in statusHistory.
	 *
	 * Each seam is wired inside its OWN try. That is the property this method has
	 * to keep, and it is why the three are a loop rather than one block: a
	 * container that cannot build the calendar provider must still leave the
	 * email and payment seams attached. A seam that fails to wire stays null and
	 * the documented degradation applies — bookings still transition, the fee is
	 * still recorded, nothing is transported.
	 *
	 * This replaced three near-identical private methods (wireAppointmentEmailSeam,
	 * wireAppointmentCalendarSeam, wireAppointmentPaymentSeam) that differed only
	 * in which setter they called. Collapsing them took this class back under
	 * phpmd's ExcessiveClassLength threshold, which the slug-resolver binding
	 * above had pushed it over by five lines.
	 *
	 * @param ContainerInterface $container The app container.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/appointment-booking/spec.md
	 */
	private function wireAppointmentSeams(ContainerInterface $container): void {
		$seams = [
			// Confirmation email.
			static function (ContainerInterface $container): void {
				$container->get(BookingService::class)->setEmailProvider(
					provider: $container->get(AppointmentEmailService::class)
				);
			},
			// Calendar leaf, consumed by two services.
			static function (ContainerInterface $container): void {
				$calendarProvider = $container->get(AppointmentCalendarLeafProvider::class);
				$container->get(AvailabilityService::class)->setCalendarProvider(provider: $calendarProvider);
				$container->get(BookingService::class)->setCalendarProvider(provider: $calendarProvider);
			},
			// No-show and late-cancellation fee transport.
			static function (ContainerInterface $container): void {
				$container->get(BookingService::class)->setPaymentProvider(
					provider: $container->get(AppointmentPaymentProvider::class)
				);
			},
		];

		foreach ($seams as $wire) {
			try {
				$wire($container);
			} catch (Throwable $e) {
				// OpenRegister or one of the collaborators is unavailable. This
				// seam stays null; the other two are unaffected.
			}
		}
	}//end wireAppointmentSeams()
}//end class
