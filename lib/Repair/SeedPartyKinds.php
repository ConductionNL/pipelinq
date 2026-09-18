<?php

/**
 * Pipelinq SeedPartyKinds.
 *
 * Seeds the party kinds the fleet already uses, so nothing loses its meaning
 * when the registry lands. Without them the picker offers an empty list on
 * every record, which looks exactly like a record type that accepts no party.
 *
 * Idempotent, by code: a kind whose code is already declared is left untouched,
 * including one an administrator has since edited or set inactive. The step
 * seeds; it does not enforce.
 *
 * @category Repair
 * @package  OCA\Pipelinq\Repair
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git_id>
 *
 * @link https://pipelinq.nl
 *
 * @spec openspec/changes/party-kinds-accepted-per-case-type/specs/party-kind-registry/spec.md#requirement-pipelinq-shall-hold-the-vocabulary-of-party-kinds-req-pkr-001
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Repair;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Service\PartyKindRegistryService;
use OCP\IAppConfig;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Repair step: seed the party kinds the fleet already uses.
 *
 * @spec openspec/changes/party-kinds-accepted-per-case-type/specs/party-kind-registry/spec.md#requirement-pipelinq-shall-hold-the-vocabulary-of-party-kinds-req-pkr-001
 */
class SeedPartyKinds implements IRepairStep {
	/**
	 * The kinds every Dutch municipal file already uses in practice.
	 *
	 * `melder` takes identity shape `none` deliberately: a person reporting a
	 * broken street light needs no account of any kind. Making such a party
	 * notifiable is the notification dialect's, not this step's.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private const KINDS = [
		[
			'code' => 'aanvrager',
			'label' => 'Aanvrager',
			'description' => 'The person or organisation the work is done for.',
			'identityShapes' => ['naturalPerson', 'organization'],
			'maxPerRecord' => 1,
			'active' => true,
		],
		[
			'code' => 'gemachtigde',
			'label' => 'Gemachtigde',
			'description' => 'Somebody acting on the aanvrager\'s behalf.',
			'identityShapes' => ['naturalPerson', 'organization'],
			'maxPerRecord' => 1,
			'active' => true,
		],
		[
			'code' => 'belanghebbende',
			'label' => 'Belanghebbende',
			'description' => 'Somebody whose interest is touched by the outcome. As many as there are.',
			'identityShapes' => ['naturalPerson', 'organization'],
			'maxPerRecord' => 0,
			'active' => true,
		],
		[
			'code' => 'melder',
			'label' => 'Melder',
			'description' => 'Whoever reported it. Needs no account of any kind.',
			'identityShapes' => ['none', 'naturalPerson', 'organization'],
			'maxPerRecord' => 0,
			'active' => true,
		],
		[
			'code' => 'contactpersoon',
			'label' => 'Contactpersoon',
			'description' => 'The person to reach about this record.',
			'identityShapes' => ['naturalPerson'],
			'maxPerRecord' => 0,
			'active' => true,
		],
	];

	/**
	 * Constructor.
	 *
	 * @param IAppConfig $appConfig The app config, holding the schema ids.
	 * @param ObjectServiceInterface $objectService OpenRegister's object service.
	 * @param PartyKindRegistryService $registry Reads the kinds already declared.
	 * @param LoggerInterface $logger PSR logger.
	 */
	public function __construct(
		private IAppConfig $appConfig,
		private ObjectServiceInterface $objectService,
		private PartyKindRegistryService $registry,
		private LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Get the repair step name.
	 *
	 * @return string Name.
	 *
	 * @spec openspec/changes/party-kinds-accepted-per-case-type/specs/party-kind-registry/spec.md#requirement-pipelinq-shall-hold-the-vocabulary-of-party-kinds-req-pkr-001
	 */
	public function getName(): string {
		return 'Seed the party kinds the fleet already uses';
	}//end getName()

	/**
	 * The kinds this run would write, given the codes already declared.
	 *
	 * Separated from the write so idempotence is testable without a store.
	 *
	 * @param array<int, string> $existingCodes The codes already declared.
	 *
	 * @return array<int, array<string, mixed>> The kinds to write.
	 *
	 * @spec openspec/changes/party-kinds-accepted-per-case-type/specs/party-kind-registry/spec.md#requirement-pipelinq-shall-hold-the-vocabulary-of-party-kinds-req-pkr-001
	 */
	public function missingKinds(array $existingCodes): array {
		return array_values(
			array_filter(
				self::KINDS,
				static fn (array $kind): bool => in_array($kind['code'], $existingCodes, true) === false
			)
		);
	}//end missingKinds()

	/**
	 * Run the repair.
	 *
	 * @param IOutput $output Output.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/party-kinds-accepted-per-case-type/specs/party-kind-registry/spec.md#requirement-pipelinq-shall-hold-the-vocabulary-of-party-kinds-req-pkr-001
	 */
	public function run(IOutput $output): void {
		$register = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
		$schema = $this->appConfig->getValueString(Application::APP_ID, 'partyKind_schema', '');
		if ($register === '' || $schema === '') {
			$output->info('Party kind registry not configured — skipping the seed.');
			return;
		}

		$missing = $this->missingKinds(existingCodes: array_keys($this->registry->vocabulary()));

		$seeded = 0;
		foreach ($missing as $kind) {
			try {
				$this->objectService->saveObject(
					object: $kind,
					register: $register,
					schema: $schema,
					_rbac: false,
					_multitenancy: false,
				);
				$seeded++;
			} catch (Throwable $e) {
				$this->logger->error(
					'SeedPartyKinds: could not seed a party kind',
					['code' => $kind['code'], 'exception' => $e->getMessage()]
				);
			}
		}

		$output->info(
			sprintf('Party kinds: %d seeded, %d already declared.', $seeded, (count(self::KINDS) - count($missing)))
		);
	}//end run()
}//end class
