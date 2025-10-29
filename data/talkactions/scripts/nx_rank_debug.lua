-- nx_rank_debug.lua
-- Administrative helpers for inspecting and manipulating the monster rank
-- system at runtime.

local function splitWords(str)
    local result = {}
    for word in str:gmatch("%S+") do
        table.insert(result, word)
    end
    return result
end

local function getTargetMonster(player)
    local target = player:getTarget()
    if target and target:isMonster() then
        return target
    end
    player:sendCancelMessage("No monster target found.")
    return nil
end

local function handleGet(player)
    local monster = getTargetMonster(player)
    if not monster then
        return
    end
    local key = NX_RANK.getRankKey(monster) or "unknown"
    local tier = NX_RANK.getRankForCreature(monster)
    local idx = tier and tier.index or -1
    player:sendTextMessage(MESSAGE_STATUS_CONSOLE_BLUE, string.format("Rank %s (index %d)", key, idx))
end

local function handleSet(player, args)
    if #args < 2 then
        player:sendCancelMessage("Usage: /rank set <tier>")
        return
    end
    local tierKey = args[2]:upper()
    local tier = NX_RANK.getTier(tierKey)
    if not tier then
        player:sendCancelMessage("Unknown tier.")
        return
    end
    local monster = getTargetMonster(player)
    if not monster then
        return
    end
    NX_RANK.setRank(monster, tierKey)
    NX_RANK.applyTier(monster, tier)
    NX_RANK.decorateName(monster, tierKey)
    player:sendTextMessage(MESSAGE_STATUS_CONSOLE_BLUE, "Rank updated.")
end

local function handleDist(player, args)
    local area = args[2]
    local monsterKey = args[3]
    local dist = NX_RANK.getActiveDist(area, monsterKey)
    local lines = {"Active distribution:"}
    for _, key in ipairs({"F","E","D","C","B","A","S","SS","SSS"}) do
        table.insert(lines, string.format("%s: %.2f%%", key, (dist[key] or 0) * 100))
    end
    player:sendTextMessage(MESSAGE_STATUS_CONSOLE_BLUE, table.concat(lines, "\n"))
end

local function handleSimulate(player, args)
    local count = tonumber(args[2]) or 0
    if count <= 0 then
        player:sendCancelMessage("Usage: /rank simulate <count> [area] [monster]")
        return
    end
    local area = args[3]
    local monsterKey = args[4]
    local tally = {}
    for i = 1, count do
        local rank = NX_RANK.pickRank(area, monsterKey)
        tally[rank] = (tally[rank] or 0) + 1
    end
    local lines = {string.format("Simulation (%d rolls):", count)}
    for _, key in ipairs({"F","E","D","C","B","A","S","SS","SSS"}) do
        local hits = tally[key] or 0
        table.insert(lines, string.format("%s: %d", key, hits))
    end
    player:sendTextMessage(MESSAGE_STATUS_CONSOLE_BLUE, table.concat(lines, "\n"))
end

function onSay(player, words, param)
    if player:getGroup():getId() < 3 then
        player:sendCancelMessage("Insufficient rights.")
        return false
    end
    local args = splitWords(param)
    local sub = args[1] and args[1]:lower() or ""
    if sub == "get" then
        handleGet(player)
    elseif sub == "set" then
        handleSet(player, args)
    elseif sub == "dist" then
        handleDist(player, args)
    elseif sub == "simulate" then
        handleSimulate(player, args)
    else
        player:sendCancelMessage("Usage: /rank get|set|dist|simulate")
    end
    return false
end
