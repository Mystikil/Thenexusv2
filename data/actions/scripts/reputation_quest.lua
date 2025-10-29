local trace = trace or { checkpoint = function() end }
trace.checkpoint('rep_eco:actions/reputation_quest.lua:begin')

if not (_G.__REPUTATION_SYSTEM_ENABLED) then
    local disabledMessage = 'Reputation/Economy system is currently disabled.'
    function onUse(player, item, fromPosition, target, toPosition, isHotkey)
        if player then
            player:sendCancelMessage(disabledMessage)
        end
        return true
    end
    trace.checkpoint('rep_eco:actions/reputation_quest.lua:disabled')
    return
end

local questConfig = NX_REPUTATION_CONFIG.questExample

function onUse(player, item, fromPosition, target, toPosition, isHotkey)
    local factionId = ReputationEconomy.getFactionId(questConfig.requiredFaction)
    if not factionId then
        player:sendCancelMessage('No faction is bound to this relic.')
        return true
    end
    if not ReputationEconomy.hasTier(player, factionId, questConfig.requiredTier) then
        player:sendTextMessage(MESSAGE_INFO_DESCR, 'The mechanism refuses to budge for those the guild does not trust.')
        return true
    end
    if player:getStorageValue(questConfig.completionStorage) > 0 then
        player:sendTextMessage(MESSAGE_INFO_DESCR, 'The cache is empty; you already claimed this reward.')
        return true
    end
    player:addItem(2160, 1)
    player:setStorageValue(questConfig.completionStorage, 1)
    ReputationEconomy.addReputation(player, factionId, 150, 'quest_reward', { source = 'quartermaster_chest' })
    player:sendTextMessage(MESSAGE_EVENT_ADVANCE, 'You recover a cache of guild marks and feel your standing improve.')
    item:getPosition():sendMagicEffect(CONST_ME_TUTORIALARROW)
    return true
end

trace.checkpoint('rep_eco:actions/reputation_quest.lua:end')
