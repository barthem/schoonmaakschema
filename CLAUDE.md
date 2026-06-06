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
- `beheer.php` — Hidden passcode-protected admin (passcode constant `PASSCODE`): CRUD for tasks/subtasks, people, the money pot/streaks, and the penalty-mode toggle. Also reads/writes `taken.json` and `mensen.json`
- `afwas.php` — Standalone dishes roster: hardcoded A/B wash-dry patterns that alternate by ISO-week parity (A = even week, B = odd week), fixed weekend pairs (Mara excluded), `?week=N` offset navigation, `Europe/Amsterdam` timezone

**JSON as database** — all state lives in flat JSON files:

- `mensen.json` — People config + two penalty counters: `missed` (cumulative weeks missed) and `streak` (consecutive weeks missed). Optional per-person `hide_streak` bool (absent = false) hides the streak cards on that person's own task page
- `taken.json` — `{ "mode": "geld" | "vergeten", "taken": [ ...task defs... ] }`. A bare top-level array is still accepted as legacy (= `geld` mode). Task defs have frequency, fixed assignment, and subtasks
- `status/status_{weekIndex}.json` — Per-week task assignments and completion state (gitignored)

## Core Concepts in schoonmaak.php

**Week indexing**: Weeks are numbered from a fixed `$startDate` (2026-05-30, the Saturday the current rota started; weekIndex 0 begins there). `weekIndex = floor(daysSinceStart / 7)`. This is NOT ISO week numbering — it's a custom rolling counter used for frequency math and rotation.

**Frequency system**: Tasks and subtasks have a `frequency` field:
- `weekly` — every week (default)
- `biweekly` — odd weeks only (`$week % 2 === 1`)
- `monthly` — every 4 weeks (`$week % 4 === 0`)

Frequency filtering happens in `isTaskActiveInWeek()` and `filterSubtasksForWeek()`. Both functions must stay in sync when adding new frequencies.

Any unrecognized `frequency` value silently falls back to `weekly` (the `=== 'biweekly'` / `=== 'monthly'` checks simply fail and the default applies). Watch for typos — `taken.json` currently has a `"montly"` entry, which is therefore treated as weekly rather than monthly.

**Task assignment flow** (in `ensureCurrentWeekStatus()`):
1. Filter tasks by frequency for current week
2. Add carry-over tasks from previous week (incomplete biweekly/monthly tasks)
3. Assign via `assignedForWeekBalanced()`: fixed tasks first, then load-balance unfixed tasks with history-aware tie-breaking
4. Expand subtasks into individual status entries (format: `"Parent - Subtask"`), all inheriting the parent's single assignee
5. Write to `status_{week}.json`

A task with subtasks is load-balanced as **one unit**: the whole task gets one owner and every subtask card goes to that same person. The parent task is never shown as a card — only its (frequency-filtered) subtasks are.

**Week finalization**: When a user visits and a previous week's status exists but isn't finalized, for each person who had ≥1 task that week:
- If they left any task incomplete: `missed++` (cumulative) **and** `streak++` (consecutive). Penalty is per person per week (max once), not per task
- If they completed all their tasks: `streak` resets to 0 (`missed` is left unchanged)
- Both counters are always updated regardless of the active mode, so switching mode never loses data
- Incomplete biweekly/monthly tasks are saved to `__carryover_biweekly` for next week

**Penalty modes** (`mode` field in `taken.json`, default `geld`): only the UI differs — both counters keep updating either way.
- `geld` — money pot: `missed * 5` euros per person, summed into a collective pot
- `vergeten` — shows each housemate's consecutive-miss `streak` (a "shame board" sorted worst-first), no euros
- Any value other than `vergeten` is treated as `geld`
- Per-person opt-out: if the logged-in person has `hide_streak` true, both the "Jouw reeks" and "Reeks per huisgenoot" cards are skipped in `vergeten` mode (the pot in `geld` mode is unaffected). Toggle per person in `beheer.php` (action `toggle_hide_streak`)

**Backfill**: On visit, the app creates status files for all missing weeks between the last known week and today (capped at 52 weeks), ensuring penalties are calculated even if nobody visited for weeks.

**Status file meta-keys**: Keys starting with `__` are metadata, not tasks:
- `__finalized` — false or timestamp string
- `__carryover_biweekly` — flat status keys to carry forward (despite the name, also holds `monthly` tasks)

## Key Constraints

- A subtask becomes the flat status key `"Parent - Subtask"`, so that **pair** must be unique across all tasks (the same bare subtask name may safely repeat under different parents)
- Subtask entries are keyed `"Parent - Subtask"` in status, but `assignedForWeekBalanced()` (history tie-break) and `getCarryoverTasks()` look tasks up by the bare parent `name`. Net effect: the prior-week history tie-break and biweekly/monthly carry-over only take effect for **subtask-less** tasks — a missed biweekly/monthly *subtask* is recorded for carry-over but never matched back, so it does not reappear
- Status files are written on first visit per week and never regenerated — changing `taken.json` mid-week won't affect the current week
- `taken.json` is read by **both** `schoonmaak.php` and `beheer.php`; both must accept the `{ mode, taken }` wrapper (and the legacy bare array). `beheer.php` must write tasks back through `writeTasks()` so the `mode` field survives every CRUD save — a plain `writeJson($tasksFile, $tasks)` would silently drop the toggle
- `afwas.php` is completely independent from the schoonmaak system (different rotation logic, no JSON state files, uses ISO weeks)
- All pages use inline `<style>` — there are no external CSS/JS assets
- **Dark mode**: every page carries a tiny inline `<head>` script that reads a `theme` cookie (`light`/`dark`, falling back to `prefers-color-scheme`) and sets `data-theme` on `<html>` before paint (no flash); a 🌓 `.theme-toggle` button calls `toggleTheme()` which flips `data-theme` and rewrites the cookie. Dark styling is pure CSS via `html[data-theme="dark"]` overrides (variable-based pages just override their `:root` vars). This script/CSS/button trio is duplicated per page to keep pages self-contained — change them together
- The app is in Dutch; keep all UI text in Dutch
