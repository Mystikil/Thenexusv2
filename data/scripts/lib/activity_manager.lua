local ActivityManager = rawget(_G, 'ActivityManager') or {}

local ActivityConfig = rawget(_G, 'ActivityConfig') or dofile('data/lib/activities/activity_config.lua')
local ActivityMonsters = rawget(_G, 'ActivityMonsters') or dofile('data/lib/activities/activity_monsters.lua')
local ActivityFeatures = rawget(_G, 'ActivityFeatures') or dofile('data/lib/activities/activity_features.lua')
local ActivityUnlocks = rawget(_G, 'ActivityUnlocks') or dofile('data/lib/activities/activity_unlocks.lua')
local ActivityPermadeath = rawget(_G, 'ActivityPermadeath') or dofile('data/scripts/lib/activity_permadeath.lua')

local RUN_STORAGE_ID = 940000
local FOCUS_STORAGE_ID = 940001
local BIND_STORAGE_ID = 940002
local LAST_ACTIVITY_STORAGE_ID = 940003

local function toPosition(pos)
    return Position(pos.x, pos.y, pos.z)
end

local function now()
    return os.time()
end

local function ensureTables()
    ActivityManager.activities = ActivityManager.activities or {}
    ActivityManager.activitiesByKind = ActivityManager.activitiesByKind or {}
    ActivityManager.runs = ActivityManager.runs or {}
    ActivityManager.playerRuns = ActivityManager.playerRuns or {}
end

local function registerActivities()
    ensureTables()
    for id, activity in pairs(ActivityConfig) do
        ActivityManager.activities[id] = activity
        ActivityManager.activitiesByKind[activity.kind] = ActivityManager.activitiesByKind[activity.kind] or {}
        ActivityManager.activitiesByKind[activity.kind][id] = activity
    end
end

local function runLog(activity, message, ...)
    print(string.format('[ACTIVITY:%d:%s] %s', activity.id, activity.kind, string.format(message, ...)))
end

local function getRun(uid)
    return ActivityManager.runs[uid]
end

local function getPlayerRun(player)
    return ActivityManager.playerRuns[player:getGuid()]
end

local function setPlayerRun(player, run)
    local guid = player:getGuid()
    if run then
        ActivityManager.playerRuns[guid] = run
        player:setStorageValue(RUN_STORAGE_ID, run.uid)
        player:setStorageValue(LAST_ACTIVITY_STORAGE_ID, run.activity.id)
    else
        ActivityManager.playerRuns[guid] = nil
        player:setStorageValue(RUN_STORAGE_ID, -1)
    end
end

local function markBind(player, activity)
    player:setStorageValue(BIND_STORAGE_ID, now() + (activity.bindSeconds or 0))
end

local function bindRemaining(player)
    local expires = player:getStorageValue(BIND_STORAGE_ID)
    if expires <= 0 then
        return 0
    end
    local delta = expires - now()
    if delta < 0 then
        return 0
    end
    return delta
end

local function formatDuration(seconds)
    local mins = math.floor(seconds / 60)
    local secs = seconds % 60
    return string.format('%02d:%02d', mins, secs)
end

local function hasRule(activity, rule)
    if not activity.specialRules then
        return false
    end
    for _, r in ipairs(activity.specialRules) do
        if r == rule then
            return true
        end
    end
    return false
end

function ActivityManager.getActivity(id)
    ensureTables()
    return ActivityManager.activities[id]
end

function ActivityManager.listActivities(kind)
    ensureTables()
    if kind then
        return ActivityManager.activitiesByKind[kind] or {}
    end
    return ActivityManager.activities
end

local function seedRun(run)
    local activity = run.activity
    if activity.dungeon then
        local mutateChance = activity.dungeon.mutateChance or 0
        if mutateChance > 0 and math.random() < mutateChance then
            run.mutated = true
            runLog(activity, 'rolled mutation for run %d', run.uid)
        end
        if activity.dungeon.timerSeconds then
            run.timerEnds = now() + activity.dungeon.timerSeconds
            run.timerStarted = now()
        end
    end
    if run.mutated and activity.monsterSet then
        local mutatedKey = activity.monsterSet .. '_mutated'
        if ActivityMonsters[mutatedKey] then
            run.monsterSet = mutatedKey
            runLog(activity, 'mutation swapped monster set to %s', mutatedKey)
        else
            run.monsterSet = activity.monsterSet
        end
    else
        run.monsterSet = activity.monsterSet
    end
    ActivityFeatures.onStart(run)
end

local function createRun(activity, leader)
    local cfg = {
        name = activity.name,
        durationSeconds = activity.dungeon and activity.dungeon.timerSeconds or 60 * 60,
        warnAt = {},
        expMult = activity.expRate or 1,
        lootMult = activity.lootRate or 1,
        hpMult = 1,
        dmgMult = 1,
        armorMult = 1,
        entryPos = activity.entryPos,
        exitPos = activity.exitPos,
        bossNames = { activity.bossName },
        partyOnly = activity.type == 'party',
        minLevel = activity.unlock and activity.unlock.minLevel or 0,
        cooldownSeconds = activity.cooldown and activity.cooldown.seconds or 0,
        seed = math.random(0, 2147483647),
    }
    local uid = createInstance(cfg)
    if not uid or uid == 0 then
        return nil, 'Failed to allocate instance.'
    end
    local run = {
        uid = uid,
        activity = activity,
        created = now(),
        players = {},
        leaderGuid = leader and leader:getGuid() or nil,
    }
    ActivityManager.runs[uid] = run
    runLog(activity, 'create uid=%d seed=%d leader=%s', uid, cfg.seed, leader and leader:getName() or 'unknown')
    seedRun(run)
    return run
end

local function findRunByActivity(activityId)
    for _, run in pairs(ActivityManager.runs) do
        if run.activity.id == activityId then
            return run
        end
    end
end

local function ensureRun(activity, leader)
    local existing = findRunByActivity(activity.id)
    if existing then
        return existing
    end
    return createRun(activity, leader)
end

local function teleportPlayer(run, player)
    teleportInto(run.uid, player)
    player:setStorageValue(FOCUS_STORAGE_ID, run.activity.id)
    markBind(player, run.activity)
    run.players[player:getGuid()] = true
    setPlayerRun(player, run)
end

local function validateParty(player, activity)
    if activity.type ~= 'party' then
        return { player }, {}
    end
    local party = player:getParty()
    if not party then
        return nil, { 'You must be in a party.' }
    end
    if party:getLeader():getGuid() ~= player:getGuid() then
        return nil, { 'Only the party leader may start this activity.' }
    end
    local members = party:getMembers()
    local players = { party:getLeader() }
    for _, member in pairs(members) do
        table.insert(players, member)
    end
    local failures = {}
    for _, member in ipairs(players) do
        local ok, reasons = ActivityUnlocks.hasAll(member, activity)
        if not ok then
            failures[member:getName()] = reasons
        end
        if ActivityPermadeath.needsConfirm(member, activity) then
            failures[member:getName()] = failures[member:getName()] or {}
            table.insert(failures[member:getName()], 'needs !confirm ' .. activity.id)
        end
        local cooldown = ActivityManager.cooldownRemaining(member, activity)
        if cooldown > 0 then
            failures[member:getName()] = failures[member:getName()] or {}
            table.insert(failures[member:getName()], string.format('cooldown %s', formatDuration(cooldown)))
        end
        local existing = getPlayerRun(member)
        if existing then
            failures[member:getName()] = failures[member:getName()] or {}
            table.insert(failures[member:getName()], 'already bound to another activity')
        end
    end
    if next(failures) then
        local reasons = {}
        for name, list in pairs(failures) do
            table.insert(reasons, string.format('%s: %s', name, table.concat(list, ', ')))
        end
        return nil, reasons
    end
    return players, {}
end

function ActivityManager.cooldownRemaining(player, activity)
    if not activity.cooldown or not activity.cooldown.storage then
        return 0
    end
    local last = player:getStorageValue(activity.cooldown.storage)
    if last <= 0 then
        return 0
    end
    local expires = last + (activity.cooldown.seconds or 0)
    local remaining = expires - now()
    if remaining < 0 then
        return 0
    end
    return remaining
end

local function applyCooldown(player, activity)
    if activity.cooldown and activity.cooldown.storage then
        player:setStorageValue(activity.cooldown.storage, now())
    end
end

function ActivityManager.enter(player, activityId)
    ensureTables()
    local activity = ActivityManager.activities[activityId]
    if not activity then
        return false, 'Activity not found.'
    end
    local participants, reasons = validateParty(player, activity)
    if not participants then
        runLog(activity, 'enter denied for %s: %s', player:getName(), table.concat(reasons, ' | '))
        return false, table.concat(reasons, ' | ')
    end
    local run, err = ensureRun(activity, player)
    if not run then
        runLog(activity, 'enter failed for %s: %s', player:getName(), err or 'unknown reason')
        return false, err
    end
    if ActivityManager.countPlayers(run) > 0 then
        local allowed = true
        for _, member in ipairs(participants) do
            if not run.players[member:getGuid()] then
                allowed = false
                break
            end
        end
        if not allowed then
            runLog(activity, 'enter denied for %s: run already active', player:getName())
            return false, 'This activity is already in progress.'
        end
    end
    for _, member in ipairs(participants) do
        if not ActivityUnlocks.consumeKey(member, activity) then
            runLog(activity, 'enter denied for %s: key requirement', member:getName())
            ActivityManager.cleanup(run)
            return false, string.format('%s is missing the required key.', member:getName())
        end
    end
    for _, member in ipairs(participants) do
        teleportPlayer(run, member)
        member:sendTextMessage(MESSAGE_EVENT_ADVANCE, string.format('Entering %s (%s).', activity.name, activity.kind))
        runLog(activity, 'enter uid=%d player=%s', run.uid, member:getName())
    end
    return true
end

local function lastFocused(player)
    local id = player:getStorageValue(FOCUS_STORAGE_ID)
    if id <= 0 then
        return nil
    end
    return ActivityManager.activities[id]
end

local function formatStatus(player, activity)
    local inside, run = ActivityManager.inside(player)
    if inside and run and run.activity.id == activity.id then
        return 'In Progress'
    end
    local cooldown = ActivityManager.cooldownRemaining(player, activity)
    if cooldown > 0 then
        return string.format('Cooldown (%s)', formatDuration(cooldown))
    end
    local unlocked = select(1, ActivityUnlocks.hasAll(player, activity))
    if not unlocked then
        return 'Locked'
    end
    return 'Available'
end

function ActivityManager.list(player, kind)
    local rows = {}
    for id, activity in pairs(ActivityManager.listActivities(kind)) do
        local status = formatStatus(player, activity)
        table.insert(rows, { id = id, text = string.format('%d · %s · %s · %s · %s', id, activity.name, activity.type or '-', activity.difficulty or '-', status) })
    end
    table.sort(rows, function(a, b) return a.id < b.id end)
    local result = {}
    for _, row in ipairs(rows) do
        table.insert(result, row.text)
    end
    return result
end

local function runInfo(run)
    local info = {}
    local activity = run.activity
    table.insert(info, string.format('%s (%s) – difficulty %s', activity.name, activity.kind, activity.difficulty or 'N/A'))
    if run.timerEnds then
        table.insert(info, string.format('Timer: %s remaining', formatDuration(math.max(0, run.timerEnds - now()))))
    end
    if run.mutated then
        table.insert(info, 'Mutation: Distorted')
    end
    table.insert(info, string.format('Players: %d', ActivityManager.countPlayers(run)))
    return info
end

function ActivityManager.info(player, activityId)
    ensureTables()
    local inside, run = ActivityManager.inside(player)
    if inside and (not activityId or (run.activity and run.activity.id == activityId)) then
        local info = runInfo(run)
        local cooldown = ActivityManager.cooldownRemaining(player, run.activity)
        table.insert(info, string.format('Cooldown: %s', cooldown > 0 and formatDuration(cooldown) or 'none'))
        if run.activity.permadeath and run.activity.permadeath.mode ~= 'off' then
            table.insert(info, string.format('Permadeath: %s', run.activity.permadeath.mode))
        end
        return table.concat(info, '\n')
    end
    local activity
    if activityId then
        activity = ActivityManager.activities[activityId]
        if activity then
            player:setStorageValue(FOCUS_STORAGE_ID, activity.id)
        end
    else
        activity = lastFocused(player)
    end
    if not activity then
        return 'No activity selected.'
    end
    local info = {
        string.format('%s (%s) – difficulty %s', activity.name, activity.kind, activity.difficulty or 'N/A'),
        string.format('Entry: %s', formatStatus(player, activity)),
    }
    if activity.permadeath and activity.permadeath.mode ~= 'off' then
        table.insert(info, string.format('Permadeath: %s', activity.permadeath.mode))
    end
    if activity.dungeon then
        table.insert(info, string.format('Dungeon timer: %s', activity.dungeon.timerSeconds and formatDuration(activity.dungeon.timerSeconds) or 'none'))
        if activity.dungeon.objectives then
            table.insert(info, 'Objectives: ' .. table.concat(activity.dungeon.objectives, '; '))
        end
    end
    if activity.monsterSet and ActivityMonsters[activity.monsterSet] then
        table.insert(info, 'Monsters: ' .. table.concat(ActivityMonsters[activity.monsterSet], ', '))
    end
    return table.concat(info, '\n')
end

function ActivityManager.leave(player)
    local inside, run = ActivityManager.inside(player)
    if not inside or not run then
        return false, 'You are not inside an activity.'
    end
    if player:isPzLocked() then
        runLog(run.activity, 'leave denied for %s: in combat', player:getName())
        return false, 'You cannot leave during battle.'
    end
    if hasRule(run.activity, 'NoTeleport') then
        runLog(run.activity, 'leave denied for %s: NoTeleport rule', player:getName())
        return false, 'Teleport is disabled in this activity.'
    end
    local remaining = bindRemaining(player)
    if remaining > 0 then
        runLog(run.activity, 'leave denied for %s: bind %d seconds remaining', player:getName(), remaining)
        return false, string.format('You are bound for %s more.', formatDuration(remaining))
    end
    player:teleportTo(toPosition(run.activity.exitPos))
    player:resetToWorldInstance()
    applyCooldown(player, run.activity)
    run.players[player:getGuid()] = nil
    setPlayerRun(player, nil)
    player:setStorageValue(BIND_STORAGE_ID, -1)
    runLog(run.activity, 'leave uid=%d player=%s', run.uid, player:getName())
    if ActivityManager.countPlayers(run) == 0 then
        ActivityManager.cleanup(run)
    end
    return true
end

function ActivityManager.countPlayers(run)
    local count = 0
    for _ in pairs(run.players) do
        count = count + 1
    end
    return count
end

function ActivityManager.cleanup(run)
    ActivityFeatures.onEnd(run)
    for guid in pairs(run.players) do
        ActivityManager.playerRuns[guid] = nil
    end
    ActivityManager.runs[run.uid] = nil
    runLog(run.activity, 'cleanup uid=%d', run.uid)
    closeInstance(run.uid)
end

function ActivityManager.onClear(activityRef, party)
    ensureTables()
    local activity = type(activityRef) == 'table' and activityRef or ActivityManager.activities[activityRef]
    if not activity then
        return
    end
    local run = findRunByActivity(activity.id)
    if not run then
        runLog(activity, 'onClear ignored: no active run')
        return
    end
    local elapsed = run.timerStarted and (now() - run.timerStarted) or nil
    local rank
    if activity.dungeon and activity.dungeon.scoreTiers and elapsed then
        local tiers = { 'S', 'A', 'B', 'C' }
        for _, tier in ipairs(tiers) do
            local threshold = activity.dungeon.scoreTiers[tier]
            if threshold and elapsed <= threshold then
                rank = tier
                break
            end
        end
        rank = rank or 'C'
    end
    runLog(activity, 'clear uid=%d elapsed=%s rank=%s', run.uid, elapsed and tostring(elapsed) or 'n/a', rank or '-')
    if activity.dungeon and party then
        if activity.dungeon.rewardTiers and rank then
            local reward = activity.dungeon.rewardTiers[rank]
            if reward then
                for _, member in ipairs(party) do
                    member:sendTextMessage(MESSAGE_EVENT_ADVANCE, string.format('Dungeon reward tier %s: %s', rank, reward))
                end
            end
        end
        if activity.dungeon.announcer and rank and (rank == 'S' or rank == 'A') then
            local names = {}
            for _, member in ipairs(party) do
                table.insert(names, member:getName())
            end
            local timeStr = elapsed and formatDuration(elapsed) or 'n/a'
            Game.broadcastMessage(string.format('[ACTIVITY:%d:%s] %s cleared %s in %s for rank %s!', activity.id, activity.kind, table.concat(names, ', '), activity.name, timeStr, rank), MESSAGE_EVENT_ADVANCE)
        end
    end
    if party then
        for _, member in ipairs(party) do
            applyCooldown(member, activity)
            setPlayerRun(member, nil)
        end
    end
    ActivityManager.cleanup(run)
end

function ActivityManager.inside(player)
    local run = getPlayerRun(player)
    if not run then
        return false, nil
    end
    return true, run
end

function ActivityManager.onLogin(player)
    local uid = player:getStorageValue(RUN_STORAGE_ID)
    if uid > 0 then
        local run = getRun(uid)
        if run then
            run.players[player:getGuid()] = true
            setPlayerRun(player, run)
            player:setStorageValue(FOCUS_STORAGE_ID, run.activity.id)
            runLog(run.activity, 'rebind player %s on login', player:getName())
        else
            player:setStorageValue(RUN_STORAGE_ID, -1)
        end
    end
end

function ActivityManager.onLogout(player)
    local inside, run = ActivityManager.inside(player)
    if inside and run then
        runLog(run.activity, 'player %s logged out inside', player:getName())
    end
end

registerActivities()
_G.ActivityManager = ActivityManager
return ActivityManager
