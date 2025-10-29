-- [codex-fix] corrected event type/handler as per TFS 10.98
-- nx_rank_scalers.lua
-- Handles runtime stat adjustments derived from monster ranks. Responsible
-- for scaling incoming/outgoing damage, loot, experience and cleaning up
-- visual state on death.

local function hasValidRankContext()
    return NX_RANK and NX_RANK.STORAGE and NX_RANK.getRankForCreature
end

local function getTierFromCreature(creature)
    if not creature then
        return nil
    end
    return NX_RANK.getRankForCreature(creature)
end

local function clampMitigation(value)
    if value < 0 then
        return 0
    end
    if value > 0.80 then
        return 0.80
    end
    return value
end

local function adjustOutgoing(attacker, primary, secondary)
    local tier = getTierFromCreature(attacker)
    if not tier then
        return primary, secondary
    end
    local mult = tier.dmg or 1
    if mult == 1 then
        return primary, secondary
    end
    if primary > 0 then
        primary = math.max(0, math.floor(primary * mult))
    end
    if secondary > 0 then
        secondary = math.max(0, math.floor(secondary * mult))
    end
    return primary, secondary
end

local function adjustIncoming(target, primary, secondary)
    local tier = getTierFromCreature(target)
    if not tier then
        return primary, secondary
    end
    local mit = clampMitigation(tier.mit or 0)
    if mit > 0 then
        primary = math.max(0, math.floor(primary * (1 - mit)))
        secondary = math.max(0, math.floor(secondary * (1 - mit)))
    end
    local resist = 0
    if tier.resist then
        if type(tier.resist) == "number" then
            resist = tier.resist
        elseif type(tier.resist) == "table" then
            resist = tier.resist.percent or 0
        end
    end
    if resist > 0 then
        primary = math.max(0, math.floor(primary * (1 - resist)))
        secondary = math.max(0, math.floor(secondary * (1 - resist)))
    end
    return primary, secondary
end

local function cleanupConditions(monster)
    if not hasValidRankContext() then
        return
    end
    local hasteStorage = NX_RANK.STORAGE.haste
    if hasteStorage and monster:getStorageValue(hasteStorage) == 1 then
        monster:removeCondition(CONDITION_HASTE, CONDITIONID_COMBAT, hasteStorage)
        monster:setStorageValue(hasteStorage, -1)
    end
end

local function applyExtraXp(monster, killer, tier)
    if not killer or not killer:isPlayer() then
        return
    end
    local mType = monster:getType() or MonsterType(monster:getName())
    if not mType then
        return
    end
    local baseExp = mType:getExperience() or 0
    local desired = baseExp * (tier.xp or 1)
    local extra = math.floor(desired - baseExp)
    if extra > 0 then
        killer:addExperience(extra, true)
    end
end

local function scaleLoot(monster, tier)
    -- Proper loot scaling requires integration with the central loot
    -- handling pipeline. This placeholder exists to make the call-site
    -- explicit without risking runtime errors.
    return tier and tier.loot_mult
end

local function scaleHealthDelta(creature, attacker, primaryDamage, primaryType, secondaryDamage, secondaryType)
    if attacker and attacker:isMonster() then
        primaryDamage, secondaryDamage = adjustOutgoing(attacker, primaryDamage, secondaryDamage)
    end
    if creature and creature:isMonster() then
        primaryDamage, secondaryDamage = adjustIncoming(creature, primaryDamage, secondaryDamage)
    end
    return primaryDamage, primaryType, secondaryDamage, secondaryType
end

function onHealthChange(creature, attacker, primaryDamage, primaryType, secondaryDamage, secondaryType, origin)
    if not hasValidRankContext() then
        return primaryDamage, primaryType, secondaryDamage, secondaryType
    end
    return scaleHealthDelta(creature, attacker, primaryDamage, primaryType, secondaryDamage, secondaryType)
end

function onStatsChange(creature, attacker, type, combat, value)
    if not hasValidRankContext() then
        return type, combat, value
    end
    if type ~= STATSCHANGE_HEALTHLOSS and type ~= STATSCHANGE_HEALTHGAIN then
        return type, combat, value
    end

    local healthChange = value
    if type == STATSCHANGE_HEALTHGAIN then
        healthChange = -value
    end

    local scaledHealth, _, _, _ = scaleHealthDelta(creature, attacker, healthChange, combat, 0, COMBAT_NONE)

    if type == STATSCHANGE_HEALTHGAIN then
        scaledHealth = -scaledHealth
    end

    return type, combat, scaledHealth
end

function onDeath(creature, corpse, killer, mostDamageKiller, unjustified, mostDamageUnjustified)
    if not creature or not creature:isMonster() or not hasValidRankContext() then
        return true
    end
    local tier = getTierFromCreature(creature)
    if tier then
        applyExtraXp(creature, killer, tier)
        scaleLoot(creature, tier)
    end
    cleanupConditions(creature)
    if NX_RANK.RUNTIME then
        NX_RANK.RUNTIME[creature:getId()] = nil
    end
    return true
end
