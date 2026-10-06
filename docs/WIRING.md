# AgriSense — Wiring and pin configuration

Every pin number below is defined in one block at the top of
`esp32/AgriSense.ino`, under **Pin definitions**. Change them there and
nothing else needs editing.

```cpp
#define SOIL1_AO      34   // ADC1_CH6 - probe 1 analogue
#define SOIL1_DO      32   // probe 1 digital (comparator output)
#define SOIL2_AO      35   // ADC1_CH7 - probe 2 analogue
#define SOIL2_DO      33   // probe 2 digital
#define DHT_PIN       4    // DHT22 data
#define RELAY_PIN     26   // relay IN -> pump
#define SIM900_RX     16   // UART2 RX  <- SIM900A TX
#define SIM900_TX     17   // UART2 TX  -> SIM900A RX

#define RELAY_ACTIVE_HIGH  true
```

---

## 1. Bill of materials

| Item | Notes |
| ---- | ----- |
| ESP32 DevKit v1 (ESP32-WROOM-32) | 30-pin or 38-pin |
| 2 × soil moisture sensor | Each with VCC, GND, DO and AO pins |
| DHT22 / AM2302 | Temperature and humidity |
| 1-channel relay module, 5 V | Rated above the pump's stall current |
| SIM900A GSM module | For SMS alerts; needs its own supply |
| 12 V DC water pump | e.g. a 12 V submersible or diaphragm pump |
| 12 V power supply | At least 2× the pump's rated current |
| 5 V supply for the ESP32 | A phone charger or a 12 V→5 V buck converter |
| Separate supply for the SIM900A | 5 V, capable of **2 A peaks** |
| 1N4007 flyback diode | Across the pump terminals |
| 10 kΩ resistor | DHT22 pull-up, if the module has no built-in one |
| Jumper wires, terminal blocks, weatherproof enclosure | |

> **On probe type.** Resistive probes are cheap but corrode: the exposed
> electrodes electrolyse in wet soil and the readings drift within weeks.
> If you have the option, fit capacitive probes instead — the wiring, the
> pin map and the firmware are identical, only the calibration numbers
> change. If you keep resistive probes, power them from a GPIO you switch
> on only while sampling, or expect to replace them each season.

---

## 2. Connection table

### Soil moisture probes → ESP32

| Probe 1 | ESP32 | | Probe 2 | ESP32 |
| ------- | ----- |-| ------- | ----- |
| VCC | 3V3 | | VCC | 3V3 |
| GND | GND | | GND | GND |
| AO | **GPIO 34** | | AO | **GPIO 35** |
| DO | **GPIO 32** | | DO | **GPIO 33** |

GPIO 34 and 35 are input-only and belong to **ADC1**. This matters: ADC2
pins (GPIO 0, 2, 4, 12–15, 25–27) stop returning readings the moment Wi-Fi
is switched on. If you move these, choose another ADC1 pin — GPIO 32, 33,
36 or 39.

The firmware reads the **analogue** pins and ignores the DO comparator
output for its decisions; DO is read only for the serial log, because its
trip point is set by a trimpot on the sensor board and cannot be calibrated
from software.

**Place the two probes apart** — different corners of the bed, or one at
each end of a row. Their average is what the pump acts on, so two probes
sitting side by side gives you the reliability of one. The dashboard raises
a `probe_mismatch` warning when they disagree by more than 25 points, which
usually means one has worked loose.

### DHT22 → ESP32

| DHT22 | ESP32 |
| ----- | ----- |
| VCC (pin 1) | 3V3 (5 V also works) |
| DATA (pin 2) | **GPIO 4** |
| NC (pin 3) | not connected |
| GND (pin 4) | GND |

Fit a 10 kΩ pull-up between DATA and VCC unless the breakout board has one.
Keep the lead under about 20 cm; longer runs cause intermittent read
failures, which the firmware reports as a sensor fault after three
consecutive misses.

### Relay module → ESP32

| Relay | ESP32 |
| ----- | ----- |
| VCC | 5 V (VIN) |
| GND | GND |
| IN | **GPIO 26** |

This build uses an **active-HIGH** relay: the relay closes when IN is driven
HIGH. That is why `RELAY_ACTIVE_HIGH` is `true`. Many cheap opto-isolated
boards are the opposite — if the pump runs when it should be idle, set that
constant to `false` and re-flash.

### SIM900A → ESP32

| SIM900A | ESP32 |
| ------- | ----- |
| TX | **GPIO 16** (UART2 RX) |
| RX | **GPIO 17** (UART2 TX) |
| GND | GND (**must be common with the ESP32**) |
| VCC | its own 5 V supply, 2 A capable |

Do **not** power the SIM900A from the ESP32's 5 V pin. It draws short 2 A
bursts when it registers on the network, which will brown out the ESP32 and
reset it mid-irrigation. Give it its own supply and tie the grounds
together.

If you are not fitting a SIM900A, set `SMS_ENABLED` to `false` in the
sketch. Everything else keeps working.

### Pump power circuit

```
  12 V (+) ────────────────► Relay COM
  Relay NO ────────────────► Pump (+)
  Pump (−) ────────────────► 12 V (−)  ──── common GND with the ESP32
```

Use **NO** (normally open), so the pump is off whenever the ESP32 is
unpowered or resetting.

Fit the 1N4007 diode directly across the pump terminals, **cathode (banded
end) to +12 V**. A pump motor is inductive and throws a large reverse spike
when it switches off; without the diode that spike will eventually weld the
relay contacts or reset the ESP32.

---

## 3. Full diagram

```
                       ┌──────────────────────────┐
  Soil probe 1  AO ────┤ GPIO 34 (ADC1)           │
                DO ────┤ GPIO 32                  │
                       │                          │
  Soil probe 2  AO ────┤ GPIO 35 (ADC1)           │
                DO ────┤ GPIO 33                  │
                       │                          │
  DHT22        DATA ───┤ GPIO 4                   │
  (+10k pull-up)       │      ESP32 DevKit        │
                       │                          │
  SIM900A        TX ───┤ GPIO 16 (RX2)            │
                 RX ───┤ GPIO 17 (TX2)            │
                       │                          │
                       │ GPIO 26 ├────────────────┼──► Relay IN
                       │                          │
                       │ 3V3   5V   GND           │
                       └──┬─────┬─────┬───────────┘
                          │     │     │
           probes + DHT ◄─┘     │     └──► common ground rail
                 relay VCC ◄────┘              │  (ESP32 + relay
                                               │   + 12 V + SIM900A)
      ┌────────────────────────────────────────┴──────────┐
      │                                                   │
   12 V (+) ──► Relay COM      Relay NO ──► Pump (+)      │
                                                          │
   12 V (−) ─────────────────────────────► Pump (−) ──────┘
                          ⏛ 1N4007 across the pump
```

**Every ground ties together** — ESP32, relay, 12 V supply and the SIM900A's
own supply. Without a common ground the relay behaves erratically and the
GSM module will not answer AT commands.

---

## 4. Powering the system

Do **not** run the pump or the SIM900A from the ESP32's 5 V pin.

Three loads, sharing one ground:

1. **12 V** for the pump, through the relay contacts.
2. **5 V** for the ESP32 and the relay coil — a separate USB charger, or a
   12 V→5 V buck converter fed from the same 12 V supply.
3. **5 V, 2 A** for the SIM900A, on its own supply.

Add a 470 µF electrolytic capacitor across the ESP32's 5 V and GND if the
board resets when the pump or the GSM module kicks in.

---

## 5. Calibrating the soil probes

The percentage is only as good as this calibration, and **every probe is
different** — calibrate each of the two separately, then use values that
suit both.

1. Flash the sketch and open the Serial Monitor at **115200 baud**.
2. Hold both probes in **dry air**. Read the `AO=` values from a line like:

   ```
   Soil1 AO=3187 (0.6%) DO=1 | Soil2 AO=3204 (-0.1%) DO=1 | avg=0.3% | ...
   ```

   Note both numbers — the higher of the two is your **dry value**.
3. Stand both probes in water, up to but **not past** the line on the board.
   Wait ten seconds and note the `AO=` values — the lower is your
   **wet value**.
4. In the dashboard go to **Irrigation Settings → Sensor calibration**, enter
   both numbers and save.

The board picks the new calibration up on its next check-in; no re-flash
needed.

Typical values are around **3200 dry** and **1200 wet**. Both probe types
read *higher* when dry, so the dry value must be the larger of the two — the
settings page enforces a gap of at least 200 counts and refuses anything
else.

Never submerge a probe past the line: the electronics above it are not
waterproof.

### Raw counts and percentages

The firmware and the backend share one formula:

```
percent = (dry_value − raw) × 100 / (dry_value − wet_value)
```

So with the defaults (3200 dry, 1200 wet) the raw set points this project
was tuned with map to the percentages you see in the dashboard:

| Raw ADC | Percentage | Meaning |
| ------- | ---------- | ------- |
| 2800 | **20 %** | at or below → pump ON |
| 2000 | **60 %** | at or above → pump OFF |

You set thresholds in percent on the settings page; the device converts.
Both ends always agree because they use the same formula.

---

## 6. Placing the probes in the field

- Push each probe fully into the root zone, roughly 10–15 cm deep for
  vegetables.
- Keep them about 20 cm away from where water is delivered, so they measure
  the soil rather than the stream from the hose.
- Separate the two probes — opposite corners of the bed is ideal.
- Firm the soil around each probe. An air gap reads as permanently dry,
  which would keep the pump running.
- Run cables up a stake and into the enclosure through a gland at the
  bottom, with a drip loop, so water cannot track down the cable.

---

## 7. First power-up checklist

1. Connect **everything except the pump**.
2. Power the ESP32 and open the Serial Monitor at 115200 baud.
3. Confirm the banner, `[init] Relay initialised - pump OFF`, and a Wi-Fi IP
   address.
4. Confirm the readings line shows plausible percentages on **both** probes
   and a temperature within a few degrees of the room.
5. Confirm `[http] POST /api/sensor-data (periodic) -> ok`, and that the
   dashboard shows the device Online.
6. Listen for the relay to click when you trigger it from the dashboard
   (Manual mode → Pump ON).
7. Only then connect the pump, and test with the intake in a bucket of water
   before installing in the field.

**Never run the pump dry** — most 12 V pumps are lubricated by the water and
are damaged within seconds without it.

---

## 8. Troubleshooting

| Symptom | Likely cause |
| ------- | ------------ |
| A probe reads 0 % or 100 % constantly | Probe on an ADC2 pin, or unplugged. The firmware reports raw values below 20 or above 4080 as a failed read rather than as dry soil, so it will not start the pump on a dead probe |
| The two probes disagree wildly | One has worked loose or sits in an air pocket. The dashboard raises `probe_mismatch` above a 25-point gap |
| Readings drift over weeks | Resistive probe corrosion — see the note in §1 |
| Temperature and humidity read `failed` | DHT22 lead too long, missing pull-up, or wrong `DHT_TYPE` |
| Pump runs as soon as power is applied | Relay is active-LOW: set `RELAY_ACTIVE_HIGH` to `false`. Also check you used NO, not NC |
| Board resets when the pump or GSM starts | Shared/undersized supply, missing flyback diode, or the SIM900A drawing from the ESP32 |
| SIM900A never answers | Not on its own 2 A supply, TX/RX swapped, or grounds not tied together |
| Dashboard shows the device offline | `AGRISENSE_BASE` uses `localhost` instead of the PC's LAN IP, or Windows Firewall is blocking port 80 |
| `HTTP 401: Invalid API key` in the serial log | `DEVICE_API_KEY` does not match the dashboard; copy it again from Devices → Edit |
| `HTTP 403` on every upload | `DEVICE_ID` does not match the key's device, or the device is set to Inactive |
