# 1radiœ

Eigene Radiosender und MP3-Playlists als Dienst in der Sonos-App, gepflegt über eine kleine Webanwendung.

* **SMAPI-Dienst** (`smapi.php`): liefert Sonos die Liste aus Sendern, Playlists und Kategorien, Logos, Beschreibungen, Tracks und Abspieladressen
* **Admin** (`admin/`): Sender anlegen, Stream testen mit automatischer Formaterkennung, Logo hochladen und zuschneiden, Reihenfolge per Drag & Drop, Vorschau wie in Sonos, Diagnose-Log
* **Playlists aus eigenen MP3s:** Upload per Drag & Drop oder FTP, Titel, Interpret, Album und Cover aus den MP3-Tags, alles bearbeitbar, Reihenfolge per Drag & Drop
* PHP 8.1+ und MySQL/MariaDB, kein Framework, kein Build-Schritt
* Automatisches Ausrollen: ein Push auf `main` aktualisiert den Server per GitHub-Webhook

Unabhängiges Projekt, nicht mit Sonos, Inc. verbunden.

## Aufbau

```
app/                  Programmcode, liegt außerhalb des Web-Verzeichnisses
www/                  Web-Verzeichnis der Domain https://1radioe.net
  smapi.php           SMAPI-Endpoint für Sonos
  admin/              Verwaltung (Login mit Passwort und TOTP)
  deploy.php          Ziel des GitHub-Webhooks
  robots.txt          sperrt Suchmaschinen
  media/              Logos, Cover, tracks/ (legt die App an, nicht in Git)
config.sample.php     Vorlage für 1radioe-private/config.php
DEPLOY-SETUP.md       Einrichtung auf All-Inkl Schritt für Schritt
```

Neben dem Checkout liegt auf dem Server der Ordner `1radioe-private` mit `config.php`, Webhook-Secret, Deploy-Log, FTP-Eingang `inbox/` und Upload-Puffer `tmp/`. Er ist nie Teil des Repositorys. Die App sucht ihn in den Ordnern oberhalb des Checkouts.

`www/media/` liegt bewusst im Web-Verzeichnis, weil die Sonos-Lautsprecher Bilder und MP3s direkt abrufen. Die App legt dort eine `.htaccess` an, die Verzeichnislisten und Skripte sperrt. Der Ordner ist nicht in Git, deshalb lässt ihn das Ausrollen unberührt. Andere Orte lassen sich in der Config mit `media_dir` und `media_url` festlegen.

## Installation und Update

Die Einrichtung auf All-Inkl mit Deploy-Key, Webhook und Umzug von `eichhof.co/1radioe` beschreibt [DEPLOY-SETUP.md](DEPLOY-SETUP.md).

Danach ist jedes Update ein Push auf `main`. Gearbeitet wird auf Branches, ein Merge nach `main` ist sofort live. Neue Spalten und Tabellen legt die App beim nächsten Aufruf von Admin oder Sonos selbst an. Was sich geändert hat, steht in [CHANGELOG.md](CHANGELOG.md).

Beim ersten Öffnen von `/admin/` fragt ein Assistent ein Passwort ab (mindestens 12 Zeichen), zeigt einen QR-Code für die Authenticator-App und danach einen Block für die `config.php`. Der QR-Code wird im Browser erzeugt, das Geheimnis verlässt den Server nicht.

## Lokal entwickeln

```bash
cp config.sample.php 1radioe-private/config.php   # Ordner vorher anlegen, Datenbank eintragen
php -S 127.0.0.1:8080 -t www
```

`1radioe-private/` im Checkout ist von Git ausgeschlossen. Mit `'allow_private_urls' => true` testet der Stream-Test auch lokale Adressen. Vor jedem Merge nach `main`: `php -l` über alle PHP-Dateien.

## Sonos-Entwicklerportal

Die Systemseite im Admin zeigt alle Werte mit Kopier-Knopf. In der Integration `co.eichhof.radioe`:

* **Sonos Music API:** SMAPI Endpoint `https://1radioe.net/smapi.php`
* **Service Configuration:** nach jeder Änderung Refresh, dann Send

## Bedienung

* **Sender hinzufügen:** Stream-URL eintragen und „Stream testen“. Der Test erkennt MP3, AAC, Ogg Vorbis, FLAC und HLS, folgt Weiterleitungen und löst `.pls`- und `.m3u`-Playlists auf. Format, Name und Beschreibung werden aus dem Stream übernommen, wenn die Felder leer sind. Ogg Opus lehnt der Test ab, weil Sonos es nicht abspielt.
* **Logo:** hochladen oder per URL laden, im Editor zuschneiden, zoomen, drehen, einpassen oder füllen, Hintergrund für transparente Bereiche wählen. Gespeichert wird immer 600 × 600 px.
* **Reihenfolge:** Kacheln per Drag & Drop verschieben. Die Vorschau entspricht der Liste in Sonos.
* **Inaktiv:** blendet einen Sender in Sonos aus, ohne ihn zu löschen.
* **Alle Streams prüfen:** testet alle Sender. Ein roter Punkt markiert Probleme.
* Änderungen erreichen Sonos automatisch, spätestens beim nächsten Öffnen des Dienstes.

### Kategorien

* **Anlegen:** „Kategorie hinzufügen“ mit Name, Beschreibung und Bild. Ohne eigenes Bild zeigt die Kategorie ein Raster aus den ersten vier Logos ihrer Sender.
* **Füllen:** In der Kategorie „Vorhandene zuordnen“ oder „Sender hinzufügen“. Jeder Sender und jede Playlist steckt in höchstens einer Kategorie, alles andere steht direkt in der Liste. Im Editor wählst du die Kategorie im Feld „Kategorie“, dort nimmst du einen Eintrag auch wieder heraus.
* **In Sonos:** Die Kategorie ist ein Ordner, darin die Sender und Playlists in deiner Reihenfolge. Leere Kategorien zeigt Sonos nicht an. Favoriten und Wecker bleiben beim Einsortieren erhalten.
* **Löschen:** Die Einträge einer gelöschten Kategorie stehen danach wieder direkt in der Liste.

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
* Logos und Cover werden mit GD neu kodiert, `www/media/` führt keine Skripte aus und zeigt keine Verzeichnisliste
* Programmcode, Git-Verzeichnis und Config liegen außerhalb des Web-Verzeichnisses
* Deploy nur mit gültiger Webhook-Signatur (HMAC-SHA256), ohne Secret wird nichts ausgerollt
* MP3s werden vor dem Import geprüft (echte MPEG-Frames), getarnte Dateien abgelehnt, Texte aus Tags und Dateinamen von ungültigen Zeichen befreit
* Stream-Test und Logo-Download erreichen keine internen Netze
* SMAPI-Antworten gegen das offizielle Sonos-Schema geprüft, XML mit DTD wird abgelehnt

## Voraussetzungen

PHP 8.1 oder neuer mit `pdo_mysql`, `curl`, `gd`, `dom` und `mbstring`, MySQL 5.7+ oder MariaDB 10.3+, HTTPS. PHP muss in `www/media/` und `1radioe-private/` schreiben dürfen. Für das automatische Ausrollen braucht der Server SSH, Git und die PHP-Funktion `exec()` (bei All-Inkl gegeben). Die Systemseite zeigt, ob alles vorhanden ist.

## Mitgelieferte Bibliotheken

[SortableJS](https://github.com/SortableJS/Sortable) 1.15.6, [Cropper.js](https://github.com/fengyuanchen/cropperjs) 1.6.2 und [qrcode-generator](https://github.com/kazuhikoarase/qrcode-generator) 1.4.4, alle MIT-Lizenz, lokal eingebunden.

## Lizenz

[MIT](LICENSE)
