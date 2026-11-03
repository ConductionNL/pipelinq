<?php

/**
 * Pipelinq ScheduledTaskStore.
 *
 * Where scheduled tasks are stored, and the language they are read in.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service\ScheduledTask
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
 * @spec openspec/changes/task-background-jobs/tasks.md#task-1
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service\ScheduledTask;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Service\LanguageService;
use OCA\Pipelinq\AppInfo\Application;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;

/**
 * Resolves the configured register and task schema (warning when either is
 * missing, so callers refuse rather than run unscoped), hands out the
 * OpenRegister object service, and sets the read language from a request's
 * Accept-Language.
 *
 * @spec openspec/changes/task-background-jobs/tasks.md#task-1
 */
class ScheduledTaskStore {
	/**
	 * Constructor.
	 *
	 * @param IAppConfig             $appConfig       App config (for register/schema IDs).
	 * @param ObjectServiceInterface $objectService   OpenRegister's published object service.
	 * @param LanguageService        $languageService Resolves the caller's language.
	 * @param LoggerInterface        $logger          Logger.
	 */
	public function __construct(
		private readonly IAppConfig $appConfig,
		private readonly ObjectServiceInterface $objectService,
		private readonly LanguageService $languageService,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Read configured register and task schema IDs.
	 *
	 * Fails closed: '' on either id means "unconfigured", and every caller
	 * refuses the OpenRegister call on it. An empty id must never be handed to
	 * OpenRegister — ObjectService skips setRegister()/setSchema() for an empty
	 * value, so the query silently inherits whatever context an earlier call in
	 * the same request left on the shared service instance. The empty case is
	 * logged so an unprovisioned instance is visible rather than silent.
	 *
	 * @return array{0: string, 1: string} [register, schema] tuple, each ''
	 *                                     when unconfigured.
	 *
	 * @spec openspec/changes/task-background-jobs/tasks.md#task-1
	 */
	public function location(): array {
		$registerId = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
		$schemaId = $this->appConfig->getValueString(Application::APP_ID, 'task_schema', '');
		if ($registerId === '' || $schemaId === '') {
			$this->logger->warning(
				'Pipelinq: register/task_schema not configured; OpenRegister calls are refused, not run unscoped'
			);
		}

		return [$registerId, $schemaId];
	}//end location()

	/**
	 * The OpenRegister object service.
	 *
	 * @return ObjectServiceInterface
	 *
	 * @spec openspec/changes/task-background-jobs/tasks.md#task-1
	 */
	public function objects(): ObjectServiceInterface {
		return $this->objectService;
	}//end objects()

	/**
	 * Forward the Accept-Language header to OpenRegister's LanguageService.
	 *
	 * Parses the highest-priority BCP-47 tag from the raw header value and
	 * sets it on OR's request-scoped LanguageService so that ObjectService
	 * calls return translated field values when the preferred language is
	 * available (ADR-025, task 9.3).
	 *
	 * No-ops silently when OR's LanguageService is unavailable (pre-ship).
	 *
	 * @param string $acceptLanguage The raw Accept-Language header value.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/pipelinq-manifest-i18n-tenant/tasks.md#task-9.3
	 */
	public function applyAcceptLanguage(string $acceptLanguage): void {
		if ($acceptLanguage === '') {
			return;
		}

		try {
			$lang = $this->parsePreferredLanguage(headerValue: $acceptLanguage);
			$this->languageService->setPreferredLanguage(language: $lang);
		} catch (\Throwable $e) {
			$this->logger->debug(
				message: 'applyAcceptLanguage: OR LanguageService unavailable.',
				context: ['exception' => $e->getMessage()]
			);
		}
	}//end applyAcceptLanguage()

	/**
	 * Extract the highest-priority BCP-47 tag from an Accept-Language header.
	 *
	 * Parses "nl-NL,nl;q=0.9,en;q=0.8" → "nl-NL".
	 *
	 * @param string $headerValue The raw Accept-Language header value.
	 *
	 * @return string The preferred language tag (e.g. "nl" or "en-US").
	 */
	private function parsePreferredLanguage(string $headerValue): string {
		$parts = explode(separator: ',', string: $headerValue);
		$first = trim(string: explode(separator: ';', string: $parts[0])[0]);

		if ($first !== '') {
			return $first;
		}

		return 'nl';
	}//end parsePreferredLanguage()
}//end class
