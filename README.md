<img width="1672" height="941" style="max-width: 100%; height: auto;" alt="ViteRex Addon" src="https://github.com/user-attachments/assets/371153d5-31e9-4670-8dbe-30034e0d9dcb" />

# ViteRex für REDAXO 5

**Modernes Vite-Frontend in jeder REDAXO-Installation — ohne Kompromisse.**

ViteRex bringt [Vite](https://vite.dev/) in REDAXO. Egal ob klassische Ordnerstruktur, moderne ydeploy-Struktur oder Theme-Addon: ViteRex passt sich an, ohne dass du an deiner Projektstruktur schrauben musst. Hot-Module-Replacement, Live-Reload bei Backend-Inhaltsänderungen, [Tailwind 4](https://tailwindcss.com/), [Lightning CSS](https://lightningcss.dev/), HTTPS-Dev-Server, ein PHP-Asset-Helper und ein Backend-Badge mit Stage-Anzeige — alles vorkonfiguriert, alles über das REDAXO-Backend einstellbar.

Konzeptionell inspiriert vom [laravel-vite-plugin](https://github.com/laravel/vite-plugin): Du registrierst ViteRex einmal, schreibst `REX_VITE` in dein Template, und das Addon kümmert sich um Dev-Server-Erkennung, Manifest-Auflösung, Preload-Tags und HMR.

[Viterex-short.webm](https://github.com/user-attachments/assets/bcf2b29a-e61c-4511-aef8-09b552d2c7c1)

---

## Schnellstart

```bash
# 1. Im Backend installieren: AddOns → Installer → "viterex_addon" → installieren & aktivieren
# 2. Backend → AddOns → ViteRex → Einstellungen öffnen, Pfade prüfen, speichern
# 3. Auf "Stubs installieren" klicken → package.json, vite.config.js etc. landen im Projekt-Root
# 4. Im Terminal:
npm install
npm run dev
```

Fertig. Der Vite-Dev-Server läuft, deine REDAXO-Seite zeigt HMR.

---

## Was kann ViteRex?

ViteRex deckt den kompletten Frontend-Workflow für REDAXO ab — von der ersten `npm install` bis zum Production-Build. Kurzüberblick:

- **Vite-Dev-Server-Integration** mit HMR und Live-Reload, plus Production-Builds mit gehashten Assets und `manifest.json`.
- **`REX_VITE`-Platzhalter** in beliebigen Templates, Modulen oder Slices — automatisch eingesetzt vor `</head>`, falls du ihn vergisst.
- **Drei Ordnerstrukturen unterstützt**: classic, modern (ydeploy), Theme-Addon. Eingestellt wird im Backend, keine Auto-Detection-Magie zur Laufzeit.
- **PHP-Helpers** für statische Assets in Templates: `Assets::url()`, `Assets::path()`, `Assets::inline()`.
- **Backend-Konfiguration** mit allen Pfaden, Live-Reload-Globs und HTTPS-Toggle.
- **„Stubs installieren"-Knopf** kopiert `package.json`, `vite.config.js` und Dev-Tooling-Configs in dein Projekt — mit Backup-Funktion und automatischem `.gitignore`-Merge.
- **Tailwind 4 + Lightning CSS** vorinstalliert in den Stubs, plus `@tailwindcss/forms`, `@tailwindcss/typography`, `tailwind-clamp`.
- **Live-Reload bei Content-Änderungen im Backend**: ~30 REDAXO-Extension-Points (Artikel, Kategorien, Slices, Module, Templates, Medien, Sprachen, optional yform-Daten) lösen einen Browser-Reload aus.
- **block_peek-Integration**: Der `REX_VITE`-Platzhalter funktioniert auch in den Block-Vorschau-iframes von [`block_peek`](https://github.com/FriendsOfREDAXO/block_peek).
- **HTTPS-Dev-Server** via [mkcert](https://github.com/FiloSottile/mkcert) — ein Befehl, fertig.
- **ViteRex-Badge** im Frontend & Backend (nur für eingeloggte Admins, nur in Dev/Staging): zeigt Stage, Vite-Status, Git-Branch, Cache-Clear-Button, REDAXO- und ViteRex-Version.
- **Automatische SVG-Optimierung**: SVGO im Dev (mutiert Source-SVGs 1:1 in-place), `mathiasreker/php-svg-optimizer` für Mediapool-Uploads in Staging/Prod. Strippt `<script>` und `on*`-Handler — schliesst eine standardmässig vorhandene XSS-Lücke beim SVG-Upload.
- **noindex-Meta-Tag** auf Dev/Staging — wenn `ydeploy` installiert ist.
- **Debug-Modus** wird automatisch passend zur Stage gesetzt.
- **Erweiterbar** durch Downstream-Addons über ein Plugin-Wrapping-Modell, drei PHP-Extension-Points (`VITEREX_BADGE`, `VITEREX_PRELOAD`, `VITEREX_INSTALL_STUBS`) und eine öffentliche `StubsInstaller`-API für eigenes Scaffolding.
- **Idempotente Installation**: Bei erstmaliger Installation werden Defaults gesetzt; Re-Installs überschreiben deine Einstellungen nicht.

---

## Voraussetzungen

- **REDAXO** `>= 5.13`
- **PHP** `>= 8.3` (war `>= 8.1` bis v3.2.x — siehe CHANGELOG)
- **Node.js** `>= 18`, **npm** `>= 9`

Empfohlene REDAXO-Addons (optional, aber sinnvoll):

- `yrewrite` für Domain-/URL-Handling
- `ydeploy` für Stage-Erkennung (dev/staging/prod) und automatisches noindex
- `block_peek` für HMR in Block-Vorschauen
- `developer` für Templates/Module im Filesystem statt Datenbank

---

## Installation

### Über den REDAXO-Installer (empfohlen)

Backend → _AddOns → Installer_, `viterex_addon` suchen, herunterladen, aktivieren.

### Manuell von GitHub

Repository nach `/src/addons/viterex_addon/` (modern) bzw. `redaxo/src/addons/viterex_addon/` (classic / Theme-Addon) entpacken, im Backend installieren und aktivieren.

### Was beim Installieren passiert

**Nichts im Projekt-Root.** Das Addon registriert sich nur und seedet `var/data/addons/viterex_addon/structure.json` (modern) bzw. `redaxo/data/addons/viterex_addon/structure.json` (classic/theme) mit Default-Pfaden. Konfiguration und Bereitstellung der Projekt-Dateien läuft über das Backend (siehe nächster Abschnitt).

---

## Erste Schritte

### 1. Einstellungen prüfen

Backend → _AddOns → ViteRex → Einstellungen_ öffnen. Defaults sind für die **moderne Ordnerstruktur** ausgelegt:

| Struktur             | Öffentliches Verzeichnis | Build-Output        |
| -------------------- | ------------------------ | ------------------- |
| **modern** (ydeploy) | `public`                 | `public/dist`       |
| **classic**          | _(leer lassen)_          | `dist`              |
| **Theme-Addon**      | `theme/public`           | `theme/public/dist` |

Speichern → die Einstellungen werden in `rex_config` gespeichert und gespiegelt nach `structure.json`, das vom Vite-Plugin auf der Node-Seite gelesen wird.

### 2. Stubs installieren

Auf den Knopf **„Stubs installieren"** klicken. Das Häkchen _„Existierende Dateien überschreiben"_ steuert das Verhalten bei vorhandenen Dateien:

- **Ohne Häkchen**: existierende Dateien bleiben, werden in der Übersicht als „übersprungen" gemeldet.
- **Mit Häkchen**: existierende Dateien werden mit Zeitstempel gesichert (`<datei>.bak.YYYYmmdd-HHiiss`), bevor sie überschrieben werden — **nichts wird stillschweigend zerstört**.

Bereitgestellt werden folgende Dateien im Projekt-Root:

- `package.json` (Vite 8, Tailwind 4, Plugins, Dev-Tooling)
- `vite.config.js` (minimal, Laravel-Style — der Import-Pfad zu `viterex-vite-plugin.js` wird aus deiner _Öffentliches Verzeichnis_-Einstellung generiert)
- `.env.example`, `.browserslistrc`, `.prettierrc`, `biome.jsonc`, `stylelint.config.js`, `jsconfig.json`
- `<assets_source_dir>/js/main.js` und `<assets_source_dir>/css/style.css` als Default-Einstiegspunkte

Außerdem wird die `.gitignore` Zeile-für-Zeile gescannt — fehlende ViteRex-Einträge (`.vite-hot`, `.vite-reload-trigger`, mkcert-Zertifikate) werden unter einer `# Added by viterex`-Markierung ergänzt.

### 3. Loslegen

```bash
npm install
npm run dev
```

`.vite-hot` taucht im Projekt-Root auf, der Browser zeigt deine Seite mit HMR.

---

## Der `REX_VITE`-Platzhalter

In beliebigen REDAXO-Templates den `REX_VITE`-Platzhalter im `<head>` ergänzen:

```php
<!doctype html>
<html>
<head>
    <meta charset="utf-8">
    REX_VITE
</head>
<body>
    ...
</body>
</html>
```

**Wichtig:** Der `REX_VITE`-Platzhalter wird **nur innerhalb von `<head>` und nur beim ersten Vorkommen** ersetzt. Jedes weitere `REX_VITE` — im Body, in Code-Beispielen auf Doku-Seiten, in Slice-Inhalten — bleibt unverändert als Literal-Text stehen. Wird im `<head>` gar kein `REX_VITE` gefunden, fügt der Output-Filter den Asset-Block automatisch vor dem ersten `</head>` ein. Du kannst den Platzhalter also auch ganz weglassen — bequem, aber explizit ist besser, wenn du Kontrolle über die Position willst.

### Formen

| Form                                                              | Verhalten                                                |
| ----------------------------------------------------------------- | -------------------------------------------------------- |
| `REX_VITE`                                                        | Default-Einstiegspunkte (CSS + JS) aus den Einstellungen |
| `REX_VITE[src="src/assets/js/main.js"]`                           | Ein einzelner expliziter Einstiegspunkt                  |
| `REX_VITE[src="src/assets/css/style.css\|src/assets/js/main.js"]` | Mehrere Einstiegspunkte, pipe-separiert                  |

### Reihenfolge der ausgegebenen Tags

Pro Vorkommen wird in dieser Reihenfolge geschrieben:

1. `<link rel="modulepreload">` und `<link rel="preload">` für Imports / CSS / Fonts / Assets aus dem Manifest (vollständige Walk-Funktion mit Zyklen-Erkennung).
2. `<link rel="stylesheet">` für jeden CSS-Eintrag — **auch im Dev** (separater Vite-Eintrag).
3. `<script type="module" src="<devUrl>/@vite/client">` (nur Dev, einmal pro Vorkommen mit JS-Einträgen).
4. `<script type="module" src="...">` pro JS-Eintrag.

---

## PHP-Helpers für statische Assets

Für Dateien, die du aus PHP-Templates referenzierst (Background-Images, Logos, inline SVG):

```php
use Ynamite\ViteRex\Assets;

// URL eines Bildes (Dev: Vite-Dev-Server-URL, Prod: gehashter Build-Pfad)
<img src="<?= Assets::url('img/logo-640w.webp') ?>">

// Background-Image im Style-Attribut:
<div style="background-image:url(<?= Assets::url('img/hero.jpg') ?>)">…</div>

// Absoluter Filesystem-Pfad (z.B. für getimagesize()):
$svgPath = Assets::path('img/logo.svg');

// Inline-SVG / JSON / Text:
<?= Assets::inline('img/icon-arrow.svg') ?>
```

Assets, die per JS oder CSS importiert werden (`import "../img/foo.png?url"` oder `background: url("../img/foo.png")`), werden von Vite automatisch verarbeitet (gehasht) und ins Manifest eingetragen — keine zusätzliche Konfiguration nötig.

---

## Einstellungs-Felder

| Feld                             | Default                    | Zweck                                                                                                                |
| -------------------------------- | -------------------------- | -------------------------------------------------------------------------------------------------------------------- |
| **JS-Einstiegspunkt**            | `src/assets/js/main.js`    | Vite JS-Entry-Pfad                                                                                                   |
| **CSS-Einstiegspunkt**           | `src/assets/css/style.css` | Vite CSS-Entry-Pfad                                                                                                  |
| **Öffentliches Verzeichnis**     | `public`                   | Vom Webserver ausgeliefertes Verzeichnis. Modern: `public`. Classic: leer. Theme: `theme/public`.                    |
| **Build-Output-Verzeichnis**     | `public/dist`              | Vite `outDir`                                                                                                        |
| **Build-URL-Prefix**             | `/dist`                    | URL-Prefix für gebaute Assets                                                                                        |
| **Assets-Source-Verzeichnis**    | `src/assets`               | Wo deine Source-Assets liegen                                                                                        |
| **Assets-Unter-Verzeichnis**     | `assets`                   | Vite `build.assetsDir`                                                                                               |
| **Statische Copy-Verzeichnisse** | `img`                      | Komma-separierte Liste — kopiert beim Build von `<assets_source_dir>/<dir>/` nach `<out_dir>/<assets_sub_dir>/<dir>` |
| **HTTPS-Dev-Server aktivieren**  | aus                        | Aktiviert HTTPS, wenn mkcert-Zertifikate im Projekt-Root liegen                                                      |
| **Live-Reload-Globs**            | siehe unten                | Ein Glob pro Zeile — Vite triggert ein Full-Reload bei Änderung passender Dateien                                    |

---

## Live-Reload

Der Browser-Reload wird über [`vite-plugin-live-reload`](https://github.com/arnoson/vite-plugin-live-reload) ausgelöst. Default-Globs:

```
src/modules/**/*.php
src/templates/**/*.php
src/addons/project/fragments/**/*.php
src/addons/project/lib/**/*.php
src/assets/**/(*.svg|*.png|*.jpg|*.jpeg|*.webp|*.avif|*.gif|*.woff|*.woff2)
.vite-reload-trigger
```

Die ersten fünf Globs decken **direkte Datei-Änderungen** ab — du speicherst eine PHP-Datei in deiner IDE → Reload.

`.vite-reload-trigger` ist ein **Signal-File** für **Backend-getriebene Content-Änderungen**. `boot.php` registriert Handler auf ~30 REDAXO-Extension-Points:

- **Artikel**: `ART_ADDED`, `ART_UPDATED`, `ART_DELETED`, `ART_MOVED`, `ART_COPIED`, `ART_STATUS`
- **Kategorien**: `CAT_ADDED`, `CAT_UPDATED`, `CAT_DELETED`, `CAT_MOVED`, `CAT_STATUS`
- **Slices**: `SLICE_ADDED`, `SLICE_UPDATED`, `SLICE_DELETED`, `SLICE_MOVE`
- **Medien**: `MEDIA_ADDED`, `MEDIA_UPDATED`, `MEDIA_DELETED`
- **Sprachen**: `CLANG_ADDED`, `CLANG_UPDATED`, `CLANG_DELETED`
- **Templates**: `TEMPLATE_ADDED`, `TEMPLATE_UPDATED`, `TEMPLATE_DELETED`
- **Module**: `MODULE_ADDED`, `MODULE_UPDATED`, `MODULE_DELETED`
- **yform-Daten** (falls `yform` installiert): `YFORM_DATA_ADDED`, `YFORM_DATA_UPDATED`, `YFORM_DATA_DELETED`

Wird einer dieser EPs ausgelöst, `touch()`t der Handler die Datei `.vite-reload-trigger`. Vite erkennt die Änderung → Browser-Reload.

> **Warum nicht direkt `var/cache/addons/...` watchen?** Diese Verzeichnisse werden auch bei normaler Frontend-Navigation durch Lazy-Cache-Regeneration neu geschrieben — das würde laufend falsche Reloads triggern. Das Signal-File wird nur bei _echten_ Backend-Saves angefasst.

---

## HTTPS-Dev-Server

```bash
npm run setup-https
```

Dieser Befehl ruft mkcert auf und erzeugt lokale Zertifikate (`localhost+2-key.pem`, `localhost+2.pem`) im Projekt-Root. Anschließend in den Einstellungen _„HTTPS-Dev-Server aktivieren"_ anhaken — beim nächsten `npm run dev` läuft Vite über HTTPS, das `.vite-hot`-File enthält `https://...`.

mkcert installiert beim ersten Start eine lokale Root-CA in deinen System-Trust-Store (eine einmalige Sicherheitsabfrage). Auf macOS via Homebrew bzw. in Chrome / Firefox automatisch erkannt.

---

## Vite-Konfiguration erweitern (für Addon-Entwickler)

`viterex-vite-plugin.js` ist im Stil des laravel-vite-plugins gebaut: ein Aufruf, alle Defaults werden injiziert, Override via Vites `mergeConfig`.

### Pro Projekt — direkt in deiner `vite.config.js`

```js
import { defineConfig } from 'vite'
import tailwindcss from '@tailwindcss/vite'
import viterex, {
  fixTailwindFullReload
} from './public/assets/addons/viterex_addon/viterex-vite-plugin.js'

export default defineConfig({
  plugins: [
    tailwindcss(),
    fixTailwindFullReload(),
    viterex({
      input: ['src/admin/main.js'], // zusätzliche Einstiegspunkte
      refresh: ['src/templates/**/*.php'] // engere Live-Reload-Globs
    })
  ],
  build: { sourcemap: true } // alles hier überschreibt viterex-Defaults
})
```

### Plugin-Optionen

| Option         | Default                                    | Wirkung                                                                                                      |
| -------------- | ------------------------------------------ | ------------------------------------------------------------------------------------------------------------ |
| `input`        | `[css_entry, js_entry]` aus structure.json | Liste der Vite-Einstiegspunkte                                                                               |
| `refresh`      | `true`                                     | `true` = Globs aus structure.json, `false` = Live-Reload aus, `Array` = explizite Globs                      |
| `detectTls`    | `true`                                     | mkcert-Zertifikate auto-erkennen, wenn `https_enabled` aktiv                                                 |
| `injectConfig` | `true`                                     | Build-/Server-/CSS-/Resolve-Config injizieren. **Escape-Hatch:** `false` lässt nur das Hot-File-Plugin aktiv |

### Downstream-Addons (z.B. `redaxo-massif`)

Eigenes Helper-File unter `<addon>/assets/<name>-vite-plugin.js`, das `viterex()` umwickelt:

```js
// redaxo-massif/assets/massif-vite-plugin.js
import vue from '@vitejs/plugin-vue'
import viterex from '../viterex/viterex-vite-plugin.js'

export default function massif(userOptions = {}) {
  return [
    ...viterex({ refresh: false, ...userOptions }), // viterex zuerst
    vue()
  ]
}
```

Nutzer wechseln dann in ihrer `vite.config.js` einfach den Import + Plugin-Aufruf von `viterex` auf `massif`.

---

## Programmatische API für Downstream-Addons

Andere REDAXO-Addons können auf zwei Wegen ihre eigenen Dateien zusätzlich zu ViteRex' Stubs ins Projekt-Root scaffolden — beide nutzen ViteRex' bewährte Path-Baking + Backup-on-Overwrite + struktur-bewusste Zielauflösung.

### Direktaufruf — aus eigener `install.php` oder einem Settings-Handler

```php
use Ynamite\ViteRex\StubsInstaller;

$result = StubsInstaller::installFromDir(
    __DIR__ . '/frontend',                  // Source-Verzeichnis des Downstream-Addons
    [                                        // Map: Source-relativ → Ziel-relativ-zum-Root
        'templates/Header/template.php' => '/src/templates/Header/template.php',
        'modules/Swiper/input.php'      => '/src/modules/Swiper/input.php',
        'assets/img/logo.svg'           => '/src/assets/img/logo.svg',
    ],
    overwrite: false,                        // true → Backup-on-overwrite (.bak.<ts>)
    packageDeps: [                           // Optional: npm-Dependency-Merge
        'devDependencies' => ['swiper' => '^12.1.2', 'gsap' => '^3.14.2'],
    ],
);
// Liefert: ['written' => [...], 'skipped' => [...], 'backedUp' => [...], 'packageDepsMerged' => 2]

// Optional: Vite-Live-Reload-Globs erweitern (idempotent)
StubsInstaller::appendRefreshGlobs([
    'src/addons/myaddon/fragments/**/*.php',
    'src/addons/myaddon/lib/**/*.php',
]);
```

### Extension-Point-Hook — registriert sich auf ViteRex' „Stubs installieren"-Flow

```php
rex_extension::register('VITEREX_INSTALL_STUBS', static function (rex_extension_point $ep) {
    $overwrite = $ep->getParam('overwrite', false);
    $myResult = Ynamite\ViteRex\StubsInstaller::installFromDir(
        __DIR__ . '/frontend', $myStubsMap, $overwrite,
    );
    // Eigenes Resultat in subject mergen, damit ViteRex' Settings-Page beide auflistet
    $subject = $ep->getSubject();
    foreach (['written', 'skipped', 'backedUp'] as $k) {
        $subject[$k] = array_merge($subject[$k] ?? [], $myResult[$k] ?? []);
    }
    return $subject;
});
```

Beide Wege sind komplementär: der Direktaufruf passt für Auto-Install in `install.php`; der Hook fängt explizite Re-Installs aus ViteRex' eigener UI ab. Ein Addon kann beide nutzen — Direktaufruf für die initiale Auto-Install, Hook für nachträgliche Re-Scaffolds.

**Hinweis zur Idempotenz**: Das Downstream-Addon trackt selbst, ob es schon scaffolded hat (z.B. via `rex_config('myaddon', 'scaffolded_at')`). `installFromDir()` selbst ist nicht idempotent — sie kopiert immer.

---

## Erweiterungs-Punkte (PHP)

| Name                    | Subject                                                 | Verwendung                                                                           |
| ----------------------- | ------------------------------------------------------- | ------------------------------------------------------------------------------------ |
| `VITEREX_BADGE`         | `array` von HTML-Strings                                | Zusätzliche Panels im ViteRex-Badge rendern (z.B. Tailwind-Breakpoint-Indikator).    |
| `VITEREX_PRELOAD`       | `array` von `<link>`-Strings                            | Custom Preload-Links einfügen (z.B. Webfonts im Dev). Parameter: `entries`, `dev`.   |
| `VITEREX_INSTALL_STUBS` | `array` `{written, skipped, backedUp, gitignoreAction}` | Eigene Dateien parallel zu ViteRex' Stubs scaffolden. Parameter: `overwrite` (bool). |

Beispiel — eigenes Badge-Panel:

```php
rex_extension::register('VITEREX_BADGE', function (rex_extension_point $ep) {
    $panels = $ep->getSubject();
    $panels[] = '<div>Custom Panel</div>';
    return $panels;
});
```

---

## ViteRex-Badge

Bei aktiver Backend-Session und Nicht-Prod-/Nicht-Staging-Umgebung wird ein Badge unten am Fenster eingeblendet (Frontend + Backend). Es zeigt:

- **Stage**: `dev` / `staging` / `prod` mit Farb-Codierung
- **Git-Branch**: aus `.git/HEAD` gelesen, mit Alert-Styling für Branches != `main` / `master`
- **Vite-Status**: farbiger Punkt — an = Stage-Farbe + Glow, aus = grau — mit Tooltip auf Hover (`Vite @ <url>`)
- **REDAXO-** und **ViteRex-Version**
- **Cache-Clear-Button** (CSRF-geschützter POST gegen `viterex_clear_cache`)

Andere Addons können via `VITEREX_BADGE`-Extension-Point eigene Panels hinzufügen.

---

## Dev-Server-Landing-Page

Beim direkten Aufruf der Vite-Dev-URL (z.B. `https://127.0.0.1:5173`) zeigt der eingebaute `viterex:dev-index`-Plugin (in `viterex-vite-plugin.js`) eine kleine HTML-Seite mit dem Hinweis _„Vite läuft"_ und einem Link zur eigentlichen Projekt-URL (aus `host_url` in `structure.json`). Verhindert die irritierende leere Seite, die Vite hier sonst serviert.

---

## block_peek-Integration

Wenn das [`block_peek`](https://github.com/FriendsOfREDAXO/block_peek)-Addon installiert ist, registriert ViteRex einen Handler auf dessen `BLOCK_PEEK_OUTPUT`-Extension-Point, der `REX_VITE`-Platzhalter im Block-Vorschau-Template auflöst. Damit funktioniert HMR + bundled Assets auch in den iframe-Vorschauen im Backend (wo der normale `OUTPUT_FILTER` aus Sicherheitsgründen schweigt — sonst würde er literale `REX_VITE`-Strings in Slice-Editoren ersetzen).

Die Integration ist konditional — sie aktiviert sich nur, wenn `block_peek` als Addon verfügbar ist. Kein hartes Coupling im Code.

---

## SVG-Optimierung

ViteRex optimiert SVGs automatisch — sowohl Source-Dateien im Build als auch Mediapool-Uploads. Single Toggle in **ViteRex → Einstellungen → SVG-Optimierung**, default ON. Welche Engine läuft und wann sie läuft, hängt von der Oberfläche ab:

| Surface          | Wann                                               | Engine                                           |
| ---------------- | -------------------------------------------------- | ------------------------------------------------ |
| Source-Assets    | `npm run dev` (server start) + `npm run build`     | SVGO (Node, via Vite-Plugin), 1:1 in-place       |
| Vite copy-pipe   | `npm run build` (`viteStaticCopy` transform)       | SVGO                                             |
| Mediapool        | `npm run build`                                    | SVGO (Vite-Plugin walked `<media_dir>`)          |
| Mediapool        | Manuell: `bin/console viterex:optimize-svgs`       | SVGO wenn verfügbar, sonst `PhpOptimizer` (PHP)  |
| Mediapool-Upload | `MEDIA_ADDED` / `MEDIA_UPDATED` (nur prod/staging) | `PhpOptimizer` (Node-frei für die Live-Umgebung) |

In **dev** ist der Mediapool-Upload-Hook ein No-op — kein SVGO-Shell-out bei jedem Test-Upload. Räume mit `npm run build` oder dem Console-Command auf, wenn du willst.

**Sicherheits-Effekt fürs Mediapool**: `<script>`-Tags und `on*`-Event-Handler werden bei jedem Prod/Staging-Upload entfernt — schliesst eine standardmässig vorhandene XSS-Lücke beim Hochladen von SVGs in Redaxo.

**Cache**: Beide Pfade (Vite-Build + Console-Command) teilen sich `<cache_dir>/svg-optimized.json`. SHA1 der optimierten Bytes pro Datei; bereits optimale Files werden in Folge-Runs übersprungen. `bin/console viterex:optimize-svgs --force` ignoriert den Cache.

**Idempotent / Fail-open**: SVGO-Output round-trippt unverändert; Cache verhindert dass das überhaupt bemerkt wird. Bei jedem Fehler bleibt die Datei unverändert.

**npm-Dep**: `svgo` wird per `install.php` in die `package.json` des Projekts gemerged. Beim Upgrade von v3.2.x erscheint es automatisch in `devDependencies`; ein einmaliges `npm install` aktiviert die Optimierung. In der Lücke davor warnt das Vite-Plugin und macht nichts (kein Crash).

### Console-Command

```bash
bin/console viterex:optimize-svgs              # walk + optimize
bin/console viterex:optimize-svgs --dry-run    # list what would change
bin/console viterex:optimize-svgs --force      # ignore cache, re-do everything
```

Engine-Wahl: SVGO wenn `npx svgo` auf PATH, sonst `PhpOptimizer`. Honors `svg_optimize_enabled` (bricht früh ab wenn off).

### Inline-SVGs: Scope-Isolation gegen Class-Kollisionen

Wer mehrere SVGs auf derselben Seite via `Assets::inline('img/foo.svg')` einbindet (typisch: Icons, Illustrationen aus Figma/Illustrator), ist standardmässig kollisionsanfällig: SVG-`<style>`-Blöcke haben **document-level scope**, sobald die SVG inline im HTML steht. Zwei SVGs mit `.cls-1`-Selectoren (Standard-Export-Naming) bluten gegenseitig in alle Pfade auf der Seite, die zufällig dieselbe Klasse tragen — auch in unbeteiligte SVGs oder HTML-Elemente. Dasselbe gilt für `<filter id="x">`, `<linearGradient id="x">`, `<symbol id="x">` und alle internen Referenzen darauf (`url(#x)`, `<use href="#x">`).

`Assets::inline()` löst das automatisch: jede inline-eingebundene SVG kriegt zur Laufzeit einen stabilen, datei-abgeleiteten Prefix (`<path-slug>-…`) auf alle `id`/`class`-Attribute und alle internen Referenzen. Beispiel:

```svg
<!-- src/assets/img/icon-foo.svg -->
<svg>
  <style>.cls-1 { fill: red }</style>
  <path class="cls-1" id="head"/>
  <use href="#head"/>
</svg>
```

…wird beim Inline-Einbinden zu:

```svg
<svg>
  <style>.img-icon-foo-cls-1 { fill: red }</style>
  <path class="img-icon-foo-cls-1" id="img-icon-foo-head"/>
  <use href="#img-icon-foo-head"/>
</svg>
```

**Was umgeschrieben wird:** `id="X"`, `url(#X)`, `href="#X"` / `xlink:href="#X"` (nur Fragment-Refs, externe URLs bleiben unangetastet), sowie `.X`-Selectoren in `<style>`-Blöcken. Bei `class="X Y Z"` werden **nur** Tokens umgeschrieben, die auch als `.X`-Selector im SVG-eigenen `<style>` definiert sind — externe Klassen (Tailwind-Utilities, Projekt-CSS, BEM) bleiben unangetastet, damit Host-Page-CSS weiterhin matcht. `#X`-Selectoren in `<style>` werden nur umgeschrieben, wenn `X` auch als echtes `id="X"` im Dokument vorkommt — Hex-Farben wie `#fff` / `#abc` bleiben so unberührt.

**Cache:** Das Ergebnis wird unter `rex_path::addonCache('viterex_addon', 'inline-svg/<sha1>.svg')` gecached, gekeyed auf `path + content`. Die Prefixing-Kosten fallen nur einmal pro (Datei, Inhalt)-Paar an. Source-Files auf der Disk bleiben **unberührt** — der Prefix entsteht nur zur Inline-Zeit, sodass dieselbe Datei weiterhin als `<img src="…">` oder `background-image: url()` funktioniert.

**Opt-out per Datei:** Wer eine SVG bewusst mit shared `id`/`class`-Namen über mehrere Inlines hinweg verwendet (z. B. ein Sprite-Pattern mit cross-document Refs), kann das Prefixing für diese Datei abschalten:

```svg
<svg>
  <!-- viterex:no-prefix -->
  …
</svg>
```

Der Magic-Comment darf irgendwo im Dokument stehen. Findet ihn der Prefixer, wird die SVG unverändert ausgeliefert (kein Cache-Eintrag).

**Globale Toggle-Bindung:** Wenn `svg_optimize_enabled` auf OFF steht, läuft auch das Inline-Prefixing nicht — `Assets::inline()` verhält sich dann wie vor v3.3.

---

## Stage-Erkennung & Debug-Modus

ViteRex erkennt drei Deployment-Stages:

| Stage     | Erkennung                                       |
| --------- | ----------------------------------------------- |
| `prod`    | `ydeploy::getStage()` beginnt mit `"prod"`      |
| `staging` | `ydeploy::getStage()` beginnt mit `"stage"`     |
| `dev`     | alles andere (oder `ydeploy` nicht installiert) |

Wenn `ydeploy` installiert ist und nicht-prod erkannt wird, setzt ViteRex automatisch ein `<meta name="robots" content="noindex, nofollow">` über den `YREWRITE_SEO_TAGS`-Extension-Point.

REDAXOs Debug-Modus wird auf Dev/Staging eingeschaltet, auf Prod ausgeschaltet — die Änderung wird nach `core/data/config.yml` persistiert (idempotent: ohne Disk-Write, wenn der Wert bereits stimmt), damit der `developer`-Addon und andere Konsumenten den richtigen Wert sehen.

---

## ydeploy-Helper

Wenn das [ydeploy](https://github.com/yakamara/ydeploy)-Addon installiert ist, blendet viterex_addon eine **Deploy**-Subpage im Backend ein (ViteRex → Deploy). Damit lassen sich Deployment-Hosts per Formular bearbeiten, statt `deploy.php` von Hand zu editieren.

So funktioniert's:

- Eine Sidecar-Datei `deploy.config.php` im Projekt-Root hält die editierbaren Werte (Repository-URL, Liste der Hosts mit Name/Hostname/Port/User/Stage/Pfad).
- `deploy.php` lädt die Sidecar innerhalb eines klar markierten Blocks per `require`:

  ```php
  // >>> VITEREX:DEPLOY_CONFIG (do not edit by hand) >>>
  $cfg = require __DIR__ . '/deploy.config.php';
  set('repository', $cfg['repository']);
  foreach ($cfg['hosts'] as $h) {
      host($h['name'])->setHostname($h['hostname'])
          ->setRemoteUser($h['user'])->setPort($h['port'])
          ->set('labels', ['stage' => $h['stage']])->setDeployPath($h['path']);
  }
  // <<< VITEREX:DEPLOY_CONFIG <<<
  ```

- **Erster Aufruf:** Die Seite versucht, Werte aus dem aktuellen `deploy.php` zu extrahieren und schreibt sie in die Sidecar (`deploy.php` bleibt dabei unverändert). Falls das Projekt ein `git remote get-url origin` hat, wird diese URL als Default-Repository verwendet. Formular prüfen und auf **Aktivieren** klicken, um `deploy.php` so umzuschreiben, dass es die Sidecar nutzt (vorher wird ein `.bak.<timestamp>`-Backup erstellt).

- **Spätere Saves:** Nur die Sidecar wird neu geschrieben; `deploy.php` bleibt unangetastet. Der Deployer liest die neuen Werte beim nächsten Lauf.

- **Alles ausserhalb des Markierungsblocks** gehört dir — eigene Tasks, `add('shared_dirs', ...)`, `add('clear_paths', ...)`, Environment-Branches usw. Der Helper fasst das nicht an.

- **Redundante `set('repository', ...)`-Aufrufe** anderswo in `deploy.php` (z. B. innerhalb eines `if ($isGit)`-Zweigs aus dem Installer-Scaffold) würden den Markierungsblock stillschweigend überschreiben — Deployer arbeitet beim `set` nach Last-Write-Wins. Beim Aktivieren erkennt der Helper solche Aufrufe und ersetzt sie durch `/* viterex: removed redundant ... — overridden by sidecar */`-Kommentare, damit der Wert aus dem Markierungsblock gewinnt. Der Originalcode bleibt im Kommentartext erhalten.

- **Stage-Label:** Das Eingabefeld bietet als Vorschläge nur die Werte an, für die ydeploy ein Badge stylet (`staging`, `production`, `prod`, `live`, `test`, `testing`). Eigene Werte sind weiterhin erlaubt, das Badge bleibt dann ungestylt. Empfohlene Wahl für reibungsloses Verhalten in beiden Welten: `staging` und `production` — sie passen zu ydeploys CSS und zu den Prefix-Matchern in viterex_addon (`Server::isProductionDeployment` testet `prod*`, `Server::isStagingDeployment` testet `stage*`).

- **Markierungsblock manuell bearbeiten ist riskant.** Wenn die Marker unvollständig oder verschwunden sind, weigert sich das nächste Aktivieren, neu zu schreiben, und bittet dich, ein Backup wiederherzustellen.

Die Sidecar ist einfaches PHP, das ein Array zurückgibt — kein Parser, keine zusätzliche Abhängigkeit nötig. Du kannst sie committen oder über `.gitignore` ausschliessen — deine Wahl.

---

## Mitwirken

Das Backend-Badge wird mit Vite gebaut. Wenn du Dateien unter `assets-src/` änderst, regeneriere die kompilierten Versionen unter `assets/badge/`:

```bash
cd viterex-addon
npm install   # einmalig
npm run build
```

Die `assets/`-Verzeichnis-Struktur wird mit-committet (Badge-Build + `viterex-vite-plugin.js`) und ist Teil des Releases. REDAXO kopiert beim Installieren den `assets/`-Tree automatisch nach `<frontend>/assets/addons/viterex_addon/` — daher landet `viterex-vite-plugin.js` an der Stelle, von der die `vite.config.js` der Nutzer importiert.

---

## Bekannte Einschränkungen

- **CSP / Nonces:** ViteRex versieht alle selbst ausgegebenen Tags (Script-, Stylesheet- und relevante Preload-Tags aus `Assets`/`Preload` sowie das Dev-Badge) automatisch mit dem Request-Nonce aus `rex_response::getNonce()`. Eine strikte CSP deines Projekts funktioniert damit out of the box mit den ViteRex-Assets — du musst den Header nur selbst setzen. ViteRex setzt bewusst **keine** CSP: die Policy ist projektweit (Fonts, Analytics, Embeds, Inline-Handler in Modulen/Slices) und gehört dir.

  ```php
  // z. B. im Config-Template oder in der boot.php deines project-Addons
  $nonce = rex_response::getNonce();
  rex_response::setHeader(
      'Content-Security-Policy',
      "default-src 'self'; "
      . "script-src 'self' 'nonce-$nonce'; "
      . "style-src 'self' 'nonce-$nonce'",
  );
  ```

  Zwei Einschränkungen bleiben:

  - **Dev/HMR ist best effort.** ViteRex nonced seine eigenen Dev-Tags, aber der Vite-HMR-Client injiziert zur Laufzeit eigene `<script>`/`<style>` (Error-Overlay, eingespritzte Styles), die ViteRex nicht erreicht; Vites `html.cspNonce` ist statisch, nicht pro Request. Empfehlung: CSP im Dev lockern. **Produktion ist sauber.**
  - **Inline-SVG `<style>`.** `Assets::inline()` kann SVG-Markup mit einem `<style>`-Element zurückgeben. Unter striktem `style-src 'nonce-…'` ohne `'unsafe-inline'` blockiert der Browser dieses. Nicht behandelt.

---

## Vite+ und andere Toolchains

Eine separate Notiz zu [Vite+](https://viteplus.dev/) und der Frage „Sollten wir migrieren?" findest du unter [`docs/vite-plus-evaluation.md`](docs/vite-plus-evaluation.md). Kurzfassung: ViteRex hält den Plugin-Code CLI-agnostisch — du kannst Vite+ heute schon selbst on-top installieren, indem du in deiner `package.json` die Scripts auf `vp` umstellst. Ein offizielles Migration-Statement gibt es noch nicht.

---

## Issues / Kontakt

Bug-Reports & Feature-Requests auf [GitHub](https://github.com/ynamite/viterex_addon/issues). Änderungen im [CHANGELOG.md](CHANGELOG.md).

## Lizenz

[MIT](LICENSE.md)

## Credits

- **Project Lead**: [Yves Torres](https://github.com/ynamite)
- **Inspiriert von**: [laravel-vite-plugin](https://github.com/laravel/vite-plugin)
