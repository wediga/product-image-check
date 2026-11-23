# product-image-check

Ein kleines Kommandozeilen-Tool in PHP, das einen Ordner mit Produktbildern prüft, bevor jemand sie von Hand abnehmen muss. Es schreibt für jedes Bild auf, was nicht passt. Sobald ein Bild durchfällt, endet es mit einem Fehlercode, deswegen lässt es sich auch in einen automatischen Ablauf einbauen.

## Was geprüft wird

- **Datei:** Das Bild ist ein vollständiges JPG oder PNG mit höchstens 50 Megapixeln.
- **Auflösung:** Jede Seite hat mindestens 1000 Pixel.
- **Hintergrund:** Mindestens die Hälfte des Bildrands ist weiß oder transparent.
- **Randabstand:** Das Produkt hält auf jeder Seite mindestens 5 % der Bildlänge Abstand. Weiche Schatten und einzelne Staubkörner zählen dabei nicht zum Produkt.

## Beispiel

```text
 ------------------------ ---------------------------------------------------------------------------------------------
  File                     Result
 ------------------------ ---------------------------------------------------------------------------------------------
  01-clean.jpg             ok
  02-cropped.png           Product is only 0 pixels from the left edge, at least 80 required
  03-gray-background.jpg   Only 0.0 % of the edge is white or transparent, wrong background or product fills the frame
  04-too-small.png         Resolution 800 x 600 is too low, each side needs at least 1000 pixels
  05-broken.jpg            File is not a readable JPG or PNG
 ------------------------ ---------------------------------------------------------------------------------------------

1 of 5 images passed
```

## Starten

Gebraucht werden PHP 8.3 mit der Erweiterung GD und Composer.

```bash
composer install
php bin/product-image-check path/to/directory
vendor/bin/phpunit
```

Der Exit-Code ist 0, wenn alle Bilder in Ordnung sind. Fällt mindestens eines durch, ist er 1. Fehlt der Ordner oder enthält er keine Bilder, ist er 2.

## Grenzen

Das Tool arbeitet mit festen Regeln und ohne KI-Modell. Sehr helle Produktteile am Rand zählen deswegen als Schatten, dunkle Schatten dagegen als Produkt. Die Schwellwerte habe ich an 64 frei lizenzierten Fotos von Wikimedia Commons überprüft, die nicht im Repository liegen. Die 5 % Randabstand sind eine eigene Vorgabe und kein Branchenstandard. Unterordner werden nicht durchsucht.

## Entstehung

Den Code habe ich zusammen mit Claude Code geschrieben und danach selbst durchgearbeitet.
