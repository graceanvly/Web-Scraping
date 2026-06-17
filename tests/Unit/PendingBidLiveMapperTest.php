<?php

namespace Tests\Unit;

use App\Models\TempBid;
use App\Support\PendingBidLiveMapper;
use ReflectionClass;
use Tests\TestCase;

class PendingBidLiveMapperTest extends TestCase
{
	protected function tearDown(): void
	{
		$this->resetBidLiveColumnFilterCache();
		parent::tearDown();
	}

	private function resetBidLiveColumnFilterCache(): void
	{
		$ref = new ReflectionClass(\App\Support\BidLiveColumnFilter::class);
		foreach (['schemaResolved', 'allowedLower', 'columnMapLower'] as $prop) {
			if ($ref->hasProperty($prop)) {
				$p = $ref->getProperty($prop);
				$p->setAccessible(true);
				$p->setValue(null, $prop === 'schemaResolved' ? false : null);
			}
		}
	}

	private function stubBidColumnMap(array $columns): void
	{
		$this->resetBidLiveColumnFilterCache();
		$ref = new ReflectionClass(\App\Support\BidLiveColumnFilter::class);
		$ref->getProperty('schemaResolved')->setAccessible(true);
		$ref->getProperty('schemaResolved')->setValue(null, true);

		$allowed = [];
		$map = [];
		foreach ($columns as $col) {
			$lower = strtolower($col);
			$allowed[$lower] = true;
			$map[$lower] = $col;
		}
		$allowedProp = $ref->getProperty('allowedLower');
		$allowedProp->setAccessible(true);
		$allowedProp->setValue(null, $allowed);

		$mapProp = $ref->getProperty('columnMapLower');
		$mapProp->setAccessible(true);
		$mapProp->setValue(null, $map);
	}

	public function test_mapper_uses_schema_column_casing_and_keeps_reference_ids(): void
	{
		$this->stubBidColumnMap(['ID', 'TITLE', 'ENTITYID', 'STATEID', 'BID_URL_ID', 'LAST_MODIFIED']);

		$temp = new TempBid([
			'TITLE' => 'Test bid',
			'ENTITYID' => 34309,
			'STATEID' => 7,
			'BID_URL_ID' => 1171,
			'raw_html' => '<p>not on oracle</p>',
		]);

		$attrs = PendingBidLiveMapper::attributesForInsert($temp);

		$this->assertArrayNotHasKey('raw_html', $attrs);
		$this->assertSame(34309, $attrs['ENTITYID']);
		$this->assertSame(7, $attrs['STATEID']);
		$this->assertSame(1171, $attrs['BID_URL_ID']);
		$this->assertArrayHasKey('LAST_MODIFIED', $attrs);
	}

	public function test_mapper_always_sets_title_when_missing_from_schema_map(): void
	{
		$this->stubBidColumnMap(['ID', 'ENTITYID', 'STATEID', 'LAST_MODIFIED']);

		$temp = new TempBid([
			'TITLE' => 'Roof replacement project',
			'ENTITYID' => 34309,
		]);

		$attrs = PendingBidLiveMapper::attributesForInsert($temp);

		$this->assertSame('Roof replacement project', $attrs['TITLE']);
	}
}
