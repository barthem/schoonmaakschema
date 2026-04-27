<?php
// beheer.php — Verborgen beheerpagina voor taken.json
// Beveiligd met een passcode. Pas PASSCODE hieronder aan.

declare(strict_types=1);
session_start();

const PASSCODE = 'buurman';

$tasksFile = __DIR__ . '/taken.json';
$peopleFile = __DIR__ . '/mensen.json';

function readJson(string $path, $fallback) {
    if (!is_file($path)) return $fallback;
    $raw = file_get_contents($path);
    $data = json_decode($raw, true);
    return is_array($data) ? $data : $fallback;
}
function writeJson(string $path, $data): void {
    file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
}

$tasks = readJson($tasksFile, []);
$people = readJson($peopleFile, []);
$personKeys = array_keys($people);
$frequencies = ['weekly' => 'Wekelijks', 'biweekly' => '2-wekelijks', 'monthly' => 'Maandelijks'];
$lastEdited = is_file($tasksFile) ? filemtime($tasksFile) : null;

// Helper: fixed_to → array van persoon-keys (lege array = niemand/roulatie)
function fixedToList($fixedTo, array $people): array {
    if (is_array($fixedTo)) {
        return array_values(array_filter($fixedTo, fn($p) => is_string($p) && $p !== '' && isset($people[$p])));
    }
    if (is_string($fixedTo) && $fixedTo !== '' && isset($people[$fixedTo])) {
        return [$fixedTo];
    }
    return [];
}

// Huidige week-status laden (zelfde startdatum als schoonmaak.php) zodat we kunnen
// tonen wie multi-pinned taken deze week heeft gekregen.
$startDate = new DateTime('2025-09-06');
$today     = new DateTime('today');
$wIndex    = intdiv(max(0, (int)$startDate->diff($today)->days), 7);
$currentWeekStatus = readJson(__DIR__ . "/status/status_{$wIndex}.json", []);

// Frequentie-rangorde: lager getal = vaker. weekly=0, biweekly=1, monthly=2
function frequencyRank(string $freq): int {
    return ['weekly' => 0, 'biweekly' => 1, 'monthly' => 2][$freq] ?? 0;
}
// Subtaak wordt "afgeschermd" door parent als de subtaak vaker zou willen draaien
// dan de parent — de parent skipt dan in die weken de hele taak inclusief subtaak.
function subtaskShadowed(string $subFreq, string $parentFreq): bool {
    return frequencyRank($subFreq) < frequencyRank($parentFreq);
}

// Vind de assigned_to van een taak in de huidige week
// (taken zonder subtaken: directe key; met subtaken: key begint met "Naam - ")
function currentAssigneeForTask(array $task, array $weekStatus): ?string {
    $name = $task['name'];
    if (empty($task['subtasks'])) {
        return $weekStatus[$name]['assigned_to'] ?? null;
    }
    $prefix = $name . ' - ';
    foreach ($weekStatus as $key => $rec) {
        if (strpos($key, '__') === 0) continue;
        if (strpos($key, $prefix) === 0) {
            return $rec['assigned_to'] ?? null;
        }
    }
    return null;
}

/* ========== Passcode check ========== */
$authenticated = ($_SESSION['beheer_auth'] ?? false) === true;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['passcode'])) {
    if ($_POST['passcode'] === PASSCODE) {
        $_SESSION['beheer_auth'] = true;
        $authenticated = true;
    } else {
        $authError = 'Verkeerde code.';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && (($_POST['action'] ?? '') === 'logout_beheer')) {
    unset($_SESSION['beheer_auth']);
    header('Location: beheer.php');
    exit;
}

/* ========== Acties (alleen als ingelogd) ========== */
$flash = '';

if ($authenticated && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // Taak verwijderen
    if ($action === 'delete') {
        $idx = (int)($_POST['idx'] ?? -1);
        if (isset($tasks[$idx])) {
            $deletedName = $tasks[$idx]['name'];
            array_splice($tasks, $idx, 1);
            writeJson($tasksFile, $tasks);
            $flash = "'{$deletedName}' verwijderd.";
        }
    }

    // Helper: parse fixed_to uit POST → null (niemand) | string (1 persoon) | array (2+ personen)
    $parseFixedTo = function() use ($people) {
        $raw = $_POST['fixed_to'] ?? null;
        if (is_array($raw)) {
            $valid = array_values(array_filter($raw, fn($p) => is_string($p) && $p !== '' && isset($people[$p])));
            if (count($valid) === 0) return null;
            if (count($valid) === 1) return $valid[0];
            return $valid;
        }
        // Backward compat met oude single-select forms
        if (is_string($raw) && $raw !== '' && isset($people[$raw])) return $raw;
        return null;
    };

    // Taak toevoegen
    if ($action === 'add') {
        $name = trim($_POST['name'] ?? '');
        if ($name !== '') {
            $fixedTo = $parseFixedTo();
            $freq = $_POST['frequency'] ?? 'weekly';
            if (!isset($frequencies[$freq])) $freq = 'weekly';

            $newTask = [
                'name' => $name,
                'fixed_to' => $fixedTo,
                'frequency' => $freq,
                'subtasks' => []
            ];
            $tasks[] = $newTask;
            writeJson($tasksFile, $tasks);
            $flash = "'{$name}' toegevoegd.";
        }
    }

    // Taak bewerken
    if ($action === 'save') {
        $idx = (int)($_POST['idx'] ?? -1);
        if (isset($tasks[$idx])) {
            $name = trim($_POST['name'] ?? '');
            if ($name !== '') {
                $fixedTo = $parseFixedTo();
                $freq = $_POST['frequency'] ?? 'weekly';
                if (!isset($frequencies[$freq])) $freq = 'weekly';

                // Subtasks parsen
                $subNames = $_POST['sub_name'] ?? [];
                $subFreqs = $_POST['sub_freq'] ?? [];
                $subtasks = [];
                foreach ($subNames as $si => $sn) {
                    $sn = trim($sn);
                    if ($sn === '') continue;
                    $sf = $subFreqs[$si] ?? 'weekly';
                    if (!isset($frequencies[$sf])) $sf = 'weekly';
                    $subtasks[] = ['name' => $sn, 'frequency' => $sf];
                }

                $tasks[$idx] = [
                    'name' => $name,
                    'fixed_to' => $fixedTo,
                    'frequency' => $freq,
                    'subtasks' => $subtasks
                ];
                writeJson($tasksFile, $tasks);
                $flash = "'{$name}' opgeslagen.";
            }
        }
    }

    // Persoon toevoegen
    if ($action === 'add_person') {
        $name = trim($_POST['person_name'] ?? '');
        if ($name !== '') {
            $key = $name; // key = naam
            if (!isset($people[$key])) {
                $people[$key] = ['name' => $name, 'missed' => 0];
                writeJson($peopleFile, $people);
                $personKeys = array_keys($people);
                $flash = "'{$name}' toegevoegd als huisgenoot.";
            } else {
                $flash = "'{$name}' bestaat al.";
                $isError = true;
            }
        }
    }

    // Persoon verwijderen
    if ($action === 'delete_person') {
        $key = $_POST['person_key'] ?? '';
        if ($key !== '' && isset($people[$key])) {
            $deletedName = $people[$key]['name'];
            unset($people[$key]);
            writeJson($peopleFile, $people);
            $personKeys = array_keys($people);
            $flash = "'{$deletedName}' verwijderd.";
        }
    }

    // Pot aanpassen per persoon
    if ($action === 'set_missed') {
        $key = $_POST['person_key'] ?? '';
        $missed = max(0, (int)($_POST['missed'] ?? 0));
        if ($key !== '' && isset($people[$key])) {
            $people[$key]['missed'] = $missed;
            writeJson($peopleFile, $people);
            $flash = "Gemiste weken van '{$people[$key]['name']}' gezet op {$missed}.";
        }
    }

    // Pot resetten (iedereen op 0)
    if ($action === 'reset_pot') {
        foreach ($people as &$p) {
            $p['missed'] = 0;
        }
        unset($p);
        writeJson($peopleFile, $people);
        $flash = 'Pot gereset — alle tellers op 0.';
    }

    // PRG na elke actie (behalve passcode login die hierboven al is afgehandeld)
    if ($action !== '') {
        $flashType = (isset($isError) && $isError) ? 'error' : 'flash';
        header('Location: beheer.php' . ($flash ? '?flash=' . urlencode($flash) . '&type=' . $flashType : ''));
        exit;
    }
}

$flash = $_GET['flash'] ?? $flash;
$flashType = $_GET['type'] ?? 'flash';
$editIdx = isset($_GET['edit']) ? (int)$_GET['edit'] : null;
?>
<!DOCTYPE html>
<html lang="nl">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width,initial-scale=1" />
    <title>Takenbeheer</title>
    <style>
        :root { --bg:#f4f6fb; --card:#fff; --text:#1f2937; --muted:#6b7280; --accent:#2563eb; --danger:#dc2626; --shadow:0 2px 10px rgba(0,0,0,.08); }
        *{box-sizing:border-box}
        body{margin:0;font-family:system-ui,-apple-system,Segoe UI,Roboto,Arial,sans-serif;background:var(--bg);color:var(--text);padding:18px}
        .wrap{max-width:800px;margin:0 auto}
        h1{font-size:1.4rem;margin:0 0 16px}
        h2{font-size:1.15rem;margin:18px 0 10px}

        .card{background:var(--card);border-radius:12px;box-shadow:var(--shadow);padding:16px;margin-bottom:14px}
        .card-header{display:flex;justify-content:space-between;align-items:center;gap:8px;flex-wrap:wrap}
        .card-header h3{margin:0;font-size:1.05rem}

        label{display:block;font-weight:600;margin:8px 0 4px;font-size:.9rem}
        input[type=text],select{width:100%;padding:8px 10px;border:1px solid #d1d5db;border-radius:8px;font-size:.95rem;font-family:inherit}
        input[type=text]:focus,select:focus{outline:none;border-color:var(--accent);box-shadow:0 0 0 3px rgba(37,99,235,.15)}

        .btn{display:inline-flex;align-items:center;gap:4px;padding:8px 14px;border:none;border-radius:8px;font-weight:700;font-size:.9rem;cursor:pointer;transition:transform .1s,box-shadow .1s;text-decoration:none;font-family:inherit}
        .btn:hover{transform:translateY(-1px);box-shadow:0 4px 12px rgba(0,0,0,.1)}
        .btn-primary{background:var(--accent);color:#fff}
        .btn-secondary{background:#e5e7eb;color:#111827}
        .btn-danger{background:#fee2e2;color:var(--danger)}
        .btn-danger:hover{background:#fecaca}
        .btn-sm{padding:5px 10px;font-size:.82rem}

        .actions{display:flex;gap:6px;flex-wrap:wrap;align-items:center}
        .pill{display:inline-block;padding:.2rem .5rem;border-radius:999px;font-size:.8rem;font-weight:600}
        .pill-weekly{background:#dcfce7;color:#166534}
        .pill-biweekly{background:#fef3c7;color:#92400e}
        .pill-monthly{background:#dbeafe;color:#1e40af}
        .pill-pinned{background:#fce7f3;color:#831843}
        .pill-warn{background:#fef3c7;color:#92400e;border:1px solid #fcd34d}
        .warn-box{background:#fffbeb;border:1px solid #fcd34d;color:#78350f;padding:8px 12px;border-radius:8px;margin-top:8px;font-size:.85rem;line-height:1.4}
        .subtask-row-warn{background:#fffbeb;border:1px solid #fcd34d;border-radius:8px;padding:6px;margin:0 -2px 6px}
        .flash{background:#dcfce7;color:#166534;padding:10px 14px;border-radius:8px;margin-bottom:14px;font-weight:600}
        .error{background:#fee2e2;color:#991b1b;padding:10px 14px;border-radius:8px;margin-bottom:14px;font-weight:600}
        .muted{color:var(--muted);font-size:.9rem}

        .subtask-row{display:flex;gap:8px;align-items:center;margin-bottom:6px}
        .subtask-row input[type=text]{flex:1}
        .subtask-row select{width:auto;flex:0 0 140px}

        .pin-checkboxes{display:flex;flex-wrap:wrap;gap:8px;margin-top:4px}
        .pin-check{display:inline-flex;align-items:center;gap:6px;padding:6px 12px;border:1px solid #d1d5db;border-radius:8px;cursor:pointer;font-weight:500;font-size:.9rem;background:#f9fafb;user-select:none;margin:0}
        .pin-check:hover{background:#eef2ff;border-color:#a5b4fc}
        .pin-check input[type=checkbox]{margin:0;cursor:pointer}
        .pin-check:has(input:checked){background:#fce7f3;border-color:#ec4899;color:#831843;font-weight:700}

        .login-box{max-width:360px;margin:60px auto}
        .login-box h1{text-align:center}

        .bar{display:flex;gap:10px;align-items:center;justify-content:space-between;flex-wrap:wrap;margin-bottom:16px}

        @media(max-width:520px){body{padding:14px}.subtask-row{flex-wrap:wrap}.subtask-row select{flex:1}}
    </style>
</head>
<body>
<div class="wrap">

<?php if (!$authenticated): ?>
    <!-- ========== LOGIN ========== -->
    <div class="login-box">
        <h1>Takenbeheer</h1>
        <?php if (!empty($authError)): ?>
            <div class="error"><?= htmlspecialchars($authError) ?></div>
        <?php endif; ?>
        <div class="card">
            <form method="post">
                <label for="passcode">Voer de code in:</label>
                <input type="password" id="passcode" name="passcode" autocomplete="off" autofocus />
                <br><br>
                <button type="submit" class="btn btn-primary" style="width:100%">Inloggen</button>
            </form>
        </div>
    </div>

<?php elseif ($editIdx !== null && isset($tasks[$editIdx])): ?>
    <!-- ========== BEWERKEN ========== -->
    <?php $t = $tasks[$editIdx]; ?>
    <div class="bar">
        <h1>Taak bewerken</h1>
        <a href="beheer.php" class="btn btn-secondary">Terug</a>
    </div>

    <div class="card">
        <form method="post">
            <input type="hidden" name="action" value="save" />
            <input type="hidden" name="idx" value="<?= $editIdx ?>" />

            <label for="name">Naam</label>
            <input type="text" id="name" name="name" value="<?= htmlspecialchars($t['name']) ?>" required />

            <label>Gepind aan</label>
            <?php $currentFixed = fixedToList($t['fixed_to'] ?? null, $people); ?>
            <div class="pin-checkboxes">
                <?php foreach ($personKeys as $pk): ?>
                    <label class="pin-check">
                        <input type="checkbox" name="fixed_to[]" value="<?= htmlspecialchars($pk) ?>" <?= in_array($pk, $currentFixed, true) ? 'checked' : '' ?> />
                        <?= htmlspecialchars($people[$pk]['name']) ?>
                    </label>
                <?php endforeach; ?>
            </div>
            <div class="muted" style="font-size:.85rem;margin-top:4px">Geen vinkje = roulatie tussen iedereen. 1 vinkje = vast aan die persoon. 2+ vinkjes = roulatie tussen die personen.</div>

            <label for="frequency">Frequentie</label>
            <select id="frequency" name="frequency">
                <?php foreach ($frequencies as $fk => $fl): ?>
                    <option value="<?= $fk ?>" <?= ($t['frequency'] ?? 'weekly') === $fk ? 'selected' : '' ?>><?= $fl ?></option>
                <?php endforeach; ?>
            </select>

            <h2>Subtaken</h2>
            <?php
                $parentFreq = $t['frequency'] ?? 'weekly';
                $editHasShadowed = false;
                foreach ($t['subtasks'] ?? [] as $sub) {
                    $sf = is_string($sub) ? $parentFreq : ($sub['frequency'] ?? $parentFreq);
                    if (subtaskShadowed($sf, $parentFreq)) { $editHasShadowed = true; break; }
                }
            ?>
            <?php if ($editHasShadowed): ?>
                <div class="warn-box">
                    ⚠ Eén of meer subtaken hebben een hogere frequentie dan de parent ("<?= htmlspecialchars($frequencies[$parentFreq] ?? $parentFreq) ?>").
                    De parent bepaalt wanneer de hele taak verschijnt — die subtaken draaien dus niet vaker dan de parent.
                    Zet de parent op "Wekelijks" als je wilt dat subtaken hun eigen schema volgen.
                </div>
            <?php endif; ?>
            <div id="subtasks-container">
                <?php foreach ($t['subtasks'] ?? [] as $si => $sub): ?>
                    <?php
                        $subName = is_string($sub) ? $sub : ($sub['name'] ?? '');
                        $subFreq = is_string($sub) ? $parentFreq : ($sub['frequency'] ?? $parentFreq);
                        $rowShadowed = subtaskShadowed($subFreq, $parentFreq);
                    ?>
                    <div class="subtask-row<?= $rowShadowed ? ' subtask-row-warn' : '' ?>">
                        <input type="text" name="sub_name[]" value="<?= htmlspecialchars($subName) ?>" placeholder="Subtaaknaam" />
                        <select name="sub_freq[]">
                            <?php foreach ($frequencies as $fk => $fl): ?>
                                <option value="<?= $fk ?>" <?= $subFreq === $fk ? 'selected' : '' ?>><?= $fl ?></option>
                            <?php endforeach; ?>
                        </select>
                        <?php if ($rowShadowed): ?>
                            <span title="Parent staat op '<?= htmlspecialchars($frequencies[$parentFreq] ?? $parentFreq) ?>', dus deze subtaak draait ook maar zo vaak." style="color:#92400e;font-weight:700">⚠</span>
                        <?php endif; ?>
                        <button type="button" class="btn btn-danger btn-sm" onclick="this.parentElement.remove()">X</button>
                    </div>
                <?php endforeach; ?>
            </div>
            <button type="button" class="btn btn-secondary btn-sm" onclick="addSubtask()" style="margin-top:4px">+ Subtaak</button>

            <br><br>
            <div class="actions">
                <button type="submit" class="btn btn-primary">Opslaan</button>
                <a href="beheer.php" class="btn btn-secondary">Annuleren</a>
            </div>
        </form>
    </div>

    <script>
    function addSubtask() {
        const c = document.getElementById('subtasks-container');
        const row = document.createElement('div');
        row.className = 'subtask-row';
        row.innerHTML = `
            <input type="text" name="sub_name[]" placeholder="Subtaaknaam" />
            <select name="sub_freq[]">
                <?php foreach ($frequencies as $fk => $fl): ?>
                <option value="<?= $fk ?>"><?= $fl ?></option>
                <?php endforeach; ?>
            </select>
            <button type="button" class="btn btn-danger btn-sm" onclick="this.parentElement.remove()">X</button>
        `;
        c.appendChild(row);
        row.querySelector('input').focus();
    }
    </script>

<?php else: ?>
    <!-- ========== OVERZICHT ========== -->
    <div class="bar">
        <h1>Takenbeheer</h1>
        <div class="actions">
            <a href="index.php" class="btn btn-secondary">Menu</a>
            <form method="post" style="margin:0">
                <input type="hidden" name="action" value="logout_beheer" />
                <button type="submit" class="btn btn-secondary">Uitloggen</button>
            </form>
        </div>
    </div>

    <?php if ($lastEdited): ?>
        <div class="muted" style="margin-bottom:14px">Laatst bewerkt: <?= date('d-m-Y H:i', $lastEdited) ?></div>
    <?php endif; ?>

    <?php if ($flash): ?>
        <div class="<?= $flashType === 'error' ? 'error' : 'flash' ?>"><?= htmlspecialchars($flash) ?></div>
    <?php endif; ?>

    <!-- Nieuwe taak toevoegen -->
    <div class="card">
        <h2 style="margin-top:0">Nieuwe taak</h2>
        <form method="post">
            <input type="hidden" name="action" value="add" />
            <div style="display:grid;grid-template-columns:1fr auto;gap:8px;align-items:end">
                <div>
                    <label for="new_name">Naam</label>
                    <input type="text" id="new_name" name="name" placeholder="Taaknaam" required />
                </div>
                <div>
                    <label for="new_freq">Frequentie</label>
                    <select id="new_freq" name="frequency">
                        <?php foreach ($frequencies as $fk => $fl): ?>
                            <option value="<?= $fk ?>"><?= $fl ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <label style="margin-top:10px">Gepind aan</label>
            <div class="pin-checkboxes">
                <?php foreach ($personKeys as $pk): ?>
                    <label class="pin-check">
                        <input type="checkbox" name="fixed_to[]" value="<?= htmlspecialchars($pk) ?>" />
                        <?= htmlspecialchars($people[$pk]['name']) ?>
                    </label>
                <?php endforeach; ?>
            </div>
            <div class="muted" style="font-size:.85rem;margin-top:4px">Geen vinkje = roulatie. 1 = vast. 2+ = roulatie tussen die personen.</div>
            <br>
            <button type="submit" class="btn btn-primary">Toevoegen</button>
            <span class="muted" style="margin-left:8px">Subtaken kun je toevoegen na het aanmaken.</span>
        </form>
    </div>

    <!-- Bestaande taken -->
    <h2><?= count($tasks) ?> taken</h2>
    <?php foreach ($tasks as $idx => $t): ?>
        <div class="card">
            <div class="card-header">
                <div>
                    <h3>
                        <?= htmlspecialchars($t['name']) ?>
                        <span class="pill pill-<?= htmlspecialchars($t['frequency'] ?? 'weekly') ?>"><?= $frequencies[$t['frequency'] ?? 'weekly'] ?? 'Wekelijks' ?></span>
                        <?php
                            $pinned = fixedToList($t['fixed_to'] ?? null, $people);
                            $assigneeKey = null;
                            if (count($pinned) === 1) {
                                $assigneeKey = $pinned[0];
                            } elseif (count($pinned) >= 2) {
                                // Multi-pin: pak wie deze week is gekozen, anders eerste uit de pool
                                $current = currentAssigneeForTask($t, $currentWeekStatus);
                                $assigneeKey = ($current && in_array($current, $pinned, true)) ? $current : $pinned[0];
                            }
                        ?>
                        <?php if ($assigneeKey !== null): ?>
                            <span class="pill pill-pinned">📌 <?= htmlspecialchars($people[$assigneeKey]['name'] ?? $assigneeKey) ?><?php if (count($pinned) >= 2): ?> <small style="opacity:.7">(rouleert)</small><?php endif; ?></span>
                        <?php endif; ?>
                    </h3>
                    <?php if (!empty($t['subtasks'])):
                        $parentFreq = $t['frequency'] ?? 'weekly';
                        $hasShadowed = false;
                    ?>
                        <div class="muted" style="margin-top:4px">
                            <?php foreach ($t['subtasks'] as $si => $sub):
                                $sName = is_string($sub) ? $sub : ($sub['name'] ?? '');
                                $sFreq = is_string($sub) ? $parentFreq : ($sub['frequency'] ?? $parentFreq);
                                $shadowed = subtaskShadowed($sFreq, $parentFreq);
                                if ($shadowed) $hasShadowed = true;
                            ?>
                                <?php if ($si > 0): ?><span style="color:#d1d5db"> · </span><?php endif; ?>
                                <?= htmlspecialchars($sName) ?>
                                <?php if ($sFreq !== $parentFreq): ?>
                                    <span class="pill pill-<?= htmlspecialchars($sFreq) ?>" style="font-size:.7rem;padding:.1rem .4rem"><?= $frequencies[$sFreq] ?? $sFreq ?></span>
                                <?php endif; ?>
                                <?php if ($shadowed): ?>
                                    <span class="pill pill-warn" style="font-size:.7rem;padding:.1rem .4rem" title="Parent staat op '<?= htmlspecialchars($frequencies[$parentFreq] ?? $parentFreq) ?>', dus deze subtaak draait ook maar zo vaak.">⚠ geremd</span>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </div>
                        <?php if ($hasShadowed): ?>
                            <div class="warn-box">
                                ⚠ Eén of meer subtaken hebben een hogere frequentie dan de parent ("<?= htmlspecialchars($frequencies[$parentFreq] ?? $parentFreq) ?>").
                                De parent bepaalt wanneer de hele taak verschijnt — die subtaken draaien dus niet vaker dan de parent.
                            </div>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
                <div class="actions">
                    <a href="beheer.php?edit=<?= $idx ?>" class="btn btn-secondary btn-sm">Bewerken</a>
                    <form method="post" style="margin:0" onsubmit="return confirm('Weet je zeker dat je \'<?= htmlspecialchars(addslashes($t['name'])) ?>\' wilt verwijderen?')">
                        <input type="hidden" name="action" value="delete" />
                        <input type="hidden" name="idx" value="<?= $idx ?>" />
                        <button type="submit" class="btn btn-danger btn-sm">Verwijderen</button>
                    </form>
                </div>
            </div>
        </div>
    <?php endforeach; ?>

    <!-- ========== POT BEHEER ========== -->
    <h2 style="margin-top:28px">Pot</h2>
    <div class="card">
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:12px;margin-bottom:12px">
            <?php foreach ($people as $pk => $p):
                $missed = (int)($p['missed'] ?? 0);
                $pot = $missed * 5;
            ?>
                <div style="background:#f9fafb;border-radius:8px;padding:10px">
                    <div style="font-weight:700"><?= htmlspecialchars($p['name']) ?></div>
                    <div class="muted">Gemist: <?= $missed ?> weken</div>
                    <div style="font-weight:800;color:#dc2626;font-size:1.1rem">&euro;<?= $pot ?></div>
                    <form method="post" style="margin-top:6px;display:flex;gap:6px;align-items:center">
                        <input type="hidden" name="action" value="set_missed" />
                        <input type="hidden" name="person_key" value="<?= htmlspecialchars($pk) ?>" />
                        <input type="number" name="missed" value="<?= $missed ?>" min="0" style="width:70px;padding:4px 8px;border:1px solid #d1d5db;border-radius:6px;font-size:.9rem" />
                        <button type="submit" class="btn btn-secondary btn-sm">Opslaan</button>
                    </form>
                </div>
            <?php endforeach; ?>
        </div>
        <div style="display:flex;justify-content:space-between;align-items:center;border-top:1px solid #e5e7eb;padding-top:12px">
            <div>
                <span class="muted">Totale pot:</span>
                <span style="font-weight:800;color:#dc2626;font-size:1.2rem">&euro;<?php
                    $totalPot = 0;
                    foreach ($people as $p) $totalPot += (int)($p['missed'] ?? 0) * 5;
                    echo $totalPot;
                ?></span>
            </div>
            <form method="post" onsubmit="return confirm('Weet je zeker? Alle tellers worden op 0 gezet.')">
                <input type="hidden" name="action" value="reset_pot" />
                <button type="submit" class="btn btn-danger btn-sm">Pot resetten</button>
            </form>
        </div>
    </div>

    <!-- ========== MENSEN BEHEER ========== -->
    <h2 style="margin-top:28px">Huisgenoten</h2>
    <div class="card">
        <form method="post" style="display:flex;gap:8px;align-items:end;margin-bottom:14px">
            <input type="hidden" name="action" value="add_person" />
            <div style="flex:1">
                <label for="person_name">Nieuwe huisgenoot</label>
                <input type="text" id="person_name" name="person_name" placeholder="Naam" required />
            </div>
            <button type="submit" class="btn btn-primary">Toevoegen</button>
        </form>
        <?php foreach ($people as $pk => $p): ?>
            <div style="display:flex;justify-content:space-between;align-items:center;padding:8px 0;border-top:1px solid #f3f4f6">
                <span style="font-weight:600"><?= htmlspecialchars($p['name']) ?></span>
                <form method="post" style="margin:0" onsubmit="return confirm('Weet je zeker dat je \'<?= htmlspecialchars(addslashes($p['name'])) ?>\' wilt verwijderen?')">
                    <input type="hidden" name="action" value="delete_person" />
                    <input type="hidden" name="person_key" value="<?= htmlspecialchars($pk) ?>" />
                    <button type="submit" class="btn btn-danger btn-sm">Verwijderen</button>
                </form>
            </div>
        <?php endforeach; ?>
    </div>

<?php endif; ?>

</div>
</body>
</html>
