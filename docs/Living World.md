# 🌍 Nexus Living World System  
### Version 1.0 — for The Forgotten Server 10.98 (C++17 / Lua 5.4)

---

## 🧩 Overview

The **Living World System** transforms the Nexus Server into a reactive, evolving world.

Every monster, every dungeon, and every floor dynamically changes in difficulty and reward
based on **player activity**, **floor depth**, and **instance tier**.  
The more players farm an area, the more dangerous (and profitable) it becomes.

No client mods. No visuals. Pure, text-driven mechanics.

---

## ⚔️ Player Features

- **Monsters evolve.**  
  Every creature in the world has a **Rank Tier** (F → SSSSSS) that defines its power.

- **World fights back.**  
  Each floor and region remembers how active it’s been. Kill too many monsters in an area and the ecosystem grows stronger.

- **Persistent ecosystem.**  
  The world saves its “pressure” between restarts and slowly cools down when ignored.

- **Depth & instance scaling.**  
  Deeper floors and higher-tier instances spawn stronger, rarer monsters.

- **Rewards scale with danger.**  
  Higher ranks drop better loot, yield more XP, and may even roll extra loot tables.

---

## 🧠 Rank Tiers (F → SSSSSS)

| Rank | Category | Description | Behavior |
|------|-----------|-------------|-----------|
| F–C | Common | Weak creatures, surface wildlife | Low XP and loot |
| B–A | Elite | Trained enemies, mini-bosses | +HP, +damage, richer loot |
| S–SSS | Champion | Apex monsters, rare spawns | High difficulty, high reward |
| SSSS–SSSSSS | Legendary | Dungeon bosses, world anomalies | Massive HP, top-tier loot |

Each rank tier modifies:
- Health  
- Damage output  
- Damage mitigation  
- XP reward  
- Loot multiplier  
- Extra loot rolls  
- Resistances  
- Speed  
- AI cooldowns  
- Spell access level

---

## 🏗️ Core Systems

### 1. Monster Rank System (C++ / JSON)

- Assigns a rank on spawn from config values.
- Scales all monster stats accordingly.
- Configurable via `data/monster_ranks.json` — no rebuild needed.
- Safe to toggle (`"enabled": true/false`).

---

### 2. Rank Assignment Logic

Each spawn’s rank is determined by **three factors:**

| Factor | Source | Example |
|--------|---------|---------|
| **Floor (Z)** | Higher/lower elevation = higher rank | Floor 8+ → +1 rank |
| **Instance Tier** | Harder dungeons = higher rank | Tier ≥ 5 → +3 ranks |
| **World Pressure** | Player activity drives difficulty | Frequent kills = +1 rank bias |

Final Rank = BaseRank + FloorOffset + InstanceOffset + PressureOffset

---

### 3. World Pressure Manager

A global background system that tracks **player activity intensity**.

| Function | Behavior |
|-----------|-----------|
| **registerKill** | Adds intensity based on monster rank |
| **decayAll** | Reduces intensity over time |
| **getPressureBias** | Feeds difficulty bias into spawn ranks |
| **saveJson / loadJson** | Saves and restores world state |

Each region of the map has its own pressure level that:
- Increases with kills  
- Decays over time  
- Raises spawn rank probability while high  
- Persists across restarts (`data/rank_pressure.json`)

---

### 4. Combat & Loot Hooks

- Outgoing monster damage × rank multiplier  
- Incoming damage reduced by mitigation & resist  
- XP scaled by rank XP multiplier  
- Loot rolls scaled by rank loot multiplier  
- Extra loot rolls per rank (`extraRolls`)

Fully integrated; no Lua changes required.

---

### 5. Staff Visibility

- GMs (group ≥ 3) see monster rank in the look description:

Rank: SSS

yaml
Copy code

- Players do not see this by default (future reveal item planned).

---

### 6. Persistence & Decay

- All pressure data saved in `data/rank_pressure.json`.
- Automatic decay keeps difficulty self-balancing.
- Configurable via JSON key `pressureDecayPerMinute`.

---

## 🧮 System Architecture

| Module | Responsibility |
|---------|----------------|
| **RankSystem** | Handles rank tiers, JSON parsing, scaling |
| **WorldPressureManager** | Tracks kill pressure, decay, persistence |
| **Monster Hooks** | Applies rank at spawn, adjusts stats |
| **Combat Hooks** | Scales incoming/outgoing damage |
| **Death Hooks** | Applies XP/loot scaling, updates pressure |
| **Game::onLook** | Adds rank line for staff visibility |

---

## 🛠️ Technical Details

### Config Path
data/monster_ranks.json

bash
Copy code

### Rank Scalars
| Key | Function |
|-----|-----------|
| hp | Max HP multiplier |
| dmg | Outgoing damage multiplier |
| mit | Incoming damage reduction (0–0.8) |
| xp | XP multiplier |
| lootMult | Loot multiplier |
| extraRolls | Extra loot rolls |
| speedDelta | Speed adjustment |
| resist | Flat % damage reduction |
| aiCdMult | AI cooldown multiplier |
| spellUnlock | Unlocks advanced boss spells |

### Performance
- **O(1)** per spawn and kill.  
- Decay touches only recently modified regions.  
- No measurable impact on server tick.

---

## 🧱 Persistence

| File | Purpose |
|------|----------|
| `data/monster_ranks.json` | Rank definitions, weights, and rules |
| `data/rank_pressure.json` | Runtime world memory of pressure levels |

---

## ✅ Summary

| Category | Features |
|-----------|-----------|
| **Dynamic Ranks** | 12-tier ladder (F–SSSSSS) |
| **Scaling Logic** | Floor + Instance + Pressure offsets |
| **World Memory** | Pressure tracking per region |
| **Combat Hooks** | Rank-based damage scaling |
| **XP & Loot** | Rank-based XP and loot |
| **Persistence** | JSON saved, auto decay |
| **Performance Safe** | Constant-time operations |
| **Extensible** | Ready for events & economy tie-ins |
| **Zero Client Mods** | All text-based |

---

## 🔍 How It Works (Developer Breakdown)

1. **Spawn**
   - When a monster is created:
     1. Base rank picked from weighted table.
     2. Offsets applied from floor/instance/pressure rules.
     3. Final rank applied to HP, speed, and cached multipliers.

2. **Combat**
   - Monster attacks: damage scaled by `dmg`.
   - Monster defends: incoming reduced by `mit` and `resist`.

3. **Death**
   - XP and loot scaled.
   - Pressure updated for the region.

4. **Decay**
   - Background decay every minute reduces pressure intensity.

5. **Save / Load**
   - On shutdown: `rank_pressure.json` saved.
   - On startup: world restored exactly as it was.

---

## 🧭 Admin Tools (Optional Lua)

- `/rankpressure here` → show region intensity  
- `/rankpressure reset` → reset region pressure  
- `/rankdebug` → print floor / instance / pressure offsets  

---

## 🗓️ Expansion Hooks (Phase 2 Ready)

- **Outbreak Events:** automatic boss spawns on over-pressured zones  
- **Ecosystem Drift:** species migration based on rank trends  
- **Seasonal Modifiers:** world-wide buffs/nerfs by real month  
- **Economy Integration:** rare resource yields increase with pressure  
- **World Log:** persistent record of outbreaks and cleanses  

All can be implemented with Lua + existing engine hooks.

---

## 🧰 Configuration Template

`data/monster_ranks.json`
```json
{
  "enabled": true,
  "order": [
    {"name":"F","s":{"hp":1.00,"dmg":1.00,"mit":0.00,"speedDelta":0,"xp":1.00,"lootMult":1.00,"extraRolls":0,"aiCdMult":1.00,"spellUnlock":0,"resist":0}},
    {"name":"E","s":{"hp":1.05,"dmg":1.03,"mit":0.01,"speedDelta":5,"xp":1.05,"lootMult":1.05,"extraRolls":0,"aiCdMult":0.98,"spellUnlock":0,"resist":1}},
    {"name":"D","s":{"hp":1.10,"dmg":1.06,"mit":0.02,"speedDelta":8,"xp":1.10,"lootMult":1.10,"extraRolls":0,"aiCdMult":0.97,"spellUnlock":0,"resist":2}},
    {"name":"C","s":{"hp":1.20,"dmg":1.12,"mit":0.03,"speedDelta":12,"xp":1.20,"lootMult":1.20,"extraRolls":1,"aiCdMult":0.95,"spellUnlock":1,"resist":3}},
    {"name":"B","s":{"hp":1.35,"dmg":1.18,"mit":0.05,"speedDelta":16,"xp":1.35,"lootMult":1.30,"extraRolls":1,"aiCdMult":0.93,"spellUnlock":1,"resist":5}},
    {"name":"A","s":{"hp":1.55,"dmg":1.26,"mit":0.08,"speedDelta":20,"xp":1.55,"lootMult":1.45,"extraRolls":2,"aiCdMult":0.90,"spellUnlock":2,"resist":7}},
    {"name":"S","s":{"hp":1.80,"dmg":1.35,"mit":0.12,"speedDelta":24,"xp":1.80,"lootMult":1.65,"extraRolls":2,"aiCdMult":0.88,"spellUnlock":2,"resist":10}},
    {"name":"SS","s":{"hp":2.20,"dmg":1.48,"mit":0.16,"speedDelta":28,"xp":2.20,"lootMult":2.00,"extraRolls":3,"aiCdMult":0.85,"spellUnlock":3,"resist":14}},
    {"name":"SSS","s":{"hp":2.80,"dmg":1.65,"mit":0.22,"speedDelta":32,"xp":2.80,"lootMult":2.50,"extraRolls":4,"aiCdMult":0.80,"spellUnlock":4,"resist":18}},
    {"name":"SSSS","s":{"hp":3.50,"dmg":1.80,"mit":0.30,"speedDelta":36,"xp":3.00,"lootMult":3.00,"extraRolls":5,"aiCdMult":0.78,"spellUnlock":5,"resist":22}},
    {"name":"SSSSS","s":{"hp":4.00,"dmg":2.00,"mit":0.35,"speedDelta":40,"xp":3.50,"lootMult":3.50,"extraRolls":6,"aiCdMult":0.76,"spellUnlock":6,"resist":25}},
    {"name":"SSSSSS","s":{"hp":5.00,"dmg":2.30,"mit":0.40,"speedDelta":44,"xp":4.00,"lootMult":4.00,"extraRolls":7,"aiCdMult":0.74,"spellUnlock":7,"resist":30}}
  ],
  "globalWeights": { "F":400,"E":250,"D":150,"C":90,"B":60,"A":30,"S":15,"SS":4,"SSS":1,"SSSS":0,"SSSSS":0,"SSSSSS":0 },
  "floorRules": [
    { "zGte": 6, "zLte": 15, "offset": 1 },
    { "zGte": 0, "zLte": 8,  "offset": 1 }
  ],
  "instanceRules": [
    { "tierGte": 5, "offset": 3 },
    { "tierGte": 8, "offset": 1 },
    { "hard": true, "offset": 1 },
    { "permadeath": true, "offset": 2 }
  ],
  "pressureDecayPerMinute": 0.99,
  "biasScale": 0.5,
  "intensityPerKillByRank": {
    "F":0.3,"E":0.35,"D":0.4,"C":0.45,"B":0.5,"A":0.6,"S":0.7,"SS":0.9,"SSS":1.1,"SSSS":1.4,"SSSSS":1.8,"SSSSSS":2.2
  }
}
📄 License & Notes
All code and design by Nexus Dev Team.
Compatible with The Forgotten Server 10.98 and newer forks.
This system introduces no client dependencies and remains fully optional via configuration.

“The world remembers what you do.”
The Nexus Living World System makes your server feel alive.