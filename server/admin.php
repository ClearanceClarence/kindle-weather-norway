<?php

declare(strict_types=1);

/*
 * Kindle weather display: admin page.
 *
 * A password-protected page for changing the settings: location, icon set,
 * tabs, and how the Kindle refreshes. Settings are saved to data/store.php
 * (see src/settings.php) and take effect on the Kindle's next refresh.
 *
 * First visit: you choose a password. After that the page asks for it.
 * Five wrong passwords lock the page for 15 minutes.
 */

// Say so plainly on a PHP that is too old, instead of failing with a blank page.
if (PHP_VERSION_ID < 80100) {
    header('Content-Type: text/plain; charset=utf-8');
    exit('Værvarsel trenger PHP 8.1 eller nyere. Denne serveren har PHP ' . PHP_VERSION . ".\n");
}

require __DIR__ . '/src/bootstrap.php';

const MIN_PASSWORD_LENGTH = 10;
const MAX_FAILURES = 5;
const LOCK_SECONDS = 900;

$config = load_config(__DIR__);
$store = load_store(__DIR__);

session_set_cookie_params([
    'httponly' => true,
    'samesite' => 'Strict',
    'secure'   => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
]);
session_name('kindle_weather_admin');
session_start();
$_SESSION['csrf'] ??= bin2hex(random_bytes(32));

/** Escapes text for HTML. */
function e(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

/** Stores a message for the next page view and reloads the page. */
function redirect_with(string $type, string $message): never
{
    $_SESSION['flash'] = [$type, $message];
    header('Location: admin.php');
    exit;
}

$loggedIn = !empty($_SESSION['admin']) && $store['password_hash'] !== '';
$needsPassword = $store['password_hash'] === '';
$errors = [];
// Before the first save, the page follows the browser's language.
if ($store['settings'] === [] && $config['language'] === SETTING_DEFAULTS['language']
    && !preg_match('/^(nb|nn|no|da|sv)\b/i', (string) ($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? 'nb'))) {
    $config['language'] = 'en';
    language('en');
}
$form = $config;                              // what the settings form shows

// ---------------------------------------------------------------------------
// Actions
// ---------------------------------------------------------------------------

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['csrf'], (string) ($_POST['csrf'] ?? ''))) {
        redirect_with('error', t('Skjemaet var utløpt. Prøv igjen.'));
    }
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'create_password' && $needsPassword) {
        $password = (string) ($_POST['password'] ?? '');
        if (mb_strlen($password) < MIN_PASSWORD_LENGTH) {
            $errors[] = t('Passordet må ha minst ') . MIN_PASSWORD_LENGTH . t(' tegn.');
        } elseif ($password !== (string) ($_POST['password_repeat'] ?? '')) {
            $errors[] = t('Passordene er ikke like.');
        } else {
            $store['password_hash'] = password_hash($password, PASSWORD_DEFAULT);
            if (!save_store(__DIR__, $store)) {
                $errors[] = t('Kunne ikke lagre. Serveren må kunne opprette mappen «data» ved siden av admin.php.');
            } else {
                session_regenerate_id(true);
                $_SESSION['admin'] = true;
                redirect_with('ok', t('Passordet er lagret. Fyll inn innstillingene under.'));
            }
        }
    } elseif ($action === 'login' && !$needsPassword) {
        if (time() < $store['locked_until']) {
            $errors[] = t('For mange forsøk. Prøv igjen om ') . (int) ceil(($store['locked_until'] - time()) / 60) . t(' minutter.');
        } elseif (password_verify((string) ($_POST['password'] ?? ''), $store['password_hash'])) {
            $store['failures'] = 0;
            $store['locked_until'] = 0;
            save_store(__DIR__, $store);
            session_regenerate_id(true);
            $_SESSION['admin'] = true;
            redirect_with('ok', t('Du er logget inn.'));
        } else {
            sleep(1);                             // slows down guessing
            $store['failures']++;
            if ($store['failures'] >= MAX_FAILURES) {
                $store['failures'] = 0;
                $store['locked_until'] = time() + LOCK_SECONDS;
            }
            save_store(__DIR__, $store);
            $errors[] = t('Feil passord.');
        }
    } elseif ($action === 'logout') {
        $_SESSION = [];
        session_destroy();
        header('Location: admin.php');
        exit;
    } elseif ($action === 'add_font' && $loggedIn) {
        $error = null;
        $key = google_font_install(__DIR__, (string) ($_POST['google_family'] ?? ''), $store, $error);
        if ($key === null) {
            redirect_with('error', (string) $error);
        }
        // Switch to the new typeface right away once the display is set up.
        if ($store['settings'] !== []) {
            $store['settings']['font'] = $key;
        }
        if (save_store(__DIR__, $store)) {
            redirect_with('ok', t('«') . $store['google_fonts'][$key]['label'] . t('» er lagt til') . ($store['settings'] !== [] ? t(' og valgt.') : '.'));
        }
        redirect_with('error', t('Kunne ikke lagre. Serveren må kunne skrive til mappen «data».'));
    } elseif (str_starts_with($action, 'remove_font:') && $loggedIn) {
        google_font_remove(__DIR__, substr($action, 12), $store);
        save_store(__DIR__, $store);
        redirect_with('ok', t('Skriften er fjernet.'));
    } elseif ($action === 'save' && $loggedIn) {
        $settings = validate_settings($_POST, $errors, $config['fonts']);
        $form = $settings + $config;

        // An optional new password.
        $newPassword = (string) ($_POST['new_password'] ?? '');
        if ($newPassword !== '') {
            if (mb_strlen($newPassword) < MIN_PASSWORD_LENGTH) {
                $errors[] = t('Nytt passord må ha minst ') . MIN_PASSWORD_LENGTH . t(' tegn.');
            } elseif ($newPassword !== (string) ($_POST['new_password_repeat'] ?? '')) {
                $errors[] = t('De nye passordene er ikke like.');
            }
        }

        if ($errors === []) {
            $store['settings'] = $settings;
            if ($newPassword !== '') {
                $store['password_hash'] = password_hash($newPassword, PASSWORD_DEFAULT);
            }
            if (save_store(__DIR__, $store)) {
                redirect_with('ok', t('Lagret. Kindle henter endringene ved neste oppdatering.'));
            }
            $errors[] = t('Kunne ikke lagre. Serveren må kunne skrive til mappen «data».');
        }
    }
}

// The Kindle script with this server's address filled in, as a download.
if (isset($_GET['download']) && $loggedIn) {
    $url = site_url();
    $source = __DIR__ . '/kindle/kindle-weather.sh';
    if (!is_readable($source)) {
        redirect_with('error', t('Finner ikke filen kindle/kindle-weather.sh på serveren. Last opp mappen «kindle» sammen med resten av mappen «server», og prøv igjen.'));
    }
    if ($url === null) {
        redirect_with('error', t('Kunne ikke finne adressen til denne siden. Kopier kindle/kindle-weather.sh selv og skriv inn adressen på linjen SERVER_URL.'));
    }
    $script = kindle_script(__DIR__, $url);
    if ($script === null) {
        redirect_with('error', t('Filen kindle/kindle-weather.sh på serveren mangler linjen SERVER_URL. Last opp mappen «kindle» på nytt.'));
    }
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="kindle-weather.sh"');
    header('Content-Length: ' . strlen($script));
    header('Cache-Control: no-store');
    echo $script;
    exit;
}

$problems = requirement_problems(__DIR__);
$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);
// Done with the session: release it so other requests from this browser are not kept waiting.
session_write_close();

// ---------------------------------------------------------------------------
// Page
// ---------------------------------------------------------------------------

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');

$csrf = e($_SESSION['csrf']);
$hours = range(0, 23);

// The page below is written in Norwegian; English is swapped in on the way out.
ob_start('translate_admin_page');
?>
<!doctype html>
<html lang="<?= language() === 'en' ? 'en' : 'nb' ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title>Værvarsel: innstillinger</title>
<style>
  :root { --ink: #111; --gray: #5a5a5a; --line: #d5d5d5; --paper: #fff; --ground: #f3f2ef; --ok: #1d6b3a; --bad: #a12a2a; }
  * { box-sizing: border-box; }
  [hidden] { display: none !important; }
  body { margin: 0; background: var(--ground); color: var(--ink); font: 16px/1.5 system-ui, -apple-system, "Segoe UI", sans-serif; }
  header { display: flex; justify-content: space-between; align-items: center; gap: 16px; padding: 18px 24px; border-bottom: 3px solid var(--ink); background: var(--paper); }
  h1 { margin: 0; font-size: 22px; }
  h2 { margin: 0 0 4px; font-size: 18px; }
  main { max-width: 1120px; margin: 0 auto; padding: 24px; }
  .layout { display: flex; flex-wrap: wrap; gap: 24px; align-items: flex-start; }
  .settings { flex: 999 1 520px; min-width: 0; display: flex; flex-direction: column; gap: 16px; }
  .preview { flex: 1 1 280px; max-width: 360px; }
  .preview img { display: block; width: 100%; border: 10px solid #2b2b2b; border-radius: 10px; background: #fff; }
  .kindle { margin-top: 18px; padding: 16px 18px; background: var(--paper); border: 1px solid var(--line); border-radius: 10px; font-size: 15px; }
  .kindle h2 { margin: 0 0 8px; font-size: 18px; }
  .kindle ol { margin: 0 0 14px; padding-left: 20px; }
  .kindle li { margin: 4px 0; }
  .results { display: flex; flex-direction: column; gap: 6px; margin-top: 10px; }
  .results button { justify-content: flex-start; text-align: left; font-weight: 500; min-height: 44px; }
  .results small { color: var(--gray); margin-left: 8px; }
  .preview nav { display: flex; flex-wrap: wrap; gap: 8px; margin-top: 10px; }
  section, .card { background: var(--paper); border: 1px solid var(--line); border-radius: 8px; padding: 18px 20px; }
  .hint { margin: 0 0 14px; color: var(--gray); font-size: 14px; }
  .row { display: grid; grid-template-columns: repeat(auto-fit, minmax(170px, 1fr)); gap: 14px; }
  label { display: flex; flex-direction: column; gap: 4px; font-size: 14px; font-weight: 600; }
  label small { font-weight: 400; color: var(--gray); }
  input[type=text], input[type=search], input[type=password], input[type=number], select { width: 100%; padding: 9px 10px; border: 1px solid #9a9a9a; border-radius: 6px; font: inherit; background: #fff; color: var(--ink); }
  input:focus-visible, select:focus-visible, button:focus-visible, a:focus-visible { outline: 3px solid #1a5fb4; outline-offset: 2px; }
  .choice { display: flex; align-items: center; gap: 12px; padding: 10px 12px; border: 1px solid var(--line); border-radius: 6px; font-weight: 600; flex-direction: row; cursor: pointer; }
  .choice + .choice { margin-top: 8px; }
  h3 { margin: 22px 0 6px; font-size: 16px; }
  button.link { min-height: 0; padding: 0; border: 0; background: none; font-weight: 500; font-size: 13px; color: var(--gray); text-decoration: underline; }
  .choice img { height: 56px; max-width: 100%; margin-left: auto; }
  .choice:has(input:checked) { border-color: var(--ink); box-shadow: inset 0 0 0 1px var(--ink); }
  label.check { flex-direction: row; align-items: center; gap: 8px; align-self: end; min-height: 48px; }
  .checks { display: flex; flex-wrap: wrap; gap: 8px 20px; }
  .checks label { flex-direction: row; align-items: center; gap: 8px; min-height: 44px; }
  input[type=checkbox], input[type=radio] { width: 20px; height: 20px; accent-color: var(--ink); }
  button, .button { min-height: 44px; padding: 10px 18px; border: 2px solid var(--ink); border-radius: 6px; background: var(--paper); color: var(--ink); font: inherit; font-weight: 700; cursor: pointer; text-decoration: none; display: inline-flex; align-items: center; }
  button.primary { background: var(--ink); color: #fff; }
  .actions { display: flex; flex-wrap: wrap; gap: 12px; align-items: center; }
  .message { padding: 12px 16px; border-radius: 6px; margin-bottom: 16px; font-weight: 600; background: var(--paper); border: 2px solid var(--ok); color: var(--ok); }
  .message.error { border-color: var(--bad); color: var(--bad); }
  .message ul { margin: 0; padding-left: 20px; }
  .narrow { max-width: 440px; margin: 40px auto; display: flex; flex-direction: column; gap: 14px; }
</style>
</head>
<body>
<header>
  <h1>Værvarsel: innstillinger</h1>
  <?php if ($loggedIn): ?>
  <form method="post"><input type="hidden" name="csrf" value="<?= $csrf ?>"><button name="action" value="logout">Logg ut</button></form>
  <?php endif; ?>
</header>
<main>

<?php if ($flash): ?>
  <div class="message <?= $flash[0] === 'error' ? 'error' : '' ?>" role="status"><?= e($flash[1]) ?></div>
<?php endif; ?>
<?php if ($errors): ?>
  <div class="message error" role="alert"><ul><?php foreach ($errors as $error): ?><li><?= e($error) ?></li><?php endforeach; ?></ul></div>
<?php endif; ?>

<?php if ($problems): ?>
  <div class="message error" role="alert"><strong>Webhotellet mangler noe:</strong><ul><?php foreach ($problems as $problem): ?><li><?= e($problem) ?></li><?php endforeach; ?></ul></div>
<?php endif; ?>

<?php if ($needsPassword): ?>

  <form method="post" class="card narrow">
    <h2>Velg et passord</h2>
    <p class="hint"><?= e(sprintf(t('Dette er første gang siden åpnes. Passordet beskytter innstillingene, og må ha minst %d tegn.'), MIN_PASSWORD_LENGTH)) ?></p>
    <input type="hidden" name="csrf" value="<?= $csrf ?>">
    <label>Passord <input type="password" name="password" autocomplete="new-password" required minlength="<?= MIN_PASSWORD_LENGTH ?>"></label>
    <label>Gjenta passordet <input type="password" name="password_repeat" autocomplete="new-password" required minlength="<?= MIN_PASSWORD_LENGTH ?>"></label>
    <div class="actions"><button class="primary" name="action" value="create_password">Lagre passord</button></div>
  </form>

<?php elseif (!$loggedIn): ?>

  <form method="post" class="card narrow">
    <h2>Logg inn</h2>
    <input type="hidden" name="csrf" value="<?= $csrf ?>">
    <label>Passord <input type="password" name="password" autocomplete="current-password" required autofocus></label>
    <div class="actions"><button class="primary" name="action" value="login">Logg inn</button></div>
  </form>

<?php else: ?>

  <div class="layout">
  <form method="post" class="settings">
    <input type="hidden" name="csrf" value="<?= $csrf ?>">
    <!-- First button in the form, so pressing Enter in a field saves. -->
    <button name="action" value="save" hidden></button>

    <section>
      <h2>Språk</h2>
      <p class="hint">Språket på Kindle-skjermene og på denne siden.</p>
      <div class="row">
        <?php foreach (LANGUAGES as $key => $label): ?>
        <label class="choice"><input type="radio" name="language" value="<?= e($key) ?>" <?= $form['language'] === $key ? 'checked' : '' ?>> <?= e($label) ?></label>
        <?php endforeach; ?>
      </div>
    </section>

    <section>
      <h2>Sted</h2>
      <p class="hint">Navnet vises øverst på skjermen. Koordinatene bestemmer hvor varselet gjelder for.</p>
      <div class="actions">
        <input type="search" id="place-query" placeholder="Søk etter adresse eller sted, for eksempel Storgata 1 Skien" autocomplete="off" style="flex: 1 1 260px; width: auto" aria-label="Søk etter adresse eller sted">
        <button type="button" id="place-search">Søk</button>
      </div>
      <div class="results" id="place-results" role="status"></div>
      <p class="hint" style="margin-top: 10px">Søket bruker Kartverket. Du kan også fylle inn feltene selv.</p>
      <div class="row">
        <label>Stedsnavn <small>første linje</small><input type="text" name="place" maxlength="40" required value="<?= e($form['place']) ?>"></label>
        <label>Område <small>andre linje, kan stå tom</small><input type="text" name="area" maxlength="40" value="<?= e($form['area']) ?>"></label>
      </div>
      <div class="row" style="margin-top: 14px">
        <label>Breddegrad <small>for eksempel 59.2096</small><input type="text" inputmode="decimal" name="lat" id="lat" required value="<?= e($form['lat'] ?? '') ?>"></label>
        <label>Lengdegrad <small>for eksempel 9.6090</small><input type="text" inputmode="decimal" name="lon" id="lon" required value="<?= e($form['lon'] ?? '') ?>"></label>
      </div>
      <div class="actions" style="margin-top: 14px">
        <button type="button" id="locate">Bruk posisjonen min</button>
        <span class="hint" id="locate-status" style="margin: 0" role="status"></span>
      </div>
    </section>

    <section>
      <h2>Kontakt</h2>
      <p class="hint">Meteorologisk institutt krever at alle som henter data oppgir e-post eller nettsted. Den sendes bare til dem.</p>
      <label>E-post eller nettsted <input type="text" name="contact" maxlength="100" required value="<?= e($form['contact']) ?>"></label>
    </section>

    <section>
      <h2>Skrift</h2>
      <p class="hint">Velg skrifttypen på skjermen.</p>
      <?php foreach ($config['fonts'] as $key => $font): ?>
      <label class="choice">
        <input type="radio" name="font" value="<?= e($key) ?>" <?= $form['font'] === $key ? 'checked' : '' ?>>
        <span><?= e($font['label']) ?><?php if ($font['google']): ?><br><button class="link" name="action" value="remove_font:<?= e($key) ?>" formnovalidate>Fjern</button><?php endif; ?></span>
        <img src="index.php?view=fonts&amp;set=<?= e($key) ?>&amp;lang=<?= language() ?>" alt="" loading="lazy">
      </label>
      <?php endforeach; ?>

      <h3>Legg til fra Google Fonts</h3>
      <p class="hint">Skriv navnet på en skrift fra <a href="https://fonts.google.com/" target="_blank" rel="noopener">fonts.google.com</a>. Den lastes ned til tjeneren én gang. Kindle har aldri kontakt med Google.</p>
      <div class="actions">
        <input type="text" name="google_family" list="google-fonts" maxlength="60" placeholder="for eksempel Lora" autocomplete="off" style="flex: 1 1 220px; width: auto" aria-label="Navn på skrift fra Google Fonts">
        <datalist id="google-fonts"><?php foreach (GOOGLE_FONT_SUGGESTIONS as $name): ?><option value="<?= e($name) ?>"><?php endforeach; ?></datalist>
        <button name="action" value="add_font" formnovalidate>Legg til</button>
      </div>
    </section>

    <section>
      <h2>Ikoner</h2>
      <p class="hint">Velg stilen på værsymbolene.</p>
      <?php foreach (ICON_SETS as $key => $label): ?>
      <label class="choice">
        <input type="radio" name="icon_set" value="<?= e($key) ?>" <?= $form['icon_set'] === $key ? 'checked' : '' ?>>
        <?= e(t($label)) ?>
        <img src="index.php?view=icons&amp;set=<?= e($key) ?>" alt="" loading="lazy">
      </label>
      <?php endforeach; ?>
    </section>

    <section>
      <h2>Faner</h2>
      <p class="hint">Fanene nederst på skjermen, og hvilken som vises først. Skjermen kan også gå gjennom fanene av seg selv, én for hver oppdatering.</p>
      <div class="checks">
        <?php foreach (TAB_LABELS as $key => $label): ?>
        <label><input type="checkbox" name="tabs[]" value="<?= e($key) ?>" <?= in_array($key, $form['tabs'], true) ? 'checked' : '' ?>> <?= e(t($label)) ?></label>
        <?php endforeach; ?>
      </div>
      <div class="row" style="margin-top: 10px">
        <label>Startfane
          <select name="default_view">
            <?php foreach (TAB_LABELS as $key => $label): ?>
            <option value="<?= e($key) ?>" <?= $form['default_view'] === $key ? 'selected' : '' ?>><?= e(t($label)) ?></option>
            <?php endforeach; ?>
          </select>
        </label>
      </div>
      <div class="row" style="margin-top: 10px">
        <label class="check"><input type="checkbox" name="rotate" value="1" <?= $form['rotate'] ? 'checked' : '' ?>> Bytt til neste fane ved hver oppdatering</label>
        <label class="check"><input type="checkbox" name="rotate_flights" value="1" <?= $form['rotate_flights'] ? 'checked' : '' ?>> Ta med flyskjermen i runden</label>
      </div>
    </section>

    <section>
      <h2>Oppdatering</h2>
      <p class="hint">Sjeldnere oppdatering og lengre stilletid sparer batteri. I stilletiden viser Kindle en nattskjerm og henter ikke nye varsler.</p>
      <div class="row">
        <label>Oppdater hvert <small>minutter, 5–120</small><input type="number" name="interval" min="5" max="120" required value="<?= e($form['interval']) ?>"></label>
        <label>Stilletid fra <small>klokkeslett</small>
          <select name="quiet_start"><?php foreach ($hours as $h): ?><option value="<?= $h ?>" <?= (int) $form['quiet_start'] === $h ? 'selected' : '' ?>><?= sprintf('%02d:00', $h) ?></option><?php endforeach; ?></select>
        </label>
        <label>Stilletid til <small>lik «fra» = av</small>
          <select name="quiet_end"><?php foreach ($hours as $h): ?><option value="<?= $h ?>" <?= (int) $form['quiet_end'] === $h ? 'selected' : '' ?>><?= sprintf('%02d:00', $h) ?></option><?php endforeach; ?></select>
        </label>
        <label>Full oppfrisking <small>hver n-te gang, 1–20</small><input type="number" name="full_refresh_every" min="1" max="20" required value="<?= e($form['full_refresh_every']) ?>"></label>
      </div>
    </section>

    <section>
      <h2>Fly</h2>
      <p class="hint">En flyknapp nederst til høyre på værskjermene åpner «Fly over oss»: et kart med flyene i nærheten. Trykk på et fly for å se hvor det skal.</p>
      <div class="row">
        <label class="check"><input type="checkbox" name="flights" value="1" <?= $form['flights'] ? 'checked' : '' ?>> Vis flyknappen</label>
        <label>Rekkevidde <small>km, 10–200</small><input type="number" name="flight_radius" min="10" max="200" required value="<?= e($form['flight_radius']) ?>"></label>
      </div>
      <div class="row" style="margin-top: 10px">
        <label class="check"><input type="checkbox" name="flight_home" value="1" <?= $form['flight_home'] ? 'checked' : '' ?>> Vis hus midt på kartet</label>
        <label class="check"><input type="checkbox" name="flight_airports" value="1" <?= $form['flight_airports'] ? 'checked' : '' ?>> Vis flyplasser</label>
      </div>
    </section>

    <section>
      <h2>Nordlys</h2>
      <p class="hint">Viser et varsel på Nå-skjermen når det er mørkt og nordlysaktiviteten (Kp, fra NOAA) er ventet å nå grensen. 5 passer for Sør-Norge. Lavere gir flere varsler, men oftere bare et svakt lys lavt i nord.</p>
      <div class="row">
        <label class="check"><input type="checkbox" name="aurora" value="1" <?= $form['aurora'] ? 'checked' : '' ?>> Vis nordlysvarsel</label>
        <label>Kp-grense <small>3–9</small><input type="number" name="aurora_kp" min="3" max="9" required value="<?= e($form['aurora_kp']) ?>"></label>
      </div>
    </section>

    <section>
      <h2>Lys</h2>
      <p class="hint">Frontlyset bruker batteri. Styrke 0 holder det av hele døgnet. Når Kindle står til lading, kan lyset være sterkere: da er det på hele dagen, unntatt i stilletiden.</p>
      <div class="row">
        <label>Lysstyrke <small>0–24, 0 = av</small><input type="number" name="light_level" min="0" max="24" required value="<?= e($form['light_level']) ?>"></label>
        <label>På fra <small>time, 0–24</small><input type="number" name="light_from" min="0" max="24" required value="<?= e($form['light_from']) ?>"></label>
        <label>På til <small>time, 0–24</small><input type="number" name="light_to" min="0" max="24" required value="<?= e($form['light_to']) ?>"></label>
        <label>Ved lading <small>0–24, 0 = ingen forskjell</small><input type="number" name="light_charging" min="0" max="24" required value="<?= e($form['light_charging']) ?>"></label>
      </div>
    </section>

    <section>
      <h2>Bytt passord</h2>
      <p class="hint">La feltene stå tomme for å beholde passordet.</p>
      <div class="row">
        <label>Nytt passord <input type="password" name="new_password" autocomplete="new-password" minlength="<?= MIN_PASSWORD_LENGTH ?>"></label>
        <label>Gjenta nytt passord <input type="password" name="new_password_repeat" autocomplete="new-password" minlength="<?= MIN_PASSWORD_LENGTH ?>"></label>
      </div>
    </section>

    <div class="actions"><button class="primary" name="action" value="save">Lagre innstillinger</button></div>
  </form>

  <aside class="preview" aria-label="Forhåndsvisning">
    <img id="preview" src="index.php?view=<?= e($config['default_view']) ?>&amp;x=1&amp;batt=84&amp;t=<?= time() ?>" alt="Slik ser skjermen ut nå">
    <nav aria-label="Vis fane">
      <?php foreach (active_tabs($config['tabs']) as $key => $label): ?>
      <button type="button" data-view="<?= e($key) ?>"><?= e(t($label)) ?></button>
      <?php endforeach; ?>
    </nav>
    <p class="hint" style="margin-top: 10px">Forhåndsvisningen viser lagrede innstillinger.</p>

    <div class="card kindle">
      <h2>Få det på Kindle</h2>
      <ol>
        <li>Kindle må være jailbreaket. <a href="https://kindlemodding.org/jailbreaking/" target="_blank" rel="noopener">Slik gjør du det</a>.</li>
        <li>Last ned filen under. Adressen til denne siden er allerede skrevet inn.</li>
        <li>Koble Kindle til PC-en med USB og kopier filen til mappen <b>documents</b>.</li>
        <li>Løs ut Kindle, slå på Wi-Fi og trykk på <b>Værvarsel</b> i biblioteket.</li>
      </ol>
      <a class="button" href="admin.php?download=script">Last ned kindle-weather.sh</a>
      <p class="hint" style="margin: 10px 0 0">Filen bruker adressen <b><?= e(site_url() ?? 'ukjent') ?></b></p>
      <p class="hint" style="margin: 10px 0 0">Trykk på det svake krysset øverst til høyre på Kindle for å lukke.</p>
      <?php $onKindle = kindle_version($config); $offered = script_version(__DIR__); ?>
      <p class="hint" style="margin: 10px 0 0"><?= e(t('Versjon: server ') . APP_VERSION . t(' · Kindle kjører ') . ($onKindle !== '' ? $onKindle : t('ukjent'))) ?></p>
      <?php if ($onKindle !== '' && $offered !== '' && $onKindle !== $offered): ?>
      <p class="hint" style="margin: 6px 0 0"><b><?= e(t('Kindle kjører ikke samme versjon som filen her. Last ned filen på nytt, kopier den til Kindle, og start skjermen på nytt.')) ?></b></p>
      <?php endif; ?>
    </div>
  </aside>
  </div>

  <script>
    // Enter in the "add a typeface" field adds it instead of saving.
    document.querySelector('[name=google_family]').addEventListener('keydown', function (event) {
      if (event.key === 'Enter') {
        event.preventDefault();
        document.querySelector('button[value=add_font]').click();
      }
    });

    // Preview: switch between the tabs.
    document.querySelectorAll('[data-view]').forEach(function (button) {
      button.addEventListener('click', function () {
        document.getElementById('preview').src = 'index.php?view=' + encodeURIComponent(button.dataset.view) + '&x=1&batt=84&t=' + Date.now();
      });
    });
    // Place search: look the text up in Kartverket's open address and
    // place-name registers, and fill in the form from the result picked.
    (function () {
      var query = document.getElementById('place-query');
      var results = document.getElementById('place-results');

      function titleCase(text) {
        return (text || '').toLowerCase().replace(/(^|[\s-])(\S)/g, function (m, a, b) { return a + b.toUpperCase(); });
      }
      function getJson(url) {
        return fetch(url).then(function (response) { return response.ok ? response.json() : {}; }).catch(function () { return {}; });
      }
      function show(hits) {
        results.textContent = '';
        if (!hits.length) { results.textContent = 'Fant ingenting. Prøv et annet navn, eller fyll inn feltene selv.'; return; }
        hits.forEach(function (hit) {
          var button = document.createElement('button');
          button.type = 'button';
          button.textContent = hit.place;
          var detail = document.createElement('small');
          detail.textContent = hit.detail;
          button.appendChild(detail);
          button.addEventListener('click', function () {
            document.querySelector('[name=place]').value = hit.place;
            document.querySelector('[name=area]').value = hit.area;
            document.getElementById('lat').value = hit.lat.toFixed(4);
            document.getElementById('lon').value = hit.lon.toFixed(4);
            results.textContent = 'Fylt inn: ' + hit.place + '. Husk å lagre.';
          });
          results.appendChild(button);
        });
      }
      function search() {
        var text = query.value.trim();
        if (text.length < 2) { return; }
        results.textContent = 'Søker …';
        var q = encodeURIComponent(text);
        Promise.all([
          getJson('https://ws.geonorge.no/adresser/v1/sok?treffPerSide=5&utkoordsys=4258&sok=' + q),
          getJson('https://ws.geonorge.no/stedsnavn/v1/sted?treffPerSide=5&utkoordsys=4258&fuzzy=true&sok=' + q)
        ]).then(function (answers) {
          var hits = [];
          (answers[0].adresser || []).forEach(function (a) {
            var point = a.representasjonspunkt || {};
            if (typeof point.lat !== 'number' || typeof point.lon !== 'number') { return; }
            var area = titleCase(a.poststed || a.kommunenavn);
            hits.push({ place: a.adressetekst, area: area, detail: [a.postnummer, area].filter(Boolean).join(' '), lat: point.lat, lon: point.lon });
          });
          (answers[1].navn || []).forEach(function (n) {
            var point = n.representasjonspunkt || {};
            var lat = typeof point.nord === 'number' ? point.nord : point.lat;
            var lon = typeof point['øst'] === 'number' ? point['øst'] : point.lon;
            var name = ((n.stedsnavn || [])[0] || {})['skrivemåte'];
            if (typeof lat !== 'number' || typeof lon !== 'number' || !name) { return; }
            var kommune = titleCase(((n.kommuner || [])[0] || {}).kommunenavn);
            hits.push({ place: name, area: kommune === name ? '' : kommune, detail: [n.navneobjekttype, kommune].filter(Boolean).join(', '), lat: lat, lon: lon });
          });
          show(hits.slice(0, 8));
        });
      }
      document.getElementById('place-search').addEventListener('click', search);
      query.addEventListener('keydown', function (event) {
        if (event.key === 'Enter') { event.preventDefault(); search(); }
      });
    })();

    // Fill in the coordinates from the browser's location.
    document.getElementById('locate').addEventListener('click', function () {
      var status = document.getElementById('locate-status');
      if (!navigator.geolocation) { status.textContent = 'Nettleseren støtter ikke posisjon.'; return; }
      status.textContent = 'Henter posisjon …';
      navigator.geolocation.getCurrentPosition(function (position) {
        document.getElementById('lat').value = position.coords.latitude.toFixed(4);
        document.getElementById('lon').value = position.coords.longitude.toFixed(4);
        status.textContent = 'Koordinatene er fylt inn. Husk å lagre.';
      }, function () {
        status.textContent = 'Fikk ikke tilgang til posisjonen.';
      });
    });
  </script>

<?php endif; ?>
</main>
</body>
</html>
