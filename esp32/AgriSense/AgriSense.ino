/*
  AgriSense - IoT-Based Smart Irrigation System (ESP32)

  Hardware
    ESP32 + 2x soil moisture sensor (VCC, GND, DO, AO)
          + MLX90614 infrared thermometer (air temp + non-contact canopy temp)
          + relay module + 12 V water pump
          + WiFi HTTP sync to the AgriSense PHP/MySQL backend

  The DHT22 has been removed; the MLX90614 replaces it. The two are not
  equivalent, and it is worth being clear about what changed:

    - Temperature: now the MLX90614's ambient channel (its own package),
      and reported to the backend as `temperature` exactly as before.
      Accuracy is comparable, BUT the package sits on the board rather
      than out in the open air, so it must be SHADED. In direct sun the
      package heats several degrees above true air temperature.

    - Canopy temperature: new, and something the DHT22 could not measure
      at all - a non-contact reading of the plants themselves.

    - Humidity: GONE. Nothing on the board measures it any more, so no
      humidity is reported. See the note above postSensorData().

  ---------------------------------------------------------------------
  Sync mode: DEVICE-AUTHORITATIVE, with operator override.

    - The ESP32 makes its own irrigation decisions locally, using
      hysteresis. A WiFi outage does not stop irrigation working
      correctly, because the decision is made on-device.

    - Every reading is POSTed to the backend at /api/sensor-data,
      including the pump_status the device has just set. The backend
      records it and updates the dashboard and irrigation logs. It does
      NOT second-guess the automatic decision.

    - The backend MAY queue an explicit operator command (pump on/off,
      mode change) from the web dashboard. The device collects it on its
      next check-in, validates it against its own safety rules, applies
      it and acknowledges. The firmware still has the final say: an
      unsafe command is refused and the refusal is reported back.

    - There is NO offline alert channel. The SIM900A that used to text the
      admin has been removed, so while the WiFi is down the device keeps
      irrigating correctly but tells nobody anything: no start, no stop, no
      safety cut-off, no dead probe. Those events still reach the serial
      console and, once the link returns, the backend - but a fault that
      happens during an outage is only noticed when someone looks at the
      dashboard and sees the device has gone quiet. Alerting is now the
      backend's job: set up its offline-device alert so an unreachable
      board is itself the warning.

  ---------------------------------------------------------------------
  Wiring
    Soil sensor 1: AO -> GPIO34, DO -> GPIO32, VCC -> 3.3V, GND -> GND
    Soil sensor 2: AO -> GPIO35, DO -> GPIO33, VCC -> 3.3V, GND -> GND
    MLX90614:      SDA -> GPIO21, SCL -> GPIO22, VCC -> 3.3V, GND -> GND
                   (3.3 V part - do NOT feed it 5 V; most breakout boards
                    already carry the 4.7k I2C pull-ups, so no extras are
                    needed. Aim it at the canopy from 5-10 cm: the field of
                    view is a cone of roughly 90 degrees, so at 10 cm it
                    averages a circle about 20 cm across - keep soil and sky
                    out of that circle or the reading is pulled towards them.
                    Shade the body of the sensor: it is now the only air
                    temperature the system has, and direct sun on the
                    package makes that reading too high.)
    Relay IN    -> GPIO26
    Free pins:     GPIO4  (was the DHT22 data line)
                   GPIO16, GPIO17 (were the SIM900A's UART2 pair)
    The SIM900A also had its own ~2 A supply. That can come out too.

    Full details and the calibration procedure: docs/WIRING.md

  ---------------------------------------------------------------------
  Libraries required (Arduino IDE -> Library Manager)
    - WiFi, HTTPClient        (built into the ESP32 core)
    - Wire                    (built into the ESP32 core)
    - ArduinoJson             (6.x or 7.x - both work)
    - Adafruit MLX90614 Library (2.1.x)
    - Adafruit BusIO          (dependency of the above)

    The DHT sensor library and Adafruit Unified Sensor are no longer used
    and can be left uninstalled.
*/

#include <WiFi.h>
#include <HTTPClient.h>
#include <ArduinoJson.h>
#include <Wire.h>
#include <Adafruit_MLX90614.h>

// ArduinoJson 6 and 7 declare documents differently; this keeps the same
// sketch compiling against either version.
#if ARDUINOJSON_VERSION_MAJOR >= 7
  #define AGRI_JSON_DOC(name, capacity) JsonDocument name
#else
  #define AGRI_JSON_DOC(name, capacity) StaticJsonDocument<capacity> name
#endif

// ===================== USER CONFIG =====================================

// ---------------------------------------------------------------------
//  SECRETS
//
//  Nothing below is a real credential, and nothing real should be typed
//  here in a copy that will be shared, committed or submitted: this file
//  sits inside the web root, and anything in it is one careless server
//  configuration away from being downloadable.
//
//  For a working board, either fill these in on a copy you keep to
//  yourself, or move them into a `secrets.h` next to this sketch that
//  never leaves your machine:
//
//      // secrets.h
//      #define WIFI_SSID_VALUE     "YourWiFiName"
//      #define WIFI_PASSWORD_VALUE "YourWiFiPassword"
//      #define DEVICE_API_KEY_VALUE "paste-the-64-character-key-here"
//
//  and uncomment the include below.
// ---------------------------------------------------------------------
#include "secrets.h"

#ifndef WIFI_SSID_VALUE
  #define WIFI_SSID_VALUE      "JRA TRAVELLER'S INN"
  #define WIFI_PASSWORD_VALUE  "JRAtravellersinn@2024"
#endif
#ifndef DEVICE_API_KEY_VALUE
  #define DEVICE_API_KEY_VALUE "3750e268605ce4f651633db4a4debbe31c050c9f5b79c78f6195a09fc6c09521"
#endif

// --- WiFi credentials ---
const char* WIFI_SSID     = "JRA TRAVELLER'S INN";
const char* WIFI_PASSWORD = "JRAtravellersinn@2024";

// --- AgriSense backend ---
// Point this at the machine running XAMPP, including the project folder.
// Use its LAN IP address: to the ESP32, "localhost" means the ESP32.
// Find it with `ipconfig` on Windows. Apache serves on port 80, so no
// port number is needed.
//   Dev:  http://192.168.1.10/AgriSense
//   Prod: https://agrisense.example.com
// The address is handed out by DHCP, so it can change when the router
// restarts - re-check with `ipconfig` if the device suddenly starts
// logging "-1" again.
const char* AGRISENSE_BASE = "http://192.168.1.17/AgriSense";

// Must match the API key shown in the dashboard under Devices -> Edit.
// The dashboard shows a key once, when it is generated: only a hash of it
// is stored, so copy it at that moment or generate another.
const char* DEVICE_API_KEY = DEVICE_API_KEY_VALUE;

// Must match device_code in the dashboard, exactly.
const char* DEVICE_ID = "AGRISENSE-ESP32-01";

// Another major: 3.x still texted the admin when the network was down, and
// 4.0.0 does not. What the board reports, and how, has changed - not just
// what it measures.
const char* FIRMWARE_VERSION = "4.1.0";

// --- Temperature (MLX90614) ---------------------------------------------
// The infrared thermometer reports two figures: the temperature of whatever
// it is pointed at (the canopy) and the temperature of its own package (the
// surrounding air). Since the DHT22 was removed, the second of those is the
// system's only air temperature - so keep the sensor shaded.
//
// The difference between the two is the interesting one: a well-watered
// plant transpires and runs at or below air temperature, while a
// water-stressed one closes its stomata and heats up above it. That is the
// basis of the crop water stress index used in irrigation research.
//
// This build MEASURES and REPORTS the canopy temperature; it deliberately
// does not let it start or stop the pump. Soil moisture stays the single
// input to the irrigation decision, so a mis-aimed IR sensor - one that has
// drifted round to face bare soil or open sky - cannot flood or starve the
// field. Canopy temperature goes to the dashboard as an early warning that
// a human interprets.
//
// With MLX_ENABLED false the board reports soil moisture only; there is no
// longer a second temperature sensor to fall back on.
const bool  MLX_ENABLED       = true;   // set false if no MLX90614 is fitted
const float MLX_TEMP_MIN      = -40.0;  // sensor spec, both channels
const float MLX_TEMP_MAX      = 125.0;
// Canopy this far above air temperature is reported as heat/water stress.
const float LEAF_STRESS_DELTA = 5.0;    // degrees C above ambient

// --- Fallback thresholds ------------------------------------------------
// Used only until the backend supplies the real values. Once the device is
// online the dashboard is the source of truth, and a change there takes
// effect on the next check-in with no re-flash.
//
// The dashboard works in percentages, because that is what a farmer can
// reason about. Converting with the shared calibration formula
//     percent = (SOIL_RAW_DRY - raw) * 100 / (SOIL_RAW_DRY - SOIL_RAW_WET)
// the raw set points this project was tuned with map as:
//     raw 2800  ->  20%   (at/below this = dry  -> pump ON)
//     raw 2000  ->  60%   (at/above this = wet  -> pump OFF)
// The gap between the two is the hysteresis band.
const float DEFAULT_MOISTURE_THRESHOLD = 20.0;  // pump ON at or below this %
const float DEFAULT_TARGET_MOISTURE    = 60.0;  // pump OFF at or above this %
const int   SOIL_RAW_DRY               = 3200;  // raw value for 0%   moisture
const int   SOIL_RAW_WET               = 1200;  // raw value for 100% moisture

// --- Safety -------------------------------------------------------------
// The pump stops after this long even if the target is never reached.
// This is the single most important safety rule in the system: without it
// a stuck or failed probe would leave the pump running indefinitely.
const uint32_t DEFAULT_MAX_PUMP_RUNTIME   = 300;  // seconds
// Minimum rest after a cycle ends, so water has time to soak in.
const uint32_t DEFAULT_MIN_CYCLE_INTERVAL = 600;  // seconds

// --- Timing -------------------------------------------------------------
const unsigned long SENSOR_READ_INTERVAL_MS   = 5000;   // read sensors every 5 s
// Starting point for the telemetry cadence, used until the first settings
// reply arrives; from then on settings.readingIntervalMs (set from the
// dashboard's "Sensor reading interval") takes over. Matches the backend's
// own default of 30 s (IrrigationSetting.php) rather than the previous
// fixed 15 s, since that default is what a fresh install actually runs.
const unsigned long DEFAULT_READING_INTERVAL_MS = 30000;
const unsigned long SETTINGS_POLL_MS          = 30000;  // poll config + commands
const unsigned long WIFI_RETRY_MS             = 15000;  // between reconnect attempts
// =======================================================================

// ---------- Pin definitions ----------
#define SOIL1_AO      34   // ADC1_CH6 - ADC1 only; ADC2 pins die when WiFi is on
#define SOIL1_DO      32
#define SOIL2_AO      35   // ADC1_CH7
#define SOIL2_DO      33
#define RELAY_PIN     26

// I2C for the MLX90614. These are the ESP32's default I2C pins; any free
// pair works as long as Wire.begin() below is given the same two.
#define I2C_SDA       21
#define I2C_SCL       22
// The MLX90614's SMBus interface is specified up to 100 kHz. The ESP32
// defaults to 100 kHz too, but it is set explicitly here because a shared
// bus with a faster device would otherwise leave this one out of spec.
#define I2C_FREQ      100000

// This relay board switches ON when its input is driven HIGH. Set to false
// for the common active-LOW opto-isolated boards.
#define RELAY_ACTIVE_HIGH  false

// ---------- Objects ----------
Adafruit_MLX90614 mlx = Adafruit_MLX90614();

// ---------- Configuration mirrored from the backend ----------
struct Settings {
  float    moistureThreshold = DEFAULT_MOISTURE_THRESHOLD;
  float    targetMoisture    = DEFAULT_TARGET_MOISTURE;
  int      dryValue          = SOIL_RAW_DRY;
  int      wetValue          = SOIL_RAW_WET;
  bool     autoIrrigation    = true;
  uint32_t maxPumpRuntime    = DEFAULT_MAX_PUMP_RUNTIME;
  uint32_t minCycleInterval  = DEFAULT_MIN_CYCLE_INTERVAL;
  // "Sensor reading interval" on the dashboard - how often a telemetry row
  // is uploaded. Kept in milliseconds here because that is what loop()'s
  // millis() scheduling needs.
  unsigned long readingIntervalMs = DEFAULT_READING_INTERVAL_MS;
};
Settings settings;

// ---------- Runtime state ----------
struct State {
  // Raw ADC, -1 when the probe did not give a usable reading.
  int   soil1Raw = -1;
  int   soil2Raw = -1;
  // Calibrated percentages. NAN means "no reading".
  float soil1Pct = NAN;
  float soil2Pct = NAN;
  float avgPct   = NAN;      // the figure irrigation decisions act on

  // MLX90614 infrared thermometer. NAN means "no reading".
  float   airTemp     = NAN;   // ambient channel - the package temperature
  float   leafTemp    = NAN;   // object channel  - the canopy
  uint8_t mlxFailures = 0;
  bool    mlxPresent  = false; // false until begin() finds it on the bus
  bool    leafStress  = false; // canopy is LEAF_STRESS_DELTA above air

  bool     pumpRunning   = false;
  uint32_t pumpStartedAt = 0;
  uint32_t lastCycleEnd  = 0;
  bool     hadCycle      = false;

  uint16_t wifiReconnects = 0;
  bool     backendOk      = false;

  unsigned long lastReadTime     = 0;
  unsigned long lastPostTime     = 0;
  unsigned long lastSettingsTime = 0;
  unsigned long lastWifiTry      = 0;
} state;

// ---------- Deferred operator command ----------
//
// A command arriving in an HTTP reply is parked here rather than executed
// on the spot. Acting inside the response parser used to re-enter the
// whole network path - handleCommand -> setPump -> postSensorData ->
// processResponse -> handleCommand - which nested HTTPClient and
// JsonDocument frames on a small stack and let a later command be applied
// and acknowledged before the one that triggered it.
//
// One slot is enough: the backend supersedes any outstanding command when
// a newer one is queued, so there is never more than one to act on.
struct PendingCommand {
  bool waiting = false;
  long id      = -1;
  char name[20] = {0};
} pendingCommand;

// ---------- Forward declarations ----------
void writeRelay(bool on);
void initMlx();
void readSensors();
void handleIrrigation();
void enforcePumpSafety();
void ensureWifi();
void runPendingCommand();
void fetchSettings();
void postStatus();
bool postSensorData(const char* triggerLabel);
bool postIrrigationLog(const char* action, const char* triggerType,
                       const char* stopReason, long commandId);
void acknowledgeCommand(long commandId, bool ok, const char* message);

// ---------------------------------------------------------------------
//  SETUP
// ---------------------------------------------------------------------
void setup() {
  Serial.begin(115200);
  delay(200);
  Serial.println();
  Serial.println("======================================================");
  Serial.println("  AgriSense - IoT-Based Smart Irrigation System");
  Serial.print  ("  Firmware v"); Serial.println(FIRMWARE_VERSION);
  Serial.print  ("  Device ID:  "); Serial.println(DEVICE_ID);
  Serial.println("======================================================");

  // Relay first, and OFF. Doing this before anything else means a reset
  // can never leave the pump running while the board boots.
  pinMode(RELAY_PIN, OUTPUT);
  writeRelay(false);
  Serial.println("[init] Relay initialised - pump OFF");

  pinMode(SOIL1_DO, INPUT);
  pinMode(SOIL2_DO, INPUT);
  analogSetPinAttenuation(SOIL1_AO, ADC_11db);   // full 0-3.3 V range
  analogSetPinAttenuation(SOIL2_AO, ADC_11db);

  initMlx();
  Serial.println("[init] Sensors initialised");

  ensureWifi();

  // Adopt the dashboard's configuration before making any decision.
  if (WiFi.status() == WL_CONNECTED) {
    fetchSettings();
    postStatus();
  }

  Serial.printf("[init] Active config: pump ON <= %.1f%%, OFF >= %.1f%%, mode %s, "
                "max runtime %lu s, rest %lu s, reading interval %lu s\n",
                settings.moistureThreshold, settings.targetMoisture,
                settings.autoIrrigation ? "AUTO" : "MANUAL",
                (unsigned long)settings.maxPumpRuntime,
                (unsigned long)settings.minCycleInterval,
                settings.readingIntervalMs / 1000);

  // Take a first reading immediately rather than waiting an interval.
  readSensors();
  Serial.println("[init] AgriSense system ready.");
}

// ---------------------------------------------------------------------
//  MAIN LOOP
//
//  Scheduled with millis(). With the SIM900A's multi-second AT-command
//  delays gone, the only blocking waits left are the few ms between ADC
//  samples and an HTTP request that reaches its 6 s timeout.
// ---------------------------------------------------------------------
void loop() {
  // 1. Safety first, on every pass.
  enforcePumpSafety();

  // 2. Keep the network up (non-blocking after the first attempt).
  ensureWifi();

  // 3. Carry out any operator command collected by the last reply. Done
  //    here, at the top level, so its own HTTP calls never nest inside
  //    the request that delivered it.
  runPendingCommand();

  unsigned long now = millis();

  // 4. Read the sensors and act on them.
  if (now - state.lastReadTime >= SENSOR_READ_INTERVAL_MS) {
    state.lastReadTime = now;

    readSensors();

    Serial.printf("Soil1 AO=%d (%.1f%%) DO=%d | Soil2 AO=%d (%.1f%%) DO=%d | "
                  "avg=%.1f%% | air=%.1fC leaf=%.1fC%s | pump %s | %s\n",
                  state.soil1Raw, isnan(state.soil1Pct) ? -1.0 : state.soil1Pct,
                  digitalRead(SOIL1_DO),
                  state.soil2Raw, isnan(state.soil2Pct) ? -1.0 : state.soil2Pct,
                  digitalRead(SOIL2_DO),
                  isnan(state.avgPct) ? -1.0 : state.avgPct,
                  isnan(state.airTemp) ? -1.0 : state.airTemp,
                  isnan(state.leafTemp) ? -1.0 : state.leafTemp,
                  state.leafStress ? " STRESS" : "",
                  state.pumpRunning ? "ON" : "OFF",
                  settings.autoIrrigation ? "AUTO" : "MANUAL");

    handleIrrigation();
  }

  // 5. Periodic telemetry, independent of state changes, so the dashboard
  //    keeps receiving fresh readings even when the pump is not switching.
  if (now - state.lastPostTime >= settings.readingIntervalMs) {
    state.lastPostTime = now;
    postSensorData("periodic");
  }

  // 6. Poll for configuration changes and operator commands.
  if (now - state.lastSettingsTime >= SETTINGS_POLL_MS) {
    state.lastSettingsTime = now;
    if (WiFi.status() == WL_CONNECTED) {
      fetchSettings();
    }
  }
}

// ---------------------------------------------------------------------
//  SENSOR READING
// ---------------------------------------------------------------------

/** Average several ADC samples to smooth out probe noise. */
int readSoilRaw(int pin) {
  long sum = 0;
  const int samples = 10;
  for (int i = 0; i < samples; i++) {
    sum += analogRead(pin);
    delay(10);
  }
  return sum / samples;
}

/**
 * Convert a raw ADC count to a percentage, clamped to [0, 100].
 *
 * Identical to the formula the PHP backend uses, so a percentage means
 * the same thing on the device and on the dashboard.
 */
float rawToPercent(int raw) {
  if (settings.dryValue == settings.wetValue) return NAN;
  float pct = (float)(settings.dryValue - raw) * 100.0f
              / (float)(settings.dryValue - settings.wetValue);
  if (pct < 0)   pct = 0;
  if (pct > 100) pct = 100;
  return pct;
}

/**
 * Read one probe. A disconnected probe leaves the ADC railed near 0 or
 * 4095; that is reported as "no reading" rather than as bone-dry soil,
 * which would otherwise start the pump.
 */
float readProbe(int pin, int& rawOut) {
  int raw = readSoilRaw(pin);
  if (raw <= 20 || raw >= 4080) {
    Serial.printf("[sensor] Probe on GPIO%d reads %d - disconnected?\n", pin, raw);
    rawOut = -1;
    return NAN;
  }
  rawOut = raw;
  return rawToPercent(raw);
}

/**
 * Bring up the MLX90614 on the I2C bus.
 *
 * A missing or mis-wired sensor is not fatal: mlxPresent stays false, the
 * readings are published as null, and irrigation carries on untouched. The
 * bus is started here rather than in setup() so a re-probe is one call.
 */
void initMlx() {
  if (!MLX_ENABLED) return;

  Wire.begin(I2C_SDA, I2C_SCL, I2C_FREQ);

  if (!mlx.begin()) {
    state.mlxPresent = false;
    Serial.println("[init] MLX90614 not found on I2C - leaf temperature disabled");
    return;
  }

  state.mlxPresent = true;
  Serial.printf("[init] MLX90614 ready (emissivity %.2f)\n", mlx.readEmissivity());
}

/**
 * Read the infrared thermometer - air temperature and canopy temperature.
 *
 * An occasional bad sample keeps the last good values, and only three
 * consecutive failures publish the reading as missing, so one dropped I2C
 * transaction cannot put a gap in the history. A read over a broken bus
 * returns NAN rather than blocking.
 */
void readMlx() {
  if (!MLX_ENABLED) return;

  // The sensor was absent at boot, or the bus dropped out. Retry it here so
  // a loose connector that is pushed back in recovers without a reset.
  if (!state.mlxPresent) {
    if (!mlx.begin()) return;
    state.mlxPresent = true;
    Serial.println("[sensor] MLX90614 is back on the bus");
  }

  float object  = mlx.readObjectTempC();
  float ambient = mlx.readAmbientTempC();

  bool bad = isnan(object) || isnan(ambient) ||
             object  < MLX_TEMP_MIN || object  > MLX_TEMP_MAX ||
             ambient < MLX_TEMP_MIN || ambient > MLX_TEMP_MAX;

  if (bad) {
    state.mlxFailures++;
    Serial.printf("[sensor] MLX90614 read failed (%u in a row)\n", state.mlxFailures);
    if (state.mlxFailures >= 3) {
      state.airTemp    = NAN;
      state.leafTemp   = NAN;
      state.leafStress = false;
      state.mlxPresent = false;   // force a re-probe on the next pass
    }
    return;
  }

  state.mlxFailures = 0;
  state.airTemp     = ambient;
  state.leafTemp    = object;

  // Both figures come from the same sensor, so this comparison is immune to
  // the package running warm in the sun: an offset that lifts the ambient
  // channel is not present in the object channel, which makes the delta
  // conservative (it under-reports stress) rather than alarmist.
  bool stressed = (object - ambient) >= LEAF_STRESS_DELTA;

  if (stressed && !state.leafStress) {
    Serial.printf("[sensor] Canopy %.1fC is %.1fC above air %.1fC - possible water stress\n",
                  object, object - ambient, ambient);
  }
  state.leafStress = stressed;
}

/** Take a full set of readings and compute the working average. */
void readSensors() {
  state.soil1Pct = readProbe(SOIL1_AO, state.soil1Raw);
  state.soil2Pct = readProbe(SOIL2_AO, state.soil2Raw);

  // Average whichever probes are working. With one probe dead the system
  // carries on using the other rather than stopping altogether.
  if (!isnan(state.soil1Pct) && !isnan(state.soil2Pct)) {
    state.avgPct = (state.soil1Pct + state.soil2Pct) / 2.0f;
  } else if (!isnan(state.soil1Pct)) {
    state.avgPct = state.soil1Pct;
  } else if (!isnan(state.soil2Pct)) {
    state.avgPct = state.soil2Pct;
  } else {
    state.avgPct = NAN;      // both probes gone - see handleIrrigation()
  }

  readMlx();
}

// ---------------------------------------------------------------------
//  PUMP CONTROL
// ---------------------------------------------------------------------

/** Drive the relay pin, honouring RELAY_ACTIVE_HIGH. */
void writeRelay(bool on) {
  bool level = RELAY_ACTIVE_HIGH ? on : !on;
  digitalWrite(RELAY_PIN, level ? HIGH : LOW);
}

/** True when the mandatory rest between two cycles has elapsed. */
bool restPeriodSatisfied() {
  if (!state.hadCycle) return true;          // nothing has run since boot
  return (millis() - state.lastCycleEnd) >= (settings.minCycleInterval * 1000UL);
}

/**
 * Switch the pump and record it in both places that remain: the serial
 * console and the backend irrigation log.
 *
 * @param on          desired state
 * @param triggerType "automatic" or "manual" (used when starting)
 * @param stopReason  why the cycle ended (used when stopping)
 * @param commandId   the operator command this fulfils, or -1
 */
void setPump(bool on, const char* triggerType, const char* stopReason, long commandId) {
  if (on == state.pumpRunning) return;       // already in that state

  writeRelay(on);
  state.pumpRunning = on;

  String moistureText = isnan(state.avgPct) ? String("n/a") : String(state.avgPct, 1) + "%";

  if (on) {
    state.pumpStartedAt = millis();
    Serial.printf("PUMP ON  (%s) at %s (S1=%d S2=%d)\n",
                  triggerType, moistureText.c_str(), state.soil1Raw, state.soil2Raw);

    postIrrigationLog("start", triggerType, nullptr, commandId);
  } else {
    uint32_t ranFor = (millis() - state.pumpStartedAt) / 1000;
    state.lastCycleEnd = millis();
    state.hadCycle     = true;
    Serial.printf("PUMP OFF (%s) after %lu s at %s\n",
                  stopReason, (unsigned long)ranFor, moistureText.c_str());

    postIrrigationLog("stop", nullptr, stopReason, commandId);
  }

  // The log upload can sit on its 6 s timeout when the backend is slow or
  // unreachable, so re-check the runtime cut-off straight away rather than
  // waiting for the next loop pass.
  enforcePumpSafety();

  // Push the transition immediately so the dashboard reflects it without
  // waiting for the next periodic upload.
  postSensorData(on ? "pump_on" : "pump_off");
}

/**
 * Automatic irrigation with hysteresis.
 *
 *   moisture <= threshold  -> pump ON
 *   moisture >= target     -> pump OFF
 *   in between             -> keep the current state
 *
 * The gap between the two set points is what stops the pump chattering
 * on and off as the reading wobbles around a single value.
 */
void handleIrrigation() {
  if (!settings.autoIrrigation) {
    return;      // manual mode - the operator is in charge
  }

  // Safe state: with no moisture reading we cannot tell when to stop, so
  // we never start, and we stop a cycle that is already running.
  if (isnan(state.avgPct)) {
    if (state.pumpRunning) {
      Serial.println("SAFETY: both soil probes lost while irrigating - stopping the pump");
      setPump(false, nullptr, "sensor_failure", -1);
    }
    return;
  }

  if (!state.pumpRunning) {
    if (state.avgPct <= settings.moistureThreshold) {
      if (!restPeriodSatisfied()) {
        Serial.printf("Soil dry (%.1f%%) but the rest period has not elapsed - waiting\n",
                      state.avgPct);
        return;
      }
      setPump(true, "automatic", nullptr, -1);
    }
    return;
  }

  if (state.avgPct >= settings.targetMoisture) {
    setPump(false, nullptr, "target_reached", -1);
  }
}

/**
 * Hard maximum-runtime cut-off. Checked on every loop pass, independently
 * of the sensor timer, so a stalled sensor or a stuck reading can never
 * leave the pump running indefinitely.
 *
 * The relay is dropped and the state updated FIRST, before any logging, so
 * that a failure in the reporting path can never leave the pump energised.
 */
void enforcePumpSafety() {
  if (!state.pumpRunning) return;

  uint32_t runningFor = (millis() - state.pumpStartedAt) / 1000;
  if (runningFor < settings.maxPumpRuntime) return;

  Serial.printf("SAFETY: maximum runtime of %lu s reached - stopping the pump\n",
                (unsigned long)settings.maxPumpRuntime);

  writeRelay(false);
  state.pumpRunning  = false;
  state.lastCycleEnd = millis();
  state.hadCycle     = true;

  postIrrigationLog("stop", nullptr, "max_runtime", -1);
  postSensorData("max_runtime");
}

// ---------------------------------------------------------------------
//  BACKEND API
// ---------------------------------------------------------------------

/**
 * Send a request to the AgriSense backend.
 *
 * @param path     path under AGRISENSE_BASE, e.g. "/api/sensor-data"
 * @param method   "GET" or "POST"
 * @param payload  JSON body for POST
 * @param response filled with the response body when not null
 */
bool apiRequest(const String& path, const char* method,
                const String& payload, String* response) {
  if (WiFi.status() != WL_CONNECTED) {
    // Irrigation itself is unaffected - the decision is made on-device -
    // but nothing is reported anywhere until the link comes back.
    Serial.printf("[http] WiFi down - skipping %s %s (local pump logic unaffected)\n",
                  method, path.c_str());
    state.backendOk = false;
    return false;
  }

  HTTPClient http;
  String url = String(AGRISENSE_BASE) + path;

  if (!http.begin(url)) {
    Serial.printf("[http] could not open a connection to %s\n", url.c_str());
    state.backendOk = false;
    return false;
  }

  http.setTimeout(6000);
  http.addHeader("X-DEVICE-KEY", DEVICE_API_KEY);
  http.addHeader("Accept", "application/json");

  int code;
  if (strcmp(method, "GET") == 0) {
    code = http.GET();
  } else {
    http.addHeader("Content-Type", "application/json");
    code = http.POST(payload);
  }

  String body = http.getString();
  if (response != nullptr) *response = body;

  bool ok = (code >= 200 && code < 300);
  if (!ok) {
    // The server's message explains most misconfigurations: wrong API key,
    // device set to inactive, or DEVICE_ID not matching the key's device.
    Serial.printf("[http] %s %s -> %d: %s\n", method, path.c_str(), code, body.c_str());
  }

  http.end();
  state.backendOk = ok;
  return ok;
}

/** Adopt configuration sent by the backend, after re-checking it locally. */
void applySettings(JsonObjectConst src) {
  if (src.isNull()) return;

  float    threshold      = src["moisture_threshold"] | settings.moistureThreshold;
  float    target         = src["target_moisture"]    | settings.targetMoisture;
  int      dry            = src["dry_value"]          | settings.dryValue;
  int      wet            = src["wet_value"]          | settings.wetValue;
  bool     autoMode       = src["auto_irrigation"]    | settings.autoIrrigation;
  uint32_t maxRun         = src["max_pump_runtime"]   | settings.maxPumpRuntime;
  uint32_t rest           = src["min_cycle_interval"] | settings.minCycleInterval;
  uint32_t readingSeconds = src["reading_interval"]   | (settings.readingIntervalMs / 1000);

  // The dashboard validates all of this, but a device that blindly trusts
  // whatever arrives over the network could be made to run a pump dry.
  // Every field that is adopted below is checked here - including the rest
  // period, where an absurd value would silently stop the field ever being
  // watered again. The reading-interval bound (5-3600 s) mirrors
  // IrrigationSetting::validate() on the backend.
  bool valid = (threshold >= 1.0 && threshold <= 95.0) &&
               (target > threshold) && (target <= 100.0) &&
               (dry - wet >= 100) &&
               (maxRun >= 10 && maxRun <= 3600) &&
               (rest <= 86400) &&
               (readingSeconds >= 5 && readingSeconds <= 3600);

  if (!valid) {
    Serial.println("[config] Rejected settings from the server - failed the local safety check");
    return;
  }

  if (autoMode != settings.autoIrrigation) {
    Serial.printf("[config] Mode changed to %s\n", autoMode ? "AUTOMATIC" : "MANUAL");
  }

  settings.moistureThreshold = threshold;
  settings.targetMoisture    = target;
  settings.dryValue          = dry;
  settings.wetValue          = wet;
  settings.autoIrrigation    = autoMode;
  settings.maxPumpRuntime    = maxRun;
  settings.minCycleInterval  = rest;
  settings.readingIntervalMs = readingSeconds * 1000UL;
}

/**
 * Carry out an operator command queued on the dashboard.
 *
 * The firmware keeps the final say: a command that would be unsafe is
 * refused, and the refusal is reported back so the dashboard can show the
 * operator why nothing happened.
 */
void handleCommand(long commandId, const char* command) {
  Serial.printf("[cmd] #%ld received: %s\n", commandId, command);

  if (strcmp(command, "PUMP_ON") == 0) {
    if (state.pumpRunning) {
      // setPump() would return without doing anything; say so rather than
      // reporting an action that did not happen.
      acknowledgeCommand(commandId, true, "The pump was already running");
      return;
    }
    if (settings.autoIrrigation) {
      acknowledgeCommand(commandId, false,
        "Device is in automatic mode - switch to manual first");
      return;
    }
    if (!restPeriodSatisfied()) {
      acknowledgeCommand(commandId, false,
        "The minimum rest period between irrigation cycles has not elapsed");
      return;
    }
    setPump(true, "manual", nullptr, commandId);
    acknowledgeCommand(commandId, true, "Pump switched on");

  } else if (strcmp(command, "PUMP_OFF") == 0) {
    // Always honoured - this is the emergency stop.
    if (!state.pumpRunning) {
      acknowledgeCommand(commandId, true, "The pump was already off");
      return;
    }
    setPump(false, nullptr, "manual_stop", commandId);
    acknowledgeCommand(commandId, true, "Pump switched off");

  } else if (strcmp(command, "MODE_AUTO") == 0) {
    settings.autoIrrigation = true;
    Serial.println("[cmd] Switched to AUTOMATIC mode");
    acknowledgeCommand(commandId, true, "Automatic mode enabled");

  } else if (strcmp(command, "MODE_MANUAL") == 0) {
    settings.autoIrrigation = false;
    Serial.println("[cmd] Switched to MANUAL mode");
    acknowledgeCommand(commandId, true, "Manual mode enabled");

  } else {
    acknowledgeCommand(commandId, false, "Unknown command");
  }
}

/** Pull settings and any queued command out of a backend response. */
void processResponse(const String& body) {
  if (body.length() == 0) return;

  // 3072, not 1536. The /api/sensor-data reply is the largest the device
  // sees - `stored` (9 fields) plus `settings` (8) plus `warnings` plus
  // `command` plus the message - and on ArduinoJson 6 the old pool could
  // fail with NoMemory, which silently dropped both the settings update
  // and any queued command. Ignored by ArduinoJson 7, which grows as
  // needed; the ESP32 has the RAM either way.
  AGRI_JSON_DOC(doc, 3072);
  DeserializationError err = deserializeJson(doc, body);
  if (err) {
    Serial.printf("[http] could not parse the response: %s\n", err.c_str());
    return;
  }

  if (!doc["settings"].isNull()) {
    applySettings(doc["settings"].as<JsonObjectConst>());
  }

  if (doc["command"].isNull()) return;

  long        id  = doc["command"]["id"] | -1L;
  const char* cmd = doc["command"]["command"] | "";
  if (id >= 0 && strlen(cmd) > 0) {
    // Park it for loop() instead of acting here - see PendingCommand.
    // A command still waiting is overwritten: the backend only ever has
    // one outstanding, and the newer instruction is the one that counts.
    pendingCommand.waiting = true;
    pendingCommand.id      = id;
    strncpy(pendingCommand.name, cmd, sizeof(pendingCommand.name) - 1);
    pendingCommand.name[sizeof(pendingCommand.name) - 1] = '\0';
  }
}

/**
 * Execute a command parked by processResponse().
 *
 * Called only from loop(), at the top level, so the HTTP calls that
 * handleCommand() makes can never nest inside another HTTP call.
 */
void runPendingCommand() {
  if (!pendingCommand.waiting) return;

  // Clear the slot first. If handling triggers another command in a reply,
  // that one lands in a fresh slot and runs on the next pass rather than
  // recursing into this one.
  pendingCommand.waiting = false;
  long id = pendingCommand.id;
  char cmd[sizeof(pendingCommand.name)];
  strncpy(cmd, pendingCommand.name, sizeof(cmd));
  cmd[sizeof(cmd) - 1] = '\0';

  handleCommand(id, cmd);
}

/**
 * Upload one set of readings.
 *
 * A probe or sensor that failed is sent as null, never as 0 or -1: the
 * backend stores SQL NULL, keeps it out of every average, and shows the
 * last good value on the dashboard marked as not current.
 */
bool postSensorData(const char* triggerLabel) {
  // 768, with room to spare at 12 members: on ArduinoJson 6 a pool that
  // runs out does not fail loudly - it silently drops whichever field did
  // not fit.
  AGRI_JSON_DOC(doc, 768);
  doc["device_id"] = DEVICE_ID;

  // Primary reading, the one the pump decision was made on.
  if (isnan(state.avgPct)) doc["soil_moisture"] = nullptr;
  else                     doc["soil_moisture"] = roundf(state.avgPct * 10) / 10.0;

  // Each probe separately, so a failing probe is visible on the dashboard
  // instead of being hidden inside the average.
  if (isnan(state.soil1Pct)) doc["soil_moisture_1"] = nullptr;
  else                       doc["soil_moisture_1"] = roundf(state.soil1Pct * 10) / 10.0;

  if (isnan(state.soil2Pct)) doc["soil_moisture_2"] = nullptr;
  else                       doc["soil_moisture_2"] = roundf(state.soil2Pct * 10) / 10.0;

  if (state.soil1Raw >= 0) doc["soil_raw"]   = state.soil1Raw;
  if (state.soil2Raw >= 0) doc["soil_raw_2"] = state.soil2Raw;

  // MLX90614, both channels. Sent only when the sensor is fitted: a board
  // built without one stays silent on these fields rather than reporting a
  // permanent failure the operator can do nothing about. A fitted sensor
  // that has stopped answering does send null, because that IS worth
  // seeing.
  //
  // `temperature` keeps its meaning - the ambient air - so the dashboard's
  // existing temperature card and charts need no change. `leaf_temperature`
  // is the new canopy reading.
  //
  // There is deliberately no `humidity` field. The DHT22 that measured it
  // is gone and nothing replaced it, so the field is OMITTED rather than
  // sent as null: in this API null means "the sensor is fitted and failed",
  // which would raise a sensor_failure alert on every single reading.
  // Untick "DHT22 fitted" on the device in the dashboard (Devices -> Edit)
  // to stop the backend expecting one.
  if (MLX_ENABLED) {
    if (isnan(state.airTemp)) doc["temperature"] = nullptr;
    else                      doc["temperature"] = roundf(state.airTemp * 10) / 10.0;

    if (isnan(state.leafTemp)) doc["leaf_temperature"] = nullptr;
    else                       doc["leaf_temperature"] = roundf(state.leafTemp * 10) / 10.0;

    doc["leaf_stress"] = state.leafStress;
  }

  doc["pump_status"]      = state.pumpRunning ? "ON" : "OFF";
  doc["mode"]             = settings.autoIrrigation ? "AUTO" : "MANUAL";
  doc["firmware_version"] = FIRMWARE_VERSION;

  String payload, response;
  serializeJson(doc, payload);

  bool ok = apiRequest("/api/sensor-data", "POST", payload, &response);
  Serial.printf("[http] POST /api/sensor-data (%s) -> %s\n", triggerLabel, ok ? "ok" : "failed");

  // The reply carries the current settings and any queued command, so one
  // round trip keeps the device fully in step.
  if (ok) processResponse(response);
  return ok;
}

/** Report the start or end of an irrigation cycle. */
bool postIrrigationLog(const char* action, const char* triggerType,
                       const char* stopReason, long commandId) {
  AGRI_JSON_DOC(doc, 384);
  doc["device_id"] = DEVICE_ID;
  doc["action"]    = action;

  if (!isnan(state.avgPct))   doc["soil_moisture"] = roundf(state.avgPct * 10) / 10.0;
  if (triggerType != nullptr) doc["trigger_type"]  = triggerType;
  if (stopReason  != nullptr) doc["stop_reason"]   = stopReason;
  if (commandId >= 0)         doc["command_id"]    = commandId;

  String payload;
  serializeJson(doc, payload);
  return apiRequest("/api/irrigation/log", "POST", payload, nullptr);
}

/** Confirm to the backend that a queued command was carried out. */
void acknowledgeCommand(long commandId, bool ok, const char* message) {
  if (commandId < 0) return;

  AGRI_JSON_DOC(doc, 256);
  doc["device_id"]  = DEVICE_ID;
  doc["command_id"] = commandId;
  doc["success"]    = ok;
  doc["message"]    = message;

  String payload;
  serializeJson(doc, payload);
  apiRequest("/api/device/command/ack", "POST", payload, nullptr);
}

/** Fetch configuration and collect any queued command. */
void fetchSettings() {
  String response;
  String path = String("/api/device/") + DEVICE_ID + "/settings";
  if (apiRequest(path, "GET", "", &response)) {
    processResponse(response);
  }
}

/** Heartbeat, sent on boot and after a reconnect. */
void postStatus() {
  AGRI_JSON_DOC(doc, 320);
  doc["device_id"]        = DEVICE_ID;
  doc["firmware_version"] = FIRMWARE_VERSION;
  doc["wifi_reconnects"]  = state.wifiReconnects;
  doc["rssi"]             = WiFi.RSSI();
  doc["uptime_seconds"]   = millis() / 1000;

  // Soil first: it is the reading irrigation actually depends on. The
  // MLX90614 is reported second because, however useful its numbers are,
  // irrigation runs perfectly well without it.
  if (state.soil1Raw < 0 && state.soil2Raw < 0) {
    doc["sensor_error"] = "Both soil probes are not responding";
  } else if (MLX_ENABLED && !state.mlxPresent) {
    doc["sensor_error"] = "MLX90614 is not responding";
  }

  String payload, response;
  serializeJson(doc, payload);
  if (apiRequest("/api/device/status", "POST", payload, &response)) {
    processResponse(response);
  }
}

// ---------------------------------------------------------------------
//  WIFI
// ---------------------------------------------------------------------

/**
 * Keep the WiFi link up.
 *
 * The first call (from setup) waits for a connection; later calls return
 * immediately after re-arming a retry, so the pump safety check in loop()
 * is never blocked by a dead network.
 */
void ensureWifi() {
  if (WiFi.status() == WL_CONNECTED) return;

  unsigned long now = millis();
  if (state.lastWifiTry != 0 && (now - state.lastWifiTry) < WIFI_RETRY_MS) {
    return;   // a retry is already in flight - do not block
  }
  state.lastWifiTry = now;

  Serial.printf("[wifi] connecting to %s ...\n", WIFI_SSID);
  WiFi.mode(WIFI_STA);
  WiFi.setAutoReconnect(true);
  WiFi.begin(WIFI_SSID, WIFI_PASSWORD);

  // Only the very first attempt waits; after that we return straight away
  // and let the next loop pass check again.
  static bool firstAttempt = true;
  if (firstAttempt) {
    firstAttempt = false;
    unsigned long start = millis();
    while (WiFi.status() != WL_CONNECTED && millis() - start < 15000UL) {
      delay(300);
      Serial.print(".");
    }
    Serial.println();
  } else {
    state.wifiReconnects++;
  }

  if (WiFi.status() == WL_CONNECTED) {
    Serial.print("[wifi] connected, IP=");
    Serial.print(WiFi.localIP());
    Serial.print(", MAC=");
    Serial.println(WiFi.macAddress());
  } else {
    Serial.println("[wifi] not connected - the pump logic still works, but "
                   "nothing is being reported; will retry.");
  }
}
