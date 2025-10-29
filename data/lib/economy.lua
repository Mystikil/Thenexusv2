-- data/lib/economy.lua
-- Dynamic economy configuration + helper utilities shared across Lua systems.
-- Phase 1 introduces:
--   * Creature loot filtering (materials only)
--   * Runtime market activity tracking
--   * NPC stock/restock modelling with supply/demand pricing
--   * Dungeon reward metadata for future balancing hooks
-- The module is idempotent; it may be required multiple times safely.

if rawget(_G, 'NX_ECONOMY_LOADED') then
        return _G.NX_ECONOMY
end

local Economy = {}
Economy.VERSION = '1.0.0'
Economy.CONFIG_PATH = 'data/XML/npc_trading.xml'

local lower = string.lower
local min = math.min
local max = math.max
local floor = math.floor
local clamp = function(value, minValue, maxValue)
        if value < minValue then
                return minValue
        end
        if value > maxValue then
                return maxValue
        end
        return value
end

local function now()
        return os.time()
end

-- ========= Utility parsing helpers ========= --
local function parseAttributes(chunk)
        local attrs = {}
        for key, value in chunk:gmatch('(%w+)%s*=%s*"([^"]*)"') do
                attrs[key] = value
        end
        return attrs
end

local function parseNumber(value, fallback)
        if not value then
                return fallback
        end
        local numeric = tonumber(value)
        if not numeric then
                return fallback
        end
        return numeric
end

local function parsePercentage(value, fallback)
        local numeric = parseNumber(value, nil)
        if not numeric then
                return fallback
        end
        -- allow both 0.25 and 25 style declarations
        if numeric > 1 then
                numeric = numeric / 100
        end
        return numeric
end

local function parseList(value)
        local list = {}
        if not value or value == '' then
                return list
        end
        for token in value:gmatch('([^,]+)') do
                local trimmed = token:gsub('^%s+', ''):gsub('%s+$', '')
                if trimmed ~= '' then
                        table.insert(list, trimmed)
                end
        end
        return list
end

local function normalizeName(value)
        return lower((value or ''):gsub('^%s+', ''):gsub('%s+$', ''))
end

-- ========= Configuration parsing ========= --
local function parseNpcTradingConfig()
        local file = io.open(Economy.CONFIG_PATH, 'r')
        if not file then
                print(string.format('[Economy] WARNING: Unable to load %s', Economy.CONFIG_PATH))
                return {
                        market = {},
                        reputation = {},
                        restock = {},
                        dungeons = {},
                        npcs = {},
                        npcsByName = {},
                        npcsByTag = {}
                }
        end

        local content = file:read('*a') or ''
        file:close()

        local market = {}
        local reputation = {}
        local restock = {}
        local dungeons = {}
        local npcsByName = {}
        local npcsByTag = {}
        local npcs = {}

        local marketChunk = content:match('<market%s+([^/>]+)%s*/?>')
        if marketChunk then
                local attrs = parseAttributes(marketChunk)
                market.baseFee = parseNumber(attrs.baseFee, 0)
                market.baseBuyModifier = parseNumber(attrs.baseBuyModifier, 1.0)
                market.baseSellModifier = parseNumber(attrs.baseSellModifier, 1.0)
                market.activityWindowHours = parseNumber(attrs.activityWindowHours, 6)
                market.activityFeePerMillion = parseNumber(attrs.activityFeePerMillion, 0)
                market.minModifier = parseNumber(attrs.minModifier, 0.75)
                market.maxModifier = parseNumber(attrs.maxModifier, 1.35)
                market.priceFloor = parseNumber(attrs.priceFloor, nil)
                market.priceCeiling = parseNumber(attrs.priceCeiling, nil)
        end

        local reputationChunk = content:match('<reputation%s+([^/>]+)%s*/?>')
        if reputationChunk then
                local attrs = parseAttributes(reputationChunk)
                for tier, value in pairs(attrs) do
                        reputation[normalizeName(tier)] = parseNumber(value, 1.0)
                end
        end

        local restockChunk = content:match('<restockDefaults%s+([^/>]+)%s*/?>')
        if restockChunk then
                local attrs = parseAttributes(restockChunk)
                restock.intervalHours = parseNumber(attrs.intervalHours, 12)
                restock.percent = parsePercentage(attrs.percent, 0.25)
        end

        local dungeonBlock = content:match('<dungeonRewards>(.-)</dungeonRewards>')
        if dungeonBlock then
                for chunk in dungeonBlock:gmatch('<dungeon%s+([^/>]+)%s*/?>') do
                        local attrs = parseAttributes(chunk)
                        local id = attrs.id or ''
                        if id ~= '' then
                                dungeons[id] = {
                                        id = id,
                                        baseGold = parseNumber(attrs.baseGold, 0),
                                        bonusPerDifficulty = parseNumber(attrs.bonusPerDifficulty, 0),
                                        pool = attrs.pool or ''
                                }
                        end
                end
        end

        for attributes, inner in content:gmatch('<npc%s+([^>]+)>(.-)</npc>') do
                local attrs = parseAttributes(attributes)
                local name = attrs.name or ''
                if name ~= '' then
                        local key = normalizeName(name)
                        local tag = normalizeName(attrs.tag or '')
                        local npc = {
                                name = name,
                                key = key,
                                tag = tag ~= '' and tag or nil,
                                restockIntervalHours = parseNumber(attrs.restockIntervalHours, restock.intervalHours or 12),
                                restockPercent = parsePercentage(attrs.restockPercent, restock.percent or 0.25),
                                feeBuy = parseNumber(attrs.feeBuy, nil),
                                feeSell = parseNumber(attrs.feeSell, nil),
                                reputationFloor = attrs.reputationFloor,
                                items = {},
                                defaults = {
                                        scarcitySlope = parseNumber(attrs.scarcitySlope, nil),
                                        supplySlope = parseNumber(attrs.supplySlope, nil),
                                        scarcityMin = parseNumber(attrs.scarcityMin, nil),
                                        scarcityMax = parseNumber(attrs.scarcityMax, nil),
                                        supplyMin = parseNumber(attrs.supplyMin, nil),
                                        supplyMax = parseNumber(attrs.supplyMax, nil),
                                        overstockFactor = parseNumber(attrs.overstockFactor, nil)
                                }
                        }

                        for stockChunk in inner:gmatch('<stock%s+([^/>]+)%s*/?>') do
                                local stockAttrs = parseAttributes(stockChunk)
                                local itemId = parseNumber(stockAttrs.itemId, 0)
                                if itemId and itemId > 0 then
                                        npc.items[itemId] = {
                                                itemId = itemId,
                                                max = parseNumber(stockAttrs.max, 0),
                                                restockIntervalHours = parseNumber(stockAttrs.restockIntervalHours, npc.restockIntervalHours),
                                                restockPercent = parsePercentage(stockAttrs.restockPercent, npc.restockPercent),
                                                scarcitySlope = parseNumber(stockAttrs.scarcitySlope, npc.defaults.scarcitySlope),
                                                supplySlope = parseNumber(stockAttrs.supplySlope, npc.defaults.supplySlope),
                                                scarcityMin = parseNumber(stockAttrs.scarcityMin, npc.defaults.scarcityMin),
                                                scarcityMax = parseNumber(stockAttrs.scarcityMax, npc.defaults.scarcityMax),
                                                supplyMin = parseNumber(stockAttrs.supplyMin, npc.defaults.supplyMin),
                                                supplyMax = parseNumber(stockAttrs.supplyMax, npc.defaults.supplyMax),
                                                overstockFactor = parseNumber(stockAttrs.overstockFactor, npc.defaults.overstockFactor),
                                                keywords = parseList(stockAttrs.keywords),
                                                minTier = stockAttrs.minTier
                                        }
                                end
                        end

                        npcs[#npcs + 1] = npc
                        npcsByName[key] = npc
                        if npc.tag then
                                npcsByTag[npc.tag] = npc
                        end
                end
        end

        return {
                market = market,
                reputation = reputation,
                restock = restock,
                dungeons = dungeons,
                npcs = npcs,
                npcsByName = npcsByName,
                npcsByTag = npcsByTag
        }
end

Economy.CONFIG = parseNpcTradingConfig()

-- ========= Runtime caches ========= --
Economy._runtime = {
        market = {
                volume = 0,
                lastDecay = now()
        },
        npc = {}
}

Economy.DEFAULTS = {
        restockIntervalHours = Economy.CONFIG.restock.intervalHours or 12,
        restockPercent = Economy.CONFIG.restock.percent or 0.25,
        scarcitySlope = 0.50,
        scarcityMin = 0.85,
        scarcityMax = 1.40,
        supplySlope = 0.35,
        supplyMin = 0.65,
        supplyMax = 1.25,
        overstockFactor = 1.5
}

Economy.LOOT = {
        enforceFilter = true,
        allowStackableFallback = true,
        forceAllowIds = {
                [5880] = true, -- iron ore
                [5902] = true, -- hardened bone
                [6500] = true, -- demonic essence
                [11223] = true, -- essence of a bad dream
                [12636] = true, -- lizard essence
                [21500] = true, -- bone shard
                [22472] = true,
                [22473] = true
        },
        forceBlockIds = {
                [2148] = true,
                [2152] = true,
                [2160] = true,
                [23799] = true -- gold pouch / fallback coin containers
        },
        allowedKeywords = {
                'hide', 'scale', 'bone', 'skull', 'tooth', 'claw', 'fang', 'essence', 'meat', 'tissue',
                'carapace', 'chitin', 'venom', 'poison', 'gland', 'heart', 'core', 'wing', 'feather',
                'tail', 'horn', 'mane', 'fur', 'blood', 'ichor', 'tentacle', 'spine', 'sinew', 'shell',
                'fragment', 'powder', 'dust', 'ash', 'seed', 'sap', 'root', 'petal', 'bark', 'spore',
                'stinger', 'eye', 'fin', 'whisker', 'plasma', 'residue', 'ectoplasm', 'soul', 'toxin',
                'ooze', 'gel', 'goo', 'fiber', 'leather', 'tusk', 'antler', 'scale', 'plate', 'prism',
                'pearl', 'amber', 'jelly', 'wart', 'moss', 'lichen'
        }
}

Economy.DUNGEONS = Economy.CONFIG.dungeons
Economy.REPUTATION_MODIFIERS = Economy.CONFIG.reputation
Economy.MARKET = Economy.CONFIG.market

-- ========= Loot filtering ========= --
local function getItemType(itemId)
        local ok, itemType = pcall(ItemType, itemId)
        if not ok or not itemType or itemType:getId() == 0 then
                return nil
        end
        return itemType
end

local function isEquipment(itemType)
        if not itemType then
                return false
        end
        if itemType:isWeapon() then
                return true
        end
        if itemType:isArmor() or itemType:isHelmet() or itemType:isBoots() or itemType:isLegs() then
                return true
        end
        if itemType:isShield() or itemType:isBackpack() then
                return true
        end
        if itemType:isNecklace() or itemType:isRing() or itemType:isTrinket() then
                return true
        end
        if itemType:isAmmo() then
                return true
        end
        return false
end

local function nameMatchesKeyword(itemType, keywords)
        if not itemType then
                return false
        end
        local name = lower(itemType:getName() or '')
        if name == '' then
                return false
        end
        for i = 1, #keywords do
                local token = keywords[i]
                if token ~= '' and name:find(token, 1, true) then
                        return true
                end
        end
        return false
end

function Economy.isCreatureProduct(itemId)
        local lootConfig = Economy.LOOT
        if not lootConfig.enforceFilter then
                return true
        end

        if lootConfig.forceBlockIds[itemId] then
                return false
        end
        if lootConfig.forceAllowIds[itemId] then
                return true
        end

        local itemType = getItemType(itemId)
        if not itemType then
                return false
        end

        if itemType:getWorth() > 0 then
                return false
        end

        if isEquipment(itemType) then
                        return false
        end

        if itemType:isKey() or itemType:isSplash() or itemType:isBed() then
                return false
        end

        if nameMatchesKeyword(itemType, lootConfig.allowedKeywords) then
                return true
        end

        if lootConfig.allowStackableFallback and itemType:isStackable() then
                return true
        end

        return false
end

local function shouldAllowContainer(item, runtimeContext)
        local childLoot = item.childLoot or {}
        for i = 1, #childLoot do
                if Economy.shouldAllowLootItem(childLoot[i], runtimeContext) then
                        return true
                end
        end
        return false
end

function Economy.shouldAllowLootItem(entry, runtimeContext)
        if not entry or not entry.itemId then
                return false
        end

        if not Economy.LOOT.enforceFilter then
                return true
        end

        local itemType = getItemType(entry.itemId)
        if itemType and itemType:isContainer() then
                return shouldAllowContainer(entry, runtimeContext)
        end

        return Economy.isCreatureProduct(entry.itemId)
end

-- ========= NPC runtime helpers ========= --
local function getNpcConfig(npcContext)
        if not npcContext then
                return nil
        end
        local name
        if type(npcContext) == 'string' then
                name = npcContext
        else
                name = npcContext.npcName or npcContext.name
        end
        local normalized = normalizeName(name)
        local npc = Economy.CONFIG.npcsByName[normalized]
        if not npc and type(npcContext) == 'table' then
                local tag
                if npcContext.options and npcContext.options.economyTag then
                        tag = normalizeName(npcContext.options.economyTag)
                elseif npcContext.tag then
                        tag = normalizeName(npcContext.tag)
                elseif npcContext.factionName then
                        tag = normalizeName(npcContext.factionName)
                end
                if tag and tag ~= '' then
                        npc = Economy.CONFIG.npcsByTag[tag]
                end
        end
        return npc
end

local function ensureRuntimeNpc(npc)
        local key = npc.key or normalizeName(npc.name)
        local registry = Economy._runtime.npc
        local bucket = registry[key]
        if not bucket then
                bucket = { items = {}, key = key }
                registry[key] = bucket
        end
        return bucket
end

local function ensureRuntimeItem(npcContext, itemId)
        local npcConfig = getNpcConfig(npcContext)
        if not npcConfig then
                return nil, nil
        end
        local itemConfig = npcConfig.items[itemId]
        if not itemConfig then
                return nil, nil
        end
        local bucket = ensureRuntimeNpc(npcConfig)
        local itemEntry = bucket.items[itemId]
        if not itemEntry then
                local defaults = Economy.DEFAULTS
                local maxStock = itemConfig.max > 0 and itemConfig.max or defaults.overstockFactor * 10
                itemEntry = {
                        itemId = itemId,
                        max = maxStock,
                        overstockFactor = itemConfig.overstockFactor or npcConfig.defaults.overstockFactor or defaults.overstockFactor,
                        restockPercent = itemConfig.restockPercent or npcConfig.restockPercent or defaults.restockPercent,
                        restockInterval = (itemConfig.restockIntervalHours or npcConfig.restockIntervalHours or defaults.restockIntervalHours) * 3600,
                        scarcitySlope = itemConfig.scarcitySlope or npcConfig.defaults.scarcitySlope or defaults.scarcitySlope,
                        scarcityMin = itemConfig.scarcityMin or npcConfig.defaults.scarcityMin or defaults.scarcityMin,
                        scarcityMax = itemConfig.scarcityMax or npcConfig.defaults.scarcityMax or defaults.scarcityMax,
                        supplySlope = itemConfig.supplySlope or npcConfig.defaults.supplySlope or defaults.supplySlope,
                        supplyMin = itemConfig.supplyMin or npcConfig.defaults.supplyMin or defaults.supplyMin,
                        supplyMax = itemConfig.supplyMax or npcConfig.defaults.supplyMax or defaults.supplyMax,
                        minTier = itemConfig.minTier,
                        keywords = itemConfig.keywords,
                        lastRestock = now(),
                        current = max(maxStock, 0)
                }
                bucket.items[itemId] = itemEntry
        end
        return itemEntry, npcConfig
end

local function restockItem(itemEntry)
        if not itemEntry or itemEntry.restockInterval <= 0 then
                return
        end
        local currentTime = now()
        local elapsed = currentTime - (itemEntry.lastRestock or currentTime)
        if elapsed < itemEntry.restockInterval then
                return
        end
        local ticks = floor(elapsed / itemEntry.restockInterval)
        if ticks <= 0 then
                return
        end
        local percent = itemEntry.restockPercent
        local maxStock = itemEntry.max
        for _ = 1, ticks do
                local delta = max(1, floor(maxStock * percent + 0.5))
                itemEntry.current = min(maxStock, itemEntry.current + delta)
        end
        itemEntry.lastRestock = itemEntry.lastRestock + ticks * itemEntry.restockInterval
        if itemEntry.lastRestock < currentTime - itemEntry.restockInterval then
                itemEntry.lastRestock = currentTime
        end
end

local function getStockState(itemEntry)
        if not itemEntry then
                return { current = 0, max = 0 }
        end
        restockItem(itemEntry)
        return {
                current = itemEntry.current,
                max = itemEntry.max,
                minTier = itemEntry.minTier
        }
end

local function adjustStock(itemEntry, delta)
        if not itemEntry then
                return
        end
        restockItem(itemEntry)
        if delta == 0 then
                return
        end
        if delta > 0 then
                local cap = itemEntry.max * (itemEntry.overstockFactor or Economy.DEFAULTS.overstockFactor)
                itemEntry.current = min(cap, itemEntry.current + delta)
        else
                itemEntry.current = max(0, itemEntry.current + delta)
        end
end

local function computeScarcityMultiplier(itemEntry)
        if not itemEntry or itemEntry.max <= 0 then
                return 1.0
        end
        restockItem(itemEntry)
        local ratio = clamp(itemEntry.current / itemEntry.max, 0, 1)
        local slope = itemEntry.scarcitySlope or Economy.DEFAULTS.scarcitySlope
        local minValue = itemEntry.scarcityMin or Economy.DEFAULTS.scarcityMin
        local maxValue = itemEntry.scarcityMax or Economy.DEFAULTS.scarcityMax
        local multiplier = 1 + (1 - ratio) * slope
        return clamp(multiplier, minValue, maxValue)
end

local function computeSupplyMultiplier(itemEntry)
        if not itemEntry or itemEntry.max <= 0 then
                return 1.0
        end
        restockItem(itemEntry)
        local overstockFactor = itemEntry.overstockFactor or Economy.DEFAULTS.overstockFactor
        local ratio = clamp(itemEntry.current / itemEntry.max, 0, overstockFactor)
        local slope = itemEntry.supplySlope or Economy.DEFAULTS.supplySlope
        local minValue = itemEntry.supplyMin or Economy.DEFAULTS.supplyMin
        local maxValue = itemEntry.supplyMax or Economy.DEFAULTS.supplyMax
        local multiplier = 1 - ((ratio - 0.5) * slope)
        return clamp(multiplier, minValue, maxValue)
end

function Economy.getNpcItemState(npcContext, itemId)
        local itemEntry = ensureRuntimeItem(npcContext, itemId)
        if not itemEntry then
                return nil
        end
        return getStockState(itemEntry)
end

function Economy.hasNpcStock(npcContext, itemId, amount)
        local itemEntry = ensureRuntimeItem(npcContext, itemId)
        if not itemEntry then
                return true
        end
        restockItem(itemEntry)
        return itemEntry.current >= (amount or 1)
end

function Economy.canNpcProvide(npcContext, priceType, itemId, amount)
        if priceType ~= 'buy' then
                ensureRuntimeItem(npcContext, itemId)
                return true
        end
        return Economy.hasNpcStock(npcContext, itemId, amount)
end

function Economy.mergeNpcMetadata(npcContext, priceType, shopItem, metadata)
        local itemEntry, npcConfig = ensureRuntimeItem(npcContext, shopItem.id)
        if not itemEntry then
                return metadata
        end
        local merged = {}
        if metadata then
                for k, v in pairs(metadata) do
                        merged[k] = v
                end
        end
        if itemEntry.minTier and (priceType == 'buy' or priceType == 'sell') and not merged.minTier then
                merged.minTier = itemEntry.minTier
        end
        merged._economy = getStockState(itemEntry)
        merged._economy.npcName = npcConfig and npcConfig.name or npcContext.npcName
        return merged
end

function Economy.resolveNpcPricing(npcContext, params)
        local itemEntry, npcConfig = ensureRuntimeItem(npcContext, params.itemId)
        if not itemEntry then
                return nil
        end
        local priceType = params.type or 'buy'
        local multiplier
        if priceType == 'buy' then
                multiplier = computeScarcityMultiplier(itemEntry)
        else
                multiplier = computeSupplyMultiplier(itemEntry)
        end

        local result = {
                priceMultiplier = multiplier,
                stock = getStockState(itemEntry)
        }

        local globalFloor = Economy.MARKET and Economy.MARKET.priceFloor or nil
        local globalCeiling = Economy.MARKET and Economy.MARKET.priceCeiling or nil
        if npcConfig and npcConfig.reputationFloor then
                result.reputationFloor = npcConfig.reputationFloor
        end
        result.priceFloor = npcConfig and npcConfig.priceFloor or globalFloor
        result.priceCeiling = npcConfig and npcConfig.priceCeiling or globalCeiling

        if priceType == 'buy' and npcConfig and npcConfig.feeBuy then
                result.feeRate = npcConfig.feeBuy
        elseif priceType == 'sell' and npcConfig and npcConfig.feeSell then
                result.feeRate = npcConfig.feeSell
        end

        result.metadata = params.metadata
        return result
end

function Economy.registerNpcTrade(player, npcContext, trade)
        if not trade or not trade.itemId then
                return
        end
        local itemEntry = ensureRuntimeItem(npcContext, trade.itemId)
        if not itemEntry then
                return
        end
        if trade.type == 'buy' then
                adjustStock(itemEntry, -max(1, trade.amount or 1))
        elseif trade.type == 'sell' then
                adjustStock(itemEntry, max(1, trade.amount or 1))
        end
        local total = trade.totalNet or trade.totalGross or 0
        if total > 0 then
                Economy.registerMarketActivity(total)
        end
end

-- ========= Market activity ========= --
function Economy.registerMarketActivity(amount)
        local runtime = Economy._runtime.market
        runtime.volume = runtime.volume + max(0, amount or 0)
end

local function decayMarketActivity()
        local marketConfig = Economy.MARKET
        if not marketConfig or marketConfig.activityWindowHours <= 0 then
                return
        end
        local runtime = Economy._runtime.market
        local window = (marketConfig.activityWindowHours or 6) * 3600
        local currentTime = now()
        local elapsed = currentTime - (runtime.lastDecay or currentTime)
        if elapsed <= 0 then
                return
        end
        if elapsed >= window then
                runtime.volume = 0
                runtime.lastDecay = currentTime
                return
        end
        local decayFactor = clamp(1 - (elapsed / window), 0, 1)
        runtime.volume = runtime.volume * decayFactor
        runtime.lastDecay = currentTime
end

function Economy.getMarketFeeModifier(priceType)
        local marketConfig = Economy.MARKET or {}
        decayMarketActivity()
        local base = priceType == 'sell' and (marketConfig.baseSellModifier or 1.0) or (marketConfig.baseBuyModifier or 1.0)
        local perMillion = marketConfig.activityFeePerMillion or 0
        local volume = Economy._runtime.market.volume or 0
        local modifier = base + (volume / 1e6) * perMillion
        modifier = clamp(modifier, marketConfig.minModifier or 0.8, marketConfig.maxModifier or 1.35)
        return modifier
end

function Economy.getDungeonRewardConfig(id)
        return Economy.DUNGEONS[id]
end

function Economy.getReputationPriceModifier(tierName)
        if not tierName then
                return 1.0
        end
        return Economy.REPUTATION_MODIFIERS[normalizeName(tierName)] or 1.0
end

_G.NX_ECONOMY_LOADED = true
_G.NX_ECONOMY = Economy
return Economy
