<?php
/* afwas.php — statisch afwas/afdrogen-rooster met:
   - highlight van vandaag
   - weekrotatie (even/oneven ISO-week) voor ma-vr
   - weekend zonder Mara
*/

declare(strict_types=1);
date_default_timezone_set('Europe/Amsterdam');

$days = [
  1 => 'Maandag',
  2 => 'Dinsdag',
  3 => 'Woensdag',
  4 => 'Donderdag',
  5 => 'Vrijdag',
  6 => 'Zaterdag',
  7 => 'Zondag',
];

// Twee patronen die per ISO-weeknummer afwisselen (doordeweeks).
$patternA = [
  1 => ['Mara', 'Bart'],  // Ma
  2 => ['Mara', 'Erin'],  // Di
  3 => ['Mara', 'Bart'],  // Wo
  4 => ['Mara', 'Erin'],  // Do
  5 => ['Bart', 'Mara'],  // Vr
];
$patternB = [
  1 => ['Mara', 'Erin'],  // Ma
  2 => ['Mara', 'Bart'],  // Di
  3 => ['Mara', 'Erin'],  // Wo
  4 => ['Bart', 'Mara'],  // Do
  5 => ['Erin', 'Bart'],  // Vr
];

// Weekend is vast: Mara staat er niet op.
$weekend = [
  6 => ['Bart', 'Erin'],  // Za
  7 => ['Erin', 'Bart'],  // Zo
];

$isoWeek  = (int)date('W');       // ISO-weeknummer
$year     = (int)date('o');       // ISO-jaar (voor week 1 in jan/dec)
$todayDow = (int)date('N');       // 1=ma ... 7=zo

$isEvenWeek = ($isoWeek % 2 === 0);
$activePattern = $isEvenWeek ? $patternA : $patternB;

// Bouw het rooster voor alle 7 dagen.
$schedule = [];
for ($d = 1; $d <= 7; $d++) {
  if ($d <= 5) {
    [$wash, $dry] = $activePattern[$d];
  } else {
    [$wash, $dry] = $weekend[$d];
  }
  $schedule[$d] = ['wash' => $wash, 'dry' => $dry];
}
?>
<!DOCTYPE html>
<html lang="nl">
<head>
  <meta charset="utf-8">
  <title>Afwasrooster</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <style>
    :root { color-scheme: light dark; }
    html, body { height: 100%; margin: 0; }
    body {
      display: grid;
      place-items: center;
      padding: 2rem;
      font-family: system-ui, -apple-system, Segoe UI, Roboto, Arial, sans-serif;
      background: Canvas;
      color: CanvasText;
    }
    .wrap { width: min(720px, 100%); }
    h1 { margin: 0 0 .4rem; font-size: clamp(1.25rem, 2.8vw, 1.7rem); }
    .meta { margin: 0 0 1rem; color: #666; font-size: .95rem; }
    @media (prefers-color-scheme: dark) { .meta { color: #999; } }

    table { border-collapse: collapse; width: 100%; }
    th, td { border: 1px solid #ccc; padding: .6rem .8rem; text-align: left; }
    th { background: #f6f6f6; }
    tbody tr:nth-child(odd) td { background: #fafafa; }
    .weekend td { background: #fff6e5; }
    .today td {
      outline: 2px solid #3b82f6; /* blauw randje */
      outline-offset: -2px;
      font-weight: 600;
    }
    caption { caption-side: bottom; font-size: .95rem; color: #666; padding-top: .5rem; }

    @media (prefers-color-scheme: dark) {
      th, td { border-color: #444; }
      th { background: #111; }
      tbody tr:nth-child(odd) td { background: #0d0d0d; }
      .weekend td { background: #261a00; }
      caption { color: #999; }
    }

    /* Mobiel: cards per dag */
    @media (max-width: 640px) {
      thead {
        position: absolute;
        left: -9999px; top: -9999px;
      }
      table, tbody, tr, td { display: block; width: 100%; }
      tr {
        border: 1px solid #ccc;
        border-radius: .6rem;
        padding: .4rem .6rem;
        margin-bottom: .9rem;
      }
      @media (prefers-color-scheme: dark) { tr { border-color: #444; } }
      td {
        border: none;
        border-bottom: 1px solid #e0e0e0;
        display: grid;
        grid-template-columns: 11ch 1fr;
        gap: .6rem;
        padding: .5rem .2rem;
      }
      td:last-child { border-bottom: none; }
      td::before { content: attr(data-label); font-weight: 600; }
      .weekend td { background: transparent; }
    }
  </style>
</head>
<body>
  <div class="wrap">
    <h1>Afwasrooster (wekelijks herhalend)</h1>
    <p class="meta">
      Week <?= htmlspecialchars((string)$isoWeek) ?> — <?= htmlspecialchars($days[$todayDow]) ?> <?= date('d-m-Y') ?>
      — Rotatie: <?= $isEvenWeek ? 'Patroon A (even week)' : 'Patroon B (oneven week)' ?>
    </p>

    <table aria-describedby="toelichting">
      <thead>
        <tr>
          <th>Dag</th>
          <th>Afwassen</th>
          <th>Afdrogen</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($schedule as $dow => $task): ?>
        <tr class="<?= $dow >= 6 ? 'weekend ' : '' ?><?= $dow === $todayDow ? 'today' : '' ?>" <?= $dow === $todayDow ? 'aria-current="row"' : '' ?>>
          <td data-label="Dag"><?= htmlspecialchars($days[$dow]) ?></td>
          <td data-label="Afwassen"><?= htmlspecialchars($task['wash']) ?></td>
          <td data-label="Afdrogen"><?= htmlspecialchars($task['dry']) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
      <caption id="toelichting">
        Doordeweeks roteert de taakverdeling per (ISO-)week. In het weekend doen Bart en Erin het samen; Mara staat niet ingeroosterd.
      </caption>
    </table>
  </div>
</body>
</html>
