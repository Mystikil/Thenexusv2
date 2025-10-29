local ActivityManager = ActivityManager or dofile('data/scripts/lib/activity_manager.lua')
local function sendLines(player, lines)
    if #lines == 0 then
        player:sendTextMessage(MESSAGE_STATUS_SMALL, 'No entries found.')
        return
    end
    for _, line in ipairs(lines) do
        player:sendTextMessage(MESSAGE_INFO_DESCR, line)
    end
end

local function parseId(param)
    local value = tonumber(param)
    if not value then
        return nil
    end
    return value
end

function onSay(player, words, param)
    local command = words
    local args = param or ''
    if command == '!dungeonid' then
        local id = parseId(args)
        if not id then
            player:sendTextMessage(MESSAGE_STATUS_SMALL, 'Usage: !dungeonid <id>')
            return false
        end
        local ok, reason = ActivityManager.enter(player, id)
        if not ok then
            player:sendTextMessage(MESSAGE_STATUS_SMALL, reason)
        end
        return false
    end

    local action, rest = args:match('^(%S+)%s*(.*)$')
    action = action or 'list'
    rest = rest or ''

    if action == 'list' or action == '' then
        local rows = ActivityManager.list(player, 'dungeon')
        sendLines(player, rows)
    elseif action == 'id' then
        local id = parseId(rest)
        if not id then
            player:sendTextMessage(MESSAGE_STATUS_SMALL, 'Usage: !dungeon id <id>')
            return false
        end
        local ok, reason = ActivityManager.enter(player, id)
        if not ok then
            player:sendTextMessage(MESSAGE_STATUS_SMALL, reason)
        end
    elseif action == 'info' then
        local id = parseId(rest)
        local message = ActivityManager.info(player, id)
        player:sendTextMessage(MESSAGE_INFO_DESCR, message)
    elseif action == 'leave' then
        local ok, reason = ActivityManager.leave(player)
        if not ok then
            player:sendTextMessage(MESSAGE_STATUS_SMALL, reason)
        end
    else
        player:sendTextMessage(MESSAGE_STATUS_SMALL, 'Usage: !dungeon list|id <id>|info [id]|leave')
    end
    return false
end
