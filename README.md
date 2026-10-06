# AgriSense — IoT-Based Smart Irrigation System

An end-to-end smart irrigation system for small-scale farmers. An ESP32 reads
two soil moisture probes plus temperature and humidity, decides on its own when
to water, drives a 12 V pump through a relay, texts the farmer over GSM, and
reports everything to a PHP/MySQL web dashboard.

```
  Soil probe 1 ─┐
  Soil probe 2 ─┼─► ESP32 ─► threshold logic ─► relay ─► 12 V pump
  DHT22        ─┘     │
                      ├── SIM900A ──► SMS alert to the farmer
                      │
                      └── Wi-Fi ────► REST ──► PHP backend ──► MySQL
                                                                 │
                                                      web dashboard, history,
                                                      charts, reports
```

The ESP32 owns the pump. The web application never switches the relay directly —
it queues an instruction that the board collects, validates against its own
safety rules, applies and acknowledges. That is what makes the maximum-runtime
cut-off and the safe-state behaviour trustworthy, and it means irrigation keeps
working when the network does not.

---

## 1. What is included

| Deliverable | Where |
| ----------- | ----- |
| Database schema + seed data | `database/agrisense.sql` |
| Sample-data removal script | `database/purge_demo_data.sql` |
| Migrations for an existing install | `database/migration_dual_probe.sql`, `database/migration_security_hardening.sql` |
| REST API | `api/` |
| Web application | `index.php`, `controllers/`, `models/`, `views/` |
| Front-end assets (offline-capable) | `assets/` |
| ESP32 firmware | `esp32/AgriSense.ino` |
| API documentation | `docs/API.md` |
| Wiring and pin configuration | `docs/WIRING.md` |
| Blynk setup | `docs/BLYNK.md` |
| Test cases + ISO/IEC 25010 evaluation | `docs/TESTING.md` |

Features: authentication with roles, live dashboard, real-time monitoring,
sensor history, irrigation history, alerts, device management, irrigation
settings, reports (print + CSV), system audit log, manual pump control with
confirmation, and multi-device support.

---

## 2. Requirements

- **XAMPP** with Apache and MySQL — PHP **8.2+**
- A browser (Chrome, Edge or Firefox)
- Arduino IDE 2.x with the **ESP32 board package**, for the firmware
- Windows 10/11, macOS or Linux

Tested on Windows 11 with XAMPP (Apache 2.4.56, PHP 8.2.4, MariaDB 10.4.28).

> **A note on MySQL vs MariaDB.** XAMPP ships MariaDB, which is a drop-in
> replacement for MySQL and speaks the same protocol and SQL dialect. The schema
> is written to the common subset, so `agrisense.sql` imports unchanged on both
> MariaDB 10.4+ and MySQL 8.0+. No change is needed either way.

---

## 3. Installation

### Step 1 — Put the project in place

Copy the `AgriSense` folder into your web root:

```
C:\xampp\htdocs\AgriSense
```

(On macOS/Linux: `/opt/lampp/htdocs/AgriSense` or `/var/www/html/AgriSense`.)

The folder can be renamed — the application works out its own base URL.

### Step 2 — Start Apache and MySQL

Open the **XAMPP Control Panel** and start both. Both must show green.

### Step 3 — Create the database

Either from the command line:

```powershell
C:\xampp\mysql\bin\mysql.exe -u root < C:\xampp\htdocs\AgriSense\database\agrisense.sql
```

or in **phpMyAdmin** → *Import* → choose `database/agrisense.sql` → *Go*.

The script creates the `agrisense` database, all eight tables, the default
accounts, two sample devices and a week of sample readings so the dashboard has
something to show before any hardware exists.

> The script begins with `DROP DATABASE IF EXISTS agrisense`. Re-running it
> **erases everything** — do not re-import over live data.

### Step 4 — Check the configuration

Default XAMPP credentials are already set in `config/config.php`. Only edit this
if your MySQL user or password differs:

```php
define('DB_HOST', '127.0.0.1');
define('DB_NAME', 'agrisense');
define('DB_USER', 'root');
define('DB_PASS', '');           // set this if you gave root a password
define('APP_TIMEZONE', 'Asia/Manila');
```

### Step 5 — Open it

<http://localhost/AgriSense/>

| Username | Password | Role |
| -------- | -------- | ---- |
| `admin` | `Admin@123` | Administrator |
| `farmer` | `Farmer@123` | Farmer, may operate the pump |
| `juan` | `Farmer@123` | Farmer, view only |

These passwords are printed here, in `database/agrisense.sql`, and in every
copy of this project, so the application treats all three as expired: the first
time you sign in you are held on *My account* until you set a new one. That is
deliberate and is not a bug.

### Step 6 — Verify

Visit <http://localhost/AgriSense/api> — you should get a JSON endpoint list.
If you get a 404, see *Troubleshooting* below.

---

## 4. Connecting the ESP32

### Step 1 — Register the device

Sign in as an administrator → **Devices**. `AGRISENSE-ESP32-01` already exists
and matches the `DEVICE_ID` in the sketch; use it, or register a new one.

Open it and press **Generate a new key**. The seeded devices deliberately ship
without a usable key — one written into `agrisense.sql` would be identical in
every copy of this project, and a device key commands a pump.

**Copy the key as soon as it appears.** Only a SHA-256 hash of it is stored, so
it is shown once and cannot be read back afterwards. If you lose it, generate
another and re-flash.

Check that **Soil moisture probes** is set to *Two probes* and **DHT22 fitted**
is on, so the dashboard shows the right cards.

### Step 2 — Install the Arduino libraries

Arduino IDE → *Tools → Manage Libraries*:

- **ArduinoJson** by Benoit Blanchon (6.x or 7.x — the sketch handles both)
- **DHT sensor library** by Adafruit (1.4.x)
- **Adafruit Unified Sensor**

`WiFi` and `HTTPClient` are built into the ESP32 core. Install that too, via
*Boards Manager* → "esp32" by Espressif.

### Step 3 — Configure the sketch

Open `esp32/AgriSense.ino` and edit the **USER CONFIG** block:

```cpp
const char* AGRISENSE_BASE = "http://192.168.1.10/AgriSense";
const char* DEVICE_ID      = "AGRISENSE-ESP32-01";

const String ADMIN_NUMBER = "+639XXXXXXXXX";   // SMS alerts
const bool   SMS_ENABLED  = true;              // false if no SIM900A
```

The two secrets — the Wi-Fi password and the device API key — are kept out of
the sketch. Create `esp32/secrets.h` and uncomment its `#include` at the top of
the file:

```cpp
// esp32/secrets.h  — keep this file to yourself
#define WIFI_SSID_VALUE      "YourWiFiName"
#define WIFI_PASSWORD_VALUE  "YourWiFiPassword"
#define DEVICE_API_KEY_VALUE "paste-the-64-character-key-here"
```

This matters more than it looks. The sketch sits inside the web root, and a
server that has not been configured to refuse it will hand the whole file to
anyone who asks for it — Wi-Fi password, API key and all. The bundled
`.htaccess` blocks it, but a file with nothing secret in it cannot leak.

`AGRISENSE_BASE` must use the **LAN IP address of the computer running XAMPP**,
not `localhost` — to the ESP32, `localhost` means the ESP32. Find it with
`ipconfig` (Windows) or `ip addr` (Linux), and make sure both are on the same
network. Include the project folder, and no port number: Apache serves on 80.

`DEVICE_ID` must match the device code in the dashboard exactly, or every
upload is refused with `403`.

You will probably also need to let Apache through the Windows Firewall:

```powershell
# Run once, in an elevated PowerShell
New-NetFirewallRule -DisplayName "Apache HTTP (AgriSense)" `
    -Direction Inbound -Protocol TCP -LocalPort 80 -Action Allow
```

### Step 4 — Wire it up

Follow `docs/WIRING.md`. Connect everything **except the pump** for the first
power-up.

### Step 5 — Flash and watch

Board: *ESP32 Dev Module*. Upload, then open the Serial Monitor at
**115200 baud**. You should see:

```
======================================================
  AgriSense - IoT-Based Smart Irrigation System
  Firmware v2.1.0
  Device ID:  AGRISENSE-ESP32-01
======================================================
[init] Relay initialised - pump OFF
[init] Sensors initialised
[wifi] connecting to YourWiFiName ...
[wifi] connected, IP=192.168.1.24, MAC=A0:B7:65:1C:2D:3E
[init] Initializing SIM900A...
[init] Active config: pump ON <= 20.0%, OFF >= 60.0%, mode AUTO, max runtime 300 s, rest 600 s
[init] AgriSense system ready.
Soil1 AO=2916 (14.2%) DO=1 | Soil2 AO=2880 (16.0%) DO=1 | avg=15.1% | 29.4C 71.0%RH | pump OFF | AUTO
[http] POST /api/sensor-data (periodic) -> ok
```

The dashboard should now show the device **Online**, with both probes listed
under the soil moisture card.

### Step 6 — Calibrate both probes

`docs/WIRING.md` §5 — this takes two minutes per probe, and the readings are
meaningless without it.

### Step 7 — Set the SMS number

Put the farmer's mobile number in `ADMIN_NUMBER`. The board texts on every
irrigation start and stop, and on a safety cut-off. This is the offline
channel: it works even with no internet at all.

Set `SMS_ENABLED` to `false` if no SIM900A is fitted; everything else carries
on working.

> The current firmware has **no Blynk integration** — SMS replaces it as the
> mobile channel. If you need Blynk (specification §20), see the status note at
> the top of `docs/BLYNK.md`.

### Step 8 — Remove the sample data

Once real readings are arriving: **System Logs → Remove sample data**. Only rows
tagged `source = 'demo'` are deleted; device data is untouched.

---

## 5. How the irrigation logic works

Two thresholds, not one. The shipped device and the firmware fallbacks both
use 20 % and 60 %; the settings page shows what your device is actually
using, and 30 % is only the column default for a device registered from
scratch:

```
  moisture ≤ 20 %  ──► pump ON
  moisture ≥ 60 %  ──► pump OFF
  20 % … 60 %      ──► keep the current state
```

The gap between them is the **hysteresis band**. With a single set point the
pump would switch on and off continuously as the reading wobbled around it;
the band means it runs a proper cycle and then rests. The settings page enforces
a minimum gap of 5 percentage points.

Safety rules, all enforced on the device:

| Rule | Behaviour |
| ---- | --------- |
| Maximum runtime | The pump stops after `max_pump_runtime` even if the target is never reached. Checked on every loop pass. |
| Rest between cycles | A new cycle cannot start until `min_cycle_interval` has passed, so water can soak in. |
| Safe state | If the moisture reading is lost while irrigating, the pump stops and a critical alert is raised. |
| Relay-off on boot | The relay is driven OFF before anything else in `setup()`, so a reset can never leave the pump running. |
| Emergency off | A `PUMP_OFF` command is always honoured, whatever mode the device is in. It stays queued until the board confirms it, so a dropped reply delays it rather than losing it. |
| Settings sanity check | The firmware re-validates configuration received from the server before adopting it. |

Manual control requires the device to be in **Manual mode** — in automatic mode
the firmware would immediately re-apply the thresholds and override you.

---

## 6. Using the system

**Dashboard** — six summary cards (soil moisture, temperature, humidity, pump,
device, last update), four charts, recent alerts and recent irrigation. Updates
itself; no refreshing.

**Real-time monitoring** — a large-type view of one device with the pump
controls, for use on a phone in the field.

**Sensor history** — every reading, searchable, sortable, filterable by date,
exportable to CSV. Failed reads are shown as `failed`, not as zeros.

**Irrigation history** — one row per cycle: start, end, duration, trigger,
moisture at both ends, the threshold in force, and why it stopped.

**Alerts** — low moisture, pump on/off, device offline, sensor failure, Wi-Fi
trouble, abnormal readings, runtime cut-offs. Three severities.

**Devices** — register boards, get API keys, rotate keys, watch connectivity.
Supports as many ESP32s as you need.

**Irrigation settings** — thresholds, calibration, mode, maximum runtime,
reading interval, rest period. Validated before saving.

**Reports** — sensor, irrigation and device reports; filter by device and date,
print or export to CSV.

**Users** — accounts, roles, and the per-farmer pump-control permission.

**System logs** — who did what, when, from which IP.

### Roles

| | Administrator | Farmer |
| --- | --- | --- |
| View dashboard, readings, history, alerts, reports | ✓ | ✓ |
| Control the pump | ✓ | only with the permission enabled |
| Manage devices and thresholds | ✓ | view only |
| Manage users, view system logs | ✓ | — |

---

## 7. Project structure

```
AgriSense/
├── index.php                  Front controller (routing table)
├── .htaccess                  Rewrites + blocks direct access to internals
│
├── api/                       REST API
│   ├── index.php              API router
│   └── handlers/              Blocked at the web server; only the router
│       ├── ApiAuth.php          loads these. One directory, deliberately
│       ├── DeviceApi.php        named after no endpoint - when the handler
│       ├── SensorApi.php        for /api/alerts lived in a folder called
│       ├── IrrigationApi.php    alerts/, Apache answered that endpoint with
│       ├── AlertApi.php         a redirect instead of the JSON.
│       └── ReportApi.php
│
├── config/
│   ├── config.php             Database credentials, thresholds, timezone
│   └── database.php           PDO singleton, prepared-statement helpers
│
├── includes/
│   ├── bootstrap.php          Loads everything, installs error handlers
│   ├── auth.php               Sessions, login, role guards
│   ├── csrf.php               CSRF tokens
│   ├── helpers.php            Escaping, formatting, validation, CSV
│   ├── response.php           JSON responses
│   └── IrrigationEngine.php   Shared business logic (ingest, alerts, snapshot)
│
├── controllers/               One controller per section
├── models/                    One model per table
├── views/                     Templates (layout, dashboard, sensors, …)
├── assets/                    css/, js/, images/, vendor/
├── esp32/AgriSense.ino  Firmware
├── database/                  agrisense.sql, purge_demo_data.sql
└── docs/                      API.md, WIRING.md, BLYNK.md, TESTING.md
```

Business rules that both the API and the web UI need live in
`IrrigationEngine`, so the two can never disagree about what the system is
doing.

---

## 8. Security

- Passwords hashed with `password_hash()` (bcrypt); never stored or logged in
  plain text; rehashed automatically when PHP's default algorithm changes.
- Every query is a prepared statement. `ORDER BY` columns come from a
  whitelist, never from user input.
- All output escaped through `e()`.
- CSRF tokens on every state-changing request, form or fetch.
- Session cookie is `HttpOnly` + `SameSite=Lax`; the session ID is regenerated
  on login; sessions idle out after 30 minutes.
- Role-based access, plus a separate pump-control permission for farmers.
- Per-device API keys are stored as a SHA-256 hash, never in plaintext. The key
  is displayed once, when it is generated, and cannot be recovered from the
  interface or from a database backup. A device can only write its own data.
- Five failed logins from one IP, or ten against one username from anywhere,
  trigger a 15-minute lockout. The second limit is what stops distributed
  guessing against a username as well known as `admin`.
- Changing a password signs out every other session for that account.
- Accounts still carrying a shipped password are held on the password page
  until they set a new one.
- A `Content-Security-Policy` restricts scripts to this origin plus a
  per-request nonce, so injected markup does not execute. Bootstrap and
  Chart.js are served locally, so nothing needs an external exception.
- CSV exports neutralise leading `= + - @`, so text that reached the database
  from a form field cannot become a formula in Excel.
- `config/`, `includes/`, `models/`, `controllers/`, `views/`, `database/` and
  `esp32/` are blocked at the web server, as are `.sql`, `.ino`, `.h` and
  `.cpp` files anywhere; API handler classes cannot be requested directly.
- Database credentials exist only in `config/config.php` — never in JavaScript.
- Errors show a friendly message; details go to the PHP error log only.
  `APP_DEBUG` switches itself off unless the site is being served from
  localhost, so a deployment cannot ship with debugging left on.

**Before putting this into service:** set a real password on each account when
prompted, and generate an API key for every device (Devices → Edit → Generate a
new key). Never put a real Wi-Fi password or API key into a copy of
`esp32/AgriSense.ino` that you intend to share or submit — put them in a
`secrets.h` beside it instead, as the sketch's header explains.

---

## 9. Troubleshooting

| Problem | Fix |
| ------- | --- |
| "The system cannot reach the database" | MySQL is not running in the XAMPP Control Panel, or `DB_PASS` is wrong |
| 404 on every page except the home page | `mod_rewrite` is off, or `AllowOverride` is not `All`. Enable `LoadModule rewrite_module` in `httpd.conf` and restart Apache. As a fallback the app also accepts `index.php?r=dashboard` |
| `/api` returns 404 | Same cause; check `api/.htaccess` was copied (it starts with a dot and is easy to miss) |
| Blank white page | Look at `C:\xampp\apache\logs\error.log`. With `APP_DEBUG` on you get the detail on screen |
| Device shows Offline although it is powered | `AGRISENSE_BASE` uses `localhost` instead of the PC's LAN IP, or the firewall is blocking port 80. Check the ESP32 serial output for the HTTP status |
| `401 Invalid API key` in the serial log | Copy the key again from Devices → Edit — all 64 characters |
| `403 This API key does not belong to…` | `DEVICE_ID` in the sketch does not match the device the key belongs to |
| Charts empty but the table has rows | The chart range does not cover the data — try "Last 30 days" |
| Soil always reads 0 % or 100 % | Probe on an ADC2 pin (Wi-Fi disables ADC2) or not calibrated — see `docs/WIRING.md` §5 |
| Pump runs the moment power is applied | The relay board is the opposite polarity to the sketch. Flip `RELAY_ACTIVE_HIGH` in `esp32/AgriSense.ino` (it ships as `true`; most opto-isolated boards need `false`), and use the NO contact |
| Times are wrong | Set `APP_TIMEZONE` in `config/config.php` |

---

## 10. Testing

`docs/TESTING.md` contains the full test-case checklist (authentication,
sensors, irrigation, communication, database, dashboard, security) and the
ISO/IEC 25010 evaluation instrument covering functional suitability,
performance efficiency, usability, reliability, security, maintainability and
portability.

---

## 11. Credits

AgriSense — IoT-Based Smart Irrigation System, v1.0.0.

Built with PHP 8.2, MySQL/MariaDB, Bootstrap 5.3, Chart.js 4.4 and the Arduino
ESP32 core. Bootstrap and Chart.js are bundled in `assets/vendor/` so the
interface works with no internet connection.

The mobile channel is SMS over a SIM900A, not Blynk — see `docs/BLYNK.md` for
what that means if the project is being assessed against a specification that
requires Blynk.
