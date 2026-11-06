<?php

/**
 * Pipelinq RecipientNameReader.
 *
 * Reads the name a mail greets its recipient by: the person's preferred name,
 * or their full name when they have stated none. Used by the send path
 * (MailTransportService) to fill `{{preferredName}}`.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service\Marketing
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/changes/clients-preferred-name/specs/client-preferred-name/spec.md#requirement-the-preferred-name-is-used-where-a-person-is-addressed-req-cpn-002
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Marketing;

use OCA\Pipelinq\AppInfo\Application;
use OCP\IAppConfig;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Reads the name a recipient is greeted by.
 *
 * @spec openspec/changes/clients-preferred-name/specs/client-preferred-name/spec.md#requirement-the-preferred-name-is-used-where-a-person-is-addressed-req-cpn-002
 */
class RecipientNameReader {
	/**
	 * App config keys of the schemas a recipient id may point at, in the
	 * order they are tried: a contact person first, then a client.
	 *
	 * @var array<int, string>
	 */
	private const SCHEMA_KEYS = ['contact_schema', 'client_schema'];

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container DI container (OpenRegister's ObjectService).
	 * @param IAppConfig $appConfig The register and schema ids.
	 * @param LoggerInterface $logger Logger.
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly IAppConfig $appConfig,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The name to greet a recipient by.
	 *
	 * The recipient id is looked up as a contact person, then as a client. The
	 * first record found decides: its preferred name, or its full name when it
	 * has none. A recipient that is no record here (a public signup known only
	 * by its address) has no name, and neither has an install without a
	 * register.
	 *
	 * @param string $recipientId The delivery's `contactId`.
	 *
	 * @return string The preferred name, the full name, or '' when unknown.
	 *
	 * @spec openspec/changes/clients-preferred-name/specs/client-preferred-name/spec.md#requirement-the-preferred-name-is-used-where-a-person-is-addressed-req-cpn-002
	 */
	public function greetingName(string $recipientId): string {
		$person = $this->recordFor(recipientId: $recipientId);
		if ($person === null) {
			return '';
		}

		$preferred = trim((string)($person['preferredName'] ?? ''));
		if ($preferred !== '') {
			return $preferred;
		}

		return trim((string)($person['name'] ?? ''));
	}//end greetingName()

	/**
	 * The full name a recipient is listed by, such as a subscriber row.
	 *
	 * Looked up as `greetingName()` looks it up, but always the full name:
	 * a list names the person or organisation, it does not greet them.
	 *
	 * @param string $recipientId A `contactId`: a contact person or a client.
	 *
	 * @return string The full name, or '' when unknown.
	 *
	 * @spec openspec/specs/marketing-lists/spec.md#requirement-a-mailing-lists-subscribers-read-by-name
	 */
	public function fullName(string $recipientId): string {
		$record = $this->recordFor(recipientId: $recipientId);
		if ($record === null) {
			return '';
		}

		return trim((string)($record['name'] ?? ''));
	}//end fullName()

	/**
	 * The first record the recipient id names: a contact person, then a
	 * client. Null when it names neither, or there is no register.
	 *
	 * @param string $recipientId The recipient id.
	 *
	 * @return array<string, mixed>|null The record's data.
	 */
	private function recordFor(string $recipientId): ?array {
		$register = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
		if ($recipientId === '' || $register === '') {
			return null;
		}

		foreach (self::SCHEMA_KEYS as $schemaKey) {
			$schema = $this->appConfig->getValueString(Application::APP_ID, $schemaKey, '');
			if ($schema === '') {
				continue;
			}

			$record = $this->read(id: $recipientId, register: $register, schema: $schema);
			if ($record !== null) {
				return $record;
			}
		}

		return null;
	}//end recordFor()

	/**
	 * One object's data, or null when it is not there or cannot be read.
	 *
	 * @param string $id The object id.
	 * @param string $register The register id.
	 * @param string $schema The schema id.
	 *
	 * @return array<string, mixed>|null The object's data.
	 */
	private function read(string $id, string $register, string $schema): ?array {
		try {
			$objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
			$object = $objectService->find(id: $id, register: $register, schema: $schema);
		} catch (Throwable $e) {
			$this->logger->info(
				'RecipientNameReader: recipient not read',
				['id' => $id, 'schema' => $schema, 'exception' => $e->getMessage()]
			);
			return null;
		}

		if (is_array($object) === true) {
			return $object;
		}

		if (is_object($object) === true && method_exists($object, 'getObject') === true) {
			return (array)$object->getObject();
		}

		return null;
	}//end read()
}//end class
