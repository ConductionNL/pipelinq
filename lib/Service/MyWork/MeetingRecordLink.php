<?php

/**
 * Pipelinq MeetingRecordLink.
 *
 * Reads which pipelinq record a calendar event is about: the first link to a
 * client, contact or lead page in its description or location. A link is the
 * page's own address, `/apps/pipelinq/clients/<id>`, as the browser shows it
 * when a user copies it into the event.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service\MyWork
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/changes/my-work-roadmap/specs/my-work/spec.md#requirement-upcoming-meetings-from-the-calendar-req-mw-130
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service\MyWork;

/**
 * The pipelinq record an event's text links to.
 *
 * @spec openspec/changes/my-work-roadmap/specs/my-work/spec.md#requirement-upcoming-meetings-from-the-calendar-req-mw-130
 */
class MeetingRecordLink {

	/**
	 * The kinds of record a meeting can be about, by the path segment of their page.
	 *
	 * Each names the app config key of its schema and the route of its page.
	 *
	 * @var array<string, array{kind: string, schemaKey: string, route: string}>
	 */
	public const KINDS = [
		'clients' => ['kind' => 'client', 'schemaKey' => 'client_schema', 'route' => 'ClientDetail'],
		'contacts' => ['kind' => 'contact', 'schemaKey' => 'contact_schema', 'route' => 'ContactDetail'],
		'leads' => ['kind' => 'lead', 'schemaKey' => 'lead_schema', 'route' => 'LeadDetail'],
	];

	/**
	 * A link to a record page: the app path, optionally behind index.php, then the kind and a UUID.
	 *
	 * @var string
	 */
	private const PATTERN = '~/apps/pipelinq/(clients|contacts|leads)/([0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12})~';

	/**
	 * The record the first pipelinq link in the texts points at, or null.
	 *
	 * @param array<int, string> $texts The event's description and location, in that order.
	 *
	 * @return array{kind: string, schemaKey: string, route: string, id: string}|null The record.
	 *
	 * @spec openspec/changes/my-work-roadmap/tasks.md#task-5.1
	 */
	public function in(array $texts): ?array {
		foreach ($texts as $text) {
			if (preg_match(self::PATTERN, $text, $match) === 1) {
				return self::KINDS[$match[1]] + ['id' => strtolower($match[2])];
			}
		}

		return null;
	}//end in()
}//end class
