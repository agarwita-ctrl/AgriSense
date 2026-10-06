# AgriSense REST API

Base URL: `http://<host>/AgriSense/api`

All requests and responses are JSON. Every response carries a boolean
`success` field.

```jsonc
// success
{ "success": true, "message": "Reading stored.", "...": "..." }

// failure
{ "success": false, "error": "Invalid API key." }
```

---

## 1. Authentication

There are two kinds of caller, and every endpoint accepts exactly one of them.

### Device authentication — the ESP32

Send the device's API key in the `X-API-Key` **or** `X-DEVICE-KEY` header.
The firmware in `esp32/AgriSense.ino` uses the latter; both are equivalent.

```http
POST /AgriSense/api/sensor-data HTTP/1.1
X-DEVICE-KEY: <the 64-character key shown when you generated it>
Content-Type: application/json
```

`Authorization: Bearer <key>` also works, as does an `api_key` or
`device_key` field in the JSON body for HTTP clients that cannot set
headers.

Keys are 64 hex characters and can only be produced by the dashboard:
**Devices → Edit → Generate a new key**. The server stores nothing but a
SHA-256 hash of the key, so it is displayed once, at the moment it is
generated, and cannot be read back afterwards — not from the interface,
and not from a database backup. Lose it and you generate another.

Each device has its own key, and a device may only read and write its own
data: if the payload names a different `device_code`, the request is refused
with `403`.

Generating a new key invalidates the old one immediately.

### Session authentication — the dashboard

Endpoints used by the web interface authenticate with the normal login session
cookie. Requests that change something must also carry the CSRF token in the
`X-CSRF-Token` header. The token is in the page's `<meta name="csrf-token">`.

---

## 2. Status codes

| Code | Meaning |
| ---- | ------- |
| 200  | Success |
| 400  | Malformed request (bad JSON) |
| 401  | Missing or invalid API key, or no signed-in session |
| 403  | Authenticated but not allowed (wrong device, no pump permission, bad CSRF token) |
| 404  | Unknown endpoint, device or record |
| 405  | Wrong HTTP method |
| 422  | Validation failed — the message says which field |
| 503  | The database is unavailable |

---

## 3. Device endpoints

### `POST /api/sensor-data`

Upload one set of readings. This is the endpoint the firmware calls most.

**Request**

```json
{
  "device_id": "AGRISENSE-ESP32-01",
  "soil_moisture": 28.5,
  "soil_moisture_1": 27.8,
  "soil_moisture_2": 29.2,
  "soil_raw": 2644,
  "soil_raw_2": 2616,
  "temperature": 30.2,
  "leaf_temperature": 34.8,
  "leaf_stress": false,
  "pump_status": "ON",
  "mode": "AUTO",
  "firmware_version": "4.0.0"
}
```

| Field | Type | Notes |
| ----- | ---- | ----- |
| `device_id` / `device_code` | string | Either spelling. Optional, but checked against the API key when present |
| `soil_moisture` | number \| null | **Primary** reading, 0–100 — the figure the pump decision was made on. With two probes this is their average |
| `soil_moisture_1` | number \| null | Probe 1 percentage |
| `soil_moisture_2` | number \| null | Probe 2 percentage; omit on a single-probe device |
| `soil_raw` | int | Probe 1 raw ADC, 0–4095. Calibrated server-side if the percentage is absent |
| `soil_raw_2` | int | Probe 2 raw ADC |
| `temperature` | number \| null | **Air** temperature in °C, plausible range −10 to 60 |
| `leaf_temperature` | number \| null | Canopy surface temperature in °C, plausible range −10 to 80. Needs an MLX90614 |
| `leaf_stress` | bool | The device's own verdict on whether the canopy is running hot. Advisory only |
| `humidity` | number \| null | 0–100. Needs a DHT22 |
| `pump_status` | string | `"ON"` or `"OFF"` |
| `mode` | string | `"AUTO"` or `"MANUAL"` |

At least one measurement must be present, otherwise the request is rejected
with `422`.

**Which fields a board sends depends on the sensors fitted.** `temperature`
means the air temperature whichever sensor produced it — a DHT22 on firmware
2.x/3.x, the MLX90614's ambient channel on 4.0.0 — so one column, one chart and
one threshold cover both. `leaf_temperature` only an MLX90614 can measure, and
`humidity` only a DHT22, which is why firmware 4.0.0 sends no humidity at all.

Register what is actually on the board under **Devices → Edit**: the two sensor
switches decide which cards appear and which missing measurements are treated as
faults. Claiming a sensor that is not fitted produces a permanently blank card
and a `sensor_failure` alert on every reading.

**Omit a field the board has no sensor for; send `null` only for a sensor that
is fitted and failed.** The two are different statements, and the server acts on
the difference: a null raises `sensor_failure`, an absent field does not.

**Canopy temperature is advisory.** The gap between `leaf_temperature` and
`temperature` is how water stress shows up hours before the soil probes move —
a plant that has closed its stomata stops cooling itself. A gap of 5 °C or more
raises a `crop_stress` alert (throttled to one an hour). It never queues a pump
command: soil moisture remains the only input to irrigation, on the device and
here, so an infrared sensor that has drifted round to face bare soil cannot
flood a field.

**Working out the primary reading.** If `soil_moisture` is given it is used
as-is — the device may average the probes itself, and that is the number it
acted on. If it is absent, the server averages whichever probe percentages
were sent, calibrating raw counts first. A payload carrying only
`soil_moisture` and `soil_moisture_2` (the average plus the second probe)
has probe 1 derived from the two, since the average is exactly their mean.

**A failed sensor read must be sent as `null`, not as `0` or `-999`.** A null is
stored as SQL `NULL`, kept out of every average, and shown on the dashboard as
the last known good value marked "not current". A placeholder number would
corrupt the history permanently.

A value outside its plausible range is stored as `NULL`, listed in `warnings`,
and raises an `abnormal_reading` alert.

**Response**

```json
{
  "success": true,
  "message": "Reading stored.",
  "reading_id": 1025,
  "stored": {
    "soil_moisture": 28.5, "soil_raw": 2650,
    "temperature": 30.2, "leaf_temperature": 34.8, "humidity": null,
    "pump_status": "ON", "mode": "AUTO"
  },
  "warnings": [],
  "settings": {
    "moisture_threshold": 30, "target_moisture": 60,
    "auto_irrigation": true, "max_pump_runtime": 300,
    "reading_interval": 30, "min_cycle_interval": 600,
    "dry_value": 3200, "wet_value": 1200
  },
  "command": { "id": 7, "command": "PUMP_OFF" },
  "server_time": "2026-09-05T11:31:17+08:00"
}
```

The current `settings` and any queued `command` ride along with the reply, so a
device on a slow link needs only one round trip per cycle. `command` is `null`
when nothing is queued.

---

### `GET /api/device/{id|code}/settings`

Fetch the configuration and collect any queued command. Accepts either the
numeric id or the device code.

```http
GET /AgriSense/api/device/ESP32-001/settings
X-API-Key: <key>
```

```json
{
  "success": true,
  "device": { "id": 1, "code": "ESP32-001", "name": "Field A - Rice Paddy" },
  "settings": {
    "moisture_threshold": 30, "target_moisture": 60,
    "dry_value": 3200, "wet_value": 1200,
    "auto_irrigation": true, "max_pump_runtime": 300,
    "reading_interval": 30, "min_cycle_interval": 600,
    "updated_at": "2026-09-05 11:31:08"
  },
  "command": null,
  "server_time": "2026-09-05T11:31:17+08:00"
}
```

Calling this marks the device as seen, so it doubles as a heartbeat.

---

### `POST /api/device/status`

Heartbeat, sent on boot and after a reconnect.

```json
{
  "device_code": "ESP32-001",
  "firmware_version": "1.0.0",
  "wifi_reconnects": 2,
  "rssi": -63,
  "uptime_seconds": 3600,
  "sensor_error": "DHT22 is not responding"
}
```

`wifi_reconnects` greater than zero raises a throttled `wifi_failure` alert;
`sensor_error` raises a `sensor_failure` alert. Both are rate-limited so a
faulty board cannot flood the alert list.

---

### `POST /api/irrigation/log`

Report the start or the end of an irrigation cycle. One database row covers a
whole cycle: `start` opens it, `stop` closes it and computes the duration.

**Start**

```json
{
  "device_code": "ESP32-001",
  "action": "start",
  "trigger_type": "automatic",
  "soil_moisture": 28.4,
  "command_id": 12
}
```

`command_id` is optional; supplying it records which operator's request the
cycle fulfils.

**Stop**

```json
{
  "device_code": "ESP32-001",
  "action": "stop",
  "soil_moisture": 61.5,
  "stop_reason": "target_reached"
}
```

`stop_reason` is one of `target_reached`, `manual_stop`, `max_runtime`,
`sensor_failure`, `device_reset`.

```json
{ "success": true, "message": "Irrigation cycle closed.",
  "cycle_id": 16, "duration_seconds": 252, "duplicate": false }
```

The endpoint is idempotent enough to survive a retry: a `start` when a cycle is
already open, or a `stop` with nothing running, returns `"duplicate": true`
rather than corrupting the history.

---

### `POST /api/device/command/ack`

Confirm that a queued command was carried out — this is what turns "sent" into
"confirmed by device" on the dashboard.

```json
{ "device_code": "ESP32-001", "command_id": 7,
  "success": true, "message": "Pump switched off" }
```

Answering `"success": false` marks the command failed and raises a critical
alert. Acknowledging `MODE_AUTO` or `MODE_MANUAL` also updates the stored mode,
so the dashboard and the board never disagree.

---

## 4. Dashboard endpoints

These need a signed-in session, not an API key.

### `GET /api/dashboard?device_id=1`

The live snapshot the dashboard polls.

```json
{
  "success": true,
  "device":   { "id": 1, "name": "Field A - Rice Paddy", "code": "ESP32-001",
                "online": true, "last_seen": "2026-09-05 11:40:05",
                "last_seen_h": "just now", "ip_address": "192.168.1.24",
                "probe_count": 2, "has_dht22": false, "has_mlx90614": true,
                "show_temperature": true, "show_humidity": false, "show_leaf": true },
  "readings": { "soil_moisture":    { "value": 41.2, "stale": false,
                                      "recorded_at": "2026-09-05 11:40:06" },
                "temperature":      { "value": 29.8, "stale": true,  "...": "..." },
                "leaf_temperature": { "value": 34.1, "stale": false, "...": "..." },
                "humidity":         { "value": null, "stale": false, "...": "..." },
                "leaf_delta": 4.3, "leaf_stress": false,
                "recorded_at": "2026-09-05 11:40:06", "recorded_h": "just now" },
  "pump":     { "status": "ON", "mode": "MANUAL",
                "running_cycle": { "id": 16, "trigger_type": "manual",
                                   "started_at": "...", "elapsed": 84 },
                "pending_command": false },
  "settings": { "moisture_threshold": 30, "target_moisture": 60, "...": "..." },
  "today":    { "cycles": 2, "total_seconds": 465, "total_h": "7m 45s" },
  "alerts":   { "unread": 3 },
  "server_time": "2026-09-05 11:41:30"
}
```

`stale: true` means the most recent reading for that sensor failed and the value
shown is the last good one. The dashboard dims it and says so.

The three `show_*` flags say which cards to draw, so a client never has to work
out for itself which sensor supplies what: air temperature comes from a DHT22 or
an MLX90614, humidity only from a DHT22, canopy only from an MLX90614.

`leaf_delta` is canopy minus air, and it is `null` unless both figures come from
the same reading — pairing a current canopy value with a stale air value would
report a gap that was never measured. `leaf_stress` is that gap reaching 5 °C.

### `GET /api/chart-data?device_id=1&range=7days`

Bucketed series for the charts, plus the irrigation activity series. The bucket
width adapts to the span (5 min → 6 h) so a 30-day chart stays readable.

`range` is `today`, `yesterday`, `7days`, `30days` or `custom` (with `from` and
`to` as `YYYY-MM-DD`).

### `GET /api/sensor-history?device_id=1&range=7days&page=1&per_page=25`

Paginated readings, plus min/max/average statistics. Supports `search`, `sort`
(`recorded_at`, `soil_moisture`, `temperature`, `humidity`) and `dir`.

### `GET /api/irrigation-history?device_id=1&range=7days`

Paginated cycles, plus totals and the per-day activity series. Supports
`trigger_type` and `search`.

### `GET /api/alerts`

`?recent=1&limit=6` returns the short list for the notification bell. Otherwise
returns a paginated, filterable list (`device_id`, `severity`, `alert_type`,
`is_read`, `search`, `range`).

### `POST /api/alerts/read`

`{ "id": 42 }` marks one alert read; `{ "all": true }` marks every unread alert
read. Needs the CSRF token.

### `POST /api/pump/control`

Queue a manual pump command. Needs the CSRF token **and** the pump-control
permission (administrators always have it; farmers need the flag on their
account).

```json
{ "device_id": 1, "command": "PUMP_ON" }
```

`command` is `PUMP_ON`, `PUMP_OFF`, `MODE_AUTO` or `MODE_MANUAL`.

```json
{ "success": true, "command_id": 12,
  "message": "Turn pump ON sent. Waiting for the device to confirm." }
```

The web application never switches the relay itself — it queues an instruction
that the ESP32 collects and applies. That keeps the firmware in sole charge of
the pump, which is what makes the safety rules enforceable. The server refuses
a request up front with `422` when:

- the device is offline (the command could not arrive),
- the device is not `active`,
- `PUMP_ON` is requested while the device is in automatic mode,
- the minimum rest period between cycles has not elapsed,
- the pump is already running.

### `GET /api/pump/command-status?id=12`

Poll what happened to a queued command: `pending` → `delivered` →
`acknowledged` (or `failed` / `expired`). Commands not collected within
120 seconds expire and never run.

### `GET /api/report/summary?type=sensor&device_id=1&range=7days`

Aggregate figures behind the reports page. `type` is `sensor`, `irrigation` or
`device`.

---

## 5. Worked example — a complete irrigation cycle

```bash
# Paste the key the dashboard showed you when you generated it.
KEY=<your-64-character-device-key>
API=http://localhost/AgriSense/api

# 1. The board wakes up and collects its configuration.
curl -H "X-API-Key: $KEY" $API/device/ESP32-001/settings

# 2. Soil is dry; the board starts the pump and says so.
curl -X POST $API/irrigation/log -H "X-API-Key: $KEY" \
     -H "Content-Type: application/json" \
     -d '{"device_code":"ESP32-001","action":"start",
          "trigger_type":"automatic","soil_moisture":28.4}'

# 3. Telemetry while irrigating.
curl -X POST $API/sensor-data -H "X-API-Key: $KEY" \
     -H "Content-Type: application/json" \
     -d '{"device_code":"ESP32-001","soil_moisture":41.2,
          "temperature":29.8,"humidity":74.1,"pump_status":"ON","mode":"AUTO"}'

# 4. Target reached; the board stops and closes the cycle.
curl -X POST $API/irrigation/log -H "X-API-Key: $KEY" \
     -H "Content-Type: application/json" \
     -d '{"device_code":"ESP32-001","action":"stop",
          "soil_moisture":61.5,"stop_reason":"target_reached"}'
```

## 6. Discovery

`GET /api` returns the endpoint list and the API version — handy for checking
that rewriting works after installation.
