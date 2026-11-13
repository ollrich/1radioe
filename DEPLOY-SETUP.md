# Einrichtung auf All-Inkl mit automatischem Deploy

Diese Anleitung bringt 1radiœ auf `https://1radioe.net` und richtet das automatische Ausrollen ein: Ein Push auf `main` aktualisiert den Server von selbst. Sie geht davon aus, dass die bisherige Installation unter `eichhof.co/1radioe` im selben All-Inkl-Account läuft und weiterläuft, bis Sonos umgestellt ist.

`w0123456` steht überall für deine Account-Kennung. Im Webspace sieht das am Ende so aus:

```
/www/htdocs/w0123456/
  1radioe.net/            Git-Checkout von ollrich/1radioe, alles für 1radiœ liegt hier
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

`www/media/` und `1radioe-private/` sind nicht in Git. `git reset --hard` lässt solche Dateien in Ruhe, deshalb überleben Uploads, Config und Secrets jeden Deploy. Auf dem Server also nie Dateien aus dem Repository von Hand ändern: Der nächste Deploy überschreibt sie. Von Hand bearbeitet wird nur, was in `1radioe-private/` liegt. Inhalte kommen nur über den Admin, MP3s per FTP in `1radioe-private/inbox/`.

Datenbank-Änderungen brauchen keinen eigenen Schritt. Die App erweitert die Datenbank beim nächsten Aufruf von Admin oder Sonos selbst.

## 1. Per SSH anmelden

SSH im KAS für den Account aktivieren, falls noch nicht geschehen, und mit den Zugangsdaten aus dem KAS anmelden. Alle folgenden Befehle laufen im Home-Verzeichnis `/www/htdocs/w0123456`.

```bash
cd ~
```

## 2. Deploy-Key erzeugen

Das Repository ist privat. Der Server bekommt deshalb einen eigenen Schlüssel, der nur lesen darf.

```bash
mkdir -p ~/.ssh && chmod 700 ~/.ssh
ssh-keygen -t ed25519 -N "" -C "1radioe.net deploy" -f ~/.ssh/1radioe_deploy
cat ~/.ssh/1radioe_deploy.pub
```

Die ausgegebene Zeile auf GitHub eintragen: Repository → Settings → Deploy keys → Add deploy key. Titel `1radioe.net`, Schlüssel einfügen, **Allow write access nicht anhaken**.

## 3. Repository klonen

Erst klonen, dann die Domain im KAS anlegen. Legt der KAS den Ordner vorher an, ist er nicht leer und das Klonen scheitert. Gibt es `~/1radioe.net` schon, vorher umbenennen.

```bash
cd ~
GIT_SSH_COMMAND="ssh -i ~/.ssh/1radioe_deploy -o IdentitiesOnly=yes -o StrictHostKeyChecking=accept-new" \
  git clone git@github.com:ollrich/1radioe.git 1radioe.net
cd ~/1radioe.net
git config core.sshCommand "ssh -i $HOME/.ssh/1radioe_deploy -o IdentitiesOnly=yes -o UserKnownHostsFile=$HOME/.ssh/known_hosts"
git fetch origin && git status
```

Die Zeile mit `core.sshCommand` speichert absolute Pfade im Checkout. So findet auch `deploy.php` Schlüssel und GitHub-Hostkey, obwohl PHP mit anderer Umgebung läuft.

Danach den privaten Ordner im Checkout anlegen. Die `.htaccess` darin ist nur ein Sicherheitsnetz, falls die Domain einmal versehentlich auf `1radioe.net/` statt auf `1radioe.net/www/` zeigt.

```bash
mkdir -p ~/1radioe.net/1radioe-private/inbox ~/1radioe.net/1radioe-private/tmp
echo 'Require all denied' > ~/1radioe.net/1radioe-private/.htaccess
git -C ~/1radioe.net status --short
```

`git status --short` gibt nichts aus: Der Ordner ist von Git ausgeschlossen.

## 4. Domain im KAS

* Domain `1radioe.net` hinzufügen, Zielverzeichnis `/1radioe.net/www/`
* SSL mit Let's Encrypt aktivieren und **SSL erzwingen** einschalten
* PHP 8.1 oder neuer, besser die neueste angebotene Version

## 5. Config

Die neue Installation bekommt eine Kopie der `config.php` der alten. Die alte liegt in einem Ordner `1radioe-private` oberhalb von `eichhof.co/1radioe`, so findet man ihn:

```bash
ls -d ~/1radioe-private ~/eichhof.co/1radioe-private 2>/dev/null
```

Den gefundenen Pfad im folgenden Befehl einsetzen, dann die Kopie öffnen:

```bash
cp -p ~/1radioe-private/config.php ~/1radioe.net/1radioe-private/config.php
chmod 600 ~/1radioe.net/1radioe-private/config.php
nano ~/1radioe.net/1radioe-private/config.php
```

Die alte Installation behält ihre eigene `config.php` und läuft unverändert weiter. Beide nutzen dieselbe Datenbank. Die App sucht `1radioe-private/config.php` zuerst im Checkout, die neue Installation nimmt also immer ihre eigene Kopie.

In der neuen `config.php`:

* `base_url` auf `'https://1radioe.net'` setzen
* `media_dir` und `media_url` dürfen nicht gesetzt sein, sonst würde die neue Installation den alten Medienordner verwenden

Passwort-Hash und TOTP-Secret stehen danach in beiden Dateien. Ändert sich eins davon, gilt das nur für die Installation, deren Datei geändert wurde.

Für das Posting zu Bluesky und Mastodon kommt eine Zeile dazu. Den Wert zeigt der Admin unter „Posting“ frisch erzeugt an, oder:

```bash
php -r 'echo base64_encode(random_bytes(32)), PHP_EOL;'
```

```php
'secret_key' => '<der Wert>',
```

Nur die neue `config.php` braucht die Zeile. Wird der Schlüssel später geändert, müssen App-Passwort und Token im Admin neu eingegeben werden.

Liegt die Domain in einem anderen All-Inkl-Account, braucht es dort eine neue Datenbank: In phpMyAdmin der alten Datenbank alle Tabellen mit `r1_` exportieren, in die neue importieren und die Zugangsdaten in `~/1radioe.net/1radioe-private/config.php` eintragen. Die `config.php` kommt dann per SFTP aus dem alten Account. Den Export danach löschen, er enthält Passwort-Hash und TOTP-Secret.

## 6. Webhook-Secret

```bash
openssl rand -hex 32 > ~/1radioe.net/1radioe-private/webhook-secret
chmod 600 ~/1radioe.net/1radioe-private/webhook-secret
cat ~/1radioe.net/1radioe-private/webhook-secret
```

Auf GitHub: Repository → Settings → Webhooks → Add webhook

* Payload URL: `https://1radioe.net/deploy.php`
* Content type: `application/json`
* Secret: die ausgegebene Zeile
* Which events: **Just the push event**
* Active: angehakt

GitHub schickt sofort einen Ping. Unter Recent Deliveries sollte er mit Status 200 und der Antwort `pong` stehen, im Log `INFO: ping from GitHub, webhook works`.

```bash
tail ~/1radioe.net/1radioe-private/deploy.log
```

## 7. Medien kopieren

Logos, Cover und Tracks liegen bisher in `eichhof.co/1radioe-media`. Kopieren, nicht verschieben, die alte Installation braucht sie noch:

```bash
cp -an ~/eichhof.co/1radioe-media/. ~/1radioe.net/www/media/
```

`-n` überschreibt nichts, der Befehl lässt sich gefahrlos wiederholen. Die Systemseite im Admin sollte danach unter „Fehlende Dateien“ `keine ✓` zeigen.

## 8. Prüfen

* `https://1radioe.net/smapi.php` zeigt `SMAPI ok` mit Version und Zahl der Einträge
* `https://1radioe.net/admin/` meldet dich mit Benutzer, Passwort und Code an
* System: Version 1.4.0 oder neuer, Medien unter `…/1radioe.net/www/media ✓`, FTP-Eingang unter `…/1radioe.net/1radioe-private/inbox ✓`, keine fehlenden Dateien
* `https://1radioe.net/1radioe-private/config.php` gibt es nicht (404), weil der Ordner außerhalb des Web-Verzeichnisses liegt
* `https://1radioe.net/robots.txt` sperrt alles

Ausrollen testen: einen Commit auf `main` pushen oder auf GitHub unter Recent Deliveries einen Push erneut senden. Im Log steht dann `SUCCESS` mit Commit.

## 9. Sonos umstellen

Erst wenn Schritt 8 klappt. Im Sonos-Entwicklerportal in der Integration `co.eichhof.radioe`:

* Sonos Music API: SMAPI Endpoint `https://1radioe.net/smapi.php`
* Service Configuration: Refresh, dann Send

Integration ID, Favoriten und Wecker bleiben, die Kennungen der Sender ändern sich nicht. Logo- und Track-Adressen holt Sonos bei der nächsten Abfrage neu.

Der alte Endpunkt `eichhof.co/1radioe/smapi.php` läuft weiter, bis Sonos sicher umgestellt ist. Eine Weiterleitung ersetzt das nicht, Sonos folgt bei SOAP-Anfragen keiner Weiterleitung.

## 10. Aufräumen, später

Wenn Sonos einige Tage nur noch `1radioe.net` anfragt (Diagnose-Log im Admin):

* `~/eichhof.co/1radioe` löschen und `~/eichhof.co/1radioe-media` zuerst umbenennen, nach einer Weile löschen
* Den alten Ordner `1radioe-private` der alten Installation löschen. Er enthält Passwort-Hash und TOTP-Secret, die neue Installation braucht ihn nicht. Danach liegt außer `1radioe.net/` nichts mehr von 1radiœ im Account

## Wenn etwas hakt

| Meldung im Deploy-Log | Ursache und Lösung |
|---|---|
| `webhook-secret missing or empty` | Datei fehlt oder ist leer, Schritt 6 |
| `invalid or missing signature` | Secret auf GitHub und im Server unterschiedlich, oder Content type nicht `application/json` |
| `payload is not JSON` | Content type im Webhook auf `application/json` stellen |
| `exec() is disabled` | PHP darf keine Befehle ausführen. Im KAS oder beim All-Inkl-Support klären |
| `Permission denied (publickey)` | Deploy-Key fehlt auf GitHub oder `core.sshCommand` zeigt auf einen falschen Pfad |
| `Host key verification failed` | Einmal `ssh -i ~/.ssh/1radioe_deploy -o UserKnownHostsFile=$HOME/.ssh/known_hosts -T git@github.com` ausführen und bestätigen |
| `dubious ownership` | `git config --global --add safe.directory /www/htdocs/w0123456/1radioe.net` |

Von Hand ausrollen geht jederzeit:

```bash
cd ~/1radioe.net && git fetch origin && git reset --hard origin/main
```

Niemals `git clean` verwenden, das kann `www/media/` und `1radioe-private/` samt Config und Secrets löschen.

## Wenn das Repository öffentlich wird

Dann braucht der Server keinen Schlüssel mehr:

```bash
cd ~/1radioe.net
git remote set-url origin https://github.com/ollrich/1radioe.git
git config --unset core.sshCommand
```

Danach den Deploy-Key auf GitHub löschen.
