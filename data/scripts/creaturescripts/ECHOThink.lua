if not ECHO_CONFIG then
    dofile('data/lib/echo_config.lua')
end
if not ECHO_UTILS then
    dofile('data/lib/echo_utils.lua')
end

ECHO_STATE = ECHO_STATE or {}
ECHO_PERSIST_BUFFER = ECHO_PERSIST_BUFFER or {}

local DAMAGE_KEYS = ECHO_UTILS.DAMAGE_KEYS
local THINKER = ECHO_CONFIG.thinker
local ADAPT = ECHO_CONFIG.adaptation

local function clamp(value, minValue, maxValue)
    if value < minValue then
        return minValue
    end
    if value > maxValue then
        return maxValue
    end
    return value
end

local function getMonsterExperience(monster)
    local name = monster:getName()
    local override = ECHO_CONFIG.expOverrides[name]
    if override then
        return override
    end
    local mType = monster:getType()
    if mType and mType:experience() then
        return mType:experience()
    end
    if mType and mType:maxHealth() then
        local derived = math.floor((mType:maxHealth() or 0) * 0.3)
        if derived > 0 then
            return derived
        end
    end
    return ECHO_CONFIG.fallbackExperience or 1200
end

local function initResistTable()
    local tableResist = {}
    for _, key in ipairs(DAMAGE_KEYS) do
        tableResist[key] = 0
    end
    return tableResist
end

local function initState(monster)
    local cid = monster:getId()
    local state = ECHO_STATE[cid]
    if state then
        return state
    end
    local baseExp = getMonsterExperience(monster)
    local spawnHash = ECHO_UTILS.getSpawnHash(monster)
    state = {
        id = cid,
        name = monster:getName(),
        spawnHash = spawnHash,
        baseExperience = baseExp,
        learnedExperience = 0,
        phase = 1,
        abilityCooldowns = {},
        nextActionTime = 0,
        nextHeavyTime = 0,
        lastThink = 0,
        lastTiltUpdate = ECHO_UTILS.millis(),
        recentBuckets = ECHO_UTILS.initBuckets(),
        sessionTotals = ECHO_UTILS.resetTotals(),
        resistTilt = initResistTable(),
        resistExpiry = {},
        spacingBias = 0,
        spacingSamples = { melee = 0, ranged = 0, lastMs = nil },
        recentAttackers = {},
        damageFocus = {},
        fights = 0,
        persistentLoaded = false,
        persistentMemory = nil,
        crowdCount = 0,
    }
    ECHO_STATE[cid] = state

    if ECHO_PERSIST and not state.persistentLoaded then
        local row = ECHO_UTILS.safeDbFetchMemory(state.name, spawnHash)
        if row then
            state.persistentMemory = row
            state.learnedExperience = math.floor((row.fights or 0) * (ECHO_CONFIG.experiencePerFight or 0))
            local total = row.total_damage_taken or 0
            if total > 0 then
                for _, dtype in ipairs(DAMAGE_KEYS) do
                    local field = 'dmg_taken_' .. dtype
                    if row[field] and row[field] > 0 then
                        local ratio = row[field] / total
                        local persistentTilt = clamp(ratio * (ADAPT.persistentTiltScalar or 0.2), THINKER.tiltMin, THINKER.tiltMax)
                        state.resistTilt[dtype] = clamp(state.resistTilt[dtype] + persistentTilt, THINKER.tiltMin, THINKER.tiltMax)
                    end
                end
            end
            if (row.fights or 0) > 0 then
                local persistentSpacing = clamp((row.fights or 0) * (ADAPT.persistentSpacingBias or 0.1), -0.5, 0.5)
                state.spacingBias = clamp(state.spacingBias + persistentSpacing, -0.75, 0.75)
            end
        end
        state.persistentLoaded = true
    end

    state.phase = ECHO_UTILS.phaseForExp(state.baseExperience + state.learnedExperience, ECHO_CONFIG)
    return state
end

local function computePhase(state)
    local exp = state.baseExperience + state.learnedExperience
    state.phase = ECHO_UTILS.phaseForExp(exp, ECHO_CONFIG)
    return state.phase
end

local function decayResist(state, now)
    local decayMs = THINKER.tiltDecayMs or 20000
    local delta = now - (state.lastTiltUpdate or now)
    if delta <= 0 then
        return
    end
    for dtype, value in pairs(state.resistTilt) do
        local expiry = state.resistExpiry[dtype]
        if expiry and now >= expiry then
            state.resistExpiry[dtype] = nil
        end
        if not expiry then
            local decay = ADAPT.tiltDecayRate or 0.15
            local scaled = value * math.max(0, 1 - (decay * (delta / decayMs)))
            state.resistTilt[dtype] = clamp(scaled, THINKER.tiltMin, THINKER.tiltMax)
        end
    end
    state.lastTiltUpdate = now
end

local function normalizeBuckets(state, now)
    local total = 0
    local halfLife = THINKER.damageMemoryHalfLifeMs or 15000
    for dtype, entry in pairs(state.recentBuckets) do
        local delta = now - (entry.last or now)
        entry.value = ECHO_UTILS.applyDecay(entry.value or 0, delta, halfLife)
        entry.last = now
        total = total + (entry.value or 0)
    end
    state.recentTotal = total
    return total
end

local function adaptDefenses(state, total)
    if total <= 0 then
        return
    end
    for dtype, entry in pairs(state.recentBuckets) do
        local portion = (entry.value or 0) / total
        local desired = portion * (ADAPT.damageTiltScalar or 0.4)
        local current = state.resistTilt[dtype] or 0
        local updated = current + (desired - current) * (ADAPT.tiltLearnRate or 0.35)
        state.resistTilt[dtype] = clamp(updated, THINKER.tiltMin, THINKER.tiltMax)
    end
end

local function updateSpacing(state, distance, now)
    local samples = state.spacingSamples
    local lastMs = samples.lastMs or now
    local delta = now - lastMs
    if delta > 0 then
        if distance <= (THINKER.meleeDistance or 2) then
            samples.melee = samples.melee + delta
        else
            samples.ranged = samples.ranged + delta
        end
    end
    local total = samples.melee + samples.ranged
    if total > 0 then
        local tendency = (samples.ranged - samples.melee) / total
        state.spacingBias = clamp(state.spacingBias + tendency * (ADAPT.spacingShiftRate or 0.2), -0.9, 0.9)
        samples.melee = samples.melee * 0.65
        samples.ranged = samples.ranged * 0.65
    else
        state.spacingBias = state.spacingBias * (1 - (ADAPT.spacingReversionRate or 0.05))
    end
    samples.lastMs = now
end

local function updateAttackers(state, monster, now)
    local damageMap = monster:getDamageMap() or {}
    local attackers = state.recentAttackers
    local count = 0
    for cid, info in pairs(damageMap) do
        local player = Creature(cid)
        if player and player:isPlayer() then
            local entry = attackers[cid] or { total = 0, delta = 0, last = now }
            local totalDamage = info.total or 0
            entry.delta = math.max(0, totalDamage - (entry.total or 0))
            entry.total = totalDamage
            entry.ticks = info.ticks or entry.ticks or 0
            entry.last = now
            attackers[cid] = entry
            count = count + 1
        end
    end
    for cid, entry in pairs(attackers) do
        if not damageMap[cid] then
            local age = now - (entry.last or now)
            if age > (THINKER.damageMemoryHalfLifeMs or 15000) then
                attackers[cid] = nil
            end
        end
    end
    state.crowdCount = count
end

local function rebuildDamageFocus(state)
    local total = 0
    for _, info in pairs(state.recentAttackers) do
        total = total + (info.delta or 0)
    end
    if total <= 0 then
        state.damageFocus = {}
        return
    end
    local focus = {}
    for cid, info in pairs(state.recentAttackers) do
        focus[cid] = (info.delta or 0) / total
    end
    state.damageFocus = focus
end

local function selectPriorityTarget(monster, state, phaseData)
    local current = monster:getTarget()
    local candidates = monster:getTargetList() or {}
    if #candidates == 0 then
        return current
    end
    local best = current
    local bestScore = -1
    local variance = ADAPT.randomTargetVariance or 0.05
    local focusScalar = ADAPT.targetFocusScalar or 0.65

    for _, target in ipairs(candidates) do
        if target and target:isPlayer() then
            local score = 0.1
            local cid = target:getId()
            local attackerData = state.recentAttackers[cid]
            if attackerData then
                score = score + (attackerData.delta or attackerData.total or 0)
            end
            if state.damageFocus[cid] then
                score = score + state.damageFocus[cid] * focusScalar
            end
            if target == current then
                score = score + 0.1
            end
            if variance > 0 then
                score = score * (1 - variance + (math.random() * variance * 2))
            end
            if score > bestScore then
                bestScore = score
                best = target
            end
        end
    end

    local crowdCfg = phaseData and phaseData.crowd or {}
    local swapChance = crowdCfg.swapBias or 0
    if state.crowdCount >= (crowdCfg.minAttackers or math.huge) then
        swapChance = swapChance + (ADAPT.crowdSwapBonus or 0.2)
    end
    if best and best ~= current then
        if not current or math.random() < swapChance then
            monster:selectTarget(best)
            return best
        end
    end
    if not current and best then
        monster:selectTarget(best)
        return best
    end
    return current
end

local function buildAbilityOptions(state, phaseData, totalRecent, now)
    local options = {}
    local spacing = state.spacingBias
    local crowdCfg = phaseData.crowd or {}
    local crowdCount = state.crowdCount or 0

    for _, entry in ipairs(phaseData.abilityPool or {}) do
        local ability = ECHO_CONFIG.abilities[entry.ability]
        if ability and ability.cooldown then
            local cd = state.abilityCooldowns[entry.ability] or 0
            if now >= cd then
                local weight = entry.weight or 0
                local tags = ability.tags or {}
                if tags.ranged then
                    weight = weight * (1 + spacing)
                elseif tags.melee then
                    weight = weight * (1 - spacing)
                end
                if tags.crowd and crowdCount >= (crowdCfg.minAttackers or math.huge) then
                    local boost = ADAPT.crowdAbilityBoost or 0.35
                    weight = weight * (1 + boost * math.max(1, crowdCount - (crowdCfg.minAttackers or crowdCount)))
                end
                if tags.defensive then
                    weight = weight * (1 + math.min(0.4, totalRecent / 600))
                end
                if ability.counterTypes and totalRecent > 0 then
                    local counter = 0
                    for dtype in pairs(ability.counterTypes) do
                        local bucket = state.recentBuckets[dtype]
                        if bucket then
                            counter = counter + (bucket.value or 0)
                        end
                    end
                    counter = counter / totalRecent
                    weight = weight * (1 + counter * (ADAPT.counterWeightScalar or 0.45))
                end
                if weight > 0 then
                    table.insert(options, { ability = ability, key = entry.ability, weight = weight })
                end
            end
        end
    end
    return options
end

local function setCooldowns(state, abilityKey, ability, phaseData, now)
    local cdMin, cdMax = ability.cooldown[1], ability.cooldown[2]
    state.abilityCooldowns[abilityKey] = now + math.random(cdMin, cdMax)
    local gcdMin, gcdMax = 1500, 2200
    if phaseData and phaseData.gcd then
        gcdMin = phaseData.gcd[1]
        gcdMax = phaseData.gcd[2]
    end
    state.nextActionTime = now + math.random(gcdMin, gcdMax)
end

local function executeAbility(monster, state, target, abilityKey, ability, phaseData, now)
    local success = false
    if ability.type == 'target' then
        local chosen = target
        if not chosen then
            chosen = monster:getTarget()
        end
        if chosen then
            success = doTargetCombat(monster, chosen, ability.combatType, ability.minDamage, ability.maxDamage, ability.effect or CONST_ME_NONE)
        end
    elseif ability.type == 'area' then
        local center = target and target:getPosition() or monster:getPosition()
        if ability.area then
            success = doAreaCombatHealth(monster, ability.combatType, center, ability.area, ability.minDamage, ability.maxDamage, ability.effect or CONST_ME_NONE)
        else
            success = doTargetCombat(monster, target or monster, ability.combatType, ability.minDamage, ability.maxDamage, ability.effect or CONST_ME_NONE)
        end
    elseif ability.type == 'self' then
        success = true
        if ability.resistBoost then
            for dtype, amount in pairs(ability.resistBoost) do
                state.resistTilt[dtype] = clamp((state.resistTilt[dtype] or 0) + amount, THINKER.tiltMin, THINKER.tiltMax)
                state.resistExpiry[dtype] = now + (ability.shieldDurationMs or 5000)
            end
        end
    end
    if success then
        setCooldowns(state, abilityKey, ability, phaseData, now)
    else
        state.nextActionTime = now + math.random(1400, 2000)
    end
    return success
end

local function attemptAbility(monster, state, target, phaseData, now, totalRecent)
    if now < (state.nextActionTime or 0) then
        return
    end
    local options = buildAbilityOptions(state, phaseData, totalRecent, now)
    if #options == 0 then
        state.nextActionTime = now + math.random(1600, 2200)
        return
    end
    local choice = ECHO_UTILS.weightedChoice(options)
    if not choice then
        state.nextActionTime = now + math.random(1500, 2100)
        return
    end
    executeAbility(monster, state, target, choice.key, choice.ability, phaseData, now)
end

local function queuePersistence(state)
    if not ECHO_PERSIST then
        return
    end
    local key = state.name .. '|' .. state.spawnHash
    local entry = ECHO_PERSIST_BUFFER[key]
    if not entry then
        entry = {
            monster_type = state.name,
            spawn_hash = state.spawnHash,
            fights = 0,
            total_damage_taken = 0,
            dmg_taken_physical = 0,
            dmg_taken_fire = 0,
            dmg_taken_ice = 0,
            dmg_taken_earth = 0,
            dmg_taken_energy = 0,
            dmg_taken_holy = 0,
            dmg_taken_death = 0,
        }
        ECHO_PERSIST_BUFFER[key] = entry
    end
    entry.fights = entry.fights + 1
    entry.total_damage_taken = entry.total_damage_taken + math.floor(state.sessionTotals.total or 0)
    for _, dtype in ipairs(DAMAGE_KEYS) do
        local field = 'dmg_taken_' .. dtype
        entry[field] = (entry[field] or 0) + math.floor(state.sessionTotals[dtype] or 0)
    end
    entry.dirty = true
end

local function applyResist(state, damage, bucket)
    if damage <= 0 or not bucket then
        return damage
    end
    local tilt = clamp(state.resistTilt[bucket] or 0, THINKER.tiltMin, THINKER.tiltMax)
    if tilt > 0 then
        damage = math.max(0, math.floor(damage * (1 - tilt)))
    elseif tilt < 0 then
        damage = math.max(0, math.floor(damage * (1 - tilt)))
    end
    return damage
end

local function updateSessionTotals(state, bucket, amount)
    state.sessionTotals[bucket] = (state.sessionTotals[bucket] or 0) + amount
    state.sessionTotals.total = (state.sessionTotals.total or 0) + amount
end

local function onThink(monster)
    if not ECHO_ENABLED or not monster or not monster:isMonster() then
        return true
    end
    local state = initState(monster)
    local now = ECHO_UTILS.millis()
    if now - (state.lastThink or 0) < (THINKER.minIntervalMs or 400) then
        return true
    end
    state.lastThink = now

    updateAttackers(state, monster, now)
    computePhase(state)
    local phaseData = ECHO_CONFIG.phases[state.phase] or ECHO_CONFIG.phases[1]

    local target = monster:getTarget()
    if target then
        local distance = monster:getPosition():getDistance(target:getPosition())
        updateSpacing(state, distance, now)
    end

    decayResist(state, now)
    local totalRecent = normalizeBuckets(state, now)
    adaptDefenses(state, totalRecent)

    if now >= (state.nextHeavyTime or 0) then
        rebuildDamageFocus(state)
        state.spacingBias = state.spacingBias * (1 - (ADAPT.spacingReversionRate or 0.05))
        state.nextHeavyTime = now + (THINKER.heavyIntervalMs or 1200)
    end

    target = selectPriorityTarget(monster, state, phaseData) or target
    attemptAbility(monster, state, target, phaseData, now, math.max(totalRecent, 0.01))
    return true
end

local function onHealthChange(monster, attacker, primaryDamage, primaryType, secondaryDamage, secondaryType, origin)
    if not ECHO_ENABLED or not monster or not monster:isMonster() then
        return primaryDamage, primaryType, secondaryDamage, secondaryType
    end
    local state = initState(monster)
    local now = ECHO_UTILS.millis()
    local halfLife = THINKER.damageMemoryHalfLifeMs or 15000

    if primaryDamage and primaryDamage > 0 then
        local bucket = ECHO_UTILS.bucketDamageType(primaryType)
        if bucket then
            ECHO_UTILS.accumulateBucket(state.recentBuckets, bucket, primaryDamage, halfLife)
            primaryDamage = applyResist(state, primaryDamage, bucket)
            updateSessionTotals(state, bucket, primaryDamage)
        end
    end
    if secondaryDamage and secondaryDamage > 0 then
        local bucket = ECHO_UTILS.bucketDamageType(secondaryType)
        if bucket then
            ECHO_UTILS.accumulateBucket(state.recentBuckets, bucket, secondaryDamage, halfLife)
            secondaryDamage = applyResist(state, secondaryDamage, bucket)
            updateSessionTotals(state, bucket, secondaryDamage)
        end
    end

    return primaryDamage, primaryType, secondaryDamage, secondaryType
end

local function onDeath(monster, corpse, killer, mostDamageKiller, unjustified, mostDamageUnjustified)
    if not monster or not monster:isMonster() then
        return true
    end
    local state = ECHO_STATE[monster:getId()]
    if not state then
        return true
    end
    state.fights = (state.fights or 0) + 1
    state.learnedExperience = state.learnedExperience + (ECHO_CONFIG.experiencePerFight or 0)
    queuePersistence(state)
    ECHO_STATE[monster:getId()] = nil
    return true
end

local function registerEvent(name, eventType, registrar)
    local event = CreatureEvent(name)
    event:type(eventType)
    registrar(event)
    event:register()
end

registerEvent('ECHOThink', 'think', function(event)
    event:onThink(onThink)
end)

registerEvent('ECHOThinkHealth', 'healthchange', function(event)
    event:onHealthChange(onHealthChange)
end)

registerEvent('ECHOThinkDeath', 'death', function(event)
    event:onDeath(onDeath)
end)
