# 1radiœ

Eigene Radiosender und MP3-Playlists als Dienst in der Sonos-App, dazu eine Web-App mit Player unter 1radioe.net, gepflegt über eine kleine Verwaltung.

* **Web-App** (`/`): alle Sender mit Player im Browser, Medientasten, Hörstatus
* **SMAPI-Dienst** (`smapi.php`): liefert Sonos die Liste aus Sendern, Playlists und Kategorien, Logos, Beschreibungen, Tracks und Abspieladressen
* **Admin** (`admin/`): Sender anlegen, Stream testen mit automatischer Formaterkennung, Logo hochladen und zuschneiden, Reihenfolge per Drag & Drop, Vorschau wie in Sonos, Diagnose-Log
* **Playlists aus eigenen MP3s:** Upload per Drag & Drop oder FTP, Titel, Interpret, Album und Cover aus den MP3-Tags, alles bearbeitbar, Reihenfolge per Drag & Drop
* PHP 8.1+ und MySQL/MariaDB, kein Framework, kein Build-Schritt
* Automatisches Ausrollen: ein Push auf `main` aktualisiert den Server per GitHub-Webhook
* **Hörstatus:** zeigt, welchen Sender Olli gerade hört, ohne Gerät, Ort oder Quelle (`/api/status`)
* **Playlist und JSON:** alle Sender als `/streams.m3u` für VLC und andere Player und als `/api/stations.json` für eine eigene App

Unabhängiges Projekt, nicht mit Sonos, Inc. verbunden.

## Aufbau

```
1radioe-private/      Config, Secrets, Deploy-Log, FTP-Eingang (nur auf dem Server und lokal, nicht in Git)
app/                  Programmcode, liegt außerhalb des Web-Verzeichnisses
www/                  Web-Verzeichnis der Domain https://1radioe.net
  index.php           Web-App mit Player
  assets/             CSS, JavaScript, Schrift Mona Sans der Web-App
  smapi.php           SMAPI-Endpoint für Sonos
  report.php          Wiedergabe-Meldungen von Sonos (Reporting Endpoint)
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

* **Sonos Music API:** SMAPI Endpoint `https://1radioe.net/smapi.php`, Reporting Endpoint `https://1radioe.net/report/v2.3` mit Reporting Endpoint Version 2.3
* **Service Configuration:** nach jeder Änderung Refresh, dann Send

## Bedienung

* **Sender hinzufügen:** Stream-URL eintragen und „Stream testen“. Der Test erkennt MP3, AAC, Ogg Vorbis, FLAC und HLS, folgt Weiterleitungen und löst `.pls`- und `.m3u`-Playlists auf. Format, Name und Beschreibung werden aus dem Stream übernommen, wenn die Felder leer sind. Ogg Opus lehnt der Test ab, weil Sonos es nicht abspielt.
* **Logo:** hochladen oder per URL laden, im Editor zuschneiden, zoomen, drehen, einpassen oder füllen, Hintergrund für transparente Bereiche wählen. Gespeichert wird immer 600 × 600 px.
* **Reihenfolge:** Kacheln per Drag & Drop verschieben. Die Vorschau entspricht der Liste in Sonos.
* **Inaktiv:** blendet einen Sender in Sonos aus, ohne ihn zu löschen.
* **Alle Streams prüfen:** testet alle Sender. Ein roter Punkt markiert Probleme, die Marke „HTTP“ Sender, die nur per HTTP erreichbar sind und deshalb nicht in der Web-App spielen.
* **Website:** Link zur Seite des Senders, erscheint in der Web-App unter „Über den Sender“.
* **Texte:** Über 1radiœ, Impressum und Datenverarbeitung im Reiter „Texte“ bearbeiten, je auf Deutsch und Englisch. Ein schlichter Editor ohne Bibliothek: Absätze, Zwischenüberschriften, fett, kursiv, Listen, Links. Der Server lässt nur diese Auszeichnungen durch (`app/texts.php`), Links nur zu `http`, `https`, `mailto` oder relativ auf dieser Seite, externe öffnen in einem neuen Tab. Gespeichert wird in `r1_settings` (`text.<name>.<sprache>`). Ohne eigenen Text gilt der Standardtext, „Auf Standard zurücksetzen“ löscht den eigenen. Englisch steht auf `1radioe.net`, Deutsch auf `1radioe.net/de/`. Ohne eigenen englischen Text zeigt die englische Seite den eigenen deutschen (echte Angaben im Impressum gehen vor Platzhaltern), erst ohne beide den englischen Standardtext. Der Hinweis zu den Balken unter „Über“ bleibt fest im Code, weil er das Symbol selbst zeigt.
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

* **Sprachen:** `1radioe.net` ist Englisch, `1radioe.net/de/` Deutsch. Unter `/de/` gilt jede Adresse genauso (`.htaccess` reicht sie ohne `/de` weiter, die Seite liest die Sprache aus der Adresse), also auch `/de/s/<smapi_id>`. Ist Deutsch die erste Sprache des Browsers, leitet `1radioe.net` auf `/de/` um (`Accept-Language`). Wer im Menü Englisch wählt, landet auf `/?lang=en` und wird nicht umgeleitet, ganz ohne Cookie. Übersetzt ist die ganze Oberfläche (`webapp_strings()` und `webapp_script_strings()` in `app/webapp.php`), die Texte aus dem Admin je Sprache. Nur die Senderbeschreibungen gibt es einmal.

* **Desktop:** Player in der Mitte, darunter die Sender als Skala mit Nadel. Ein Klick spielt, ein zweiter pausiert. Kategorien öffnen eine eigene Skala. Sind es zu viele für die Breite, blättern Pfeile die Skala.
* **Handy:** Liste mit aufklappbaren Kategorien, nach dem ersten Start ein angedockter Player, der sich zur Vollansicht aufziehen lässt.
* **Vor/Zurück** bleibt in der Ebene, auf der der Sender gestartet wurde, mit Umlauf, und überspringt Ordner. Medientasten und Sperrbildschirm folgen derselben Regel.
* **Tastatur:** Leertaste, Pfeiltasten, 1 bis 0, Esc. Erklärt im (?) über der Skala.
* **Wiedergabe:** direkt vom Stream des Senders, nie über diesen Server. Pausieren legt auf, Abspielen verbindet neu. Bei Abbruch verbindet der Player selbst neu und zeigt „Verbindet“.
* **HLS** (`.m3u8`, etwa die BBC): Safari, Chrome und die Browser auf dem Handy spielen das selbst. Firefox kann es nicht, dort lädt die Web-App [hls.js](https://github.com/video-dev/hls.js) von diesem Server nach, nur für HLS-Sender und nur in Browsern ohne eigene HLS-Wiedergabe. hls.js holt Playlist und Stücke direkt beim Sender. Dafür erlaubt die Sicherheitsrichtlinie der Seite (CSP) Abrufe an `https:`-Adressen und `blob:` als Quelle für Ton. Ohne Worker, weil der `blob:`-Skripte bräuchte.
* **Song:** Schickt der Stream Titel mit (ICY-Metadaten bei Shoutcast und Icecast), zeigt der Player Titel und Interpret, im angedockten Player und in der Liste statt „Live“, auf dem Sperrbildschirm und in den Mediensteuerungen. Der Browser kann die Daten aus dem Stream nicht selbst lesen, deshalb liest sie der Server (`/api/nowplaying`, höchstens alle 20 Sekunden je Sender). Abgefragt wird nur, solange der Player spielt. HLS-Streams und Streams ohne Titel zeigen nur den Sender. Wiederholt ein Stream nur den Sendernamen, wird nichts angezeigt, „ByteFM - Nachrichten“ wird zu „Nachrichten“.
* **Hörstatus:** Die Mint-Balken zeigen beim Darüberfahren mit der Maus, wer was hört, etwa „Olli hört gerade TSF Jazz“ an einer Kategorie. Auf dem Handy zeigt ein Tipp auf die Balken diese Info, ohne den Sender zu starten.
* **Gespeichert** wird im Browser nur die Lautstärke. Keine Cookies für Besucher, kein Tracking, keine externen Ressourcen. Nur Ollis gemerkter Browser hat das Hör-Cookie.
* **Menü:** Der Burger oben rechts öffnet auf dem Handy und am Desktop ein Menü mit Über, Impressum, Datenverarbeitung und dem Sprachumschalter. Die Texte erscheinen im Menü selbst, der Pfeil führt zurück. Am Desktop ist es breiter (520 px). Links unten gibt es nur ohne JavaScript. Der Sprachumschalter nimmt den Sender im Player mit. Die Texte kommen aus dem Admin (Reiter „Texte“).
* **Ohne JavaScript** eine Liste mit Links zu den Streams, Impressum und Datenverarbeitung als Abschnitte.

Impressum und Datenverarbeitung haben als Standard Platzhalter in eckigen Klammern. Die echten Angaben gehören in den Admin, Reiter „Texte“.

## Playlist-Dateien und App-Schnittstelle

Alle aktiven Sender, nie MP3-Playlists oder Tracks. Die Reihenfolge ist die des Admins: die oberste Ebene wie in Sonos, die Sender einer Kategorie an der Stelle der Kategorie. Eine inaktive Kategorie blendet ihre Sender aus.

| Adresse | Inhalt |
|---|---|
| `/streams.m3u` | M3U mit Namen, für VLC: „Medien, Netzwerkstream öffnen“. Bei jedem Abruf neu aus der Datenbank, Player dürfen sie 5 Minuten zwischenspeichern |
| `/api/stations.json` | alles für eine eigene App |
| `/api/status` | laufende Sender |
| `/api/nowplaying?id=<id>` | aktueller Song eines Senders, `{"id": …, "track": {"artist": …, "title": …}}` oder `"track": null` |
| `/api/listen` | Meldungen des eigenen Browsers (Hör-Cookie) oder von Home Assistant (Schlüssel) |

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

Läuft bei Olli ein Sender, soll das die Web-App an diesem Sender zeigen. Mehr nicht: nie welches Gerät, nie ob Sonos oder Browser, nie wo.

* **Modell:** wie „Now Playing“ bei Last.fm. Jede Quelle meldet beim Start und danach jede Minute „läuft“, beim Stoppen „aus“. Ein Sender gilt als laufend, solange die letzte Meldung jünger als 150 Sekunden ist und kein „aus“ folgte. Fällt eine Quelle still aus, erlischt der Status von selbst.
* **Gespeichert** wird je Sender nur der Zeitpunkt der letzten Meldung, ob „aus“ kam und seit wann er läuft, für das Posting (`r1_listen_status`).
* **Sonos:** `getMediaURI` für einen Sender startet. Über den Reporting Endpoint (`report.php`, Sonos schickt `POST …/timePlayed`) kommt kurz nach dem Start ein `update` und bei Pause oder Wechsel auf einen anderen Sender ein `final`. Was bei Live-Streams fehlt, ist die Meldung jede Minute dazwischen (getestet im November 2026, einstellen lässt sich das nicht). Den laufenden Zustand meldet deshalb Home Assistant. Die alten SMAPI-Meldungen `reportPlaySeconds` und `reportPlayStatus` werden weiter verstanden, Sonos schickt sie neuen Integrationen aber nicht mehr. Tracks und Playlists zählen nicht.
* **Home Assistant:** kennt den Zustand jedes Sonos-Lautsprechers. Eine Automation meldet jede Minute „läuft“ für jeden Stream, den ein Lautsprecher spielt, und bei Pause, Stopp oder Senderwechsel sofort „aus“. Gemeldet werden die Adresse, die der Lautsprecher spielt (`media_content_id`), und sein Titel (`media_channel`, bei 1radiœ „<Titel> • <Beschreibung>“). Der Server sucht den Sender zuerst über die Adresse, dann über den Titel, denn viele Streams leiten um (FluxFM etwa auf wechselnde Server) und der Lautsprecher meldet dann die neue Adresse. So zählt ein Sender auch, wenn er nicht über 1radiœ gestartet wurde, etwa als Sonos-Favorit. Einrichtung siehe unten.
* **Abfrage:** `GET /api/status` liefert `{"active": ["<smapi_id>", …], "ttl": 150}`.
* **Browser:** Nach dem Login im Admin lässt sich ein Browser zum Hören merken. Er bekommt das Cookie `r1listen`, das nur Meldungen an `POST /api/listen` erlaubt und nie den Admin öffnet. Der Player in der Web-App meldet dann „läuft“ beim Start und jede Minute, „aus“ bei Pause, Fehler, Senderwechsel und beim Schließen. In diesem Browser zeigt die Web-App oben „Hören wird erfasst“, ein Klick pausiert das für die Sitzung. Geräte verwalten und widerrufen: System → Hörgeräte.
* **`POST /api/listen`:** Body `{"station": "<smapi_id>", "state": "on" | "off"}` oder `{"url": "<Adresse>", "title": "<Titel>", "state": …}`. Ein Browser braucht das Cookie und eine `Origin` dieser Seite, „on“ zusätzlich den Header `X-1radioe: 1`. Home Assistant oder eine App schickt stattdessen den Header `X-1radioe-Token: <Schlüssel>`. Eine `url` darf eine Sonos-Adresse sein (`x-sonosapi-stream:<smapi_id>?sid=…`) oder die Stream-Adresse, auch mit Sonos-Vorsatz wie `aac://` oder `x-rincon-mp3radio://`. Bei `http` und `https` und bei einer anderen Abfrage hinter dem `?` passt sie trotzdem, eine Playlist-Station auch über ihren aufgelösten Stream. Passt die Adresse nicht, zählt der `title`: ein Sender mit genau diesem Titel oder mit dem Teil vor „ • “, Groß- und Kleinschreibung egal. Die Antwort ist `{"station": "<smapi_id>"}` oder `{"station": null}`, wenn es kein Sender von 1radiœ ist.
* **Testen:** Admin → System → Hörstatus, dazu das Diagnose-Log, das bei jeder Meldung die übergebenen Werte zeigt, auch jede Meldung von Home Assistant (`listen on`, `listen off`).

### Home Assistant einrichten

1. **Schlüssel:** Admin → System → Hörgeräte → „Schlüssel erzeugen“. Die angezeigte Zeile `radioe_listen_token: …` gehört in `secrets.yaml` von Home Assistant. Sie ist nur einmal sichtbar, bei Verlust einfach widerrufen und neu erzeugen.
2. **Befehl:** in `configuration.yaml` unter `rest_command:` (bei einem vorhandenen Block nur den Eintrag darunter), danach unter Tools → YAML die REST-Befehle neu laden (vor Home Assistant 2026.8 hieß Tools noch Entwicklerwerkzeuge):

   ```yaml
   rest_command:
     radioe_listen:
       url: https://1radioe.net/api/listen
       method: post
       headers:
         X-1radioe-Token: !secret radioe_listen_token
       content_type: application/json
       payload: '{{ {"url": url, "title": title | default(""), "state": state} | to_json }}'
       timeout: 10
   ```

3. **Automation:** Sie meldet, was die Lautsprecher spielen. Spotify-Titel und Ähnliches schickt sie gar nicht erst, andere Streams beantwortet der Server mit `{"station": null}`. Puffern zählt nicht als Pause, und spielt ein zweiter Lautsprecher denselben Sender weiter, geht er nicht auf „aus“. Die Liste der Lautsprecher in den Auslösern sind Ollis Sonos-Geräte.

   ```yaml
   alias: 1radiœ Hörstatus
   description: >-
     Meldet 1radioe.net, welchen Sender ein Sonos-Lautsprecher spielt: jede Minute „läuft“,
     bei Pause, Stopp oder Senderwechsel sofort „aus“. Der Server erkennt den Sender an der
     Adresse oder am Titel und ignoriert alles andere. Beschrieben im README von 1radiœ unter Hörstatus.
   mode: queued
   max: 10
   triggers:
     - trigger: state
       entity_id: [media_player.buro, media_player.kuche, media_player.wohnzimmer,
                   media_player.schrankzimmer, media_player.era_on_the_shelf, media_player.roamy]
       to: null
       note: Start, Pause, Stopp. Nur der Zustand, nicht jeder neue Songtitel.
     - trigger: state
       entity_id: [media_player.buro, media_player.kuche, media_player.wohnzimmer,
                   media_player.schrankzimmer, media_player.era_on_the_shelf, media_player.roamy]
       attribute: media_content_id
       note: Senderwechsel während der Wiedergabe.
     - trigger: time_pattern
       minutes: /1
       note: Heartbeat, der Server hält „läuft“ 150 Sekunden.
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

Startet Olli einen Sender, egal wo, und läuft er nach einer Wartezeit noch, geht ein Beitrag an Bluesky und Mastodon. Eingerichtet im Admin unter „Posting“.

* **Auslöser:** der Hörstatus. Eine Hörsitzung beginnt, wenn ein Sender von „läuft nicht“ auf „läuft“ wechselt. Die erste Meldung nach Ablauf der Wartezeit (Standard 3 Minuten) übernimmt die Sitzung, auch bei mehreren gleichzeitigen Meldungen genau einmal. Durchzappen und Fehlstarts lösen so nichts aus. Kein Cron nötig.
* **Bremsen:** Schalter „Automatisch posten“, pro Sender ein Schalter „Posten“ im Editor, Sperrfrist je Sender (Standard 6 Stunden), Höchstzahl pro Tag (Standard 4). Wer in der Web-App das Erfassen aussetzt, postet auch nicht.
* **Text:** Vorlage mit `{sender}`, `{link}`, `{website}` und `{hashtags}`. Hashtags gibt es für alle Sender und zusätzlich pro Sender. Ist ein Beitrag zu lang (Bluesky 300 Grapheme, Mastodon laut Instanz, Links zählen dort 23 Zeichen), fallen Hashtags von hinten weg. Die Vorschau im Admin zeigt beide Fassungen mit Länge.
* **Link:** `{link}` zeigt auf `1radioe.net/s/<smapi_id>`, bei einem Netz mit Sprache `de` auf `1radioe.net/de/s/<smapi_id>`. Die Adresse leitet auf `/?s=<smapi_id>` (oder `/de/?s=…`) weiter, die Web-App mit dem Sender im Player und Open-Graph-Tags für die Link-Karte.
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
* Texte aus dem Admin gehen durch eine Positivliste weniger Tags, ohne Attribute außer geprüften Link-Zielen
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

[SortableJS](https://github.com/SortableJS/Sortable) 1.15.6, [Cropper.js](https://github.com/fengyuanchen/cropperjs) 1.6.2 und [qrcode-generator](https://github.com/kazuhikoarase/qrcode-generator) 1.4.4, alle MIT-Lizenz, lokal eingebunden. [hls.js](https://github.com/video-dev/hls.js) 1.7.4 (`hls.light.min.js` aus dem npm-Paket, Prüfsumme in `www/assets/vendor/hls.js-LICENSE.txt`), Apache-2.0-Lizenz, nur für HLS in Firefox. Schrift [Mona Sans](https://github.com/github/mona-sans) 2.0.27 von GitHub, SIL Open Font License 1.1 (`www/assets/fonts/MonaSans-OFL.txt`).

## Lizenz

[MIT](LICENSE)
