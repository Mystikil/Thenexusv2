local trace = trace or { checkpoint = function() end }
trace.checkpoint('rep_eco:scripts/_rep_eco_sanity.lua:begin')

local tablesToCheck = {
    'factions',
    'npc_factions',
    'player_faction_reputation',
    'player_faction_reputation_log',
    'faction_economy',
    'faction_economy_history',
    'faction_economy_ledger',
    'faction_market_cursor',
}

for _, tableName in ipairs(tablesToCheck) do
    local query = string.format('SELECT COUNT(*) AS `count` FROM `%s` LIMIT 1', tableName)
    local resultId = db.storeQuery(query)
    if not resultId then
        print(string.format('[REP/ECO] table missing: %s (ensure migration 33 ran or disable the reputation/economy feature toggles)', tableName))
        trace.checkpoint('rep_eco:scripts/_rep_eco_sanity.lua:fail')
        return false
    end
    result.free(resultId)
end

trace.checkpoint('rep_eco:scripts/_rep_eco_sanity.lua:ok')
return true

