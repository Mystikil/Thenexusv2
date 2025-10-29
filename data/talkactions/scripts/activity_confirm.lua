local ActivityPermadeath = ActivityPermadeath or dofile('data/scripts/lib/activity_permadeath.lua')

function onSay(player, words, param)
    local id = tonumber(param)
    if not id then
        player:sendTextMessage(MESSAGE_STATUS_SMALL, 'Usage: !confirm <activityId>')
        return false
    end
    local ok, message = ActivityPermadeath.confirm(player, id)
    if not ok then
        player:sendTextMessage(MESSAGE_STATUS_SMALL, message)
    else
        player:sendTextMessage(MESSAGE_EVENT_ADVANCE, message)
    end
    return false
end
