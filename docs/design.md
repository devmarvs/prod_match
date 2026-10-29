# Design sketch — precision-first product matching

## Scope and outcomes

A trusted private product is compared to candidate products in public rival stores monitored by its owner. A high-precision **connection** is an accepted relationship. A **suggestion** is an unconfirmed relationship that can be accepted, rejected, or expired. We intentionally prefer abstention over accidental wrong connections, especially for different size/colour/pack variants.

## Data model

- **ProductIdentity** `(StoreID, Code, SubCode)`: `SubCode` is part of the key, including empty values. Product attributes and lifecycle timestamps can change while identity remains the same.
- **Evidence**: original `Attributes` key, original value, canonicalized comparison value, source/provenance, quality flags, timestamp; avoid losing provenance by flattening.
- **Connection** `(private identity, public identity)`, origin (manual/deterministic/automation), audit trail, provenance snapshot, creation/update timestamps and active/deactivated state.
- **Suggestion** same six-part relation key plus rationale, rank, source, state (pending/accepted/rejected/expired), observation version, created/updated/expiry timestamps, and public snapshot for review.
- **Coverage** `(private store, followed public store)` limits which public store candidates are eligible. That relationship is absent from the supplied sample and must be an explicit input in production.
- Connections link **two source records**: they do not merge public and private records into one identity.

## Matching workflow

1. **Ingest/normalize** per source; validate checksum for EAN/GTIN/UPC lengths 8, 12, 13 and 14; normalize brand, title and variant cautiously. Keep `EAN_DEDUCTED`, `GOOGLE_EAN`, `SEARCH_BOX_EAN`, `EAN_`, and other original keys, even if promoted to `EAN` downstream. `EAN_INVALID` cannot be positive match evidence.
2. **Candidate generation**: the prototype indexes canonical EAN and brand-scoped title tokens within explicitly followed stores, then evaluates a bounded number of rare-token candidates in addition to EAN hits. **Production evolution** could add brand/model/MPN and properly scoped reference indexes, larger text/semantic candidate search with bounded K and incremental maintenance. Never perform an all-pairs product join.
3. **Safety checks**: explicitly contradictory valid EANs, incompatible brands/explicit models, size/colour/pack/capacity conflicts (including title and repeated attribute evidence), invalid identifier evidence, and reused EANs across multiple public variants within a store. Maintain an explainable 'no connection' option.
4. **Decide**: only strong, consistent evidence qualifies for connection; uncertain but plausible records become suggestions. Use adjustable policies and review/quality datasets rather than interpreting similarity heuristics as probabilities.
5. **Rank and cap** suggestions per private product **across all monitored public stores**, usually 5–10, with deterministic tie-breaking and duplicate suppression.
6. **Persist and audit**: insert/upsert by six-part relation identity; preserve source version/evidence and manual decisions. On product refresh, invalidate or re-evaluate stale suggestions and review whether old automatic links still hold.

## Candidate retrieval details and scale

The exercise snapshot describes ~546.8 million active public records, ~1.18 billion in the three status buckets, and 32.4 million private catalogue records. These are platform-level context figures, **not** the submitted sample size. The sample examined includes 1.717 million public records across eight stores.

Initial architecture: event-driven incremental pipeline. Crawl/import changes produce idempotent product-updated events; a normalizer validates and populates search indexes; per-private-store/followed-rival matching jobs retrieve bounded candidates; worker pool evaluates evidence; connection/suggestion writes are idempotent by relationship identity; events update downstream reporting. Add DLQ/retries, back-pressure, job checkpoints, partitioning/sharding by store, stable keys, incremental rematching and ownership of index rebuilds. Protect tenant boundaries and enforce store authorization before candidate evaluation.

Possible technology choices: queue/message broker for jobs, PostgreSQL for relationship state/decision history and normalized indexed metadata, search/inverted index for text retrieval and ID-based lookup, observability metrics/traces. Exact components depend on Lengow's current infrastructure; there is no need to provision any of this for the assignment.

## Data quality, failure modes, evaluation

- **Ambiguous inferred EAN**: can be copied onto every size of a public product family. Never trust it as unique per variant. Check a candidate group's EAN ambiguity plus normalized size.
- **Brand alias**: `ON RUNNING` and `On-Running` should compare consistently; cases like `HOKA` versus `HOKA ONE ONE` need an explicit, reviewed mapping, not unconditional fuzzy acceptance.
- **Size formats**: 43 1/3 vs `431/3` and US/EU conversions need careful category-specific rules; overly broad conversion may produce false positives.
- **Cross-language and template text**: titles and categories often differ between stores; title-only rules must be conservative.
- **Data freshness**: a public record can be stale or inactive. Use status/timestamps in candidate eligibility, re-evaluation and lifecycle; don't treat price/stock as identity evidence.
- **Seller-specific IDs**: `Code`, REF, MPN and ASIN have different scopes; don't compare arbitrary `Code` values globally.
- **Missing followed-store information**: do not assume all eight stores are followed by this customer; request it in real integration, state a sample-wide assumption for exploratory analysis.

Evaluate with human-labeled test pairs stratified across exact EAN, deduced EAN, no EAN, variants, brands, stores and activity status. Report **connection precision as the primary success metric**, then suggestion precision@K/recall@K, coverage, review burden, false positives by source, latency, stale-link rate and candidate-retrieval recall. With only unlabeled CSVs, reported candidate intersections are **not** true positives, precision or recall.

## Prototype boundary and next steps

The executable sample does attribute parsing, checksum normalization, pair evaluation, contradiction handling (including conflicts embedded in `Title` or repeated attributes), explanations, a small **in-memory inverted candidate generator**, an **explicit followed-store filter**, public-store EAN reuse detection across variants, and deterministic deduplicated top-K suggestions. It does **not** implement index generation from the entire CSV, persisted relationships, true customer/rival authorization from source data, calibrated thresholds, manual review or distributed operation. In a follow-on iteration, first implement an incremental durable candidate index over the supplied files, evaluate on a manually labeled set, then tune the conservative policy.

## CSV adapter / practical local execution

`CsvInput` streams the original Lengow CSV columns with `fgetcsv`, correctly handling quoted commas/newlines and an optional UTF-8 BOM. It can read extracted `.csv` files directly or read their members from `use-case-data.zip` through PHP ZipArchive or the system `unzip -p` command. The process does not hold the 3.31 GB public file in memory.

`CsvSampleRunner` selects a small active private-product sample, scans the public file once, filters the caller-supplied public store scope, accumulates **all** matching EAN hits for the selected private EANs (up to an explicit fail-closed memory cap), and retains only high title-overlap, same-brand fallback candidates for records missing EAN evidence. Each sampled private product then reuses the original `MatchingWorkflow`. This is a bounded end-to-end vertical slice, **not** a production retrieval index and **not** a quality benchmark.

A public scan stopped early via `--scan-limit` cannot detect future EAN reuse or contradictions from later rows. To avoid silently claiming verified links, the adapter downgrades any such provisional connections to suggestions and marks `public_scan_incomplete`. Complete scans of the supplied file keep normal matching policy. The actual customer-to-public-store follow mapping is not supplied; the CLI requires explicit store IDs or clearly labeled `--stores=all` exploratory mode. All sample reports may contain company data and are intended to remain local and uncommitted.
