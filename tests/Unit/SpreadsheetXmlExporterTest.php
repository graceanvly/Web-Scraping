<?php

namespace Tests\Unit;

use App\Support\SpreadsheetXmlExporter;
use Tests\TestCase;

class SpreadsheetXmlExporterTest extends TestCase
{
	public function test_download_response_has_excel_content_type(): void
	{
		$response = SpreadsheetXmlExporter::download(
			'test.xls',
			'Sheet1',
			['URL'],
			[['https://example.gov/bids']],
		);

		$this->assertStringContainsString('application/vnd.ms-excel', (string) $response->headers->get('Content-Type'));
		$this->assertStringContainsString('test.xls', (string) $response->headers->get('Content-Disposition'));
	}
}
