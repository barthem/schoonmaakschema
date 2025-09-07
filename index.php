<?php
$allowedIps = ['127.0.0.1', '::1']; // IPv4 en IPv6 loopback toestaan

$clientIp = $_SERVER['REMOTE_ADDR'] ?? '';

if (!in_array($clientIp, $allowedIps, true)) {
    http_response_code(403);
    echo "Toegang geweigerd";
    exit;
}

// main.php
$tasks = [
    "Schoonmaak" => "schoonmaak.php",
    "Afwas"      => "afwas.php"
];
?>
<!DOCTYPE html>
<html lang="nl">
<head>
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
    </style>
</head>
<body>
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
