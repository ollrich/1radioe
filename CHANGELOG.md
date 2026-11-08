# Änderungen

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
