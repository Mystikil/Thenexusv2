local trace = trace or { checkpoint = function() end }
trace.checkpoint('rep_eco:talkactions/reputation.lua:begin')

local reputationEnabled = _G.__REPUTATION_SYSTEM_ENABLED ~= false
local economyEnabled = _G.__ECONOMY_SYSTEM_ENABLED ~= false

local function registerDisabledTalk(words)
    local talk = TalkAction(words)
    function talk.onSay(player, words, param)
        player:sendCancelMessage('Reputation/Economy system is currently disabled.')
        return false
    end
    talk:separator(' ')
    talk:register()
end

if not reputationEnabled and not economyEnabled then
    for _, words in ipairs({ '!rep', '/addrep', '/setrep', '/reptier', '/economy' }) do
        registerDisabledTalk(words)
    end
    trace.checkpoint('rep_eco:talkactions/reputation.lua:disabled')
    return
end

local function requireAccess(player)
    if not player or not player:isPlayer() then
        return false
    end
    if not player:getGroup():getAccess() then
        player:sendCancelMessage('You do not have access to this command.')
        return false
    end
    return true
end

local function resolveFactionId(param)
    if param == '' then
        return nil
    end
    local id = tonumber(param)
    if id then
        return id
    end
    return ReputationEconomy.getFactionId(param)
end

local function getTargetPlayer(name)
    if not name or name == '' then
        return nil
    end
    return Player(name)
end

local function formatStanding(player, faction)
    local rep = ReputationEconomy.getPlayerReputation(player, faction.id)
    local toNext = ReputationEconomy.pointsToNextTier(rep.value)
    local tierIndex = ReputationEconomy.getTierIndex(rep.tier.name)
    local nextTier = NX_REPUTATION_CONFIG.tiers[tierIndex + 1]
    local parts = {
        string.format('%s: %d (%s)', faction.name, rep.value, rep.tier.name)
    }
    if toNext and toNext > 0 and nextTier then
        parts[#parts + 1] = string.format('%d to %s', toNext, nextTier.name)
    end
    local economy = ReputationEconomy.getEconomyState(faction.id)
    parts[#parts + 1] = string.format('Economy: %s (%.2f%%)', economy.label, (economy.modifier - 1) * 100)
    return table.concat(parts, ' | ')
end

local repTalk = TalkAction('!rep')
repTalk:separator(' ')
function repTalk.onSay(player, words, param)
    if not player or not player:isPlayer() then
        return false
    end
    if not ReputationEconomy or not ReputationEconomy.getAllFactions then
        player:sendTextMessage(MESSAGE_STATUS_CONSOLE_BLUE, 'Reputation/Economy system is currently disabled.')
        return false
    end

    local lines = {}
    for _, faction in ipairs(ReputationEconomy.getAllFactions()) do
        lines[#lines + 1] = formatStanding(player, faction)
    end
    player:sendTextMessage(MESSAGE_EVENT_ADVANCE, table.concat(lines, '\n'))
    return false
end
repTalk:register()

local addRep = TalkAction('/addrep')
addRep:separator(' ')
function addRep.onSay(player, words, param)
    if not requireAccess(player) then
        return true
    end
    local targetName, factionParam, amountStr = param:match('(%S+)%s+(%S+)%s+(-?%d+)')
    if not targetName or not factionParam or not amountStr then
        player:sendCancelMessage('Usage: /addrep <player> <faction> <amount>')
        return false
    end

    local target = getTargetPlayer(targetName)
    if not target then
        player:sendCancelMessage('Player must be online.')
        return false
    end

    local factionId = resolveFactionId(factionParam)
    if not factionId then
        player:sendCancelMessage('Unknown faction.')
        return false
    end

    local amount = tonumber(amountStr) or 0
    ReputationEconomy.addReputation(target, factionId, amount, 'admin_add', { admin = player:getName() })
    player:sendTextMessage(
        MESSAGE_EVENT_ADVANCE,
        string.format(
            'Added %d reputation for %s in faction %s.',
            amount,
            target:getName(),
            ReputationEconomy.getFactionConfig(factionId).name
        )
    )
    return false
end
addRep:register()

local setRep = TalkAction('/setrep')
setRep:separator(' ')
function setRep.onSay(player, words, param)
    if not requireAccess(player) then
        return true
    end
    local targetName, factionParam, amountStr = param:match('(%S+)%s+(%S+)%s+(-?%d+)')
    if not targetName or not factionParam or not amountStr then
        player:sendCancelMessage('Usage: /setrep <player> <faction> <amount>')
        return false
    end

    local target = getTargetPlayer(targetName)
    if not target then
        player:sendCancelMessage('Player must be online.')
        return false
    end

    local factionId = resolveFactionId(factionParam)
    if not factionId then
        player:sendCancelMessage('Unknown faction.')
        return false
    end

    local desired = tonumber(amountStr) or 0
    local current = ReputationEconomy.getPlayerReputation(target, factionId, true).value
    ReputationEconomy.addReputation(target, factionId, desired - current, 'admin_set', { admin = player:getName() })
    player:sendTextMessage(
        MESSAGE_EVENT_ADVANCE,
        string.format(
            'Set %s reputation for %s to %d.',
            ReputationEconomy.getFactionConfig(factionId).name,
            target:getName(),
            desired
        )
    )
    return false
end
setRep:register()

local repTier = TalkAction('/reptier')
repTier:separator(' ')
function repTier.onSay(player, words, param)
    if not requireAccess(player) then
        return true
    end

    local targetName, factionParam = param:match('(%S+)%s+(%S+)')
    if not targetName or not factionParam then
        player:sendCancelMessage('Usage: /reptier <player> <faction>')
        return false
    end

    local target = getTargetPlayer(targetName)
    if not target then
        player:sendCancelMessage('Player must be online.')
        return false
    end

    local factionId = resolveFactionId(factionParam)
    if not factionId then
        player:sendCancelMessage('Unknown faction.')
        return false
    end

    local info = ReputationEconomy.getPlayerReputation(target, factionId)
    player:sendTextMessage(
        MESSAGE_EVENT_ADVANCE,
        string.format('%s stands at %d (%s).', target:getName(), info.value, info.tier.name)
    )
    return false
end
repTier:register()

local economy = TalkAction('/economy')
economy:separator(' ')
function economy.onSay(player, words, param)
    if not requireAccess(player) then
        return true
    end

    if param == '' then
        player:sendCancelMessage('Usage: /economy <faction>')
        return false
    end

    local factionId = resolveFactionId(param)
    if not factionId then
        player:sendCancelMessage('Unknown faction.')
        return false
    end

    local state = ReputationEconomy.getEconomyState(factionId)
    local faction = ReputationEconomy.getFactionConfig(factionId)
    player:sendTextMessage(
        MESSAGE_EVENT_ADVANCE,
        string.format(
            '%s pool: %d | %s (%.2f%% modifier)',
            faction.name,
            state.pool,
            state.label,
            (state.modifier - 1) * 100
        )
    )
    return false
end
economy:register()

trace.checkpoint('rep_eco:talkactions/reputation.lua:end')
