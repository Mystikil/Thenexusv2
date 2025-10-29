local existing = rawget(_G, 'ActivityUnlocks')
if existing then
    return existing
end

local ActivityUnlocks = {}

local function getActivityConfig(id)
    local config = rawget(_G, 'ActivityConfig')
    if not config then
        config = dofile('data/lib/activities/activity_config.lua')
    end
    return config and config[id]
end

local function ensureTable(value)
    if type(value) ~= 'table' then
        return {}
    end
    return value
end

function ActivityUnlocks.hasAll(player, activity)
    local unlock = ensureTable(activity and activity.unlock)
    local missing = {}
    if unlock.storage and player:getStorageValue(unlock.storage) ~= 1 then
        table.insert(missing, 'unlock quest not complete')
    end
    if unlock.questStorages then
        for _, storage in ipairs(unlock.questStorages) do
            if player:getStorageValue(storage) <= 0 then
                table.insert(missing, string.format('quest flag %d missing', storage))
            end
        end
    end
    if unlock.minLevel and player:getLevel() < unlock.minLevel then
        table.insert(missing, string.format('level %d required', unlock.minLevel))
    end
    if unlock.minRep and unlock.minRep.faction and unlock.minRep.tier and __REPUTATION_SYSTEM_ENABLED then
        local factionId = ReputationEconomy and ReputationEconomy.getFactionId(unlock.minRep.faction)
        if not factionId or not ReputationEconomy.hasTier(player, factionId, unlock.minRep.tier) then
            table.insert(missing, string.format('%s reputation tier %s required', unlock.minRep.faction, unlock.minRep.tier))
        end
    elseif unlock.minRep and unlock.minRep.faction and unlock.minRep.tier then
        table.insert(missing, 'reputation system disabled')
    end
    if unlock.keyItemId and player:getItemCount(unlock.keyItemId) <= 0 then
        table.insert(missing, string.format('missing key item %d', unlock.keyItemId))
    end
    return #missing == 0, missing
end

function ActivityUnlocks.consumeKey(player, activity)
    local unlock = ensureTable(activity and activity.unlock)
    if unlock.consumeKey and unlock.keyItemId then
        return player:removeItem(unlock.keyItemId, 1)
    end
    return true
end

function ActivityUnlocks.grant(player, id)
    local activity = getActivityConfig(id)
    if not activity or not activity.unlock or not activity.unlock.storage then
        return false
    end
    player:setStorageValue(activity.unlock.storage, 1)
    return true
end

_G.ActivityUnlocks = ActivityUnlocks
return ActivityUnlocks
