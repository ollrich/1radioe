# Änderungen

## 1.10.2 (13. November 2026)

* Admin, System: Neuer Bereich „Sonos-Adressen“ zeigt, unter welcher Adresse Sonos den Dienst aufruft, wann zuletzt und wie oft. Er zählt auch ohne Diagnose-Log und ohne IP-Adressen. So sieht man, wann eine alte Adresse wie `eichhof.co/1radioe/smapi.php` nicht mehr gebraucht wird
* Das Diagnose-Log hat eine Spalte „Adresse“. Einträge ohne Adresse stammen von einer älteren Version, zum Beispiel der alten Installation
* Datenbank: Schema 8 mit der Spalte `endpoint` im Log und der Tabelle `r1_endpoints`. Die alte Installation an derselben Datenbank läuft weiter
* `DEPLOY-SETUP.md`: Die alte `smapi.php` wird in Schritt 8 durch eine Datei ersetzt, die den neuen Code einbindet. Damit bekommen Lautsprecher an alten Adressen sofort den neuen Stand, und in Schritt 9 kann die alte Installation bis auf diese Datei weg

## 1.10.1 (13. November 2026)

* Web-App: Die Leertaste ist jetzt immer Play und Pause, auch direkt nach einem Klick auf einen Knopf. Bisher blieb der Fokus nach einem Klick auf „Nächster Sender“ auf dem Knopf, und die Leertaste sprang einen Sender weiter. Wer per Tab-Taste auf einem Knopf steht, drückt ihn mit der Leertaste wie gewohnt. Gedrückt halten schaltet nicht mehr mehrfach um, und nach dem Verstellen der Lautstärke greift die Leertaste ebenfalls

## 1.10.0 (12. November 2026)

**Song im Player, Info am Hörstatus**

* Schickt ein Stream Titel und Interpret mit (ICY-Metadaten), zeigt die Web-App beides beim Hören: im Player unter dem Sendernamen, im angedockten Player und in der Liste statt „Live“, auf dem Sperrbildschirm und in den Mediensteuerungen
* Der Server liest die Titel aus dem Stream (`/api/nowplaying?id=<id>`), weil Browser sie nicht aus dem Audio holen können. Höchstens alle 20 Sekunden je Sender, Streams ohne Titel nur alle 5 Minuten, parallele Anfragen warten auf eine Abfrage. HLS wird übersprungen
* Robust gegen leere Titel vor dem eigentlichen Song, Latin-1, Apostrophe im Namen und zusätzliche Felder wie `json=`. Ein Titel, der nur den Sendernamen wiederholt, wird nicht gezeigt, der Sendername als Interpret entfällt
* `stations.json` nennt die Adresse als `nowplaying_url`
* Die Mint-Balken „Ollie hört gerade“ zeigen beim Darüberfahren eine Info mit dem Sender, an Kategorien mit dem Sender darin. Auf Touch-Geräten zeigt ein Tipp auf die Balken die Info, statt den Sender zu starten
* Datenbank-Schema 7, wieder nur ergänzend

## 1.9.0 (12. November 2026)

**Posting zu Bluesky und Mastodon**

* Neuer Bereich „Posting“ im Admin. Läuft ein Sender nach einer Wartezeit noch, egal ob auf Sonos oder im Browser, geht ein Beitrag an Bluesky und Mastodon
* Bremsen: Schalter „Automatisch posten“, Wartezeit (Standard 3 Minuten), Sperrfrist je Sender (6 Stunden), Höchstzahl pro Tag (4). Im Sender-Editor lässt sich ein Sender vom Posten ausnehmen und mit eigenen Hashtags versehen
* Textvorlage mit `{sender}`, `{link}`, `{website}` und `{hashtags}`, Vorschau für beide Netzwerke mit Längenprüfung. Zu lange Beiträge verlieren Hashtags von hinten
* Bluesky mit Handle, App-Passwort und frei wählbarem PDS, Links und Hashtags als Facets, Link-Karte mit Senderlogo. Mastodon mit Instanz und Access-Token, Sichtbarkeit wählbar, Zeichengrenze wird von der Instanz gelesen
* App-Passwort und Token verschlüsselt gespeichert (libsodium, Schlüssel `secret_key` in `config.php`)
* „Verbindung prüfen“ und „Testbeitrag senden“, Protokoll der letzten Beiträge mit Links und Fehlern
* Der Beitrag läuft nach der Antwort an Sonos oder den Browser, eine Meldung wartet nie auf Bluesky oder Mastodon
* Senderseiten: `1radioe.net/s/<smapi_id>` öffnet die Web-App mit diesem Sender im Player, mit Open-Graph-Tags für Link-Karten. Auf dem Handy steht der Sender gleich im angedockten Player, Kategorien öffnen sich
* Der Hörstatus merkt sich, seit wann ein Sender läuft. System → Hörstatus zeigt das an
* Die Skala springt beim Laden zuverlässig zum aktuellen Sender, auch wenn er außerhalb des sichtbaren Bereichs liegt
* Datenbank-Schema 6, wieder nur ergänzend

## 1.8.0 (12. November 2026)

**Hörzugang: Hören im Browser zählt für den Hörstatus**

* Nach dem Login bietet der Admin an, den Browser zum Hören zu merken, mit einem Namen wie „Firmenrechner“. Der Browser bekommt ein eigenes Cookie `r1listen` (180 Tage, verlängert sich bei Benutzung, `HttpOnly`, `SameSite=Lax`). Es erlaubt nur Meldungen an `/api/listen` und führt nie in die Verwaltung. Die Admin-Sitzung bleibt so kurzlebig wie bisher
* Gespeichert wird nur die Prüfsumme des Cookies. System → Hörgeräte zeigt alle Geräte mit „zuletzt benutzt“ und lässt sie widerrufen, auch „Hier nicht mehr merken“ für den aktuellen Browser
* Mit gemerktem Browser meldet der Player in der Web-App „läuft“ beim Start und jede Minute, „aus“ bei Pause, Fehler, Senderwechsel und beim Schließen des Tabs. Der eigene Status erscheint sofort, ohne auf die nächste Abfrage zu warten
* Nur in diesem Browser zeigt die Web-App oben „Hören wird erfasst“. Ein Klick pausiert die Erfassung für die laufende Sitzung
* `POST /api/listen` prüft Cookie und Herkunft (`Origin`), „läuft“ braucht zusätzlich den Header `X-1radioe: 1`. „aus“ kommt auch ohne ihn an, weil `sendBeacon` beim Schließen keine eigenen Header senden kann
* Besucher ohne Cookie melden nie etwas
* Auf der Skala endet die Nadel jetzt über den Status-Balken statt sie zu verdecken

## 1.7.0 (12. November 2026)

**Web-App mit Player auf 1radioe.net**

* Startseite mit allen aktiven Sendern. Am Desktop steht der Player in der Mitte, darunter die Sender als Skala mit Nadel. Kategorien öffnen eine eigene Skala, „Alle Sender“ führt zurück. Passen nicht alle Sender in die Breite, erscheinen Pfeile zum Blättern
* Auf dem Handy eine Liste mit aufklappbaren Kategorien, nach dem ersten Start ein angedockter Player, der sich zur Vollansicht aufziehen lässt
* Player: Play/Pause, Vor/Zurück mit Umlauf innerhalb der Ebene, auf der der Sender gestartet wurde, Lautstärke (bleibt im Browser gespeichert, auf iOS ausgeblendet, weil Seiten sie dort nicht ändern dürfen), „Über den Sender“ mit Beschreibung, Website und Einzel-Playlist
* Robuste Wiedergabe: Pausieren legt auf, Abspielen verbindet neu am Live-Punkt. Bei Abbruch, Fehler oder Stillstand verbindet der Player mit wachsendem Abstand neu (1, 2, 5, dann alle 10 Sekunden, rund zwei Minuten lang) und zeigt „Verbindet“
* Media Session: Medientasten, Mediensteuerung von Chrome und Sperrbildschirm mit Sendername und Logo
* Tastatur am Desktop: Leertaste Play/Pause, Pfeiltasten Vor/Zurück, 1 bis 0 Direktwahl, Esc zurück zu allen Sendern. Erklärt im (?) über der Skala
* Hörstatus: kleine Mint-Balken am Sender, den Ollie gerade hört, auch am Ordner einer Kategorie und im Player. Abfrage alle 30 Sekunden und beim Zurückkehren zum Tab
* Pop-ups „Über 1radiœ“, Impressum und Datenschutz, Impressum und Datenschutz noch mit Platzhaltern. Direkt verlinkbar als `/#impressum`, `/#datenschutz`, `/#about`
* Hell als Grundversion, im Dark Mode automatisch Navy. Schrift Mona Sans (SIL OFL 1.1), lokal eingebunden
* Ohne JavaScript eine schlichte Liste mit Links zu den Streams
* Strenge Content Security Policy, keine externen Ressourcen, `Referrer-Policy: no-referrer`, `noindex`

## 1.6.0 (12. November 2026)

**Playlist-Dateien und Sender als JSON**

* `/streams.m3u` und `/streams.pls`: alle aktiven Sender in der Reihenfolge des Admins, zum Beispiel für VLC unter „Netzwerkstream öffnen“. Die Einträge zeigen direkt auf die Original-Streams, `.pls` und `.m3u` der Sender sind aufgelöst
* `/s/<id>.m3u`: ein einzelner Sender als Playlist
* `/api/stations.json`: alle aktiven Sender mit Beschreibung, Website, Kategorie, Reihenfolge, Stream und Logo samt Prüfsumme, für eine eigene App. Mit `If-None-Match` kommt `304`, solange sich nichts geändert hat
* MP3-Playlists und Tracks bleiben Sonos vorbehalten und erscheinen in keiner dieser Ausgaben
* Neues Feld „Website des Senders“ im Editor, für „Über den Sender“ in der Web-App
* Der Stream-Test prüft, ob ein Stream per HTTPS erreichbar ist. Bei `http://` probiert er dieselbe Adresse mit `https://` und bietet sie zur Übernahme an. „Alle Streams prüfen“ speichert das Ergebnis, Sender ohne HTTPS tragen im Raster die Marke „HTTP“
* Systemseite: neuer Bereich „Öffentliche Adressen“ mit Kopier-Knopf

## 1.5.0 (12. November 2026)

**Hörstatus, Kern und Sonos**

* Die App merkt sich, welche Sender gerade laufen: je Sender nur der Zeitpunkt der letzten Meldung und ob danach „aus“ kam. Kein Gerät, keine Quelle, keine IP-Adresse, keine Haushaltskennung von Sonos
* Ein Sender gilt als laufend, solange seine letzte Meldung jünger als 150 Sekunden ist. Fehlt das „aus“, erlischt der Status von selbst
* Sonos meldet über SMAPI: `getMediaURI` für einen Sender startet den Status, `reportPlaySeconds` wird jetzt mit `interval` 60 beantwortet und hält ihn am Leben, `reportPlayStatus` beendet ihn. Tracks und Playlists lösen nichts aus
* `GET /api/status` liefert die laufenden Sender als JSON, ungecacht
* Admin, System: Tabelle „Hörstatus“ zum Testen, aktualisiert sich alle 10 Sekunden
* Das Diagnose-Log zeigt bei Wiedergabe-Meldungen die übergebenen Werte (`seconds`, `status`) und ob der Status gesetzt wurde
* Datenbank Version 5: Spalten `website` und `https_ok` für Sender, Tabellen `r1_listen_status` und `r1_listen_devices`. Alles additiv, die alte Installation läuft mit derselben Datenbank weiter
* Lokale Entwicklung: `app/dev-router.php` bildet die Adressen ohne Dateiendung für den eingebauten PHP-Server nach

**Noch mit echtem Sonos zu prüfen** (Diagnose-Log einschalten, einen Sender starten, ein paar Minuten laufen lassen, stoppen):

1. Kommt `reportPlaySeconds` bei Live-Streams überhaupt, und etwa jede Minute?
2. Muss dafür im Sonos-Entwicklerportal unter Service Configuration eine Reporting-Fähigkeit eingeschaltet werden?
3. Ruft Sonos `getMediaURI` auch ohne Wiedergabe auf, etwa beim Vorladen?
4. Was kommt beim Stoppen: `reportPlayStatus`, `setPlayedSeconds` oder nichts?

## 1.4.0 (12. November 2026)

**Umzug nach 1radioe.net, ohne neue Funktionen**

* Neue Struktur: Programmcode in `app/` außerhalb des Web-Verzeichnisses, Web-Verzeichnis `www/` mit Admin und SMAPI-Endpunkt. Logos, Cover und Tracks liegen in `www/media/` und gehören nie ins Repository
* Automatisches Ausrollen: Ein Push auf `main` ruft per GitHub-Webhook `deploy.php` auf. Nach geprüfter Signatur bringt `git reset --hard` den Server auf den neuen Stand, Uploads bleiben unberührt. Secret und Deploy-Log liegen in `1radioe-private`, die Einrichtung steht in `DEPLOY-SETUP.md`
* Keine Indexierung: `robots.txt` sperrt Suchmaschinen, dazu der Header `X-Robots-Tag: noindex, nofollow`
* Die Systemseite zeigt fehlende Logo-, Cover- und Track-Dateien, praktisch nach dem Kopieren der Medien
* `base_url` darf leer bleiben, die Adresse ergibt sich dann aus der Anfrage. So teilen sich alte und neue Installation während des Umzugs eine Config
* Die Config-Vorlage liegt als `config.sample.php` im Hauptordner des Repositorys
* Der automatische Umzug von Medien aus 1.0 und 1.1.0 entfällt
* MIT-Lizenz

## 1.3.0 (8. November 2026)

* **Kategorien:** Sender und Playlists lassen sich in Kategorien wie „Lounge“ zusammenfassen. In Sonos erscheint eine Kategorie als Ordner mit ihren Einträgen. Im Admin gibt es dafür eine eigene Ansicht mit Drag & Drop, „Vorhandene zuordnen“ und das Feld „Kategorie“ im Editor. Ohne eigenes Bild bekommt eine Kategorie ein Raster aus den ersten vier Logos. Leere Kategorien zeigt Sonos nicht an, beim Löschen einer Kategorie bleiben ihre Einträge erhalten. Favoriten und Wecker in Sonos laufen weiter, weil sich die Kennungen der Sender nicht ändern
* Kleine Akzentschrift auf Navy (zum Beispiel „♫ 3 Tracks“, „Playlist“, Links) in Mint `#9ac7bf` aus der Farbpalette von oliver eichhof, alles Klickbare bleibt im Logo-Blau `#1a80b6`

## 1.2.1 (8. November 2026)

* Alle Blautöne der Oberfläche sind jetzt das Logo-Blau `#1a80b6`: auch Beschriftungen wie „♫ 3 Tracks“ und „Playlist“ (vorher ein helleres `#4db0e6`) und der Zuschnittrahmen im Bildeditor (vorher das Standardblau von Cropper.js). Beim Überfahren behält der Hauptknopf seine Farbe und hebt sich leicht an

## 1.2.0 (8. November 2026)

**Oberfläche**

* Logo und Favicon neu aus der Vorlage erstellt, die Farben der Oberfläche stammen jetzt aus dem Logo: Navy `#0b1328`, Weiß `#fcfcfd`, Blau `#1a80b6`
* Logo und Favicon tragen die Version in der Adresse, damit der Browser nach einem Update nicht das alte Bild zeigt
* Leere Playlists erscheinen ausgegraut mit „leer“, weil Sonos sie nicht anzeigt
* Nach einer Änderung der Stream-Adresse erinnert der Editor an den Stream-Test

**Fehler behoben**

* „Logo entfernen“ für einen nicht vorhandenen Sender endete mit einem internen Fehler
* Scheiterte nach dem Speichern eines neuen Senders der Logo-Upload, legte erneutes Speichern den Sender doppelt an
* Eine geänderte Stream-Adresse behielt das Testergebnis und die Playlist-Markierung der alten Adresse
* MP3-Tags mit ungültigem UTF-8 oder Steuerzeichen konnten die Trackliste leer bleiben lassen oder die Antwort an Sonos unlesbar machen
* Scheiterte beim Import das Speichern in der Datenbank, blieb die MP3 verwaist im Track-Ordner und fehlte im FTP-Eingang
* Fehlte eine Logo-Datei auf dem Server, bekam Sonos eine leere Bildadresse

**Verbesserungen**

* Doppelte MP3s werden am Inhalt erkannt und nicht zweimal gespeichert. Fehlt die Datei eines Tracks auf dem Server, stellt ein erneuter Upload derselben MP3 sie wieder her
* MP3-Tags in Windows-1252 und fälschlich als Latin-1 markiertes UTF-8 werden richtig gelesen
* Der FTP-Import überspringt Dateien, die gerade noch hochgeladen werden
* Die Größe der Upload-Teile richtet sich nach den Grenzen des Servers
* Sonos bekommt keine leeren Playlists und keine Tracks mehr, deren Datei fehlt
* Ein Stream-Test im Editor wird beim Speichern übernommen, auch bei neuen Sendern
* Logos, Cover und MP3s dürfen ein Jahr im Cache bleiben, ihre Dateinamen ändern sich bei jeder Änderung
* Das Datenbank-Update läuft auch über den Sonos-Endpunkt und ist gegen parallele Aufrufe gesperrt
* Die Bildverarbeitung prüft vorher den Speicherbedarf, und bricht der Server trotzdem ab, kommt eine verständliche Meldung statt einer leeren Antwort
* Kein HSTS-Header mehr: die App legt keine Regeln für die ganze Domain fest
* Stream-Test und Logo-Download prüfen auch IPv6-Adressen auf interne Netze
* Die Systemseite prüft zusätzlich die PHP-Erweiterung `mbstring`

## 1.1.1 (8. November 2026)

* Logos, Cover und Tracks liegen in `1radioe-media` neben der App, FTP-Eingang und Upload-Puffer in `1radioe-private`. Vorhandene Dateien zieht die App beim ersten Aufruf automatisch um, ein Update darf den Ordner `1radioe` also komplett ersetzen
* Fehlende Dateien werden als fehlend angezeigt statt als kaputtes Bild

## 1.1.0 (8. November 2026)

* Playlists aus eigenen MP3s: Upload in Teilen oder per FTP, Titel, Interpret, Album und Cover aus den MP3-Tags, Reihenfolge per Drag & Drop, Bibliothek mit Tracks in mehreren Playlists

## 1.0.0 (7. November 2026)

* SMAPI-Dienst mit Verwaltung: Sender anlegen, Stream-Test mit Formaterkennung, Logo-Editor, Reihenfolge per Drag & Drop, Login mit Passwort und TOTP, Diagnose-Log
