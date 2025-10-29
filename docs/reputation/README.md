# Reputation & Trading Economy System

## Overview
This module introduces faction-wide reputation tracking tied to a dynamic trading economy. NPC and market fees feed faction pools which in turn adjust prices, unlock secret inventory, and gate quests. Reputation gains and penalties are unified across trading, donations, quests, and creature kills.

## Database & Migration
Run the Lua migration `data/migrations/33.lua` to create the following tables:

- `factions`, `npc_factions`
- `player_faction_reputation`, `player_faction_reputation_log`
- `faction_economy`, `faction_economy_history`, `faction_economy_ledger`
- `faction_market_cursor`

The migration seeds three sample factions (Traders Guild, Artisan Assembly, Central Exchange) and maps the sample quartermaster NPC. Pools are seeded with default reserves and indexes are added for reputation lookups and ledger processing.

## Configuration
All tunables live in [`data/lib/nx_reputation_config.lua`](../../data/lib/nx_reputation_config.lua):

- `tiers`: names, ranges, modifiers, and access flags
- `factions`: fees, reputation gain multipliers, economy curves, default donation values, and town routing
- `npcs`: optional per-NPC overrides (secret offers)
- `donationChests`, `creatures`, `questExample`, and global modifiers

Adjust coefficients or add new factions without touching runtime code.

## Runtime Scripts
- **Library:** [`data/lib/nx_reputation.lua`](../../data/lib/nx_reputation.lua) exposes helper APIs for reputation calculations, economy queues, tier checks, and ledger ingestion.
- **Global events:** [`data/scripts/globalevents/reputation_economy_tick.lua`](../../data/scripts/globalevents/reputation_economy_tick.lua) initializes pools, flushes ledgers each minute, captures market fees, and applies weekly decay.
- **NPC example:** [`data/npc/Faction Quartermaster.xml`](../../data/npc/Faction%20Quartermaster.xml) / [`data/npc/scripts/reputation_quartermaster.lua`](../../data/npc/scripts/reputation_quartermaster.lua) demonstrate faction binding, gated inventory, and a Friendly+ questline.
- **Actions:** [`data/actions/scripts/reputation_donation.lua`](../../data/actions/scripts/reputation_donation.lua) and [`data/actions/scripts/reputation_quest.lua`](../../data/actions/scripts/reputation_quest.lua) provide donation and quest gating examples.
- **Creaturescript:** [`data/creaturescripts/scripts/reputation_kill.lua`](../../data/creaturescripts/scripts/reputation_kill.lua) awards/penalizes reputation on protected kills.
- **Talk actions:** [`data/scripts/talkactions/reputation.lua`](../../data/scripts/talkactions/reputation.lua) implements `!rep` plus `/addrep`, `/setrep`, `/reptier`, and `/economy` admin commands.
- **Tests:** [`data/scripts/lib/tests/reputation_tests.lua`](../../data/scripts/lib/tests/reputation_tests.lua) contains assertion-based sanity checks for tier ordering and config integrity.

## Hooking NPCs
1. Require the library via `lib.lua` (already added).
2. After setting up an NPC handler, bind the faction:
   ```lua
   ReputationEconomy.setNpcFaction(npcHandler, 'Traders Guild', { npcName = 'Merchant Name' })
   ```
3. Optional: pass metadata when adding items to gate tiers or economy thresholds:
   ```lua
   shopModule:addBuyableItem({'guild crest'}, 24774, { price = 12000, meta = { minTier = 'Honored', economyMin = 100000 } })
   ```
Existing shop scripts automatically call the unified price pipeline with reputation and economy modifiers.

## Commands
- `!rep` – show personal standings, tiers, and current economy state per faction.
- `/addrep <player> <faction> <amount>` – adjust reputation by delta.
- `/setrep <player> <faction> <amount>` – set reputation to an absolute value.
- `/reptier <player> <faction>` – report tier.
- `/economy <faction>` – view pool and modifier. *(Admin commands require access level.)*

## Manual Test Plan
1. **Migration:** run the migration and verify new tables with `DESCRIBE faction_economy;`.
2. **NPC Trade:** with a neutral character, open the quartermaster shop and confirm price hint text; observe higher prices when faction pool is low (simulate via DB update) and reduced prices after donations.
3. **Reputation Gain:** buy/sell goods and verify `player_faction_reputation` increases. Use `!rep` to confirm tier thresholds.
4. **Secret Inventory:** raise reputation to Honored (use `/addrep`) and reopen shop to see the gated `guild crest` offer.
5. **Quest Gate:** attempt to use the quest chest (`uniqueid=47010`) before Friendly (should be blocked), then gain Friendly and confirm reward plus reputation bump.
6. **Donations:** deposit coins via donation chest (`uniqueid=47001`/`47002`) and check `faction_economy_history` for logged entries.
7. **Creature Penalties:** kill a configured protected NPC (e.g., `Guild Merchant`) and confirm reputation loss.
8. **Market Fees:** complete a market sale and ensure the `Central Exchange` pool increases after the timer tick.

## Running Tests
Execute the Lua test file from the console if desired:
```lua
>dofile('data/scripts/lib/tests/reputation_tests.lua')
```

## Customization Tips
- Extend `NX_REPUTATION_CONFIG.factions` with new entries and rerun the migration (or manually insert) to add more factions.
- Tune decay or fee rates per faction without restarting the server by editing the config and reloading scripts.
- Use `ReputationEconomy.registerShopItemMetadata` if you need to add gating dynamically within existing scripts.
