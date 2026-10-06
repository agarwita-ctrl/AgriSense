# AgriSense — Blynk IoT setup

> ## Status: not present in the current firmware
>
> `esp32/AgriSense.ino` as it stands has **no Blynk integration**. The
> hardware it targets uses a **SIM900A GSM module for SMS alerts** as its
> mobile channel instead, and irrigation is device-authoritative with
> operator commands collected from the web dashboard.
>
> This matters if the project is assessed against the original
> specification, where **§20 requires Blynk IoT integration** with virtual
> datastreams and mobile controls. As delivered, the mobile channel is SMS,
> not Blynk.
>
> Two ways to close the gap:
>
> 1. **Add Blynk to the current sketch.** The board has ample flash and RAM
>    for it. Follow sections 1–4 below, put the `BLYNK_*` defines above the
>    includes, call `Blynk.run()` in `loop()`, and mirror the readings from
>    `postSensorData()`. Roughly 60 lines — ask and I will write it.
> 2. **Document SMS as the mobile channel.** Defensible on its own terms:
>    SMS works with no internet at all, which matters in a field. But it is
>    a deviation from the specification and should be stated as one.
>
> Everything below describes how the Blynk side is meant to be set up, and
> stays accurate for option 1.

---

Blynk gives the farmer a phone app for monitoring and control, alongside the
web dashboard. The two are independent paths to the same ESP32: the web
dashboard talks to MySQL through the REST API, Blynk talks to the board
directly through Blynk's cloud. If the internet drops, irrigation carries on
locally on the thresholds already stored on the device.

---

## 1. Create the template

1. Sign up at [blynk.cloud](https://blynk.cloud) (the free plan is enough for
   one or two devices).
2. **Developer Zone → My Templates → + New Template**
   - Name: `AgriSense`
   - Hardware: `ESP32`
   - Connection type: `WiFi`
3. Open the template and copy the **Template ID** and **Template Name** from
   the Home tab.

---

## 2. Create the datastreams

**Datastreams tab → + New Datastream → Virtual Pin.** Create all nine. The pin
numbers must match `SECTION 5` of the sketch exactly.

| Pin | Name | Type | Units | Min | Max | Decimals |
| --- | ---- | ---- | ----- | --- | --- | -------- |
| V0 | Soil Moisture | Double | % | 0 | 100 | 1 |
| V1 | Temperature | Double | °C | -10 | 60 | 1 |
| V2 | Humidity | Double | % | 0 | 100 | 1 |
| V3 | Pump Status | Integer | — | 0 | 1 | — |
| V4 | Automatic Mode | Integer | — | 0 | 1 | — |
| V5 | Moisture Threshold | Double | % | 5 | 90 | 1 |
| V6 | Target Moisture | Double | % | 10 | 95 | 1 |
| V7 | Manual Pump | Integer | — | 0 | 1 | — |
| V8 | Device Status | String | — | — | — | — |

---

## 3. Build the mobile dashboard

**Web Dashboard tab** (and the same again in the mobile app editor):

### Displays

| Widget | Datastream | Notes |
| ------ | ---------- | ----- |
| Gauge | V0 Soil Moisture | 0–100 %. Colour it: red below 30, amber 30–60, green above 60 |
| Gauge or Label | V1 Temperature | °C |
| Gauge or Label | V2 Humidity | % |
| LED | V3 Pump Status | Lights when the pump is running |
| Label | V8 Device Status | Shows faults in plain words |
| Chart | V0, V1, V2 | Three series, so trends are visible on the phone |

### Controls

| Widget | Datastream | Configuration |
| ------ | ---------- | ------------- |
| Switch | V4 Automatic Mode | ON = automatic, OFF = manual |
| Button | V7 Manual Pump | **Switch mode**, not Push. ON = pump on |
| Slider | V5 Moisture Threshold | 5–90, step 1 |
| Slider | V6 Target Moisture | 10–95, step 1 |

### Notifications

Under **Events** create:

- `low_moisture` — Warning — "Soil moisture is below the threshold"
- `pump_on` — Information — "Irrigation started"
- `device_offline` — Critical — enable **Notify by push**

---

## 4. Add the device and get the token

1. **Search → Devices → + New Device → From template → AgriSense.**
2. Name it to match the web dashboard, e.g. `Field A - Rice Paddy`.
3. Copy the **AuthToken**.
4. Paste all three values into the top of `AgriSense.ino`:

```cpp
#define BLYNK_TEMPLATE_ID   "TMPL2xxxxxxx"
#define BLYNK_TEMPLATE_NAME "AgriSense"
#define BLYNK_AUTH_TOKEN    "your-auth-token-here"
```

These three lines **must stay above** `#include <BlynkSimpleEsp32.h>`, or the
sketch will not compile.

---

## 5. How the controls behave

The firmware, not the app, has the final say on the pump. That is deliberate:
a phone can be out of signal, but the pump is real.

- **V7 Manual Pump** is ignored while the device is in automatic mode. The app
  gets the reason back on V8 ("Switch to MANUAL mode first") and the button
  snaps back.
- **V7** is also ignored while the mandatory rest between cycles is still
  running, so the soil has time to absorb water.
- **V5 / V6** sliders are range-checked on the device: the target must always
  stay above the threshold. An invalid value is rejected and the slider snaps
  back to the accepted one.
- The **maximum runtime** cut-off cannot be overridden from Blynk at all. It
  comes from the web dashboard and always applies.
- Turning the pump **off** is always honoured, from either interface.

Changes made from Blynk are local to the device. Changes made in the web
dashboard are stored in MySQL and pushed to the device on its next check-in,
where they overwrite the local values. **The web dashboard is the source of
truth**; treat the Blynk sliders as a field-side convenience.

---

## 6. Testing without hardware

Blynk's device simulator will not exercise the irrigation logic. To test the
backend without an ESP32, use the `curl` examples in `API.md` — they drive the
same endpoints the firmware uses.

---

## 7. Troubleshooting

| Symptom | Cause |
| ------- | ----- |
| `Blynk: Invalid auth token` | Token copied from the wrong device, or extra whitespace |
| Compiles but never connects | Template ID / Name defined *after* the Blynk include |
| Widgets stay blank | Datastream pin numbers do not match `SECTION 5` of the sketch |
| Pump button does nothing | Device is in automatic mode — that is the intended behaviour |
| Values update in Blynk but not on the web dashboard | Blynk is fine; the REST API is not. Check `API_BASE` and the serial log for HTTP errors |
