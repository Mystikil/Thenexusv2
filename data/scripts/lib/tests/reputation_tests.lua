if _G.__REPUTATION_TESTS__ then
    return true
end
_G.__REPUTATION_TESTS__ = true

if not _G.__REPUTATION_SYSTEM_ENABLED or type(ReputationEconomy) ~= 'table' or not ReputationEconomy.getFactionId then
    print('[REP/ECO][tests] reputation tests skipped (system disabled)')
    return true
end

assert(ReputationEconomy.getTierForValue(-1200).name == 'Hated', 'Tier lookup failed for hated range')
assert(ReputationEconomy.getTierForValue(0).name == 'Neutral', 'Tier lookup failed for neutral range')
assert(ReputationEconomy.getTierIndex('Friendly') < ReputationEconomy.getTierIndex('Honored'), 'Tier ordering mismatch')

local faction = NX_REPUTATION_CONFIG.factions['Traders Guild']
assert(faction ~= nil, 'Missing Traders Guild config')
assert(faction.fees.npcBuy <= 0.05, 'Unexpected buy fee threshold')

return true
