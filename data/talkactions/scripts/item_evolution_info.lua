local slotsToCheck = {
        {slot = CONST_SLOT_RIGHT, label = "Weapon"},
        {slot = CONST_SLOT_LEFT, label = "Off-hand"},
        {slot = CONST_SLOT_ARMOR, label = "Armor"},
}

local function formatCategoryName(category)
        if not category or category == "" then
                return ""
        end

        return category:sub(1, 1):upper() .. category:sub(2) .. " "
end

local function formatStage(info)
        local stageText
        if info.maxStage and info.maxStage > 0 then
                stageText = string.format("Stage %d/%d", info.stage, info.maxStage)
        else
                stageText = string.format("Stage %d", info.stage)
        end

        if info.rankText and info.rankText ~= "" then
                stageText = string.format("%s - %s", stageText, info.rankText)
        end
        return stageText
end

local function formatExperience(info)
        if info.atMaxStage or not info.nextThreshold or info.nextThreshold <= info.experience then
                return string.format("%d XP (maximum stage)", info.experience)
        end

        local remaining = info.nextThreshold - info.experience
        return string.format("%d/%d XP (%d remaining)", info.experience, info.nextThreshold, remaining)
end

function onSay(player, words, param)
        local reported = false

        for _, entry in ipairs(slotsToCheck) do
                local info = player:getEvolutionItemProgress(entry.slot)
                if info then
                        reported = true

                        local header = string.format("%s: %s%s", entry.label, formatCategoryName(info.category), info.itemName)
                        local stageText = formatStage(info)
                        local experienceText = formatExperience(info)
                        player:sendTextMessage(MESSAGE_INFO_DESCR, string.format("%s. %s. %s.", header, stageText, experienceText))
                end
        end

        if not reported then
                player:sendTextMessage(MESSAGE_INFO_DESCR, "You do not have any evolving items equipped.")
        end
        return false
end
