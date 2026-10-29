# Product Matching — PHP Prototype

A precision-first product matcher for Lengow's take-home exercise. It compares a private catalogue product with products from selected public stores and returns either:

- **Connection:** a match with enough consistent evidence for automatic linking.
- **Suggestion:** a plausible match that still needs review.

The goal is to avoid false positives, particularly when identifiers are inferred or different product variants share the same EAN.

## Quick start

Requires **PHP 8.1+**. No framework, database or Composer installation is needed.

```bash
php tests/run.php
php tests/csv-run.php
php bin/demo.php
```

The demo uses synthetic products and shows a confirmed connection, uncertain suggestions, and rejected variants.

## Try the supplied Lengow dataset

You can read Lengow's original ZIP directly, without extracting its CSV files:

```bash
php bin/match-csv.php \
  --zip=/path/to/use-case-data.zip \
  --stores=all \
  --limit=10 \
  --scan-limit=20000 \
  --report=/tmp/lengow-preview.json
```

`--stores=all` is **for exploration only**: the supplied data does not identify which public stores each customer follows. For an actual customer-scoped run, replace `all` with the known eligible store IDs (for example, `--stores=ID1,ID2`).

The command also accepts extracted files with `--private=/path/private.csv` and `--public=/path/public.csv` instead of `--zip`. Reading a ZIP requires PHP's `ZipArchive` extension or the system `unzip` utility.

**Important options:**

| Option | Purpose |
|---|---|
| `--limit=10` | Select up to 10 active private products (maximum 100) |
| `--private-code=CODE` | Select a specific private product |
| `--scan-limit=20000` | Read up to 20,000 public rows; `0` reads the entire supplied public CSV |
| `--suggestions=5` | Maximum suggestions per private product |
| `--title-candidates=25` | Maximum retained title candidates per private product |
| `--max-ean-candidates=4000` | Fail safely if the EAN candidate cap is reached |
| `--report=FILE` | Write results to a JSON file instead of stdout |

Run `php bin/match-csv.php --help` for the full usage guide.

**A partial scan is not a complete match search.** It may find no candidates simply because matching rows appear later in the file. To avoid overconfident results, provisional connections from partial scans are downgraded to suggestions. Setting `--scan-limit=0` reads the full *supplied* public CSV, but still matches only the selected private products—not the entire catalogue.

For a small JSON fixture, you can also run:

```bash
php bin/match-json.php fixtures/synthetic-products.json 5
```

## How it works

1. **Candidate retrieval:** Stream public CSV rows and retain bounded EAN and brand/title candidates, or use in-memory indexes for JSON input. Restrict to the specified public stores.
2. **Evidence checks:** Validate EAN checksums, retain identifier provenance, and compare brand, model, observed size, colour, capacity, and pack quantity where recognizable.
3. **Decision:** Reject known contradictions. Auto-connect only with corroborating evidence; otherwise suggest or abstain.
4. **Results:** Return source-scoped identities, matching reasons, a ranking score, and a capped list of reviewable suggestions.

A product is identified by `(StoreID, Code, SubCode)`; a match connects two such identities. The original source records are never merged.

The `rank_score` is **only a sorting heuristic**, not a probability of correctness.

## Project structure

| Location | Responsibility |
|---|---|
| `src/Matcher.php` | Pair evaluation and evidence/variant validation |
| `src/MatchingWorkflow.php` | In-memory candidate indexes, store filtering, suggestion ranking |
| `src/CsvInput.php`, `src/CsvSampleRunner.php` | Streaming CSV/ZIP input and bounded candidate selection |
| `bin/` | Runnable demo, JSON and CSV entry points |
| `tests/` | 49 matching checks plus 19 CSV/integration checks |
| `fixtures/` | Synthetic demo data |
| `docs/design.md` | Domain model, trade-offs, and production-scale design |
| `docs/dataset-findings.md` | Aggregate observations from the provided data |

## Limitations and next steps

This is a **working prototype, not a production matching service**.

- **Coverage:** The CSV command streams the supplied data without loading it all into memory, but only evaluates a bounded number of private products and candidates. It does not build a persistent full-catalogue search index.
- **Match quality:** Rules are deliberately conservative but not calibrated against a labeled ground-truth set. Precision and recall have **not** been measured.
- **Data ambiguity:** Identifier provenance can be missing; variant parsing, multilingual titles, brand aliases and size conversions are incomplete. Some valid matches will be missed, and untested false positives remain possible.
- **Workflow:** Suggestion review states are returned, but acceptance/rejection, persistence, source-change expiry and audit history are not implemented.
- **Scale and tenancy:** The supplied files omit the real customer-to-followed-store mapping. Production would require authoritative store permissions, durable indexes, incremental processing and monitoring. See `docs/design.md`.

The next step would be to evaluate manually labeled product pairs, refine variant/identifier rules, then introduce durable per-store indexes and an auditable suggestion lifecycle.

## Data handling

The repository contains **synthetic fixtures and aggregate findings only**. It does not publish Lengow's original ZIP/CSVs, private product records, or generated reports containing source data without permission.
