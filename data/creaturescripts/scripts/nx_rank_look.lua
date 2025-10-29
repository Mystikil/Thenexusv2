-- [codex-fix] corrected event type/handler as per TFS 10.98
-- nx_rank_look.lua
-- Appends rank information to the look description based on reveal rules.

local function canSeeRank(player, monster)
    if not player or not monster then
        return false
    end
    local group = player:getGroup()
    if group and group:getId() >= 3 then
        return true
    end
    local revealConfig = NX_RANK and NX_RANK.REVEAL
    if revealConfig and player:getStorageValue(revealConfig.PERM) == 1 then
        return true
    end
    local tempKey = revealConfig and revealConfig.TEMP_UNTIL
    local tempUntil = tempKey and player:getStorageValue(tempKey) or -1
    if tempUntil and tempUntil ~= -1 and tempUntil > os.time() then
        return true
    end
    if revealConfig and revealConfig.LORE_LVL and revealConfig.LORE_LVL > 0 then
        local skill = player:getSkillLevel(SKILL_MAGIC)
        if skill >= revealConfig.LORE_LVL then
            return true
        end
    end
    return false
end

local function buildScalerString(tier)
    if type(tier) ~= "table" then
        return ""
    end
    return string.format(" (HPx%.2f DMGx%.2f Lootx%.2f)", tier.hp or 1, tier.dmg or 1, tier.loot_mult or 1)
end

function onLook(player, thing, position, description)
    if type(description) ~= "string" then
        description = thing and thing:getDescription() or ""
    end
    if not thing or not thing.isMonster or not thing:isMonster() then
        return description
    end

    if not NX_RANK or not NX_RANK.getRankKey then
        return description
    end

    local rankKey = NX_RANK.getRankKey(thing)
    if not rankKey then
        return description
    end

    local tier = NX_RANK.getRankForCreature(thing)
    local revealConfig = NX_RANK.REVEAL
    if not revealConfig then
        return description
    end

    if canSeeRank(player, thing) then
        local line = revealConfig.LINE_FMT:format(rankKey)
        local group = player:getGroup()
        if revealConfig.STAFF_SHOW_SCALERS and group and group:getId() >= 3 then
            line = line .. buildScalerString(tier)
        end
        description = description .. string.format("\n%s", line)
    elseif revealConfig.SHOW_HINT then
        description = description .. string.format("\n%s", revealConfig.HINT_TEXT)
    end

    return description
end
