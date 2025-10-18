<?php
// selecteer.php
// Leest mensen uit mensen.json en post de gekozen persoon naar schoonmaakschema.php

$peopleFile = __DIR__ . '/mensen.json';
$people = [];

if (is_file($peopleFile)) {
    $json = file_get_contents($peopleFile);
    $people = json_decode($json, true) ?: [];
}

// Optioneel: sorteer op naam (alfabetisch)
// uasort($people, fn($a, $b) => strcasecmp($a['name'], $b['name']));
?>
<!DOCTYPE html>
<html lang="nl">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>Kies je profiel</title>
    <style>
        :root {
            --bg: #f4f6fb;
            --card: #ffffff;
            --text: #1f2937;
            --accent: #2563eb;
            --shadow: 0 2px 8px rgba(0,0,0,.08);
        }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            font-family: system-ui, -apple-system, Segoe UI, Roboto, Arial, sans-serif;
            background: var(--bg);
            color: var(--text);
            padding: 20px;
        }
        header { text-align: center; margin: 10px 0 20px; }
        h1 { font-size: 1.6rem; margin: 0 0 8px; }
        p.lead { margin: 0; color: #475569; }

        /* Grid van knoppen (blokjes) */
        form.grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
            gap: 16px;
            max-width: 800px;
            margin: 0 auto;
        }
        /* Het “blokje” is een button zodat we POST kunnen doen zonder JS */
        button.card {
            display: flex;
            align-items: center;
            justify-content: center;
            min-height: 110px;
            padding: 14px;
            width: 100%;
            border: 2px solid transparent;
            border-radius: 12px;
            background: var(--card);
            box-shadow: var(--shadow);
            color: var(--text);
            font-size: 1.1rem;
            font-weight: 700;
            cursor: pointer;
            transition: transform .12s ease, box-shadow .12s ease, border-color .12s ease, background .12s ease;
        }
        button.card:hover,
        button.card:focus-visible {
            transform: translateY(-2px);
            box-shadow: 0 6px 18px rgba(0,0,0,.12);
            border-color: var(--accent);
            outline: none;
        }
        button.card:active {
            transform: translateY(0);
            box-shadow: var(--shadow);
        }
        /* Kleine subtitel (optioneel, bv. totaal gemist) als je die hier ooit wilt tonen */
        .sub { display: block; font-size: .9rem; font-weight: 500; color: #64748b; margin-top: 6px; }

        /* Mobile spacing */
        @media (max-width: 480px) {
            body { padding: 16px; }
            h1 { font-size: 1.4rem; }
        }

        /* Fallback melding */
        .empty {
            max-width: 680px;
            margin: 24px auto;
            background: #fff;
            border-radius: 12px;
            box-shadow: var(--shadow);
            padding: 18px;
            border-left: 4px solid #f59e0b;
        }
        .footer-note {
            text-align: center;
            margin-top: 20px;
            color: #6b7280;
            font-size: .95rem;
        }
    </style>
</head>
<body>
    <header>
        <h1>Wie ben je?</h1>
        <p class="lead">Kies je naam om je taken te bekijken en af te vinken.</p>
    </header>

    <?php if (empty($people)): ?>
        <div class="empty">
            <strong>Geen mensen gevonden.</strong>
            <div>Voeg entries toe in <code>mensen.json</code>, bijvoorbeeld:
<pre style="margin:8px 0 0; white-space: pre-wrap">{
  "bart": { "name": "Bart", "missed": 0 },
  "anne": { "name": "Anne", "missed": 0 }
}</pre>
            </div>
        </div>
    <?php else: ?>
        <!-- Eén formulier met meerdere submit-knoppen: de geklikte knop post zijn value -->
        <form method="post" action="schoonmaak.php" class="grid">
            <?php foreach ($people as $key => $p): ?>
                <button class="card" type="submit" name="person" value="<?= htmlspecialchars($key) ?>">
                    <span class="name"><?= htmlspecialchars($p['name']) ?></span>
                </button>
            <?php endforeach; ?>
        </form>
    <?php endif; ?>

    <div class="footer-note">Je keuze wordt via <strong>POST</strong> doorgestuurd naar <code>schoonmaakschema.php</code>.</div>
</body>
</html>
