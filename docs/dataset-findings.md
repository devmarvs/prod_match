# Findings from supplied Lengow sample (25 October 2026)

Files: `use-case-private_products-intersport.csv` and `use-case-public_products-final.csv` (counts below from streaming CSV parsing). Identifying business records have deliberately not been included in this redistributable starter.

| Metric | Value |
|---|---:|
| Private records | 52,267 |
| Private StoreID | 77210 |
| Active private records | 52,192 |
| Private records with brand and valid EAN | 52,267 |
| Distinct validated private EANs | 52,267 |
| Private rows that share a normalized title with other private rows | 47,099 |
| Public records | 1,717,072 |
| Public stores | 8 |
| Active public records | 1,714,908 |
| Public records with brand | 1,397,201 |
| Public records with valid EAN from supported fields | 1,038,393 |
| Public records containing EAN_DEDUCTED | 107,558 |
| Public records containing EAN_INVALID | 300,240 |
| Public record rows matching a private EAN | 41,865 |
| Distinct private products with EAN hits | 18,950 |
| Public stores producing a private EAN hit | 6 |

The `41,865` figure counts public candidate rows whose normalized valid EAN equals one of the private EANs; it does **not** represent confirmed product connections. Of these row-level EAN hits, 6,171 included `EAN_DEDUCTED` source evidence; note that source-key counts can overlap if a row carries multiple keys. Some inferred EAN values are repeated across distinct size variants in a public store. A candidate match by EAN can therefore be a false positive.

Observed brand values also differ by punctuation, e.g. `ON RUNNING` versus `On-Running`; it is unsafe to treat all raw brand differences as actual manufacturer conflicts without normalization/alias review.

The submitted sample's eight public stores are **not** the PDF's 7,168-store overall architecture snapshot. The provided files do not state the followed-rival-store mapping nor provide a labeled reference set of correct connections.

Practical priority: (1) normalize and preserve EAN provenance, (2) guard against different purchasable variants, (3) explicitly scope candidates to followed stores, (4) demonstrate conservative connections vs suggestions, (5) explain index-backed, incremental scaling.

## Exploratory smoke test (not labeled evaluation)

The prototype evaluator was run locally against one actual private size variant and six public records that all shared its EAN. Five public records had contradictory `SubTitle` sizes and were classified **none**; the sole size-compatible record was a **suggestion**, not a connection, because its only EAN evidence was `EAN_DEDUCTED`. This checks the core failure mode against real input. It does **not** establish precision or recall and the actual source rows are **not** included in this starter.
