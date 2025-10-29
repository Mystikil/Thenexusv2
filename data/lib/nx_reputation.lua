local trace = trace or { checkpoint = function() end }
trace.checkpoint('rep_eco:nx_reputation.lua:begin')

-- Reputation & Trading Economy runtime helpers
if not NX_REPUTATION_CONFIG then
    dofile('data/lib/nx_reputation_config.lua')
end

local ReputationEconomy = rawget(_G, 'ReputationEconomy') or {}
_G.ReputationEconomy = ReputationEconomy

local reputationEnabled = rawget(_G, '__REPUTATION_SYSTEM_ENABLED')
if reputationEnabled == nil then
    reputationEnabled = true
end
local economyEnabled = rawget(_G, '__ECONOMY_SYSTEM_ENABLED')
if economyEnabled == nil then
    economyEnabled = true
end

if not reputationEnabled and not economyEnabled then
    -- Ensure a benign namespace exists even when the feature is disabled so callers can safely short-circuit.
    local defaultTier = (NX_REPUTATION_CONFIG and NX_REPUTATION_CONFIG.tiers and NX_REPUTATION_CONFIG.tiers[1]) or {
        name = 'Neutral',
        min = -math.huge,
        max = math.huge,
    }
    local economyApi = rawget(_G, 'NX_ECONOMY')

    function ReputationEconomy.getFactionId()
        return nil
    end

    function ReputationEconomy.getFactionConfig()
        return nil
    end

    function ReputationEconomy.getPlayerReputation(player, factionId)
        local playerId = type(player) == 'number' and player or (player and player.getGuid and player:getGuid())
        return {
            playerId = playerId,
            factionId = factionId,
            value = 0,
            tier = defaultTier,
        }
    end

    function ReputationEconomy.getEconomyState()
        return { label = 'disabled', modifier = 1.0 }
    end

    function ReputationEconomy.getAllFactions()
        return {}
    end

    function ReputationEconomy.getFactionConfigList()
        return {}
    end

    function ReputationEconomy.addReputation()
        return false
    end

    function ReputationEconomy.addTradeReputation()
        return false
    end

    function ReputationEconomy.setNpcFaction()
        return false
    end

    function ReputationEconomy.calculateNpcPrice(_, _, params)
        params = params or {}
        local basePrice = params.basePrice or 0
        local amount = math.max(1, params.amount or 1)
        return {
            unitPrice = basePrice,
            unitGross = basePrice,
            unitFee = 0,
            grossTotal = basePrice * amount,
            netTotal = basePrice * amount,
            totalFee = 0,
            tier = defaultTier,
            tierModifier = 1.0,
            economyModifier = 1.0,
            economyState = { label = 'disabled', modifier = 1.0 },
            globalModifier = 1.0,
            feeRate = 0,
            factionConfig = nil,
            dynamic = nil,
            stock = nil,
        }
    end

    function ReputationEconomy.filterShopItems(_, npcHandler)
        local items = {}
        if npcHandler and npcHandler.shopItems then
            for i = 1, #npcHandler.shopItems do
                items[#items + 1] = npcHandler.shopItems[i]
            end
        end
        return items
    end

    function ReputationEconomy.queueEconomyDelta()
        return false
    end

    function ReputationEconomy.flushEconomyLedger()
        return false
    end

    function ReputationEconomy.captureMarketFees()
        return 0
    end

    function ReputationEconomy.applyDecay()
        return 0
    end

    function ReputationEconomy.onStartup()
        return false
    end

    function ReputationEconomy.getAllFactionsByName()
        return {}
    end

    function ReputationEconomy.getTierIndex()
        return 1
    end

    function ReputationEconomy.getTierForValue()
        return defaultTier
    end

    function ReputationEconomy.pointsToNextTier()
        return nil
    end

    function ReputationEconomy.hasTier()
        return true
    end

    function ReputationEconomy.getNpcContext()
        return nil
    end

    function ReputationEconomy.sendShopHint()
        return false
    end

    function ReputationEconomy.registerShopItemMetadata()
        return false
    end

    function ReputationEconomy.onNpcTrade(player, npcContext, trade)
        if economyApi and economyApi.registerNpcTrade then
            economyApi.registerNpcTrade(player, npcContext, trade)
        end
        return false
    end

    trace.checkpoint('rep_eco:nx_reputation.lua:disabled')
    return ReputationEconomy
end

local config = NX_REPUTATION_CONFIG
local tierOrder = config._tierOrder or {}
local Economy = rawget(_G, 'NX_ECONOMY')
local OFFERSTATE_ACCEPTED = rawget(_G, 'OFFERSTATE_ACCEPTED') or 3
local jsonEncode = json and json.encode or function()
    return '{}'
end

local TABLE_FACTIONS = 'factions'
local TABLE_NPC_FACTIONS = 'npc_factions'
local TABLE_PLAYER_REP = 'player_faction_reputation'
local TABLE_PLAYER_REP_LOG = 'player_faction_reputation_log'
local TABLE_ECONOMY = 'faction_economy'
local TABLE_ECONOMY_LEDGER = 'faction_economy_ledger'
local TABLE_ECONOMY_HISTORY = 'faction_economy_history'
local TABLE_MARKET_CURSOR = 'faction_market_cursor'

local playerTierCache = ReputationEconomy._playerTierCache or {}
local factionCache = ReputationEconomy._factionCache or { byId = {}, byName = {} }
local npcFactionCache = ReputationEconomy._npcFactionCache or {}
local factionEconomyCache = ReputationEconomy._factionEconomyCache or {}
local npcShopMetadata = ReputationEconomy._npcShopMetadata or setmetatable({}, { __mode = 'k' })

ReputationEconomy._playerTierCache = playerTierCache
ReputationEconomy._factionCache = factionCache
ReputationEconomy._npcFactionCache = npcFactionCache
ReputationEconomy._factionEconomyCache = factionEconomyCache
ReputationEconomy._npcShopMetadata = npcShopMetadata

local function now()
    return os.time()
end

local function escape(value)
    return db.escapeString(value)
end

local function ensureFactionCached()
    if next(factionCache.byId) then
        return
    end
    for name, info in pairs(config.factions) do
        factionCache.byId[info.id] = { name = name, config = info }
        factionCache.byName[name] = info.id
    end
end

local function getFactionInfoById(factionId)
    ensureFactionCached()
    return factionCache.byId[factionId]
end

local function getFactionIdByName(factionName)
    ensureFactionCached()
    local id = factionCache.byName[factionName]
    if id then
        return id
    end

    -- fallback to database lookup if a custom faction has been added outside config
    local resultId = db.storeQuery(string.format('SELECT `id`, `name` FROM `%s` WHERE `name` = %s LIMIT 1', TABLE_FACTIONS, escape(factionName)))
    if resultId then
        local fetchedId = result.getNumber(resultId, 'id')
        local fetchedName = result.getString(resultId, 'name')
        result.free(resultId)
        factionCache.byId[fetchedId] = { name = fetchedName, config = config.factions[fetchedName] or {} }
        factionCache.byName[fetchedName] = fetchedId
        return fetchedId
    end
    return nil
end

function ReputationEconomy.getFactionId(factionName)
    return getFactionIdByName(factionName)
end

function ReputationEconomy.getFactionConfig(faction)
    ensureFactionCached()
    if type(faction) == 'string' then
        local info = config.factions[faction]
        if info then
            return info
        end
        local id = getFactionIdByName(faction)
        local cached = id and getFactionInfoById(id)
        return cached and cached.config or nil
    end
    if type(faction) == 'number' then
        local cached = getFactionInfoById(faction)
        if cached then
            if cached.config and next(cached.config) then
                return cached.config
            end
            if cached.name then
                return config.factions[cached.name]
            end
        end
    end
    return nil
end

local function resolveTierByValue(points)
    for _, tier in ipairs(config.tiers) do
        if points >= tier.min and points <= tier.max then
            return tier
        end
    end
    return config.tiers[#config.tiers]
end

local function getTierIndex(tierName)
    return tierOrder[tierName] or 1
end

function ReputationEconomy.getTierIndex(tierName)
    return getTierIndex(tierName)
end

local function ensurePlayerEntry(playerId, factionId)
    db.query(string.format(
        'INSERT IGNORE INTO `%s` (`player_id`, `faction_id`, `reputation`, `last_activity`, `last_decay`) VALUES (%d, %d, 0, %d, %d)',
        TABLE_PLAYER_REP,
        playerId,
        factionId,
        now(),
        now()
    ))
end

local function cachePlayerTier(playerId, factionId, repValue, tier)
    local factionCacheEntry = playerTierCache[playerId]
    if not factionCacheEntry then
        factionCacheEntry = {}
        playerTierCache[playerId] = factionCacheEntry
    end
    factionCacheEntry[factionId] = {
        timestamp = now(),
        reputation = repValue,
        tier = tier,
    }
end

local function getCachedTier(playerId, factionId)
    local factionCacheEntry = playerTierCache[playerId]
    if factionCacheEntry then
        local entry = factionCacheEntry[factionId]
        if entry and (now() - entry.timestamp) <= config.cache.ttl then
            return entry
        end
    end
    return nil
end

function ReputationEconomy.clearPlayerCache(playerId)
    if playerId then
        playerTierCache[playerId] = nil
    else
        playerTierCache = {}
        ReputationEconomy._playerTierCache = playerTierCache
    end
end

local function fetchPlayerReputation(playerId, factionId, bypassCache)
    if not bypassCache then
        local cached = getCachedTier(playerId, factionId)
        if cached then
            return cached.reputation, cached.tier
        end
    end

    ensurePlayerEntry(playerId, factionId)

    local query = string.format('SELECT `reputation` FROM `%s` WHERE `player_id` = %d AND `faction_id` = %d LIMIT 1', TABLE_PLAYER_REP, playerId, factionId)
    local resultId = db.storeQuery(query)
    local reputationValue = 0
    if resultId then
        reputationValue = result.getNumber(resultId, 'reputation')
        result.free(resultId)
    end
    local tier = resolveTierByValue(reputationValue)
    cachePlayerTier(playerId, factionId, reputationValue, tier)
    return reputationValue, tier
end

function ReputationEconomy.getPlayerReputation(player, factionId, bypassCache)
    if not reputationEnabled then
        local defaultTier = config.tiers[1]
        return {
            playerId = type(player) == 'number' and player or player:getGuid(),
            factionId = factionId,
            value = 0,
            tier = defaultTier,
        }
    end

    local playerId = type(player) == 'number' and player or player:getGuid()
    local repValue, tier = fetchPlayerReputation(playerId, factionId, bypassCache)
    return {
        playerId = playerId,
        factionId = factionId,
        value = repValue,
        tier = tier,
    }
end

function ReputationEconomy.getTierForValue(points)
    return resolveTierByValue(points)
end

function ReputationEconomy.pointsToNextTier(points)
    local currentTier = resolveTierByValue(points)
    local currentIndex = getTierIndex(currentTier.name)
    local nextTier = config.tiers[currentIndex + 1]
    if not nextTier then
        return nil
    end
    if points >= nextTier.min then
        return 0
    end
    return nextTier.min - points
end

local function ensureEconomyRow(factionId, seedPool)
    db.query(string.format(
        'INSERT IGNORE INTO `%s` (`faction_id`, `pool`, `updated_at`) VALUES (%d, %d, %d)',
        TABLE_ECONOMY,
        factionId,
        seedPool or 0,
        now()
    ))
end

local function fetchEconomyState(factionId, factionConfig, bypassCache)
    local cached = factionEconomyCache[factionId]
    if cached and not bypassCache and (now() - cached.timestamp) <= config.cache.ttl then
        return cached
    end

    ensureEconomyRow(factionId, (factionConfig and factionConfig.economy and factionConfig.economy.seedPool) or 0)

    local query = string.format('SELECT `pool`, `updated_at` FROM `%s` WHERE `faction_id` = %d LIMIT 1', TABLE_ECONOMY, factionId)
    local resultId = db.storeQuery(query)
    local pool = 0
    local updatedAt = now()
    if resultId then
        pool = result.getNumber(resultId, 'pool')
        updatedAt = result.getNumber(resultId, 'updated_at')
        result.free(resultId)
    end

    local entry = {
        pool = pool,
        updatedAt = updatedAt,
        timestamp = now()
    }
    factionEconomyCache[factionId] = entry
    return entry
end

function ReputationEconomy.getEconomyPool(factionId, bypassCache)
    if not economyEnabled then
        return 0
    end
    local factionConfig = ReputationEconomy.getFactionConfig(factionId)
    local state = fetchEconomyState(factionId, factionConfig, bypassCache)
    return state.pool
end

function ReputationEconomy.getEconomyState(factionId, bypassCache)
    if not economyEnabled then
        return {
            pool = 0,
            modifier = 1.0,
            label = 'disabled',
            secretChance = 0,
            capDiscount = false,
        }
    end
    local factionConfig = ReputationEconomy.getFactionConfig(factionId) or {}
    local economyConfig = factionConfig.economy or {}
    local stateData = fetchEconomyState(factionId, factionConfig, bypassCache)
    local pool = stateData.pool

    local thresholds = economyConfig.thresholds or {}
    local selected = thresholds[1] or { modifier = 1.0, label = 'Neutral', secretChance = 0 }
    for _, threshold in ipairs(thresholds) do
        if pool >= threshold.min then
            selected = threshold
        end
    end

    local modifier = selected.modifier or 1.0
    if economyConfig.minModifier then
        modifier = math.max(economyConfig.minModifier, modifier)
    end
    if economyConfig.maxModifier then
        modifier = math.min(economyConfig.maxModifier, modifier)
    end

    return {
        pool = pool,
        modifier = modifier,
        label = selected.label or 'Neutral',
        secretChance = selected.secretChance or 0,
        capDiscount = selected.capDiscount or false
    }
end

local function resolveNpcName(npcHandler, options)
    if options and options.npcName then
        return options.npcName
    end
    if npcHandler then
        local getParameter = npcHandler.getParameter
        if type(getParameter) == 'function' then
            local name = npcHandler:getParameter('name')
            if name then
                return name
            end
        end
    end
    -- fallback to config hints
    return nil
end

function ReputationEconomy.setNpcFaction(npcHandler, factionName, options)
    local factionId = getFactionIdByName(factionName)
    if not factionId then
        print(string.format('[ReputationEconomy] Unknown faction "%s" for NPC handler.', tostring(factionName)))
        return nil
    end

    local npcName = options and options.npcName or nil
    if not npcName and type(npcHandler) == 'table' and npcHandler.getParameter then
        npcName = npcHandler:getParameter('name')
    end

    if not npcName and type(options) == 'table' and options.forceName then
        npcName = options.forceName
    end

    local configOverride
    if npcName and config.npcs[npcName] then
        configOverride = config.npcs[npcName]
    end

    local context = {
        factionId = factionId,
        factionName = factionName,
        npcName = npcName,
        options = options or {},
        configOverride = configOverride
    }

    npcHandler.__reputationContext = context
    npcHandler.__reputationShopMeta = npcHandler.__reputationShopMeta or { buy = {}, sell = {} }

    if npcName then
        npcFactionCache[npcName] = factionId
        db.asyncQuery(string.format(
            'INSERT INTO `%s` (`npc_name`, `faction_id`) VALUES (%s, %d) ON DUPLICATE KEY UPDATE `faction_id` = VALUES(`faction_id`)',
            TABLE_NPC_FACTIONS,
            escape(npcName),
            factionId
        ))
    end

    return context
end

function ReputationEconomy.getNpcContext(npcHandler)
    if npcHandler.__reputationContext then
        return npcHandler.__reputationContext
    end

    local npcName
    if npcHandler and npcHandler.getParameter then
        npcName = npcHandler:getParameter('name')
    end
    if npcName and npcFactionCache[npcName] then
        npcHandler.__reputationContext = {
            factionId = npcFactionCache[npcName],
            factionName = getFactionInfoById(npcFactionCache[npcName]).name,
            npcName = npcName,
            options = {},
            configOverride = config.npcs[npcName]
        }
        return npcHandler.__reputationContext
    end

    -- fallback to first configured faction for backwards compatibility
    ensureFactionCached()
    for name, info in pairs(config.factions) do
        return ReputationEconomy.setNpcFaction(npcHandler, name, { npcName = npcName })
    end
    return nil
end

local function getNpcShopMetaTable(npcHandler)
    if npcHandler.__reputationShopMeta then
        return npcHandler.__reputationShopMeta
    end
    npcHandler.__reputationShopMeta = { buy = {}, sell = {} }
    return npcHandler.__reputationShopMeta
end

local function buildShopKey(itemId, subType)
    return string.format('%d:%d', itemId, subType or 0)
end

function ReputationEconomy.registerShopItemMetadata(npcHandler, priceType, itemId, subType, meta)
    local bucket = getNpcShopMetaTable(npcHandler)
    bucket[priceType] = bucket[priceType] or {}
    local key = buildShopKey(itemId, subType)
    if bucket[priceType][key] then
        -- merge metadata
        for k, v in pairs(meta) do
            bucket[priceType][key][k] = v
        end
    else
        bucket[priceType][key] = meta
    end
end

function ReputationEconomy.getShopItemMetadata(npcHandler, priceType, itemId, subType)
    local bucket = getNpcShopMetaTable(npcHandler)
    local key = buildShopKey(itemId, subType)
    local meta = bucket[priceType] and bucket[priceType][key] or {}

    local context = ReputationEconomy.getNpcContext(npcHandler)
    if context and context.configOverride and context.configOverride.secretOffers and context.configOverride.secretOffers[priceType] then
        local override = context.configOverride.secretOffers[priceType][itemId]
        if override then
            local newMeta = {}
            for k, v in pairs(meta) do
                newMeta[k] = v
            end
            for k, v in pairs(override) do
                newMeta[k] = v
            end
            meta = newMeta
        end
    end

    return meta
end

local function computeTierModifier(tier, priceType)
    local modifier
    if priceType == 'buy' then
        modifier = tier.buyModifier or 1.0
    else
        modifier = tier.sellModifier or 1.0
    end
    if Economy and Economy.getReputationPriceModifier and tier and tier.name then
        modifier = modifier * Economy.getReputationPriceModifier(tier.name)
    end
    return modifier
end

local function computeGlobalModifier(priceType)
    local modifier = (config.globalModifiers and config.globalModifiers[priceType]) or 1.0
    if Economy and Economy.getMarketFeeModifier then
        modifier = modifier * Economy.getMarketFeeModifier(priceType)
    end
    return modifier
end

local function getFeeRate(factionConfig, npcContext, priceType)
    local base = 0
    if factionConfig and factionConfig.fees then
        if priceType == 'buy' then
            base = factionConfig.fees.npcBuy or 0
        else
            base = factionConfig.fees.npcSell or 0
        end
    end

    if npcContext and npcContext.options and npcContext.options.fees and npcContext.options.fees[priceType] then
        base = npcContext.options.fees[priceType]
    end

    if Economy and Economy.resolveNpcPricing and npcContext and npcContext.__lastPricing and npcContext.__lastPricing[priceType] then
        local override = npcContext.__lastPricing[priceType].feeRate
        if override ~= nil then
            base = override
        end
    end

    return math.max(0, base)
end

local function clamp(value)
    if value < 0 then
        return 0
    end
    return value
end

function ReputationEconomy.calculateNpcPrice(player, npcContext, params)
    if not reputationEnabled and not economyEnabled then
        params = params or {}
        local basePrice = params.basePrice or 0
        local amount = math.max(1, params.amount or 1)
        return {
            unitPrice = basePrice,
            unitGross = basePrice,
            unitFee = 0,
            grossTotal = basePrice * amount,
            netTotal = basePrice * amount,
            totalFee = 0,
            tier = config.tiers[1],
            tierModifier = 1.0,
            economyModifier = 1.0,
            economyState = { label = 'disabled', modifier = 1.0 },
            globalModifier = 1.0,
            feeRate = 0,
            factionConfig = nil,
            dynamic = nil,
            stock = nil
        }
    end

    params = params or {}
    local priceType = params.type or 'buy'
    local amount = math.max(1, params.amount or 1)
    local basePrice = params.basePrice or 0
    local factionId = npcContext and npcContext.factionId or 0
    local factionConfig = ReputationEconomy.getFactionConfig(factionId)

    local repInfo = ReputationEconomy.getPlayerReputation(player, factionId)
    local tier = repInfo.tier
    local tierModifier = computeTierModifier(tier, priceType)

    local economyState = ReputationEconomy.getEconomyState(factionId)
    local economyModifier = economyState.modifier or 1.0

    local globalModifier = computeGlobalModifier(priceType)

    local dynamicPricing
    if Economy and Economy.resolveNpcPricing then
        dynamicPricing = Economy.resolveNpcPricing(npcContext, {
            type = priceType,
            amount = amount,
            basePrice = basePrice,
            itemId = params.itemId,
            metadata = params.metadata,
            player = player
        })
    end

    local dynamicMultiplier = 1.0
    local dynamicFeeOverride
    local priceFloor
    local priceCeiling
    local stockInfo
    if dynamicPricing then
        dynamicMultiplier = dynamicPricing.priceMultiplier or 1.0
        dynamicFeeOverride = dynamicPricing.feeRate
        priceFloor = dynamicPricing.priceFloor
        priceCeiling = dynamicPricing.priceCeiling
        stockInfo = dynamicPricing.stock
        if npcContext then
            npcContext.__lastPricing = npcContext.__lastPricing or {}
            npcContext.__lastPricing[priceType] = dynamicPricing
        end
    elseif npcContext and npcContext.__lastPricing then
        npcContext.__lastPricing[priceType] = nil
    end

    local adjustedUnit = math.floor(basePrice * dynamicMultiplier * tierModifier * economyModifier * globalModifier + 0.5)
    if priceFloor then
        local floorValue = math.floor(basePrice * priceFloor + 0.5)
        if floorValue > adjustedUnit then
            adjustedUnit = floorValue
        end
    end
    if priceCeiling then
        local ceilingValue = math.floor(basePrice * priceCeiling + 0.5)
        if ceilingValue < adjustedUnit then
            adjustedUnit = ceilingValue
        end
    end
    if adjustedUnit < 0 then
        adjustedUnit = 0
    end

    local feeRate = getFeeRate(factionConfig, npcContext, priceType)
    if dynamicFeeOverride ~= nil then
        feeRate = dynamicFeeOverride
    end
    local unitFee = math.floor(adjustedUnit * feeRate + 0.5)
    if unitFee > adjustedUnit then
        unitFee = adjustedUnit
    end

    local unitNet = adjustedUnit
    if priceType == 'sell' then
        unitNet = adjustedUnit - unitFee
    end
    if unitNet < 0 then
        unitNet = 0
    end

    local grossTotal = adjustedUnit * amount
    local totalFee = unitFee * amount
    local netTotal = priceType == 'sell' and (grossTotal - totalFee) or grossTotal
    if netTotal < 0 then
        netTotal = 0
    end

    return {
        unitPrice = unitNet,
        unitGross = adjustedUnit,
        unitFee = unitFee,
        grossTotal = grossTotal,
        netTotal = netTotal,
        totalFee = totalFee,
        tier = tier,
        tierModifier = tierModifier,
        economyModifier = economyModifier,
        economyState = economyState,
        globalModifier = globalModifier,
        feeRate = feeRate,
        factionConfig = factionConfig,
        dynamic = dynamicPricing,
        stock = stockInfo
    }
end

function ReputationEconomy.queueEconomyDelta(factionId, delta, reason, referenceId)
    if not economyEnabled then
        return 0
    end

    if delta == 0 then
        return
    end
    db.asyncQuery(string.format(
        'INSERT INTO `%s` (`faction_id`, `delta`, `reason`, `reference_id`, `created_at`, `processed`) VALUES (%d, %d, %s, %d, %d, 0)',
        TABLE_ECONOMY_LEDGER,
        factionId,
        math.floor(delta),
        escape(reason or 'trade'),
        referenceId or 0,
        now()
    ))
end

function ReputationEconomy.flushEconomyLedger(limit)
    if not economyEnabled then
        return 0
    end

    limit = limit or 50
    local query = string.format('SELECT `id`, `faction_id`, `delta`, `reason`, `reference_id` FROM `%s` WHERE `processed` = 0 ORDER BY `id` ASC LIMIT %d', TABLE_ECONOMY_LEDGER, limit)
    local resultId = db.storeQuery(query)
    if not resultId then
        return 0
    end

    local processedIds = {}
    local processedCount = 0
    local iterations = 0
    repeat
        iterations = iterations + 1
        if iterations > limit * 10 then
            print('[ReputationEconomy] flushEconomyLedger aborted due to iteration cap')
            break
        end
        local rowId = result.getNumber(resultId, 'id')
        local factionId = result.getNumber(resultId, 'faction_id')
        local delta = result.getNumber(resultId, 'delta')
        local reason = result.getString(resultId, 'reason')
        local referenceId = result.getNumber(resultId, 'reference_id')

        local factionConfig = ReputationEconomy.getFactionConfig(factionId)
        ensureEconomyRow(factionId, factionConfig and factionConfig.economy and factionConfig.economy.seedPool or 0)

        db.query(string.format('UPDATE `%s` SET `pool` = `pool` + %d, `updated_at` = %d WHERE `faction_id` = %d', TABLE_ECONOMY, delta, now(), factionId))
        db.asyncQuery(string.format(
            'INSERT INTO `%s` (`faction_id`, `delta`, `reason`, `reference_id`, `created_at`) VALUES (%d, %d, %s, %d, %d)',
            TABLE_ECONOMY_HISTORY,
            factionId,
            delta,
            escape(reason),
            referenceId or 0,
            now()
        ))

        factionEconomyCache[factionId] = nil

        processedIds[#processedIds + 1] = rowId
        processedCount = processedCount + 1
    until not result.next(resultId)
    result.free(resultId)

    if #processedIds > 0 then
        db.query(string.format('UPDATE `%s` SET `processed` = 1, `processed_at` = %d WHERE `id` IN (%s)', TABLE_ECONOMY_LEDGER, now(), table.concat(processedIds, ',')))
    end

    return processedCount
end

function ReputationEconomy.getFeeBreakdownString(priceInfo, priceType)
    local modifierPercent = (priceInfo.tierModifier - 1.0) * 100
    local economyPercent = (priceInfo.economyModifier - 1.0) * 100
    local parts = {
        string.format('Tier %s: %+.2f%%', priceInfo.tier.name, modifierPercent),
        string.format('Economy: %+.2f%% (%s)', economyPercent, priceInfo.economyState.label)
    }
    if priceInfo.globalModifier ~= 1.0 then
        parts[#parts + 1] = string.format('Global: %+.2f%%', (priceInfo.globalModifier - 1.0) * 100)
    end
    if priceInfo.feeRate > 0 then
        parts[#parts + 1] = string.format('Fee: %.2f%%', priceInfo.feeRate * 100)
    end
    return table.concat(parts, ', ')
end

local function applySoftHardCaps(factionConfig, currentValue, delta)
    if delta == 0 then
        return 0
    end
    factionConfig = factionConfig or {}
    local repConfig = factionConfig.reputation or {}
    local hardCap = repConfig.hardCap or 10000
    local hardMin = repConfig.hardMin or (-hardCap)
    local softCap = repConfig.softCap or hardCap
    local softDiminish = repConfig.softDiminishFactor or 1.0

    if delta > 0 and currentValue >= softCap and softDiminish > 0 and softDiminish < 1 then
        delta = math.floor(delta * softDiminish)
    end

    local tentative = currentValue + delta
    if tentative > hardCap then
        delta = hardCap - currentValue
    elseif tentative < hardMin then
        delta = hardMin - currentValue
    end

    return delta
end

function ReputationEconomy.addReputation(player, factionId, delta, source, extra)
    if not reputationEnabled then
        return 0
    end

    if delta == 0 then
        return 0
    end

    local playerId = type(player) == 'number' and player or player:getGuid()
    ensurePlayerEntry(playerId, factionId)

    local repInfo = ReputationEconomy.getPlayerReputation(playerId, factionId, true)
    local currentValue = repInfo.value
    local factionConfig = ReputationEconomy.getFactionConfig(factionId)

    delta = applySoftHardCaps(factionConfig, currentValue, delta)
    if delta == 0 then
        return 0
    end

    db.query(string.format('UPDATE `%s` SET `reputation` = `reputation` + %d, `last_activity` = %d WHERE `player_id` = %d AND `faction_id` = %d', TABLE_PLAYER_REP, delta, now(), playerId, factionId))

    db.asyncQuery(string.format(
        'INSERT INTO `%s` (`player_id`, `faction_id`, `delta`, `source`, `context`, `created_at`) VALUES (%d, %d, %d, %s, %s, %d)',
        TABLE_PLAYER_REP_LOG,
        playerId,
        factionId,
        delta,
        escape(source or 'unknown'),
        escape(extra and jsonEncode(extra) or '{}'),
        now()
    ))

    playerTierCache[playerId] = playerTierCache[playerId] or {}
    playerTierCache[playerId][factionId] = nil -- force refresh next lookup

    return delta
end

function ReputationEconomy.addTradeReputation(player, factionId, amount, priceType, priceInfo)
    if not reputationEnabled then
        return 0
    end

    local factionConfig = ReputationEconomy.getFactionConfig(factionId)
    if not factionConfig then
        return 0
    end

    local repConfig = factionConfig.reputation or {}
    local factor = 0
    if priceType == 'buy' then
        factor = repConfig.tradeBuyFactor or 0
    else
        factor = repConfig.tradeSellFactor or 0
    end

    if factor <= 0 then
        return 0
    end

    local delta = math.floor(amount * factor)
    if delta == 0 then
        return 0
    end

    return ReputationEconomy.addReputation(player, factionId, delta, priceType == 'buy' and 'npc_buy' or 'npc_sell', {
        total = amount,
        tier = priceInfo and priceInfo.tier and priceInfo.tier.name or '',
        economy = priceInfo and priceInfo.economyState and priceInfo.economyState.label or ''
    })
end

function ReputationEconomy.onNpcTrade(player, npcContext, trade)
    local factionId = npcContext.factionId
    if trade.totalFee and trade.totalFee > 0 then
        ReputationEconomy.queueEconomyDelta(factionId, trade.totalFee, trade.type .. ':' .. trade.itemId, player:getGuid())
    end

    if trade.totalNet and trade.totalNet > 0 then
        ReputationEconomy.addTradeReputation(player, factionId, trade.totalNet, trade.type, trade.priceInfo)
    end

    if Economy and Economy.registerNpcTrade then
        Economy.registerNpcTrade(player, npcContext, trade)
    end
end

function ReputationEconomy.getNpcFactionByName(npcName)
    if npcFactionCache[npcName] then
        return npcFactionCache[npcName]
    end
    local resultId = db.storeQuery(string.format('SELECT `faction_id` FROM `%s` WHERE `npc_name` = %s LIMIT 1', TABLE_NPC_FACTIONS, escape(npcName)))
    if resultId then
        local factionId = result.getNumber(resultId, 'faction_id')
        result.free(resultId)
        npcFactionCache[npcName] = factionId
        return factionId
    end
    return nil
end

local function tierSatisfies(tierName, requiredName)
    if not requiredName then
        return true
    end
    return getTierIndex(tierName) >= getTierIndex(requiredName)
end

function ReputationEconomy.hasTier(player, factionId, requiredTier)
    if not requiredTier then
        return true
    end
    local info = ReputationEconomy.getPlayerReputation(player, factionId)
    return tierSatisfies(info.tier.name, requiredTier)
end

function ReputationEconomy.filterShopItems(player, npcHandler, npcContext)
    local filtered = {}
    local seenIds = {}
    local repInfo = ReputationEconomy.getPlayerReputation(player, npcContext.factionId)
    local tierIndex = getTierIndex(repInfo.tier.name)
    local economyState = ReputationEconomy.getEconomyState(npcContext.factionId)

    for _, shopItem in ipairs(npcHandler.shopItems) do
        local include = false
        local metadata
        local buyPrice = 0
        local sellPrice = 0

        if shopItem.buy and shopItem.buy > 0 then
            metadata = ReputationEconomy.getShopItemMetadata(npcHandler, 'buy', shopItem.id, shopItem.subType)
            if Economy and Economy.mergeNpcMetadata then
                metadata = Economy.mergeNpcMetadata(npcContext, 'buy', shopItem, metadata)
            end
            include = true
            if metadata and metadata.minTier and tierIndex < getTierIndex(metadata.minTier) then
                include = false
            end
            if include and metadata and metadata.economyMin and economyState.pool < metadata.economyMin then
                include = false
            end
            if include and metadata and metadata.economyMax and economyState.pool > metadata.economyMax then
                include = false
            end
            if include and Economy and not Economy.canNpcProvide(npcContext, 'buy', shopItem.id, 1) then
                include = false
            end
            if include then
                local priceInfo = ReputationEconomy.calculateNpcPrice(player, npcContext, {
                    type = 'buy',
                    basePrice = shopItem.buy,
                    amount = 1,
                    itemId = shopItem.id,
                    metadata = metadata
                })
                if priceInfo and priceInfo.stock and priceInfo.stock.current and priceInfo.stock.current <= 0 then
                    include = false
                else
                    buyPrice = priceInfo.unitPrice
                end
            end
        end

        if include or (shopItem.sell and shopItem.sell > 0) then
            local sellMeta = ReputationEconomy.getShopItemMetadata(npcHandler, 'sell', shopItem.id, shopItem.subType)
            if Economy and Economy.mergeNpcMetadata then
                sellMeta = Economy.mergeNpcMetadata(npcContext, 'sell', shopItem, sellMeta)
            end
            local allowSell = shopItem.sell and shopItem.sell > 0
            if allowSell then
                if sellMeta and sellMeta.minTier and tierIndex < getTierIndex(sellMeta.minTier) then
                    allowSell = false
                end
                if allowSell and sellMeta and sellMeta.economyMin and economyState.pool < sellMeta.economyMin then
                    allowSell = false
                end
            end
            if allowSell and Economy and not Economy.canNpcProvide(npcContext, 'sell', shopItem.id, 1) then
                allowSell = false
            end
            if allowSell then
                local priceInfo = ReputationEconomy.calculateNpcPrice(player, npcContext, {
                    type = 'sell',
                    basePrice = shopItem.sell,
                    amount = 1,
                    itemId = shopItem.id,
                    metadata = sellMeta
                })
                sellPrice = priceInfo.unitPrice
                include = true
            end
        end

        if include then
            local key = buildShopKey(shopItem.id, shopItem.subType)
            if not seenIds[key] then
                seenIds[key] = true
                filtered[#filtered + 1] = {
                    id = shopItem.id,
                    buy = buyPrice,
                    sell = sellPrice,
                    subType = shopItem.subType,
                    name = shopItem.name
                }
            end
        end
    end

    return filtered
end

function ReputationEconomy.buildShopHint(player, npcContext)
    local repInfo = ReputationEconomy.getPlayerReputation(player, npcContext.factionId)
    local tier = repInfo.tier
    local economyState = ReputationEconomy.getEconomyState(npcContext.factionId)
    local buyPct = (tier.buyModifier - 1.0) * 100
    local sellPct = (tier.sellModifier - 1.0) * 100
    local economyPct = (economyState.modifier - 1.0) * 100
    return string.format('Your tier: %s (%+.1f%% sell / %+.1f%% buy). Faction economy: %s (%+.1f%% prices).', tier.name, sellPct, buyPct, economyState.label, economyPct)
end

function ReputationEconomy.sendShopHint(player, npcContext)
    local hint = ReputationEconomy.buildShopHint(player, npcContext)
    player:sendTextMessage(MESSAGE_EVENT_ADVANCE, hint)
end

function ReputationEconomy.captureMarketFees(limit)
    if not economyEnabled then
        return 0
    end

    limit = limit or 100
    local cursorQuery = string.format('SELECT `id`, `last_history_id` FROM `%s` LIMIT 1', TABLE_MARKET_CURSOR)
    local cursorId = 0
    local lastId = 0
    local resultId = db.storeQuery(cursorQuery)
    if resultId then
        cursorId = result.getNumber(resultId, 'id')
        lastId = result.getNumber(resultId, 'last_history_id')
        result.free(resultId)
    end

    local marketQuery = string.format('SELECT `id`, `player_id`, `itemtype`, `amount`, `price`, `sale` FROM `market_history` WHERE `state` = %d AND `id` > %d ORDER BY `id` ASC LIMIT %d', OFFERSTATE_ACCEPTED, lastId, limit)
    local historyId = db.storeQuery(marketQuery)
    if not historyId then
        return 0
    end

    local processed = 0
    local finalId = lastId
    local iterations = 0
    repeat
        iterations = iterations + 1
        if iterations > limit * 10 then
            print('[ReputationEconomy] captureMarketFees aborted due to iteration cap')
            break
        end
        local rowId = result.getNumber(historyId, 'id')
        local playerId = result.getNumber(historyId, 'player_id')
        local sale = result.getNumber(historyId, 'sale')
        local price = result.getNumber(historyId, 'price')
        local amount = result.getNumber(historyId, 'amount')

        local total = price * amount
        local factionId = config.factions['Central Exchange'] and config.factions['Central Exchange'].id or 0
        if factionId > 0 then
            local factionConfig = ReputationEconomy.getFactionConfig(factionId)
            local feeRate = factionConfig and factionConfig.fees and factionConfig.fees.market or 0
            local fee = math.floor(total * feeRate)
            if fee > 0 then
                ReputationEconomy.queueEconomyDelta(factionId, fee, sale == 1 and 'market_sale' or 'market_buy', playerId)
            end
        end

        if Economy and Economy.registerMarketActivity then
            Economy.registerMarketActivity(total)
        end

        finalId = rowId
        processed = processed + 1
    until not result.next(historyId)
    result.free(historyId)

    if cursorId == 0 then
        db.query(string.format('INSERT INTO `%s` (`last_history_id`, `updated_at`) VALUES (%d, %d)', TABLE_MARKET_CURSOR, finalId, now()))
    else
        db.query(string.format('UPDATE `%s` SET `last_history_id` = %d, `updated_at` = %d WHERE `id` = %d', TABLE_MARKET_CURSOR, finalId, now(), cursorId))
    end

    return processed
end

function ReputationEconomy.applyDecay()
    if not reputationEnabled then
        return 0
    end
    local processed = 0
    for name, info in pairs(config.factions) do
        local factionId = info.id
        local repConfig = info.reputation or {}
        local decay = repConfig.decayPerWeek or 0
        if decay > 0 then
            local query = string.format('SELECT `player_id`, `reputation`, `last_decay` FROM `%s` WHERE `faction_id` = %d', TABLE_PLAYER_REP, factionId)
            local resultId = db.storeQuery(query)
            if resultId then
                local iterations = 0
                repeat
                    iterations = iterations + 1
                    if iterations > 10000 then
                        print(string.format('[ReputationEconomy] applyDecay aborted for faction %s due to iteration cap', tostring(name)))
                        break
                    end
                    local playerId = result.getNumber(resultId, 'player_id')
                    local reputationValue = result.getNumber(resultId, 'reputation')
                    local lastDecay = result.getNumber(resultId, 'last_decay')
                    if now() - lastDecay >= 7 * 24 * 60 * 60 and reputationValue > 0 then
                        local newRep = reputationValue - math.min(decay, reputationValue)
                        db.query(string.format('UPDATE `%s` SET `reputation` = %d, `last_decay` = %d WHERE `player_id` = %d AND `faction_id` = %d', TABLE_PLAYER_REP, newRep, now(), playerId, factionId))
                        db.asyncQuery(string.format('INSERT INTO `%s` (`player_id`, `faction_id`, `delta`, `source`, `context`, `created_at`) VALUES (%d, %d, %d, %s, %s, %d)', TABLE_PLAYER_REP_LOG, playerId, factionId, newRep - reputationValue, escape('decay'), escape('{}'), now()))
                        if playerTierCache[playerId] then
                            playerTierCache[playerId][factionId] = nil
                        end
                        processed = processed + 1
                    end
                until not result.next(resultId)
                result.free(resultId)
            end
        end
    end
    return processed
end

function ReputationEconomy.describeStanding(player, factionId)
    local info = ReputationEconomy.getPlayerReputation(player, factionId)
    local points = info.value
    local tier = info.tier
    local toNext = ReputationEconomy.pointsToNextTier(points)
    local nextStr = toNext and toNext > 0 and string.format(', %d to %s', toNext, config.tiers[ReputationEconomy.getTierIndex(tier.name) + 1] and config.tiers[ReputationEconomy.getTierIndex(tier.name) + 1].name or tier.name) or ''
    return string.format('%s: %d (%s%s)', getFactionInfoById(factionId).name, points, tier.name, nextStr)
end

function ReputationEconomy.onStartup()
    ensureFactionCached()
    for _, info in pairs(factionCache.byId) do
        ensureEconomyRow(info.config.id, info.config.economy and info.config.economy.seedPool or 0)
    end
end

function ReputationEconomy.getAllFactions()
    ensureFactionCached()
    local list = {}
    for id, info in pairs(factionCache.byId) do
        list[#list + 1] = { id = id, name = info.name, config = info.config }
    end
    table.sort(list, function(a, b) return a.id < b.id end)
    return list
end

trace.checkpoint('rep_eco:nx_reputation.lua:end')

return ReputationEconomy
