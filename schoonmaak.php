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

declare(strict_types=1);
session_start();

$allowedIps = ['127.0.0.1', '::1']; // IPv4 en IPv6 loopback toestaan

$clientIp = $_SERVER['REMOTE_ADDR'] ?? '';

if (!in_array($clientIp, $allowedIps, true)) {
    http_response_code(403);
    echo "Toegang geweigerd";
    exit;
}

/* ========== Config ========== */
$peopleFile = __DIR__ . '/mensen.json';
$tasksFile  = __DIR__ . '/taken.json';

// Vaste start (bijv. een vrijdag). Weekindex = floor(days/7) vanaf deze datum.
$startDate  = new DateTime('2025-01-03'); // <-- pas aan naar jullie echte startdatum

/* ========== Helpers ========== */
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
 * Gebalanceerde roulatie:
 * - Vaste taken eerst → verhogen 'load' van die persoon.
 * - Niet-vaste taken één voor één naar de persoon met de laagste load.
 * - Bij gelijke load wint de persoon die in de week-rotatie het vroegst komt (deterministisch).
 */
function assignedForWeekBalanced(array $people, array $tasks, int $week): array {
    $personKeys = array_keys($people);
    $n = max(1, count($personKeys));

    // 1) Loads init
    $load = array_fill_keys($personKeys, 0);
    $assigned = [];
    $unfixedIdx = [];

    // 2) Vaste taken plaatsen + loads tellen
    foreach ($tasks as $i => $t) {
        if (!empty($t['fixed_to'])) {
            $p = $t['fixed_to'];
            $assigned[$i] = $p;
            if (isset($load[$p])) $load[$p]++;
        } else {
            $unfixedIdx[] = $i;
        }
    }

    // 3) Week-rotatie voor tie-breaks (deterministisch per week)
    //    Voorbeeld: week 0 => [p0,p1,p2], week 1 => [p1,p2,p0], ...
    $rot = [];
    for ($k = 0; $k < $n; $k++) {
        $rot[] = $personKeys[($week + $k) % $n];
    }
    $rank = [];
    foreach ($rot as $pos => $pk) $rank[$pk] = $pos;

    // 4) Niet-vaste taken verdelen
    foreach ($unfixedIdx as $i) {
        $minLoad = min($load);
        $candidates = array_keys(array_filter($load, fn($v) => $v === $minLoad, ARRAY_FILTER_USE_BOTH));
        usort($candidates, fn($a, $b) => $rank[$a] <=> $rank[$b]); // wie staat het vroegst in de rotatie
        $chosen = $candidates[0];
        $assigned[$i] = $chosen;
        $load[$chosen]++;
    }

    ksort($assigned, SORT_NUMERIC);
    return $assigned;
}

function ensureCurrentWeekStatus(string $path, array $people, array $tasks, int $week): array {
    // Zorg dat er een statusbestand is voor de huidige week; zo niet, maak het aan met alle actuele taken.
    $status = readJson($path, []);
    if (empty($status)) {
        $assigned = assignedForWeekBalanced($people, $tasks, $week);
        $status = ['__finalized' => false];
        foreach ($tasks as $i => $t) {
            $name  = $t['name'];
            $owner = $assigned[$i] ?? null;
            $status[$name] = [
                'assigned_to' => $owner,
                'done'        => false
            ];
        }
        writeJson($path, $status);
    }
    return $status;
}
function allStatusIndices(): array {
    $files = glob(__DIR__ . '/status_*.json');
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
$curPath = __DIR__ . "/status_{$wIndex}.json";

/* ========== Finaliseer ALLE vorige weken die nog open staan (boete 1x p.p. p.week) ========== */
/*
   Voor elk status_<k>.json met k < huidige week (wIndex):
   - Als __finalized nog niet gezet is:
     * bouw set $weekMissed[personKey] = true voor alle taken met done=false
     * na de loop: voor elke personKey in weekMissed => people[personKey].missed++
     * zet __finalized met timestamp
   We maken GEEN oude statusbestanden aan als ze niet bestaan.
*/
$indices = allStatusIndices();
foreach ($indices as $idx) {
    if ($idx >= $wIndex) break; // alleen oudere weken
    $path = __DIR__ . "/status_{$idx}.json";
    $st   = readJson($path, []);
    if (empty($st) || !empty($st['__finalized'])) {
        continue; // niks te doen of al afgerond
    }

    $weekMissed = []; // personKey => true (max 1 boete per week)
    foreach ($st as $taskName => $rec) {
        if (strpos($taskName, '__') === 0) continue; // meta overslaan
        $assignedTo = $rec['assigned_to'] ?? null;
        $done       = (bool)($rec['done'] ?? false);
        if ($assignedTo && !$done) {
            $weekMissed[$assignedTo] = true;
        }
    }

    $changedPeople = false;
    foreach (array_keys($weekMissed) as $p) {
        if (isset($people[$p])) {
            $people[$p]['missed'] = (int)($people[$p]['missed'] ?? 0) + 1;
            $changedPeople = true;
        }
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
            'name'    => $name,
            'done'    => (bool)($rec['done'] ?? false),
            'done_at' => $rec['done_at'] ?? null
        ];
    }
}

$meName = htmlspecialchars($people[$personKey]['name']);
$missed = (int)($people[$personKey]['missed'] ?? 0);
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
        .row{max-width:980px;margin:0 auto}
        .grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:14px}
        .card{background:var(--card);border-radius:12px;box-shadow:var(--shadow);padding:14px}
        .card h3{margin:.2rem 0 .4rem;font-size:1.05rem}
        .done{background:#dcfce7}
        .actions{margin-top:10px;display:flex;gap:8px;flex-wrap:wrap}
        button, .btn { padding:10px 12px;border:none;border-radius:10px;background:var(--accent);color:#fff;font-weight:700;cursor:pointer;transition:transform .12s ease, box-shadow .12s ease, background .12s ease;text-decoration:none;display:inline-block }
        button:hover{transform:translateY(-1px);box-shadow:0 6px 18px rgba(0,0,0,.12)}
        .btn-secondary{background:#e5e7eb;color:#111827}
        .money{font-weight:800}
        .bar{display:flex;gap:10px;align-items:center;flex-wrap:wrap}
        @media (max-width:520px){body{padding:14px}}
        .note{margin-top:10px;color:var(--muted);font-size:.92rem}
    </style>
</head>
<body>
<header>
    <div>
        <h1><?= $meName ?> · jouw taken</h1>
        <div class="muted">Weekindex: <span class="pill"><?= (int)$wIndex ?></span> · Start: <?= $startDate->format('Y-m-d') ?></div>
    </div>
    <div class="bar">
        <form method="post">
            <input type="hidden" name="action" value="logout">
            <button type="submit" class="btn btn-secondary">Wissel persoon</button>
        </form>
        <a href="main.php" class="btn btn-secondary">Menu</a>
    </div>
</header>

<div class="row" style="margin-bottom:16px">
    <div class="card" style="display:flex;justify-content:space-between;align-items:center;gap:10px">
        <div>
            <div><strong>Pot‑stand <?= $meName ?></strong></div>
            <div class="muted">Gemiste weken: <?= $missed ?> × €5 = <span class="money">€<?= $missed * 5 ?></span></div>
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
                    <h3><?= htmlspecialchars($t['name']) ?></h3>
                    <?php if ($t['done']): ?>
                        <div>✅ Afgevinkt<?= $t['done_at'] ? ' op <strong>'.htmlspecialchars($t['done_at']).'</strong>' : '' ?></div>
                    <?php else: ?>
                        <form method="post" class="actions">
                            <input type="hidden" name="action" value="done">
                            <input type="hidden" name="task"   value="<?= htmlspecialchars($t['name']) ?>">
                            <button type="submit">Afvinken</button>
                        </form>
                    <?php endif; ?>
                    <div class="note">Status staat in <code>status_<?= (int)$wIndex ?>.json</code>.</div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>

</body>
</html>
