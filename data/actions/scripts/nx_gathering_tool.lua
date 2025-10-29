local NX = (dofile_once and dofile_once('data/lib/nx_professions.lua')) or dofile('data/lib/nx_professions.lua')

local fishingFallback

local function callFishingFallback(player, item, fromPosition, target, toPosition, isHotkey)
        if fishingFallback == false then
                return false
        end
        if not fishingFallback then
                local env = setmetatable({}, { __index = _G })
                local chunk, err = loadfile('data/actions/scripts/tools/fishing.lua', 't', env)
                if not chunk then
                        print('[NX] Gathering: unable to load fishing fallback: ' .. (err or 'unknown error'))
                        fishingFallback = false
                        return false
                end
                chunk()
                fishingFallback = env.onUse or false
        end
        if fishingFallback then
                return fishingFallback(player, item, fromPosition, target, toPosition, isHotkey)
        end
        return false
end

local function callFallback(toolKind, player, item, fromPosition, target, toPosition, isHotkey)
        if toolKind == 'rod' then
                return callFishingFallback(player, item, fromPosition, target, toPosition, isHotkey)
        elseif toolKind == 'pick' then
                if type(onUsePick) == 'function' then
                        return onUsePick(player, item, fromPosition, target, toPosition, isHotkey)
                end
        elseif toolKind == 'knife' then
                if type(onUseKitchenKnife) == 'function' then
                        return onUseKitchenKnife(player, item, fromPosition, target, toPosition, isHotkey)
                end
        end
        return false
end

local function calculateAmount(entry)
        local amount = 1
        if type(entry.amount) == "table" then
                local min = entry.amount[1] or 1
                local max = entry.amount[2] or min
                if max < min then
                        max = min
                end
                amount = math.random(min, max)
        elseif type(entry.amount) == "number" then
                amount = entry.amount
        end
        if amount < 1 then
                amount = 1
        end
        return amount
end

local function checkCapacity(player, itemId, amount)
        local itemType = ItemType(itemId)
        if itemType then
                local weight = itemType:getWeight(amount, true)
                if player:getFreeCapacity() < weight then
                        player:sendTextMessage(MESSAGE_STATUS_SMALL, "You are too heavy to carry your gathering haul.")
                        return false
                end
        end
        return true
end

local function giveLoot(player, entry)
        if not entry then
                return false
        end
        local amount = calculateAmount(entry)
        if not checkCapacity(player, entry.item, amount) then
                return false
        end
        local reward = player:addItem(entry.item, amount)
        if reward then
                local itemType = ItemType(entry.item)
                local name = itemType and itemType:getName() or "item"
                if amount > 1 then
                        player:sendTextMessage(MESSAGE_STATUS_SMALL, string.format("You gathered %d %s.", amount, name))
                else
                        player:sendTextMessage(MESSAGE_STATUS_SMALL, string.format("You gathered %s.", name))
                end
                return true
        end
        return false
end

local function grantRare(player, nodeKey)
        local node = NODES[nodeKey]
        if not node or not node.rare or #node.rare == 0 then
                return
        end
        local bonus = NX_NODE_RARE_BONUS[nodeKey] or 1
        local rareEntry = NX_DoRoll(node.rare, bonus, false)
        if rareEntry then
                giveLoot(player, rareEntry)
        end
end

local function hasUnlock(level, profKey, nodeKey)
        local lookups = NX_UNLOCK_LOOKUP[profKey]
        if not lookups then
                return false
        end
        local req = lookups[nodeKey]
        if not req then
                return false
        end
        return level >= req
end

function onUse(player, item, fromPosition, target, toPosition, isHotkey)
        local toolKind = NX_ToolKind(item.itemid)
        if not toolKind then
                return false
        end

        local tile = Tile(toPosition)
        if not tile then
                return callFallback(toolKind, player, item, fromPosition, target, toPosition, isHotkey)
        end

        local nodeKey = NX_DetectNodeAt(toPosition)
        if not nodeKey then
                player:sendCancelMessage("There's nothing to gather here.")
                return true
        end

        local node = NODES[nodeKey]
        if not node then
                return callFallback(toolKind, player, item, fromPosition, target, toPosition, isHotkey)
        end

        if node.tool ~= toolKind then
                player:sendCancelMessage("Wrong tool for this node.")
                return true
        end

        local profKey = NX_TOOL_TO_PROF[toolKind]
        local prof = NX_PROF[profKey]
        if not prof then
                player:sendCancelMessage("You can't use that right now.")
                return true
        end

        local level, xp = NX_GetProfessionLevel(player, profKey)
        if not hasUnlock(level, profKey, nodeKey) then
                local required = NX_UNLOCK_LOOKUP[profKey] and NX_UNLOCK_LOOKUP[profKey][nodeKey]
                if required then
                        player:sendCancelMessage(string.format("Requires %s level %d.", prof.NAME, required))
                else
                        player:sendCancelMessage("You can't gather from this node yet.")
                end
                return true
        end

        local now = os.time()
        local exhaustKey = NX_PROF_EXHAUST[profKey]
        local cdKey = NX_PerTileCDKeyFromPos(toPosition)
        local cooldown = Game.getStorageValue(cdKey)
        if cooldown and cooldown > now then
                player:sendTextMessage(MESSAGE_STATUS_SMALL, "This node seems depleted, come back later.")
                return true
        end

        if exhaustKey then
                local readyAt = player:getStorageValue(exhaustKey)
                if readyAt > now then
                        player:sendTextMessage(MESSAGE_STATUS_SMALL, "You're taking a short break before gathering again.")
                        return true
                end
                local delay = math.ceil((ANTI_BOT.perUseExhaustMs or 0) / 1000)
                player:setStorageValue(exhaustKey, now + math.max(1, delay))
        end

        local chance, currentLevel = NX_SuccessChance(player, prof.LVL, node.difficulty, 55)
        local roll = math.random(100)
        if roll <= chance then
                local entry = NX_DoRoll(node.rolls, 1, true)
                if not entry then
                        player:sendTextMessage(MESSAGE_STATUS_SMALL, "The node yields nothing useful.")
                        return true
                end

                if not giveLoot(player, entry) then
                        return true
                end

                local oldLevel = currentLevel
                grantRare(player, nodeKey)

                local gained = 10 + (node.difficulty or 0)
                local newLevel = NX_SetLevelXP(player, prof.LVL, prof.XP, gained)
                if newLevel > oldLevel then
                        player:sendTextMessage(MESSAGE_EVENT_ADVANCE, string.format("%s skill advanced to level %d!", prof.NAME, newLevel))
                end

                if newLevel % 5 == 0 and newLevel > oldLevel then
                        local milestoneEntry = NX_DoRoll(node.rolls, 1, true)
                        if giveLoot(player, milestoneEntry) then
                                player:sendTextMessage(MESSAGE_STATUS_SMALL, string.format("Milestone bonus! Your %s expertise rewarded extra loot.", prof.NAME))
                        end
                end

                Game.setStorageValue(cdKey, now + (ANTI_BOT.perTileCooldownSec or 30))
        else
                player:sendTextMessage(MESSAGE_STATUS_SMALL, "You failed and dulled your tool a little.")
                NX_SetLevelXP(player, prof.LVL, prof.XP, 2)
                local reduced = math.max(1, math.floor((ANTI_BOT.perTileCooldownSec or 30) / 2))
                Game.setStorageValue(cdKey, now + reduced)
        end

        return true
end
