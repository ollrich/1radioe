# 1radiœ

Eigene Radiosender und MP3-Playlists als Dienst in der Sonos-App, gepflegt über eine kleine Webanwendung.

* **SMAPI-Dienst** (`smapi.php`): liefert Sonos die Liste aus Sendern und Playlists, Logos, Beschreibungen, Tracks und Abspieladressen
* **Admin** (`admin/`): Sender anlegen, Stream testen mit automatischer Formaterkennung, Logo hochladen und zuschneiden, Reihenfolge per Drag & Drop, Vorschau wie in Sonos, Diagnose-Log
* **Playlists aus eigenen MP3s:** Upload per Drag & Drop oder FTP, Titel, Interpret, Album und Cover aus den MP3-Tags, alles bearbeitbar, Reihenfolge per Drag & Drop
* PHP 8.1+ und MySQL/MariaDB, kein Framework, kein Build-Schritt: per FTP hochladen und fertig

Unabhängiges Projekt, nicht mit Sonos, Inc. verbunden.

## Aufbau

```
1radioe/              → in den Domain-Ordner laden, ergibt https://eichhof.co/1radioe/
  smapi.php           SMAPI-Endpoint für Sonos
  admin/              Verwaltung (Login mit Passwort und TOTP)
  lib/                Programmcode, per .htaccess gesperrt
1radioe-private/      → AUSSERHALB des Domain-Ordners ablegen
  config.sample.php   Vorlage, als config.php kopieren
  inbox/              FTP-Eingang für MP3s (wird angelegt)
  tmp/                Zwischenablage für Uploads (wird angelegt)
1radioe-media/        → legt die App selbst neben 1radioe an: Logos, Cover, tracks/
```

Der Ordner `1radioe` enthält nur Programmcode. Alles, was du hochlädst, liegt in `1radioe-media` und `1radioe-private`. Ein Update darf `1radioe` deshalb komplett ersetzen, ohne dass Logos oder Tracks verloren gehen.

Die App sucht `1radioe-private/config.php` in den Ordnern oberhalb ihres eigenen Ordners. Liegt die Config versehentlich im Web-Verzeichnis, warnt die Systemseite, und die mitgelieferte `.htaccess` sperrt den Ordner trotzdem. `1radioe-media` liegt bewusst im Web-Verzeichnis, weil die Sonos-Lautsprecher Bilder und MP3s direkt abrufen; die App legt dort eine `.htaccess` an, die Verzeichnislisten und Skripte sperrt. Andere Orte lassen sich in der Config mit `media_dir` und `media_url` festlegen.

## Installation auf All-Inkl

1. **Datenbank:** Im KAS eine MySQL-Datenbank anlegen und Name, Benutzer und Passwort notieren.
2. **Config:** Den Ordner `1radioe-private` per FTP ins Hauptverzeichnis des Webspace laden, also neben den Ordner `eichhof.co`, nicht hinein. Darin `config.sample.php` nach `config.php` kopieren und die Datenbankdaten eintragen. `password_hash` und `totp_secret` bleiben leer.
3. **App:** Den Ordner `1radioe` in den Ordner `eichhof.co` laden. Im KAS PHP 8.1 oder neuer für die Domain einstellen.
4. **Einrichtung:** `https://eichhof.co/1radioe/admin/` öffnen. Der Assistent fragt ein Passwort ab (mindestens 12 Zeichen), zeigt einen QR-Code für die Authenticator-App und danach einen Block für die `config.php`. Diesen einfügen und die Datei wieder hochladen. Der QR-Code wird im Browser erzeugt, das Geheimnis verlässt den Server nicht.
5. **Anmelden** mit Benutzer, Passwort und dem 6-stelligen Code.
6. **Sender:** Bei leerer Liste übernimmt „Die drei Sender aus RADIOE Lite übernehmen“ die bisherigen Sender mit ihren alten IDs, damit Favoriten und Wecker in Sonos weiter funktionieren.

## Update

Den Ordner `1radioe` per FTP hochladen und dabei ruhig komplett ersetzen. Beim nächsten Öffnen des Admins erweitert die App bei Bedarf die Datenbank und legt fehlende Ordner an. Die Systemseite zeigt unter Medien, Track-Ordner, FTP-Eingang und Upload-Puffer, ob alles beschreibbar ist.

**Von 1.0 oder 1.1.0:** Diese Versionen speicherten Logos und Tracks noch in `1radioe/media/`. Ab 1.1.1 zieht die App sie beim ersten Aufruf automatisch nach `1radioe-media/` um, aber nur, wenn sie beim Update noch da sind. Also beim Wechsel von diesen Versionen einmalig nur Dateien überschreiben und den alten Ordner nicht löschen.

**Auf 1.2:** Den Ordner `1radioe` ersetzen. Die Datenbank bekommt eine neue Spalte für die Erkennung doppelter MP3s, das erledigt der erste Aufruf des Admins oder die nächste Anfrage von Sonos. Was sich geändert hat, steht in [CHANGELOG.md](CHANGELOG.md).

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

### Playlists

* **Anlegen:** „Playlist hinzufügen“ mit Name, Beschreibung und Cover. Die Playlist erscheint als Kachel zwischen den Sendern und lässt sich wie diese sortieren und deaktivieren.
* **MP3s hinzufügen:** in der Playlist hochladen oder per Drag & Drop ablegen. Uploads laufen in 2-MB-Teilen, die Upload-Grenze des Hosters spielt also keine Rolle (höchstens 300 MB je Datei). Titel, Interpret, Album, Cover und Dauer kommen aus den MP3-Tags (ID3v1 und v2), fehlt der Titel, wird der Dateiname genommen.
* **Per FTP:** Dateien in `1radioe-private/inbox/` legen und unter „Tracks“ auf „FTP-Eingang importieren“ klicken.
* **Per FTP:** Dateien, die gerade noch hochgeladen werden (in den letzten 15 Sekunden geändert), überspringt der Import. Einfach kurz danach noch einmal klicken.
* **Bibliothek:** Jeder Track liegt nur einmal auf dem Server und kann in mehreren Playlists stehen. „Aus Bibliothek hinzufügen“ ordnet vorhandene Tracks zu, das ✕ entfernt einen Track nur aus der Playlist, gelöscht wird er unter „Tracks“.
* **Doppelte Dateien:** Eine MP3, die schon in der Bibliothek liegt, erkennt die App am Inhalt (nicht am Namen) und speichert sie nicht noch einmal. Beim Upload in eine Playlist kommt der vorhandene Track dorthin. Fehlt die Datei eines Tracks auf dem Server, stellt ein erneuter Upload derselben MP3 sie wieder her.
* **Bearbeiten:** Titel, Interpret, Album und Cover je Track. Ohne eigenes Cover zeigt Sonos das Bild der Playlist.
* **In Sonos:** Die Playlist öffnet die Trackliste, „Abspielen“ legt alle Tracks in der festgelegten Reihenfolge in die Warteschlange. Zufallswiedergabe und Wiederholen schaltest du in der Sonos-App. Leere Playlists und Tracks, deren Datei fehlt, zeigt Sonos nicht an.
* Die MP3-Dateien laden die Lautsprecher direkt von deinem Server. Sie liegen unter zufälligen, nicht erratbaren Namen ohne Verzeichnisliste, sind aber für jeden abrufbar, der einen Link kennt.

## Sicherheit

* Passwort nur als Hash in der Config, zweiter Faktor per TOTP mit Schutz gegen wiederverwendete Codes
* Sperre nach 5 Fehlversuchen pro IP für 15 Minuten, zusätzlich eine globale Bremse
* Sitzungen nur über HTTPS, `SameSite=Strict`, Ablauf nach 2 Stunden Inaktivität
* CSRF-Schutz für alle Änderungen, strenge Content Security Policy ohne Inline-Skripte
* Logos und Cover werden mit GD neu kodiert, `1radioe-media/` führt keine Skripte aus und zeigt keine Verzeichnisliste
* MP3s werden vor dem Import geprüft (echte MPEG-Frames), getarnte Dateien abgelehnt, Texte aus Tags und Dateinamen von ungültigen Zeichen befreit
* Stream-Test und Logo-Download erreichen keine internen Netze
* SMAPI-Antworten gegen das offizielle Sonos-Schema geprüft, XML mit DTD wird abgelehnt

## Voraussetzungen

PHP 8.1 oder neuer mit `pdo_mysql`, `curl`, `gd`, `dom` und `mbstring`, MySQL 5.7+ oder MariaDB 10.3+, HTTPS. PHP muss neben dem App-Ordner einen Ordner anlegen dürfen (bei All-Inkl gegeben). Die Systemseite zeigt, ob alles vorhanden ist.

## Mitgelieferte Bibliotheken

[SortableJS](https://github.com/SortableJS/Sortable) 1.15.6, [Cropper.js](https://github.com/fengyuanchen/cropperjs) 1.6.2 und [qrcode-generator](https://github.com/kazuhikoarase/qrcode-generator) 1.4.4, alle MIT-Lizenz, lokal eingebunden.
