# 1radiœ

Eigene Radiosender als Dienst in der Sonos-App, gepflegt über eine kleine Webanwendung.

* **SMAPI-Dienst** (`smapi.php`): liefert Sonos die Senderliste, Logos, Beschreibungen und Stream-Adressen
* **Admin** (`admin/`): Sender anlegen, Stream testen mit automatischer Formaterkennung, Logo hochladen und zuschneiden, Reihenfolge per Drag & Drop, Vorschau wie in Sonos, Diagnose-Log
* PHP 8.1+ und MySQL/MariaDB, kein Framework, kein Build-Schritt: per FTP hochladen und fertig

Unabhängiges Projekt, nicht mit Sonos, Inc. verbunden.

## Aufbau

```
1radioe/              → in den Domain-Ordner laden, ergibt https://eichhof.co/1radioe/
  smapi.php           SMAPI-Endpoint für Sonos
  admin/              Verwaltung (Login mit Passwort und TOTP)
  media/              hochgeladene Logos (muss beschreibbar sein)
  lib/                Programmcode, per .htaccess gesperrt
1radioe-private/      → AUSSERHALB des Domain-Ordners ablegen
  config.sample.php   Vorlage, als config.php kopieren
```

Die App sucht `1radioe-private/config.php` in den Ordnern oberhalb ihres eigenen Ordners. Liegt die Config versehentlich im Web-Verzeichnis, warnt die Systemseite, und die mitgelieferte `.htaccess` sperrt den Ordner trotzdem.

## Installation auf All-Inkl

1. **Datenbank:** Im KAS eine MySQL-Datenbank anlegen und Name, Benutzer und Passwort notieren.
2. **Config:** Den Ordner `1radioe-private` per FTP ins Hauptverzeichnis des Webspace laden, also neben den Ordner `eichhof.co`, nicht hinein. Darin `config.sample.php` nach `config.php` kopieren und die Datenbankdaten eintragen. `password_hash` und `totp_secret` bleiben leer.
3. **App:** Den Ordner `1radioe` in den Ordner `eichhof.co` laden. Im KAS PHP 8.1 oder neuer für die Domain einstellen.
4. **Einrichtung:** `https://eichhof.co/1radioe/admin/` öffnen. Der Assistent fragt ein Passwort ab (mindestens 12 Zeichen), zeigt einen QR-Code für die Authenticator-App und danach einen Block für die `config.php`. Diesen einfügen und die Datei wieder hochladen. Der QR-Code wird im Browser erzeugt, das Geheimnis verlässt den Server nicht.
5. **Anmelden** mit Benutzer, Passwort und dem 6-stelligen Code.
6. **Sender:** Bei leerer Liste übernimmt „Die drei Sender aus RADIOE Lite übernehmen“ die bisherigen Sender mit ihren alten IDs, damit Favoriten und Wecker in Sonos weiter funktionieren.

## Umstellung im Sonos-Entwicklerportal

Die Systemseite im Admin zeigt alle Werte mit Kopier-Knopf. In der bestehenden Integration:

* **General Information:** Service Name `1radiœ`
* **Sonos Music API:** SMAPI Endpoint `https://eichhof.co/1radioe/smapi.php` (Integration ID bleibt `co.eichhof.radioe`)
* **Brand Assets:** neues Icon und Logo von 1radiœ hochladen
* **Localization Resources:** Texte an den neuen Namen anpassen
* **Service Configuration:** Refresh, dann Send

Danach in der Sonos-App prüfen. Zeigt sie noch den alten Namen, die App neu starten. Erst wenn alles läuft, den alten Ordner `/radioe` (Lite) löschen.

## Bedienung

* **Sender hinzufügen:** Stream-URL eintragen und „Stream testen“. Der Test erkennt MP3, AAC, Ogg Vorbis, FLAC und HLS, folgt Weiterleitungen und löst `.pls`- und `.m3u`-Playlists auf. Format, Name und Beschreibung werden aus dem Stream übernommen, wenn die Felder leer sind. Ogg Opus lehnt der Test ab, weil Sonos es nicht abspielt.
* **Logo:** hochladen oder per URL laden, im Editor zuschneiden, zoomen, drehen, einpassen oder füllen, Hintergrund für transparente Bereiche wählen. Gespeichert wird immer 600 × 600 px.
* **Reihenfolge:** Kacheln per Drag & Drop verschieben. Die Vorschau entspricht der Liste in Sonos.
* **Inaktiv:** blendet einen Sender in Sonos aus, ohne ihn zu löschen.
* **Alle Streams prüfen:** testet alle Sender. Ein roter Punkt markiert Probleme.
* Änderungen erreichen Sonos automatisch, spätestens beim nächsten Öffnen des Dienstes.

## Sicherheit

* Passwort nur als Hash in der Config, zweiter Faktor per TOTP mit Schutz gegen wiederverwendete Codes
* Sperre nach 5 Fehlversuchen pro IP für 15 Minuten, zusätzlich eine globale Bremse
* Sitzungen nur über HTTPS, `SameSite=Strict`, Ablauf nach 2 Stunden Inaktivität
* CSRF-Schutz für alle Änderungen, strenge Content Security Policy ohne Inline-Skripte
* Logos werden mit GD neu kodiert, `media/` führt keine Skripte aus
* Stream-Test und Logo-Download erreichen keine internen Netze
* SMAPI-Antworten gegen das offizielle Sonos-Schema geprüft, XML mit DTD wird abgelehnt

## Voraussetzungen

PHP 8.1 oder neuer mit `pdo_mysql`, `curl`, `gd` und `dom`, MySQL 5.7+ oder MariaDB 10.3+, HTTPS. Die Systemseite zeigt, ob alles vorhanden ist.

## Mitgelieferte Bibliotheken

[SortableJS](https://github.com/SortableJS/Sortable) 1.15.6, [Cropper.js](https://github.com/fengyuanchen/cropperjs) 1.6.2 und [qrcode-generator](https://github.com/kazuhikoarase/qrcode-generator) 1.4.4, alle MIT-Lizenz, lokal eingebunden.
