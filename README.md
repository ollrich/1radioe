# 1radiœ

Eigene Radiosender und MP3-Playlists als Dienst in der Sonos-App, dazu eine Web-App mit Player unter 1radioe.net, gepflegt über eine kleine Verwaltung.

* **Web-App** (`/`): alle Sender mit Player im Browser, Medientasten, Hörstatus
* **SMAPI-Dienst** (`smapi.php`): liefert Sonos die Liste aus Sendern, Playlists und Kategorien, Logos, Beschreibungen, Tracks und Abspieladressen
* **Admin** (`admin/`): Sender anlegen, Stream testen mit automatischer Formaterkennung, Logo hochladen und zuschneiden, Reihenfolge per Drag & Drop, Vorschau wie in Sonos, Diagnose-Log
* **Playlists aus eigenen MP3s:** Upload per Drag & Drop oder FTP, Titel, Interpret, Album und Cover aus den MP3-Tags, alles bearbeitbar, Reihenfolge per Drag & Drop
* PHP 8.1+ und MySQL/MariaDB, kein Framework, kein Build-Schritt
* Automatisches Ausrollen: ein Push auf `main` aktualisiert den Server per GitHub-Webhook
* **Hörstatus:** zeigt, welchen Sender Ollie gerade hört, ohne Gerät, Ort oder Quelle (`/api/status`)
* **Playlist-Dateien und JSON:** alle Sender als `/streams.m3u` und `/streams.pls` für VLC und als `/api/stations.json` für eine eigene App

Unabhängiges Projekt, nicht mit Sonos, Inc. verbunden.

## Aufbau

```
1radioe-private/      Config, Secrets, Deploy-Log, FTP-Eingang (nur auf dem Server und lokal, nicht in Git)
app/                  Programmcode, liegt außerhalb des Web-Verzeichnisses
www/                  Web-Verzeichnis der Domain https://1radioe.net
  index.php           Web-App mit Player
  assets/             CSS, JavaScript, Schrift Mona Sans der Web-App
  smapi.php           SMAPI-Endpoint für Sonos
  admin/              Verwaltung (Login mit Passwort und TOTP)
  deploy.php          Ziel des GitHub-Webhooks
  robots.txt          sperrt Suchmaschinen
  media/              Logos, Cover, tracks/ (legt die App an, nicht in Git)
config.sample.php     Vorlage für 1radioe-private/config.php
DEPLOY-SETUP.md       Einrichtung auf All-Inkl Schritt für Schritt
```

Im Checkout neben `www/` liegt der Ordner `1radioe-private` mit `config.php`, Webhook-Secret, Deploy-Log, FTP-Eingang `inbox/` und Upload-Puffer `tmp/`. Er liegt außerhalb des Web-Verzeichnisses und ist von Git ausgeschlossen, das Ausrollen lässt ihn unberührt. So bleibt alles für 1radiœ in einem Ordner. Die App sucht ihn zuerst im Checkout, dann in den Ordnern darüber.

`www/media/` liegt bewusst im Web-Verzeichnis, weil die Sonos-Lautsprecher Bilder und MP3s direkt abrufen. Die App legt dort eine `.htaccess` an, die Verzeichnislisten und Skripte sperrt. Der Ordner ist nicht in Git, deshalb lässt ihn das Ausrollen unberührt. Andere Orte lassen sich in der Config mit `media_dir` und `media_url` festlegen.

## Installation und Update

Die Einrichtung auf All-Inkl mit Webhook und Umzug von `eichhof.co/1radioe` beschreibt [DEPLOY-SETUP.md](DEPLOY-SETUP.md).

Danach ist jedes Update ein Push auf `main`. Gearbeitet wird auf Branches, ein Merge nach `main` ist sofort live. Neue Spalten und Tabellen legt die App beim nächsten Aufruf von Admin oder Sonos selbst an. Was sich geändert hat, steht in [CHANGELOG.md](CHANGELOG.md).

Beim ersten Öffnen von `/admin/` fragt ein Assistent ein Passwort ab (mindestens 12 Zeichen), zeigt einen QR-Code für die Authenticator-App und danach einen Block für die `config.php`. Der QR-Code wird im Browser erzeugt, das Geheimnis verlässt den Server nicht.

## Lokal entwickeln

```bash
cp config.sample.php 1radioe-private/config.php   # Ordner vorher anlegen, Datenbank eintragen
php -S 127.0.0.1:8080 -t www app/dev-router.php
```

`app/dev-router.php` bildet die Adressen ohne Dateiendung aus `www/.htaccess` nach. `1radioe-private/` im Checkout ist von Git ausgeschlossen. Mit `'allow_private_urls' => true` testet der Stream-Test auch lokale Adressen. Vor jedem Merge nach `main`: `php -l` über alle PHP-Dateien.

## Sonos-Entwicklerportal

Die Systemseite im Admin zeigt alle Werte mit Kopier-Knopf. In der Integration `co.eichhof.radioe`:

* **Sonos Music API:** SMAPI Endpoint `https://1radioe.net/smapi.php`
* **Service Configuration:** nach jeder Änderung Refresh, dann Send

## Bedienung

* **Sender hinzufügen:** Stream-URL eintragen und „Stream testen“. Der Test erkennt MP3, AAC, Ogg Vorbis, FLAC und HLS, folgt Weiterleitungen und löst `.pls`- und `.m3u`-Playlists auf. Format, Name und Beschreibung werden aus dem Stream übernommen, wenn die Felder leer sind. Ogg Opus lehnt der Test ab, weil Sonos es nicht abspielt.
* **Logo:** hochladen oder per URL laden, im Editor zuschneiden, zoomen, drehen, einpassen oder füllen, Hintergrund für transparente Bereiche wählen. Gespeichert wird immer 600 × 600 px.
* **Reihenfolge:** Kacheln per Drag & Drop verschieben. Die Vorschau entspricht der Liste in Sonos.
* **Inaktiv:** blendet einen Sender in Sonos aus, ohne ihn zu löschen.
* **Alle Streams prüfen:** testet alle Sender. Ein roter Punkt markiert Probleme, die Marke „HTTP“ Sender, die nur per HTTP erreichbar sind und deshalb nicht in der Web-App spielen.
* **Website:** Link zur Seite des Senders, erscheint in der Web-App unter „Über den Sender“.
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

## Web-App

`https://1radioe.net/` zeigt alle aktiven Sender, keine MP3-Playlists.

* **Desktop:** Player in der Mitte, darunter die Sender als Skala mit Nadel. Ein Klick spielt, ein zweiter pausiert. Kategorien öffnen eine eigene Skala. Sind es zu viele für die Breite, blättern Pfeile die Skala.
* **Handy:** Liste mit aufklappbaren Kategorien, nach dem ersten Start ein angedockter Player, der sich zur Vollansicht aufziehen lässt.
* **Vor/Zurück** bleibt in der Ebene, auf der der Sender gestartet wurde, mit Umlauf, und überspringt Ordner. Medientasten und Sperrbildschirm folgen derselben Regel.
* **Tastatur:** Leertaste, Pfeiltasten, 1 bis 0, Esc. Erklärt im (?) über der Skala.
* **Wiedergabe:** direkt vom Stream des Senders, nie über diesen Server. Pausieren legt auf, Abspielen verbindet neu. Bei Abbruch verbindet der Player selbst neu und zeigt „Verbindet“.
* **Song:** Schickt der Stream Titel mit (ICY-Metadaten bei Shoutcast und Icecast), zeigt der Player Titel und Interpret, im angedockten Player und in der Liste statt „Live“, auf dem Sperrbildschirm und in den Mediensteuerungen. Der Browser kann die Daten aus dem Stream nicht selbst lesen, deshalb liest sie der Server (`/api/nowplaying`, höchstens alle 20 Sekunden je Sender). Abgefragt wird nur, solange der Player spielt. HLS-Streams und Streams ohne Titel zeigen nur den Sender. Wiederholt ein Stream nur den Sendernamen, wird nichts angezeigt, „ByteFM - Nachrichten“ wird zu „Nachrichten“.
* **Hörstatus:** Die Mint-Balken zeigen beim Darüberfahren mit der Maus, wer was hört, etwa „Ollie hört gerade TSF Jazz“ an einer Kategorie. Auf dem Handy zeigt ein Tipp auf die Balken diese Info, ohne den Sender zu starten.
* **Gespeichert** wird im Browser nur die Lautstärke. Keine Cookies für Besucher, kein Tracking, keine externen Ressourcen. Nur Ollies gemerkter Browser hat das Hör-Cookie.
* **Ohne JavaScript** eine Liste mit Links zu den Streams, Impressum und Datenschutz als Abschnitte.

Die Texte von Impressum und Datenschutz in `www/index.php` sind Platzhalter.

## Playlist-Dateien und App-Schnittstelle

Alle aktiven Sender, nie MP3-Playlists oder Tracks. Die Reihenfolge ist die des Admins: die oberste Ebene wie in Sonos, die Sender einer Kategorie an der Stelle der Kategorie. Eine inaktive Kategorie blendet ihre Sender aus.

| Adresse | Inhalt |
|---|---|
| `/streams.m3u` | M3U mit Namen, für VLC: „Medien, Netzwerkstream öffnen“ |
| `/streams.pls` | dasselbe als PLS |
| `/s/<id>.m3u` | ein Sender |
| `/api/stations.json` | alles für eine eigene App |
| `/api/status` | laufende Sender |
| `/api/nowplaying?id=<id>` | aktueller Song eines Senders, `{"id": …, "track": {"artist": …, "title": …}}` oder `"track": null` |
| `/api/listen` | Meldungen des eigenen Browsers, nur mit Hör-Cookie |

Die Adressen in den Playlists zeigen direkt auf die Streams der Sender. Hören über VLC fließt bewusst nicht in den Hörstatus ein.

**`stations.json`**

```json
{
  "version": "<Katalog-Stand>",
  "generated": "2026-11-12T16:00:00+01:00",
  "status_url": "https://1radioe.net/api/status",
  "ping_url": "https://1radioe.net/api/ping",
  "nowplaying_url": "https://1radioe.net/api/nowplaying?id={id}",
  "categories": [ { "id": "jazz", "name": "Jazz", "sort": 3, "logo": null } ],
  "stations": [
    {
      "id": "radio-swiss-jazz",
      "name": "Radio Swiss Jazz",
      "description": "…",
      "website": "https://www.radioswissjazz.ch",
      "category": "jazz",
      "sort": 1,
      "streams": [ { "url": "https://…", "format": "audio/mpeg", "https": true } ],
      "logo": { "url": "https://1radioe.net/media/….jpg", "sha256": "…", "size": 600 }
    }
  ]
}
```

* `id` ist die SMAPI-ID und ändert sich nie.
* `sort` zählt innerhalb der Ebene: Sender ohne Kategorie und Kategorien teilen sich die Positionen der obersten Ebene, Sender einer Kategorie zählen darin ab 1.
* `version` ändert sich mit jeder Änderung im Admin. Die Antwort trägt einen `ETag`. Wer ihn mit `If-None-Match` mitschickt, bekommt `304`, solange sich nichts geändert hat.
* `logo` ist `null`, wenn es kein Bild gibt. Bei Kategorien ohne eigenes Bild ist es das Raster aus vier Logos.

## Hörstatus

Läuft bei Ollie ein Sender, soll das die Web-App an diesem Sender zeigen. Mehr nicht: nie welches Gerät, nie ob Sonos oder Browser, nie wo.

* **Modell:** wie „Now Playing“ bei Last.fm. Jede Quelle meldet beim Start und danach jede Minute „läuft“, beim Stoppen „aus“. Ein Sender gilt als laufend, solange die letzte Meldung jünger als 150 Sekunden ist und kein „aus“ folgte. Fällt eine Quelle still aus, erlischt der Status von selbst.
* **Gespeichert** wird je Sender nur der Zeitpunkt der letzten Meldung, ob „aus“ kam und seit wann er läuft, für das Posting (`r1_listen_status`).
* **Sonos:** `getMediaURI` für einen Sender startet, `reportPlaySeconds` hält am Leben (Antwort `interval` 60), `reportPlayStatus` beendet. Tracks und Playlists zählen nicht.
* **Abfrage:** `GET /api/status` liefert `{"active": ["<smapi_id>", …], "ttl": 150}`.
* **Browser:** Nach dem Login im Admin lässt sich ein Browser zum Hören merken. Er bekommt das Cookie `r1listen`, das nur Meldungen an `POST /api/listen` erlaubt und nie den Admin öffnet. Der Player in der Web-App meldet dann „läuft“ beim Start und jede Minute, „aus“ bei Pause, Fehler, Senderwechsel und beim Schließen. In diesem Browser zeigt die Web-App oben „Hören wird erfasst“, ein Klick pausiert das für die Sitzung. Geräte verwalten und widerrufen: System → Hörgeräte.
* **`POST /api/listen`:** Body `{"station": "<smapi_id>", "state": "on" | "off"}`. Verlangt das Cookie und eine `Origin` dieser Seite, „on“ zusätzlich den Header `X-1radioe: 1`.
* **Testen:** Admin → System → Hörstatus, dazu das Diagnose-Log, das bei jeder Meldung die übergebenen Werte zeigt.

## Posting zu Bluesky und Mastodon

Startet Ollie einen Sender, egal wo, und läuft er nach einer Wartezeit noch, geht ein Beitrag an Bluesky und Mastodon. Eingerichtet im Admin unter „Posting“.

* **Auslöser:** der Hörstatus. Eine Hörsitzung beginnt, wenn ein Sender von „läuft nicht“ auf „läuft“ wechselt. Die erste Meldung nach Ablauf der Wartezeit (Standard 3 Minuten) übernimmt die Sitzung, auch bei mehreren gleichzeitigen Meldungen genau einmal. Durchzappen und Fehlstarts lösen so nichts aus. Kein Cron nötig.
* **Bremsen:** Schalter „Automatisch posten“, pro Sender ein Schalter „Posten“ im Editor, Sperrfrist je Sender (Standard 6 Stunden), Höchstzahl pro Tag (Standard 4). Wer in der Web-App das Erfassen aussetzt, postet auch nicht.
* **Text:** Vorlage mit `{sender}`, `{link}`, `{website}` und `{hashtags}`. Hashtags gibt es für alle Sender und zusätzlich pro Sender. Ist ein Beitrag zu lang (Bluesky 300 Grapheme, Mastodon laut Instanz, Links zählen dort 23 Zeichen), fallen Hashtags von hinten weg. Die Vorschau im Admin zeigt beide Fassungen mit Länge.
* **Link:** `{link}` zeigt auf `1radioe.net/s/<smapi_id>`. Die Adresse leitet auf `/?s=<smapi_id>` weiter, die Web-App mit dem Sender im Player und Open-Graph-Tags für die Link-Karte.
* **Bluesky:** Handle, App-Passwort und PDS (Standard `https://bsky.social`). Links und Hashtags als Facets, Links gekürzt angezeigt, dazu eine Link-Karte mit Senderlogo.
* **Mastodon:** Instanz und Access-Token mit der Berechtigung `write:statuses`, Sichtbarkeit wählbar. Die Link-Karte baut Mastodon aus der Senderseite. Ein `Idempotency-Key` verhindert doppelte Beiträge.
* **Ohne Wartezeit für Sonos:** Der Beitrag geht raus, nachdem die Antwort gesendet ist (`fastcgi_finish_request`). Fehlt das auf dem Server, sagt es der Admin.
* **Geheimnisse:** App-Passwort und Token liegen verschlüsselt in der Datenbank (libsodium). Der Schlüssel steht als `secret_key` in `config.php`, der Admin zeigt einen frisch erzeugten, solange keiner gesetzt ist. Im Admin steht nur „gespeichert“.
* **Protokoll:** Admin → Posting zeigt die letzten Beiträge mit Ergebnis, Links und Fehlern, auch übersprungene. Die letzten 500 bleiben erhalten.
* **Testen:** „Verbindung prüfen“ meldet sich an, ohne zu posten. „Testbeitrag senden“ postet wirklich, ohne Wartezeit und Bremsen.

## Sicherheit

* Passwort nur als Hash in der Config, zweiter Faktor per TOTP mit Schutz gegen wiederverwendete Codes
* Sperre nach 5 Fehlversuchen pro IP für 15 Minuten, zusätzlich eine globale Bremse
* Sitzungen nur über HTTPS, `SameSite=Strict`, Ablauf nach 2 Stunden Inaktivität
* CSRF-Schutz für alle Änderungen, strenge Content Security Policy ohne Inline-Skripte
* Logos und Cover werden mit GD neu kodiert, `www/media/` führt keine Skripte aus und zeigt keine Verzeichnisliste
* Programmcode, Git-Verzeichnis und Config liegen außerhalb des Web-Verzeichnisses
* Deploy nur mit gültiger Webhook-Signatur (HMAC-SHA256), ohne Secret wird nichts ausgerollt
* MP3s werden vor dem Import geprüft (echte MPEG-Frames), getarnte Dateien abgelehnt, Texte aus Tags und Dateinamen von ungültigen Zeichen befreit
* Stream-Test, Logo-Download und Posting erreichen keine internen Netze
* Bluesky-App-Passwort und Mastodon-Token verschlüsselt in der Datenbank, der Schlüssel liegt in der Config
* SMAPI-Antworten gegen das offizielle Sonos-Schema geprüft, XML mit DTD wird abgelehnt

## Voraussetzungen

PHP 8.1 oder neuer mit `pdo_mysql`, `curl`, `gd`, `dom` und `mbstring`, MySQL 5.7+ oder MariaDB 10.3+, HTTPS. PHP muss in `www/media/` und `1radioe-private/` schreiben dürfen. Für das automatische Ausrollen braucht der Server SSH, Git und die PHP-Funktion `exec()` (bei All-Inkl gegeben). Die Systemseite zeigt, ob alles vorhanden ist.

## Mitgelieferte Bibliotheken

[SortableJS](https://github.com/SortableJS/Sortable) 1.15.6, [Cropper.js](https://github.com/fengyuanchen/cropperjs) 1.6.2 und [qrcode-generator](https://github.com/kazuhikoarase/qrcode-generator) 1.4.4, alle MIT-Lizenz, lokal eingebunden. Schrift [Mona Sans](https://github.com/github/mona-sans) 2.0.27 von GitHub, SIL Open Font License 1.1 (`www/assets/fonts/MonaSans-OFL.txt`).

## Lizenz

[MIT](LICENSE)
