<?php

namespace Tests\Unit;

use App\Models\Bid;
use App\Support\BidLiveColumnFilter;
use ReflectionClass;
use Tests\TestCase;

class BidLiveColumnFilterTest extends TestCase
{
	protected function tearDown(): void
	{
		$this->resetBidLiveColumnFilterCache();
		parent::tearDown();
	}

	private function resetBidLiveColumnFilterCache(): void
	{
		$ref = new ReflectionClass(BidLiveColumnFilter::class);
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
		$ref = new ReflectionClass(BidLiveColumnFilter::class);
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

	public function test_filter_preserves_uppercase_keys_for_eloquent_fill(): void
	{
		$this->stubBidColumnMap(['id', 'title', 'entityid', 'stateid', 'bid_url_id']);

		$filtered = BidLiveColumnFilter::filter([
			'TITLE' => 'Roof project',
			'ENTITYID' => 34309,
			'STATEID' => 7,
			'BID_URL_ID' => 1171,
			'raw_html' => '<p>skip</p>',
		]);

		$this->assertSame([
			'TITLE' => 'Roof project',
			'ENTITYID' => 34309,
			'STATEID' => 7,
			'BID_URL_ID' => 1171,
		], $filtered);

		$bid = new Bid();
		$bid->fill($filtered);

		$this->assertSame('Roof project', $bid->getAttribute('TITLE'));
		$this->assertSame(34309, (int) $bid->getAttribute('ENTITYID'));
		$this->assertSame(7, (int) $bid->getAttribute('STATEID'));
		$this->assertSame(1171, (int) $bid->getAttribute('BID_URL_ID'));
	}

	public function test_filter_accepts_soliciationnumber_alias_for_oracle_solicitationnumber(): void
	{
		$this->stubBidColumnMap(['id', 'title', 'solicitationnumber']);

		$this->assertTrue(BidLiveColumnFilter::hasColumn('SOLICIATIONNUMBER'));
		$this->assertSame('SOLICITATIONNUMBER', BidLiveColumnFilter::liveAttributeKeyFor('SOLICIATIONNUMBER'));

		$filtered = BidLiveColumnFilter::filter([
			'SOLICIATIONNUMBER' => 'ABC-123',
		]);

		$this->assertSame(['SOLICIATIONNUMBER' => 'ABC-123'], $filtered);
	}
}
