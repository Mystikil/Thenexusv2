local ActivityPermadeath = rawget(_G, 'ActivityPermadeath') or {}

local CONFIRM_STORAGE_BASE = 930000

local function getConfirmStorage(activityId)
    return CONFIRM_STORAGE_BASE + activityId
end

function ActivityPermadeath.needsConfirm(player, activity)
    local permadeath = activity and activity.permadeath
    if not permadeath or permadeath.mode == 'off' then
        return false
    end
    if not permadeath.confirmOnce then
        return false
    end
    local storage = getConfirmStorage(activity.id)
    return player:getStorageValue(storage) ~= 1
end

function ActivityPermadeath.confirm(player, activityId)
    local activity = ActivityManager and ActivityManager.getActivity(activityId)
    if not activity then
        return false, 'Unknown activity.'
    end
    local permadeath = activity.permadeath
    if not permadeath or permadeath.mode == 'off' then
        return false, 'This activity does not require confirmation.'
    end
    local storage = getConfirmStorage(activityId)
    player:setStorageValue(storage, 1)
    return true, string.format('Permadeath confirmed for %s (%s).', activity.name, permadeath.mode)
end

function ActivityPermadeath.reset(player, activityId)
    local storage = getConfirmStorage(activityId)
    player:setStorageValue(storage, -1)
end

local function applyInventoryLoss(player, activity, config)
    local percent = math.max(0, math.min(1, config.dropPercent or 0))
    if percent <= 0 then
        return
    end
    local removed = 0
    local inventorySlots = { CONST_SLOT_HEAD, CONST_SLOT_NECKLACE, CONST_SLOT_BACKPACK, CONST_SLOT_ARMOR,
        CONST_SLOT_RIGHT, CONST_SLOT_LEFT, CONST_SLOT_LEGS, CONST_SLOT_FEET, CONST_SLOT_RING, CONST_SLOT_AMMO }
    for _, slot in ipairs(inventorySlots) do
        local item = player:getSlotItem(slot)
        if item then
            local stack = item:getCount()
            if stack > 0 then
                local toRemove = math.max(1, math.floor(stack * percent))
                removed = removed + toRemove
                item:remove(toRemove)
            else
                item:remove()
                removed = removed + 1
            end
        end
    end
    print(string.format('[ACTIVITY:%d:%s] permadeath inventory loss=%d (target %.2f%%)', activity.id, activity.kind, removed, percent))
end

local function applyExpLoss(player, activity, config)
    local percent = math.max(0, math.min(1, config.expPercent or 0))
    if percent <= 0 then
        return
    end
    local currentExp = player:getExperience()
    local loss = math.floor(currentExp * percent)
    if loss <= 0 then
        return
    end
    player:removeExperience(loss)
    print(string.format('[ACTIVITY:%d:%s] permadeath exp loss=%d (%.2f%%)', activity.id, activity.kind, loss, percent))
end

function ActivityPermadeath.onPlayerDeath(player)
    if not ActivityManager then
        return
    end
    local inside, run = ActivityManager.inside(player)
    if not inside or not run then
        return
    end
    local activity = run.activity
    if not activity then
        return
    end
    local permadeath = activity.permadeath
    if not permadeath or permadeath.mode == 'off' then
        return
    end
    if permadeath.mode == 'character' then
        player:remove()
        print(string.format('[ACTIVITY:%d:%s] permadeath character removal for %s', activity.id, activity.kind, player:getName()))
    elseif permadeath.mode == 'inventory' then
        applyInventoryLoss(player, activity, permadeath)
    elseif permadeath.mode == 'exp' then
        applyExpLoss(player, activity, permadeath)
    end
    if permadeath.broadcast then
        Game.broadcastMessage(string.format('[PERMADEATH] %s perished in %s.', player:getName(), activity.name), MESSAGE_EVENT_ADVANCE)
    end
end

ActivityPermadeath.getConfirmStorage = getConfirmStorage
_G.ActivityPermadeath = ActivityPermadeath
return ActivityPermadeath
