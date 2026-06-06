<dialog id="manualBidModal">
	<div class="manual-modal-shell">
		<div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:1rem;">
			<div>
				<h3 style="margin:0;">Add bid manually</h3>
				<p id="manualBidSourceLabel" class="muted" style="margin:0.35rem 0 0; font-size:0.85rem;"></p>
			</div>
			<button type="button" class="manual-modal-close" onclick="closeManualBidModal()">&times;</button>
		</div>
		<div class="manual-modal-layout">
			<form id="manualBidForm" method="POST" class="manual-modal-main">
				@csrf
				<input type="hidden" name="started_at" id="manual_started_at">
				<input type="hidden" name="search" value="{{ $search ?? '' }}">
				<input type="hidden" name="per_page" value="{{ request('per_page', 50) }}">
				<input type="hidden" name="page" value="{{ request('page', 1) }}">
				<input type="hidden" name="failed_page" value="{{ request('failed_page', 1) }}">
				<input type="hidden" name="approve" id="manual_approve" value="0">

				<div class="manual-edit-grid">
					<div class="full">
						<label for="manual_title">Title</label>
						<input type="text" id="manual_title" name="TITLE" required maxlength="255">
					</div>
					<div class="full ref-picker" id="manualEntityPicker">
						<label for="manual_entity_search">Entity <span id="manualEntityHint" class="muted" style="font-weight:400;"></span></label>
						<input type="hidden" id="manual_entity_id" name="ENTITYID">
						<div class="ref-picker-inner">
							<input type="search" id="manual_entity_search" autocomplete="off" placeholder="Search entity master list…">
							<ul id="manual_entity_results" class="ref-picker-results" role="listbox" hidden></ul>
						</div>
						<div class="ref-picker-meta">
							<button type="button" class="ref-picker-clear" id="manual_entity_clear">Clear entity</button>
						</div>
					</div>
					<div class="full ref-picker" id="manualStatePicker">
						<label for="manual_state_search">State <span id="manualStateHint" class="muted" style="font-weight:400;"></span></label>
						<input type="hidden" id="manual_state_id" name="STATEID">
						<div class="ref-picker-inner">
							<input type="search" id="manual_state_search" autocomplete="off" placeholder="Search states…">
							<ul id="manual_state_results" class="ref-picker-results" role="listbox" hidden></ul>
						</div>
						<div class="ref-picker-meta">
							<button type="button" class="ref-picker-clear" id="manual_state_clear">Clear state</button>
						</div>
					</div>
					<div>
						<label for="manual_enddate">End date</label>
						<input type="date" id="manual_enddate" name="ENDDATE">
					</div>
					<div>
						<label for="manual_naics">NAICS code</label>
						<input type="text" id="manual_naics" name="NAICSCODE" maxlength="255">
					</div>
					<div>
						<label for="manual_email">Contact email</label>
						<input type="email" id="manual_email" name="EMAIL" maxlength="255">
					</div>
					<div>
						<label for="manual_userid">Assign to</label>
						<select id="manual_userid" name="USERID">
							<option value="">— None —</option>
							@foreach ($manilaDirectoryUsers ?? [] as $dirUser)
								<option value="{{ $dirUser['id'] }}">{{ $dirUser['label'] }}</option>
							@endforeach
						</select>
					</div>
					<div class="full">
						<label for="manual_url">Bid URL</label>
						<input type="text" id="manual_url" name="URL" maxlength="500">
					</div>
					<div class="full">
						<label for="manual_description">Description</label>
						<textarea id="manual_description" name="DESCRIPTION" rows="5"></textarea>
					</div>
				</div>

				<footer class="manual-modal-footer">
					<button type="button" class="secondary outline" onclick="closeManualBidModal()">Cancel</button>
					<div style="display:flex; gap:0.5rem;">
						<button type="submit" class="secondary" onclick="prepareManualSubmit(false)">Save to pending</button>
						<button type="submit" style="background:#16a34a; border-color:#16a34a;" onclick="prepareManualSubmit(true)">Save &amp; approve</button>
					</div>
				</footer>
			</form>

			<aside class="similar-panel" aria-labelledby="manualSimilarHeading">
				<h4 id="manualSimilarHeading">Similar bids</h4>
				<p class="similar-meta" id="manualSimilarMatchLabel">Last 5 by entity, then email, then URL.</p>
				<p class="similar-loading" id="manualSimilarLoading" hidden>Loading…</p>
				<ul class="similar-list" id="manualSimilarList" hidden></ul>
				<p class="similar-empty" id="manualSimilarEmpty" hidden>No similar bids found.</p>
			</aside>
		</div>
	</div>
</dialog>

<dialog id="manualSimilarDetailModal">
	<div class="similar-detail-shell">
		<div style="display:flex; justify-content:space-between; align-items:flex-start; gap:1rem; margin-bottom:1rem;">
			<div>
				<span id="manual_similar_detail_badge" class="similar-badge live">Live</span>
				<h3 id="manual_similar_detail_title" style="margin:0.35rem 0 0; font-size:1.25rem;"></h3>
			</div>
			<button type="button" class="manual-modal-close" onclick="document.getElementById('manualSimilarDetailModal').close()">&times;</button>
		</div>
		<div class="similar-detail-meta">
			<div class="meta-card"><strong>End date</strong><span id="manual_similar_detail_enddate"></span></div>
			<div class="meta-card"><strong>NAICS code</strong><span id="manual_similar_detail_naics"></span></div>
			<div class="meta-card"><strong>Entity</strong><span id="manual_similar_detail_entity"></span></div>
			<div class="meta-card"><strong>Contact email</strong><span id="manual_similar_detail_email"></span></div>
		</div>
		<div class="meta-card" style="margin-bottom:1rem;"><strong>URL</strong><a id="manual_similar_detail_url" href="#" target="_blank" rel="noopener" style="word-break:break-all;"></a></div>
		<div><strong style="display:block; margin-bottom:0.5rem;">Details</strong><div id="manual_similar_detail_description"></div></div>
		<footer style="display:flex; justify-content:flex-end; margin-top:1.25rem;">
			<button type="button" class="secondary" onclick="document.getElementById('manualSimilarDetailModal').close()">Close</button>
		</footer>
	</div>
</dialog>

<script>
(function () {
	const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content || '';
	const entitySearchUrl = @json(route('bids.reference.entities'));
	const stateSearchUrl = @json(route('bids.reference.states'));
	const similarUrl = @json(route('pending.similar'));
	const liveDetailUrlTpl = @json(route('bids.json', ['bid' => '__ID__']));
	const pendingDetailUrlTpl = @json(route('pending.json', ['pendingBid' => '__ID__']));

	const manualModal = document.getElementById('manualBidModal');
	const manualForm = document.getElementById('manualBidForm');
	let manualCancelUrl = '';
	let manualStarted = false;
	let similarReq = 0;
	let similarTimer = null;

	const entityPicker = initManualRefPicker({
		hidden: document.getElementById('manual_entity_id'),
		search: document.getElementById('manual_entity_search'),
		results: document.getElementById('manual_entity_results'),
		clearBtn: document.getElementById('manual_entity_clear'),
		hint: document.getElementById('manualEntityHint'),
		searchUrl: entitySearchUrl,
		fallbackPrefix: 'Entity #',
		onChange: scheduleManualSimilarRefresh,
	});

	initManualRefPicker({
		hidden: document.getElementById('manual_state_id'),
		search: document.getElementById('manual_state_search'),
		results: document.getElementById('manual_state_results'),
		clearBtn: document.getElementById('manual_state_clear'),
		hint: document.getElementById('manualStateHint'),
		searchUrl: stateSearchUrl,
		fallbackPrefix: 'State #',
	});

	document.getElementById('manual_email')?.addEventListener('input', scheduleManualSimilarRefresh);
	document.getElementById('manual_url')?.addEventListener('input', scheduleManualSimilarRefresh);

	document.getElementById('manualSimilarList')?.addEventListener('click', function (e) {
		const btn = e.target.closest('.similar-detail-link');
		if (!btn) return;
		e.preventDefault();
		openManualSimilarDetail(btn.dataset.source, btn.dataset.id);
	});

	manualModal?.addEventListener('close', function () {
		if (manualStarted && manualCancelUrl) {
			const startedAt = document.getElementById('manual_started_at')?.value || '';
			if (startedAt) {
				const fd = new FormData();
				fd.append('_token', csrfToken);
				fd.append('started_at', startedAt);
				fetch(manualCancelUrl, {
					method: 'POST',
					headers: {
						'Accept': 'application/json',
						'X-Requested-With': 'XMLHttpRequest',
					},
					body: fd,
				}).catch(function () {});
			}
		}
		manualStarted = false;
		manualCancelUrl = '';
	});

	window.openManualBidFromBtn = async function (btn) {
		const cfg = {
			startUrl: btn.getAttribute('data-manual-start-url') || '',
			storeUrl: btn.getAttribute('data-manual-store-url') || '',
			cancelUrl: btn.getAttribute('data-manual-cancel-url') || '',
			listingUrl: btn.getAttribute('data-manual-listing-url') || '',
		};
		if (!cfg.startUrl || !cfg.storeUrl) {
			alert('Manual add is not configured for this row.');
			return;
		}
		try {
			await openManualBid(cfg);
		} catch (e) {
			console.error(e);
			alert(e.message || 'Could not open manual add.');
		}
	};

	window.closeManualBidModal = function () {
		manualModal?.close();
	};

	window.prepareManualSubmit = function (approve) {
		document.getElementById('manual_approve').value = approve ? '1' : '0';
		manualStarted = false;
	};

	async function openManualBid(cfg) {
		if (!manualForm || !manualModal) return;
		manualForm.action = cfg.storeUrl || '';
		manualCancelUrl = cfg.cancelUrl || '';
		document.getElementById('manualBidSourceLabel').textContent = cfg.listingUrl || '';
		document.getElementById('manual_url').value = cfg.listingUrl || '';
		document.getElementById('manual_title').value = '';
		document.getElementById('manual_description').value = '';
		document.getElementById('manual_email').value = '';
		document.getElementById('manual_naics').value = '';
		document.getElementById('manual_enddate').value = '';
		document.getElementById('manual_userid').value = '';
		entityPicker.setFromId('');
		document.getElementById('manual_state_id').value = '';
		document.getElementById('manual_state_search').value = '';
		document.getElementById('manual_started_at').value = '';
		manualStarted = false;

		try {
			const fd = new FormData();
			fd.append('_token', csrfToken);
			const r = await fetch(cfg.startUrl, {
				method: 'POST',
				headers: {
					'Accept': 'application/json',
					'X-CSRF-TOKEN': csrfToken,
					'X-Requested-With': 'XMLHttpRequest',
				},
				body: fd,
			});
			if (!r.ok) {
				let msg = 'Could not start manual entry.';
				try {
					const err = await r.json();
					if (err.message) msg = err.message;
				} catch (ignore) {}
				throw new Error(msg);
			}
			const data = await r.json();
			document.getElementById('manual_started_at').value = data.started_at || '';
			manualStarted = true;
		} catch (e) {
			alert(e.message || 'Could not open manual add.');
			return;
		}

		manualModal.showModal();
		scheduleManualSimilarRefresh();
	}

	function initManualRefPicker(cfg) {
		let reqSeq = 0;
		let highlightIdx = -1;

		function refreshHint() {
			if (!cfg.hint) return;
			const id = (cfg.hidden?.value || '').trim();
			cfg.hint.textContent = id !== '' && id !== '0' ? '· #' + id : '· none';
		}

		function hideResults() {
			if (!cfg.results || !cfg.search) return;
			cfg.results.hidden = true;
			cfg.results.innerHTML = '';
			cfg.search.setAttribute('aria-expanded', 'false');
			highlightIdx = -1;
		}

		function applyChoice(id, label) {
			cfg.hidden.value = String(id);
			cfg.search.value = label;
			refreshHint();
			hideResults();
			cfg.onChange?.();
		}

		async function setFromId(raw) {
			hideResults();
			const idStr = raw != null && String(raw) !== '' && String(raw) !== '0' ? String(raw) : '';
			cfg.hidden.value = idStr;
			if (!idStr) {
				cfg.search.value = '';
				refreshHint();
				return;
			}
			refreshHint();
			try {
				const r = await fetch(cfg.searchUrl + '?id=' + encodeURIComponent(idStr), {
					headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': csrfToken },
				});
				const data = await r.json();
				cfg.search.value = data.resolved?.label || (cfg.fallbackPrefix + idStr);
			} catch (e) {
				cfg.search.value = cfg.fallbackPrefix + idStr;
			}
		}

		async function runSearch(query) {
			reqSeq += 1;
			const seq = reqSeq;
			try {
				const r = await fetch(cfg.searchUrl + '?q=' + encodeURIComponent(query) + '&limit=40', {
					headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': csrfToken },
				});
				const data = await r.json();
				if (seq !== reqSeq) return;
				const rows = Array.isArray(data.results) ? data.results : [];
				cfg.results.innerHTML = rows.map(function (row) {
					return '<li role="option" data-ref-id="' + escHtml(row.id) + '" data-ref-label="' + escHtml(row.label) + '">' + escHtml(row.label) + '</li>';
				}).join('');
				cfg.results.hidden = rows.length === 0;
				cfg.search.setAttribute('aria-expanded', rows.length ? 'true' : 'false');
			} catch (e) {
				hideResults();
			}
		}

		let debounce;
		cfg.search?.addEventListener('input', function () {
			clearTimeout(debounce);
			debounce = setTimeout(function () { runSearch(cfg.search.value.trim()); }, 250);
		});
		cfg.search?.addEventListener('focus', function () { runSearch(cfg.search.value.trim()); });
		cfg.results?.addEventListener('click', function (ev) {
			const li = ev.target.closest('li[data-ref-id]');
			if (!li) return;
			applyChoice(li.dataset.refId, li.dataset.refLabel || '');
		});
		cfg.clearBtn?.addEventListener('click', function () {
			cfg.hidden.value = '';
			cfg.search.value = '';
			refreshHint();
			hideResults();
			cfg.onChange?.();
		});

		return { setFromId: setFromId };
	}

	function escHtml(str) {
		const d = document.createElement('div');
		d.textContent = str == null ? '' : String(str);
		return d.innerHTML;
	}

	function scheduleManualSimilarRefresh() {
		clearTimeout(similarTimer);
		similarTimer = setTimeout(refreshManualSimilarPanel, 350);
	}

	async function refreshManualSimilarPanel() {
		const loading = document.getElementById('manualSimilarLoading');
		const list = document.getElementById('manualSimilarList');
		const empty = document.getElementById('manualSimilarEmpty');
		const label = document.getElementById('manualSimilarMatchLabel');
		const entityId = parseInt(document.getElementById('manual_entity_id')?.value || '0', 10) || 0;
		const email = (document.getElementById('manual_email')?.value || '').trim();
		const url = (document.getElementById('manual_url')?.value || '').trim();
		similarReq += 1;
		const seq = similarReq;
		loading.hidden = false;
		list.hidden = true;
		empty.hidden = true;
		const params = new URLSearchParams({ entity_id: String(entityId), email: email, url: url, exclude_temp_id: '0' });
		let data;
		try {
			const r = await fetch(similarUrl + '?' + params.toString(), {
				headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': csrfToken },
			});
			data = await r.json();
		} catch (e) {
			if (seq !== similarReq) return;
			loading.hidden = true;
			label.textContent = 'Could not load similar bids.';
			empty.hidden = false;
			return;
		}
		if (seq !== similarReq) return;
		loading.hidden = true;
		const entries = Array.isArray(data.entries) ? data.entries : [];
		label.textContent = data.match_label || 'No similar bids found';
		if (!entries.length) {
			list.hidden = true;
			empty.hidden = false;
			return;
		}
		list.innerHTML = '';
		entries.forEach(function (row) {
			const li = document.createElement('li');
			li.className = 'similar-item';
			const badge = row.source === 'live' ? 'live' : 'pending';
			const titleHtml = row.id
				? '<button type="button" class="similar-detail-link" data-source="' + escHtml(badge) + '" data-id="' + escHtml(String(row.id)) + '">' + escHtml(row.title || 'Untitled') + '</button>'
				: escHtml(row.title || 'Untitled');
			li.innerHTML = '<span class="similar-badge ' + badge + '">' + (badge === 'live' ? 'Live' : 'Pending') + '</span><p class="similar-item-title">' + titleHtml + '</p>';
			list.appendChild(li);
		});
		list.hidden = false;
		empty.hidden = true;
	}

	async function openManualSimilarDetail(source, id) {
		const tpl = source === 'live' ? liveDetailUrlTpl : pendingDetailUrlTpl;
		const url = tpl.replace('__ID__', encodeURIComponent(id));
		try {
			const r = await fetch(url, { headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': csrfToken } });
			const bid = await r.json();
			document.getElementById('manual_similar_detail_badge').textContent = source === 'live' ? 'Live' : 'Pending';
			document.getElementById('manual_similar_detail_title').textContent = bid.TITLE || 'Untitled bid';
			document.getElementById('manual_similar_detail_enddate').textContent = bid.ENDDATE || 'N/A';
			document.getElementById('manual_similar_detail_naics').textContent = bid.NAICSCODE || 'N/A';
			document.getElementById('manual_similar_detail_entity').textContent = bid.entity_label || 'N/A';
			document.getElementById('manual_similar_detail_email').textContent = bid.EMAIL || 'N/A';
			const urlEl = document.getElementById('manual_similar_detail_url');
			urlEl.href = bid.URL || '#';
			urlEl.textContent = bid.URL || 'N/A';
			document.getElementById('manual_similar_detail_description').textContent = bid.DESCRIPTION || 'No details available.';
			document.getElementById('manualSimilarDetailModal').showModal();
		} catch (e) {
			alert('Could not load bid details.');
		}
	}
})();
</script>
