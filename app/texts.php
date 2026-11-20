<?php
/**
 * Texts of the public dialogs "Über 1radiœ", "Impressum" and "Datenverarbeitung",
 * edited in the admin (Texte), in German and English.
 *
 * Stored as cleaned HTML in r1_settings under text.<name>.<lang>. Nothing stored means
 * the default below. English shows on 1radioe.net, German on 1radioe.net/de/. Without an
 * own English text the English page shows the own German one (real legal details beat
 * an English text with placeholders), and only then the English default.
 * Only a few tags survive text_clean(): p, h3, strong, em, a, ul, ol, li, br. A link
 * may point to http, https, mailto or a relative address on this site, external links
 * open in a new tab. Everything else is dropped or reduced to its text.
 */
declare(strict_types=1);

const TEXT_NAMES = ['about' => 'Über 1radiœ', 'impressum' => 'Impressum', 'datenverarbeitung' => 'Datenverarbeitung'];
const TEXT_LANGS = ['de' => 'Deutsch', 'en' => 'Englisch'];
const TEXT_MAX   = 30000;   // characters of cleaned HTML, far more than the dialogs need
// Ollie's own profiles elsewhere: the web app names them with rel="me" (in the page head and
// on links to them in the texts), so Mastodon can verify 1radioe.net in the profile
const ME_LINKS   = ['https://norden.social/@olli'];

/** The texts as long as nothing is stored, German and English. */
function text_defaults(): array
{
    return [
        'about' => [
            'de' => <<<'HTML'
                <p>1radiœ ist meine persönliche Auswahl an Internetradios. Dazu kommen DJ-Mixe und Konzerte, die ich auf SoundCloud und YouTube sammle, und Playlists auf Deezer. Gepflegt wird alles an einer Stelle. Die Sender laufen überall: hier im Browser, auf Sonos, als Playlist in VLC und später in einer eigenen App. Mixe und Konzerte spielen hier im Browser.</p>
                <p>Die Sender spielen direkt vom Original-Stream. Über diesen Server läuft kein Ton. Es gibt kein Tracking und keine externen Dienste, außer SoundCloud und YouTube, wenn du sie selbst lädst.</p>
                <h3>Mitnehmen</h3>
                <p>Alle Sender als Playlist für VLC und andere Player: <a href="streams.m3u">streams.m3u</a></p>
                <h3>Quellcode</h3>
                <p><a href="https://github.com/ollrich/1radioe" target="_blank" rel="noopener">github.com/ollrich/1radioe</a>, Apache-Lizenz 2.0</p>
                <p>Ein Projekt von Olli.</p>
                HTML,
            'en' => <<<'HTML'
                <p>1radiœ is my personal selection of internet radio stations. Alongside them are DJ mixes and concerts I collect on SoundCloud and YouTube, and playlists on Deezer. Everything is kept in one place. The stations play everywhere: here in the browser, on Sonos, as a playlist in VLC and later in an app of its own. Mixes and concerts play here in the browser.</p>
                <p>The stations play straight from the original stream. No sound runs through this server. There is no tracking and there are no external services, except SoundCloud and YouTube when you load them yourself.</p>
                <h3>Take it with you</h3>
                <p>All stations as a playlist for VLC and other players: <a href="streams.m3u">streams.m3u</a></p>
                <h3>Source code</h3>
                <p><a href="https://github.com/ollrich/1radioe" target="_blank" rel="noopener">github.com/ollrich/1radioe</a>, Apache License 2.0</p>
                <p>A project by Ollie.</p>
                HTML,
        ],
        'impressum' => [
            'de' => <<<'HTML'
                <p>[Name]<br>[Anschrift]<br>[Postleitzahl, Ort]</p>
                <h3>Kontakt</h3>
                <p>[E-Mail-Adresse]</p>
                <h3>Verantwortlich für den Inhalt</h3>
                <p>[Name, Anschrift wie oben]</p>
                HTML,
            'en' => <<<'HTML'
                <p>[Name]<br>[Address]<br>[Postcode, town]</p>
                <h3>Contact</h3>
                <p>[Email address]</p>
                <h3>Responsible for the content</h3>
                <p>[Name, address as above]</p>
                HTML,
        ],
        'datenverarbeitung' => [
            'de' => <<<'HTML'
                <h3>Kein Tracking</h3>
                <p>Diese Seite nutzt keine Analyse-Werkzeuge und keine Werbung und lädt von sich aus nichts von fremden Servern. Schriften, Skripte, Senderlogos und die Vorschaubilder der Videos liegen auf diesem Server. Die Ausnahmen sind die Player von SoundCloud und YouTube, siehe unten.</p>
                <h3>Beim Abspielen</h3>
                <p>Dein Browser verbindet sich direkt mit dem Server des jeweiligen Senders. Dieser erhält dabei deine IP-Adresse. Es gelten die Hinweise des Senders zur Datenverarbeitung.</p>
                <h3>SoundCloud</h3>
                <p>Einige Einträge spielen im Player von SoundCloud. Er lädt erst, wenn du auf „SoundCloud laden“ klickst, und nur für diesen Besuch. Ab dann verbindet sich dein Browser mit SoundCloud. SoundCloud erhält deine IP-Adresse, setzt Cookies und verarbeitet Daten nach seinen eigenen Hinweisen: <a href="https://soundcloud.com/pages/privacy">soundcloud.com/pages/privacy</a>. Anbieter ist die SoundCloud Global Limited &amp; Co. KG in Berlin.</p>
                <h3>YouTube</h3>
                <p>Einige Einträge sind Videos von YouTube, diese Website nutzt dafür die YouTube API Services. Die Liste der Videos holt dieser Server einmal am Tag über die YouTube Data API bei Google ab, dabei werden keine Daten über dich übermittelt. Titel, Kanal, Länge und Vorschaubild liegen auf diesem Server.</p>
                <p>Der Player von YouTube (youtube-nocookie.com) lädt erst, wenn du auf „YouTube laden“ klickst, und nur für diesen Besuch. Ab dann verbindet sich dein Browser mit Google. Google erhält deine IP-Adresse und Angaben zu deinem Browser, kann Cookies setzen und verarbeitet Daten nach eigenen Regeln, auch in den USA. Anbieter ist die Google Ireland Limited in Dublin. Datenschutzerklärung von Google: <a href="https://policies.google.com/privacy">policies.google.com/privacy</a>, Nutzungsbedingungen von YouTube: <a href="https://www.youtube.com/t/terms">youtube.com/t/terms</a></p>
                <h3>Lautstärke</h3>
                <p>Die gewählte Lautstärke liegt im lokalen Speicher deines Browsers und verlässt dein Gerät nicht.</p>
                <h3>Cookies</h3>
                <p>Besucher bekommen keine Cookies. Ein Cookie gibt es nur für Olli selbst, damit sein Hörstatus erfasst werden kann.</p>
                <h3>Hosting und Verantwortlicher</h3>
                <p>[Hoster, Server-Logfiles, Speicherdauer]<br>[Name, Kontakt]</p>
                HTML,
            'en' => <<<'HTML'
                <h3>No tracking</h3>
                <p>This site uses no analytics and no advertising and loads nothing from other servers on its own. Fonts, scripts, station logos and the video preview images are on this server. The exceptions are the players of SoundCloud and YouTube, see below.</p>
                <h3>When a station plays</h3>
                <p>Your browser connects directly to the server of the station. That server receives your IP address. The station's own notes on data processing apply.</p>
                <h3>SoundCloud</h3>
                <p>Some entries play in SoundCloud's player. It loads only when you click “Load SoundCloud”, and only for this visit. From then on your browser connects to SoundCloud. SoundCloud receives your IP address, sets cookies and processes data under its own notes: <a href="https://soundcloud.com/pages/privacy">soundcloud.com/pages/privacy</a>. The provider is SoundCloud Global Limited &amp; Co. KG in Berlin.</p>
                <h3>YouTube</h3>
                <p>Some entries are videos from YouTube; this website uses the YouTube API Services for them. This server fetches the list of videos from Google once a day through the YouTube Data API, no data about you is passed on. Title, channel, length and preview image are kept on this server.</p>
                <p>YouTube's player (youtube-nocookie.com) loads only when you click “Load YouTube”, and only for this visit. From then on your browser connects to Google. Google receives your IP address and details about your browser, may set cookies and processes data under its own rules, also in the USA. The provider is Google Ireland Limited in Dublin. Google Privacy Policy: <a href="https://policies.google.com/privacy">policies.google.com/privacy</a>, YouTube Terms of Service: <a href="https://www.youtube.com/t/terms">youtube.com/t/terms</a></p>
                <h3>Volume</h3>
                <p>The volume you choose stays in your browser's local storage and does not leave your device.</p>
                <h3>Cookies</h3>
                <p>Visitors get no cookies. Only Ollie's own browser has one, so that the listening status can be recorded.</p>
                <h3>Hosting and controller</h3>
                <p>[Host, server log files, retention period]<br>[Name, contact]</p>
                HTML,
        ],
    ];
}

function text_key(string $name, string $lang): string
{
    return "text.$name.$lang";
}

/** The stored HTML of a text, or null if there is none (the default applies). */
function text_stored(string $name, string $lang): ?string
{
    $v = setting(text_key($name, $lang));
    return $v === null || $v === '' ? null : $v;
}

/** The HTML for the page: stored, for English otherwise the stored German one, otherwise the default. */
function text_html(string $name, string $lang = 'de'): string
{
    return text_stored($name, $lang)
        ?? ($lang !== 'de' ? text_stored($name, 'de') : null)
        ?? text_clean(text_defaults()[$name][$lang] ?? text_defaults()[$name]['de'] ?? '');
}

/** Saves a text after cleaning it. Empty means back to the default. Returns the stored HTML or ''. */
function text_save(string $name, string $lang, string $html): string
{
    if (!isset(TEXT_NAMES[$name]) || !isset(TEXT_LANGS[$lang])) {
        throw new InputError(['text' => 'Unbekannter Text.']);
    }
    $clean = text_clean($html);
    if (mb_strlen($clean) > TEXT_MAX) {
        throw new InputError(['text' => 'Der Text ist zu lang.']);
    }
    if ($clean === '') {
        q('DELETE FROM r1_settings WHERE k = ?', [text_key($name, $lang)]);
    } else {
        setting_set(text_key($name, $lang), $clean);
    }
    return $clean;
}

/* ---------- Cleaning ---------- */

const TEXT_BLOCK  = ['p' => 'p', 'div' => 'p', 'h1' => 'h3', 'h2' => 'h3', 'h3' => 'h3', 'h4' => 'h3', 'h5' => 'h3', 'h6' => 'h3', 'blockquote' => 'p'];
const TEXT_INLINE = ['strong' => 'strong', 'b' => 'strong', 'em' => 'em', 'i' => 'em'];
const TEXT_DROP   = ['script', 'style', 'iframe', 'object', 'embed', 'svg', 'math', 'template', 'noscript', 'head', 'title',
                     'meta', 'link', 'form', 'input', 'button', 'textarea', 'select', 'option', 'img', 'video', 'audio', 'canvas'];

/** HTML from the editor reduced to the allowed tags, one block per line. */
function text_clean(string $html): string
{
    $html = trim($html);
    if ($html === '') {
        return '';
    }
    $doc  = new DOMDocument();
    $prev = libxml_use_internal_errors(true);
    $doc->loadHTML('<?xml encoding="utf-8"?><body><div id="r1-root">' . $html . '</div></body>', LIBXML_NONET);
    libxml_clear_errors();
    libxml_use_internal_errors($prev);
    $root = $doc->getElementById('r1-root');
    if ($root === null) {
        return '';
    }
    $blocks = [];
    $inline = '';
    $flush  = static function () use (&$blocks, &$inline): void {
        if (text_has_content($inline)) {
            $blocks[] = '<p>' . text_trim_br($inline) . '</p>';
        }
        $inline = '';
    };
    foreach ($root->childNodes as $node) {
        $tag = $node instanceof DOMElement ? strtolower($node->tagName) : '';
        if (isset(TEXT_BLOCK[$tag])) {
            $flush();
            $inner = text_trim_br(text_inline_children($node));
            if (text_has_content($inner)) {
                $blocks[] = '<' . TEXT_BLOCK[$tag] . '>' . $inner . '</' . TEXT_BLOCK[$tag] . '>';
            }
        } elseif ($tag === 'ul' || $tag === 'ol') {
            $flush();
            $list = text_list($node);
            if ($list !== '') {
                $blocks[] = $list;
            }
        } else {
            $inline .= text_inline($node);
        }
    }
    $flush();
    return implode("\n", $blocks);
}

/** A list with its items; nested lists stay inside their item. */
function text_list(DOMElement $list): string
{
    $tag   = strtolower($list->tagName);
    $items = '';
    foreach ($list->childNodes as $child) {
        if ($child instanceof DOMElement && strtolower($child->tagName) === 'li') {
            $inner = '';
            foreach ($child->childNodes as $c) {
                $ct     = $c instanceof DOMElement ? strtolower($c->tagName) : '';
                $inner .= $ct === 'ul' || $ct === 'ol' ? text_list($c) : text_inline($c);
            }
            $inner = text_trim_br($inner);
            if (text_has_content($inner)) {
                $items .= '<li>' . $inner . '</li>';
            }
        } elseif ($child instanceof DOMElement || trim($child->textContent) !== '') {
            $inner = text_trim_br(text_inline($child));   // stray content directly in the list
            if (text_has_content($inner)) {
                $items .= '<li>' . $inner . '</li>';
            }
        }
    }
    return $items === '' ? '' : "<$tag>$items</$tag>";
}

function text_inline_children(DOMNode $node): string
{
    $out = '';
    foreach ($node->childNodes as $child) {
        $out .= text_inline($child);
    }
    return $out;
}

/** One node inside a block: text, allowed inline tags, links; blocks inside become line breaks. */
function text_inline(DOMNode $node): string
{
    if ($node instanceof DOMText) {
        return htmlspecialchars($node->data, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
    }
    if (!$node instanceof DOMElement) {
        return '';   // comments and the like
    }
    $tag = strtolower($node->tagName);
    if (in_array($tag, TEXT_DROP, true)) {
        return '';
    }
    if ($tag === 'br') {
        return '<br>';
    }
    $inner = text_inline_children($node);
    if (isset(TEXT_INLINE[$tag])) {
        $t = TEXT_INLINE[$tag];
        return text_has_content($inner) ? "<$t>$inner</$t>" : $inner;
    }
    if ($tag === 'a') {
        $href = text_href((string) $node->getAttribute('href'));
        if ($href === null || !text_has_content($inner)) {
            return $inner;
        }
        $external = (bool) preg_match('~^https?://~i', $href);
        $me       = in_array(rtrim($href, '/'), ME_LINKS, true);   // own profile: rel="me"
        return '<a href="' . htmlspecialchars($href, ENT_QUOTES | ENT_HTML5, 'UTF-8') . '"'
            . ($external ? ' target="_blank" rel="' . ($me ? 'me noopener' : 'noopener') . '"' : '') . '>' . $inner . '</a>';
    }
    if (isset(TEXT_BLOCK[$tag]) || $tag === 'li') {
        return text_trim_br($inner) . '<br>';   // a block nested in a block: keep the line
    }
    return $inner;   // span, font, u and everything unknown: only the content
}

/**
 * E-mail addresses out of the HTML, for the public page: every mailto link and every
 * address in the text becomes a placeholder with the address encoded (URL-encoded,
 * base64, reversed), which assets/app.js turns back into a normal mailto link. Address
 * collectors read the page without scripts and find nothing; without JavaScript the
 * placeholder says where the address went. $html: cleaned HTML from text_clean().
 */
function text_protect_mail(string $html, string $lang = 'de'): string
{
    $hint   = $lang === 'de' ? 'E-Mail-Adresse, sichtbar mit JavaScript' : 'e-mail address, shown with JavaScript';
    $mail   = '[A-Za-z0-9._%+-]+@[A-Za-z0-9-]+(?:\.[A-Za-z0-9-]+)*\.[A-Za-z]{2,}';
    $encode = static fn(string $target): string => strrev(base64_encode(rawurlencode($target)));
    $holder = static function (string $target, ?string $label) use ($encode, $hint): string {
        return '<span class="mail" data-m="' . htmlspecialchars($encode($target), ENT_QUOTES | ENT_HTML5, 'UTF-8') . '"'
            . ($label !== null ? ' data-keep="1">' . $label : '>' . htmlspecialchars($hint, ENT_QUOTES | ENT_HTML5, 'UTF-8'))
            . '</span>';
    };
    // mailto links: the label stays if it holds no address ("Schreib mir"), else the hint
    $html = (string) preg_replace_callback('~<a href="(mailto:[^"]+)"[^>]*>(.*?)</a>~s', static function (array $m) use ($holder, $mail): string {
        $target = html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $label  = preg_match('~' . $mail . '~', strip_tags($m[2])) ? null : $m[2];
        return $holder($target, $label);
    }, $html);
    // addresses in the text, outside links (a placeholder inside a link would nest links)
    $out   = '';
    $inA   = 0;
    foreach (preg_split('~(<[^>]+>)~', $html, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY) ?: [] as $part) {
        if ($part[0] === '<') {
            $inA += preg_match('~^<a[\s>]~i', $part) ? 1 : (preg_match('~^</a>~i', $part) ? -1 : 0);
            $out .= $part;
        } elseif ($inA > 0) {
            $out .= $part;
        } else {
            $out .= (string) preg_replace_callback('~' . $mail . '~', static fn(array $m): string => $holder('mailto:' . html_entity_decode($m[0], ENT_QUOTES | ENT_HTML5, 'UTF-8'), null), $part);
        }
    }
    return $out;
}

/** A link target that may stay: http, https, mailto or relative on this site. */
function text_href(string $href): ?string
{
    $href = trim($href);
    // no spaces or control characters inside: browsers drop tabs and line breaks, so
    // "java<tab>script:" would turn into a script link; no backslash: browsers read
    // "/\evil.com" as "//evil.com", another site
    if ($href === '' || mb_strlen($href) > 1000 || preg_match('~[\x00-\x20\x7f\\\\]~', $href)) {
        return null;
    }
    if (preg_match('~^(https?://[^/?#\s]+|mailto:[^\s]+)~i', $href)) {
        return $href;
    }
    // relative: no scheme and not protocol-relative (//host)
    if (!str_starts_with($href, '//') && !preg_match('~^[a-z][a-z0-9+.-]*:~i', $href)) {
        return $href;
    }
    return null;
}

function text_has_content(string $html): bool
{
    return trim(html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8'), " \t\n\r\0\x0B\u{a0}") !== '';
}

function text_trim_br(string $html): string
{
    return (string) preg_replace(['~^(\s|<br>)+~', '~(\s|<br>)+$~'], '', $html);
}
