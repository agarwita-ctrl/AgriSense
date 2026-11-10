<?php
/**
 * AgriSense - Landing page
 *
 * Rendered without the application shell. Everything on it is a statement
 * about what the system really does: the numbers are the defaults in
 * IrrigationSetting / the firmware, and the failure behaviour is what
 * AgriSense.ino and the API actually implement.
 */
$login = url('login');
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e(APP_NAME) ?> &middot; <?= e(APP_TAGLINE) ?></title>
    <meta name="description" content="AgriSense reads soil moisture in the field, runs the pump when the soil is dry and stops it at the level you choose, and logs every watering cycle.">
    <link rel="icon" type="image/svg+xml" href="<?= e(asset('images/favicon.svg')) ?>">
    <?= pwa_head_tags('#10281f') ?>
    <link rel="stylesheet" href="<?= e(asset('css/landing.css')) ?>">
</head>
<body class="lp">
<a class="lp-skip" href="#how">Skip to content</a>

<div class="lp-dark">
    <header class="lp-top lp-wrap">
        <a class="lp-brand" href="<?= e(url()) ?>" aria-label="<?= e(APP_NAME) ?> home">
            <span class="lp-mark"><img src="<?= e(asset('images/logo.svg')) ?>" width="26" height="26" alt=""></span>
            <span class="lp-brand-name"><?= e(APP_NAME) ?></span>
        </a>
        <nav class="lp-nav" aria-label="Page sections">
            <a href="#how">How it works</a>
            <a href="#safe">When things fail</a>
            <a href="#use">In the dashboard</a>
            <a class="lp-btn lp-btn-straw lp-btn-sm" href="<?= e($login) ?>">Sign in</a>
        </nav>
    </header>

    <section class="lp-hero lp-wrap" aria-labelledby="lpTitle">
        <div class="lp-hero-copy">
            <h1 id="lpTitle">Water the field when the soil asks for it.</h1>
            <p class="lp-lede">
                AgriSense reads the moisture in your soil, switches the pump on when it gets
                too dry and off when it is wet enough, and keeps a record of every
                watering you can check from your phone.
            </p>
            <div class="lp-actions">
                <a class="lp-btn lp-btn-straw" href="<?= e($login) ?>">Sign in</a>
                <a class="lp-btn lp-btn-ghost" href="#how">See how it works</a>
            </div>
            <p class="lp-note">Accounts are created by your administrator.</p>
        </div>

        <figure class="lp-soil" id="lpSoil"
                role="img"
                aria-label="A soil moisture gauge. Moisture falls to 20 percent, the pump switches on, moisture rises to 60 percent, and the pump switches off. The cycle then repeats.">
            <div class="lp-soil-top" aria-hidden="true">
                <div class="lp-pump-box">
                    <span class="lp-pump lp-pump-off">Pump off</span>
                    <span class="lp-pump lp-pump-on">Pump running</span>
                </div>
                <div class="lp-read">
                    <span class="lp-read-num"></span>
                    <span class="lp-read-label">soil moisture</span>
                </div>
            </div>
            <div class="lp-drops" aria-hidden="true"><i></i><i></i><i></i><i></i><i></i><i></i></div>
            <div class="lp-ground" aria-hidden="true">
                <div class="lp-wet"></div>
                <div class="lp-line lp-line-off"><span>Pump stops at 60%</span></div>
                <div class="lp-line lp-line-on"><span>Pump starts at 20%</span></div>
                <div class="lp-stake lp-stake-1"></div>
                <div class="lp-stake lp-stake-2"></div>
            </div>
        </figure>
        <button class="lp-pause" id="lpPause" type="button" aria-pressed="false" aria-controls="lpSoil">
            Pause animation
        </button>
    </section>
</div>

<main>
    <!-- ================= How a cycle works ================= -->
    <section class="lp-sec" id="how" aria-labelledby="howTitle">
        <div class="lp-wrap">
            <h2 id="howTitle">How a watering cycle works</h2>
            <p class="lp-sec-lede">
                The decision is made by the controller in the field, not by the website.
                The website sets the rules and keeps the record.
            </p>

            <ol class="lp-steps">
                <li>
                    <h3>Two probes, two jobs</h3>
                    <p>
                        Every 5 seconds the controller reads two soil moisture probes. One sits where
                        the soil dries first and decides when to start watering; the other sits where
                        water settles and decides when to stop.
                    </p>
                </li>
                <li>
                    <h3>The controller decides</h3>
                    <p>
                        At or below the dry level, 20% by default, it starts the pump. At the
                        wet level, 60% by default, it stops. You change both numbers on the
                        dashboard.
                    </p>
                </li>
                <li>
                    <h3>The pump runs with a limit</h3>
                    <p>
                        Whatever the soil does, the pump stops after the longest runtime you
                        allow, 5 minutes by default, and then rests before it can start again.
                    </p>
                </li>
                <li>
                    <h3>The dashboard keeps the record</h3>
                    <p>
                        Readings reach the website every 30 seconds. Each cycle is logged with
                        when it started, how long it ran and why it stopped.
                    </p>
                </li>
            </ol>
        </div>
    </section>

    <!-- ================= Failure behaviour ================= -->
    <section class="lp-sec lp-sec-alt" id="safe" aria-labelledby="safeTitle">
        <div class="lp-wrap">
            <h2 id="safeTitle">When something fails, the pump stays safe</h2>
            <p class="lp-sec-lede">
                A pump that runs dry or runs on is worse than one that stops. These are the
                cases the system is built around.
            </p>

            <dl class="lp-fail">
                <div>
                    <dt>The Wi-Fi drops</dt>
                    <dd>
                        The controller keeps watering by its own rules and starts reporting
                        again when the connection returns. Readings taken while it was
                        offline are not recorded. The dashboard marks the device offline
                        and raises an alert.
                    </dd>
                </div>
                <div>
                    <dt>A probe comes loose</dt>
                    <dd>
                        It shows as no reading, never as dry soil. If either probe is lost, the
                        pump will not start automatically, and a pump that is running is stopped.
                    </dd>
                </div>
                <div>
                    <dt>The wet level is never reached</dt>
                    <dd>The pump still stops at the maximum runtime, and an alert says it did.</dd>
                </div>
                <div>
                    <dt>The planting cycle is over, or has not begun</dt>
                    <dd>
                        Automatic watering pauses, and a running pump is stopped. Manual
                        control from the dashboard still works.
                    </dd>
                </div>
                <div>
                    <dt>A setting arrives that makes no sense</dt>
                    <dd>
                        The controller checks every value from the website before using it, and
                        rejects any that could run the pump dry or never water the field.
                    </dd>
                </div>
            </dl>
        </div>
    </section>

    <!-- ================= What people can do ================= -->
    <section class="lp-sec" id="use" aria-labelledby="useTitle">
        <div class="lp-wrap">
            <h2 id="useTitle">What you can do in the dashboard</h2>

            <div class="lp-roles">
                <div>
                    <h3>Farmers</h3>
                    <ul>
                        <li>See the soil moisture now, and for each probe.</li>
                        <li>
                            Watch air and canopy temperature. When the leaves run 5&nbsp;&deg;C
                            hotter than the air, it flags possible water stress.
                        </li>
                        <li>Look back over a day, a week or a month of readings.</li>
                        <li>Start or stop the pump by hand, with permission from an administrator.</li>
                        <li>Set the crop, the day it was planted and how many days the cycle lasts.</li>
                        <li>Get alerts when the pump runs, a sensor fails or the device goes quiet.</li>
                    </ul>
                </div>
                <div>
                    <h3>Administrators</h3>
                    <ul>
                        <li>Register field devices and issue each one its own key.</li>
                        <li>Set the dry and wet levels, sensor calibration and runtime limits.</li>
                        <li>Create accounts, and choose who may operate the pump.</li>
                        <li>Export readings, irrigation history and reports to CSV, or print them.</li>
                        <li>Read the system log of who changed what, and when.</li>
                    </ul>
                </div>
            </div>
        </div>
    </section>
</main>

<section class="lp-cta" aria-labelledby="ctaTitle">
    <div class="lp-wrap lp-cta-in">
        <div>
            <h2 id="ctaTitle">Check on the field from anywhere.</h2>
            <p>Sign in with the account your administrator gave you.</p>
        </div>
        <a class="lp-btn lp-btn-straw" href="<?= e($login) ?>">Sign in</a>
    </div>
</section>

<footer class="lp-foot lp-wrap">
    <span><strong><?= e(APP_NAME) ?></strong> &ndash; <?= e(APP_TAGLINE) ?></span>
    <span>Version <?= e(APP_VERSION) ?></span>
</footer>

<?= pwa_script_tag() ?>
<script <?= csp_attr() ?>>
    // The hero loops for as long as the page is open, so it can be paused.
    (function () {
        var button = document.getElementById('lpPause');
        var figure = document.getElementById('lpSoil');
        if (!button || !figure) { return; }
        button.addEventListener('click', function () {
            var paused = figure.classList.toggle('is-paused');
            button.setAttribute('aria-pressed', paused ? 'true' : 'false');
            button.textContent = paused ? 'Play animation' : 'Pause animation';
        });
    })();
</script>
</body>
</html>
