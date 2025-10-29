v1 change log:
--[[
Instancing system - README / Information
======================================

Overview
--------
This instancing system provides isolated copies of maps/areas for players or parties.
Each instance keeps its own state (mobs, items, timers) and can be configured with a timeout.
Designed to be lightweight and easy to integrate with existing scripts.

Main features
-------------
- Per-player and per-party instances
- Configurable default duration and per-instance overrides
- Simple Lua API for creation, lookup and removal
- Optional automatic reattachment on player login while instance is active
- Safety checks for teleporting and access control

Configuration (example)
-----------------------
-- Add to your config table or a dedicated instancing config file:
instancing = {
	enabled = true,                 -- master toggle
	default_duration = 1800,        -- seconds (30 minutes)
	max_instances = 500,            -- safety cap to avoid resource exhaustion
	allow_party_instances = true,   -- allow instances owned by parties
	cleanup_interval = 60,          -- seconds between garbage collection runs
}

API (common functions)
----------------------
These are the recommended functions the instance module should expose:
- createInstance(templateId, ownerType, ownerId, duration) -> instanceId
		templateId: id of the layout/template to clone
		ownerType: "player" or "party"
		ownerId: playerId or partyId
		duration: optional override in seconds

- getInstance(instanceId) -> instanceTable or nil
- getPlayerInstance(playerId) -> instanceId or nil
- removeInstance(instanceId, reason)
- extendInstance(instanceId, extraSeconds)
- isPositionInInstance(pos, instanceId) -> boolean
- listInstances() -> table of active instance ids

Templates & Data
----------------
Templates should define the map layout and initial spawn state. Store templates under:
	data/instances/templates/
Use a consistent template id scheme. Templates must be cloneable (maps/layers/spawns).

Usage examples
--------------
-- Creating an instance for a player:
local instId = instancing.createInstance("dungeon01", "player", player:getId(), 3600)

-- Creating a party instance:
local instId = instancing.createInstance("boss_room", "party", partyId)

-- Checking player's instance on login:
local instId = instancing.getPlayerInstance(player:getId())
if instId then
	instancing.reattachPlayerToInstance(player, instId)
end

Best practices
--------------
- Keep instance-local state inside the instance table to prevent leaks.
- Use the provided instance teleport/lock helpers when moving players or spawning mobs.
- Enforce max_instances and reasonable durations on public servers.
- Clean up timers, event handlers and references when removing an instance.

Troubleshooting
---------------
- Excessive memory/growth: lower default_duration or max_instances, add monitoring.
- Players stuck out of instance: ensure reattach logic runs on login and that teleport uses instance-safe coords.
- Overlap/conflicts: ensure templates use distinct layers or lock entry points.

Future ideas
------------
- Persist instances to DB for long-running instances / progress saves.
- Dynamic scaling of spawns based on party size.
- Per-instance permission sets and configurable reward split.

Changelog
---------
v1.0 - Initial release of the instancing system (basic create/get/remove, timeouts, per-player/party ownership).
]]
