<?php

/**
 * Pipelinq SurveyOptOutService.
 *
 * Recording that a contact never wants a satisfaction survey again.
 *
 * THIS IS NOT PART OF SCORING A RESPONSE. It lived inside SurveyResponseService
 * because the public form submits both in one request, but the two are
 * different acts against different records: one writes a response, this one
 * writes a standing preference on the contact. Kept together, the preference
 * was reached only through the response path and had no test of its own.
 *
 * THE PREFERENCE IS WRITTEN ON EVERY MATCHING CONTACT ROW. A contact reachable
 * under one uid may hold more than one row; honouring the opt-out on some of
 * them and not the others is the same as not honouring it.
 *
 * A FAILED WRITE IS LOGGED AND THE REST STILL RUN. Abandoning the loop on the
 * first failure would leave the contact opted out on some rows and not others,
 * which is the worst of the three outcomes.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service
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
 * @spec openspec/changes/customer-satisfaction-closed-loop/specs/customer-satisfaction/spec.md#requirement-survey-fatigue-throttling-and-opt-out
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use JsonSerializable;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Pipelinq\AppInfo\Application;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Write a contact's standing survey opt-out.
 *
 * @spec openspec/changes/customer-satisfaction-closed-loop/specs/customer-satisfaction/spec.md#requirement-survey-fatigue-throttling-and-opt-out
 */
class SurveyOptOutService {
	/**
	 * Upper bound on the contact rows one uid resolves to.
	 *
	 * @var int
	 */
	private const LIMIT = 10;

	/**
	 * Constructor.
	 *
	 * @param IAppConfig $appConfig The app config, holding the register and schema ids.
	 * @param ObjectServiceInterface $objectService OpenRegister's object service.
	 * @param LoggerInterface $logger PSR logger.
	 */
	public function __construct(
		private readonly IAppConfig $appConfig,
		private readonly ObjectServiceInterface $objectService,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Record that a contact never wants a satisfaction survey again.
	 *
	 * @param string $contactRef The contact uid.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/customer-satisfaction-closed-loop/specs/customer-satisfaction/spec.md#requirement-survey-fatigue-throttling-and-opt-out
	 */
	public function record(string $contactRef): void {
		$contactRef = trim($contactRef);
		$register = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
		$schema = $this->appConfig->getValueString(Application::APP_ID, 'contact_schema', '');
		if ($contactRef === '' || $register === '' || $schema === '') {
			return;
		}

		foreach ($this->contactRows(register: $register, schema: $schema, contactRef: $contactRef) as $row) {
			$row['surveyOptOut'] = true;
			$this->write(register: $register, schema: $schema, row: $row);
		}
	}//end record()

	/**
	 * The contact rows one uid resolves to, as plain arrays.
	 *
	 * @param string $register The register id.
	 * @param string $schema The contact schema id.
	 * @param string $contactRef The contact uid.
	 *
	 * @return array<int, array<string, mixed>> The rows, or [] when the read failed.
	 */
	private function contactRows(string $register, string $schema, string $contactRef): array {
		try {
			$found = $this->objectService->findAll(
				[
					'filters' => ['register' => $register, 'schema' => $schema, 'contactsUid' => $contactRef],
					'limit' => self::LIMIT,
				]
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				'SurveyOptOutService: the opt-out could not be recorded',
				['exception' => $e->getMessage()]
			);

			return [];
		}

		$rows = [];
		foreach ($found as $row) {
			$data = $row;
			if ($row instanceof JsonSerializable) {
				$data = $row->jsonSerialize();
			}

			if (is_array($data) === true) {
				$rows[] = $data;
			}
		}

		return $rows;
	}//end contactRows()

	/**
	 * Save one contact row, logging rather than throwing on failure.
	 *
	 * @param string $register The register id.
	 * @param string $schema The contact schema id.
	 * @param array<string, mixed> $row The row to write.
	 *
	 * @return void
	 */
	private function write(string $register, string $schema, array $row): void {
		try {
			$this->objectService->saveObject(
				object: $row,
				register: $register,
				schema: $schema,
				uuid: (string)($row['id'] ?? $row['uuid'] ?? ''),
			);
		} catch (Throwable $e) {
			$this->logger->error(
				'SurveyOptOutService: the opt-out could not be saved',
				['exception' => $e->getMessage()]
			);
		}
	}//end write()
}//end class
