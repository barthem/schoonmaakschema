<?php
// schoonmaak.php
// - Redirect naar selecteer.php als geen persoon in sessie.
// - Week-per-week statusbestanden: status_<week>.json
// - Bij binnenkomst in een nieuwe week: finaliseer ALLE eerdere weken die nog open staan.
//   * Finaliseren telt boetes PER PERSOON PER WEEK maximaal 1x (niet per taak).
//   * We finaliseren alleen bestaande status-bestanden; we maken geen oude aan.
// - Voor de huidige week: status_<week>.json bestaat/wordt aangemaakt met alle taken (assigned_to, done=false).
// - Afvinken zet done=true + done_by/done_at in status_<week>.json.
// - Roulatie is nu EERLIJK/gebalanceerd obv vaste startdatum: vaste taken eerst, niet‑vaste naar laagste load met roterende tie-break.
// - NIEUW: Support voor biweekly taken via frequency attribuut
// - NIEUW: Pinned badge voor taken met fixed_to

declare(strict_types=1);
session_start();

/* ========== Config ========== */
$peopleFile = __DIR__ . '/mensen.json';
$tasksFile  = __DIR__ . '/taken.json';
$statusDir  = __DIR__ . '/status';

// Maak statusmap aan als die nog niet bestaat
if (!is_dir($statusDir)) {
    mkdir($statusDir, 0755, true);
}

// Vaste start (bijv. een vrijdag). Weekindex = floor(days/7) vanaf deze datum.
$startDate  = new DateTime('2025-09-06'); // <-- pas aan naar jullie echte startdatum. 05-09 is een vrijdag, 06-09 is een zaterdag

/* ========== Helpers ========== */
function statusPath(int $week): string {
    global $statusDir;
    return $statusDir . "/status_{$week}.json";
}

function readJson(string $path, $fallback) {
    if (!is_file($path)) return $fallback;
    $raw = file_get_contents($path);
    $data = json_decode($raw, true);
    return is_array($data) ? $data : $fallback;
}
function writeJson(string $path, $data): void {
    file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
}
function weekIndex(DateTime $start, DateTime $today): int {
    $days = (int)$start->diff($today)->days;
    return intdiv(max(0, $days), 7);
}

/**
 * Check of een taak actief is in een bepaalde week
 * Voor biweekly: kies even weken (0,2,4...) of oneven weken (1,3,5...)
 */
function isTaskActiveInWeek(array $task, int $week): bool {
    $frequency = $task['frequency'] ?? 'weekly';

    if ($frequency === 'biweekly') {
        // Tweewekelijks: alleen in oneven weken (1,3,5,7...)
        return ($week % 2) === 1;
    }

    if ($frequency === 'monthly') {
        // Maandelijks: elke 4 weken (week 0, 4, 8, 12...)
        return ($week % 4) === 0;
    }

    // Default: weekly
    return true;
}

/**
 * Filter subtasks voor een bepaalde week op basis van hun frequency.
 * Subtasks kunnen strings (legacy) of objecten {name, frequency} zijn.
 * Bij strings wordt de frequency van de parent-taak overgenomen.
 */
function filterSubtasksForWeek(array $subtasks, int $week, string $parentFrequency = 'weekly'): array {
    $active = [];
    foreach ($subtasks as $sub) {
        // Backward compat: als het een string is, maak er een object van
        if (is_string($sub)) {
            $sub = ['name' => $sub, 'frequency' => $parentFrequency];
        }
        $freq = $sub['frequency'] ?? $parentFrequency;
        if ($freq === 'biweekly') {
            if (($week % 2) !== 1) continue;
        }
        if ($freq === 'monthly') {
            if (($week % 4) !== 0) continue;
        }
        $active[] = $sub;
    }
    return $active;
}

/**
 * Filter taken voor een specifieke week op basis van frequency
 */
function getActiveTasksForWeek(array $tasks, int $week): array {
    return array_filter($tasks, fn($task) => isTaskActiveInWeek($task, $week));
}

/**
 * Bereken totaal potje van alle personen
 */
function calculateTotalPot(array $people): int {
    $total = 0;
    foreach ($people as $person) {
        $missed = (int)($person['missed'] ?? 0);
        $total += $missed * 5;
    }
    return $total;
}

/**
 * Normaliseer fixed_to naar een array van geldige personen.
 * - null/leeg/"" → []  (geen restrictie, iedereen mag)
 * - "Bart"       → ["Bart"]  (gepind aan 1 persoon)
 * - ["Bart","Erin"] → gefilterd op geldige personen
 */
function normalizeFixedTo($fixedTo, array $people): array {
    if (is_array($fixedTo)) {
        return array_values(array_filter($fixedTo, fn($p) => is_string($p) && $p !== '' && isset($people[$p])));
    }
    if (is_string($fixedTo) && $fixedTo !== '' && isset($people[$fixedTo])) {
        return [$fixedTo];
    }
    return [];
}

/**
 * Gebalanceerde roulatie met taak-variatie:
 * - Single-fixed (1 persoon) eerst — geen keuze, telt direct mee in load.
 * - Multi-fixed (array van 2+) — kies uit die kandidaten met load-balancing + history tie-break.
 * - Niet-vaste taken — kies uit alle personen met load-balancing + history tie-break.
 *
 * LET OP: Deze functie krijgt al gefilterde taken (alleen actieve taken voor deze week)
 */
function assignedForWeekBalanced(array $people, array $tasks, int $week): array {
    $personKeys = array_keys($people);
    $n = max(1, count($personKeys));

    $load = array_fill_keys($personKeys, 0);
    $assigned = [];
    $singleFixedIdx = [];
    $multiFixedIdx = [];   // taken gepind tussen 2+ personen
    $unfixedIdx = [];

    // Indeel taken op basis van fixed_to
    foreach ($tasks as $i => $t) {
        $cands = normalizeFixedTo($t['fixed_to'] ?? null, $people);
        if (count($cands) === 1) {
            $singleFixedIdx[] = $i;
        } elseif (count($cands) >= 2) {
            $multiFixedIdx[] = $i;
        } else {
            $unfixedIdx[] = $i;
        }
    }

    // 1) Single-fixed direct toewijzen + load tellen
    foreach ($singleFixedIdx as $i) {
        $cands = normalizeFixedTo($tasks[$i]['fixed_to'], $people);
        $p = $cands[0];
        $assigned[$i] = $p;
        $load[$p]++;
    }

    // 2) Geschiedenis van vorige week(en) voor taak-variatie
    $previousWeekHistory = [];
    if ($week > 0) {
        for ($lookback = 1; $lookback <= 2; $lookback++) {
            $prevWeek = $week - $lookback;
            if ($prevWeek < 0) break;

            $prevPath = statusPath($prevWeek);
            $prevStatus = readJson($prevPath, []);
            foreach ($prevStatus as $taskName => $taskData) {
                if (strpos($taskName, '__') !== 0 && isset($taskData['assigned_to'])) {
                    if (!isset($previousWeekHistory[$taskName])) {
                        $previousWeekHistory[$taskName] = $taskData['assigned_to'];
                    }
                }
            }
        }
    }

    // 3) Week-rotatie voor tie-breaks (deterministisch per week)
    $rot = [];
    for ($k = 0; $k < $n; $k++) {
        $rot[] = $personKeys[($week + $k) % $n];
    }
    $rank = [];
    foreach ($rot as $pos => $pk) $rank[$pk] = $pos;

    // Helper: kies kandidaat uit een set met laagste load + history-aware tie-break
    $pickCandidate = function(array $candidates, ?string $lastAssignedTo) use (&$load, $rank): string {
        $loadsForCands = array_intersect_key($load, array_flip($candidates));
        $minLoad = min($loadsForCands);
        $minCands = array_keys(array_filter($loadsForCands, fn($v) => $v === $minLoad));
        $preferred = array_values(array_filter($minCands, fn($p) => $p !== $lastAssignedTo));
        $finalCands = !empty($preferred) ? $preferred : $minCands;
        usort($finalCands, fn($a, $b) => $rank[$a] <=> $rank[$b]);
        return $finalCands[0];
    };

    // 4) Multi-fixed: kies uit beperkte kandidatenset
    foreach ($multiFixedIdx as $i) {
        $taskName = $tasks[$i]['name'];
        $cands = normalizeFixedTo($tasks[$i]['fixed_to'], $people);
        $chosen = $pickCandidate($cands, $previousWeekHistory[$taskName] ?? null);
        $assigned[$i] = $chosen;
        $load[$chosen]++;
    }

    // 5) Niet-vaste taken: alle personen als kandidaat
    foreach ($unfixedIdx as $i) {
        $taskName = $tasks[$i]['name'];
        $chosen = $pickCandidate($personKeys, $previousWeekHistory[$taskName] ?? null);
        $assigned[$i] = $chosen;
        $load[$chosen]++;
    }

    ksort($assigned, SORT_NUMERIC);
    return $assigned;
}

/**
 * Haal carry-over biweekly taken op van de vorige week
 */
function getCarryoverTasks(int $currentWeek, array $allTasks): array {
    if ($currentWeek <= 0) return [];
    
    $prevPath = statusPath($currentWeek - 1);
    $prevStatus = readJson($prevPath, []);
    
    $carryoverNames = $prevStatus['__carryover_biweekly'] ?? [];
    if (empty($carryoverNames)) return [];
    
    // Zoek de taak-definities op in allTasks
    $carryoverTasks = [];
    foreach ($allTasks as $task) {
        if (in_array($task['name'], $carryoverNames, true)) {
            // Markeer als carryover zodat we het kunnen tonen in de UI
            $task['is_carryover'] = true;
            $carryoverTasks[] = $task;
        }
    }
    
    return $carryoverTasks;
}

/**
 * Zorg dat er een statusbestand is voor de huidige week
 * GEFIXED: Filter taken op frequency VOOR toewijzing
 * NIEUW: Voeg carry-over biweekly taken toe van vorige week
 */
function ensureCurrentWeekStatus(string $path, array $people, array $allTasks, int $week): array {
    // Zorg dat er een statusbestand is voor de huidige week; zo niet, maak het aan met alleen actieve taken.
    $status = readJson($path, []);
    // Check of er ECHTE taken in zitten (niet alleen meta-keys zoals __finalized)
    $hasTasks = false;
    foreach ($status as $key => $val) {
        if (strpos($key, '__') !== 0) { $hasTasks = true; break; }
    }
    if (!$hasTasks) {
        // Filter taken op basis van frequency (weekly vs biweekly)
        $activeTasks = getActiveTasksForWeek($allTasks, $week);
        
        // Haal carry-over biweekly taken op van vorige week
        $carryoverTasks = getCarryoverTasks($week, $allTasks);
        
        // Voeg carry-over taken toe (alleen als ze niet al in activeTasks zitten)
        $activeTaskNames = array_column($activeTasks, 'name');
        foreach ($carryoverTasks as $ct) {
            if (!in_array($ct['name'], $activeTaskNames, true)) {
                $activeTasks[] = $ct;
            }
        }
        
        // Herindex de array voor correcte toewijzing (zodat indices 0,1,2... zijn)
        $activeTasks = array_values($activeTasks);
        
        // Wijs actieve taken toe aan personen met load balancing
        $assigned = assignedForWeekBalanced($people, $activeTasks, $week);
        
        // Maak status-entries voor alle actieve taken
        // Subtasks worden uitgevouwen als losse taken met dezelfde assigned_to
        $status = ['__finalized' => false];
        foreach ($activeTasks as $i => $t) {
            $owner = $assigned[$i] ?? null;
            $subtasks = filterSubtasksForWeek($t['subtasks'] ?? [], $week, $t['frequency'] ?? 'weekly');
            
            if (!empty($subtasks)) {
                // Taak met subtaken: elke subtaak wordt een losse entry
                // Naam wordt "Hoofdtaak - Subtaak" voor context
                $parentName = $t['name'];
                foreach ($subtasks as $sub) {
                    $subName = is_string($sub) ? $sub : ($sub['name'] ?? '');
                    if ($subName === '') continue;
                    $subFreq = is_string($sub) ? ($t['frequency'] ?? 'weekly') : ($sub['frequency'] ?? $t['frequency'] ?? 'weekly');
                    $displayName = $parentName . ' - ' . $subName;
                    $status[$displayName] = [
                        'assigned_to'  => $owner,
                        'done'         => false,
                        'frequency'    => $subFreq,
                        'fixed_to'     => $t['fixed_to'] ?? null,
                        'is_carryover' => $t['is_carryover'] ?? false
                    ];
                }
            } else {
                // Taak zonder subtaken: gewoon als entry
                $status[$t['name']] = [
                    'assigned_to'  => $owner,
                    'done'         => false,
                    'frequency'    => $t['frequency'] ?? 'weekly',
                    'fixed_to'     => $t['fixed_to'] ?? null,
                    'is_carryover' => $t['is_carryover'] ?? false
                ];
            }
        }
        writeJson($path, $status);
    }
    return $status;
}

function allStatusIndices(): array {
    global $statusDir;
    $files = glob($statusDir . '/status_*.json');
    $out = [];
    foreach ($files ?: [] as $f) {
        if (preg_match('~/status_(\d+)\.json$~', $f, $m)) {
            $out[] = (int)$m[1];
        }
    }
    sort($out, SORT_NUMERIC);
    return $out;
}

/* ========== Data laden ========== */
$people = readJson($peopleFile, []);
$tasks  = readJson($tasksFile, []);

/* ========== Persoon (sessie) ========== */
// vanuit selecteer.php kan person per POST gezet worden
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['person']) && isset($people[$_POST['person']])) {
    $_SESSION['person'] = $_POST['person'];
}
// wisselen/uitloggen
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (($_POST['action'] ?? '') === 'logout')) {
    unset($_SESSION['person']);
    header('Location: selecteer.php');
    exit;
}

// persoon ophalen
$personKey = $_SESSION['person'] ?? null;
// 👉 redirect als geen persoon of ongeldige persoon
if (!$personKey || !isset($people[$personKey])) {
    header('Location: selecteer.php');
    exit;
}

/* ========== Week & status ========== */
$today   = new DateTime('today');
$wIndex  = weekIndex($startDate, $today);
$curPath = statusPath($wIndex);

/* ========== Backfill gemiste weken: maak statusbestanden aan voor weken die niemand bezocht heeft ========== */
// Vind de hoogste bestaande weekindex (of -1 als er geen zijn)
$existingIndices = allStatusIndices();
$lastKnownWeek = empty($existingIndices) ? -1 : max($existingIndices);

// Maak statusbestanden aan voor alle weken tussen de laatst bekende en de huidige week
// Safety cap: maximaal 52 weken terugkijken om performance issues te voorkomen
$backfillStart = max(0, $lastKnownWeek + 1, $wIndex - 52);
for ($w = $backfillStart; $w < $wIndex; $w++) {
    $backfillPath = statusPath($w);
    if (!is_file($backfillPath)) {
        // Maak een statusbestand aan met taken voor die week, zodat finalisatie boetes kan tellen
        ensureCurrentWeekStatus($backfillPath, $people, $tasks, $w);
    }
}

/* ========== Finaliseer ALLE vorige weken die nog open staan (boete 1x p.p. p.week) ========== */
$indices = allStatusIndices();
foreach ($indices as $idx) {
    if ($idx >= $wIndex) break; // alleen oudere weken
    $path = statusPath($idx);
    $st   = readJson($path, []);
    if (empty($st) || !empty($st['__finalized'])) {
        continue; // niks te doen of al afgerond
    }

    $weekMissed = []; // personKey => true (max 1 boete per week)
    $carryoverBiweekly = []; // niet-afgeronde biweekly taken voor volgende week
    
    foreach ($st as $taskName => $rec) {
        if (strpos($taskName, '__') === 0) continue; // meta overslaan
        $assignedTo = $rec['assigned_to'] ?? null;
        $done       = (bool)($rec['done'] ?? false);
        $frequency  = $rec['frequency'] ?? 'weekly';
        
        if ($assignedTo && !$done) {
            $weekMissed[$assignedTo] = true;
            
            // Biweekly/monthly taken die niet af zijn: doorschuiven naar volgende week
            if ($frequency === 'biweekly' || $frequency === 'monthly') {
                $carryoverBiweekly[] = $taskName;
            }
        }
    }

    $changedPeople = false;
    foreach (array_keys($weekMissed) as $p) {
        if (isset($people[$p])) {
            $people[$p]['missed'] = (int)($people[$p]['missed'] ?? 0) + 1;
            $changedPeople = true;
        }
    }

    // Sla carry-over biweekly taken op
    if (!empty($carryoverBiweekly)) {
        $st['__carryover_biweekly'] = $carryoverBiweekly;
    }
    
    $st['__finalized'] = date('Y-m-d H:i:s');
    writeJson($path, $st);
    if ($changedPeople) {
        writeJson($peopleFile, $people);
    }
}

/* ========== Zorg dat huidig weekbestand er is ========== */
$status = ensureCurrentWeekStatus($curPath, $people, $tasks, $wIndex);

/* ========== Actie: afvinken ========== */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (($_POST['action'] ?? '') === 'done')) {
    $taskName = (string)($_POST['task'] ?? '');
    if ($taskName !== '' && isset($status[$taskName])) {
        // alleen toegewezen persoon mag afvinken
        if (($status[$taskName]['assigned_to'] ?? null) === $personKey) {
            $status[$taskName]['done']    = true;
            $status[$taskName]['done_by'] = $personKey;
            $status[$taskName]['done_at'] = date('Y-m-d H:i:s');
            writeJson($curPath, $status);
        }
    }
    header('Location: schoonmaak.php'); // PRG
    exit;
}

/* ========== Taken van huidige persoon (huidige week) ========== */
$myTasks = [];
foreach ($status as $name => $rec) {
    if (strpos($name, '__') === 0) continue; // meta overslaan
    if (($rec['assigned_to'] ?? null) === $personKey) {
        $myTasks[] = [
            'name'        => $name,
            'done'        => (bool)($rec['done'] ?? false),
            'done_at'     => $rec['done_at'] ?? null,
            'frequency'   => $rec['frequency'] ?? 'weekly',
            'fixed_to'    => $rec['fixed_to'] ?? null,
            'is_carryover' => (bool)($rec['is_carryover'] ?? false)
        ];
    }
}

/* ========== Vorige week (alle taken + wie afvinkte) ========== */
$showPrevious = isset($_GET['view']) && $_GET['view'] === 'vorige';
$prevWeekIndex = $wIndex - 1;
$prevTasks = [];
$prevHasData = false;
if ($showPrevious && $prevWeekIndex >= 0) {
    $prevPath = statusPath($prevWeekIndex);
    if (is_file($prevPath)) {
        $prevHasData = true;
        $prevStatusRaw = readJson($prevPath, []);
        foreach ($prevStatusRaw as $name => $rec) {
            if (strpos($name, '__') === 0) continue;
            $prevTasks[] = [
                'name'        => $name,
                'assigned_to' => $rec['assigned_to'] ?? null,
                'done'        => (bool)($rec['done'] ?? false),
                'done_by'     => $rec['done_by'] ?? null,
                'done_at'     => $rec['done_at'] ?? null,
                'frequency'   => $rec['frequency'] ?? 'weekly',
                'fixed_to'    => $rec['fixed_to'] ?? null,
            ];
        }
        // Sorteer op persoon zodat overzicht groepeert
        usort($prevTasks, function($a, $b) {
            return strcmp((string)($a['assigned_to'] ?? ''), (string)($b['assigned_to'] ?? ''))
                ?: strcmp($a['name'], $b['name']);
        });
    }
}

// Bereken totalen
$meName = htmlspecialchars($people[$personKey]['name']);
$missed = (int)($people[$personKey]['missed'] ?? 0);
$myPot = $missed * 5;
$totalPot = calculateTotalPot($people);
?>
<!DOCTYPE html>
<html lang="nl">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width,initial-scale=1" />
    <title><?= $meName ?> · Schoonmaaktaken</title>
    <style>
        :root { --bg:#f4f6fb; --card:#fff; --text:#1f2937; --muted:#6b7280; --accent:#2563eb; --ok:#16a34a; --shadow:0 2px 10px rgba(0,0,0,.08); }
        *{box-sizing:border-box}
        body{margin:0;font-family:system-ui,-apple-system,Segoe UI,Roboto,Arial,sans-serif;background:var(--bg);color:var(--text);padding:18px}
        header{max-width:980px;margin:0 auto 16px;display:flex;flex-wrap:wrap;align-items:center;gap:10px;justify-content:space-between}
        h1{font-size:1.4rem;margin:0}
        .muted{color:var(--muted)}
        .pill{display:inline-block;padding:.25rem .6rem;border-radius:999px;background:#eef2ff;color:#3730a3;font-weight:600}
        .pill-biweekly{background:#fef3c7;color:#92400e}
        .pill-monthly{background:#dbeafe;color:#1e40af}
        .pill-pinned{background:#fce7f3;color:#831843}
        .pill-carryover{background:#fee2e2;color:#991b1b}
        .row{max-width:980px;margin:0 auto}
        .grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:14px}
        .card{background:var(--card);border-radius:12px;box-shadow:var(--shadow);padding:14px}
        .card h3{margin:.2rem 0 .4rem;font-size:1.05rem}
        .done{background:#dcfce7}
        .actions{margin-top:10px;display:flex;gap:8px;flex-wrap:wrap}
        button, .btn { padding:10px 12px;border:none;border-radius:10px;background:var(--accent);color:#fff;font-weight:700;cursor:pointer;transition:transform .12s ease, box-shadow .12s ease, background .12s ease;text-decoration:none;display:inline-block }
        button:hover{transform:translateY(-1px);box-shadow:0 6px 18px rgba(0,0,0,.12)}
        .btn-secondary{background:#e5e7eb;color:#111827}
        .money{font-weight:800;color:#dc2626}
        .money-big{font-size:1.2rem;color:#dc2626}
        .bar{display:flex;gap:10px;align-items:center;flex-wrap:wrap}
        .pot-cards{display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:14px;margin-bottom:16px}
        .frequency-badge{display:inline-block;font-size:.8rem;margin-left:6px}
        @media (max-width:520px){body{padding:14px}}
        .note{margin-top:10px;color:var(--muted);font-size:.92rem}
    </style>
</head>
<body>
<?php if ($showPrevious): ?>
    <header>
        <div>
            <h1>Vorige week · week <?= (int)$prevWeekIndex ?></h1>
            <div class="muted">Wat was er en wie heeft het afgevinkt?</div>
        </div>
        <div class="bar">
            <a href="schoonmaak.php" class="btn btn-secondary">← Terug naar deze week</a>
            <a href="index.php" class="btn btn-secondary">Menu</a>
        </div>
    </header>

    <div class="row">
        <?php if (!$prevHasData): ?>
            <div class="card">
                <h3>Geen data voor vorige week</h3>
                <p class="muted">Er bestaat nog geen statusbestand voor week <?= (int)$prevWeekIndex ?>.</p>
            </div>
        <?php else: ?>
            <div class="grid">
                <?php foreach ($prevTasks as $t):
                    $assignedName = $t['assigned_to'] && isset($people[$t['assigned_to']]) ? $people[$t['assigned_to']]['name'] : ($t['assigned_to'] ?? '—');
                    $doneByName   = $t['done_by'] && isset($people[$t['done_by']]) ? $people[$t['done_by']]['name'] : $t['done_by'];
                ?>
                    <div class="card <?= $t['done'] ? 'done' : '' ?>">
                        <h3>
                            <?= htmlspecialchars($t['name']) ?>
                            <?php if (!empty($t['fixed_to'])): ?>
                                <span class="pill pill-pinned frequency-badge">📌 Gepind</span>
                            <?php endif; ?>
                            <?php if (($t['frequency'] ?? 'weekly') === 'biweekly'): ?>
                                <span class="pill pill-biweekly frequency-badge">2-wekelijks</span>
                            <?php endif; ?>
                            <?php if (($t['frequency'] ?? 'weekly') === 'monthly'): ?>
                                <span class="pill pill-monthly frequency-badge">Maandelijks</span>
                            <?php endif; ?>
                        </h3>
                        <div class="muted">Toegewezen aan: <strong><?= htmlspecialchars($assignedName) ?></strong></div>
                        <?php if ($t['done']): ?>
                            <div style="margin-top:6px">
                                ✅ Afgevinkt door <strong><?= htmlspecialchars($doneByName ?? $assignedName) ?></strong>
                                <?= $t['done_at'] ? ' op <strong>'.htmlspecialchars($t['done_at']).'</strong>' : '' ?>
                            </div>
                        <?php else: ?>
                            <div style="margin-top:6px;color:#dc2626;font-weight:600">❌ Niet afgevinkt</div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
<?php else: ?>
    <header>
        <div>
            <h1><?= $meName ?> · jouw taken</h1>
            <div class="muted">Weekindex: <span class="pill"><?= (int)$wIndex ?></span> · Start: <?= $startDate->format('Y-m-d') ?></div>
        </div>
        <div class="bar">
            <a href="schoonmaak.php?view=vorige" class="btn btn-secondary">📅 Vorige week</a>
            <form method="post">
                <input type="hidden" name="action" value="logout">
                <button type="submit" class="btn btn-secondary">Wissel persoon</button>
            </form>
            <a href="index.php" class="btn btn-secondary">Menu</a>
        </div>
    </header>

    <div class="row">
        <div class="pot-cards">
            <div class="card">
                <div><strong>Jouw pot-stand</strong></div>
                <div class="muted">Gemiste weken: <?= $missed ?></div>
                <div class="money">€<?= $myPot ?></div>
            </div>
            <div class="card" style="background:linear-gradient(135deg, #fee2e2, #fef2f2);">
                <div><strong>🏆 Totale pot</strong></div>
                <div class="muted">Alle huisgenoten samen</div>
                <div class="money money-big">€<?= $totalPot ?></div>
            </div>
        </div>
    </div>

    <div class="row">
        <div class="grid">
            <?php if (empty($myTasks)): ?>
                <div class="card">
                    <h3>Geen taken voor jou deze week</h3>
                    <p class="muted">Lekker bezig — of je staat volgende week weer op de lijst.</p>
                </div>
            <?php else: ?>
                <?php foreach ($myTasks as $t): ?>
                    <div class="card <?= $t['done'] ? 'done' : '' ?>">
                        <h3>
                            <?= htmlspecialchars($t['name']) ?>
                            <?php if (!empty($t['fixed_to'])): ?>
                                <span class="pill pill-pinned frequency-badge">📌 Gepind</span>
                            <?php endif; ?>
                            <?php if (($t['frequency'] ?? 'weekly') === 'biweekly'): ?>
                                <span class="pill pill-biweekly frequency-badge">2-wekelijks</span>
                            <?php endif; ?>
                            <?php if (($t['frequency'] ?? 'weekly') === 'monthly'): ?>
                                <span class="pill pill-monthly frequency-badge">Maandelijks</span>
                            <?php endif; ?>
                            <?php if (!empty($t['is_carryover'])): ?>
                                <span class="pill pill-carryover frequency-badge">⏩ Doorgeschoven</span>
                            <?php endif; ?>
                        </h3>
                        <?php if ($t['done']): ?>
                            <div>✅ Afgevinkt<?= $t['done_at'] ? ' op <strong>'.htmlspecialchars($t['done_at']).'</strong>' : '' ?></div>
                        <?php else: ?>
                            <form method="post" class="actions">
                                <input type="hidden" name="action" value="done">
                                <input type="hidden" name="task"   value="<?= htmlspecialchars($t['name']) ?>">
                                <button type="submit">Afvinken</button>
                            </form>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
<?php endif; ?>

</body>
</html>