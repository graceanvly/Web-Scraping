<?php

namespace App\Support;

use Illuminate\Support\Facades\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Minimal Excel-compatible export (SpreadsheetML, opens in Excel). */
final class SpreadsheetXmlExporter
{
	/**
	 * @param  list<string>  $headers
	 * @param  iterable<list<string|int|float|null>>  $rows
	 */
	public static function download(string $filename, string $sheetName, array $headers, iterable $rows): StreamedResponse
	{
		return Response::streamDownload(
			function () use ($sheetName, $headers, $rows): void {
				echo self::documentStart($sheetName);
				echo self::row($headers, true);
				foreach ($rows as $row) {
					echo self::row($row, false);
				}
				echo self::documentEnd();
			},
			$filename,
			['Content-Type' => 'application/vnd.ms-excel; charset=UTF-8']
		);
	}

	private static function documentStart(string $sheetName): string
	{
		$name = self::escape($sheetName);

		return '<?xml version="1.0" encoding="UTF-8"?>'
			. '<?mso-application progid="Excel.Sheet"?>'
			. '<Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet" '
			. 'xmlns:ss="urn:schemas-microsoft-com:office:spreadsheet">'
			. '<Worksheet ss:Name="' . $name . '"><Table>';
	}

	private static function documentEnd(): string
	{
		return '</Table></Worksheet></Workbook>';
	}

	/**
	 * @param  list<string|int|float|null>  $cells
	 */
	private static function row(array $cells, bool $header): string
	{
		$xml = '<Row>';
		foreach ($cells as $cell) {
			$value = self::escape((string) ($cell ?? ''));
			$type = $header ? 'String' : 'String';
			if (!$header && $value !== '' && is_numeric($cell) && !str_contains($value, '.')) {
				$type = 'Number';
			}
			$xml .= '<Cell><Data ss:Type="' . $type . '">' . $value . '</Data></Cell>';
		}

		return $xml . '</Row>';
	}

	private static function escape(string $value): string
	{
		return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
	}
}
