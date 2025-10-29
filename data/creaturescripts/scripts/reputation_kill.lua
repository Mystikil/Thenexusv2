local trace = trace or { checkpoint = function() end }
trace.checkpoint('rep_eco:creaturescripts/reputation_kill.lua:begin')

if not (_G.__REPUTATION_SYSTEM_ENABLED) then
    local disabledMessage = 'Reputation/Economy system is currently disabled.'
    local notifiedPlayers = {}
    function onKill(creature, target)
        local player = creature and creature:getPlayer()
        if player then
            local guid = player:getGuid()
            if guid and not notifiedPlayers[guid] then
                player:sendTextMessage(MESSAGE_STATUS_CONSOLE_BLUE, disabledMessage)
                notifiedPlayers[guid] = true
            end
        end
        return true
    end
    trace.checkpoint('rep_eco:creaturescripts/reputation_kill.lua:disabled')
    return
end

local config = NX_REPUTATION_CONFIG

function onKill(creature, target)
    local player = creature:getPlayer()
    if not player then
        return true
    end
    if not target or not target:isMonster() then
        return true
    end
    local name = target:getName()
    local protected = config.creatures.protected[name]
    if protected then
        local factionId = ReputationEconomy.getFactionId(protected.faction)
        if factionId then
            ReputationEconomy.addReputation(player, factionId, -math.abs(protected.penalty or 0), 'kill_penalty', { target = name })
        end
    end
    local ally = config.creatures.allies[name]
    if ally then
        local factionId = ReputationEconomy.getFactionId(ally.faction)
        if factionId then
            ReputationEconomy.addReputation(player, factionId, math.abs(ally.reward or 0), 'kill_reward', { target = name })
        end
    end
    return true
end

trace.checkpoint('rep_eco:creaturescripts/reputation_kill.lua:end')
