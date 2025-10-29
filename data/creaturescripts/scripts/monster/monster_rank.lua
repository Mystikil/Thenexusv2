-- monster_rank.lua
-- Applies randomised rank tiers to monsters on spawn, scaling their stats
-- and rewards so that encounters feel more varied.

local STORAGE = {
    rank = 945000,
    dmgMultiplier = 945001,
    xpMultiplier = 945002
}

local RANKS = {
    {
        name = "Minion",
        storage = 1,
        weight = 55,
        statMultiplier = 1.0,
        speedMultiplier = 1.0,
        damageMultiplier = 1.0,
        experienceMultiplier = 1.0
    },
    {
        name = "Elite",
        storage = 2,
        weight = 25,
        statMultiplier = 1.5,
        speedMultiplier = 1.2,
        damageMultiplier = 1.5,
        experienceMultiplier = 1.5
    },
    {
        name = "Champion",
        storage = 3,
        weight = 12,
        statMultiplier = 2.0,
        speedMultiplier = 1.35,
        damageMultiplier = 2.0,
        experienceMultiplier = 2.0
    },
    {
        name = "Boss",
        storage = 4,
        weight = 6,
        statMultiplier = 3.0,
        speedMultiplier = 1.5,
        damageMultiplier = 3.0,
        experienceMultiplier = 3.0
    },
    {
        name = "Overlord",
        storage = 5,
        weight = 2,
        statMultiplier = 4.0,
        speedMultiplier = 1.75,
        damageMultiplier = 4.0,
        experienceMultiplier = 4.0
    }
}

local function pickRank()
    local totalWeight = 0
    for _, rank in ipairs(RANKS) do
        totalWeight = totalWeight + rank.weight
    end

    if totalWeight <= 0 then
        return RANKS[1]
    end

    local roll = math.random(totalWeight)
    for _, rank in ipairs(RANKS) do
        roll = roll - rank.weight
        if roll < 0 then
            return rank
        end
    end

    return RANKS[#RANKS]
end

local function scaleHealth(monster, rank)
    local baseMax = monster:getMaxHealth()
    local desiredMax = math.max(1, math.floor(baseMax * rank.statMultiplier))
    if desiredMax ~= baseMax then
        monster:setMaxHealth(desiredMax)
        monster:addHealth(desiredMax)
    end
end

local function scaleSpeed(monster, rank)
    local baseSpeed = monster:getBaseSpeed()
    if not baseSpeed or baseSpeed <= 0 then
        return
    end

    local desired = math.max(1, math.floor(baseSpeed * rank.speedMultiplier))
    local current = monster:getSpeed() or baseSpeed
    local delta = desired - current
    if delta ~= 0 then
        monster:changeSpeed(delta)
    end
end

local function decorateName(monster, rank)
    local monsterType = monster:getType()
    local baseName = monsterType and monsterType:getName() or monster:getName()
    local rankedName = string.format("%s [%s]", baseName, rank.name)

    if monster.setName then
        monster:setName(rankedName)
    end
end

function onThink(monster)
    if not monster or not monster:isMonster() then
        return true
    end

    -- Prevent reapplying the rank adjustments on subsequent think ticks.
    if monster:getStorageValue(STORAGE.rank) ~= -1 then
        return true
    end

    local rank = pickRank()

    monster:setStorageValue(STORAGE.rank, rank.storage)
    monster:setStorageValue(STORAGE.dmgMultiplier, math.floor(rank.damageMultiplier * 1000))
    monster:setStorageValue(STORAGE.xpMultiplier, math.floor(rank.experienceMultiplier * 1000))

    scaleHealth(monster, rank)
    scaleSpeed(monster, rank)
    decorateName(monster, rank)

    if monster.unregisterEvent then
        monster:unregisterEvent('MonsterRankThink')
    end

    return true
end

local function getRankFromStorage(monster)
    local stored = monster:getStorageValue(STORAGE.rank)
    if stored and stored ~= -1 then
        for _, rank in ipairs(RANKS) do
            if rank.storage == stored then
                return rank
            end
        end
    end
    return nil
end

local function applyBonusExperience(monster, killer)
    if not killer or not killer:isPlayer() then
        return
    end

    local rank = getRankFromStorage(monster)
    if not rank then
        return
    end

    local monsterType = monster:getType()
    if not monsterType then
        return
    end

    local baseExp = monsterType:getExperience() or 0
    if baseExp <= 0 then
        return
    end

    local desired = math.floor(baseExp * rank.experienceMultiplier)
    local bonus = math.max(0, desired - baseExp)
    if bonus > 0 then
        killer:addExperience(bonus, true)
    end
end

local function logKill(monster, killer)
    local rank = getRankFromStorage(monster)
    if not rank then
        return
    end

    local killerName = killer and killer:isPlayer() and killer:getName() or "unknown"
    print(string.format("[MonsterRank] %s defeated a %s [%s]", killerName, monster:getName(), rank.name))
end

function onDeath(monster, corpse, killer, mostDamageKiller, unjustified, mostDamageUnjustified)
    if not monster or not monster:isMonster() then
        return true
    end

    applyBonusExperience(monster, killer)
    logKill(monster, killer or mostDamageKiller)

    return true
end
