# 1radiœ: Schnittstellen für eine App

Was eine eigene App von einer 1radiœ-Installation braucht. Stand 1.32.2. Alle Antworten sind JSON in UTF-8, alle Adressen HTTPS. `<domain>` steht für die Adresse der Installation.

## Überblick

| Adresse | Methode | Wofür |
|---|---|---|
| `/api/stations.json` | GET | Sender, Kategorien, Logos, Stream-Adressen; `?profile=<profil-2>` für die Liste von Profil 2 |
| `/api/playlists` | GET | MP3-Playlists mit ihren Tracks; immer mit dem Schlüssel des Profils |
| `/api/status` | GET | welche Sender gerade gehört werden (die Balken) und welcher zuletzt |
| `/api/nowplaying?id=<id>` | GET | laufender Song eines Senders |
| `/api/listen` | POST | die App meldet: dieser Sender läuft, oder nicht mehr |

`stations.json` nennt die übrigen Adressen selbst (`status_url`, `nowplaying_url`, `listen_url`). Die App sollte sie von dort nehmen und nicht fest einbauen.

## Profile (seit 1.32.0)

Zwei Listen aus demselben Bestand. Die Namen der Profile, die als `profile` übergeben werden, stehen in `PROFILES` in `app/profiles.php`; hier heißen sie `<profil-1>` und `<profil-2>`.

* **Profil 1** ist der Standard: ohne `profile` (oder mit `?profile=<profil-1>`) kommt seine Liste, dieselbe wie in Web-App, Sonos und den Playlist-Dateien.
* **Profil 2:** `?profile=<profil-2>` an `stations.json` und `playlists`. Diese Liste gibt es nur für die App, nicht in der öffentlichen Web-App, nicht in Sonos und nicht in `streams.m3u`/`streams.xspf`. `stations.json` braucht keinen Schlüssel, `playlists` den Schlüssel von Profil 2.
* **Unbekanntes Profil:** `400` mit `{"error": "unknown profile", "profiles": ["<profil-1>", "<profil-2>"]}`, nie stillschweigend die Liste von Profil 1.
* **Zuordnung:** In der Verwaltung hat jeder Sender und jede MP3-Playlist zwei Häkchen „Sichtbar für Profil 1 / Profil 2“, beide möglich. Kategorien folgen ihrem Inhalt: Eine Kategorie erscheint in einem Profil nur, wenn sie dort sichtbare Sender oder Playlists hat. Die Track-Bibliothek ist gemeinsam, ein Track ist über seine Playlists sichtbar.
* **Antwort:** gleiches Format für beide Profile, dazu `"profile"` mit dem Namen des Profils. Der `ETag` enthält das Profil, nach einem Wechsel bekommt die App also nie ein `304` für die andere Liste. Logo- und Cover-Adressen von Sendern und Playlists sind für beide Profile gleich, Kategorie-Raster nicht (siehe `logo`).
* **Schlüssel:** In der Verwaltung (Hörgeräte) wird jeder Schlüssel für ein Profil erzeugt. Der Schlüssel von Profil 1 meldet den Hörstatus und liest dessen Playlists, der von Profil 2 liest nur die Playlists von Profil 2.
* **Hörstatus:** nur Profil 1. `POST /api/listen` mit dem Schlüssel von Profil 2 gibt `403` mit `{"error": "no listening status for this profile"}`. `/api/status` ist für beide gleich.

## Sender: `GET /api/stations.json`

```json
{
  "version": "ebe58e9c",
  "profile": "<profil-1>",
  "generated": "2026-11-18T19:42:09+01:00",
  "status_url": "https://<domain>/api/status",
  "listen_url": "https://<domain>/api/listen",
  "nowplaying_url": "https://<domain>/api/nowplaying?id={id}",
  "categories": [
    { "id": "nts", "name": "NTS", "description": "Nuts to soup.", "sort": 12,
      "logo": { "url": "https://<domain>/media/….jpg", "sha256": "…", "size": 600 } }
  ],
  "stations": [
    {
      "id": "bbc-radio-1",
      "name": "BBC Radio 1",
      "description": "…",
      "website": "https://www.bbc.co.uk/sounds/play/live/bbc_radio_one",
      "country": "GB",
      "city": "London",
      "category": null,
      "sort": 1,
      "streams": [ { "url": "https://…m3u8", "format": "application/vnd.apple.mpegURL", "https": true } ],
      "logo": { "url": "https://<domain>/media/….jpg", "sha256": "…", "size": 600 }
    }
  ]
}
```

* **`id`** ist dauerhaft (es ist die SMAPI-ID, die auch Sonos in Favoriten speichert). Damit merkt sich die App Favoriten und meldet den Hörstatus.
* **Reihenfolge und Ebenen:** wie in der Verwaltung und in Sonos. `category` ist `null` (oberste Ebene) oder die `id` einer Kategorie. `sort` zählt innerhalb der Ebene ab 1: Sender ohne Kategorie, Kategorien und MP3-Playlists teilen sich die Plätze der obersten Ebene, Sender und Playlists einer Kategorie zählen darin neu ab 1. Die Plätze der Playlists stehen in `/api/playlists`; in `stations.json` fehlen sie, dort hat `sort` also Lücken. Die App sortiert nach `sort` und nummeriert selbst. Eine Kategorie erscheint nur, wenn sie aktive Sender oder Playlists des Profils hat.
* **`streams`**: bisher immer genau einer. `format` ist der MIME-Typ (`audio/mpeg`, `audio/aac`, `application/ogg`, `application/vnd.apple.mpegURL` für HLS, `audio/flac`). `.pls` und `.m3u` sind schon zur Stream-Adresse aufgelöst. `https: false` heißt: nur per HTTP erreichbar.
* **Umleitungen:** Viele Streams leiten weiter, manche bei jedem Abruf auf einen anderen Server. Die App spielt immer die Adresse aus `stations.json`, nie eine umgeleitete, die sie sich gemerkt hat.
* **`logo`**: 600 × 600 px, JPEG oder PNG, oder `null`. Der Dateiname ändert sich mit jedem neuen Logo, `sha256` erlaubt einen Abgleich ohne Download. Kategorien ohne eigenes Bild haben ein Raster aus bis zu vier Logos, gebildet nur aus den Einträgen, die das Profil in der Kategorie sieht.
* **`description`** gibt es bei Sendern und (seit 1.30.2) bei Kategorien, die App zeigt sie unter dem Namen wie die Web-App. Leer, wenn nichts eingetragen ist.
* **`website`, `country`, `city`**: leer bzw. `null`, wenn nichts eingetragen ist. `country` ist ein ISO-3166-Code (`GB`, `FR`, `DE`), den Namen setzt die App in ihrer Sprache ein.
* **Nicht enthalten:** MP3-Playlists und Tracks (die kommen aus `/api/playlists`), SoundCloud-Einträge, Deezer-Links und YouTube-Playlists (nur in der Web-App, ihre Bedingungen erlauben kein Abspielen in einer Radio-App, YouTube keinen Ton ohne Bild).
* **Zwischenspeichern:** Die Antwort trägt `Cache-Control: no-cache` und einen `ETag`. Mit `If-None-Match: <etag>` kommt `304 Not Modified`, solange sich nichts geändert hat. `version` ändert sich bei jeder Änderung in der Verwaltung.

## MP3-Playlists: `GET /api/playlists`

Immer mit dem Schlüssel des Profils (Header `X-1radioe-Token`), weil die Liste die direkten Adressen der MP3-Dateien nennt: ohne `profile` die Playlists von Profil 1 mit dessen Schlüssel (derselbe wie für `/api/listen`), mit `?profile=<profil-2>` die von Profil 2 mit dessen Schlüssel. Der Server prüft, dass beide zusammenpassen.

| Fall | Antwort |
|---|---|
| passender Schlüssel | `200` |
| kein, unbekannter oder widerrufener Schlüssel | `401` `{"error": "key required"}` |
| Schlüssel des anderen Profils | `403` `{"error": "key of another profile"}` |
| unbekanntes Profil | `400` `{"error": "unknown profile", "profiles": ["<profil-1>", "<profil-2>"]}` |

```json
{
  "version": "d4cefb1f",
  "profile": "<profil-1>",
  "generated": "2026-11-25T14:03:13+01:00",
  "playlists": [
    {
      "id": "lieblingsalben",
      "name": "Lieblingsalben",
      "description": "…",
      "category": null,
      "sort": 3,
      "cover": { "url": "https://<domain>/media/….jpg", "sha256": "…", "size": 600 },
      "tracks": [
        {
          "id": "track:12",
          "title": "Genesis",
          "artist": "Justice",
          "album": null,
          "duration": 233,
          "url": "https://<domain>/media/tracks/….mp3",
          "cover": { "url": "https://<domain>/media/….jpg", "sha256": "…", "size": 600 }
        }
      ]
    }
  ]
}
```

* **`id`** der Playlist ist ihre dauerhafte SMAPI-ID, die eines Tracks `track:<Nummer>` wie in Sonos.
* **`category` und `sort`:** in derselben Zählung wie die Sender in `stations.json` desselben Profils. Mit beiden Antworten zusammen ergibt sich die Reihenfolge von Sonos.
* **Enthalten** sind aktive Playlists des Profils mit mindestens einem Track, dessen Datei vorhanden ist; Tracks ohne Datei fehlen. Leere Playlists fehlen ganz, wie in Sonos.
* **`artist`, `album`**: `null`, wenn leer. `duration` in Sekunden.
* **`cover`** der Playlist und der Tracks wie `logo` in `stations.json`, oder `null`. Ein Track ohne eigenes Cover nimmt das Cover der Playlist.
* **`url`**: die MP3-Datei direkt, gestreamt, kein Download nötig. Die Dateinamen sind zufällig und bleiben gleich.
* **Zwischenspeichern:** `Cache-Control: no-cache`, `ETag` aus dem Inhalt selbst (mit Profil). Jede Änderung, auch an einem Track, gibt einen neuen.
* **Hörstatus:** Tracks werden nicht gemeldet.

## Hörstatus lesen: `GET /api/status`

```json
{ "active": ["flux-b-funk"], "last": "fip", "ttl": 150 }
```

`active` sind die `id`s der Sender, die gerade gehört werden, in der Reihenfolge der Liste. Eine Kategorie zeigt die Balken, wenn einer ihrer Sender aktiv ist. Die Web-App fragt alle 30 Sekunden.

`last` ist die `id` des zuletzt gehörten Senders (seit 1.29.0): die neueste beendete Sitzung von mindestens fünf Minuten, sonst `null`. Ein Sender, der gerade läuft, zählt nicht. Die Web-App zeigt „Zuletzt gehört“ nur, solange `active` leer ist.

## Laufender Song: `GET /api/nowplaying?id=<id>`

```json
{ "id": "groove-salad", "track": { "artist": "Sounds From The Ground", "title": "Red Light Green Light" } }
```

`track` ist `null`, wenn der Stream keine Titel schickt (HLS schickt nie welche). `artist` kann leer sein. Der Server liest die ICY-Metadaten selbst und hält jede Antwort 20 Sekunden (`Cache-Control: public, max-age=15`). Häufiger als alle 20 bis 25 Sekunden zu fragen bringt nichts, und nur solange der Sender spielt. Unbekannte oder inaktive `id`: `404`. Liest die App die ICY-Daten selbst aus dem Stream, braucht sie diese Adresse nicht.

## Hörstatus melden: `POST /api/listen`

Nur mit Schlüssel von Profil 1. Er wird in der Verwaltung unter **System, Hörgeräte, Schlüssel erzeugen** angelegt (Name zum Beispiel „Android“) und genau einmal angezeigt, allein (für die App) und als Zeile für Home Assistant. Die App speichert ihn verschlüsselt (unter Android im Keystore). Widerrufen lässt er sich dort jederzeit.

```http
POST /api/listen
Content-Type: application/json
X-1radioe-Token: <schlüssel>

{"station": "flux-b-funk", "state": "on"}
```

* **`state`**: `"on"` beim Start und danach jede Minute, solange der Sender spielt (Heartbeat), `"off"` bei Pause, Stopp und Senderwechsel (für den alten Sender). Ein Sender gilt als laufend, solange die letzte Meldung jünger als 150 Sekunden ist (`ttl` in `/api/status`) und kein `"off"` folgte. Stürzt die App ab, erlischt der Status also von selbst.
* **Antworten:** `204` gemeldet, `404` unbekannte `id`, `401` Schlüssel unbekannt oder widerrufen, `403` Schlüssel von Profil 2, `400` Feld fehlt oder `state` ist falsch, `503` Server gerade nicht bereit.
* **Folgen:** Das erste `"on"` startet eine Hörsitzung. Läuft der Sender drei Minuten, kann daraus ein Beitrag auf Bluesky und Mastodon werden (mit Sperrfrist je Sender und Tageslimit, in der Verwaltung unter Posting). Die App sollte deshalb nur melden, solange wirklich Ton läuft, nicht beim bloßen Antippen und nicht, während sie neu verbindet.
* **Schalter in der Verwaltung:** Mit „Nur Meldungen mit Schlüssel annehmen“ zählen nur noch Home Assistant, gemerkte Browser und Apps mit Schlüssel. Die App ist davon nicht betroffen.
* Alternativ zu `station` gehen `url` (die gespielte Adresse) und `title`. Das ist für Home Assistant gedacht, eine App kennt die `id` und schickt sie.

## Sonst gut zu wissen

* **Kein Login, keine Cookies:** Lesen geht ohne Anmeldung (außer `/api/playlists`), Schreiben nur mit dem Schlüssel. Die Verwaltung (`/admin/`) ist für die App tabu.
* **Sprache:** Die Daten sind einsprachig (Namen und Beschreibungen wie in der Verwaltung eingetragen). Die Web-App gibt es auf Englisch (`/`) und Deutsch (`/de/`), Senderseiten unter `/s/<id>` bzw. `/de/s/<id>`, das wäre das Ziel für „Teilen“ in der App.
* **HTTP:** `http://` leitet auf `https://` um, für POST aber nicht. Die App nutzt immer HTTPS.
* **Weitere Quellen:** [README](README.md) (Verwaltung, Hörstatus, Home Assistant) und [CHANGELOG](CHANGELOG.md).
