# Activities (Instances & Dungeons)

## Activity kinds
- `kind = "instance"` – traditional story / quest instances.
- `kind = "dungeon"` – time-attack dungeons that reuse the same command flow but can opt-in to mutations, timers, announcers, and tiered rewards.

## Unlock requirements
Configure the `unlock` block with any combination of:
- `storage` – quest/NPC storage that must be set to `1`.
- `questStorages` – array of storages that all must be positive to unlock.
- `minLevel` – minimum level required to enter.
- `minRep = { faction = "Name", tier = "Friendly" }` – checks the reputation tier via `ReputationEconomy`.
- `keyItemId` – item that must be present in inventory (consumed if `consumeKey = true`).

Use `ActivityUnlocks.grant(player, id)` in quests/NPCs to flip the primary unlock storage, and remember to set any supporting quest storages alongside it.

## Permadeath modes
Define `permadeath = { ... }` with:
- `mode` – `"off"`, `"character"`, `"inventory"`, or `"exp"`.
- `confirmOnce = true` – force a one-time `!confirm <id>` before entry.
- `dropPercent` / `expPercent` – applied for `inventory` / `exp` modes.
- `broadcast = true` – announces deaths to the server.

## Dungeon-specific options
The optional `dungeon` block supports:
- `mutateChance` – probability to flag the run as mutated.
- `timerSeconds` – countdown timer for time-attack scoring.
- `scoreTiers` – map of rank (`S`/`A`/`B`/`C`) to completion thresholds.
- `rewardTiers` – reward keys handed out on `onClear`.
- `objectives` – flavour strings shown in `!info`.
- `announcer = true` – announce high-rank clears.

## Adding a new activity
1. Reserve an unused coordinate band – map ranges must not overlap.
2. Append an entry to `data/lib/activities/activity_config.lua` with `kind`, `id`, names, unlocks, cooldown, bind rules, map coordinates, and any optional permadeath/dungeon features.
3. Add supporting monster sets in `activity_monsters.lua` and optional feature hooks in `activity_features.lua`.
4. Update NPCs/quests to call `ActivityUnlocks.grant(player, id)` when access should be unlocked.
5. Drop a portal with `ITEM_ATTRIBUTE_DESCRIPTION = <id>` or rely on chat commands (`!instance` / `!dungeon`).

## Migration
- Legacy `ember_catacomb` portal now targets Activity `101` (`The Ember Depths`), occupying coordinates `2000,1000,8` → `2080,1065,8`.
- Reserve `1000,1000,7` → `1060,1060,7` for Activity `1` (`Forgotten Catacombs`).

Ensure no future activity reuses those coordinate blocks to maintain per-activity isolation.
