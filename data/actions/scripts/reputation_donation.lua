local trace = trace or { checkpoint = function() end }
trace.checkpoint('rep_eco:actions/reputation_donation.lua:begin')

if not (_G.__REPUTATION_SYSTEM_ENABLED) then
    local disabledMessage = 'Reputation/Economy system is currently disabled.'
    function onUse(player, item, fromPosition, target, toPosition, isHotkey)
        if player then
            player:sendCancelMessage(disabledMessage)
        end
        return true
    end
    trace.checkpoint('rep_eco:actions/reputation_donation.lua:disabled')
    return
end

local config = NX_REPUTATION_CONFIG

local function collectDonationItems(player, factionConfig)
    local donationItems = factionConfig.defaults and factionConfig.defaults.donationItems or {}
    local totalValue = 0
    local removed = {}
    for itemId, value in pairs(donationItems) do
        local count = player:getItemCount(itemId)
        if count > 0 and value > 0 then
            player:removeItem(itemId, count)
            totalValue = totalValue + (value * count)
            removed[#removed + 1] = string.format('%dx %s', count, ItemType(itemId):getName())
        end
    end
    return totalValue, removed
end

function onUse(player, item, fromPosition, target, toPosition, isHotkey)
    local entry = config.donationChests[item:getActionId()]
    if not entry then
        return false
    end

    local factionName = entry.faction
    local factionId = ReputationEconomy.getFactionId(factionName)
    if not factionId then
        player:sendCancelMessage('This donation chest has not been configured correctly.')
        return true
    end

    local factionConfig = ReputationEconomy.getFactionConfig(factionId)
    local totalValue, removedItems = collectDonationItems(player, factionConfig or {})
    if totalValue <= 0 then
        player:sendTextMessage(MESSAGE_INFO_DESCR, 'Place approved goods in your backpack before donating.')
        return true
    end

    local multiplier = (factionConfig.reputation and factionConfig.reputation.donationMultiplier) or 1
    local netValue = math.floor(totalValue * multiplier)
    ReputationEconomy.queueEconomyDelta(factionId, netValue, 'donation', player:getGuid())

    if entry.reputationPerValue and entry.reputationPerValue > 0 then
        local repGain = math.floor(netValue * entry.reputationPerValue)
        ReputationEconomy.addReputation(player, factionId, repGain, 'donation', {
            items = removedItems,
            chest = item:getActionId()
        })
    end

    player:sendTextMessage(MESSAGE_EVENT_ADVANCE, string.format('You donate goods worth %d gold to the %s.', netValue, factionName))
    if entry.broadcast then
        Game.broadcastMessage(string.format('%s donated to the %s.', player:getName(), factionName), MESSAGE_EVENT_ADVANCE)
    end
    item:getPosition():sendMagicEffect(CONST_ME_MAGIC_BLUE)
    return true
end

trace.checkpoint('rep_eco:actions/reputation_donation.lua:end')
