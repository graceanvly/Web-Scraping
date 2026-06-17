<?php

namespace Tests\Unit;

use App\Http\Controllers\PendingBidController;
use App\Models\TempBid;
use Illuminate\Http\Request;
use ReflectionMethod;
use Tests\TestCase;

class PendingBidEditableFieldsTest extends TestCase
{

	/** @return array<string, mixed> */
	private function editModalPayload(array $overrides = []): array
	{
		return array_merge([
			'edit_modal' => '1',
			'TITLE' => 'Updated title',
			'DESCRIPTION' => '',
			'EMAIL' => '',
			'URL' => '',
			'ENDDATE' => '',
			'NAICSCODE' => '',
			'ENTITYID' => '42',
			'STATEID' => '7',
			'BID_URL_ID' => '99',
			'CATEGORYID' => '3',
			'USERID' => '',
		], $overrides);
	}

	private function applyEditableFields(Request $request, TempBid $pendingBid): void
	{
		$controller = new PendingBidController();
		$method = new ReflectionMethod($controller, 'applyEditableFields');
		$method->setAccessible(true);
		$method->invoke($controller, $request, $pendingBid);
	}

	public function test_apply_editable_fields_persists_entity_state_and_bid_url(): void
	{
		$temp = new TempBid([
			'TITLE' => 'Original',
			'ENTITYID' => null,
			'STATEID' => null,
			'BID_URL_ID' => null,
		]);

		$request = Request::create('/pending/1', 'PUT', $this->editModalPayload());
		$this->applyEditableFields($request, $temp);

		$this->assertSame('Updated title', $temp->TITLE);
		$this->assertSame(42, (int) $temp->ENTITYID);
		$this->assertSame(7, (int) $temp->STATEID);
		$this->assertSame(99, (int) $temp->BID_URL_ID);
		$this->assertSame(3, (int) $temp->CATEGORYID);
	}

	public function test_to_live_bid_attributes_includes_saved_reference_ids(): void
	{
		$temp = new TempBid([
			'TITLE' => 'Original',
			'ENTITYID' => null,
			'STATEID' => null,
			'BID_URL_ID' => null,
		]);

		$request = Request::create('/pending/1', 'POST', $this->editModalPayload());
		$this->applyEditableFields($request, $temp);

		$attrs = $temp->toLiveBidAttributes();

		$this->assertSame(42, (int) $attrs['ENTITYID']);
		$this->assertSame(7, (int) $attrs['STATEID']);
		$this->assertSame(99, (int) $attrs['BID_URL_ID']);
	}

	private function applyReferenceIdsFromRequest(Request $request, TempBid $pendingBid): void
	{
		$controller = new PendingBidController();
		$method = new ReflectionMethod($controller, 'applyReferenceIdsFromRequest');
		$method->setAccessible(true);
		$method->invoke($controller, $request, $pendingBid);
	}

	public function test_apply_reference_ids_skips_missing_request_keys(): void
	{
		$temp = new TempBid([
			'TITLE' => 'Original',
			'ENTITYID' => 42,
			'STATEID' => 7,
			'BID_URL_ID' => 99,
		]);

		$request = Request::create('/pending/1', 'POST', [
			'edit_modal' => '1',
			'TITLE' => 'Updated title',
		]);

		$this->applyReferenceIdsFromRequest($request, $temp);

		$this->assertSame(42, (int) $temp->ENTITYID);
		$this->assertSame(7, (int) $temp->STATEID);
		$this->assertSame(99, (int) $temp->BID_URL_ID);
	}
}
