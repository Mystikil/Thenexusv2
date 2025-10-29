-- data/lib/nx_professions.lua
-- Shared constants, XP tables, and helpers for gathering professions.
-- Safe to be dofile'd multiple times (idempotent guard).
if rawget(_G, "__NX_PROFESSIONS_LOADED") then
        return _G.NX_PROFESSIONS
end
_G.__NX_PROFESSIONS_LOADED = true

local M = {}

-- ===== Config =====
M.ITEMS_XML_PATH = M.ITEMS_XML_PATH or 'data/items/items.xml'

local lower = string.lower

-- ===== Utility helpers =====
local function trim(str)
        return (str or ""):gsub("^%s+", ""):gsub("%s+$", "")
end

local function norm(str)
        return lower(trim((str or ""):gsub("%s+", " ")))
end

-- Splits on comma, pipe or slash. Avoid [] classes to prevent malformed pattern issues.
local function splitAliases(s)
        local out = {}
        s = tostring(s or "")
        for token in s:gmatch("([^,|/]+)") do
                local t = token:gsub("^%s+", ""):gsub("%s+$", "")
                if #t > 0 then
                        table.insert(out, t)
                end
        end
        return out
end

-- ===== Constants =====
NX_PROF = {
        FISH = { LVL = 70000, XP = 70001, NAME = "Fishing" },
        FORG = { LVL = 70002, XP = 70003, NAME = "Foraging" },
        MINE = { LVL = 70004, XP = 70005, NAME = "Mining" },
}
M.NX_PROF = NX_PROF

XP_PER_TIER = {
        [1] = 0, [2] = 100, [3] = 300, [4] = 700, [5] = 1500,
        [6] = 2700, [7] = 4300, [8] = 6400, [9] = 9000, [10] = 12200,
        [11] = 16000, [12] = 20500, [13] = 25800, [14] = 32000, [15] = 39200,
        [16] = 47500, [17] = 57000, [18] = 67800, [19] = 80000, [20] = 93700
}
M.XP_PER_TIER = XP_PER_TIER

ANTI_BOT = { perUseExhaustMs = 1500, perTileCooldownSec = 30, rotation = "daily" }
M.ANTI_BOT = ANTI_BOT

-- ===== Internal cache =====
local RESOLVER = {
        cache = {},
        names = {},
        parsed = false
}

-- Parse items.xml minimally using Lua patterns (not PCRE!)
-- Example tag matched: <item id="2160" name="crystal coin" article="a" plural="crystal coins" />
local function loadItemsXmlIntoCache()
        if RESOLVER.parsed then
                return
        end

        local file = io.open(M.ITEMS_XML_PATH, "r")
        if not file then
                print(string.format("[nx_professions] WARNING: cannot open %s", M.ITEMS_XML_PATH))
                RESOLVER.parsed = true
                return
        end

        local content = file:read("*a") or ""
        file:close()

        local aliasCount = 0
        for tag in content:gmatch("<item%s+[^>]-/?>") do
                local id = tag:match('id%s*=%s*"(%d+)"')
                local rawName = tag:match('name%s*=%s*"([^"]+)"')
                if id and rawName then
                        local itemId = tonumber(id)
                        if itemId then
                                for _, alias in ipairs(splitAliases(rawName)) do
                                        local key = norm(alias)
                                        if key ~= "" and not RESOLVER.names[key] then
                                                RESOLVER.names[key] = itemId
                                                aliasCount = aliasCount + 1
                                        end
                                end
                        end
                end
        end

        RESOLVER.parsed = true
        print(string.format("[nx_professions] items.xml parsed (%d aliases)", aliasCount))
end

-- Public: get itemId by (case-insensitive) name; returns nil if not found
function M.itemIdByName(name)
        if not name or name == "" then
                return nil
        end

        local key = norm(name)
        if RESOLVER.cache[key] then
                return RESOLVER.cache[key]
        end

        local id
        if type(getItemIdByName) == "function" then
                local ok, value = pcall(getItemIdByName, name, false)
                if ok then
                        local numeric = tonumber(value)
                        if numeric and numeric > 0 then
                                id = numeric
                        end
                end
        end

        if not id then
                if not RESOLVER.parsed then
                        loadItemsXmlIntoCache()
                end
                id = RESOLVER.names[key]
        end

        if not id and RESOLVER.names then
                for alias, value in pairs(RESOLVER.names) do
                        if alias == key or alias:find(key, 1, true) then
                                id = value
                                break
                        end
                end
        end

        if id and id > 0 then
                RESOLVER.cache[key] = id
                return id
        end
        return nil
end

local function NX_ItemId(name)
        return M.itemIdByName(name)
end
_G.NX_ItemId = NX_ItemId
M.NX_ItemId = NX_ItemId

local function NX_IdExists(id)
        return type(id) == "number" and id > 0
end
_G.NX_IdExists = NX_IdExists
M.NX_IdExists = NX_IdExists

local function NX_FirstExisting(names)
        if type(names) ~= "table" then
                return nil
        end
        for _, name in ipairs(names) do
                local id = NX_ItemId(name)
                if id then
                        return id
                end
        end
        return nil
end
_G.NX_FirstExisting = NX_FirstExisting
M.NX_FirstExisting = NX_FirstExisting

-- Node -> item display names (resolver will map names to ids; missing names are ignored)
NODE_ITEMNAMES = {
        fishing_pool = {
                "Shallow Water", "Deep Water", "Water", "Small Pond", "Lily Pad", "Fishing Net"
        },
        meadow_flowers = {
                "Wild Flowers", "Flower Bowl", "Bush", "Tall Grass", "Fern", "Teal Leaves"
        },
        woodland_trees = {
                "Tree", "Oak", "Pine", "Dead Tree", "Tree Stump", "Fallen Tree", "Branch", "Tree Branch"
        },
        surface_ore = {
                "Rock", "Stone Pile", "Ore Vein", "Coal Vein", "Stone Block", "Cracked Rock"
        },
        deep_basalt = {
                "Basalt", "Basalt Column", "Basalt Crystal Wall", "Basalt Rock"
        },
}

NODE_ITEMSETS = {}

local function NX_NodeKeyFromAid(aid)
        if aid >= 6100 and aid <= 6199 then
                return "fishing_pool"
        elseif aid >= 6200 and aid <= 6299 then
                return "meadow_flowers"
        elseif aid >= 6300 and aid <= 6399 then
                return "woodland_trees"
        elseif aid >= 6400 and aid <= 6499 then
                return "surface_ore"
        elseif aid >= 6500 and aid <= 6599 then
                return "deep_basalt"
        end
        return nil
end
_G.NX_NodeKeyFromAid = NX_NodeKeyFromAid
M.NX_NodeKeyFromAid = NX_NodeKeyFromAid

local function NX_BuildNodeSets()
        NODE_ITEMSETS = {}
        for nodeKey, names in pairs(NODE_ITEMNAMES) do
                local set = {}
                for _, nm in ipairs(names) do
                        local id = NX_ItemId(nm)
                        if id and id > 0 then
                                set[id] = true
                        end
                end
                if next(set) == nil then
                        print(string.format("[nx_professions] warning: node '%s' resolved 0 itemids", nodeKey))
                end
                NODE_ITEMSETS[nodeKey] = set
        end
        M.NODE_ITEMSETS = NODE_ITEMSETS
end
_G.NX_BuildNodeSets = NX_BuildNodeSets
M.NX_BuildNodeSets = NX_BuildNodeSets

M.NODE_ITEMNAMES = NODE_ITEMNAMES

local function NX_DetectNodeAt(pos)
        local tile = Tile(pos)
        if not tile then
                return nil
        end

        local top = tile:getTopTopItem() or tile:getTopDownItem() or tile:getGround()
        if top then
                local iid = top:getId()
                if iid and iid > 0 then
                        for nodeKey, set in pairs(NODE_ITEMSETS) do
                                if set[iid] then
                                        return nodeKey
                                end
                        end
                end
        end

        local thing = tile:getTopVisibleThing()
        local aid = (thing and thing:isItem()) and thing:getActionId() or 0
        if aid and aid > 0 then
                return NX_NodeKeyFromAid(aid)
        end
        return nil
end
_G.NX_DetectNodeAt = NX_DetectNodeAt
M.NX_DetectNodeAt = NX_DetectNodeAt

local function NX_PerTileCDKeyFromPos(pos)
        if not pos then
                return 70100
        end
        local h = ((pos.x * 73856093) ~ (pos.y * 19349663) ~ (pos.z * 83492791)) & 0x7fffffff
        return 70100 + (h % 101)
end
_G.NX_PerTileCDKeyFromPos = NX_PerTileCDKeyFromPos
M.NX_PerTileCDKeyFromPos = NX_PerTileCDKeyFromPos

local function countSetEntries(set)
        local count = 0
        if set then
                for _ in pairs(set) do
                        count = count + 1
                end
        end
        return count
end

NX_BuildNodeSets()
print(string.format("[nx_professions] Node item sets built: fishing=%d, meadow=%d, woods=%d, surface=%d, basalt=%d",
        countSetEntries(NODE_ITEMSETS.fishing_pool),
        countSetEntries(NODE_ITEMSETS.meadow_flowers),
        countSetEntries(NODE_ITEMSETS.woodland_trees),
        countSetEntries(NODE_ITEMSETS.surface_ore),
        countSetEntries(NODE_ITEMSETS.deep_basalt)
))

local function NX_FilterValid(rows)
        local valid = {}
        if type(rows) ~= "table" then
                return valid
        end

        for _, row in ipairs(rows) do
                local itemId = NX_ItemId(row.name)
                if itemId then
                        valid[#valid + 1] = {
                                item = itemId,
                                chance = row.chance or 0,
                                amount = row.amount,
                                name = row.name
                        }
                end
        end
        return valid
end
_G.NX_FilterValid = NX_FilterValid
M.NX_FilterValid = NX_FilterValid

local function resolveToolSet(names)
        local variants = {}
        for _, entry in ipairs(names) do
                local id = NX_ItemId(entry)
                if id then
                        variants[#variants + 1] = id
                end
        end
        return variants[1], variants
end

local rodPrimary, rodVariants = resolveToolSet({"Mechanical Fishing Rod", "Fishing Rod", "Enhanced Fishing Rod"})
local pickPrimary, pickVariants = resolveToolSet({"Pick", "Pickaxe"})
local knifePrimary, knifeVariants = resolveToolSet({"Kitchen Knife", "Hunting Knife", "Knife"})

TOOLS = {
        rod = rodPrimary,
        pick = pickPrimary,
        knife = knifePrimary
}
M.TOOLS = TOOLS

NX_TOOL_VARIANTS = {
        rod = rodVariants,
        pick = pickVariants,
        knife = knifeVariants
}
M.NX_TOOL_VARIANTS = NX_TOOL_VARIANTS

for kind, variants in pairs(NX_TOOL_VARIANTS) do
        if not variants or #variants == 0 then
                print(string.format("[nx_professions] warning: no tool resolved for '%s'", kind))
        end
end

local function NX_ToolKind(itemid)
        if not itemid then
                return nil
        end
        for kind, variants in pairs(NX_TOOL_VARIANTS) do
                for _, id in ipairs(variants) do
                        if id == itemid then
                                return kind
                        end
                end
        end
        return nil
end
_G.NX_ToolKind = NX_ToolKind
M.NX_ToolKind = NX_ToolKind

local NODE_KEYS = {
        fishing_pool = true,
        meadow_flowers = true,
        woodland_trees = true,
        surface_ore = true,
        deep_basalt = true
}

NODES = {
        fishing_pool = {
                tool = "rod", difficulty = 10,
                rolls = NX_FilterValid({
                        {name = "Fish", chance = 60, amount = {1, 2}},
                        {name = "Gold Coin", chance = 12, amount = {1, 10}},
                        {name = "White Pearl", chance = 4},
                        {name = "Black Pearl", chance = 3},
                }),
                rare = NX_FilterValid({
                        {name = "Gold Nugget", chance = 0.6},
                        {name = "Small Diamond", chance = 0.4},
                        {name = "Blue Crystal Shard", chance = 0.3},
                })
        },
        meadow_flowers = {
                tool = "knife", difficulty = 8,
                rolls = NX_FilterValid({
                        {name = "Wild Rose", chance = 25},
                        {name = "Fern", chance = 20},
                        {name = "Teal Leaves", chance = 15},
                        {name = "Branch", chance = 12, amount = {1, 2}},
                        {name = "Tree Branch", chance = 10},
                        {name = "Rock", chance = 8},
                }),
                rare = NX_FilterValid({
                        {name = "Grave Flower", chance = 1.0},
                        {name = "Bird Nest", chance = 0.5},
                })
        },
        woodland_trees = {
                tool = "knife", difficulty = 14,
                rolls = NX_FilterValid({
                        {name = "Branch", chance = 20, amount = {1, 3}},
                        {name = "Tree Branch", chance = 12},
                        {name = "Rock Soil", chance = 12},
                        {name = "Rock", chance = 10},
                        {name = "Teal Leaves", chance = 10},
                }),
                rare = NX_FilterValid({
                        {name = "Bird Nest", chance = 1.0},
                })
        },
        surface_ore = {
                tool = "pick", difficulty = 12,
                rolls = NX_FilterValid({
                        {name = "Vein of Ore", chance = 30},
                        {name = "Pulverized Ore", chance = 12},
                        {name = "Coal", chance = 12, amount = {1, 3}},
                        {name = "Stone Block", chance = 10},
                }),
                rare = NX_FilterValid({
                        {name = "Crystal", chance = 1.2},
                        {name = "Blue Crystal", chance = 0.7},
                        {name = "Gold Nugget", chance = 0.9},
                })
        },
        deep_basalt = {
                tool = "pick", difficulty = 18,
                rolls = NX_FilterValid({
                        {name = "Basalt", chance = 35, amount = {1, 2}},
                        {name = "Basalt Crystal Wall", chance = 6},
                        {name = "Stone Block", chance = 6},
                }),
                rare = NX_FilterValid({
                        {name = "Small Diamond", chance = 0.6},
                        {name = "Crystal", chance = 1.0},
                })
        }
}
M.NODES = NODES

local rollsResolved = 0
for key, node in pairs(NODES) do
        if NODE_KEYS[key] then
                rollsResolved = rollsResolved + (#node.rolls + #(node.rare or {}))
                if #node.rolls == 0 then
                        print(string.format("[nx_professions] warning: node '%s' has no common loot entries", key))
                end
        end
end

local toolCount = 0
for _, variants in pairs(NX_TOOL_VARIANTS) do
        toolCount = toolCount + (#variants or 0)
end
print(string.format("[nx_professions] %d tool variants resolved", toolCount))
print(string.format("[nx_professions] %d loot lines active", rollsResolved))

UNLOCKS = {
        FISH = { [1] = {"fishing_pool"} },
        FORG = { [1] = {"meadow_flowers"}, [5] = {"woodland_trees"} },
        MINE = { [1] = {"surface_ore"}, [10] = {"deep_basalt"} },
}
M.UNLOCKS = UNLOCKS

local unlockLookup = {}
for profKey, entries in pairs(UNLOCKS) do
        unlockLookup[profKey] = {}
        for level, list in pairs(entries) do
                for _, nodeKey in ipairs(list) do
                        local current = unlockLookup[profKey][nodeKey]
                        if not current or level < current then
                                unlockLookup[profKey][nodeKey] = level
                        end
                end
        end
end
NX_UNLOCK_LOOKUP = unlockLookup
M.NX_UNLOCK_LOOKUP = NX_UNLOCK_LOOKUP

local nodeNames = {
        fishing_pool = "fishing_pool",
        meadow_flowers = "meadow_flowers",
        woodland_trees = "woodland_trees",
        surface_ore = "surface_ore",
        deep_basalt = "deep_basalt"
}
NX_NODE_NAMES = nodeNames
M.NX_NODE_NAMES = NX_NODE_NAMES

local toolToProf = { rod = "FISH", pick = "MINE", knife = "FORG" }
NX_TOOL_TO_PROF = toolToProf
M.NX_TOOL_TO_PROF = NX_TOOL_TO_PROF

local function NX_EnsureProfStorage(player, prof)
        local level = player:getStorageValue(prof.LVL)
        if level < 1 then
                level = 1
                player:setStorageValue(prof.LVL, level)
        end
        local xp = player:getStorageValue(prof.XP)
        if xp < 0 then
                xp = 0
                player:setStorageValue(prof.XP, xp)
        end
        return level, xp
end
_G.NX_EnsureProfStorage = NX_EnsureProfStorage
M.NX_EnsureProfStorage = NX_EnsureProfStorage

local function NX_GetProfessionLevel(player, profKey)
        local prof = NX_PROF[profKey]
        if not prof then
                return 1, 0
        end
        return NX_EnsureProfStorage(player, prof)
end
_G.NX_GetProfessionLevel = NX_GetProfessionLevel
M.NX_GetProfessionLevel = NX_GetProfessionLevel

local function NX_SetLevelXP(player, storLvl, storXP, addXP)
        local level = player:getStorageValue(storLvl)
        if level < 1 then
                level = 1
        end
        local xp = player:getStorageValue(storXP)
        if xp < 0 then
                xp = 0
        end
        if addXP and addXP > 0 then
                xp = xp + addXP
        end

        local newLevel = 1
        for lvl = 1, #XP_PER_TIER do
                local req = XP_PER_TIER[lvl]
                if req and xp >= req then
                        newLevel = lvl
                else
                        break
                end
        end

        player:setStorageValue(storLvl, newLevel)
        player:setStorageValue(storXP, xp)
        return newLevel, xp
end
_G.NX_SetLevelXP = NX_SetLevelXP
M.NX_SetLevelXP = NX_SetLevelXP

local function NX_SuccessChance(player, storLvl, difficulty, base)
        base = base or 55
        local level = player:getStorageValue(storLvl)
        if level < 1 then
                level = 1
        end
        local chance = base + (level * 2) - (difficulty or 0)
        if chance < 5 then
                chance = 5
        elseif chance > 95 then
                chance = 95
        end
        return chance, level
end
_G.NX_SuccessChance = NX_SuccessChance
M.NX_SuccessChance = NX_SuccessChance

local rotationMode = lower(ANTI_BOT.rotation or "off")
local ROTATION_STAMP_KEY = 70550
NX_ROTATION_TOGGLE_KEY = 70551
M.NX_ROTATION_TOGGLE_KEY = NX_ROTATION_TOGGLE_KEY
NX_NODE_RARE_BONUS = NX_NODE_RARE_BONUS or {}
M.NX_NODE_RARE_BONUS = NX_NODE_RARE_BONUS

-- Rotation policy: return true only when a rotation window is open.
-- You can later swap this to weekly/daily cron-style checks.
function M.shouldRotateNow(now)
        if rotationMode == "off" then
                return false
        end
        if rotationMode == "serversave" then
                return true
        end
        if rotationMode == "daily" then
                local today = tonumber(os.date("%Y%m%d", now or os.time()))
                local stored = Game.getStorageValue(ROTATION_STAMP_KEY)
                if stored ~= today then
                        Game.setStorageValue(ROTATION_STAMP_KEY, today)
                        return true
                end
                return false
        end
        return true
end

-- Back-compat shim for older scripts that expect a global:
local function legacyShouldRotate()
        return M.shouldRotateNow(os.time())
end

_G.NX_ShouldRotate = legacyShouldRotate
M.NX_ShouldRotate = legacyShouldRotate

local function NX_SetNodeRarityBonus(nodeKey, multiplier)
        NX_NODE_RARE_BONUS[nodeKey] = multiplier
end
_G.NX_SetNodeRarityBonus = NX_SetNodeRarityBonus
M.NX_SetNodeRarityBonus = NX_SetNodeRarityBonus

local function resetRarity()
        for key in pairs(NODES) do
                NX_NODE_RARE_BONUS[key] = 1.0
        end
end
_G.NX_ResetRarity = resetRarity
M.NX_ResetRarity = resetRarity
resetRarity()

local function weightedRoll(entries, multiplier, allowFallback)
        if not entries or #entries == 0 then
                return nil
        end
        multiplier = multiplier or 1
        local fallback = entries[1]
        local fallbackChance = fallback and (fallback.chance or 0) or 0
        for _, entry in ipairs(entries) do
                local chance = entry.chance or 0
                local scaled = chance * multiplier
                if scaled >= 100 then
                        return entry
                end
                if scaled > 0 then
                        local roll = math.random(100000) / 1000
                        if roll <= scaled then
                                return entry
                        end
                end
                if chance > fallbackChance then
                        fallback = entry
                        fallbackChance = chance
                end
        end
        if allowFallback then
                return fallback
        end
        return nil
end
_G.NX_DoRoll = weightedRoll
M.NX_DoRoll = weightedRoll

local EXHAUST_BASE = 70300
NX_PROF_EXHAUST = {
        FISH = EXHAUST_BASE,
        FORG = EXHAUST_BASE + 1,
        MINE = EXHAUST_BASE + 2
}
M.NX_PROF_EXHAUST = NX_PROF_EXHAUST

local function NX_GetNextLevelInfo(level, xp)
        local nextLevel = nil
        for lvl = level + 1, #XP_PER_TIER do
                local req = XP_PER_TIER[lvl]
                if req then
                        nextLevel = lvl
                        if req > xp then
                                return nextLevel, req - xp
                        end
                end
        end
        return nil, nil
end
_G.NX_GetNextLevelInfo = NX_GetNextLevelInfo
M.NX_GetNextLevelInfo = NX_GetNextLevelInfo

local function NX_NextUnlock(profKey, level)
        local config = UNLOCKS[profKey]
        if not config then
                return nil, nil
        end
        local targetLevel
        local nodes
        for lvl = level + 1, 200 do
                local unlock = config[lvl]
                if unlock then
                        targetLevel = lvl
                        nodes = unlock
                        break
                end
                if lvl > #XP_PER_TIER then
                        break
                end
        end
        return targetLevel, nodes
end
_G.NX_NextUnlock = NX_NextUnlock
M.NX_NextUnlock = NX_NextUnlock

-- RNG helper preserved for compatibility
local function NX_Roll(pct)
        return math.random(1, 10000) <= math.floor((pct or 0) * 100)
end
M.roll = NX_Roll
M.NX_Roll = NX_Roll
_G.NX_Roll = NX_Roll

-- Self-test (non-fatal)
local probe = { "tree", "steel", "flower" }
for _, pname in ipairs(probe) do
        local pid = M.itemIdByName(pname)
        if pid then
                print(string.format("[nx_professions] probe '%s' -> id %d", pname, pid))
        else
                print(string.format("[nx_professions] probe '%s' not found (check items.xml naming)", pname))
        end
end

print("[nx_professions] loaded")

_G.NX_PROFESSIONS = M
return M
