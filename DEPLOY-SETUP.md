# Einrichtung auf All-Inkl mit automatischem Deploy

Diese Anleitung bringt 1radiœ auf `https://1radioe.net` und richtet das automatische Ausrollen ein: Ein Push auf `main` aktualisiert den Server von selbst. Sie geht davon aus, dass die bisherige Installation unter `eichhof.co/1radioe` im selben All-Inkl-Account läuft und weiterläuft, bis Sonos umgestellt ist.

`w0123456` steht überall für deine Account-Kennung. Im Webspace sieht das am Ende so aus:

```
/www/htdocs/w0123456/
  1radioe.net/            Domain-Ordner und Git-Checkout, alles für 1radiœ liegt hier
    1radioe-private/      Config, Webhook-Secret, Deploy-Log, FTP-Eingang. Nie in Git.
    app/                  Programmcode, nicht per Web erreichbar
    www/                  Web-Verzeichnis der Domain
      media/              Logos, Cover, Tracks. Nicht in Git.
  eichhof.co/1radioe/     alte Installation, bleibt vorerst
```

`1radioe-private/` liegt neben `www/` und damit außerhalb des Web-Verzeichnisses. Git schließt den Ordner aus (`.gitignore`), deshalb landet er nie im Repository und das Ausrollen lässt ihn unberührt.

## Wie das Ausrollen funktioniert

1. Ein Push auf `main` schickt einen Webhook von GitHub an `https://1radioe.net/deploy.php`.
2. `deploy.php` prüft die Signatur mit dem gemeinsamen Secret. Ohne Secret wird nichts ausgerollt.
3. Nur Pushes auf `main` zählen. Dann laufen `git fetch` und `git reset --hard origin/main`.
4. Jeder Lauf steht in `1radioe-private/deploy.log`, die letzten 100 Einträge.

Das Repository ist öffentlich. Der Server holt den Code deshalb über HTTPS, ohne Schlüssel.

`www/media/` und `1radioe-private/` sind nicht in Git. `git reset --hard` lässt solche Dateien in Ruhe, deshalb überleben Uploads, Config und Secrets jeden Deploy. Auf dem Server also nie Dateien aus dem Repository von Hand ändern: Der nächste Deploy überschreibt sie. Von Hand bearbeitet wird nur, was in `1radioe-private/` liegt. Inhalte kommen nur über den Admin, MP3s per FTP in `1radioe-private/inbox/`.

Datenbank-Änderungen brauchen keinen eigenen Schritt. Die App erweitert die Datenbank beim nächsten Aufruf von Admin oder Sonos selbst.

## 1. Domain im KAS

Zuerst die Domain anlegen. Der KAS legt dabei den Domain-Ordner an, den es für Domain und SSH ohnehin braucht.

* Domain `1radioe.net` hinzufügen, Ordner `/1radioe.net/` anlegen, Zielverzeichnis `/1radioe.net/www/`
* SSL mit Let's Encrypt aktivieren und **SSL erzwingen** einschalten
* PHP 8.1 oder neuer, besser die neueste angebotene Version

Das Zielverzeichnis muss auf `www` enden. Sonst wäre der Programmcode übers Web erreichbar.

## 2. Per SSH anmelden

SSH im KAS aktivieren, falls noch nicht geschehen, und mit den Zugangsdaten aus dem KAS anmelden. Dann in den Domain-Ordner wechseln, alle folgenden Befehle laufen dort:

```bash
cd /www/htdocs/w0123456/1radioe.net
```

In der SSH-Sitzung bei All-Inkl ist `$HOME` gleich `/`. Die Anleitung verwendet deshalb kein `~`, sondern Pfade relativ zum Domain-Ordner. `../` ist der Account-Root.

## 3. Code holen

Der Domain-Ordner existiert schon und ist vielleicht nicht leer. Deshalb wird Git direkt darin eingerichtet, statt zu klonen. Erst nachsehen, was der KAS angelegt hat:

```bash
ls -A . www
```

Liegt in `www/` eine `index.html` vom KAS, löschen. Sie würde statt der Web-App ausgeliefert.

```bash
git init
git remote add origin https://github.com/ollrich/1radioe.git
git fetch origin
git checkout -f -B main origin/main
git status
```

`git init` gibt einen Hinweis zum Branch-Namen `master` aus, der ist egal. Am Ende muss `Your branch is up to date with 'origin/main'` stehen.

Dann den privaten Ordner anlegen. Die `.htaccess` darin ist nur ein Sicherheitsnetz, falls die Domain einmal versehentlich auf `1radioe.net/` statt auf `1radioe.net/www/` zeigt.

```bash
mkdir -p 1radioe-private/inbox 1radioe-private/tmp
echo 'Require all denied' > 1radioe-private/.htaccess
git status --short
```

`git status --short` gibt nichts aus: Der Ordner ist von Git ausgeschlossen.

## 4. Config

Die neue Installation bekommt eine Kopie der `config.php` der alten. Die alte liegt in einem Ordner `1radioe-private` oberhalb von `eichhof.co/1radioe`, so findet man ihn:

```bash
ls -d ../1radioe-private ../eichhof.co/1radioe-private 2>/dev/null
```

Den gefundenen Pfad im ersten Befehl einsetzen:

```bash
cp -p ../1radioe-private/config.php 1radioe-private/config.php
chmod 600 1radioe-private/config.php
```

Die alte Installation behält ihre eigene `config.php` und läuft unverändert weiter. Beide nutzen dieselbe Datenbank. Die App sucht `1radioe-private/config.php` zuerst im Checkout, die neue Installation nimmt also immer ihre eigene Kopie. Passwort-Hash und TOTP-Secret stehen danach in beiden Dateien. Ändert sich eins davon, gilt das nur für die Installation, deren Datei geändert wurde.

Für das Posting zu Bluesky und Mastodon braucht die neue Config einen Schlüssel. Erzeugen:

```bash
php -r 'echo base64_encode(random_bytes(32)), PHP_EOL;'
```

Dann die Kopie öffnen:

```bash
nano 1radioe-private/config.php
```

* `base_url` auf `'https://1radioe.net'` setzen. Fehlt die Zeile, direkt unter `return [` einfügen.
* Darunter `'secret_key' => '<der Wert>',` mit dem eben erzeugten Wert einfügen. Wird der Schlüssel später geändert, müssen App-Passwort und Token im Admin neu eingegeben werden.
* `media_dir` und `media_url` dürfen nicht gesetzt sein, sonst würde die neue Installation den alten Medienordner verwenden.

Speichern mit `Ctrl+O` und Enter, beenden mit `Ctrl+X`. Dann prüfen, erwartet wird `No syntax errors detected`:

```bash
php -l 1radioe-private/config.php
```

Liegt die Domain in einem anderen All-Inkl-Account, braucht es dort eine neue Datenbank: In phpMyAdmin der alten Datenbank alle Tabellen mit `r1_` exportieren, in die neue importieren und die Zugangsdaten in `1radioe-private/config.php` eintragen. Die `config.php` kommt dann per SFTP aus dem alten Account. Den Export danach löschen, er enthält Passwort-Hash und TOTP-Secret.

## 5. Webhook

```bash
openssl rand -hex 32 > 1radioe-private/webhook-secret
chmod 600 1radioe-private/webhook-secret
cat 1radioe-private/webhook-secret
```

Auf GitHub: Repository → Settings → Webhooks → Add webhook

* Payload URL: `https://1radioe.net/deploy.php`
* Content type: `application/json`
* Secret: die ausgegebene Zeile
* SSL verification: eingeschaltet lassen
* Which events: **Just the push event**
* Active: angehakt

GitHub schickt sofort einen Ping. Unter Recent Deliveries sollte er einen grünen Haken haben, die Antwort ist `pong`. Im Log steht `INFO: ping from GitHub, webhook works`:

```bash
tail 1radioe-private/deploy.log
```

## 6. Medien kopieren

Logos, Cover und Tracks liegen bisher in `eichhof.co/1radioe-media`. Kopieren, nicht verschieben, die alte Installation braucht sie noch:

```bash
cp -an ../eichhof.co/1radioe-media/. www/media/
```

`-n` überschreibt nichts, der Befehl lässt sich gefahrlos wiederholen.

## 7. Prüfen

* `https://1radioe.net/smapi.php` zeigt `SMAPI ok` mit Version und Zahl der Einträge
* `https://1radioe.net/admin/` meldet dich mit Benutzer, Passwort und Code an
* System im Admin: aktuelle Version, Konfiguration unter `…/1radioe.net/1radioe-private/config.php`, Medien unter `…/1radioe.net/www/media ✓`, FTP-Eingang unter `…/1radioe.net/1radioe-private/inbox ✓`, Fehlende Dateien `keine ✓`
* `https://1radioe.net/` zeigt die Web-App und spielt
* `https://1radioe.net/1radioe-private/config.php` gibt es nicht (404), weil der Ordner außerhalb des Web-Verzeichnisses liegt
* `https://1radioe.net/robots.txt` sperrt alles

Ausrollen testen: einen Commit auf `main` pushen. Im Log steht dann `SUCCESS` mit Commit.

Damit die Web-App deinen Hörstatus meldet: im Admin unter System → Hörgeräte in jedem Browser, der melden soll, „Dieses Gerät merken“.

## 8. Sonos umstellen

Erst wenn Schritt 7 klappt. Im Sonos-Entwicklerportal in der Integration `co.eichhof.radioe`:

* Sonos Music API: SMAPI Endpoint `https://1radioe.net/smapi.php`
* Service Configuration: Refresh, dann Send

Integration ID, Favoriten und Wecker bleiben, die Kennungen der Sender ändern sich nicht. Logo- und Track-Adressen holt Sonos bei der nächsten Abfrage neu.

Der alte Endpunkt `eichhof.co/1radioe/smapi.php` läuft weiter, bis Sonos sicher umgestellt ist. Eine Weiterleitung ersetzt das nicht, Sonos folgt bei SOAP-Anfragen keiner Weiterleitung.

## 9. Aufräumen, später

Wenn Sonos einige Tage nur noch `1radioe.net` anfragt (Diagnose-Log im Admin):

* `eichhof.co/1radioe` löschen und `eichhof.co/1radioe-media` zuerst umbenennen, nach einer Weile löschen
* Den alten Ordner `1radioe-private` der alten Installation im Account-Root löschen. Er enthält Passwort-Hash und TOTP-Secret, die neue Installation braucht ihn nicht. Danach liegt außer `1radioe.net/` nichts mehr von 1radiœ im Account

## Wenn etwas hakt

| Meldung im Deploy-Log | Ursache und Lösung |
|---|---|
| `webhook-secret missing or empty` | Datei fehlt oder ist leer, Schritt 5 |
| `invalid or missing signature` | Secret auf GitHub und im Server unterschiedlich, oder Content type nicht `application/json` |
| `payload is not JSON` | Content type im Webhook auf `application/json` stellen |
| `exec() is disabled` | PHP darf keine Befehle ausführen. Im KAS oder beim All-Inkl-Support klären |
| `dubious ownership` | Checkout und PHP gehören verschiedenen Nutzern. Beim All-Inkl-Support klären |

Von Hand ausrollen geht jederzeit:

```bash
cd /www/htdocs/w0123456/1radioe.net && git fetch origin && git reset --hard origin/main
```

Niemals `git clean` verwenden, das kann `www/media/` und `1radioe-private/` samt Config und Secrets löschen.

## Falls das Repository wieder privat wird

Dann braucht der Server einen eigenen Schlüssel, der nur lesen darf. Er liegt mit im privaten Ordner, damit nichts lose im Account-Root liegt. Im Domain-Ordner:

```bash
ssh-keygen -t ed25519 -N "" -C "1radioe.net deploy" -f 1radioe-private/deploy_key
cat 1radioe-private/deploy_key.pub
```

Die ausgegebene Zeile auf GitHub eintragen: Repository → Settings → Deploy keys → Add deploy key. Titel `1radioe.net`, **Allow write access nicht anhaken**. Dann Git auf SSH umstellen, mit vollen Pfaden, weil `$HOME` nicht stimmt:

```bash
P=/www/htdocs/w0123456/1radioe.net/1radioe-private
git config core.sshCommand "ssh -i $P/deploy_key -o IdentitiesOnly=yes -o UserKnownHostsFile=$P/known_hosts"
git remote set-url origin git@github.com:ollrich/1radioe.git
GIT_SSH_COMMAND="ssh -i $P/deploy_key -o IdentitiesOnly=yes -o UserKnownHostsFile=$P/known_hosts -o StrictHostKeyChecking=accept-new" git fetch origin
```

Meldet das Deploy-Log danach `Permission denied (publickey)`, fehlt der Deploy-Key auf GitHub. Bei `Host key verification failed` den letzten Befehl noch einmal ausführen.
