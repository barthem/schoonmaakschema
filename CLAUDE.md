# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

A PHP web app for managing household chores between housemates (Bart, Erin, Mara). Two modules: **schoonmaak** (cleaning task rotation) and **afwas** (dishes rotation). No framework, no database, no build step — pure PHP with JSON file storage.

## Running the App

```bash
php -S localhost:8000
# Open http://localhost:8000/index.php
```

Requires PHP 7.4+. The `status/` directory is created automatically and is gitignored.

## Architecture

**Single-file PHP pages** — each page is a self-contained PHP file with embedded HTML/CSS (no templates, no JS):

- `index.php` — Menu routing to schoonmaak or afwas
- `selecteer.php` — Person picker (POST to schoonmaak.php)
- `schoonmaak.php` — Core logic: task assignment, frequency filtering, finalization, penalty system, and task UI
- `afwas.php` — Standalone dishes roster with ISO week-based pattern rotation (A/B even/odd weeks)

**JSON as database** — all state lives in flat JSON files:

- `mensen.json` — People config + penalty counter (`missed`)
- `taken.json` — Task definitions with frequency, fixed assignment, and subtasks
- `status/status_{weekIndex}.json` — Per-week task assignments and completion state (gitignored)

## Core Concepts in schoonmaak.php

**Week indexing**: Weeks are numbered from a fixed `$startDate` (2025-09-06). `weekIndex = floor(daysSinceStart / 7)`. This is NOT ISO week numbering — it's a custom rolling counter used for frequency math and rotation.

**Frequency system**: Tasks and subtasks have a `frequency` field:
- `weekly` — every week (default)
- `biweekly` — odd weeks only (`$week % 2 === 1`)
- `monthly` — every 4 weeks (`$week % 4 === 0`)

Frequency filtering happens in `isTaskActiveInWeek()` and `filterSubtasksForWeek()`. Both functions must stay in sync when adding new frequencies.

**Task assignment flow** (in `ensureCurrentWeekStatus()`):
1. Filter tasks by frequency for current week
2. Add carry-over tasks from previous week (incomplete biweekly/monthly tasks)
3. Assign via `assignedForWeekBalanced()`: fixed tasks first, then load-balance unfixed tasks with history-aware tie-breaking
4. Expand subtasks into individual status entries (format: `"Parent - Subtask"`)
5. Write to `status_{week}.json`

**Week finalization**: When a user visits and a previous week's status exists but isn't finalized:
- Count one penalty per person per week (not per task) for any incomplete task
- Save incomplete biweekly/monthly tasks to `__carryover_biweekly` for next week
- Increment `missed` counter in `mensen.json` (penalty = missed * 5 euros)

**Backfill**: On visit, the app creates status files for all missing weeks between the last known week and today (capped at 52 weeks), ensuring penalties are calculated even if nobody visited for weeks.

**Status file meta-keys**: Keys starting with `__` are metadata, not tasks:
- `__finalized` — false or timestamp string
- `__carryover_biweekly` — array of task names to carry forward

## Key Constraints

- Subtask names must be globally unique across all tasks (they become flat status keys)
- Status files are written on first visit per week and never regenerated — changing `taken.json` mid-week won't affect the current week
- `afwas.php` is completely independent from the schoonmaak system (different rotation logic, no JSON state files, uses ISO weeks)
- All pages use inline `<style>` — there are no external CSS/JS assets
- The app is in Dutch; keep all UI text in Dutch
