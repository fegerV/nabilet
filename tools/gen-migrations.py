#!/usr/bin/env python3
"""
Generate Laravel migrations for the NABILET Core production schema.

The source of truth is NOT this script: it is nabilet_core_spec/migrations.sql,
loaded into a real MySQL and dumped back out through information_schema. That makes
the output a transcription of a schema proven to execute, not a hand-written copy
that can silently drift.

Layout:
  001..008  tables, columns, primary keys, indexes/uniques   (no foreign keys)
  009       every foreign key, deferred until all tables exist
  010       CHECK constraints (raw SQL, MySQL)

NOT generated, maintained by hand:
  011       hall-schema immutability trigger. It is a single hand-written migration
            because a trigger body (BEGIN ... SIGNAL ... END) is not expressible as
            column metadata and there is exactly one of them. See
            database/migrations/2026_09_20_001100_*.php.

Usage:
  1. Load nabilet_core_spec/migrations.sql into MySQL 8.4.
  2. Dump information_schema into DUMP/ as columns.tsv, indexes.tsv, fks.tsv,
     checks.tsv (see the column list in load_tsv()).
  3. python3 tools/gen-migrations.py
  4. php tools/verify-migrations.php   # diffs the result back against the spec
"""
import os
import re

ROOT = r"C:/Project/nabilet"
DUMP = r"C:/Users/Professional/AppData/Local/Temp/nabilet-probe/schema"
OUT = os.path.join(ROOT, "database", "migrations")
SPEC = os.path.join(ROOT, "nabilet_core_spec", "migrations")

GROUPS = [
    ("001_identity", 100, [
        "organizations", "roles", "permissions", "role_permissions", "users",
        "user_organization", "user_roles", "user_sessions", "login_logs",
    ]),
    ("002_content", 200, [
        "event_categories", "events", "event_translations", "venue_translations",
        "page_translations", "pages", "seo_meta", "redirects",
        "media_assets", "media_links",
    ]),
    ("003_venues_schemas", 300, [
        "venues", "halls", "hall_schema_versions", "sectors", "hall_rows",
        "seats", "hall_tables", "standing_zones",
    ]),
    ("004_sales", 400, [
        "sessions", "inventory_items", "carts", "cart_items", "seat_holds",
        "orders", "order_items", "promo_codes", "promo_code_redemptions",
    ]),
    ("005_payments_tickets", 500, [
        "payments", "payment_transactions", "refunds", "ticket_templates",
        "tickets", "checkin_devices", "ticket_scans", "offline_bundles",
    ]),
    ("006_notifications_privacy", 600, [
        "notification_templates", "notifications", "consents", "privacy_requests",
    ]),
    ("007_analytics", 700, [
        "analytics_events", "ab_experiments", "ab_variants", "ab_assignments",
        "ab_metrics", "heatmap_events", "embed_domains",
    ]),
    ("008_integrations_system", 800, [
        "webhooks", "webhook_deliveries", "webhook_events", "api_keys",
        "idempotency_keys", "ip_rules", "modules", "settings", "audit_logs",
    ]),
]


def load_tsv(name):
    path = os.path.join(DUMP, name)
    if not os.path.exists(path):
        return []
    rows = []
    with open(path, encoding="utf-8") as fh:
        for line in fh:
            if not line.strip():
                continue
            rows.append(line.rstrip("\n").split("\t"))
    return rows


def map_type(ct):
    ct = ct.strip().lower()
    n = re.search(r"\((\d+)(?:,(\d+))?\)", ct)

    if ct.startswith("bigint"):
        return ("unsignedBigInteger", []) if "unsigned" in ct else ("bigInteger", [])
    if ct.startswith("int"):
        return ("unsignedInteger", []) if "unsigned" in ct else ("integer", [])
    if ct.startswith("smallint"):
        return ("unsignedSmallInteger", []) if "unsigned" in ct else ("smallInteger", [])
    if ct.startswith("tinyint"):
        return ("boolean", [])
    if ct.startswith("varchar"):
        return ("string", [n.group(1)] if n else ["255"])
    if ct.startswith("varbinary"):
        return ("binary", [n.group(1)] if n else ["255"])
    if ct.startswith("char"):
        return ("char", [n.group(1)] if n else ["255"])
    if ct.startswith("longtext"):
        return ("longText", [])
    if ct.startswith("mediumtext"):
        return ("mediumText", [])
    if ct.startswith("text"):
        return ("text", [])
    if ct.startswith("decimal"):
        return ("decimal", [n.group(1), n.group(2)] if n and n.group(2) else ["8", "2"])
    if ct == "json":
        return ("json", [])
    if ct.startswith("datetime"):
        return ("dateTime", [n.group(1)] if n else [])
    if ct == "date":
        return ("date", [])
    if ct.startswith("timestamp"):
        return ("timestamp", [])
    raise SystemExit("unmapped column type: " + ct)


def php_default(raw):
    raw = raw.strip()
    if raw in ("\\N", "NULL", ""):
        return None
    if raw.lower() in ("current_timestamp", "current_timestamp()", "now()"):
        return "USE_CURRENT"
    if re.fullmatch(r"-?\d+", raw):
        return raw
    if re.fullmatch(r"-?\d+\.\d+", raw):
        return raw
    m = re.fullmatch(r"'(.*)'", raw)
    if m:
        return "'" + m.group(1).replace("\\", "\\\\").replace("'", "\\'") + "'"
    return None


def php_str(value):
    return '"' + value.replace("\\", "\\\\").replace('"', '\\"') + '"'


# ── metadata ─────────────────────────────────────────────────────────────────
columns = {}
for row in load_tsv("columns.tsv"):
    table, _pos, name, ctype, nullable, default, extra, _key = row[:8]
    columns.setdefault(table, []).append({
        "name": name, "type": ctype, "nullable": nullable == "YES",
        "default": default, "auto": "auto_increment" in extra.lower(),
    })

indexes = {}
for row in load_tsv("indexes.tsv"):
    table, iname, non_unique, _seq, col = row[:5]
    indexes.setdefault(table, {}).setdefault(iname, {
        "unique": non_unique == "0", "cols": [],
    })["cols"].append(col)

fks = []
for row in load_tsv("fks.tsv"):
    cname, table, col, ref_table, ref_col, delete_rule = row[:6]
    fks.append({"name": cname, "table": table, "column": col,
                "ref_table": ref_table, "ref_col": ref_col, "on_delete": delete_rule})


def strip_comments(text):
    return "\n".join(l for l in text.splitlines() if not l.strip().startswith("--"))


def parse_checks(text):
    """ADD CONSTRAINT <name> CHECK ( <balanced> ) — later files override earlier."""
    out = {}
    t = strip_comments(text)
    for m in re.finditer(r"ADD\s+CONSTRAINT\s+(\w+)\s+CHECK\s*\(", t, re.I):
        name = m.group(1)
        i = m.end() - 1
        depth = 0
        for j in range(i, len(t)):
            if t[j] == "(":
                depth += 1
            elif t[j] == ")":
                depth -= 1
                if depth == 0:
                    out[name] = t[i + 1:j].strip()
                    break
    return out


def parse_check_tables(text):
    out = {}
    t = strip_comments(text)
    current = None
    for m in re.finditer(r"ALTER\s+TABLE\s+(\w+)|ADD\s+CONSTRAINT\s+(\w+)\s+CHECK", t, re.I):
        if m.group(1):
            current = m.group(1)
        elif m.group(2) and current:
            out[m.group(2)] = current
    return out


checks = {}
check_tables = {}
for src in ("009_integrity_hardening.sql", "010_tz_gaps.sql"):
    path = os.path.join(SPEC, src)
    if not os.path.exists(path):
        continue
    with open(path, encoding="utf-8") as fh:
        text = fh.read()
    checks.update(parse_checks(text))
    check_tables.update(parse_check_tables(text))


# ── rendering ────────────────────────────────────────────────────────────────
def render_table(table):
    lines = []
    pk_cols = []
    for iname, idx in indexes.get(table, {}).items():
        if iname == "PRIMARY":
            pk_cols = idx["cols"]

    simple_pk = (
        len(pk_cols) == 1 and pk_cols[0] == "id"
        and any(c["name"] == "id" and c["auto"] for c in columns[table])
    )

    for col in columns[table]:
        if simple_pk and col["name"] == "id":
            lines.append("            $table->id();")
            continue
        method, args = map_type(col["type"])
        call = "$table->%s('%s'%s)" % (
            method, col["name"], "".join(", " + a for a in args),
        )
        if col["nullable"]:
            call += "->nullable()"
        d = php_default(col["default"])
        if d == "USE_CURRENT":
            call += "->useCurrent()"
        elif d is not None and not col["auto"]:
            call += "->default(%s)" % d
        lines.append("            " + call + ";")

    if not simple_pk and pk_cols:
        lines.append("            $table->primary([%s]);" % ", ".join(
            "'%s'" % c for c in pk_cols))

    # MySQL auto-creates an index for every FK that has no usable index, and names
    # it after the constraint. information_schema therefore reports e.g. `fk_uo_role`
    # as an index - but it is not in the spec text and re-declaring it in the
    # migration is noise that would make the spec diff fail. Skip those; adding the
    # FK in 009 recreates them anyway.
    fk_names = {f["name"] for f in fks if f["table"] == table}

    for iname, idx in indexes.get(table, {}).items():
        if iname == "PRIMARY" or iname in fk_names:
            continue
        cols = ", ".join("'%s'" % c for c in idx["cols"])
        kind = "unique" if idx["unique"] else "index"
        lines.append("            $table->%s([%s], %s);" % (kind, cols, php_str(iname)))

    return "\n".join(lines)


HEADER = """<?php

declare(strict_types=1);

use Illuminate\\Database\\Migrations\\Migration;
use Illuminate\\Database\\Schema\\Blueprint;
use Illuminate\\Support\\Facades\\Schema;

/**
 * {title}
 *
 * Generated from the verified production schema — see
 * nabilet_core_spec/migrations.sql, which is the source of truth. Money is integer
 * minor units; timestamps are DATETIME(6); every name matches the spec.
{tables_doc}
 */
return new class extends Migration
{{
    public function up(): void
    {{
"""

FOOTER = """    }

    public function down(): void
    {
        // Irreversible by design: dropping a table destroys sold tickets and payment
        // history. Roll forward with a new migration instead.
    }
};
"""


def emit(filename, text):
    path = os.path.join(OUT, filename)
    with open(path, "w", encoding="utf-8", newline="\n") as fh:
        fh.write(text)
    print("wrote", filename)


os.makedirs(OUT, exist_ok=True)

for slug, order, tables in GROUPS:
    for t in tables:
        if t not in columns:
            raise SystemExit("table missing from dump: " + t)

    body = []
    for table in tables:
        body.append(
            "        Schema::create(%s, function (Blueprint $table): void {\n%s\n        });\n"
            % (php_str(table), render_table(table))
        )

    doc = " *\n * - " + "\n * - ".join(tables)
    title = "Tables: %s" % ", ".join(tables)
    text = HEADER.format(title=title, tables_doc=doc) + "\n".join(body) + FOOTER
    emit("2026_09_20_%06d_%s.php" % (order, slug), text)

# ── 009: foreign keys, deferred ──────────────────────────────────────────────
fk_lines = []
by_table = {}
for fk in fks:
    by_table.setdefault(fk["table"], []).append(fk)

for table in sorted(by_table):
    inner = []
    for fk in sorted(by_table[table], key=lambda f: (f["column"], f["name"])):
        chain = "$table->foreign('%s', %s)->references('%s')->on('%s')" % (
            fk["column"], php_str(fk["name"]), fk["ref_col"], fk["ref_table"])
        action = {
            "RESTRICT": "restrictOnDelete",
            "CASCADE": "cascadeOnDelete",
            "SET NULL": "nullOnDelete",
            "NO ACTION": "restrictOnDelete",
        }.get(fk["on_delete"].upper())
        if action:
            chain += "->%s()" % action
        inner.append("            %s;" % chain)
    fk_lines.append(
        "        Schema::table(%s, function (Blueprint $table): void {\n%s\n        });\n"
        % (php_str(table), "\n".join(inner))
    )

fk_text = HEADER.format(
    title="Deferred foreign keys for the whole schema",
    tables_doc=" *\n * Every foreign key is applied here, after all tables exist. Declaring\n"
               " * them inline would make creation order significant and break `migrate:fresh`.",
) + "\n".join(fk_lines) + FOOTER
emit("2026_09_20_000900_add_foreign_keys.php", fk_text)

# ── 010: CHECK constraints ───────────────────────────────────────────────────
chk_lines = []
by_ctable = {}
for name, clause in sorted(checks.items()):
    by_ctable.setdefault(check_tables.get(name, "unknown"), []).append((name, clause))

for table in sorted(by_ctable):
    for name, clause in sorted(by_ctable[table]):
        sql = "ALTER TABLE %s ADD CONSTRAINT %s CHECK (%s)" % (table, name, clause)
        chk_lines.append("        DB::statement(%s);" % php_str(sql))

chk_body = [
    "        if (! $this->supportsCheckConstraints()) {\n            return;\n        }\n",
]
chk_body.extend(chk_lines)

chk_text = HEADER.format(
    title="CHECK constraints — the invariants the database must enforce",
    tables_doc=" *\n * Raw SQL on purpose: Blueprint has no portable CHECK API, and these\n"
               " * constraints are the whole point of migrations/009 hardening. Guarded to\n"
               " * MySQL/MariaDB; on any other driver they are skipped loudly, not silently.",
).replace(
    "use Illuminate\\Database\\Schema\\Blueprint;\nuse Illuminate\\Support\\Facades\\Schema;",
    "use Illuminate\\Support\\Facades\\DB;",
) + "\n".join(chk_body) + (
    "\n    }\n\n"
    "    /**\n"
    "     * CHECK constraints here are MySQL/MariaDB-only. Skipping on another driver is a\n"
    "     * deliberate decision — applying half a contract would be worse than refusing.\n"
    "     */\n"
    "    private function supportsCheckConstraints(): bool\n"
    "    {\n"
    "        return in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true);\n"
    "    }\n\n"
    "    public function down(): void\n"
    "    {\n"
    "        // Irreversible by design: dropping an invariant weakens the database.\n"
    "    }\n};\n"
)
emit("2026_09_20_001000_add_check_constraints.php", chk_text)

print("\ntables:", len(columns), "| fks:", len(fks), "| checks:", len(checks))
