<?php

/**
 * Unit tests for AddInManifest.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service\MailAddIn
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://pipelinq.nl
 *
 * @spec openspec/changes/contact-moments-mail-add-in/specs/mail-add-in/spec.md
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service\MailAddIn;

use DOMDocument;
use DOMXPath;
use OCA\Pipelinq\Service\MailAddIn\AddInManifest;
use OCP\IConfig;
use OCP\IURLGenerator;
use PHPUnit\Framework\TestCase;

/**
 * The manifest Outlook installs: well-formed, pointing at this instance only.
 *
 * @covers \OCA\Pipelinq\Service\MailAddIn\AddInManifest
 *
 * @spec openspec/changes/contact-moments-mail-add-in/specs/mail-add-in/spec.md#requirement-pipelinq-serves-an-outlook-add-in-req-mai-001
 */
class AddInManifestTest extends TestCase {
	/**
	 * A manifest for an instance at the given base URL.
	 *
	 * @param string $base       The instance's absolute base URL.
	 * @param string $instanceId The instance id.
	 *
	 * @return AddInManifest
	 */
	private function manifest(string $base = 'https://cloud.example.nl', string $instanceId = 'oc1234'): AddInManifest {
		$urls = $this->createMock(IURLGenerator::class);
		$urls->method('getAbsoluteURL')->willReturnCallback(static fn (string $path): string => $base . $path);
		$urls->method('imagePath')->willReturnCallback(static fn (string $app, string $file): string => '/apps/' . $app . '/img/' . $file);
		$urls->method('linkToRouteAbsolute')->willReturnCallback(
			static fn (string $route): string => match ($route) {
				'pipelinq.mailAddIn.taskpane' => $base . '/apps/pipelinq/mail-add-in/taskpane',
				'pipelinq.dashboard.page' => $base . '/apps/pipelinq/',
				default => $base . '/unexpected/' . $route,
			}
		);

		$config = $this->createMock(IConfig::class);
		$config->method('getSystemValue')->with('instanceid', '')->willReturn($instanceId);

		return new AddInManifest($urls, $config);
	}//end manifest()

	/**
	 * Parse the manifest and give an XPath over the Office namespace.
	 *
	 * @param string $xml The manifest.
	 *
	 * @return DOMXPath
	 */
	private function xpath(string $xml): DOMXPath {
		$doc = new DOMDocument();
		$this->assertTrue($doc->loadXML($xml), 'the manifest is well-formed XML');
		$xpath = new DOMXPath($doc);
		$xpath->registerNamespace('o', 'http://schemas.microsoft.com/office/appforoffice/1.1');
		$xpath->registerNamespace('xsi', 'http://www.w3.org/2001/XMLSchema-instance');
		return $xpath;
	}//end xpath()

	/**
	 * The manifest is a mail app for the read form, whose pane, icons and
	 * domain are all this instance.
	 *
	 * @return void
	 */
	public function testTheManifestPointsAtThisInstance(): void {
		$xpath = $this->xpath($this->manifest()->xml());

		$this->assertSame('MailApp', $xpath->evaluate('string(/o:OfficeApp/@xsi:type)'));
		$this->assertSame(
			'https://cloud.example.nl/apps/pipelinq/mail-add-in/taskpane',
			$xpath->evaluate('string(//o:Form[@xsi:type="ItemRead"]/o:DesktopSettings/o:SourceLocation/@DefaultValue)')
		);
		$this->assertSame('https://cloud.example.nl', $xpath->evaluate('string(//o:AppDomains/o:AppDomain)'));
		$this->assertSame('https://cloud.example.nl/apps/pipelinq/img/mail-add-in-64.png', $xpath->evaluate('string(//o:IconUrl/@DefaultValue)'));
		$this->assertSame('https://cloud.example.nl/apps/pipelinq/img/mail-add-in-128.png', $xpath->evaluate('string(//o:HighResolutionIconUrl/@DefaultValue)'));
		$this->assertSame('https://cloud.example.nl/apps/pipelinq/', $xpath->evaluate('string(//o:SupportUrl/@DefaultValue)'));
		$this->assertSame('ReadItem', $xpath->evaluate('string(//o:Permissions)'));
		$this->assertSame('Mailbox', $xpath->evaluate('string(//o:Hosts/o:Host/@Name)'));
		$this->assertSame('Message', $xpath->evaluate('string(//o:Rule[@xsi:type="ItemIs"]/@ItemType)'));
		$this->assertSame('Read', $xpath->evaluate('string(//o:Rule[@xsi:type="ItemIs"]/@FormType)'));
	}//end testTheManifestPointsAtThisInstance()

	/**
	 * The top-level elements come in the order the manifest schema demands;
	 * Outlook refuses a manifest whose elements are out of order.
	 *
	 * @return void
	 */
	public function testTheElementsFollowTheSchemaOrder(): void {
		$xpath = $this->xpath($this->manifest()->xml());
		$names = [];
		foreach ($xpath->query('/o:OfficeApp/*') as $node) {
			$names[] = $node->localName;
		}

		$this->assertSame(
			[
				'Id', 'Version', 'ProviderName', 'DefaultLocale', 'DisplayName', 'Description', 'IconUrl',
				'HighResolutionIconUrl', 'SupportUrl', 'AppDomains', 'Hosts', 'Requirements', 'FormSettings',
				'Permissions', 'Rule', 'DisableEntityHighlighting',
			],
			$names
		);
	}//end testTheElementsFollowTheSchemaOrder()

	/**
	 * The add-in id is a GUID that stays put for one instance and differs
	 * between instances.
	 *
	 * @return void
	 */
	public function testTheAddInIdIsStablePerInstance(): void {
		$first = $this->manifest(instanceId: 'oc1234')->addInId();

		$this->assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-8[0-9a-f]{3}-[0-9a-f]{12}$/', $first);
		$this->assertSame($first, $this->manifest(instanceId: 'oc1234')->addInId());
		$this->assertNotSame($first, $this->manifest(instanceId: 'oc9999')->addInId());

		$xpath = $this->xpath($this->manifest(instanceId: 'oc1234')->xml());
		$this->assertSame($first, $xpath->evaluate('string(/o:OfficeApp/o:Id)'));
	}//end testTheAddInIdIsStablePerInstance()

	/**
	 * A port stays in the domain, and a URL with XML specials stays
	 * well-formed and round-trips.
	 *
	 * @return void
	 */
	public function testPortsAndSpecialCharactersSurvive(): void {
		$manifest = $this->manifest(base: 'https://cloud.example.nl:8443/sub&dir');

		$this->assertSame('https://cloud.example.nl:8443', $manifest->origin());
		$xpath = $this->xpath($manifest->xml());
		$this->assertSame(
			'https://cloud.example.nl:8443/sub&dir/apps/pipelinq/mail-add-in/taskpane',
			$xpath->evaluate('string(//o:SourceLocation/@DefaultValue)')
		);
	}//end testPortsAndSpecialCharactersSurvive()
}//end class
