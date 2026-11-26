# 1radiœ

Eigene Radiosender und MP3-Playlists als Dienst in der Sonos-App, dazu eine Web-App mit Player, gepflegt über eine kleine Verwaltung. Läuft auf einfachem PHP-Hosting, ohne Framework und ohne Build-Schritt.

1radiœ ist aus [sns-smapi](https://github.com/ollrich/sns-smapi) entstanden, einem schlanken SMAPI-Dienst in einer PHP-Datei, der eigene Streams auf Sonos bringt. Hier ist daraus eine vollständige Anwendung mit Verwaltung, Web-App, Playlists und Schnittstellen geworden.

Unabhängiges Projekt, nicht mit Sonos, Inc. verbunden.

## Funktionen

* **Sonos-Dienst** (`smapi.php`): liefert Sonos Sender, MP3-Playlists und Kategorien mit Logos, Beschreibungen, Tracks und Abspieladressen
* **Web-App** (`/`): alle Sender mit Player im Browser, Medientasten, Sperrbildschirm, Songtitel aus dem Stream, Deutsch und Englisch, live unter [1radioe.net](https://1radioe.net)
* **Verwaltung** (`admin/`): Sender anlegen und testen (automatische Formaterkennung), Logos zuschneiden, Reihenfolge per Drag & Drop, Vorschau wie in Sonos, Texte, Diagnose-Log
* **MP3-Playlists:** Upload per Drag & Drop oder FTP, Angaben und Cover aus den MP3-Tags
* **Weitere Medien in der Web-App:** SoundCloud-Tracks, Deezer-Playlists (als Link) und YouTube-Playlists
* **Profile:** zwei Listen, Profil 1 öffentlich, Profil 2 nur für eine eigene App
* **Hörstatus:** zeigt in der Web-App, welcher Sender gerade gehört wird, ohne Gerät, Ort oder Quelle
* **Posting** zu Bluesky und Mastodon, wenn ein Sender eine Weile läuft
* **Schnittstellen:** `/streams.m3u` für VLC, `/streams.xspf` für Eter, `/api/stations.json` und `/api/playlists` für eine eigene App
* **Automatisches Ausrollen:** ein Push auf `main` aktualisiert den Server per GitHub-Webhook

## Aufbau

```
1radioe-private/      Config, Secrets, Deploy-Log, FTP-Eingang (nicht in Git)
app/                  Programmcode, außerhalb des Web-Verzeichnisses
www/                  Web-Verzeichnis (Document Root)
  index.php           Web-App mit Player
  assets/             CSS, Schrift, Icons; app.js ist der Einstieg, die ES-Module liegen in app/
  smapi.php           SMAPI-Endpoint für Sonos
  report.php          Wiedergabe-Meldungen von Sonos (Reporting Endpoint)
  api/                Schnittstellen (stations.json, playlists, status, nowplaying, listen)
  admin/              Verwaltung (Login mit Passwort und TOTP), Skript wie oben
  deploy.php          Ziel des GitHub-Webhooks
  media/              Logos, Cover, tracks/ (legt die App an, nicht in Git)
tests/                Tests in einfachem PHP
config.sample.php     Vorlage für 1radioe-private/config.php
API.md                Schnittstellen für eine eigene App
DEPLOY-SETUP.md       Einrichtung mit Webhook Schritt für Schritt
CHANGELOG.md          Änderungen je Version
```

Der Ordner `1radioe-private` liegt neben `www/`, außerhalb des Web-Verzeichnisses, und ist von Git ausgeschlossen. Darin stehen `config.php`, Webhook-Secret, Deploy-Log, FTP-Eingang `inbox/` und Upload-Puffer `tmp/`. Das Ausrollen lässt ihn unberührt. Die App sucht ihn zuerst im Checkout, dann in den Ordnern darüber.

`www/media/` liegt bewusst im Web-Verzeichnis, weil Sonos-Lautsprecher Bilder und MP3s direkt abrufen. Die App legt dort eine `.htaccess` an, die Verzeichnislisten und Skripte sperrt. Andere Orte lassen sich in der Config mit `media_dir` und `media_url` festlegen.

**Skripte:** Web-App und Verwaltung sind ES-Module ohne Build. `app.js` ist jeweils der Einstieg, die Teile liegen in `app/` daneben. `core.js` hält, was alle brauchen, und importiert nichts. Jeder Teil deklariert beim Laden nur und hängt seine Listener in `init()` an, der Einstieg ruft die `init()` in fester Reihenfolge auf. Geladen wird der Einstieg als `assets/v<Version>/app.js`, `www/.htaccess` bildet `assets/v…/` auf `assets/` ab. So tragen alle Module die Version im Pfad und Browser dürfen sie ein Jahr behalten. Der Server hält nur die aktuellen Dateien: Eine Seite, die genau während eines Deploys lädt, könnte alte und neue Teile mischen; die Seite selbst wird nie zwischengespeichert, das Risiko ist bewusst in Kauf genommen. `modulepreload` deckt alle Teile ab, ein neuer Teil in `app/` ist ohne weitere Änderung dabei.

## Installation und Update

Die Einrichtung auf einem Server mit SSH und Git, mit Webhook für das automatische Ausrollen, beschreibt [DEPLOY-SETUP.md](DEPLOY-SETUP.md).

Danach ist jedes Update ein Push auf `main`. Gearbeitet wird auf Branches, ein Merge nach `main` ist sofort live. Neue Spalten und Tabellen legt die App beim nächsten Aufruf selbst an. Was sich geändert hat, steht in [CHANGELOG.md](CHANGELOG.md).

Beim ersten Öffnen von `/admin/` fragt ein Assistent ein Passwort ab (mindestens 12 Zeichen), zeigt einen QR-Code für die Authenticator-App und danach einen Block für die `config.php`. Der QR-Code entsteht im Browser, das Geheimnis verlässt den Server nicht.

## Lokal entwickeln

```bash
cp config.sample.php 1radioe-private/config.php   # Ordner vorher anlegen, Datenbank eintragen
php -S 127.0.0.1:8080 -t www app/dev-router.php
```

`app/dev-router.php` bildet die Adressen aus `www/.htaccess` nach. Mit `'allow_private_urls' => true` testet der Stream-Test auch lokale Adressen.

**Prüfungen:** Vor jedem Merge nach `main` prüft GitHub Actions die Syntax aller PHP- und JavaScript-Dateien und lässt die Tests laufen (`.github/workflows/lint.yml`). Lokal geht das mit `php -l`, `node --check` und `php tests/run.php`. Module (Dateien mit `import`/`export`) prüft `node --check` nur als `.mjs`, die CI kopiert sie dafür.

**Tests** (`tests/`): einfaches PHP ohne Framework, je Datei `*.test.php` eine Gruppe: Kategorie-Bilder je Liste, Adressfilter, Profile und Schlüssel, Senderzuordnung und täglicher Stream-Test, Uploads in Teilen, XSPF, YouTube-Abgleich mit nachgebauter API. Die Tests brauchen eine eigene MariaDB-Datenbank, deren Name `test` enthält, denn sie leeren sie zuerst. Zugang über `R1_TEST_DB_HOST`, `R1_TEST_DB_PORT`, `R1_TEST_DB_NAME`, `R1_TEST_DB_USER` und `R1_TEST_DB_PASS` (ohne Angabe `127.0.0.1`, `r1_test`, Benutzer und Passwort `r1`, wie in der CI). Konfiguration und Medienordner liegen in einem temporären Ordner, kein Netz, keine Schlüssel. Der Player im Browser hat keine automatischen Tests und wird von Hand geprüft.

## Sonos einrichten

Im Sonos-Entwicklerportal eine Integration anlegen. Die Systemseite der Verwaltung zeigt alle Werte mit Kopier-Knopf:

* **Sonos Music API:** SMAPI Endpoint `https://<domain>/smapi.php`, Reporting Endpoint `https://<domain>/report/v2.3` mit Reporting Endpoint Version 2.3
* **Service Configuration:** nach jeder Änderung Refresh, dann Send

Änderungen in der Verwaltung erreichen Sonos automatisch, spätestens beim nächsten Öffnen des Dienstes.

## Verwaltung

### Sender

* **Hinzufügen:** Stream-URL eintragen und „Stream testen“. Der Test erkennt MP3, AAC, Ogg Vorbis, FLAC und HLS, folgt Weiterleitungen und löst `.pls`- und `.m3u`-Playlists auf. Format, Name und Beschreibung kommen aus dem Stream, wenn die Felder leer sind. Ogg Opus wird abgelehnt, weil Sonos es nicht abspielt.
* **Logo:** hochladen oder per URL laden, zuschneiden, zoomen, drehen, Hintergrund für transparente Bereiche wählen. Gespeichert wird 600 × 600 px, für die kleinen Kacheln der Web-App entsteht beim ersten Aufruf daneben ein Bild mit 192 px (`…-s.jpg`).
* **Reihenfolge:** Kacheln per Drag & Drop verschieben, die Vorschau entspricht der Liste in Sonos.
* **Inaktiv:** blendet einen Eintrag überall aus, ohne ihn zu löschen.
* **Website, Land und Ort:** erscheinen in der Web-App unter „Über den Sender“. Das Land wird als ISO-Code gespeichert (`FR`), den Namen setzt der Browser in der Sprache der Seite ein.
* **Alle Streams prüfen:** testet alle Sender. Ein roter Punkt markiert Probleme, die Marke „HTTP“ Sender, die nur per HTTP erreichbar sind und deshalb nicht in der Web-App spielen.
* **Täglicher Test:** Der Server prüft jeden aktiven Stream einmal am Tag, ohne Cron: Jeder Aufruf der Web-App und von `/api/status` nimmt sich bis zu drei fällige Streams und testet sie nach der Antwort. Erst ein zweiter Fehlschlag zählt, ein roter Punkt wird nach zwei Stunden erneut geprüft.

### Kategorien

* **Anlegen:** mit Name, Beschreibung und Bild. Ohne eigenes Bild zeigt eine Kategorie ein Raster aus den ersten vier Logos, und zwar aus dem, was die jeweilige Liste darin zeigt (Web-App, Sonos, App je Profil).
* **Füllen:** „Vorhandene zuordnen“ oder direkt in der Kategorie anlegen. Jeder Eintrag steckt in höchstens einer Kategorie.
* **In Sonos** ist die Kategorie ein Ordner. Leere Kategorien zeigt Sonos nicht, Favoriten und Wecker bleiben beim Einsortieren erhalten.
* **Löschen:** Die Einträge stehen danach wieder direkt in der Liste.

### MP3-Playlists

* **Anlegen:** mit Name, Beschreibung und Cover, sortieren und deaktivieren wie Sender.
* **MP3s hinzufügen:** hochladen oder per Drag & Drop ablegen. Uploads laufen in 2-MB-Teilen, die Upload-Grenze des Hosters spielt keine Rolle (höchstens 300 MB je Datei). Titel, Interpret, Album, Cover und Dauer kommen aus den MP3-Tags (ID3v1 und v2).
* **Per FTP:** Dateien in `1radioe-private/inbox/` legen und unter „Tracks“ importieren. Dateien, die in den letzten 15 Sekunden geändert wurden, überspringt der Import.
* **Bibliothek:** Jeder Track liegt einmal auf dem Server und kann in mehreren Playlists stehen. Doppelte Dateien erkennt die App am Inhalt.
* **In Sonos** öffnet eine Playlist die Trackliste, „Abspielen“ legt alle Tracks in die Warteschlange. Leere Playlists und Tracks ohne Datei zeigt Sonos nicht.
* Die MP3s laden die Lautsprecher direkt vom Server. Sie liegen unter zufälligen Namen ohne Verzeichnisliste, sind aber für jeden abrufbar, der einen Link kennt.

### SoundCloud, Deezer und YouTube

Diese Einträge gibt es nur in der Web-App. Sonos, `streams.m3u`, `streams.xspf` und `stations.json` lassen sie aus, es gibt für sie keinen Hörstatus und kein Posting.

* **SoundCloud:** Link zu einem Track, Kurzlinks werden aufgelöst, Tracking-Parameter entfernt. Gespielt wird im offiziellen Player von SoundCloud. Die API-Bedingungen von SoundCloud verbieten Radio-Apps, die Inhalte vieler Nutzer bündeln, erlaubt ist das Einbetten des Players.
* **Deezer:** Link zu einer Playlist, auch Teilen-Links. Gespeichert wird die reine Adresse. In der Web-App öffnet die Kachel einen Hinweis mit dem Weg zu Deezer und dem Tipp zum Export in andere Musik-Apps; das Radio läuft dabei weiter.
* **YouTube:** Adresse einer öffentlichen oder nicht gelisteten Playlist (nur auf der obersten Ebene). Abgleich einmal am Tag über die YouTube Data API v3, „Jetzt abgleichen“ sofort; je Playlist läuft höchstens ein Abgleich gleichzeitig. Gespeichert werden je Video ID, Titel, Kanal, Länge und ein Bild (`yt-<id>-<hash>.jpg`), so fragt die Seite vor dem Klick nichts bei Google an. YouTube erlaubt solche Daten höchstens 30 Tage, der Abgleich frischt sie auf. Höchstens 500 Videos.
* **YouTube-API-Schlüssel:** In der Google Cloud Console die YouTube Data API v3 aktivieren, einen API-Schlüssel erstellen, auf diese API und möglichst auf die IP-Adresse des Servers beschränken und im Editor einer YouTube-Playlist eintragen. Er wird verschlüsselt gespeichert. Ein Abgleich kostet je 50 Videos zwei Einheiten des Tageskontingents von 10.000.

### Profile

Zwei Listen aus demselben Bestand. Jeder Sender und jede MP3-Playlist hat in der Verwaltung zwei Häkchen, „Sichtbar für Profil 1 / Profil 2“, beide sind möglich.

* **Profil 1** ist die öffentliche Liste: Web-App, Sonos, `streams.m3u`, `streams.xspf` und `stations.json` ohne Profil.
* **Profil 2** gibt es nur für eine eigene App: `stations.json` und `/api/playlists` mit `?profile=<name>`.
* Kategorien folgen ihrem Inhalt, die Track-Bibliothek ist gemeinsam. Hörstatus und Posting gehören zu Profil 1.
* **Schlüssel** werden in der Verwaltung je Profil erzeugt. Der von Profil 2 liest nur dessen Playlists und meldet nie einen Hörstatus.
* Namen und Anzeige der Profile stehen in `PROFILES` in `app/profiles.php`, Details in [API.md](API.md).

### Texte

„Über“, Impressum und Datenverarbeitung im Reiter „Texte“, je auf Deutsch und Englisch, mit einem schlichten Editor (Absätze, Zwischenüberschriften, fett, kursiv, Listen, Links). Der Server lässt nur diese Auszeichnungen durch (`app/texts.php`). Ohne eigenen Text gilt ein Standardtext mit Platzhaltern in eckigen Klammern.

**E-Mail-Adressen** stehen nicht im HTML: Der Server ersetzt sie durch einen Platzhalter mit verschlüsselter Adresse, das Skript macht daraus beim Laden einen `mailto:`-Link. Adress-Sammler lesen Seiten meist ohne Skript und finden nichts.

## Web-App

[1radioe.net](https://1radioe.net) zeigt alle aktiven Sender von Profil 1, dazu SoundCloud, Deezer und YouTube, keine MP3-Playlists.

* **Sprachen:** Englisch unter `/`, Deutsch unter `/de/`. Ist Deutsch die erste Sprache des Browsers, leitet `/` auf `/de/` um; wer im Menü Englisch wählt, bleibt dort, ohne Cookie.
* **Desktop:** Player in der Mitte, darunter die Sender als Skala mit Nadel. Kategorien öffnen eine eigene Skala.
* **Handy:** Liste mit aufklappbaren Kategorien und ein angedockter Player, der sich zur Vollansicht aufziehen lässt.
* **Vor/Zurück** bleibt in der Ebene, auf der der Sender gestartet wurde. Medientasten und Sperrbildschirm folgen derselben Regel.
* **Tastatur:** Leertaste, Pfeiltasten und Esc wirken überall, 1 bis 0 nur mit dem Fokus im Player oder in der Senderauswahl (WCAG 2.1.4). Erklärt im (?) unten rechts.
* **Screenreader:** Eine Statusmeldung sagt Sender und Zustand an, der Lautstärkeregler nennt seinen Wert.
* **Wiedergabe:** direkt vom Stream des Senders, nie über den Server. Pausieren legt auf, Abspielen verbindet neu. Bei einem Abbruch verbindet der Player selbst neu.
* **HLS:** Safari, Chrome und Handy-Browser spielen es selbst. Firefox bekommt [hls.js](https://github.com/video-dev/hls.js) vom eigenen Server, nur für HLS-Sender.
* **Song:** Schickt der Stream Titel mit (ICY-Metadaten), zeigt der Player Titel und Interpret. Der Server liest sie (`/api/nowplaying`), solange der Player spielt.
* **Hörstatus:** Mint-Balken an einem Sender zeigen „Olli hört gerade …“. Läuft nichts, steht dort „Zuletzt von Olli gehört: …“.
* **SoundCloud und YouTube** laden erst nach einem Klick („SoundCloud laden“, „YouTube laden“), die Zustimmung gilt für den Besuch. Gesteuert werden beide Player über `postMessage`, ohne die Skripte der Anbieter zu laden. Ein YouTube-Video spielt sichtbar an der Stelle des Logos, wie YouTube es verlangt.
* **Menü:** Über, Impressum, Datenverarbeitung und Sprache. Ohne Senderliste (Datenbank nicht erreichbar oder kein Sender aktiv) bleibt es nutzbar, die Links stehen dann zusätzlich unten.
* **Ohne JavaScript** eine Liste mit Links zu den Streams, die Texte als Abschnitte.
* **Gespeichert** wird im Browser nur die Lautstärke. Keine Cookies für Besucher, kein Tracking, keine externen Ressourcen außer SoundCloud und YouTube nach dem Klick.
* **Senderseiten:** `/s/<id>` leitet auf die Web-App mit dem Sender im Player und hat eine eigene Link-Karte (Name, Beschreibung, Logo).

## Schnittstellen

Alle aktiven Sender von Profil 1 in der Reihenfolge der Verwaltung, nie MP3-Playlists, Tracks, SoundCloud, Deezer oder YouTube.

| Adresse | Inhalt |
|---|---|
| `/streams.m3u` | M3U für VLC und andere Player |
| `/streams.xspf` | XSPF 1.0 mit Name, Stream-Adresse und Logo, zum Import in Eter (Mac, iPad) |
| `/api/stations.json` | Sender und Kategorien für eine eigene App, `?profile=` für Profil 2 |
| `/api/playlists` | MP3-Playlists mit Tracks, nur mit dem Schlüssel des Profils |
| `/api/status` | laufende Sender und der zuletzt gehörte |
| `/api/nowplaying?id=<id>` | aktueller Song eines Senders |
| `/api/listen` | Meldungen für den Hörstatus (Browser mit Hör-Cookie, Home Assistant, App mit Schlüssel) |

Felder, Beispiele, ETag und Antwortcodes stehen in [API.md](API.md). Die Adressen in den Playlist-Dateien zeigen direkt auf die Streams; Hören über VLC fließt nicht in den Hörstatus ein.

## Hörstatus

Die Web-App zeigt, welcher Sender gerade gehört wird. Mehr nicht: nie welches Gerät, nie ob Sonos oder Browser, nie wo.

* **Modell:** wie „Now Playing“ bei Last.fm. Jede Quelle meldet beim Start und danach jede Minute „läuft“, beim Stoppen „aus“. Ein Sender gilt als laufend, solange die letzte Meldung jünger als 150 Sekunden ist und kein „aus“ folgte. Fällt eine Quelle still aus, erlischt der Status von selbst.
* **Gespeichert** wird je Sender nur der Zeitpunkt der letzten Meldung, ob „aus“ kam und seit wann er läuft (`r1_listen_status`). Ein Verlauf entsteht nicht.
* **Zuletzt gehört:** die neueste beendete Sitzung von mindestens fünf Minuten (`listen_last()`), kurzes Reinhören zählt nicht.
* **Browser:** Nach dem Login in der Verwaltung lässt sich ein Browser merken. Er bekommt das Cookie `r1listen`, das nur Meldungen an `/api/listen` erlaubt. Der Player meldet dann „läuft“, solange er wirklich spielt, und „aus“ bei Pause, Fehler, Senderwechsel und beim Schließen. Die Web-App zeigt dort „Hören wird erfasst“, ein Klick pausiert das für die Sitzung.
* **Sonos:** `getMediaURI` und der Reporting Endpoint melden Start und Ende. Die Meldung jede Minute dazwischen schickt Sonos bei Live-Streams nicht, den laufenden Zustand meldet deshalb Home Assistant.
* **Nur mit Schlüssel:** In der Verwaltung unter Hörgeräte lässt sich einstellen, dass nur Home Assistant, gemerkte Browser und Apps mit Schlüssel zählen.
* **Testen:** Verwaltung → System → Hörstatus, dazu das Diagnose-Log.

### Home Assistant einrichten

Home Assistant kennt den Zustand jedes Sonos-Lautsprechers und meldet, welchen Stream er spielt. Der Server sucht den Sender über die Adresse, dann über den Titel, so zählt ein Sender auch als Sonos-Favorit.

1. **Schlüssel:** Verwaltung → System → Hörgeräte → „Schlüssel erzeugen“. Die angezeigte Zeile `radioe_listen_token: …` gehört in `secrets.yaml`. Sie ist nur einmal sichtbar.
2. **Befehl** in `configuration.yaml`, danach die REST-Befehle neu laden:

   ```yaml
   rest_command:
     radioe_listen:
       url: https://<domain>/api/listen
       method: post
       headers:
         X-1radioe-Token: !secret radioe_listen_token
       content_type: application/json
       payload: '{{ {"url": url, "title": title | default(""), "state": state} | to_json }}'
       timeout: 10
   ```

3. **Automation:** meldet jede Minute „läuft“ für jeden Stream, den ein Lautsprecher spielt, und bei Pause, Stopp oder Senderwechsel sofort „aus“. In den Auslösern stehen die eigenen Sonos-Lautsprecher.

   ```yaml
   alias: 1radiœ Hörstatus
   mode: queued
   max: 10
   triggers:
     - trigger: state
       entity_id: [media_player.wohnzimmer, media_player.kueche]   # eigene Lautsprecher
       to: null
     - trigger: state
       entity_id: [media_player.wohnzimmer, media_player.kueche]
       attribute: media_content_id
     - trigger: time_pattern
       minutes: /1
   variables:
     re: '(x-sonosapi-|[a-z0-9.+-]+://)'
     playing: >-
       {%- set ns = namespace(items=[], urls=[]) -%}
       {%- for p in integration_entities('sonos') | select('match', 'media_player\.')
                    | select('is_state', 'playing') -%}
         {%- set u = state_attr(p, 'media_content_id') -%}
         {%- if u is string and u is match(re) and u not in ns.urls -%}
           {%- set ns.urls = ns.urls + [u] -%}
           {%- set ns.items = ns.items + [{'url': u, 'title': state_attr(p, 'media_channel') or ''}] -%}
         {%- endif -%}
       {%- endfor -%}
       {{ ns.items }}
     stopped: >-
       {%- set f = trigger.from_state if trigger.platform == 'state' else none -%}
       {%- set t = trigger.to_state if trigger.platform == 'state' else none -%}
       {%- set u = f.attributes.get('media_content_id') if f is not none and f.state == 'playing' else none -%}
       {%- if u is string and u is match(re) and u not in playing | map(attribute='url') | list
             and (t is none or t.state not in ['playing', 'buffering']
                  or t.attributes.get('media_content_id') != u) -%}
         {{ {'url': u, 'title': f.attributes.get('media_channel') or ''} }}
       {%- else -%}
         {{ {} }}
       {%- endif -%}
   conditions:
     - "{{ playing | count > 0 or stopped.url is defined }}"
   actions:
     - repeat:
         for_each: "{{ playing }}"
         sequence:
           - action: rest_command.radioe_listen
             data:
               url: "{{ repeat.item.url }}"
               title: "{{ repeat.item.title }}"
               state: "on"
             continue_on_error: true
     - if:
         - "{{ stopped.url is defined }}"
       then:
         - action: rest_command.radioe_listen
           data:
             url: "{{ stopped.url }}"
             title: "{{ stopped.title }}"
             state: "off"
           continue_on_error: true
   ```

## Posting zu Bluesky und Mastodon

Läuft laut Hörstatus ein Sender nach einer Wartezeit noch, geht ein Beitrag an Bluesky und Mastodon. Eingerichtet in der Verwaltung unter „Posting“.

* **Auslöser:** Eine Hörsitzung beginnt, wenn ein Sender auf „läuft“ wechselt. Die erste Meldung nach der Wartezeit (Standard 3 Minuten) übernimmt die Sitzung genau einmal, Durchzappen löst nichts aus. Kein Cron nötig, der Beitrag geht nach der Antwort raus.
* **Bremsen:** Schalter „Automatisch posten“, je Sender ein Schalter „Posten“, Sperrfrist je Sender (Standard 6 Stunden), Höchstzahl pro Tag (Standard 4).
* **Text:** Vorlage mit `{sender}`, `{link}`, `{website}` und `{hashtags}`. Ist ein Beitrag zu lang, fallen Hashtags von hinten weg. Die Vorschau zeigt beide Fassungen mit Länge.
* **Link:** `{link}` zeigt auf die Senderseite `/s/<id>` (bzw. `/de/s/<id>`) mit eigener Link-Karte.
* **Bluesky:** Handle, App-Passwort und PDS, Links und Hashtags als Facets, Link-Karte mit Senderlogo. **Mastodon:** Instanz und Access-Token mit `write:statuses`, ein `Idempotency-Key` verhindert doppelte Beiträge.
* **Geheimnisse** liegen verschlüsselt in der Datenbank (libsodium), der Schlüssel als `secret_key` in `config.php`.
* **Testen:** „Verbindung prüfen“ meldet sich an, ohne zu posten. „Testbeitrag senden“ postet wirklich.

## Sicherheit

* Passwort nur als Hash in der Config, zweiter Faktor per TOTP mit Schutz gegen wiederverwendete Codes
* Sperre nach 5 Fehlversuchen pro IP für 15 Minuten, zusätzlich eine globale Bremse
* Sitzungen nur über HTTPS, `SameSite=Strict`, Ablauf nach 2 Stunden Inaktivität
* CSRF-Schutz für alle Änderungen, strenge Content Security Policy ohne Inline-Skripte
* Texte aus der Verwaltung gehen durch eine Positivliste weniger Tags
* Logos und Cover werden mit GD neu kodiert, `www/media/` führt keine Skripte aus und zeigt keine Verzeichnisliste
* Programmcode, Git-Verzeichnis und Config liegen außerhalb des Web-Verzeichnisses
* Deploy nur mit gültiger Webhook-Signatur (HMAC-SHA256)
* MP3s werden vor dem Import geprüft, getarnte Dateien abgelehnt
* Stream-Test, Logo-Download und Posting erreichen keine internen Netze
* Schlüssel für Hörstatus und App nur als SHA-256 gespeichert
* SMAPI-Antworten gegen das offizielle Sonos-Schema geprüft, XML mit DTD wird abgelehnt

## Voraussetzungen

PHP 8.1 oder neuer mit `pdo_mysql`, `curl`, `gd`, `dom`, `mbstring` und `sodium`, MySQL 5.7+ oder MariaDB 10.3+, HTTPS. PHP muss in `www/media/` und `1radioe-private/` schreiben dürfen. Für das automatische Ausrollen braucht der Server SSH, Git und die PHP-Funktion `exec()`. Die Systemseite der Verwaltung zeigt, ob alles vorhanden ist.

## Mitgelieferte Bibliotheken

* [SortableJS](https://github.com/SortableJS/Sortable) 1.15.6, [Cropper.js](https://github.com/fengyuanchen/cropperjs) 1.6.2 und [qrcode-generator](https://github.com/kazuhikoarase/qrcode-generator) 1.4.4, MIT-Lizenz
* [hls.js](https://github.com/video-dev/hls.js) 1.7.4 (`hls.light.min.js`), Apache-2.0-Lizenz, nur für HLS in Firefox
* Schrift [Mona Sans](https://github.com/github/mona-sans) 2.0.27, SIL Open Font License 1.1. Die Web-App lädt sie zugeschnitten als „R1 Sans“ (lateinisch, 141 statt 301 KB), weil „Mona Sans“ ein reservierter Name der OFL ist. Wortmarke und Icons sind daraus als Vektorpfade gebaut.

Alle Bibliotheken liegen lokal, nichts wird von fremden Servern geladen.

## Lizenz

[Apache License 2.0](LICENSE) (bis 1.30.0 MIT). Wer 1radiœ weitergibt oder darauf aufbaut, liefert die Datei [NOTICE](NOTICE) mit und nennt damit den Ursprung, kennzeichnet geänderte Dateien und legt die Lizenz bei.
