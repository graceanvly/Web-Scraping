<?php

namespace Tests\Unit;

use App\Models\Bid;
use App\Support\BidLiveWriter;
use Tests\TestCase;

class BidLiveWriterTest extends TestCase
{
	public function test_apply_attributes_sets_reference_ids_on_bid_model(): void
	{
		$bid = new Bid();
		BidLiveWriter::applyAttributes($bid, [
			'TITLE' => 'Roof project',
			'ENTITYID' => 34309,
			'STATEID' => 44,
			'BID_URL_ID' => 1171,
		]);

		$this->assertSame('Roof project', $bid->getAttribute('TITLE'));
		$this->assertSame(34309, (int) $bid->getAttribute('ENTITYID'));
		$this->assertSame(44, (int) $bid->getAttribute('STATEID'));
		$this->assertSame(1171, (int) $bid->getAttribute('BID_URL_ID'));
	}

	public function test_apply_attributes_skips_null_on_update(): void
	{
		$bid = new Bid();
		$bid->setAttribute('INLINEURL', 1);
		$bid->exists = true;

		BidLiveWriter::applyAttributes($bid, [
			'TITLE' => 'Updated title',
			'INLINEURL' => null,
		]);

		$this->assertSame('Updated title', $bid->getAttribute('TITLE'));
		$this->assertSame(1, (int) $bid->getAttribute('INLINEURL'));
	}
}
