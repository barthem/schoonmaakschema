<?php
// main.php
$tasks = [
    "Schoonmaak" => "schoonmaak.php",
    "Afwas"      => "afwas.php"
];
?>
<!DOCTYPE html>
<html lang="nl">
<head>
    <script>(function(){try{var m=document.cookie.match(/(?:^|; )theme=([^;]+)/);var t=m?m[1]:(window.matchMedia&&matchMedia('(prefers-color-scheme: dark)').matches?'dark':'light');document.documentElement.setAttribute('data-theme',t);}catch(e){}})();
    function toggleTheme(){var h=document.documentElement,n=h.getAttribute('data-theme')==='dark'?'light':'dark';h.setAttribute('data-theme',n);document.cookie='theme='+n+';path=/;max-age=31536000;samesite=lax';}</script>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Schoonmaakschema - Menu</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            background: #f4f4f9;
            margin: 0;
            padding: 20px;
        }

        h1 {
            text-align: center;
            margin-bottom: 20px;
        }

        .grid {
            display: flex;
            flex-wrap: wrap;
            justify-content: center;
            gap: 15px;
        }

        .task {
            flex: 1 1 150px;
            max-width: 200px;
            min-height: 100px;
            background: #ffffff;
            border-radius: 10px;
            box-shadow: 0 2px 5px rgba(0,0,0,0.1);
            display: flex;
            justify-content: center;
            align-items: center;
            font-size: 1.2em;
            font-weight: bold;
            cursor: pointer;
            transition: transform 0.2s, background 0.2s;
            text-decoration: none;
            color: #333;
        }

        .task:hover {
            transform: scale(1.05);
            background: #eaf6ff;
        }

        @media (max-width: 600px) {
            .task {
                flex: 1 1 100%;
                max-width: none;
            }
        }

        /* Dark mode */
        html[data-theme="dark"] body{background:#0f172a;color:#e2e8f0}
        html[data-theme="dark"] .task{background:#1e293b;color:#e2e8f0;box-shadow:0 2px 5px rgba(0,0,0,.5)}
        html[data-theme="dark"] .task:hover{background:#243245}
        .topbar{display:flex;justify-content:flex-end;max-width:640px;margin:0 auto 10px}
        .theme-toggle{cursor:pointer;border:1px solid #8888;background:transparent;color:inherit;border-radius:8px;padding:6px 10px;font-size:1rem;line-height:1}
        .theme-toggle:hover{border-color:currentColor}
    </style>
</head>
<body>
    <div class="topbar">
        <button type="button" onclick="toggleTheme()" class="theme-toggle" aria-label="Wissel licht of donker thema" title="Licht / donker">🌓</button>
    </div>
    <h1>Kies je schema</h1>
    <div class="grid">
        <?php foreach ($tasks as $label => $link): ?>
            <a class="task" href="<?= htmlspecialchars($link) ?>">
                <?= htmlspecialchars($label) ?>
            </a>
        <?php endforeach; ?>
    </div>
</body>
</html>
