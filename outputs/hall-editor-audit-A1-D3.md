# NABILET hall-scheme editor audit — A1–D3

**Date:** 2026-10-06  
**Repository:** `C:/Project/nabilet`, HEAD `8cb2cfdd171c597bd3ed3a0ae400e62f6ad8f5f4`  
**Scope:** routed Vue/Konva hall editor (`resources/js/pages/hall-editor/HallEditorPage.vue`), schema API/version lifecycle, database guard, and static trace into inventory/storefront. **Audit only:** no project source was edited. Browser/API writes used isolated test records on `127.0.0.1:8001` (`nabilet_testing`); ordinary `nabilet` data was not written.

## Executive result

The editor can generate and autosave grid seats, row overrides, table-seat sectors, standing zones, and static objects. Several defects prevent calling the complete editing/publish lifecycle reliable:

1. **P1 — UI publish is wired to a nonexistent route:** the routed editor posts `/api/v1/schema-versions/{id}/publish` and gets 404. The registered hall-scoped API route succeeds when called directly.
2. **P1 — wrapped import reports success while clearing the layout:** `{ "schema": exportedSchema }` passes validation, but applying the wrapper yields zero sectors/seats. The unwrapped export restores the layout.
3. **P1 — seat placement can overlap an occupied seat:** the browser-created seat was saved at the same row/coordinates as an existing seat; the server accepted the payload.
4. **P1 — no archive API route:** a second draft cannot be published while another version is published (409), and the archive route probe returns 404. The service method exists but is not exposed in `routes/api.php`.
5. **P1 — editor-created standing zones convert to inventory at price 0** in the inspected case: the static object has capacity but no price field. Actual session inventory/purchase was not exercised.
6. **P1 — optimistic concurrency is not connected end-to-end:** the service accepts `expectedVersion`, but the controller does not pass it; the editor also does not send a base timestamp.
7. **P1 integrity risk — the MySQL trigger blocks direct geometry edits to published rows, but permits status downgrade and delete.** The bypass was demonstrated inside a rollback-only transaction; no lasting row change was made.
8. **P2 — inspector numeric edits are not consistently snapshotted.** Undo after changing a stage width removed the stage instead of reverting its width.
9. **P2 — Pan/Zoom controls are misleading/incomplete:** Pan is not wired to update `stage.draggable` after mount; the Zoom tool tooltip promises click/Alt-click, but click zoom is not implemented. Wheel and toolbar zoom are implemented.

Server-side protections that **did** hold: negative price → 422, empty-schema publish → 422, and publishing a second version while one is published → 409. These are not client-only guards.

## Evidence and reproduction index

- Latest corrected browser run: [`hall-audit-editor-api-evidence.json`](hall-audit-editor-api-evidence.json), hall `01M46ZB9JPD55M1P841WYZG39J`; no captured JS exceptions.
- Network trace: [`hall-audit-editor-network.json`](hall-audit-editor-network.json). The initial capture is retained as [`hall-audit-editor-network-initial.json`](hall-audit-editor-network-initial.json).
- SQL before/after publish snapshot, raw schema value, and direct API result: [`hall-audit-editor-sql-snapshots.json`](hall-audit-editor-sql-snapshots.json), isolated hall `01M46ZM6A8KQP3N4YYEDMTTHES`, version id `13`.
- Screenshot pairs and operation captures are listed inline below; all are in `outputs/`.
- Additional tested records: import/export `01M46YZCJJXS7DPAQ5777TYJFD`; trigger probe version id `10` in hall `01M46Z8V67B0PWTG2X53KV879S`.

Evidence tags used below: **[CODE]** static source; **[BROWSER]** browser/UI; **[NETWORK]** request/response; **[SQL]** database observation. “Not runtime-verified” is intentional—not a pass.

---

## A — Creation tools, geometry, prices, numbering

### A1 — Grid and arc generators

- **CLAIM:** The generator creates rectangle and amphitheatre/arc layouts with row, seat, VIP-row, and arc-angle controls.
- **REALITY:** Grid generation was exercised in the routed browser: 3 rows × 4 seats, with first row VIP and accessible seat kinds represented in the saved request. Arc geometry is implemented and has unit tests for ordinary spreads, symmetry, and row append. The 360° boundary was not browser-exercised; by the source formula, for `seatsPerRow > 1` the first/last angular values are `−π/+π`, so their centers coincide. The form permits 360° (`clampIntField(..., 10, 360)`).
- **EVIDENCE:** [CODE] `HallEditorPage.vue:192–223, 2341–2344`; `seatGeometry.ts:25–31, 45–61, 87–103`; `tests/Unit/HallEditor/seatGeometry.test.ts:68–95` (tests omit 360°). [BROWSER/NETWORK] 3×4 grid and VIP/accessibility kinds in `hall-audit-editor-api-evidence.json` / first grid-save POST.
- **STATUS / PRIORITY:** **PARTIAL / P2** — grid works; arc is code/unit-test supported, not browser-verified; full-circle collision is a deterministic edge case.
- **FIX:** Define and enforce an arc-angle ceiling below 360° or implement wrap-aware spacing; add a 360° geometry regression test and a browser check.
- **SCREENSHOTS:** [initial canvas](hall-audit-editor-before.png) → [generated grid](hall-audit-editor-grid-saved.png).

### A2 — Rows, seats, table sectors, numbering, prices

- **CLAIM:** Operators can append rows, place and number seats, make banquet tables with seats, and set row-specific prices.
- **REALITY:** A row-price override of ₽3,200 persisted as `320000` kopecks while the sector default remained `250000`. A table-tool click created a `shape: "table"` sector with eight actual seats (not a decorative static-table object); table seats are row 1, numbered 1–8. A later version-2 autosave contains a fifth row with five seats. The first recorded `+ Ряд` click result itself was ambiguous (`addRowClick:false` while the save counter advanced), so treat that individual click as inconclusive. The seat-placement browser run produced a new seat at `(x=22,y=0)` in row 1, where an existing seat already occupied `(22,0)`; the autosave payload retained both and the server returned 200. This contradicts the adjacent-cell protection intent in the source.
- **EVIDENCE:** [CODE] `HallEditorPage.vue:225–249, 256–283, 390–438, 446–454`; `seatGeometry.ts:111–149, 168–190`. [BROWSER] `hall-audit-editor-row-price.png`, `hall-audit-editor-table-added.png`, `hall-audit-editor-seat-added.png`, `hall-audit-editor-v2-draft.png`. [NETWORK] In `hall-audit-editor-network.json`, the draft payload has an existing row-1 seat at `x=22,y=0` and an additional seat at the same coordinates; draft update returned 200. `HallEditorPage.vue:415–426` shifts a click to the nearest seat’s next slot but does not search whether that slot is already occupied.
- **STATUS / PRIORITY:** **PARTIAL / P1** — table and price flow work; seat insertion can create overlapping sellable geometry; row-button evidence is not conclusive for the first attempt.
- **FIX:** Find a genuinely free grid position before insertion (or fail with a clear message), and enforce unique row/number and/or geometry constraints at the server boundary. Make row-add interaction coverage deterministic.

### A3 — Standing zones and static objects

- **CLAIM:** The editor creates and saves standing areas, stages, entrances, text labels, and decorative objects.
- **REALITY:** Browser evidence confirms a standing rectangle with capacity 106 and a stage object were created and included in draft saves. Standing capacity is calculated as `floor(area / 150)` with minimum 10. The editor-created standing object has no explicit price in its model/payload. Static conversion of this tested object produced `price_amount: 0`; there is no evidence here that a customer can buy it. Stage width was editable in the inspector, but its undo granularity is defective (B3). Text, entrance, and static decorative table are present in code but were not each browser-tested. A static decorative table is distinct from the table tool’s real eight-seat sector.
- **EVIDENCE:** [CODE] `HallEditorPage.vue:538–559, 703–717`; `HallSchemaVersion.php:250–256` uses a zero fallback for standalone standing-object price. [BROWSER] `hall-audit-editor-standing-added.png`, `hall-audit-editor-stage-added.png`; converted audit schema showed capacity 106 and price 0 (static conversion probe, not session inventory).
- **STATUS / PRIORITY:** **PARTIAL / P1** for standing-zone price; other static-object tools are not all runtime-verified.
- **FIX:** Add an explicit standing price field, validate and save it, and verify conversion to inventory. Keep the two “table” concepts clearly labeled in the UI.

---

## B — Editing, navigation, undo/redo, shortcuts

### B1 — Selection and editing

- **CLAIM:** The editor supports selection, multi-selection, seat dragging, row re-numbering, and inspector edits.
- **REALITY:** The browser run exercised creation and a stage inspector edit, but did not exhaustively verify every selection/drag path. Static code contains marquee selection, seat drag finalization, collision spreading, and re-numbering. The single-seat add path has the collision defect in A2. Static/background numeric setters mutate values without their own `snapshot()` call, so edits may be merged into the prior history action.
- **EVIDENCE:** [CODE] `HallEditorPage.vue:295–307, 446–535, 608–701, 2346–2370`; browser stage-width evidence in `hall-audit-editor-stage-width.png` and `hall-audit-editor-stage-width-undo.png`.
- **STATUS / PRIORITY:** **PARTIAL / P2** — substantial editing paths exist; not all are runtime-verified; per-property history is inconsistent.
- **FIX:** Snapshot before every user-visible mutation (including static/background numeric edits and clearing a row override); test single/multi-selection and cross-row drags against persisted payloads.

### B2 — Pan and Zoom tools

- **CLAIM:** Pan tool drags the viewport; Zoom tool click zooms in and Alt-click zooms out.
- **REALITY:** The Konva stage is created with `draggable: tool.value === 'pan'` only at mount (`tool` initially is not Pan); no subsequent stage-draggable update is wired. The editor has a wheel handler and toolbar `zoomBy()` buttons, but no click/Alt-click zoom branch in `handleToolClick`. Browser screenshot pairs did not provide a numerical Konva transform measurement; the probe compared the canvas host rectangle, which is not a valid proxy for the internal stage transform. Therefore the source demonstrates the wiring gap; the screenshot probe is only suggestive, not a conclusive transform measurement.
- **EVIDENCE:** [CODE] `HallEditorPage.vue:2064–2069, 2132–2137, 2159–2167, 567–583`; toolbar `zoomBy` controls are in the template. [BROWSER] `hall-audit-editor-pan-before.png` / `hall-audit-editor-pan-after.png`; `hall-audit-editor-zoom-before.png` / `hall-audit-editor-zoom-after.png`; `hall-audit-editor-zoom-button.png`.
- **STATUS / PRIORITY:** **PARTIAL / P2** — wheel and toolbar zoom are present; advertised Pan and click-to-zoom are not wired as described.
- **FIX:** Watch tool changes and call `stage.draggable(tool === 'pan')`; implement Zoom click/Alt-click or correct the tooltip. Verify using `stage.position()` and `stage.scale()` values before/after gestures.

### B3 — Undo/redo and keyboard shortcuts

- **CLAIM:** Undo/redo buttons and `Ctrl/Cmd+Z`, `Ctrl/Cmd+Shift+Z`, `Delete`/`Backspace` cover editing history and deletion.
- **REALITY:** The history composable correctly stores snapshots when callers invoke it, but the stage-width setter does not snapshot. After width changed from 260 to 400, clicking Undo removed the entire stage—the prior “add stage” snapshot was consumed. Keyboard handlers are present in source, but actual keydown shortcuts were not separately exercised in the browser. Handler ignores INPUT/TEXTAREA only; other focusable controls may still receive global shortcuts.
- **EVIDENCE:** [CODE] `useEditorHistory.ts:11–67`; `HallEditorPage.vue:182–188, 2201–2216, 2346–2370`. [BROWSER] `hall-audit-editor-stage-width.png` and `hall-audit-editor-stage-width-undo.png`; result object `static-property-history` records stage absent after Undo. Button-level row-price undo/redo clicks were present in the run; shortcut dispatch itself was not tested.
- **STATUS / PRIORITY:** **PARTIAL / P2** — history buttons exist, but property-level undo is wrong for the tested stage edit; shortcut runtime remains unverified.
- **FIX:** Take a pre-mutation snapshot in every inspector setter. Add browser tests for Ctrl/Cmd+Z, redo, Delete and focus exclusions; record before/after schema states.

---

## C — Autosave, background, import/export, publish/versioning

### C1 — Autosave and background persistence

- **CLAIM:** Edits and backgrounds autosave to the draft; uploaded backgrounds survive reload.
- **REALITY:** Autosave uses a 500 ms debounce and the browser run captured repeated POSTs to the draft route (initial 201, updates 200). The payload builder includes `background: backgrounds[0]`, and uploaded image sources are converted to Data URLs. This conflicts with the service comment saying Data URLs do not enter the payload; the server caps serialized payload size at 2,000,000 bytes, so a sufficiently large background can make autosave fail. Only the first background is serialized. Background upload, reload persistence, and large-image behavior were not runtime-tested. Publish does not flush/wait for a pending timer when `currentVersionId` already exists.
- **EVIDENCE:** [CODE] `HallEditorPage.vue:838–941, 1580–1609`; `HallService.php:211–225, 358–375`. [NETWORK] `hall-audit-editor-network.json` shows repeated draft saves and schema payloads; tested run used no background (`background: null`).
- **STATUS / PRIORITY:** **PARTIAL / P1** for the publish-vs-pending-save race; **P2** for background payload/size and missing runtime coverage.
- **FIX:** Flush and await autosave before publishing; define a server-compatible background-storage contract (upload/reference rather than inline Data URL), enforce image size/type server-side, and preserve all intended backgrounds.

### C2 — JSON export/import

- **CLAIM:** Exported hall JSON can be re-imported; wrapper imports are accepted; invalid files produce specific errors.
- **REALITY:** Export produces a top-level object with `version`, `canvas`, `background`, `sectors`, `staticObjects`. Unwrapped export round-tripped correctly. `{ "schema": exported }` is explicitly accepted by validation, but the handler calls `applyServerSchema(data)` rather than applying the unwrapped `root`. Runtime result: success toast, zero sectors/seats; unwrapped import restored the test layout. This is destructive to the currently displayed in-memory layout and can autosave an empty schema over the existing draft. Invalid JSON/schema errors are code-backed but not all negative cases were browser-tested.
- **EVIDENCE:** [CODE] `HallEditorPage.vue:1412–1451, 1462–1531, 1539–1577`; in particular wrapper detection at 1467–1471 and apply call at 1563. [BROWSER] `hall-audit-editor-import-wrapper.png` (0 sectors) and `hall-audit-editor-import-restored.png` (2 sectors, 21 seats); `wrapped-import-result` in API evidence.
- **STATUS / PRIORITY:** **PARTIAL / P1** — native export works; accepted wrapper silently empties the layout while reporting success.
- **FIX:** Pass the validated unwrapped root into `applyServerSchema`; apply import transactionally only after normalization succeeds; show resulting sector/seat count and preserve the prior canvas on any failure.

### C3 — Publish, new version, and archive lifecycle

- **CLAIM:** The editor publishes a saved draft, locks published geometry, and lets an operator create/publish a replacement version.
- **REALITY:** UI publish posts `/api/v1/schema-versions/11/publish` and returns 404. Registered route is `/api/v1/halls/{publicId}/schema-versions/{versionId}/publish`; direct call succeeded with 200. Reload displayed the published lock. `newVersion()` locally clears the lock and creates a new draft only when the next autosave is triggered; server rules still prevent replacing an already-published version. Publishing v2 while v1 was published returned 409. `HallService::archiveSchemaVersion()` exists, but no archive route is registered; the archive request returned 404. This blocks the normal replacement-version lifecycle.
- **EVIDENCE:** [CODE] `HallEditorPage.vue:943–989`; `routes/api.php:38–42`; `HallController.php:146–182`; `HallService.php:294–355`. [NETWORK] UI publish 404 is in `hall-audit-editor-network.json`; direct publish 200, second publish 409, archive 404 are in `hall-audit-editor-api-evidence.json`. [BROWSER] `hall-audit-editor-ui-publish.png`, `hall-audit-editor-published.png`, `hall-audit-editor-new-version.png`, `hall-audit-editor-v2-draft.png`.
- **STATUS / PRIORITY:** **NOT FUNCTIONAL END-TO-END / P1** — UI publish path broken; archive operation absent; a replacement cannot be published via the observed lifecycle.
- **FIX:** Align the editor URL with the registered hall-scoped route; expose an authenticated, hall-scoped archive route with policy checks; add an end-to-end test that archives v1, publishes v2, reloads, and verifies public schema selection.

---

## D — Backend validation, database integrity, inventory/storefront

### D1 — Server validation and concurrent editing

- **CLAIM:** The server validates schema payloads and prevents invalid prices, empty publishes, multiple published versions, cross-hall version use, and stale concurrent writes.
- **REALITY:** Negative sector price was rejected with HTTP 422 and field details; empty-schema publish was rejected with 422 `SCHEMA_EMPTY_PAYLOAD`; publishing v2 alongside published v1 returned 409 `SCHEMA_VERSION_ALREADY_PUBLISHED`. The hall-scoped publish controller/service check is present. But the server accepted the duplicate seat geometry from A2 (draft update 200). Optimistic conflict detection is effectively disconnected: `HallService::createSchemaDraft()` accepts `expectedVersion`, but `HallController::createSchemaDraft()` passes only hall, payload, user, and optional `version_id`; the browser request also omits `base_updated_at`. No concurrent-editor race was executed.
- **EVIDENCE:** [CODE] `HallController.php:146–165`; `HallService.php:211–262, 304–343`; `routes/api.php:39–41`. [API] `server_invalid_price` 422 and `server_empty_publish` 422 in API evidence; `publish_v2_while_v1_published` 409. [NETWORK] duplicate-position draft update returned 200.
- **STATUS / PRIORITY:** **PARTIAL / P1** for geometric integrity and lost-update risk; other listed lifecycle/price validations passed.
- **FIX:** Pass a server-verified base revision through the controller/client contract; return 409 on stale writes; validate seat-number/coordinate collisions at server boundary so non-UI clients cannot persist broken geometry.

### D2 — SQL snapshots and immutability trigger

- **CLAIM:** Once published, a hall schema version is immutable at the database layer.
- **REALITY:** Direct update of `schema_json` on a published version was rejected with SQLSTATE `45000`. The trigger is `BEFORE UPDATE` and compares schema, version, hall, dimensions, and background while `OLD.status` is published/archived. It deliberately does not block status changes and there is no delete trigger. A rollback-only probe downgraded status, edited/deleted the row, then rolled back; the bypass was possible inside the transaction, but no persistent test record was altered by that probe.

  Fresh before/after SQL probe: isolated test hall `01M46ZM6A8KQP3N4YYEDMTTHES` / hall id 12, version id 13. Before: version 1, `draft`, `schema_json` 335 bytes, `published_at=NULL`. The registered publish endpoint returned 200. After: same version/schema and 335 bytes, `published`, `published_at=2026-10-06 00:31:13`. The JSON value is preserved in the linked SQL snapshot file.
- **EVIDENCE:** [CODE] `2026_09_20_001100_add_schema_version_immutability_trigger.php:65–119`, especially trigger predicate 90–105. [SQL] [`hall-audit-editor-sql-snapshots.json`](hall-audit-editor-sql-snapshots.json); `SHOW TRIGGERS LIKE 'hall_schema_versions'` showed `trg_schema_version_immutable`. Prior direct-edit/bypass probe is summarized above and was rolled back.
- **STATUS / PRIORITY:** **RISK / P1** — direct geometry writes are blocked, but the database-level immutability claim is bypassable by changing status first or deleting.
- **FIX:** Make published/archived status transitions monotonic at the DB boundary and add a delete guard (or equivalent restricted DB privileges); retest bypass attempts without committing any changes.

### D3 — Published schema → inventory → storefront

- **CLAIM:** A published editor schema feeds session inventory and the customer seat map.
- **REALITY:** The static call path is connected: session creation chooses/loads a schema version and invokes `InventoryService::generateFromSchema`; `HallSchemaVersion::toInventoryFormat()` converts editor seats/rows, maps `accessible` to `wheelchair`, and maps standalone standing objects into standing inventory data. Conversion of the browser-published test schema produced Grid Audit row counts `[5,4,4]` with row 1 at `320000` and other rows at `250000`; the table sector produced eight seats. The standing object mapped capacity 106 at price 0. `SeatMap.vue` renders button-based seats and selected/sold/held/unavailable/accessibility states. **No session/inventory generation or customer cart/purchase against these newly audited halls was run**, so persistence, purchasability, seat selection, cart totals, and checkout are not claimed as runtime-proven.
- **EVIDENCE:** [CODE] `SessionController.php:101–107` (generation call at 104); `HallSchemaVersion.php:77–80, 152–155, 188–200, 250–256`; `InventoryService.php` `generateFromSchema()`; `SeatMap.vue:7, 141–175, 235–315`. Static conversion probe results are in the carried-forward audit evidence summary; they are not a purchase-flow trace.
- **STATUS / PRIORITY:** **PARTIAL / P1** for zero-priced standing inventory; broader end-to-end linkage remains **UNVERIFIED**.
- **FIX:** Give standing zones a price in the editor/schema contract and add an isolated integration test: publish → create session → inspect generated rows/items → select seat/standing capacity → cart totals. Do not infer purchase success from static conversion alone.

---

## Claimed vs. working tools and shortcuts

| Advertised capability | Observed status | Evidence boundary |
|---|---|---|
| Grid generator | **Works in browser** (3×4; VIP/accessibility kinds saved) | Screenshot + draft Network payload |
| Arc generator | **Implemented; ordinary geometry has unit tests** | No browser run; 360° endpoints coincide by formula |
| Add row | **Partial**; row-4/five-seat data appears in v2 autosave, but the first `+ Ряд` click result was ambiguous | Do not count the first click as a clean pass |
| Add seat | **Creates and saves**, but can overlap an occupied point | Browser screenshot + duplicate coordinates in saved payload |
| Table tool | **Works** as a seat-bearing `shape: table` sector with 8 seats | Browser + API payload; not a decorative static object |
| Standing tool | **Creates capacity 106**; converted price was 0 | Browser + static conversion; no sale test |
| Stage/static objects | **Stage creation and width edit work**; undo granularity fails | Browser before/after screenshots |
| Pan | **Not correctly wired by inspected source**; runtime transform not numerically measured | `stage.draggable` set only at mount |
| Zoom tool click / Alt-click | **Not implemented in click dispatcher** | Wheel listener and toolbar zoom exist; transform not measured numerically |
| Wheel / toolbar zoom | **Implemented in source**; toolbar clicked, but no reliable scale value captured | Not certified numerically in this run |
| Undo / redo buttons | **Present and clickable**; undo after stage width edit removes the stage | Browser property-history result |
| Ctrl/Cmd+Z, Ctrl/Cmd+Shift+Z, Delete/Backspace | **Handlers exist in source; key events not runtime-tested** | Do not mark as browser-verified |
| JSON export | **Works**; top-level schema object | Blob captured in browser |
| JSON import, unwrapped export | **Works in tested round-trip** | Restored 2 sectors / 21 seats |
| JSON import `{schema: ...}` | **Broken**; success toast, zero sectors/seats | Browser screenshot + result JSON |
| Background image | **Code exists; not runtime-tested**; Data URL is in payload | Payload cap risk; only first background serialized |
| UI publish | **Broken (404)** | Network trace |
| Direct hall-scoped publish | **Works (200)** | API test record |
| New version | **Local unlock; server draft after a subsequent save** | Browser + v2 draft response |
| Archive | **Not reachable (404)** despite service method | Route listing + API probe |

## Client checks vs server-side enforcement

| Condition | Client behavior | Server result | Conclusion |
|---|---|---|---|
| Empty layout publish | UI blocks when there are no sectors | 422 `SCHEMA_EMPTY_PAYLOAD` | Server protection exists; not a client-only check |
| Negative price | UI validates numeric inputs | 422 with field-specific details from direct API probe | Server protection exists |
| Another version already published | UI can show local new-version controls | 409 `SCHEMA_VERSION_ALREADY_PUBLISHED` | Server protects lifecycle; archive route is the missing step |
| Duplicate seat geometry | UI placement can select an occupied neighbor slot | Draft update accepted (200) | **No equivalent server collision check demonstrated** |
| Stale concurrent save | UI/server contract does not send/pass base revision | Service check exists but controller omits argument | **Optimistic locking is not effective end-to-end** |
| Published schema mutation | UI locks after reload | Trigger blocks direct schema update, but downgrade/delete bypass it | DB guard is incomplete, despite the UI lock |

## Priority queue

- **P1 — Fix before relying on editor operations:** publish URL; import wrapper applying the wrong object; seat-placement collision; archive route/version lifecycle; standing-zone price; end-to-end optimistic concurrency; DB downgrade/delete bypass.
- **P2 — Correctness and confidence:** snapshot inspector/background numeric mutations; Pan tool drag wiring; Zoom click/tooltip mismatch; 360° arc overlap; background Data URL size/storage and only-first-background serialization; runtime tests for keyboard shortcuts and every static tool.
- **Unverified integration:** actual session inventory generation and customer storefront/cart/checkout using a newly published hall. Keep this explicitly open rather than asserting success.

## Reproducibility and workspace state

| Probe | Result |
|---|---|
| Corrected browser/CDP run on isolated hall `01M46ZB9JPD55M1P841WYZG39J` | Completed; no captured JS exceptions; evidence JSON and screenshots retained |
| Import/export on isolated hall `01M46YZCJJXS7DPAQ5777TYJFD` | Unwrapped round-trip preserved layout; accepted wrapper yielded 0 sectors/seats |
| UI publish vs registered endpoint | UI route 404; hall-scoped direct route 200 |
| Invalid negative price | 422 with field-specific error |
| Empty schema publish | 422 `SCHEMA_EMPTY_PAYLOAD` |
| Publish v2 while v1 published | 409 `SCHEMA_VERSION_ALREADY_PUBLISHED` |
| Archive endpoint | 404; no registered route |
| Fresh SQL before/after publish, version id 13 | draft → published; schema remained 335 bytes and unchanged |
| Direct published-schema SQL mutation | blocked with SQLSTATE `45000` |
| Status-downgrade/delete bypass | possible inside rollback-only transaction; rollback preserved the row |
| Customer session/inventory/cart/purchase | Not run; do not infer from static mapping |

Git HEAD remained `8cb2cfdd171c597bd3ed3a0ae400e62f6ad8f5f4`. No tracked project source files were changed. Existing modified artifacts remain `node_modules/.package-lock.json`, Vitest results JSON, `node_modules/.vue-global-types/vue_3.5_0_0_0.d.ts`, and `vendor/autoload.php`; they were not reverted. Audit screenshots/JSON plus this report and SQL snapshot are untracked outputs. The isolated server at port 8001 was still responding at the time of this report. Temporary audit scripts/profiles in Windows Temp were not deleted during this pass.

---

## Remediation addendum — 2026-10-06

This addendum records the subsequent code changes and verification. It does not erase the original browser/SQL observations above: browser-level checks were not rerun after the latest frontend changes.

| Finding | Current assessment | Evidence boundary / remaining work |
|---|---|---|
| A1 — 360° arc endpoint collision | **FIXED IN CODE; unit-tested** — arc spread is capped at 180°. | Geometry unit suite passed; no post-fix browser run. |
| A2 — overlapping seat placement / duplicate seats | **FIXED IN CODE; covered by tests** — placement searches for a free position; server rejects duplicate IDs/numbers and overlapping seats. | Feature and geometry tests passed; no post-fix browser save/reload. |
| A3 — standing-zone price becomes zero | **FIXED IN CODE; regression cases passed** — editor price field and server validation require positive minor-unit price and strict integer capacity; conversion now prefers the standing object’s own price even when wrapped by an empty sector. | Feature tests cover direct and wrapped conversions plus invalid capacity; real session generation, selection, cart, and purchase remain unverified. |
| B1/B3 — inspector mutations and undo snapshots | **FIXED IN CODE** — relevant edits snapshot editor state. | No browser re-run of undo/redo or keyboard shortcuts after the changes. |
| B2 — Pan/Zoom controls | **FIXED IN CODE** — interaction wiring was changed. | No post-fix browser measurement of Konva stage position/scale; do not count as runtime-verified. |
| C1 — pending autosave vs publish race | **FIXED IN CODE** — publish flushes/awaits autosave and handles failed saves. | No browser re-run of delayed-save/publish race. |
| C2 — wrapped import clears layout | **FIXED IN CODE; unit-tested** — wrapper is unwrapped, validated, and state is restored on failure. | Targeted frontend suite passed; no post-fix browser rollback/round-trip. |
| C3 — publish URL and archive route | **IMPLEMENTED; backend-tested / route-registered** — hall-scoped route/controller/service wiring and replacement publication are present. | Lifecycle tests passed and route listing showed four schema-version routes; no authenticated HTTP request or post-fix browser flow. |
| D1 — stale draft saves overwrite each other | **IMPLEMENTED; sequential stale-revision case tested** — revision is wired through the editor, controller, service, and response; the QA autosave script now sends and advances `base_revision`. | Competing simultaneous clients were not tested; do not claim race-level proof. |
| D2 — frozen schema lifecycle can be bypassed in SQL | **GUARDS IMPLEMENTED; KEY MYSQL PATHS TESTED** — migration adds update-transition, version-delete, and parent hall-delete guards; it now installs the replacement update trigger before removing the legacy guard. | The disposable-DB Feature suite exercises revision jumps, invalid draft/published/archived transitions, frozen-geometry edits, allowed publication/archive paths, version deletion, and hall cascade deletion. Production privileges/compatibility and simultaneous deployment writes remain unverified. |
| D3 — published schema to sale flow | **PARTIAL / UNVERIFIED END-TO-END** — schema conversion is covered at service level. | No isolated publish → session → inventory → seat/standing selection → cart/checkout run was completed. |

### Verification actually completed

- Laravel Feature suite on the new disposable `nabilet_test_suite` database: **62 tests passed, 231 assertions** (including the expanded lifecycle/trigger tests). `phpunit.xml` now forces this dedicated DB target so inherited environment variables cannot redirect `migrate:fresh` to application or audit data.
- Targeted hall-editor Vitest suite: **14 tests passed** in the earlier run; the latest full frontend suite: **55 passed**.
- `vue-tsc --noEmit`, Vite production build, PHP syntax checks, Python QA-script compile, `git diff --check`, and hall schema route listing passed. A no-DB runtime probe also confirmed the wrapped standing-zone price and strict capacity behavior.
- `tests/run.php`: **636 methods passed, 0 failed, 1,317 assertions**, but emitted an unresolved warning in `tests/Unit/AuthWiringTest.php:134` (`$app` undefined / `refresh` on null). This is not established as a production defect.
- Frontend Vitest reports all tests passed, with jsdom warnings for unimplemented `window.alert()` in an expected save-error test. Browser E2E after the latest UI changes and the customer purchase flow remain unverified.

### Test-database reset incident and recovery status

The schema-version table in `nabilet_testing` had **11 rows before the full Laravel suite and 0 afterward**. The rows' count/hash were unchanged immediately after applying the lifecycle migration and before that suite. The ordinary `nabilet` database still had **9 schema-version rows**, no `revision` column, and was not migrated or written during this remediation.

The reset mechanism is confirmed in project configuration: `phpunit.xml` points Feature tests to MySQL database `nabilet_testing` and explicitly notes that `RefreshDatabase` drops and rebuilds it; seven Feature test classes use that trait. Laravel's trait invokes `migrate:fresh`, which drops all tables before rerunning migrations. Retained MySQL binary-log entries also contain repeated `DROP TABLE` statements under `use nabilet_testing`, including the hall schema table. This explains the observed loss mechanism and makes the test reset the strong causal explanation; the retained event listing does not timestamp one individual reset well enough to attribute a unique event to the exact run.

No restore or re-seed of the lost audit rows was attempted. Binary logging is enabled with a 30-day configured retention and log files are present, but no point-in-time restore was reconstructed or validated; no backup/export was found in the repository, and the MySQL container did not have `mysqlbinlog` available. Therefore **no safe recovery source has yet been confirmed**. After verifying the schema name did not exist, a separate `nabilet_test_suite` database was created for test execution; the full Feature suite ran only there. `phpunit.xml` now forces that disposable name. Do not rerun the Feature suite against `nabilet_testing`, and do not write to `nabilet` as part of this remediation.

### Push review — migration/spec gate and CI verifiers

The migration verifier initially terminated because the lifecycle migration used `DB::table()`, unsupported by the dependency-free test facade. It now uses `DB::selectOne(... DATABASE() ...)`; the stub models `information_schema` lookups and `Schema::table()` additions/drops. The verifier now strictly compares the Core schema while checking named app extensions against an exact, documented ratchet list. It explicitly checks the revision’s nullability/default and lifecycle trigger replacement order.

The verifier now passes **16 checks, 0 failures** across all 25 migration files. It confirms the eight app-only tables, selected extra columns and indexes, and the carts generated active-only uniqueness as named extensions; any unlisted drift fails. This is not a broad “ignore extras” waiver.

The broader CI-gate pass initially exposed `HallSchemaVersion.revision` as absent from the Core schema. The intended ownership is now explicit: `revision` is an application-owned optimistic-lock extension, declared by migration `2026_10_06_000100_harden_hall_schema_lifecycle.php`, not part of the standalone Core schema. `verify-models-schema.php` now accepts only this exact extension after checking that its migration still declares a nullable, no-default `unsignedBigInteger` in the correct table; the migration verifier separately checks the same nullable/no-default invariant. This is a specific, migration-backed contract, not a generic allowlist.

A second genuine bundle inconsistency was found and fixed: the canonical consolidated `nabilet_core_spec/migrations.sql` contained a 34-line checkout section (011), but neither top-level `migrations.sql` nor the split set had it. The split set now has `migrations/011_checkout_fixes.sql`, the same section is in top-level `migrations.sql`, and the CI schema count is updated from 678 to 684. The consolidated top-level and Core SQL bundles now match byte-for-byte. Both top-level and split bundles were applied to fresh disposable MySQL 8.4.11 databases: each produced **64 tables, 684 columns, and 1 trigger**, and their column definitions compare equal.

Checks completed during this continuation: isolated Feature suite **62 passed / 231 assertions**; Vitest **55 passed**; Vue typecheck and production build passed; migration verifier **16/16**; model/schema verifier now passes with 69 pre-existing inventoried model-field differences; purity, module graph, state-machine, both OpenAPI, and both contract-schema checks passed. Kernel suite **636 passed / 1,317 assertions**, with the pre-existing `$app` warning in `AuthWiringTest.php:134`. Whole-tree PHP lint subsequently completed: **518 files, no syntax errors**. The revised CI column expectation and the SQL sync need a final full set of verifications before deciding whether to commit/push; no commit or push has occurred.
