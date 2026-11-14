<?php

/**
 * Names the destination of the forms pipelinq asked for before forms had one.
 *
 * Decision 179: a form names the object a submit creates. Forms that
 * pipelinq requested earlier have none: their submissions were relayed, and
 * pipelinq turned each into a contact, a lead and a touchpoint in code. This
 * service writes that same target onto each such form, once, as the
 * declaration CampaignFormDestination gives a new form. A form that already
 * names a destination is left alone, so an administrator's own choice is
 * never overwritten and a second run changes nothing.
 *
 * The forms live in the hosting app's register. They are found by the app
 * that asked for them (`sourceApp`), and read and written through
 * OpenRegister, so nothing here depends on the hosting app's code.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Service
 * @package  OCA\Pipelinq\Service\Marketing
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/changes/website-forms-submit-into-tickets/specs/marketing-campaigns/spec.md#requirement-a-landing-page-form-submits-into-a-lead-with-its-contact-and-touchpoint
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Marketing;

use OCA\Pipelinq\Kernel\AppIdentity;
use OCA\Pipelinq\Kernel\Contracts\FormDestinationBackfillInterface;
use OCA\Pipelinq\Service\CampaignService;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Sets `destination` on this app's hosted forms that lack one.
 *
 * @spec openspec/changes/website-forms-submit-into-tickets/specs/marketing-campaigns/spec.md#requirement-a-landing-page-form-submits-into-a-lead-with-its-contact-and-touchpoint
 */
class FormDestinationBackfill implements FormDestinationBackfillInterface {

	/**
	 * The register the hosted forms live in.
	 *
	 * @var string
	 */
	public const FORM_REGISTER = 'portaliq';

	/**
	 * The schema of a hosted form.
	 *
	 * @var string
	 */
	public const FORM_SCHEMA = 'form';

	/**
	 * Forms read per page.
	 *
	 * @var int
	 */
	private const PAGE = 100;

	/**
	 * Never read more forms than this in one run.
	 *
	 * @var int
	 */
	private const MAX_FORMS = 5000;

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface      $container    Container, used to reach OpenRegister's ObjectService.
	 * @param CampaignService         $campaigns    Resolves the campaign a form belongs to.
	 * @param CampaignFormDestination $destinations Declares a campaign form's destination.
	 * @param LoggerInterface         $logger       PSR logger.
	 *
	 * @spec openspec/changes/website-forms-submit-into-tickets/specs/marketing-campaigns/spec.md#requirement-a-landing-page-form-submits-into-a-lead-with-its-contact-and-touchpoint
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly CampaignService $campaigns,
		private readonly CampaignFormDestination $destinations,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Name the destination of every hosted form of this app that has none.
	 *
	 * @return array{status: string, named: int, skipped: int, failed: int} `status` is `done`,
	 *         `no-openregister` or `read-failed`; the counts say what happened to each form.
	 *
	 * @spec openspec/changes/website-forms-submit-into-tickets/specs/marketing-campaigns/spec.md#requirement-a-landing-page-form-submits-into-a-lead-with-its-contact-and-touchpoint
	 */
	public function run(): array {
		$result = ['status' => 'done', 'named' => 0, 'skipped' => 0, 'failed' => 0];
		try {
			$objects = $this->container->get('OCA\\OpenRegister\\Service\\ObjectService');
		} catch (Throwable $e) {
			$result['status'] = 'no-openregister';
			return $result;
		}

		try {
			$forms = $this->forms(objects: $objects);
		} catch (Throwable $e) {
			$this->logger->info('FormDestinationBackfill: the hosted forms could not be read', ['exception' => $e->getMessage()]);
			$result['status'] = 'read-failed';
			return $result;
		}

		$counts = ['named' => 0, 'skipped' => 0, 'failed' => 0];
		foreach ($forms as $form) {
			$outcome = $this->name(objects: $objects, form: $form);
			$counts[$outcome] = ($counts[$outcome] + 1);
		}

		return ['status' => 'done', 'named' => $counts['named'], 'skipped' => $counts['skipped'], 'failed' => $counts['failed']];
	}//end run()

	/**
	 * Every hosted form this app asked for.
	 *
	 * The `sourceApp` filter is applied again in PHP: OpenRegister ignores a
	 * filter key it does not know, and an ignored filter would hand over
	 * every app's forms looking like a correct result.
	 *
	 * @param object $objects OpenRegister's object service.
	 *
	 * @return array<int, array{id: string, data: array<string, mixed>}> The forms.
	 */
	private function forms(object $objects): array {
		$forms = [];
		$offset = 0;
		do {
			$page = $this->formPage(objects: $objects, offset: $offset);
			if (is_iterable($page) === false) {
				break;
			}

			[$count, $pageForms] = $this->appFormsOf(page: $page);
			$forms = array_merge($forms, $pageForms);

			$offset += self::PAGE;
		} while ($count === self::PAGE && $offset < self::MAX_FORMS);

		return $forms;
	}//end forms()

	/**
	 * Read one page of this app's forms, unscoped (a repair has no session).
	 *
	 * @param object $objects The OpenRegister ObjectService.
	 * @param int $offset The page offset.
	 *
	 * @return mixed The page as findAll returns it.
	 */
	private function formPage(object $objects, int $offset) {
		return $objects->findAll(
			config: [
				'filters' => ['register' => self::FORM_REGISTER, 'schema' => self::FORM_SCHEMA, 'sourceApp' => AppIdentity::APP_ID],
				'limit' => self::PAGE,
				'offset' => $offset,
			],
			_rbac: false,
			_multitenancy: false
		);
	}//end formPage()

	/**
	 * Count a page's rows and keep the forms that belong to this app.
	 *
	 * @param iterable<mixed> $page The page rows.
	 *
	 * @return array{0: int, 1: array<int, array<string, mixed>>} The row count and this app's forms.
	 */
	private function appFormsOf(iterable $page): array {
		$count = 0;
		$forms = [];
		foreach ($page as $row) {
			$count++;
			$form = $this->formOf(row: $row);
			if ($form !== null && (string)($form['data']['sourceApp'] ?? '') === AppIdentity::APP_ID) {
				$forms[] = $form;
			}
		}

		return [$count, $forms];
	}//end appFormsOf()

	/**
	 * A form's id and its own data, without metadata.
	 *
	 * @param mixed $row An OpenRegister entity or a plain row.
	 *
	 * @return array{id: string, data: array<string, mixed>}|null The form, or null when it has no id.
	 */
	private function formOf(mixed $row): ?array {
		$id = '';
		$data = [];
		if (is_object($row) === true && method_exists($row, 'getObject') === true && method_exists($row, 'getUuid') === true) {
			$id = (string)$row->getUuid();
			$data = (array)$row->getObject();
		}

		if (is_array($row) === true) {
			$id = (string)($row['@self']['id'] ?? ($row['uuid'] ?? ($row['id'] ?? '')));
			$data = $row;
		}

		unset($data['@self'], $data['id'], $data['uuid']);
		if ($id === '') {
			return null;
		}

		return ['id' => $id, 'data' => $data];
	}//end formOf()

	/**
	 * Name one form's destination, unless it has one.
	 *
	 * @param object                                           $objects OpenRegister's object service.
	 * @param array{id: string, data: array<string, mixed>}    $form    The form.
	 *
	 * @return 'named'|'skipped'|'failed' What happened to the form.
	 */
	private function name(object $objects, array $form): string {
		if ($this->hasDestination(form: $form) === true) {
			return 'skipped';
		}

		$campaignId = $this->campaignIdFor(form: $form);
		$campaign = $this->campaignFor(campaignId: $campaignId);

		$data = $form['data'];
		$data['destination'] = $this->destinations->forCampaign(campaignId: $campaignId, campaign: $campaign);

		return $this->saveNamedForm(objects: $objects, data: $data, formId: $form['id']);
	}//end name()

	/**
	 * Whether the form already names a register and schema as its destination.
	 *
	 * @param array<string, mixed> $form The form.
	 *
	 * @return bool True when the destination is set.
	 */
	private function hasDestination(array $form): bool {
		$current = ($form['data']['destination'] ?? null);
		return is_array($current) === true && trim((string)($current['register'] ?? '')) !== '' && trim((string)($current['schema'] ?? '')) !== '';
	}//end hasDestination()

	/**
	 * The campaign behind the form, or [] when it has none or it cannot be found.
	 *
	 * @param string $campaignId The campaign id, or ''.
	 *
	 * @return array<string, mixed> The campaign.
	 */
	private function campaignFor(string $campaignId): array {
		$campaign = [];
		if ($campaignId !== '') {
			$campaign = ($this->campaigns->find(id: $campaignId) ?? []);
		}

		return $campaign;
	}//end campaignFor()

	/**
	 * Save the form with its destination; `named`, or `failed` (logged) when the save fails.
	 *
	 * @param object $objects The OpenRegister ObjectService.
	 * @param array<string, mixed> $data The form data with its destination.
	 * @param mixed $formId The form id.
	 *
	 * @return string The outcome.
	 */
	private function saveNamedForm(object $objects, array $data, $formId): string {
		try {
			$objects->saveObject(
				object: $data,
				register: self::FORM_REGISTER,
				schema: self::FORM_SCHEMA,
				uuid: $formId,
				_rbac: false,
				_multitenancy: false
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				'FormDestinationBackfill: a form could not be given its destination',
				['form' => $formId, 'exception' => $e->getMessage()]
			);
			return 'failed';
		}

		return 'named';
	}//end saveNamedForm()

	/**
	 * The campaign a form belongs to.
	 *
	 * The reference pipelinq set on the request carries it. When it is
	 * missing, the form id is matched against the campaigns' stored
	 * `formRef`, as the relay listener did.
	 *
	 * @param array{id: string, data: array<string, mixed>} $form The form.
	 *
	 * @return string The campaign id, or an empty string.
	 */
	private function campaignIdFor(array $form): string {
		$id = $this->destinations->campaignIdOf(reference: (string)($form['data']['externalReference'] ?? ''));
		if ($id !== '') {
			return $id;
		}

		$matches = $this->campaigns->all(filters: ['formRef' => $form['id']]);
		if ($matches === []) {
			return '';
		}

		return $this->campaigns->idOf(campaign: $matches[0]);
	}//end campaignIdFor()
}//end class
