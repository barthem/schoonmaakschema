# schoonmaakschema# Schoonmaakschema Web App

Een eenvoudige PHP-webapp voor het beheren van huishoudelijke taken met automatische roulatie, boetesysteem en wekelijkse tracking.

## 📋 Functies

- **Automatische taakroulatie**: Taken worden eerlijk verdeeld op basis van werkbelasting en taakgeschiedenis
- **Wekelijkse & tweewekelijkse taken**: Support voor taken met verschillende frequenties
- **Vaste taken**: Bepaalde taken kunnen toegewezen worden aan specifieke personen
- **Boetesysteem**: Gemiste taken resulteren in een boete van €5 per week
- **Collectieve pot**: Alle boetes gaan naar een gemeenschappelijke pot
- **Afwasrooster**: Apart rooster voor afwas/afdrogen met weekrotatie
- **Mobiel-vriendelijk**: Responsive design voor gebruik op telefoon en tablet

## 🚀 Installatie

### Vereisten

- PHP 7.4 of hoger
- Webserver (Apache, Nginx, of PHP's ingebouwde server)
- Schrijfrechten in de projectmap (voor JSON-bestanden)

### Setup

1. **Clone of download** het project naar je webserver:
   ```bash
   git clone <repository-url>
   cd schoonmaakschema
   ```

2. **Configureer de startdatum** in `schoonmaak.php`:
   ```php
   $startDate = new DateTime('2025-09-05'); // Pas aan naar gewenste startdatum
   ```

3. **Configureer personen** in `mensen.json`:
   ```json
   {
       "Bart": {
           "name": "Bart",
           "missed": 0
       },
       "Erin": {
           "name": "Erin",
           "missed": 0
       }
   }
   ```

4. **Configureer taken** in `taken.json`:
   ```json
   [
       {
           "name": "Badkamer schoonmaken",
           "fixed_to": null,
           "frequency": "weekly",
           "subtasks": []
       },
       {
           "name": "Keuken dweilen",
           "fixed_to": null,
           "frequency": "biweekly",
           "subtasks": [
               { "name": "Vloer", "frequency": "biweekly" },
               { "name": "Aanrecht", "frequency": "weekly" }
           ]
       },
       {
           "name": "Vuilnis wegbrengen",
           "fixed_to": "Bart",
           "frequency": "weekly",
           "subtasks": [
               { "name": "Plastic", "frequency": "weekly" },
               { "name": "Restafval", "frequency": "weekly" },
               { "name": "Karton", "frequency": "biweekly" }
           ]
       }
   ]
   ```

5. **Start de applicatie**:
   ```bash
   # Met PHP's ingebouwde server
   php -S localhost:8000
   
   # Of plaats in je webserver root
   # en ga naar http://jouw-domein.nl
   ```

6. **Ga naar** `http://localhost:8000/index.php` of je domein

## 📁 Bestandsstructuur

```
schoonmaakschema/
├── index.php          # Hoofdmenu
├── selecteer.php      # Persoon selecteren
├── schoonmaak.php     # Schoonmaaktaken overzicht
├── afwas.php          # Afwasrooster
├── mensen.json        # Configuratie van huisgenoten
├── taken.json         # Configuratie van taken
├── status/            # Statusbestanden per week (wordt automatisch aangemaakt)
│   ├── status_0.json
│   ├── status_1.json
│   └── ...
└── README.md          # Deze documentatie
```

## 🎯 Gebruik

### Taken afvinken

1. Open `index.php` en kies "Schoonmaak"
2. Selecteer je naam
3. Bekijk je toegewezen taken voor deze week
4. Vink taken af door op de "Afvinken" knop te klikken
5. Afgevinkte taken worden groen gemarkeerd

### Afwasrooster bekijken

1. Open `index.php` en kies "Afwas"
2. Het rooster toont automatisch de huidige week
3. Vandaag wordt gemarkeerd met een blauwe rand
4. Het rooster roteert automatisch tussen twee patronen (even/oneven weken)

### Persoon wisselen

- Klik op "Wissel persoon" om uit te loggen en een andere persoon te selecteren

## ⚙️ Configuratie

### Taken configureren

In `taken.json` kun je taken toevoegen of aanpassen:

- **name**: Naam van de taak
- **fixed_to**: Persoon aan wie de taak vast is toegewezen (of `null` voor roulatie)
- **frequency**: `"weekly"` of `"biweekly"`
- **subtasks**: Optionele lijst van subtaken (of `[]` als er geen zijn). Subtaken worden uitgevouwen als losse taken die elk individueel afgevinkt moeten worden. Bij de roulatie telt de groep als één taak (voor load balancing), maar elke subtaak verschijnt als eigen kaart. Elke subtaak kan een eigen frequency hebben. Let op: subtaaknamen moeten uniek zijn over alle taken heen.

Voorbeeld met subtaken (met eigen frequency):
```json
{
    "name": "WC schoonmaken",
    "fixed_to": "Mara",
    "frequency": "weekly",
    "subtasks": [
        { "name": "WC vloer dweilen", "frequency": "weekly" },
        { "name": "WC schrobben", "frequency": "weekly" },
        { "name": "Achter de WC dweilen", "frequency": "biweekly" }
    ]
}
```

Subtaken met `"frequency": "biweekly"` verschijnen alleen in de weken dat ze actief zijn. Als een subtaak geen frequency heeft, erft die de frequency van de hoofdtaak. De hoofdtaak zelf ("WC schoonmaken") verschijnt niet als kaart — alleen de subtaken worden getoond.

Voorbeeld zonder subtaken:
```json
{
    "name": "kattenbak legen/vullen",
    "fixed_to": "Erin",
    "frequency": "weekly",
    "subtasks": []
}
```

### Personen configureren

In `mensen.json` kun je huisgenoten toevoegen:

- **name**: Weergavenaam
- **missed**: Aantal gemiste weken (wordt automatisch bijgewerkt)

### Startdatum aanpassen

De startdatum bepaalt vanaf wanneer de weekindexering begint. Pas deze aan in `schoonmaak.php`:

```php
$startDate = new DateTime('2025-09-05'); // Vrijdag als startdag
```

## 🔄 Hoe de roulatie werkt

1. **Vaste taken** worden eerst toegewezen aan de opgegeven persoon
2. **Niet-vaste taken** worden verdeeld op basis van:
   - Laagste werkbelasting (load balancing)
   - Taakgeschiedenis (vermijd dezelfde taak meerdere weken achter elkaar)
   - Week-rotatie voor deterministische tiebreaking

3. **Tweewekelijkse taken** worden alleen getoond in oneven weken (configureerbaar)

## 💰 Boetesysteem

- Elke week die voorbijgaat wordt automatisch gefinaliseerd
- Niet-afgevinkte taken resulteren in een boete van **€5 per persoon per week**
- Je krijgt maximaal **1 boete per week**, ongeacht hoeveel taken je mist
- Alle boetes gaan naar een collectieve pot
- De pot-stand is zichtbaar voor iedereen

## 🛡️ Beveiliging (optioneel)

Om de app te beveiligen voor alleen lokaal gebruik, uncomment de IP-check in `selecteer.php`:

```php
$allowedIps = ['127.0.0.1', '::1', '83.83.22.123'];
$clientIp = $_SERVER['REMOTE_ADDR'] ?? '';

if (!in_array($clientIp, $allowedIps, true)) {
    http_response_code(403);
    echo "Toegang geweigerd";
    exit;
}
```

## 🐛 Troubleshooting

### Taken verschijnen niet

- Controleer of `taken.json` correct geformatteerd is (gebruik een JSON validator)
- Controleer of de frequency correct is ingesteld (`"weekly"` of `"biweekly"`)

### Boetes worden niet bijgewerkt

- Zorg dat PHP schrijfrechten heeft voor `mensen.json` en de `status/` map
- Controleer of de startdatum correct is ingesteld

### Status bestanden worden niet aangemaakt

- Controleer schrijfrechten in de projectmap
- Zorg dat PHP errors worden getoond tijdens development

## 📱 Browser Support

- Chrome/Edge (laatste 2 versies)
- Firefox (laatste 2 versies)
- Safari (laatste 2 versies)
- Mobile browsers (iOS Safari, Chrome Mobile)

## 🤝 Bijdragen

Suggesties en verbeteringen zijn welkom! Open een issue of pull request.

## 📄 Licentie

Dit project is vrij te gebruiken voor persoonlijke doeleinden.

## ✨ Credits

Ontwikkeld voor eerlijk en geautomatiseerd huishoudelijk taakbeheer.