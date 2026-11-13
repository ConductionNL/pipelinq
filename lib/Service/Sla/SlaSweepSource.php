<?php

/**
 * Pipelinq SlaSweepSource.
 *
 * Where an SLA sweep looks: register, interval, schemas and store.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service\Sla
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/specs/sla-engine-and-escalation/spec.md
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Sla;

use OCA\Pipelinq\Kernel\AppIdentity;
use OCA\Pipelinq\Service\TicketService;
use OCP\IAppConfig;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Reads the sweep's configuration: the register, the sweep interval, the
 * tracked surfaces and the OpenRegister ObjectService.
 *
 * The `request` and `klacht` surfaces both resolve to the unified `ticket`
 * schema and are narrowed with a `ticketType` filter; `callback` still owns
 * its own schema.
 *
 * @spec openspec/specs/sla-engine-and-escalation/spec.md
 */
class SlaSweepSource {
	/**
	 * Ticket subtypes that carry SLA tracking, mapped to the tracked-object
	 * "type" label the SLA engine escalates on.
	 *
	 * @var array<string, string>
	 */
	public const TICKET_TYPE_LABELS = [
		TicketService::TYPE_REQUEST   => 'request',
		TicketService::TYPE_COMPLAINT => 'complaint',
	];

	/**
	 * Tracked surfaces that own a dedicated schema, by config key.
	 *
	 * @var array<string, string>
	 */
	private const SCHEMA_TYPES = [
		'callback_schema' => 'callback',
	];

	/**
	 * Constructor.
	 *
	 * @param TicketService      $ticketService Resolver for the unified ticket schema.
	 * @param IAppConfig         $appConfig     App config.
	 * @param ContainerInterface $container     DI container (OR ObjectService).
	 * @param LoggerInterface    $logger        PSR logger.
	 *
	 * @return void
	 */
	public function __construct(
		private TicketService $ticketService,
		private IAppConfig $appConfig,
		private ContainerInterface $container,
		private LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The configured register UUID, empty when unset.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/sla-engine-and-escalation/spec.md
	 */
	public function register(): string {
		return $this->appConfig->getValueString(AppIdentity::APP_ID, 'register', '');
	}//end register()

	/**
	 * The sweep interval in seconds, clamped to 60-1800 (default 300).
	 *
	 * @return int
	 *
	 * @spec openspec/specs/sla-engine-and-escalation/spec.md
	 */
	public function interval(): int {
		$configured = (int) $this->appConfig->getValueString(
			AppIdentity::APP_ID,
			'sla_sweep_interval_seconds',
			'300',
		);

		return min(1800, max(60, $configured));
	}//end interval()

	/**
	 * The OpenRegister ObjectService, or null (logged) when OpenRegister is unavailable.
	 *
	 * @return object|null
	 *
	 * @spec openspec/specs/sla-engine-and-escalation/spec.md
	 */
	public function objectService(): ?object {
		try {
			return $this->container->get('OCA\OpenRegister\Service\ObjectService');
		} catch (Throwable $e) {
			$this->logger->warning('SlaDeadlineSweepJob: ObjectService unavailable', ['error' => $e->getMessage()]);
			return null;
		}
	}//end objectService()

	/**
	 * Every tracked surface to sweep in a run.
	 *
	 * @param SlaSweepRun $run The sweep run.
	 *
	 * @return array<int, SlaSweepSurface>
	 *
	 * @spec openspec/specs/sla-engine-and-escalation/spec.md
	 */
	public function surfaces(SlaSweepRun $run): array {
		$surfaces = $this->ticketSurfaces(run: $run);
		foreach (self::SCHEMA_TYPES as $schemaConfigKey => $type) {
			$schemaId = $this->appConfig->getValueString(AppIdentity::APP_ID, $schemaConfigKey, '');
			if ($schemaId !== '') {
				$surfaces[] = new SlaSweepSurface(run: $run, schemaId: $schemaId, type: $type, extraFilters: []);
			}
		}

		return $surfaces;
	}//end surfaces()

	/**
	 * One surface per ticket subtype on the unified ticket schema; none when
	 * that schema is not configured.
	 *
	 * @param SlaSweepRun $run The sweep run.
	 *
	 * @return array<int, SlaSweepSurface>
	 */
	private function ticketSurfaces(SlaSweepRun $run): array {
		$ticketSchema = $this->ticketService->getSchemaId();
		if ($ticketSchema === '') {
			return [];
		}

		$surfaces = [];
		foreach (self::TICKET_TYPE_LABELS as $ticketType => $label) {
			$surfaces[] = new SlaSweepSurface(run: $run, schemaId: $ticketSchema, type: $label, extraFilters: ['ticketType' => $ticketType]);
		}

		return $surfaces;
	}//end ticketSurfaces()
}//end class
