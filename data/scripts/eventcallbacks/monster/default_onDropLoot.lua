local event = Event()

local MAX_LOOT_CHANCE = 100000

local function scaleLootEntry(entry, multiplier)
        if multiplier == 1 then
                return entry
        end

        local scaledChance = math.floor(entry.chance * multiplier)
        if entry.chance > 0 and scaledChance == 0 then
                scaledChance = 1
        end
        if scaledChance < 0 then
                scaledChance = 0
        end
        if scaledChance > MAX_LOOT_CHANCE then
                scaledChance = MAX_LOOT_CHANCE
        end

        local scaled = {
                itemId = entry.itemId,
                chance = scaledChance,
                subType = entry.subType,
                maxCount = entry.maxCount,
                actionId = entry.actionId,
                text = entry.text,
                childLoot = {}
        }

        local childLoot = entry.childLoot or {}
        for i = 1, #childLoot do
                scaled.childLoot[i] = scaleLootEntry(childLoot[i], multiplier)
        end

        return scaled
end

local function rollLootTable(monster, corpse, lootEntries, multiplier)
        local economy = rawget(_G, 'NX_ECONOMY')
        local shouldAllow = economy and economy.shouldAllowLootItem

        for i = 1, #lootEntries do
                local entry = scaleLootEntry(lootEntries[i], multiplier)
                local allow = true
                if shouldAllow then
                        allow = shouldAllow(entry, { monster = monster, corpse = corpse })
                end
                if allow then
                        if not corpse:createLootItem(entry) then
                                print("[Warning] DropLoot: Could not add loot item to corpse.")
                        end
                end
        end
end

event.onDropLoot = function(self, corpse)
        if configManager.getNumber(configKeys.RATE_LOOT) == 0 then
                return
        end

        local player = Player(corpse:getCorpseOwner())
        local mType = self:getType()
        local doCreateLoot = false

        local lootMultiplier = 1
        local extraRolls = 0
        if self.hasRank and self:hasRank() then
                lootMultiplier = self.getRankLootMultiplier and self:getRankLootMultiplier() or lootMultiplier
                extraRolls = self.getRankExtraRolls and self:getRankExtraRolls() or extraRolls
        end

        if not player or player:getStamina() > 840 or not configManager.getBoolean(configKeys.STAMINA_SYSTEM) then
                doCreateLoot = true
        end

        if doCreateLoot then
                local monsterLoot = mType:getLoot()
                rollLootTable(self, corpse, monsterLoot, lootMultiplier)
                if extraRolls > 0 then
                        for _ = 1, extraRolls do
                                rollLootTable(self, corpse, monsterLoot, lootMultiplier)
                        end
                end
        end

        if player then
                local text
                if doCreateLoot then
			text = ("Loot of %s: %s."):format(mType:getNameDescription(), corpse:getContentDescription())
		else
			text = ("Loot of %s: nothing (due to low stamina)."):format(mType:getNameDescription())
		end
		local party = player:getParty()
		if party then
			party:broadcastPartyLoot(text)
		else
			player:sendTextMessage(MESSAGE_LOOT, text)
		end
	end
end

event:register()
