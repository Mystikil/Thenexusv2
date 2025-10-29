local trace = trace or { checkpoint = function() end }
trace.checkpoint('rep_eco:globalevents/reputation_economy.lua:begin')

local reputationEnabled = _G.__REPUTATION_SYSTEM_ENABLED ~= false
local economyEnabled = _G.__ECONOMY_SYSTEM_ENABLED ~= false

local function log(message)
    print('[ReputationEconomy] ' .. message)
end

local startup = GlobalEvent('ReputationStartup')
function startup.onStartup()
    if ReputationEconomy and (reputationEnabled or economyEnabled) then
        ReputationEconomy.onStartup()
        log('initialized pools and caches')
    end
    return true
end
startup:register()

local tick = GlobalEvent('ReputationEconomyTick')
tick:interval(60000)
function tick.onThink(interval)
    if not ReputationEconomy or not economyEnabled then
        return true
    end
    local applied = ReputationEconomy.flushEconomyLedger()
    if applied > 0 then
        log('applied ' .. applied .. ' ledger entries')
    end
    local marketProcessed = ReputationEconomy.captureMarketFees()
    if marketProcessed > 0 then
        log('captured ' .. marketProcessed .. ' market transactions')
    end
    return true
end
tick:register()

local decay = GlobalEvent('ReputationDecay')
decay:time('05:00:00')
function decay.onTime(interval)
    if ReputationEconomy and reputationEnabled then
        local decayed = ReputationEconomy.applyDecay()
        if decayed > 0 then
            log('applied decay to ' .. decayed .. ' player rows')
        end
    end
    return true
end
decay:register()

trace.checkpoint('rep_eco:globalevents/reputation_economy.lua:end')
