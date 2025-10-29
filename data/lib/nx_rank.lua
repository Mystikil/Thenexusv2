-- nx_rank.lua
-- Centralised configuration and helpers for the monster ranking system.
-- The intention is to keep all tuning knobs and utility helpers in one
-- file so that gameplay designers can extend rank behaviour without
-- touching any of the consumers.

if not NX_RANK then
    NX_RANK = {}
end

local rankOrder = {
    "F", "E", "D", "C", "B", "A", "S", "SS", "SSS"
}

-- Storage keys. These are string storages to avoid conflicts.
NX_RANK.STORAGE = {
    rank = 54000,
    rank_idx = 54001,
    area = 54002,
    haste = 54003,
    hp_delta = 54004,
    ai_cd_mult = 54005,
    spell_unlock = 54006,
    resist = 54007
}

-- Reveal storages / configuration. Numbers chosen from unused range.
NX_RANK.REVEAL = {
    PERM = 54200,
    TEMP_UNTIL = 54201,
    LORE_LVL = 10, -- default lore skill level required
    SHOW_HINT = true,
    HINT_TEXT = "You sense that this creature hides its true strength.",
    LINE_FMT = "Rank: %s",
    STAFF_SHOW_SCALERS = true
}

NX_RANK.RANKS = {}
NX_RANK.RANK_BY_KEY = {}
NX_RANK.RUNTIME = {}

local defaultRanks = {
    {
        key = "F",
        hp = 0.75,
        dmg = 0.75,
        mit = 0.00,
        spd = 5,
        xp = 0.75,
        loot_mult = 0.8,
        extra_rolls = 0,
        ai_cd_mult = 1.20,
        resist = { percent = 0.00 },
        spell_unlock = 0,
        name_suffix = "",
        light = { level = 0, color = 0 }
    },
    {
        key = "E",
        hp = 0.90,
        dmg = 0.90,
        mit = 0.02,
        spd = 10,
        xp = 0.90,
        loot_mult = 0.9,
        extra_rolls = 0,
        ai_cd_mult = 1.10,
        resist = { percent = 0.01 },
        spell_unlock = 0,
        light = { level = 0, color = 0 }
    },
    {
        key = "D",
        hp = 1.0,
        dmg = 1.0,
        mit = 0.04,
        spd = 20,
        xp = 1.0,
        loot_mult = 1.0,
        extra_rolls = 0,
        ai_cd_mult = 1.0,
        resist = { percent = 0.02 },
        spell_unlock = 0,
        light = { level = 1, color = 215 }
    },
    {
        key = "C",
        hp = 1.15,
        dmg = 1.10,
        mit = 0.06,
        spd = 30,
        xp = 1.10,
        loot_mult = 1.1,
        extra_rolls = 0,
        ai_cd_mult = 0.95,
        resist = { percent = 0.03 },
        spell_unlock = 0,
        light = { level = 2, color = 215 }
    },
    {
        key = "B",
        hp = 1.30,
        dmg = 1.20,
        mit = 0.08,
        spd = 40,
        xp = 1.25,
        loot_mult = 1.2,
        extra_rolls = 0,
        ai_cd_mult = 0.90,
        resist = { percent = 0.04 },
        spell_unlock = 1,
        light = { level = 2, color = 191 }
    },
    {
        key = "A",
        hp = 1.55,
        dmg = 1.35,
        mit = 0.10,
        spd = 50,
        xp = 1.50,
        loot_mult = 1.4,
        extra_rolls = 1,
        ai_cd_mult = 0.85,
        resist = { percent = 0.05 },
        spell_unlock = 1,
        light = { level = 3, color = 191 }
    },
    {
        key = "S",
        hp = 1.85,
        dmg = 1.55,
        mit = 0.12,
        spd = 60,
        xp = 1.85,
        loot_mult = 1.6,
        extra_rolls = 1,
        ai_cd_mult = 0.80,
        resist = { percent = 0.07 },
        spell_unlock = 2,
        light = { level = 4, color = 63 }
    },
    {
        key = "SS",
        hp = 2.30,
        dmg = 1.85,
        mit = 0.15,
        spd = 70,
        xp = 2.30,
        loot_mult = 1.8,
        extra_rolls = 2,
        ai_cd_mult = 0.75,
        resist = { percent = 0.09 },
        spell_unlock = 3,
        light = { level = 4, color = 215 },
        name_suffix = " the Elite"
    },
    {
        key = "SSS",
        hp = 3.0,
        dmg = 2.1,
        mit = 0.18,
        spd = 80,
        xp = 3.0,
        loot_mult = 2.0,
        extra_rolls = 3,
        ai_cd_mult = 0.70,
        resist = { percent = 0.12 },
        spell_unlock = 4,
        light = { level = 5, color = 63 },
        name_suffix = " the Apex",
        color = 180
    }
}

for index, def in ipairs(defaultRanks) do
    def.index = index
    NX_RANK.RANKS[index] = def
    NX_RANK.RANK_BY_KEY[def.key] = def
end

NX_RANK.DISTRIBUTION = {
    global = {
        F = 18,
        E = 18,
        D = 20,
        C = 16,
        B = 12,
        A = 8,
        S = 5,
        SS = 2,
        SSS = 1
    },
    areas = {},
    overrides = {}
}

local function normaliseDistribution(list)
    local total = 0
    for _, value in pairs(list) do
        total = total + math.max(value, 0)
    end
    if total <= 0 then
        local count = #rankOrder
        for _, key in ipairs(rankOrder) do
            list[key] = 1 / count
        end
        return list
    end
    for key, value in pairs(list) do
        list[key] = math.max(value, 0) / total
    end
    return list
end

local function resolveDistribution(areaTag, monsterKey)
    local values = {}
    local function merge(src)
        if not src then
            return
        end
        for key, weight in pairs(src) do
            values[key] = (values[key] or 0) + weight
        end
    end
    merge(NX_RANK.DISTRIBUTION.global)
    if areaTag and NX_RANK.DISTRIBUTION.areas[areaTag] then
        merge(NX_RANK.DISTRIBUTION.areas[areaTag])
    end
    if monsterKey and NX_RANK.DISTRIBUTION.overrides[monsterKey] then
        merge(NX_RANK.DISTRIBUTION.overrides[monsterKey])
    end
    for _, key in ipairs(rankOrder) do
        if not values[key] then
            values[key] = 0
        end
    end
    return normaliseDistribution(values)
end

function NX_RANK.getActiveDist(areaTag, monsterKey)
    local dist = resolveDistribution(areaTag, monsterKey)
    local copy = {}
    for k, v in pairs(dist) do
        copy[k] = v
    end
    return copy
end

local function weightedPick(dist)
    local roll = math.random()
    local cumulative = 0
    local lastKey = rankOrder[#rankOrder]
    for _, key in ipairs(rankOrder) do
        cumulative = cumulative + (dist[key] or 0)
        if roll <= cumulative then
            return key
        end
        lastKey = key
    end
    return lastKey
end

function NX_RANK.pickRank(areaTag, monsterKey)
    local dist = resolveDistribution(areaTag, monsterKey)
    local key = weightedPick(dist)
    return key, NX_RANK.RANK_BY_KEY[key]
end

function NX_RANK.getTier(rankStr)
    if not rankStr then
        return nil
    end
    return NX_RANK.RANK_BY_KEY[rankStr]
end

local function setStorage(creature, key, value)
    if not creature then
        return
    end
    creature:setStorageValue(key, value)
end

local function resolveTileArea(creature)
    local defaultTag = "global"
    local pos = creature and creature:getPosition()
    if not pos then
        return defaultTag
    end
    local tile = Tile(pos)
    if not tile then
        return defaultTag
    end
    local ground = tile:getGround()
    if ground then
        local aid = ground:getActionId()
        if aid and aid > 0 then
            return string.format("aid:%d", aid)
        end
    end
    local topItem = tile:getTopTopItem()
    if topItem then
        local aid = topItem:getActionId()
        if aid and aid > 0 then
            return string.format("aid:%d", aid)
        end
    end
    local zone = tile:getZone()
    if zone and zone ~= 0 then
        return string.format("zone:%d", zone)
    end
    return defaultTag
end

function NX_RANK.setRank(creature, rankStr)
    if not creature then
        return nil
    end
    local tier = NX_RANK.getTier(rankStr)
    if not tier then
        return nil
    end
    setStorage(creature, NX_RANK.STORAGE.rank, rankStr)
    setStorage(creature, NX_RANK.STORAGE.rank_idx, tier.index)
    NX_RANK.RUNTIME[creature:getId()] = rankStr
    return tier
end

function NX_RANK.decorateName(creature, rankStr)
    if not creature then
        return
    end
    local tier = NX_RANK.getTier(rankStr)
    if not tier then
        return
    end
    local suffix = tier.name_suffix
    local baseName = creature:getName()
    if suffix and suffix ~= "" then
        if creature.setCustomName then
            creature:setCustomName(baseName .. suffix)
        elseif creature.setName then
            creature:setName(baseName .. suffix)
        end
    end
    if tier.color and creature.setOutfit then
        local outfit = creature:getOutfit()
        if outfit then
            outfit.head = tier.color
            creature:setOutfit(outfit)
        end
    end
end

function NX_RANK.applyLight(creature, tier)
    if not creature or not tier then
        return
    end
    local light = tier.light
    if light then
        creature:setLight(light.level, light.color, -1)
    end
end

local function removeExistingSpeed(monster)
    if monster:getStorageValue(NX_RANK.STORAGE.haste) == 1 then
        monster:removeCondition(CONDITION_HASTE, CONDITIONID_COMBAT, NX_RANK.STORAGE.haste)
        monster:setStorageValue(NX_RANK.STORAGE.haste, -1)
    end
end

local function applySpeed(monster, tier)
    removeExistingSpeed(monster)
    local delta = tier.spd or 0
    if delta == 0 then
        return
    end
    local condition = Condition(CONDITION_HASTE)
    condition:setParameter(CONDITION_PARAM_TICKS, -1)
    condition:setParameter(CONDITION_PARAM_SPEED, delta)
    condition:setParameter(CONDITION_PARAM_SUBID, NX_RANK.STORAGE.haste)
    monster:addCondition(condition)
    monster:setStorageValue(NX_RANK.STORAGE.haste, 1)
end

local function applyHealth(monster, tier)
    local currentMax = monster:getMaxHealth()
    local previousDelta = monster:getStorageValue(NX_RANK.STORAGE.hp_delta)
    if previousDelta == -1 then
        previousDelta = 0
    end
    local base = currentMax - (previousDelta or 0)
    if base <= 0 then
        base = currentMax
    end
    local newMax = math.max(1, math.floor(base * (tier.hp or 1)))
    if newMax ~= currentMax then
        monster:setMaxHealth(newMax)
    end
    monster:addHealth(monster:getMaxHealth())
    monster:setStorageValue(NX_RANK.STORAGE.hp_delta, newMax - base)
end

function NX_RANK.applyTier(monster, tier)
    if not monster or not tier then
        return
    end
    applyHealth(monster, tier)
    applySpeed(monster, tier)
    NX_RANK.applyLight(monster, tier)
    monster:setStorageValue(NX_RANK.STORAGE.ai_cd_mult, tier.ai_cd_mult or 1)
    monster:setStorageValue(NX_RANK.STORAGE.spell_unlock, tier.spell_unlock or 0)
    if tier.resist then
        if type(tier.resist) == "number" then
            monster:setStorageValue(NX_RANK.STORAGE.resist, tier.resist)
        elseif type(tier.resist) == "table" then
            monster:setStorageValue(NX_RANK.STORAGE.resist, tier.resist.percent or 0)
        end
    else
        monster:setStorageValue(NX_RANK.STORAGE.resist, -1)
    end
end

function NX_RANK.resolveAreaTag(creature)
    return resolveTileArea(creature)
end

function NX_RANK.getRankForCreature(creature)
    if not creature then
        return nil
    end
    local rankStr = creature:getStorageValue(NX_RANK.STORAGE.rank)
    if rankStr and rankStr ~= -1 and NX_RANK.RANK_BY_KEY[rankStr] then
        return NX_RANK.RANK_BY_KEY[rankStr]
    end
    local runtime = NX_RANK.RUNTIME[creature:getId()]
    if runtime and NX_RANK.RANK_BY_KEY[runtime] then
        return NX_RANK.RANK_BY_KEY[runtime]
    end
    local idx = creature:getStorageValue(NX_RANK.STORAGE.rank_idx)
    if idx and idx ~= -1 then
        local tier = NX_RANK.RANKS[idx]
        if tier then
            return tier
        end
    end
    return nil
end

function NX_RANK.getRankKey(creature)
    if not creature then
        return nil
    end
    local rankStr = creature:getStorageValue(NX_RANK.STORAGE.rank)
    if rankStr and rankStr ~= -1 and NX_RANK.RANK_BY_KEY[rankStr] then
        return rankStr
    end
    local runtime = NX_RANK.RUNTIME[creature:getId()]
    if runtime and NX_RANK.RANK_BY_KEY[runtime] then
        return runtime
    end
    local idx = creature:getStorageValue(NX_RANK.STORAGE.rank_idx)
    if idx and idx ~= -1 then
        local tier = NX_RANK.RANKS[idx]
        if tier then
            return tier.key
        end
    end
    return nil
end

return NX_RANK
