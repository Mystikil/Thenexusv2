local NX = (dofile_once and dofile_once('data/lib/nx_professions.lua')) or dofile('data/lib/nx_professions.lua')

local orderedProfs = {"FISH", "FORG", "MINE"}

local function formatUnlocks(level, profKey)
        local targetLevel, nodes = NX_NextUnlock(profKey, level)
        if not targetLevel or not nodes then
                return nil
        end
        return string.format("- %s: Lv %d \226\134\136 %s", NX_PROF[profKey].NAME, targetLevel, table.concat(nodes, ", "))
end

local function formatProfessionLines(player)
        local lines = {"Professions:"}
        for _, profKey in ipairs(orderedProfs) do
                local prof = NX_PROF[profKey]
                local level, xp = NX_GetProfessionLevel(player, profKey)
                local nextLevel, xpToNext = NX_GetNextLevelInfo(level, xp)
                local suffix
                if nextLevel and xpToNext then
                        suffix = string.format(" (Lv %d in %d XP)", nextLevel, xpToNext)
                else
                        suffix = " (Max)"
                end
                lines[#lines + 1] = string.format("%s: Lv %d (%d XP)%s", prof.NAME, level, xp, suffix)
        end
        return lines
end

function onSay(player, words, param)
        local lines = formatProfessionLines(player)
        lines[#lines + 1] = ""
        lines[#lines + 1] = "Next unlocks:"

        local hasUnlocks = false
        for _, profKey in ipairs(orderedProfs) do
                local level = select(1, NX_GetProfessionLevel(player, profKey))
                local unlockLine = formatUnlocks(level, profKey)
                if unlockLine then
                        lines[#lines + 1] = unlockLine
                        hasUnlocks = true
                end
        end

        if not hasUnlocks then
                lines[#lines + 1] = "- None"
        end

        local icon = TOOLS.rod or TOOLS.pick or TOOLS.knife or NX_ItemId("Book") or 1950
        player:showTextDialog(icon, table.concat(lines, "\n"))
        return false
end
