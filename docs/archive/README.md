# docs/archive

Архив устаревшей документации. **На 2026-09-30 каталог пуст** — всё старое удалено.

Удалено без замены (история доступна в git):
- `docs/archive/openapi-rfc7807-superseded.yaml` — вытеснен `docs/openapi.yaml` (конверт ошибок `{"error":{…}}` по §66 ТЗ вместо RFC 7807).
- `docs/FORENSIC_AUDIT_SUMMARY.md` и весь `docs/audit/` (10 отчётов: IMPLEMENTATION_STATUS, MODULE_STATUS_REPORT, CRITICAL_FIXES_*, SERVICEPROVIDER_COMPLETION_REPORT, SECURITY_SEO_CODE_AUDIT, TICKETING_TRANSACTION_AUDIT, SHARED-HOSTING-READINESS, PRODUCTION_READINESS, FORENSIC_AUDIT_SUMMARY, IMPLEMENTATION_PLAN) — разовые срезы аудита сентября 2026 с противоречащими друг другу статусами; актуальное состояние реализации — только в `docs/ROADMAP.md`.

**Актуальные документы:** [`../ROADMAP.md`](../ROADMAP.md) (статусы этапов — источник истины),
[`../PLAN.md`](../PLAN.md), [`../ARCHITECTURE.md`](../ARCHITECTURE.md), [`../INTERNAL_MODULES.md`](../INTERNAL_MODULES.md),
[`../openapi.yaml`](../openapi.yaml) (контракт API; `nabilet_core_spec/` — поставляемая копия пакета).
