local ActivityUnlocks = ActivityUnlocks or dofile('data/lib/activities/activity_unlocks.lua')

function onUse(player, item, fromPosition, target, toPosition, isHotkey)
        player:setStorageValue(61101, 1)
        player:setStorageValue(61102, 1)
        ActivityUnlocks.grant(player, 101)
        player:sendTextMessage(MESSAGE_EVENT_ADVANCE, 'You earned access to The Ember Depths!')
        return true
end
