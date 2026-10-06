# AgriSense — Test cases and quality evaluation

Two parts:

- **Part A** — the test cases required by the specification, as a checklist you
  can run and sign off.
- **Part B** — the ISO/IEC 25010 evaluation framework, mapped to the features
  that satisfy each characteristic.

Results marked **[verified]** were executed against this build on
Windows 11 / XAMPP (Apache 2.4.56, PHP 8.2.4, MariaDB 10.4.28). Cases marked
**[hardware]** need the assembled ESP32 and are for you to run on the bench.

---

## Part A — Test cases

### A1. Authentication

| # | Case | Steps | Expected | Result |
|---|------|-------|----------|--------|
| 1.1 | Valid login | Sign in as `admin` / `Admin@123` | Dashboard loads, name in the header | [verified] |
| 1.2 | Invalid password | Sign in with a wrong password | `401`, "Invalid username or password", no session | [verified] |
| 1.3 | Unknown username | Sign in as `nobody` | Same message and timing as 1.2 (no user enumeration) | [verified] |
| 1.4 | Inactive account | Set a user to Inactive, then sign in | "This account has been deactivated" | |
| 1.5 | Logout | Use the account menu → Sign out | Redirected to login; back button does not restore the session | |
| 1.6 | Unauthorised page | As `juan` (farmer) open `/users` | `403` "restricted to administrators" | [verified] |
| 1.7 | Unauthenticated API | `GET /api/dashboard` with no session | `401` JSON, no data | [verified] |
| 1.8 | Session fixation | Note the cookie, sign in, compare | Session ID changes on login | |
| 1.9 | Brute force | 5 wrong passwords from one IP | 6th attempt refused for 15 minutes | |
| 1.10 | Idle timeout | Sign in, wait 30 minutes, click a link | Signed out with an explanatory message | |

### A2. Sensors

| # | Case | Steps | Expected | Result |
|---|------|-------|----------|--------|
| 2.1 | Soil moisture reading | Probe in dry soil, then wet | Percentage falls/rises; dashboard updates without a refresh | [hardware] |
| 2.2 | Temperature reading | Compare with a reference thermometer | Within ±0.5 °C | [hardware] |
| 2.3 | Humidity reading | Compare with a reference hygrometer | Within ±5 % | [hardware] |
| 2.4 | Soil sensor failure | Unplug the probe | Raw value rails; firmware reports a failed read, not "0 % dry"; pump does **not** start | [hardware] |
| 2.5 | DHT22 failure | Unplug the DHT22 | After 3 failures the reading is sent as `null`; dashboard keeps the last good value dimmed and marked "not current" | [verified] (API) |
| 2.6 | Null is stored as NULL | `POST /api/sensor-data` with `"temperature": null` | Row stored with SQL `NULL`; averages unaffected | [verified] |
| 2.7 | Out-of-range value | `POST` with `"temperature": 850` | Stored as `NULL`, `warnings` explains why, `abnormal_reading` alert raised | [verified] |
| 2.8 | Empty payload | `POST` with no measurements | `422` with a clear message | [verified] |
| 2.9 | Calibration | Change dry/wet values, compare the percentage for the same raw count | Percentage changes accordingly; device adopts it on next check-in | [verified] (server side) |

### A3. Irrigation

| # | Case | Steps | Expected | Result |
|---|------|-------|----------|--------|
| 3.1 | Moisture below threshold | Let soil dry past the threshold in AUTO | Pump starts; `pump_on` alert; cycle opens | [hardware] |
| 3.2 | Moisture above target | Keep watering to the target | Pump stops; cycle closes with `target_reached` | [hardware] |
| 3.3 | Hysteresis | Hold the reading between threshold and target | Pump keeps its current state — no chattering | [hardware] |
| 3.4 | Manual pump ON | Manual mode → Pump ON → confirm | Command queued, collected, acknowledged; cycle logged as `manual` with the operator's name | [verified] |
| 3.5 | Manual pump OFF | Press Pump OFF while running | Pump stops; cycle closed with `manual_stop` | [verified] |
| 3.6 | Manual ON refused in AUTO | Press Pump ON while in automatic mode | `422` "Switch the device to Manual mode first" | [verified] |
| 3.7 | Maximum runtime | Set max runtime to 30 s and start the pump | Pump stops at 30 s; `max_runtime` alert; cycle closed with that reason | [hardware] |
| 3.8 | Rest between cycles | Try to start again immediately after a cycle | Refused, with the remaining wait shown | [verified] |
| 3.9 | Safe state on sensor loss | Unplug the probe while irrigating in AUTO | Pump stops; `safe_state` critical alert | [hardware] |
| 3.10 | Unauthorised pump control | As `juan` `POST /api/pump/control` | `403`, refusal recorded in the system log | [verified] |
| 3.11 | Offline device | Stop the ESP32, then press Pump ON | Refused — "The device is offline" | [verified] |
| 3.12 | Confirmation prompt | Press Pump ON in the browser | Modal explains what will happen; nothing is sent until confirmed | [verified] |
| 3.13 | Command expiry | Queue a command, keep the device off for 2 min | Command marked `expired`, never runs | |
| 3.14 | Power loss mid-cycle | Cut power to the ESP32 while irrigating | On restart the relay is OFF; the stale cycle is closed as `device_reset` | [hardware] |

### A4. Communication

| # | Case | Steps | Expected | Result |
|---|------|-------|----------|--------|
| 4.1 | Wi-Fi connected | Power on in range | Serial shows IP; dashboard shows Online | [hardware] |
| 4.2 | Wi-Fi disconnected | Switch the router off | Board keeps irrigating on local thresholds; retries every 5 s | [hardware] |
| 4.3 | Wi-Fi restored | Switch the router on | Reconnects unattended; `wifi_failure` alert notes the reconnects | [hardware] |
| 4.4 | Blynk connected | Open the app | Live values; controls work | [hardware] |
| 4.5 | Blynk disconnected | Block Blynk / go offline | Local irrigation and the REST upload are unaffected | [hardware] |
| 4.6 | API unavailable | Stop Apache | Serial logs the failure; the board keeps irrigating; dashboard marks it offline | [hardware] |
| 4.7 | Invalid API key | Change one character in `API_KEY` | `401 Invalid API key`; nothing stored | [verified] |
| 4.8 | Cross-device write | Use device 1's key with `"device_code":"ESP32-002"` | `403`; attempt recorded in the system log | [verified] |
| 4.9 | Inactive device | Set the device Inactive, then upload | `403` with an explanation | |
| 4.10 | Retry safety | Send the same `start` twice | Second returns `"duplicate": true`; only one cycle is recorded | [verified] |

### A5. Database

| # | Case | Steps | Expected | Result |
|---|------|-------|----------|--------|
| 5.1 | Reading insertion | Upload a reading | New `sensor_readings` row with a timestamp | [verified] |
| 5.2 | Irrigation log insertion | Run a cycle | Row opened on start, closed on stop with a computed duration | [verified] |
| 5.3 | Alert insertion | Trigger a low-moisture condition | `alerts` row with type, message and severity | [verified] |
| 5.4 | Historical retrieval | Open Sensor History over 7 days | Paginated, sortable, filterable; matches the database | [verified] |
| 5.5 | No overwriting | Upload many readings | Every row retained; nothing updated in place | [verified] |
| 5.6 | Referential integrity | Delete a device | Its readings, cycles, alerts and commands cascade away; no orphans | [verified] |
| 5.7 | Last admin protection | Try to demote or delete the only administrator | Refused with an explanation | |
| 5.8 | Database down | Stop MySQL, open any page | Friendly "cannot reach the database" page; no credentials or stack trace shown | [verified] |
| 5.9 | Demo/live separation | Check `source` on seeded vs uploaded rows | `demo` vs `device`; purge removes only the demo rows | [verified] |

### A6. Dashboard

| # | Case | Steps | Expected | Result |
|---|------|-------|----------|--------|
| 6.1 | Real-time update | Upload a reading with the dashboard open | Cards change within one poll, no manual refresh | [verified] |
| 6.2 | Charts render | Open the dashboard | Moisture (with threshold lines), temperature, humidity, activity | [verified] |
| 6.3 | Chart filters | Switch Today / Yesterday / 7 / 30 days | Series reloads without a page reload | [verified] |
| 6.4 | Custom date range | Choose Custom and pick two dates | Tables and charts respect the range | [verified] |
| 6.5 | Sensor CSV export | Export from Sensor History | CSV opens in Excel with the filtered rows | [verified] |
| 6.6 | Irrigation CSV export | Export from Irrigation History | Includes start, end, duration, trigger, moisture, threshold | [verified] |
| 6.7 | Reports | Generate all three report types | Correct totals; CSV and Print both work | [verified] |
| 6.8 | Alerts | Mark one / mark all read | Counter updates in the header and sidebar | [verified] |
| 6.9 | Pagination | Page through 600+ readings | Filters and sort survive paging | [verified] |
| 6.10 | Responsive | 360 px, 768 px, 1920 px | Sidebar collapses; no horizontal scrolling; buttons stay tappable | |
| 6.11 | Offline device banner | Let a device go quiet for 2 minutes | Banner appears; pump buttons disable | [verified] |
| 6.12 | No hardcoded values | Empty the readings table | Cards show "--", not sample numbers | [verified] |

### A7. Security

| # | Case | Steps | Expected | Result |
|---|------|-------|----------|--------|
| 7.1 | SQL injection | Search for `' OR 1=1 --` | Treated as literal text; no error, no extra rows | [verified] |
| 7.2 | XSS | Name a device `<script>alert(1)</script>` | Rendered as text, never executed | [verified] |
| 7.3 | CSRF | POST to `/api/pump/control` with no token | `403`, nothing queued | [verified] |
| 7.4 | Password storage | Inspect `users.password` | bcrypt hashes only, no plain text | [verified] |
| 7.5 | Internals blocked | Request `/config/config.php`, `/database/agrisense.sql` | `403` from Apache | [verified] |
| 7.6 | Handler files blocked | Request `/api/handlers/ApiAuth.php` | `403` | [verified] |
| 7.7 | Credentials not exposed | View source on any page | No database credentials or API keys in the HTML or JavaScript | [verified] |
| 7.8 | Error disclosure | Serve the site under a non-localhost name and force an error | Generic message; the detail goes only to the PHP error log. `APP_DEBUG` derives itself, so this needs no edit | [verified] |
| 7.9 | Firmware not downloadable | Request `/esp32/AgriSense.ino` | `403` — the sketch must never be served, it is where the Wi-Fi password and API key live | [verified] |
| 7.10 | API keys hashed | Inspect `devices` | An `api_key_hash` column only; no plaintext key anywhere in the schema | [verified] |
| 7.11 | Default passwords expired | Sign in as `admin` / `Admin@123` on a fresh import | Held on *My account* until a new password is set; every other route and API endpoint refuses | [verified] |
| 7.12 | Password change ends other sessions | Sign in twice, change the password in one | The other session is signed out on its next request | [verified] |
| 7.13 | Account lockout | 10 failed sign-ins for one username from different IPs | Locked for 15 minutes, even from an address with no failures. Other accounts unaffected | [verified] |
| 7.14 | CSV formula injection | Name a device `=cmd\|calc!A1`, export the device report | The cell is written `'=cmd\|calc!A1`, so Excel treats it as text | [verified] |
| 7.15 | Content-Security-Policy | Inspect the response headers | `script-src 'self'` plus a per-request nonce; inline script without the nonce does not run | [verified] |
| 7.16 | Command not lost on a dropped reply | Queue `PUMP_OFF`, poll as the device three times without acknowledging | The command is re-served every time, and stops only once acknowledged | [verified] |

---

## Part B — ISO/IEC 25010 evaluation

Use this as the instrument for the evaluation write-up. Score each item 1–5
(1 = strongly disagree, 5 = strongly agree) with a panel of intended users
(farmers) and IT evaluators, then report the mean per characteristic.

### B1. Functional suitability

*Completeness, correctness, appropriateness.*

1. The system monitors soil moisture, temperature and humidity in real time.
2. The pump starts automatically when soil moisture falls below the threshold.
3. The pump stops automatically when the target moisture is reached.
4. Authorised users can operate the pump manually.
5. Sensor readings and irrigation activity are stored and can be reviewed later.
6. Reports and charts present the data usefully.

**Implemented by:** threshold logic with hysteresis in `runAutomaticIrrigation()`;
`sensor_readings` and `irrigation_logs`; the dashboard, history pages, charts
and the three report types.

### B2. Performance efficiency

*Time behaviour, resource use, capacity.*

1. The dashboard loads quickly.
2. Readings appear without a manual refresh.
3. The system stays responsive with months of accumulated data.

**Implemented by:** indexes on `(device_id, recorded_at)`; server-side
time-bucketed aggregation so a 30-day chart returns ~120 points rather than
~86 000 rows; a small polling payload; polling pauses while the browser tab is
hidden.

**Suggested measurements:** dashboard load time; `/api/dashboard` response
time; query time over 100 000 readings.

### B3. Usability

*Learnability, operability, error protection, aesthetics, accessibility.*

1. The interface is easy to learn without training.
2. Labels and messages are in plain language.
3. Important information (moisture, pump state, device status) is easy to find.
4. The system prevents me from making dangerous mistakes.
5. It is comfortable to use on a phone.

**Implemented by:** an agricultural visual theme; six summary cards; a
confirmation dialogue before starting a real pump; validation messages that say
what to do rather than what failed; large touch targets; Bootstrap 5 responsive
layout.

### B4. Reliability

*Maturity, availability, fault tolerance, recoverability.*

1. The system runs without crashing.
2. It keeps working when Wi-Fi drops.
3. Sensor failures are reported rather than hidden.
4. No data is lost when a device restarts.

**Implemented by:** local irrigation continues without the network; automatic
Wi-Fi reconnection; failed reads stored as `NULL` with the last good value
shown as stale; open cycles reconciled after a reset; duplicate-safe event
endpoints; a maximum-runtime cut-off checked on every loop pass.

### B5. Security

*Confidentiality, integrity, non-repudiation, accountability, authenticity.*

1. Only authorised users can sign in.
2. Only authorised users can operate the pump.
3. Actions are traceable to a user.
4. Data cannot be tampered with through the interface.

**Implemented by:** `password_hash()` / `password_verify()`; role-based access
plus a separate pump-control permission; prepared statements everywhere;
output escaping through `e()`; CSRF tokens; per-device API keys compared in
constant time; session regeneration on login; a `system_logs` audit trail
covering sign-ins, pump commands, configuration changes and refusals.

### B6. Maintainability

*Modularity, reusability, analysability, modifiability, testability.*

1. The code is organised so a feature can be found quickly.
2. Configuration is separate from logic.
3. Changes can be made without breaking other parts.

**Implemented by:** the layout in the README §7; one model per table; shared
business rules in `IrrigationEngine` so the API and the web UI cannot diverge;
all environment settings in `config/config.php`; GPIO pins collected in one
block of the sketch.

### B7. Portability

*Adaptability, installability, replaceability.*

1. The system installs on a standard XAMPP setup.
2. It runs on the available hardware without modification.
3. It can move to another computer or server.

**Implemented by:** stock XAMPP (Apache + PHP 8.2 + MySQL/MariaDB); no PHP
extensions beyond PDO; SQL written to the common MySQL 8 / MariaDB 10.4
dialect; Bootstrap and Chart.js vendored locally so the interface works with
no internet; the base URL derived at runtime so the folder can be renamed.

---

## Part C — Suggested acceptance procedure

1. Import the database and confirm the dashboard renders with sample data.
2. Run every **[verified]** case in Part A to confirm the installation.
3. Assemble the hardware per `WIRING.md` and calibrate the probe.
4. Run the **[hardware]** cases on the bench, with the pump intake in a bucket.
5. Remove the sample data (System Logs → Remove sample data).
6. Run the system in the field for at least seven days.
7. Administer the Part B instrument to the farmer panel and evaluators.
8. Export the sensor and irrigation reports for the trial period as evidence.
