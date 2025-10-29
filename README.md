# 🌀 The Nexus — The Forgotten Server 10.98 (Extended)

[![Discord](https://img.shields.io/badge/Join-include?logo=discord&logoColor=%237B68EE&label=Discord&color=%237B68EE)](https://discord.gg/GgvreyFvdV)
[![Build VCPKG](https://img.shields.io/badge/Build-include?logo=Drone&logoColor=%23DAA520&label=VCPKG&color=%23DAA520)](https://github.com/CodingALS/forgottenserver-10.98/actions/workflows/build-vcpkg.yml)
[![Docker Build](https://img.shields.io/badge/Generate-include?logo=Docker&logoColor=%236495ED&label=Docker&labelColor=grey&color=%236495ED)](https://github.com/CodingALS/forgottenserver-10.98/actions/workflows/docker.yml)
[![Repo Size](https://img.shields.io/badge/40%20MiB-include?label=Repo%20Size&color=%23FF1493)](https://github.com/CodingALS/forgottenserver-10.98)
[![License](https://img.shields.io/badge/GPL%202.0-include?label=License&color=%23FF7F50)](https://github.com/CodingALS/forgottenserver-10.98/blob/main/LICENSE)

---

## 📜 Overview

**The Nexus Project** extends **The Forgotten Server 10.98** (TFS 1.4.2 protocol) with long-term systems for instanced content, dynamic world scaling, and richer Lua automation while maintaining upstream compatibility with the Tibia 10.98 client.

---

## ✨ Feature Highlights

- **Engine-level instancing** — spin up isolated copies of the world for parties, raids, or dungeons with the experimental `InstanceManager` scaffolding and Lua helpers. See [README_INSTANCES.md](README_INSTANCES.md) for the full primer.
- **Living World difficulty scaling** — monsters rank up based on floor depth, instance tier, and world pressure, dynamically adjusting stats, loot, and XP without client changes. Details live in [`docs/Living World.md`](docs/Living%20World.md).
- **Activities & dungeon framework** — describe unlocks, permadeath, timers, and reward tiers in Lua-driven configs for repeatable content. See [`docs/activities.md`](docs/activities.md).
- **Reputation & trading economy** — track faction reputation, donations, and market pools with a persistent ledger powering NPC shops and quests. Overview in [`docs/reputation/README.md`](docs/reputation/README.md).
- **Item serialization** — assign globally unique serial numbers to items for auditing, trades, and tooling support. Learn more in [`docs/serialization.md`](docs/serialization.md).

---

## 🚀 Getting Started

### 📦 Requirements

- **C++17** toolchain (MSVC, Clang, or GCC)
- **CMake ≥ 3.16**
- **Boost 1.78+** (pulled automatically through vcpkg)
- **vcpkg** for dependency management
- **MySQL Server** or **MariaDB**
- **LuaJIT 2.x**

### 🔧 Build (CMake)

```bash
git clone https://github.com/YourRepo/TheNexus.git
cd TheNexus
cmake -S . -B build -DCMAKE_TOOLCHAIN_FILE=<path-to-vcpkg>/scripts/buildsystems/vcpkg.cmake
cmake --build build --config Release
```

Run the built server with:

```bash
./build/theforgottenserver
```

#### Optional Flags

- Disable experimental instancing:
  ```bash
  cmake -S . -B build -DENABLE_INSTANCING=0
  ```
- Point to a custom Lua directory:
  ```bash
  cmake -S . -B build -DLUAJIT_INCLUDE_DIR=/path/to/luajit/include
  ```

---

## 🧪 Experimental Systems

### Instance System
The engine-level instancing layer is gated by `ENABLE_INSTANCING` and orchestrated by `src/game/InstanceManager.{h,cpp}`. Lua entry points (`data/actions/scripts/instance_portal.lua`, `data/talkactions/scripts/instance_admin.lua`) expose helpers such as `createInstance`, `bindParty`, and `teleportInto` for rapid prototyping. Refer to [README_INSTANCES.md](README_INSTANCES.md) for configuration tips and expansion notes.

### Living World System
The Living World framework ranks every monster on spawn using floor depth, activity pressure, and instance tier. Rank multipliers touch combat, loot, XP, and resistances, while staff can inspect ranks directly in-game. Full documentation, including configuration and architecture, is in [`docs/Living World.md`](docs/Living%20World.md).

### Activities & Dungeons
Activities wrap instancing with scripted unlocks, permadeath modes, dungeon timers, announcers, and reward tables. Configure runs in `data/lib/activities/activity_config.lua`, define encounter packs, and wire entry portals or chat commands as described in [`docs/activities.md`](docs/activities.md).

### Reputation & Trading Economy
The reputation module tracks faction standing, donation flows, and market fees with persistent database tables seeded via `data/migrations/33.lua`. Lua helpers expose APIs for NPC shops, quests, and admin commands such as `!rep` or `/economy`. See [`docs/reputation/README.md`](docs/reputation/README.md) for setup and test plans.

### Item Serialization
Item creation hooks assign Crockford Base32 serials to new items and expose inspection commands (`/serial`, `/reserial`). Configuration and extension ideas are outlined in [`docs/serialization.md`](docs/serialization.md).

---

## 🗺️ Roadmap & TODOs

- **Instance lifecycle polish**
  - Validate full parties before binding and reject invalid states (`InstanceManager::bindParty`).
  - Teleport bound players and creatures, preparing instance state safely (`InstanceManager::teleportInto`).
  - Detect boss deaths, close instances, and evacuate occupants cleanly (`InstanceManager::onBossDeath` / `close`).
  - Heartbeat timers should emit warnings and auto-expire runs (`InstanceManager::heartbeat`).
- **Lua tooling for instancing**
  - Add cooldown tracking, matchmaking helpers, and dynamic scaling hooks to the Lua bridge.
  - Provide templated map loaders to spin up bespoke layouts per instance.
- **Living World visibility**
  - Ship the planned in-game reveal item so players can inspect monster ranks without staff access.
- **Serialization UX**
  - Surface item serials in client-side tooltips or inspection panels for easier auditing.

Contributions tackling any of the above are welcome—please coordinate on Discord to avoid duplicate efforts.

---

## 🌍 Client Connections

Use one of the following clients to connect:

- **OTClient V8**
- **OTClient Redemption**
- **Tibia 10.98**

---

## 🛠️ Troubleshooting

- **Boost not found** — ensure `VCPKG_ROOT` (or the toolchain file) is defined in your environment.
- **Missing LuaJIT headers** — confirm your include path contains `<vcpkg>/installed/<triplet>/include/luajit`.
- **Linker errors after migrating architectures** — rerun CMake with `-A x64` and pass `--fresh` if using `cmake --preset`.

---

## 🤝 Contribution

Fork the project, create a feature branch, follow the existing code style, and document new Lua hooks or engine extensions. Pull requests with accompanying tests or usage notes are greatly appreciated.

---

## 📄 License

This project is licensed under the **GNU General Public License v2.0**. See [LICENSE](LICENSE) for the full text.

---

## 🏷️ Credits

- **Original TFS Team:** OTLand
- **Extended Fork Maintainers:** CodingALS, Mystikil, and community contributors
- **Special Thanks:** Devnexus Network, OpenTibia community, and testers

> “A world within worlds — built not only to play, but to create.”
