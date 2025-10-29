-- [codex-fix] corrected event type/handler as per TFS 10.98
-- nx_rank_apply.lua
-- Creature spawn hook responsible for assigning ranks and initial stat
-- modifications. Delegates the heavy lifting to NX_RANK helper functions.

function onSpawn(creature)
    if not creature or not creature:isMonster() or not NX_RANK then
        return true
    end

    local areaTag = NX_RANK.resolveAreaTag(creature)
    creature:setStorageValue(NX_RANK.STORAGE.area, areaTag)

    local monsterKey = creature:getName():lower()
    local rankKey, tier = NX_RANK.pickRank(areaTag, monsterKey)
    if not tier then
        return true
    end

    NX_RANK.setRank(creature, rankKey)
    NX_RANK.decorateName(creature, rankKey)
    NX_RANK.applyTier(creature, tier)

    return true
end
