-- nx_rank_reveal.lua
-- Staff utility for granting temporary or permanent monster rank vision to
-- players for testing purposes.

local function splitWords(str)
    local result = {}
    for word in str:gmatch("%S+") do
        table.insert(result, word)
    end
    return result
end

function onSay(player, words, param)
    if player:getGroup():getId() < 3 then
        player:sendCancelMessage("Insufficient rights.")
        return false
    end
    local args = splitWords(param)
    local sub = args[1] and args[1]:lower() or ""
    if sub == "on" then
        player:setStorageValue(NX_RANK.REVEAL.PERM, 1)
        player:sendTextMessage(MESSAGE_STATUS_CONSOLE_BLUE, "Permanent rank vision enabled.")
    elseif sub == "off" then
        player:setStorageValue(NX_RANK.REVEAL.PERM, -1)
        player:setStorageValue(NX_RANK.REVEAL.TEMP_UNTIL, -1)
        player:sendTextMessage(MESSAGE_STATUS_CONSOLE_BLUE, "Rank vision cleared.")
    elseif sub == "buff" then
        local minutes = tonumber(args[2]) or 10
        local expires = os.time() + minutes * 60
        player:setStorageValue(NX_RANK.REVEAL.TEMP_UNTIL, expires)
        player:sendTextMessage(MESSAGE_STATUS_CONSOLE_BLUE, string.format("Temporary vision for %d minute(s).", minutes))
    else
        player:sendCancelMessage("Usage: /rankvision on|off|buff <minutes>")
    end
    return false
end
