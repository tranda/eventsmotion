# Race Plans Summary

_Auto-generated from `config/idbf_race_plans.php` by `php artisan idbf:export-plans`._
_Source of truth for IDBF plans: `docs/idbf/Race-Plans-v2-2020-08.pdf`._

## Position-reference convention

Refs like `1st in hts` / `3rd in reps` / `5th in SF` resolve as:

1. Positions 1..N (N = number of races in the source stage) → literal **winner of each race**, in race order.
2. Positions N+1..M → remaining crews ranked by combined time across the stage (lucky losers).

Example: for RP.1 with 2 heats, `1st in hts` = winner of Heat 1, `2nd in hts` = winner of Heat 2, `3rd in hts` = fastest non-winner overall.

## Index

**3 lanes**: [RP.1_3L](#rp13l)
**4 lanes**: [ROUNDS_4L](#rounds4l), [RP.1](#rp1), [RP.1_COMPACT](#rp1compact), [RP.2](#rp2), [RP.3](#rp3)
**6 lanes**: [ROUNDS_6L](#rounds6l), [RP.1A](#rp1a), [RP.2A](#rp2a), [RP.3A](#rp3a), [RP.4A](#rp4a), [RP.5A](#rp5a), [RP.6A](#rp6a), [RP.7A](#rp7a)
**8 lanes**: [RP.1B](#rp1b), [RP.2B](#rp2b), [RP.3B](#rp3b), [RP.4B](#rp4b), [RP.5B](#rp5b), [RP.6B](#rp6b), [RP.7B](#rp7b)

---

# 3-lane plans

## `RP.1_3L` _(non-IDBF variant)_

- **Lanes**: 3
- **Crew count**: 4 crews
- **Stages**: Heat 1 → Heat 2 → Repechage 1 → Grand Final
- **Total races**: 4

### Heat lane seeding (seed numbers)

| Lane → | L1 | L2 | L3 |
|---|---|---|---|
| **Heat 1** | 4 | 1 | — |
| **Heat 2** | 3 | 2 | — |

### Repechage lane seeding

| Lane → | L1 | L2 | L3 |
|---|---|---|---|
| **Rep 1** | 4th in hts | 3rd in hts | — |

### Grand Final lane seeding

| Lane → | L1 | L2 | L3 |
|---|---|---|---|
| **Final 1** | 2nd in hts | 1st in hts | 1st in reps |

### Advancement

- 4 crews split into 2 heats (2 vs 2).
- 1st of each heat → Grand Final.
- 2nd of each heat → Repechage.
- Winner of the Repechage → Grand Final (3 crews total).
- Every crew races at least twice.


---

# 4-lane plans

## `ROUNDS_4L` _(non-IDBF variant)_

- **Lanes**: 4
- **Crew count**: 2–4 crews
- **Stages**: Round 1 → Round 2 → Round 3
- **Total races**: 3

### Round lane seeding (seed numbers)

| Lane → | L1 | L2 | L3 | L4 |
|---|---|---|---|---|
| **Round 1** | 3 | 1 | 2 | 4 |
| **Round 2** | 2 | 3 | 4 | 1 |
| **Round 3** | 4 | 2 | 1 | 3 |

### Advancement

- If time permits use 3 rounds (sum all 3 times for placings); otherwise 2 rounds (sum both).


## `RP.1`

- **Lanes**: 4
- **Crew count**: 5–8 crews
- **Stages**: Heat 1 → Heat 2 → Repechage 1 → Repechage 2 → Grand Final
- **Total races**: 5

### Heat lane seeding (seed numbers)

| Lane → | L1 | L2 | L3 | L4 |
|---|---|---|---|---|
| **Heat 1** | 5 | 1 | 4 | 8 |
| **Heat 2** | 6 | 2 | 3 | 7 |

### Repechage lane seeding

| Lane → | L1 | L2 | L3 | L4 |
|---|---|---|---|---|
| **Rep 1** | 7th in hts | 3rd in hts | 6th in hts | — |
| **Rep 2** | 8th in hts | 4th in hts | 5th in hts | — |

### Grand Final lane seeding

| Lane → | L1 | L2 | L3 | L4 |
|---|---|---|---|---|
| **Final 1** | 1st in reps | 1st in hts | 2nd in hts | 2nd in reps |

### Advancement

- Crews seeded in heats based on previous results.
- Winner of each heat → Grand Final (2 crews).
- Remainder → repechages (3 to 8).
- 5-6 crews: one repechage only — 1st & 2nd → Grand Final.
- 7-8 crews: winner of each repechage → Grand Final.
- Remainder drop out (5 to 8).


## `RP.1_COMPACT` _(non-IDBF variant)_

- **Lanes**: 4
- **Crew count**: 7 crews
- **Stages**: Heat 1 → Heat 2 → Repechage 1 → Grand Final
- **Total races**: 4

### Heat lane seeding (seed numbers)

| Lane → | L1 | L2 | L3 | L4 |
|---|---|---|---|---|
| **Heat 1** | 5 | 1 | 4 | 8 |
| **Heat 2** | 6 | 2 | 3 | 7 |

### Repechage lane seeding

| Lane → | L1 | L2 | L3 | L4 |
|---|---|---|---|---|
| **Rep 1** | 6th in hts | 4th in hts | 5th in hts | 7th in hts |

### Grand Final lane seeding

| Lane → | L1 | L2 | L3 | L4 |
|---|---|---|---|---|
| **Final 1** | 3rd in hts | 1st in hts | 2nd in hts | 1st in reps |

### Advancement

- Variant of RP.1 — not in the IDBF Race Plans document.
- 2 heat winners + next fastest overall → Grand Final (3 crews).
- Remaining 4 crews → repechage.
- Winner of repechage → Grand Final (4 crews total).


## `RP.2`

- **Lanes**: 4
- **Crew count**: 9–12 crews
- **Stages**: Heat 1 → Heat 2 → Heat 3 → Repechage 1 → Repechage 2 → Semi 1 → Semi 2 → Grand Final
- **Total races**: 8

### Heat lane seeding (seed numbers)

| Lane → | L1 | L2 | L3 | L4 |
|---|---|---|---|---|
| **Heat 1** | 7 | 1 | 6 | 12 |
| **Heat 2** | 8 | 2 | 5 | 11 |
| **Heat 3** | 9 | 3 | 4 | 10 |

### Repechage lane seeding

| Lane → | L1 | L2 | L3 | L4 |
|---|---|---|---|---|
| **Rep 1** | 9th in hts | 5th in hts | 8th in hts | 12th in hts |
| **Rep 2** | 10th in hts | 6th in hts | 7th in hts | 11th in hts |

### Semi-final lane seeding

| Lane → | L1 | L2 | L3 | L4 |
|---|---|---|---|---|
| **Semi 1** | 1st in reps | 1st in hts | 4th in hts | 4th in reps |
| **Semi 2** | 2nd in reps | 2nd in hts | 3rd in hts | 3rd in reps |

### Grand Final lane seeding

| Lane → | L1 | L2 | L3 | L4 |
|---|---|---|---|---|
| **Final 1** | 3rd in SF | 1st in SF | 2nd in SF | 4th in SF |

### Advancement

- Winner of each heat plus next fastest overall → semi-finals (4 crews).
- Remainder → repechages (5 to 12).
- Winner of each repechage plus next 2 fastest overall → semi-finals (4 crews).
- Winner of each semi plus next 2 fastest overall → Grand Final (4 crews).


## `RP.3`

- **Lanes**: 4
- **Crew count**: 13–16 crews
- **Stages**: Heat 1 → Heat 2 → Heat 3 → Heat 4 → Repechage 1 → Repechage 2 → Semi 1 → Semi 2 → Semi 3 → Grand Final
- **Total races**: 10

### Heat lane seeding (seed numbers)

| Lane → | L1 | L2 | L3 | L4 |
|---|---|---|---|---|
| **Heat 1** | 9 | 1 | 8 | 16 |
| **Heat 2** | 10 | 2 | 7 | 15 |
| **Heat 3** | 11 | 3 | 6 | 14 |
| **Heat 4** | 12 | 4 | 5 | 13 |

### Repechage lane seeding

| Lane → | L1 | L2 | L3 | L4 |
|---|---|---|---|---|
| **Rep 1** | 13th in hts | 9th in hts | 12th in hts | 16th in hts |
| **Rep 2** | 14th in hts | 10th in hts | 11th in hts | 15th in hts |

### Semi-final lane seeding

| Lane → | L1 | L2 | L3 | L4 |
|---|---|---|---|---|
| **Semi 1** | 7th in reps | 1st in hts | 6th in hts | 4th in reps |
| **Semi 2** | 8th in reps | 2nd in hts | 5th in hts | 3rd in reps |
| **Semi 3** | 1st in reps | 3rd in hts | 4th in hts | 2nd in reps |

### Grand Final lane seeding

| Lane → | L1 | L2 | L3 | L4 |
|---|---|---|---|---|
| **Final 1** | 3rd in SF | 1st in SF | 2nd in SF | 4th in SF |

### Advancement

- 1st and 2nd in each heat → semi-finals (8 crews).
- Remainder → repechages (9 to 16).
- 1st and 2nd in each repechage → semi-finals (4 crews).
- Winner of each semi plus next fastest overall → Grand Final (4 crews).


---

# 6-lane plans

## `ROUNDS_6L` _(non-IDBF variant)_

- **Lanes**: 6
- **Crew count**: 3–6 crews
- **Stages**: Round 1 → Round 2 → Round 3
- **Total races**: 3

### Round lane seeding (seed numbers)

| Lane → | L1 | L2 | L3 | L4 | L5 | L6 |
|---|---|---|---|---|---|---|
| **Round 1** | 5 | 3 | 1 | 2 | 4 | 6 |
| **Round 2** | 3 | 2 | 6 | 5 | 1 | 4 |
| **Round 3** | 1 | 5 | 4 | 3 | 6 | 2 |

### Advancement

- If time permits use 3 rounds (sum all 3 times); otherwise 2 rounds (sum both).


## `RP.1A`

- **Lanes**: 6
- **Crew count**: 7–8 crews
- **Stages**: Heat 1 → Heat 2 → Repechage 1 → Grand Final
- **Total races**: 4

### Heat lane seeding (seed numbers)

| Lane → | L1 | L2 | L3 | L4 | L5 | L6 |
|---|---|---|---|---|---|---|
| **Heat 1** | — | 5 | 1 | 4 | 8 | — |
| **Heat 2** | — | 6 | 2 | 3 | 7 | — |

### Repechage lane seeding

| Lane → | L1 | L2 | L3 | L4 | L5 | L6 |
|---|---|---|---|---|---|---|
| **Rep 1** | 8th in hts | 6th in hts | 4th in hts | 5th in hts | 7th in hts | — |

### Grand Final lane seeding

| Lane → | L1 | L2 | L3 | L4 | L5 | L6 |
|---|---|---|---|---|---|---|
| **Final 1** | 2nd in reps | 3rd in hts | 1st in hts | 2nd in hts | 1st in reps | 3rd in reps |

### Advancement

- Winner of each heat plus next fastest overall in both heats → Grand Final (3 crews).
- Remainder → repechage (4 to 8).
- 1st, 2nd and 3rd in repechage → Grand Final (3 crews).
- Remainder drop out (7 to 8).


## `RP.2A`

- **Lanes**: 6
- **Crew count**: 9–12 crews
- **Stages**: Heat 1 → Heat 2 → Repechage 1 → Repechage 2 → Grand Final
- **Total races**: 5

### Heat lane seeding (seed numbers)

| Lane → | L1 | L2 | L3 | L4 | L5 | L6 |
|---|---|---|---|---|---|---|
| **Heat 1** | 9 | 5 | 1 | 4 | 8 | 12 |
| **Heat 2** | 10 | 6 | 2 | 3 | 7 | 11 |

### Repechage lane seeding (varies by exact crew count)

**9 crews:**

| Lane → | L1 | L2 | L3 | L4 | L5 | L6 |
|---|---|---|---|---|---|---|
| **Rep 1** | 8th in hts | 6th in hts | 4th in hts | 5th in hts | 7th in hts | 9th in hts |

**10 crews:**

| Lane → | L1 | L2 | L3 | L4 | L5 | L6 |
|---|---|---|---|---|---|---|
| **Rep 1** | 12th in hts | 8th in hts | 4th in hts | 7th in hts | 11th in hts | — |
| **Rep 2** | 9th in hts | 5th in hts | 6th in hts | 10th in hts | — | — |

**11 crews:**

| Lane → | L1 | L2 | L3 | L4 | L5 | L6 |
|---|---|---|---|---|---|---|
| **Rep 1** | 12th in hts | 8th in hts | 4th in hts | 7th in hts | 11th in hts | — |
| **Rep 2** | 9th in hts | 5th in hts | 6th in hts | 10th in hts | — | — |

**12 crews:**

| Lane → | L1 | L2 | L3 | L4 | L5 | L6 |
|---|---|---|---|---|---|---|
| **Rep 1** | 12th in hts | 8th in hts | 4th in hts | 7th in hts | 11th in hts | — |
| **Rep 2** | 9th in hts | 5th in hts | 6th in hts | 10th in hts | — | — |

### Grand Final lane seeding

| Lane → | L1 | L2 | L3 | L4 | L5 | L6 |
|---|---|---|---|---|---|---|
| **Final 1** | 2nd in reps | 3rd in hts | 1st in hts | 2nd in hts | 1st in reps | 3rd in reps |

### Advancement

- Winner of each heat plus next fastest overall in both heats → Grand Final (3 crews).
- Remainder → repechage (4 to 12).
- 9 crews: 1st, 2nd and 3rd in single rep → Grand Final.
- 10-12 crews: 1st in each rep plus next fastest overall → Grand Final.
- Remainder drop out (7 to 12).


## `RP.3A`

- **Lanes**: 6
- **Crew count**: 13–18 crews
- **Stages**: Heat 1 → Heat 2 → Heat 3 → Repechage 1 → Repechage 2 → Semi 1 → Semi 2 → Minor Final → Grand Final
- **Total races**: 9

### Heat lane seeding (seed numbers)

| Lane → | L1 | L2 | L3 | L4 | L5 | L6 |
|---|---|---|---|---|---|---|
| **Heat 1** | 13 | 7 | 1 | 6 | 12 | 18 |
| **Heat 2** | 14 | 8 | 2 | 5 | 11 | 17 |
| **Heat 3** | 15 | 9 | 3 | 4 | 10 | 16 |

### Repechage lane seeding

| Lane → | L1 | L2 | L3 | L4 | L5 | L6 |
|---|---|---|---|---|---|---|
| **Rep 1** | 15th in hts | 11th in hts | 7th in hts | 10th in hts | 14th in hts | 18th in hts |
| **Rep 2** | 16th in hts | 12th in hts | 8th in hts | 9th in hts | 13th in hts | 17th in hts |

### Semi-final lane seeding

| Lane → | L1 | L2 | L3 | L4 | L5 | L6 |
|---|---|---|---|---|---|---|
| **Semi 1** | 3rd in reps | 5th in hts | 1st in hts | 4th in hts | 2nd in reps | 6th in reps |
| **Semi 2** | 4th in reps | 6th in hts | 2nd in hts | 3rd in hts | 1st in reps | 5th in reps |

### Grand Final lane seeding

| Lane → | L1 | L2 | L3 | L4 | L5 | L6 |
|---|---|---|---|---|---|---|
| **Final 1** | 5th in SF | 3rd in SF | 1st in SF | 2nd in SF | 4th in SF | 6th in SF |

### Minor Final lane seeding

| Lane → | L1 | L2 | L3 | L4 | L5 | L6 |
|---|---|---|---|---|---|---|
| **Minor 1** | 11th in SF | 9th in SF | 7th in SF | 8th in SF | 10th in SF | 12th in SF |

### Advancement

- 1st and 2nd in each heat → semi-finals (6 crews).
- Remainder → repechages (13 to 18).
- 13 crews: 1st & 2nd in each rep → semi-finals (3 crews drop out).
- 14 crews: 1st & 2nd in each rep plus next fastest → semi-finals (4 crews drop out).
- 15-18 crews: 1st & 2nd in each rep plus next 2 fastest → semi-finals (3 to 6 crews drop out).
- 1st & 2nd in each semi plus next 2 fastest → Grand Final (6 crews).
- Crews placed 7 to 12 in semis → Minor Final.


## `RP.4A`

- **Lanes**: 6
- **Crew count**: 19–24 crews
- **Stages**: Heat 1 → Heat 2 → Heat 3 → Heat 4 → Repechage 1 → Repechage 2 → Semi 1 → Semi 2 → Semi 3 → Minor Final → Grand Final
- **Total races**: 11

### Heat lane seeding (seed numbers)

| Lane → | L1 | L2 | L3 | L4 | L5 | L6 |
|---|---|---|---|---|---|---|
| **Heat 1** | 17 | 9 | 1 | 8 | 16 | 24 |
| **Heat 2** | 18 | 10 | 2 | 7 | 15 | 23 |
| **Heat 3** | 19 | 11 | 3 | 6 | 14 | 22 |
| **Heat 4** | 20 | 12 | 4 | 5 | 13 | 21 |

### Repechage lane seeding

| Lane → | L1 | L2 | L3 | L4 | L5 | L6 |
|---|---|---|---|---|---|---|
| **Rep 1** | 21st in hts | 17th in hts | 13th in hts | 16th in hts | 20th in hts | 24th in hts |
| **Rep 2** | 22nd in hts | 18th in hts | 14th in hts | 15th in hts | 19th in hts | 23rd in hts |

### Semi-final lane seeding

| Lane → | L1 | L2 | L3 | L4 | L5 | L6 |
|---|---|---|---|---|---|---|
| **Semi 1** | 1st in reps | 7th in hts | 1st in hts | 6th in hts | 12th in hts | 6th in reps |
| **Semi 2** | 2nd in reps | 8th in hts | 2nd in hts | 5th in hts | 11th in hts | 5th in reps |
| **Semi 3** | 3rd in reps | 9th in hts | 3rd in hts | 4th in hts | 10th in hts | 4th in reps |

### Grand Final lane seeding

| Lane → | L1 | L2 | L3 | L4 | L5 | L6 |
|---|---|---|---|---|---|---|
| **Final 1** | 5th in SF | 3rd in SF | 1st in SF | 2nd in SF | 4th in SF | 6th in SF |

### Minor Final lane seeding

| Lane → | L1 | L2 | L3 | L4 | L5 | L6 |
|---|---|---|---|---|---|---|
| **Minor 1** | 11th in SF | 9th in SF | 7th in SF | 8th in SF | 10th in SF | 12th in SF |

### Advancement

- 1st, 2nd and 3rd in each heat → semi-finals (12 crews).
- Remainder → repechages (13 to 24).
- 19-20 crews: 1st & 2nd in each rep → semi-finals (3 drop).
- 21 crews: 1st & 2nd in each rep plus next fastest → semi-finals (4 drop).
- 22-24 crews: 1st, 2nd and 3rd in each rep → semi-finals (remainder drop).
- 1st in each semi plus next 3 fastest → Grand Final (6 crews).
- Next 6 fastest → Minor Final.


## `RP.5A`

- **Lanes**: 6
- **Crew count**: 25–30 crews
- **Stages**: Heat 1 → Heat 2 → Heat 3 → Heat 4 → Heat 5 → Repechage 1 → Repechage 2 → Repechage 3 → Semi 1 → Semi 2 → Semi 3 → Minor Final → Grand Final
- **Total races**: 13

### Heat lane seeding (seed numbers)

| Lane → | L1 | L2 | L3 | L4 | L5 | L6 |
|---|---|---|---|---|---|---|
| **Heat 1** | 21 | 11 | 1 | 10 | 20 | 30 |
| **Heat 2** | 22 | 12 | 2 | 9 | 19 | 29 |
| **Heat 3** | 23 | 13 | 3 | 8 | 18 | 28 |
| **Heat 4** | 24 | 14 | 4 | 7 | 17 | 27 |
| **Heat 5** | 25 | 15 | 5 | 6 | 16 | 26 |

### Repechage lane seeding

| Lane → | L1 | L2 | L3 | L4 | L5 | L6 |
|---|---|---|---|---|---|---|
| **Rep 1** | 26th in hts | 20th in hts | 14th in hts | 19th in hts | 25th in hts | — |
| **Rep 2** | 27th in hts | 21st in hts | 15th in hts | 18th in hts | 24th in hts | 30th in hts |
| **Rep 3** | 28th in hts | 22nd in hts | 16th in hts | 17th in hts | 23rd in hts | 29th in hts |

### Semi-final lane seeding

| Lane → | L1 | L2 | L3 | L4 | L5 | L6 |
|---|---|---|---|---|---|---|
| **Semi 1** | 13th in hts | 7th in hts | 1st in hts | 6th in hts | 12th in hts | 5th in reps |
| **Semi 2** | 1st in reps | 8th in hts | 2nd in hts | 5th in hts | 11th in hts | 4th in reps |
| **Semi 3** | 2nd in reps | 9th in hts | 3rd in hts | 4th in hts | 10th in hts | 3rd in reps |

### Grand Final lane seeding

| Lane → | L1 | L2 | L3 | L4 | L5 | L6 |
|---|---|---|---|---|---|---|
| **Final 1** | 5th in SF | 3rd in SF | 1st in SF | 2nd in SF | 4th in SF | 6th in SF |

### Minor Final lane seeding

| Lane → | L1 | L2 | L3 | L4 | L5 | L6 |
|---|---|---|---|---|---|---|
| **Minor 1** | 11th in SF | 9th in SF | 7th in SF | 8th in SF | 10th in SF | 12th in SF |

### Advancement

- 1st and 2nd in each heat plus next 3 fastest overall → semi-finals (13 crews).
- Remainder → repechages (14 to 30).
- 1st in each repechage plus next 2 fastest overall → semi-finals (5 crews).
- Remainder drop out (19 to 30).
- 1st in each semi plus next 3 fastest → Grand Final (6 crews).
- Next 6 fastest → Minor Final.


## `RP.6A`

- **Lanes**: 6
- **Crew count**: 31–36 crews
- **Stages**: Heat 1 → Heat 2 → Heat 3 → Heat 4 → Heat 5 → Heat 6 → Repechage 1 → Repechage 2 → Repechage 3 → Semi 1 → Semi 2 → Semi 3 → Semi 4 → Minor Final → Grand Final
- **Total races**: 15

### Heat lane seeding (seed numbers)

| Lane → | L1 | L2 | L3 | L4 | L5 | L6 |
|---|---|---|---|---|---|---|
| **Heat 1** | 25 | 13 | 1 | 12 | 24 | 36 |
| **Heat 2** | 26 | 14 | 2 | 11 | 23 | 35 |
| **Heat 3** | 27 | 15 | 3 | 10 | 22 | 34 |
| **Heat 4** | 28 | 16 | 4 | 9 | 21 | 33 |
| **Heat 5** | 29 | 17 | 5 | 8 | 20 | 32 |
| **Heat 6** | 30 | 18 | 6 | 7 | 19 | 31 |

### Repechage lane seeding

| Lane → | L1 | L2 | L3 | L4 | L5 | L6 |
|---|---|---|---|---|---|---|
| **Rep 1** | 31st in hts | 25th in hts | 19th in hts | 24th in hts | 30th in hts | 36th in hts |
| **Rep 2** | 32nd in hts | 26th in hts | 20th in hts | 23rd in hts | 29th in hts | 35th in hts |
| **Rep 3** | 33rd in hts | 27th in hts | 21st in hts | 22nd in hts | 28th in hts | 34th in hts |

### Semi-final lane seeding

| Lane → | L1 | L2 | L3 | L4 | L5 | L6 |
|---|---|---|---|---|---|---|
| **Semi 1** | 17th in hts | 9th in hts | 1st in hts | 8th in hts | 16th in hts | 6th in reps |
| **Semi 2** | 18th in hts | 10th in hts | 2nd in hts | 7th in hts | 15th in hts | 5th in reps |
| **Semi 3** | 1st in reps | 11th in hts | 3rd in hts | 6th in hts | 14th in hts | 4th in reps |
| **Semi 4** | 2nd in reps | 12th in hts | 4th in hts | 5th in hts | 13th in hts | 3rd in reps |

### Grand Final lane seeding

| Lane → | L1 | L2 | L3 | L4 | L5 | L6 |
|---|---|---|---|---|---|---|
| **Final 1** | 5th in SF | 3rd in SF | 1st in SF | 2nd in SF | 4th in SF | 6th in SF |

### Minor Final lane seeding

| Lane → | L1 | L2 | L3 | L4 | L5 | L6 |
|---|---|---|---|---|---|---|
| **Minor 1** | 11th in SF | 9th in SF | 7th in SF | 8th in SF | 10th in SF | 12th in SF |

### Advancement

- 1st, 2nd and 3rd in each heat → semi-finals (18 crews).
- Remainder → repechages (19 to 36).
- 1st in each repechage plus next 3 fastest overall → semi-finals (6 crews).
- Remainder drop out (24 to 36).
- 1st in each semi plus next 2 fastest → Grand Final (6 crews).
- Next 6 fastest → Minor Final.


## `RP.7A`

- **Lanes**: 6
- **Crew count**: 37–42 crews
- **Stages**: Heat 1 → Heat 2 → Heat 3 → Heat 4 → Heat 5 → Heat 6 → Heat 7 → Repechage 1 → Repechage 2 → Repechage 3 → Repechage 4 → Semi 1 → Semi 2 → Semi 3 → Semi 4 → Semi 5 → Tail Final → Minor Final → Grand Final
- **Total races**: 19

### Heat lane seeding (seed numbers)

| Lane → | L1 | L2 | L3 | L4 | L5 | L6 |
|---|---|---|---|---|---|---|
| **Heat 1** | 29 | 15 | 1 | 14 | 28 | 42 |
| **Heat 2** | 30 | 16 | 2 | 13 | 27 | 41 |
| **Heat 3** | 31 | 17 | 3 | 12 | 26 | 40 |
| **Heat 4** | 32 | 18 | 4 | 11 | 25 | 39 |
| **Heat 5** | 33 | 19 | 5 | 10 | 24 | 38 |
| **Heat 6** | 34 | 20 | 6 | 9 | 23 | 37 |
| **Heat 7** | 35 | 21 | 7 | 8 | 22 | 36 |

### Repechage lane seeding

| Lane → | L1 | L2 | L3 | L4 | L5 | L6 |
|---|---|---|---|---|---|---|
| **Rep 1** | 38th in hts | 30th in hts | 22nd in hts | 29th in hts | 37th in hts | — |
| **Rep 2** | 39th in hts | 31st in hts | 23rd in hts | 28th in hts | 36th in hts | — |
| **Rep 3** | 40th in hts | 32nd in hts | 24th in hts | 27th in hts | 35th in hts | — |
| **Rep 4** | 41st in hts | 33rd in hts | 25th in hts | 26th in hts | 34th in hts | 42nd in hts |

### Semi-final lane seeding

| Lane → | L1 | L2 | L3 | L4 | L5 | L6 |
|---|---|---|---|---|---|---|
| **Semi 1** | 21st in hts | 11th in hts | 1st in hts | 10th in hts | 20th in hts | 9th in reps |
| **Semi 2** | 1st in reps | 12th in hts | 2nd in hts | 9th in hts | 19th in hts | 8th in reps |
| **Semi 3** | 2nd in reps | 13th in hts | 3rd in hts | 8th in hts | 18th in hts | 7th in reps |
| **Semi 4** | 3rd in reps | 14th in hts | 4th in hts | 7th in hts | 17th in hts | 6th in reps |
| **Semi 5** | 4th in reps | 15th in hts | 5th in hts | 6th in hts | 16th in hts | 5th in reps |

### Grand Final lane seeding

| Lane → | L1 | L2 | L3 | L4 | L5 | L6 |
|---|---|---|---|---|---|---|
| **Final 1** | 5th in SF | 3rd in SF | 1st in SF | 2nd in SF | 4th in SF | 6th in SF |

### Minor Final lane seeding

| Lane → | L1 | L2 | L3 | L4 | L5 | L6 |
|---|---|---|---|---|---|---|
| **Minor 1** | 11th in SF | 9th in SF | 7th in SF | 8th in SF | 10th in SF | 12th in SF |

### Tail Final lane seeding

| Lane → | L1 | L2 | L3 | L4 | L5 | L6 |
|---|---|---|---|---|---|---|
| **Tail 1** | 17th in SF | 15th in SF | 13th in SF | 14th in SF | 16th in SF | 18th in SF |

### Advancement

- 1st, 2nd and 3rd in each heat → semi-finals (21 crews).
- Remainder → repechages (22 to 42).
- 1st and 2nd plus fastest overall in each repechage → semi-finals (9 crews).
- Remainder drop out (34 to 42).
- 1st in each semi plus next fastest → Grand Final (6 crews).
- Next 6 fastest → Minor Final.
- Next 6 fastest → Tail Final.


---

# 8-lane plans

## `RP.1B`

- **Lanes**: 8
- **Crew count**: 7–8 crews
- **Stages**: Round 1 → Round 2 → Round 3
- **Total races**: 3

### Round lane seeding (seed numbers)

| Lane → | L1 | L2 | L3 | L4 | L5 | L6 | L7 | L8 |
|---|---|---|---|---|---|---|---|---|
| **Round 1** | 7 | 5 | 3 | 1 | 2 | 4 | 6 | 8 |
| **Round 2** | 3 | 2 | 7 | 6 | 5 | 8 | 1 | 4 |
| **Round 3** | 6 | 4 | 2 | 8 | 7 | 1 | 3 | 5 |

### Advancement

- If time permits use 3 rounds (sum all 3 times); otherwise 2 rounds (sum both).


## `RP.2B`

- **Lanes**: 8
- **Crew count**: 9–12 crews
- **Stages**: Heat 1 → Heat 2 → Repechage 1 → Grand Final
- **Total races**: 4

### Heat lane seeding (seed numbers)

| Lane → | L1 | L2 | L3 | L4 | L5 | L6 | L7 | L8 |
|---|---|---|---|---|---|---|---|---|
| **Heat 1** | 13 | 9 | 5 | 1 | 4 | 8 | 12 | — |
| **Heat 2** | 14 | 10 | 6 | 2 | 3 | 7 | 11 | — |

### Repechage lane seeding

| Lane → | L1 | L2 | L3 | L4 | L5 | L6 | L7 | L8 |
|---|---|---|---|---|---|---|---|---|
| **Rep 1** | 11 | 9 | 7 | 5 | 6 | 8 | 10 | 12 |

### Grand Final lane seeding

| Lane → | L1 | L2 | L3 | L4 | L5 | L6 | L7 | L8 |
|---|---|---|---|---|---|---|---|---|
| **Final 1** | 3rd in reps | 1st in reps | 3rd in hts | 1st in hts | 2nd in hts | 4th in hts | 2nd in reps | 4th in reps |

### Advancement

- 1st and 2nd in each heat → Grand Final (4 crews).
- Remainder → repechage (5 to 12).
- 1st, 2nd, 3rd and 4th in repechage → Grand Final (4 crews).
- Remainder drop out (5 to 12).


## `RP.3B`

- **Lanes**: 8
- **Crew count**: 13–16 crews
- **Stages**: Heat 1 → Heat 2 → Repechage 1 → Repechage 2 → Grand Final
- **Total races**: 5

### Heat lane seeding (seed numbers)

| Lane → | L1 | L2 | L3 | L4 | L5 | L6 | L7 | L8 |
|---|---|---|---|---|---|---|---|---|
| **Heat 1** | 13 | 9 | 5 | 1 | 4 | 8 | 12 | 16 |
| **Heat 2** | 14 | 10 | 6 | 2 | 3 | 7 | 11 | 15 |

### Repechage lane seeding

| Lane → | L1 | L2 | L3 | L4 | L5 | L6 | L7 | L8 |
|---|---|---|---|---|---|---|---|---|
| **Rep 1** | — | 13th in hts | 9th in hts | 5th in hts | 8th in hts | 12th in hts | 16th in hts | — |
| **Rep 2** | — | 14th in hts | 10th in hts | 6th in hts | 7th in hts | 11th in hts | 15th in hts | — |

### Grand Final lane seeding

| Lane → | L1 | L2 | L3 | L4 | L5 | L6 | L7 | L8 |
|---|---|---|---|---|---|---|---|---|
| **Final 1** | 3rd in reps | 1st in reps | 3rd in hts | 1st in hts | 2nd in hts | 4th in hts | 2nd in reps | 4th in reps |

### Advancement

- 1st and 2nd in each heat → Grand Final (4 crews).
- Remainder → repechage (5 to 16).
- 1st and 2nd in each repechage → Grand Final (4 crews).
- Remainder drop out (12 to 16).


## `RP.4B`

- **Lanes**: 8
- **Crew count**: 17–24 crews
- **Stages**: Heat 1 → Heat 2 → Heat 3 → Repechage 1 → Repechage 2 → Semi 1 → Semi 2 → Minor Final → Grand Final
- **Total races**: 9

### Heat lane seeding (seed numbers)

| Lane → | L1 | L2 | L3 | L4 | L5 | L6 | L7 | L8 |
|---|---|---|---|---|---|---|---|---|
| **Heat 1** | 19 | 13 | 7 | 1 | 6 | 12 | 18 | 24 |
| **Heat 2** | 20 | 14 | 8 | 2 | 5 | 11 | 17 | 23 |
| **Heat 3** | 21 | 15 | 9 | 3 | 4 | 10 | 16 | 22 |

### Repechage lane seeding

| Lane → | L1 | L2 | L3 | L4 | L5 | L6 | L7 | L8 |
|---|---|---|---|---|---|---|---|---|
| **Rep 1** | 21 | 17 | 13 | 9 | 12 | 16 | 20 | 24 |
| **Rep 2** | 22 | 18 | 14 | 10 | 11 | 15 | 19 | 23 |

### Semi-final lane seeding

| Lane → | L1 | L2 | L3 | L4 | L5 | L6 | L7 | L8 |
|---|---|---|---|---|---|---|---|---|
| **Semi 1** | 5th in rps | 1st in rps | 5th in hts | 1st in hts | 4th in hts | 8th in hts | 4th in rps | 8th in rps |
| **Semi 2** | 6th in rps | 2nd in rps | 6th in hts | 2nd in hts | 3rd in hts | 7th in hts | 3rd in rps | 7th in rps |

### Grand Final lane seeding

| Lane → | L1 | L2 | L3 | L4 | L5 | L6 | L7 | L8 |
|---|---|---|---|---|---|---|---|---|
| **Final 1** | 7th in SF | 5th in SF | 3rd in SF | 1st in SF | 2nd in SF | 4th in SF | 6th in SF | 8th in SF |

### Advancement

- 1st and 2nd in each heat plus next 2 fastest overall → semi-finals (8 crews).
- Remainder → repechage (9 to 24).
- 1st, 2nd and 3rd in each repechage plus next 2 fastest overall → semi-finals (8 crews).
- Remainder drop out (17 to 24).
- 1st, 2nd and 3rd in each semi plus next 2 fastest overall → Grand Final (8 crews).
- Remainder drop out (9 to 16).


## `RP.5B`

- **Lanes**: 8
- **Crew count**: 25–32 crews
- **Stages**: Heat 1 → Heat 2 → Heat 3 → Heat 4 → Repechage 1 → Repechage 2 → Semi 1 → Semi 2 → Semi 3 → Minor Final → Grand Final
- **Total races**: 11

### Heat lane seeding (seed numbers)

| Lane → | L1 | L2 | L3 | L4 | L5 | L6 | L7 | L8 |
|---|---|---|---|---|---|---|---|---|
| **Heat 1** | 25 | 17 | 9 | 1 | 8 | 16 | 24 | 32 |
| **Heat 2** | 26 | 18 | 10 | 2 | 7 | 15 | 23 | 31 |
| **Heat 3** | 27 | 19 | 11 | 3 | 6 | 14 | 22 | 30 |
| **Heat 4** | 28 | 20 | 12 | 4 | 5 | 13 | 21 | 29 |

### Repechage lane seeding

| Lane → | L1 | L2 | L3 | L4 | L5 | L6 | L7 | L8 |
|---|---|---|---|---|---|---|---|---|
| **Rep 1** | 29 | 25 | 21 | 17 | 20 | 24 | 28 | 32 |
| **Rep 2** | 30 | 26 | 22 | 18 | 19 | 23 | 27 | 31 |

### Semi-final lane seeding

| Lane → | L1 | L2 | L3 | L4 | L5 | L6 | L7 | L8 |
|---|---|---|---|---|---|---|---|---|
| **Semi 1** | 3rd in rps | 13th in hts | 7th in hts | 1st in hts | 6th in hts | 12th in hts | 2nd in rps | 8th in rps |
| **Semi 2** | 4th in rps | 14th in hts | 8th in hts | 2nd in hts | 5th in hts | 11th in hts | 1st in rps | 7th in rps |
| **Semi 3** | 5th in rps | 15th in hts | 9th in hts | 3rd in hts | 4th in hts | 10th in hts | 16th in hts | 6th in rps |

### Grand Final lane seeding

| Lane → | L1 | L2 | L3 | L4 | L5 | L6 | L7 | L8 |
|---|---|---|---|---|---|---|---|---|
| **Final 1** | 7th in SF | 5th in SF | 3rd in SF | 1st in SF | 2nd in SF | 4th in SF | 6th in SF | 8th in SF |

### Minor Final lane seeding

| Lane → | L1 | L2 | L3 | L4 | L5 | L6 | L7 | L8 |
|---|---|---|---|---|---|---|---|---|
| **Minor 1** | 15th in SF | 13th in SF | 11th in SF | 9th in SF | 10th in SF | 12th in SF | 14th in SF | 16th in SF |

### Advancement

- 1st, 2nd, 3rd and 4th in each heat → semi-finals (16 crews).
- Remainder → repechage (17 to 32).
- 1st, 2nd and 3rd in each repechage plus next 2 fastest overall → semi-finals (8 crews).
- Remainder drop out (25 to 32).
- 1st and 2nd in each semi plus next 2 fastest overall → Grand Final (8 crews).
- Next 8 fastest → Minor Final.


## `RP.6B`

- **Lanes**: 8
- **Crew count**: 33–40 crews
- **Stages**: Heat 1 → Heat 2 → Heat 3 → Heat 4 → Heat 5 → Repechage 1 → Repechage 2 → Repechage 3 → Semi 1 → Semi 2 → Semi 3 → Minor Final → Grand Final
- **Total races**: 13

### Heat lane seeding (seed numbers)

| Lane → | L1 | L2 | L3 | L4 | L5 | L6 | L7 | L8 |
|---|---|---|---|---|---|---|---|---|
| **Heat 1** | 31 | 21 | 11 | 1 | 10 | 20 | 30 | 40 |
| **Heat 2** | 32 | 22 | 12 | 2 | 9 | 19 | 29 | 39 |
| **Heat 3** | 33 | 23 | 13 | 3 | 8 | 18 | 28 | 38 |
| **Heat 4** | 34 | 24 | 14 | 4 | 7 | 17 | 27 | 37 |
| **Heat 5** | 35 | 25 | 15 | 5 | 6 | 16 | 26 | 36 |

### Repechage lane seeding

| Lane → | L1 | L2 | L3 | L4 | L5 | L6 | L7 | L8 |
|---|---|---|---|---|---|---|---|---|
| **Rep 1** | 35 | 29 | 23 | 17 | 22 | 28 | 34 | 40 |
| **Rep 2** | 36 | 30 | 24 | 18 | 21 | 27 | 33 | 39 |
| **Rep 3** | 37 | 31 | 25 | 19 | 20 | 26 | 32 | 38 |

### Semi-final lane seeding

| Lane → | L1 | L2 | L3 | L4 | L5 | L6 | L7 | L8 |
|---|---|---|---|---|---|---|---|---|
| **Semi 1** | 3rd in rsp | 13th in hts | 7th in hts | 1st in hts | 6th in hts | 12th in hts | 2nd in rps | 8th in rps |
| **Semi 2** | 4th in rps | 14th in hts | 8th in hts | 2nd in hts | 5th in hts | 11th in hts | 1st in rps | 7th in rps |
| **Semi 3** | 5th in rps | 15th in hts | 9th in hts | 3rd in hts | 4th in hts | 10th in hts | 16th in hts | 6th in rsp |

### Grand Final lane seeding

| Lane → | L1 | L2 | L3 | L4 | L5 | L6 | L7 | L8 |
|---|---|---|---|---|---|---|---|---|
| **Final 1** | 7th in SF | 5th in SF | 3rd in SF | 1st in SF | 2nd in SF | 4th in SF | 6th in SF | 8th in SF |

### Minor Final lane seeding

| Lane → | L1 | L2 | L3 | L4 | L5 | L6 | L7 | L8 |
|---|---|---|---|---|---|---|---|---|
| **Minor 1** | 15th in SF | 13th in SF | 11th in SF | 9th in SF | 10th in SF | 12th in SF | 14th in SF | 16th in SF |

### Advancement

- 1st, 2nd and 3rd in each heat plus next fastest overall → semi-finals (16 crews).
- Remainder → repechage (17 to 40).
- 1st and 2nd in each repechage plus next 2 fastest → semi-finals (8 crews).
- Remainder drop out (25 to 40).
- 1st and 2nd in each semi plus next 2 fastest → Grand Final (8 crews).
- Next 8 fastest → Minor Final.


## `RP.7B`

- **Lanes**: 8
- **Crew count**: 41–48 crews
- **Stages**: Heat 1 → Heat 2 → Heat 3 → Heat 4 → Heat 5 → Heat 6 → Repechage 1 → Repechage 2 → Repechage 3 → Repechage 4 → Semi 1 → Semi 2 → Semi 3 → Semi 4 → Tail Final → Minor Final → Grand Final
- **Total races**: 17

### Heat lane seeding (seed numbers)

| Lane → | L1 | L2 | L3 | L4 | L5 | L6 | L7 | L8 |
|---|---|---|---|---|---|---|---|---|
| **Heat 1** | 37 | 25 | 13 | 1 | 12 | 24 | 36 | 48 |
| **Heat 2** | 38 | 26 | 14 | 2 | 11 | 23 | 35 | 47 |
| **Heat 3** | 39 | 27 | 15 | 3 | 10 | 22 | 34 | 46 |
| **Heat 4** | 40 | 28 | 16 | 4 | 9 | 21 | 33 | 45 |
| **Heat 5** | 41 | 29 | 17 | 5 | 8 | 20 | 32 | 44 |
| **Heat 6** | 42 | 30 | 18 | 6 | 7 | 19 | 31 | 43 |

### Repechage lane seeding

| Lane → | L1 | L2 | L3 | L4 | L5 | L6 | L7 | L8 |
|---|---|---|---|---|---|---|---|---|
| **Rep 1** | 47 | 39 | 31 | 23 | 30 | 38 | 46 | — |
| **Rep 2** | 48 | 40 | 32 | 24 | 29 | 37 | 45 | — |
| **Rep 3** | — | 41 | 33 | 25 | 28 | 36 | 44 | — |
| **Rep 4** | — | 42 | 34 | 26 | 27 | 35 | 43 | — |

### Semi-final lane seeding

| Lane → | L1 | L2 | L3 | L4 | L5 | L6 | L7 | L8 |
|---|---|---|---|---|---|---|---|---|
| **Semi 1** | 3rd in rps | 17th in hts | 9th in hts | 1st in hts | 8th in hts | 16th in hts | 2nd in rps | 10th in rsp |
| **Semi 2** | 4th in rps | 18th in hts | 10th in hts | 2nd in hts | 7th in hts | 15th in hts | 1st in rps | 9th in rps |
| **Semi 3** | 5th in rps | 19th in hts | 11th in hts | 3rd in hts | 6th in hts | 14th in hts | 22nd in hts | 8th in rps |
| **Semi 4** | 6th in rsp | 20th in hts | 12th in hts | 4th in hts | 5th in hts | 13th in hts | 21st in hts | 7th in rps |

### Grand Final lane seeding

| Lane → | L1 | L2 | L3 | L4 | L5 | L6 | L7 | L8 |
|---|---|---|---|---|---|---|---|---|
| **Final 1** | 7th in SF | 5th in SF | 3rd in SF | 1st in SF | 2nd in SF | 4th in SF | 6th in SF | 8th in SF |

### Minor Final lane seeding

| Lane → | L1 | L2 | L3 | L4 | L5 | L6 | L7 | L8 |
|---|---|---|---|---|---|---|---|---|
| **Minor 1** | 15th in SF | 13th in SF | 11th in SF | 9th in SF | 10th in SF | 12th in SF | 14th in SF | 16th in SF |

### Tail Final lane seeding

| Lane → | L1 | L2 | L3 | L4 | L5 | L6 | L7 | L8 |
|---|---|---|---|---|---|---|---|---|
| **Tail 1** | 23rd in SF | 21st in SF | 19th in SF | 17th in SF | 18th in SF | 20th in SF | 22nd in SF | 24th in SF |

### Advancement

- 1st, 2nd and 3rd in each heat plus next 4 fastest overall → semi-finals (22 crews).
- Remainder → repechage (23 to 48).
- 1st and 2nd in each repechage plus next 2 fastest → semi-finals (10 crews).
- Remainder drop out (33 to 48).
- 1st in each semi plus next 4 fastest → Grand Final (8 crews).
- Next 8 fastest → Minor Final.
- Next 8 fastest → Tail Final.


