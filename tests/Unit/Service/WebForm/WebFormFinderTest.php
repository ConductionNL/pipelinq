<?php

/**
 * Unit tests for WebFormFinder and WebFormSubmitException.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service\WebForm
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/changes/website-forms-submit-into-tickets/specs/website-enquiry-intake/spec.md#requirement-a-website-form-submits-into-the-destination-it-names
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service\WebForm;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\Pipelinq\Service\WebForm\WebFormFinder;
use OCA\Pipelinq\Service\WebForm\WebFormSubmitException;
use OCA\Pipelinq\Tests\Unit\Service\WebForm\Fakes\FakeObjectStore;
use OCA\Pipelinq\Tests\Unit\Service\WebForm\Fakes\ObjectServiceContract;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Only a form whose own body says published and matches is taken.
 */
class WebFormFinderTest extends TestCase {

	use ObjectServiceContract;

	/**
	 * The store of the last built finder.
	 *
	 * @var FakeObjectStore
	 */
	private FakeObjectStore $store;

	/**
	 * A finder over the given stored rows.
	 *
	 * @param array<int, mixed> $rows The rows.
	 *
	 * @return WebFormFinder The finder.
	 */
	private function finder(array $rows): WebFormFinder {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => ['register' => '20', 'webForm_schema' => '40'][$key] ?? $default
		);
		$this->store = new FakeObjectStore(rows: ['40' => $rows]);

		return new WebFormFinder($config, $this->contractOver($this->store));
	}//end finder()

	/**
	 * The published form for a key, with its id, read with RBAC off and filtered on status and key.
	 *
	 * @return void
	 */
	public function testPublishedByKeyReturnsTheFormWithItsId(): void {
		$form = $this->finder([['@self' => ['id' => 'f-1'], 'key' => 'website-support', 'status' => 'published']])->publishedByKey(key: 'website-support');

		$this->assertSame('f-1', $form['id']);
		$filters = $this->store->queries[0]['filters'];
		$this->assertSame(['register' => '20', 'schema' => '40', 'status' => 'published', 'key' => 'website-support'], $filters);
		$this->assertFalse($this->store->queries[0]['_rbac']);
	}//end testPublishedByKeyReturnsTheFormWithItsId()

	/**
	 * A row the store hands back that is not published, or has another key, is not taken.
	 *
	 * The store's filter narrows; the decision is the body's own.
	 *
	 * @return void
	 */
	public function testStoreRowsThatDoNotMatchAreIgnored(): void {
		$draft = new ObjectEntity();
		$draft->setUuid('f-1');
		$draft->setObject(['key' => 'website-support', 'status' => 'draft']);
		$other = new ObjectEntity();
		$other->setUuid('f-2');
		$other->setObject(['key' => 'website-other', 'status' => 'published']);
		// Entity rows pass the fake store's filter untouched, as a store that
		// ignored a filter would hand them back.
		$finder = $this->finder([$draft, $other]);

		$this->assertNull($finder->publishedByKey(key: 'website-support'));
	}//end testStoreRowsThatDoNotMatchAreIgnored()

	/**
	 * An ObjectEntity row is read through jsonSerialize, its uuid as the id.
	 *
	 * @return void
	 */
	public function testObjectEntityRowsAreRead(): void {
		$entity = new ObjectEntity();
		$entity->setUuid('f-9');
		$entity->setObject(['key' => 'forms-bound', 'status' => 'published', 'formsFormId' => 7]);

		$form = $this->finder([$entity])->publishedByFormsFormId(formsFormId: 7);

		$this->assertSame('f-9', $form['id']);
		$this->assertSame('forms-bound', $form['key']);
	}//end testObjectEntityRowsAreRead()

	/**
	 * Every published form on a key, so a second one can be named a conflict.
	 *
	 * @return void
	 */
	public function testAllPublishedByKeyListsEveryMatch(): void {
		$finder = $this->finder(
			[
				['@self' => ['id' => 'f-1'], 'key' => 'k', 'status' => 'published'],
				['@self' => ['id' => 'f-2'], 'key' => 'k', 'status' => 'published'],
			]
		);

		$this->assertSame(['f-1', 'f-2'], array_column($finder->allPublishedByKey(key: 'k'), 'id'));
		$this->assertSame([], $finder->allPublishedByKey(key: ''));
	}//end testAllPublishedByKeyListsEveryMatch()

	/**
	 * An empty key or form id reads nothing at all.
	 *
	 * @return void
	 */
	public function testEmptyLookupsReadNothing(): void {
		$finder = $this->finder([]);

		$this->assertNull($finder->publishedByKey(key: ''));
		$this->assertNull($finder->publishedByFormsFormId(formsFormId: 0));
		$this->assertSame([], $this->store->queries);
	}//end testEmptyLookupsReadNothing()

	/**
	 * Without a configured register or schema the finder refuses rather than reading everything.
	 *
	 * @return void
	 */
	public function testUnconfiguredThrows(): void {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturn('');

		$this->expectException(RuntimeException::class);
		(new WebFormFinder($config, $this->contractOver(new FakeObjectStore())))->publishedByKey(key: 'website-support');
	}//end testUnconfiguredThrows()

	/**
	 * The refusal carries its status and findings.
	 *
	 * @return void
	 */
	public function testSubmitExceptionCarriesStatusAndFindings(): void {
		$findings = [['property' => 'title', 'code' => 'required']];
		$e = new WebFormSubmitException('No.', 422, $findings);

		$this->assertSame('No.', $e->getMessage());
		$this->assertSame(422, $e->getStatus());
		$this->assertSame($findings, $e->getFindings());
		$this->assertSame([], (new WebFormSubmitException('Later.', 503))->getFindings());
	}//end testSubmitExceptionCarriesStatusAndFindings()
}//end class
