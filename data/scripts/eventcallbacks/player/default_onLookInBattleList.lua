local event = Event()

event.onLookInBattleList = function(self, creature, distance)
        local baseDescription = creature:getDescription(distance)
        if creature:isMonster() and creature.getRank then
                baseDescription = baseDescription:gsub("%s+Its rank is [^%.]+%.?", "")
                baseDescription = baseDescription:gsub("%s+Its Rank is [^%.]+%.?", "")
        end

        local description = "You see " .. baseDescription

        if creature:isMonster() and creature.getRank then
                local rank = creature:getRank()
                if rank and rank ~= "" then
                        description = string.format("%s\nIts Rank is %s.", description, rank)
                end
        end

        if self:getGroup():getAccess() then
                local str = "%s\nHealth: %d / %d"
                if creature:isPlayer() and creature:getMaxMana() > 0 then
			str = string.format("%s, Mana: %d / %d", str, creature:getMana(), creature:getMaxMana())
		end
		description = string.format(str, description, creature:getHealth(), creature:getMaxHealth()) .. "."

		local position = creature:getPosition()
		description = string.format(
			"%s\nPosition: %d, %d, %d",
			description, position.x, position.y, position.z
		)

		if creature:isPlayer() then
			description = string.format("%s\nIP: %s", description, creature:getIp())
		end
	end
	return description
end

event:register()
