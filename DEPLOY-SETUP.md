# Einrichtung mit automatischem Ausrollen

Diese Anleitung bringt 1radiœ auf einen Webserver und richtet das automatische Ausrollen ein: Ein Push auf `main` aktualisiert den Server von selbst. Gebraucht werden PHP-Hosting mit SSH, Git und MySQL oder MariaDB, eine eigene Domain mit HTTPS und ein eigenes Repository auf GitHub (Fork oder Kopie von 1radiœ).

Platzhalter in dieser Anleitung:

* `<domain>`: die Domain der Installation, etwa `radio.example.org`
* `<ordner>`: der Domain-Ordner auf dem Server, zugleich der Git-Checkout
* `<konto>/<repo>`: das eigene Repository auf GitHub

Auf dem Server sieht das am Ende so aus:

```
<ordner>/                 Git-Checkout, alles für 1radiœ liegt hier
  1radioe-private/        Config, Webhook-Secret, Deploy-Log, FTP-Eingang. Nie in Git.
  app/                    Programmcode, nicht per Web erreichbar
  www/                    Web-Verzeichnis (Document Root) der Domain
    media/                Logos, Cover, Tracks. Nicht in Git.
```

`1radioe-private/` liegt neben `www/` und damit außerhalb des Web-Verzeichnisses. Git schließt den Ordner aus (`.gitignore`), deshalb landet er nie im Repository und das Ausrollen lässt ihn unberührt.

## Wie das Ausrollen funktioniert

1. Ein Push auf `main` schickt einen Webhook von GitHub an `https://<domain>/deploy.php`.
2. `deploy.php` prüft die Signatur mit dem gemeinsamen Secret. Ohne Secret wird nichts ausgerollt.
3. Nur Pushes auf `main` zählen. Dann laufen `git fetch` und `git reset --hard origin/main`.
4. Jeder Lauf steht in `1radioe-private/deploy.log`, die letzten 100 Einträge.

`www/media/` und `1radioe-private/` sind nicht in Git. `git reset --hard` lässt solche Dateien in Ruhe, deshalb überleben Uploads, Config und Secrets jeden Deploy. Auf dem Server also nie Dateien aus dem Repository von Hand ändern: Der nächste Deploy überschreibt sie. Von Hand bearbeitet wird nur, was in `1radioe-private/` liegt. Inhalte kommen über die Verwaltung, MP3s auch per FTP in `1radioe-private/inbox/`.

Datenbank-Änderungen brauchen keinen eigenen Schritt. Die App legt Tabellen und Spalten beim nächsten Aufruf selbst an.

## 1. Domain und Datenbank

* Domain anlegen, das Zielverzeichnis (Document Root) auf `<ordner>/www/` setzen. Es muss auf `www` enden, sonst wäre der Programmcode übers Web erreichbar.
* HTTPS aktivieren (etwa mit Let's Encrypt) und HTTP auf HTTPS umleiten.
* PHP 8.1 oder neuer, besser die neueste angebotene Version, mit `pdo_mysql`, `curl`, `gd`, `dom`, `mbstring` und `sodium`.
* Eine leere Datenbank mit eigenem Benutzer anlegen.

## 2. Code holen

Per SSH anmelden und in den Domain-Ordner wechseln, alle folgenden Befehle laufen dort:

```bash
cd <ordner>
```

Legt der Hoster den Ordner selbst an, ist er vielleicht nicht leer. Deshalb wird Git direkt darin eingerichtet, statt zu klonen. Erst nachsehen, was dort liegt; eine `index.html` des Hosters in `www/` löschen, sie würde statt der Web-App ausgeliefert.

```bash
ls -A . www
git init
git remote add origin https://github.com/<konto>/<repo>.git
git fetch origin
git checkout -f -B main origin/main
git status
```

Am Ende muss `Your branch is up to date with 'origin/main'` stehen. Für ein privates Repository siehe unten.

Dann den privaten Ordner anlegen. Die `.htaccess` darin ist nur ein Sicherheitsnetz, falls die Domain einmal versehentlich auf `<ordner>/` statt auf `<ordner>/www/` zeigt.

```bash
mkdir -p 1radioe-private/inbox 1radioe-private/tmp
echo 'Require all denied' > 1radioe-private/.htaccess
git status --short
```

`git status --short` gibt nichts aus: Der Ordner ist von Git ausgeschlossen.

## 3. Config

```bash
cp config.sample.php 1radioe-private/config.php
chmod 600 1radioe-private/config.php
php -r 'echo base64_encode(random_bytes(32)), PHP_EOL;'
```

In `1radioe-private/config.php` eintragen:

* `db`: Host, Datenbank, Benutzer und Passwort aus Schritt 1
* `base_url`: `'https://<domain>'`
* `secret_key`: der eben ausgegebene Wert. Damit werden die Zugänge fürs Posting und der YouTube-Schlüssel verschlüsselt; wird er später geändert, müssen sie in der Verwaltung neu eingegeben werden.
* `timezone`, und `integration_id`, sobald die Integration im Sonos-Portal angelegt ist (Schritt 7)

`password_hash` und `totp_secret` bleiben leer, die liefert der Assistent in Schritt 5. Danach prüfen, erwartet wird `No syntax errors detected`:

```bash
php -l 1radioe-private/config.php
```

## 4. Webhook

```bash
openssl rand -hex 32 > 1radioe-private/webhook-secret
chmod 600 1radioe-private/webhook-secret
cat 1radioe-private/webhook-secret
```

Auf GitHub: Repository → Settings → Webhooks → Add webhook

* Payload URL: `https://<domain>/deploy.php`
* Content type: `application/json`
* Secret: die ausgegebene Zeile
* SSL verification: eingeschaltet lassen
* Which events: **Just the push event**
* Active: angehakt

GitHub schickt sofort einen Ping. Unter Recent Deliveries sollte er einen grünen Haken haben, die Antwort ist `pong`. Im Log steht `INFO: ping from GitHub, webhook works`:

```bash
tail 1radioe-private/deploy.log
```

## 5. Verwaltung einrichten

`https://<domain>/admin/` öffnen. Der Assistent fragt ein Passwort ab (mindestens 12 Zeichen), zeigt einen QR-Code für die Authenticator-App und danach einen Block mit `password_hash` und `totp_secret` für die `config.php`. Eintragen, speichern, mit Benutzer, Passwort und Code anmelden.

## 6. Prüfen

* `https://<domain>/smapi.php` zeigt `SMAPI ok` mit Version und Zahl der Einträge
* System in der Verwaltung: aktuelle Version, Konfiguration unter `…/1radioe-private/config.php`, Medien unter `…/www/media ✓`, FTP-Eingang unter `…/1radioe-private/inbox ✓`, alle Voraussetzungen grün
* `https://<domain>/` zeigt die Web-App
* `https://<domain>/1radioe-private/config.php` gibt es nicht (404), weil der Ordner außerhalb des Web-Verzeichnisses liegt
* `https://<domain>/robots.txt` erlaubt die Web-App und sperrt Verwaltung, Schnittstellen, Playlist-Dateien und Tracks

Ausrollen testen: einen Commit auf `main` pushen. Im Log steht dann `SUCCESS` mit Commit.

Soll ein Browser den Hörstatus melden: in der Verwaltung unter System → Hörgeräte in diesem Browser „Dieses Gerät merken“.

## 7. Sonos

Im Sonos-Entwicklerportal eine Integration anlegen und ihre ID als `integration_id` in die Config eintragen. Die Systemseite der Verwaltung zeigt alle Werte mit Kopier-Knopf.

* Sonos Music API, Endpoints: SMAPI Endpoint `https://<domain>/smapi.php`
* Sonos Music API, Endpoints: Reporting Endpoint `https://<domain>/report/v2.3`, Reporting Endpoint Version `2.3`. Darüber melden die Lautsprecher Start und Ende der Wiedergabe für den Hörstatus.
* Service Configuration: nach jeder Änderung Refresh, dann Send

Sonos folgt bei SOAP-Anfragen keiner Weiterleitung. Zieht eine Installation auf eine andere Adresse um, bleibt an der alten Adresse eine `smapi.php`, die den neuen Code nur einbindet (`<?php require '<neuer-ordner>/www/smapi.php';`), bis keine Lautsprecher sie mehr aufrufen. Wann Sonos welche Adresse zuletzt aufgerufen hat, zeigt die Systemseite unter „Sonos-Adressen“.

## Wenn etwas hakt

| Meldung im Deploy-Log | Ursache und Lösung |
|---|---|
| `webhook-secret missing or empty` | Datei fehlt oder ist leer, Schritt 4 |
| `invalid or missing signature` | Secret auf GitHub und auf dem Server unterschiedlich, oder Content type nicht `application/json` |
| `payload is not JSON` | Content type im Webhook auf `application/json` stellen |
| `exec() is disabled` | PHP darf keine Befehle ausführen, beim Hoster freischalten lassen |
| `dubious ownership` | Checkout und PHP gehören verschiedenen Nutzern, beim Hoster klären |

Von Hand ausrollen geht jederzeit:

```bash
cd <ordner> && git fetch origin && git reset --hard origin/main
```

Niemals `git clean` verwenden, das kann `www/media/` und `1radioe-private/` samt Config und Secrets löschen.

**Hinweis für Shared Hosting:** Bei manchen Hostern ist `$HOME` in der SSH-Sitzung nicht das eigene Verzeichnis (bei All-Inkl etwa `/`). Die Befehle hier verwenden deshalb kein `~`, sondern Pfade relativ zum Domain-Ordner.

## Privates Repository

Dann braucht der Server einen eigenen Schlüssel, der nur lesen darf. Er liegt im privaten Ordner, damit nichts lose im Account liegt. Im Domain-Ordner:

```bash
ssh-keygen -t ed25519 -N "" -C "<domain> deploy" -f 1radioe-private/deploy_key
cat 1radioe-private/deploy_key.pub
```

Die ausgegebene Zeile auf GitHub eintragen: Repository → Settings → Deploy keys → Add deploy key, **Allow write access nicht anhaken**. Dann Git auf SSH umstellen, mit vollen Pfaden:

```bash
P=<ordner>/1radioe-private
git config core.sshCommand "ssh -i $P/deploy_key -o IdentitiesOnly=yes -o UserKnownHostsFile=$P/known_hosts"
git remote set-url origin git@github.com:<konto>/<repo>.git
GIT_SSH_COMMAND="ssh -i $P/deploy_key -o IdentitiesOnly=yes -o UserKnownHostsFile=$P/known_hosts -o StrictHostKeyChecking=accept-new" git fetch origin
```

Meldet das Deploy-Log danach `Permission denied (publickey)`, fehlt der Deploy-Key auf GitHub. Bei `Host key verification failed` den letzten Befehl noch einmal ausführen.
