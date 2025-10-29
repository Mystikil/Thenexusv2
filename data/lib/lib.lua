local trace = trace or { checkpoint = function() end }
trace.checkpoint('lib.lua:begin')

-- Compatibility library for our old Lua API AND Compatibility with OPCODE JSON
dofile('data/lib/compat/compat.lua')
dofile('data/lib/compat/compat_extras.lua')
dofile('data/lib/compat/json.lua')

-- Core API functions implemented in Lua
dofile('data/lib/core/core.lua')

-- Debugging helper function for Lua developers
dofile('data/lib/debugging/dump.lua')
dofile('data/lib/debugging/lua_version.lua')

-- Serialization helpers
dofile('data/lib/serialization.lua')
dofile('data/lib/economy.lua')
dofile('data/lib/nx_rank.lua')
dofile('data/lib/nx_boss.lua')

dofile('data/lib/activities/activity_config.lua')
dofile('data/lib/activities/activity_monsters.lua')
dofile('data/lib/activities/activity_features.lua')
dofile('data/lib/activities/activity_unlocks.lua')
dofile('data/scripts/lib/activity_permadeath.lua')
dofile('data/scripts/lib/activity_manager.lua')

local configManagerAvailable = rawget(_G, 'configManager') ~= nil
local configKeysAvailable = rawget(_G, 'configKeys') ~= nil
local reputationEnabled = true
local economyEnabled = true
if configManagerAvailable and configKeysAvailable then
    reputationEnabled = configManager.getBoolean(configKeys.ENABLE_REPUTATION_SYSTEM)
    economyEnabled = configManager.getBoolean(configKeys.ENABLE_ECONOMY_SYSTEM)
end

_G.__REPUTATION_SYSTEM_ENABLED = reputationEnabled
_G.__ECONOMY_SYSTEM_ENABLED = economyEnabled

dofile('data/lib/nx_reputation_config.lua')

if reputationEnabled or economyEnabled then
    trace.checkpoint('rep_eco:lib:begin')
    dofile('data/lib/nx_reputation.lua')
    trace.checkpoint('rep_eco:lib:end')
else
    trace.checkpoint('rep_eco:lib:disabled')
    -- Load the reputation runtime for its disabled-mode stubs to keep callers safe.
    dofile('data/lib/nx_reputation.lua')
end

trace.checkpoint('lib.lua:end')
