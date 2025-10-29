-- Utility helpers for the E.C.H.O. system.

if not ECHO_CONFIG then
    dofile('data/lib/echo_config.lua')
end

ECHO_UTILS = ECHO_UTILS or {}

local DAMAGE_KEYS = {"physical", "fire", "ice", "earth", "energy", "holy", "death"}
ECHO_UTILS.DAMAGE_KEYS = DAMAGE_KEYS

local combatMap = {
    [COMBAT_PHYSICALDAMAGE] = "physical",
    [COMBAT_FIREDAMAGE] = "fire",
    [COMBAT_ICEDAMAGE] = "ice",
    [COMBAT_EARTHDAMAGE] = "earth",
    [COMBAT_ENERGYDAMAGE] = "energy",
    [COMBAT_HOLYDAMAGE] = "holy",
    [COMBAT_DEATHDAMAGE] = "death",
}

function ECHO_UTILS.millis()
    return math.floor(os.clock() * 1000)
end

function ECHO_UTILS.getSpawnHash(monster)
    if not monster or not monster:isMonster() then
        return ""
    end
    local spawn = monster:getSpawnPosition()
    local pos = spawn or monster:getPosition()
    if not pos then
        return monster:getName()
    end
    return string.format("%s:%d:%d:%d", monster:getName(), pos.x, pos.y, pos.z)
end

function ECHO_UTILS.bucketDamageType(combatType)
    return combatMap[combatType]
end

local function safeQuery(query)
    if not db or not query or query == "" then
        return false
    end
    return db.query(query)
end

function ECHO_UTILS.safeDbUpsert(row)
    if not ECHO_PERSIST or not row or not row.monster_type or not row.spawn_hash then
        return false
    end
    local success, result = pcall(function()
        local monsterType = db.escapeString(row.monster_type)
        local spawnHash = db.escapeString(row.spawn_hash)
        local values = string.format(
            "(%s, %s, %d, %d, %d, %d, %d, %d, %d, %d, %d)",
            monsterType,
            spawnHash,
            row.fights or 0,
            row.total_damage_taken or 0,
            row.dmg_taken_physical or 0,
            row.dmg_taken_fire or 0,
            row.dmg_taken_ice or 0,
            row.dmg_taken_earth or 0,
            row.dmg_taken_energy or 0,
            row.dmg_taken_holy or 0,
            row.dmg_taken_death or 0
        )
        local insert = "INSERT INTO echo_memory (monster_type, spawn_hash, fights, total_damage_taken, " ..
            "dmg_taken_physical, dmg_taken_fire, dmg_taken_ice, dmg_taken_earth, dmg_taken_energy, dmg_taken_holy, dmg_taken_death) VALUES "
        local ondup = " ON DUPLICATE KEY UPDATE fights = fights + VALUES(fights), total_damage_taken = total_damage_taken + VALUES(total_damage_taken), " ..
            "dmg_taken_physical = dmg_taken_physical + VALUES(dmg_taken_physical), " ..
            "dmg_taken_fire = dmg_taken_fire + VALUES(dmg_taken_fire), " ..
            "dmg_taken_ice = dmg_taken_ice + VALUES(dmg_taken_ice), " ..
            "dmg_taken_earth = dmg_taken_earth + VALUES(dmg_taken_earth), " ..
            "dmg_taken_energy = dmg_taken_energy + VALUES(dmg_taken_energy), " ..
            "dmg_taken_holy = dmg_taken_holy + VALUES(dmg_taken_holy), " ..
            "dmg_taken_death = dmg_taken_death + VALUES(dmg_taken_death)"
        return safeQuery(insert .. values .. ondup)
    end)
    if not success then
        return false
    end
    return result and true or false
end

function ECHO_UTILS.safeDbFetchMemory(monsterType, spawnHash)
    if not ECHO_PERSIST or not db or not monsterType or not spawnHash then
        return nil
    end
    local query = string.format(
        "SELECT fights, total_damage_taken, dmg_taken_physical, dmg_taken_fire, dmg_taken_ice, dmg_taken_earth, " ..
        "dmg_taken_energy, dmg_taken_holy, dmg_taken_death FROM echo_memory WHERE monster_type = %s AND spawn_hash = %s",
        db.escapeString(monsterType),
        db.escapeString(spawnHash)
    )
    local ok, resultId = pcall(function()
        return db.storeQuery(query)
    end)
    if not ok or not resultId then
        return nil
    end
    local row = {
        fights = result.getNumber(resultId, "fights") or 0,
        total_damage_taken = result.getNumber(resultId, "total_damage_taken") or 0,
        dmg_taken_physical = result.getNumber(resultId, "dmg_taken_physical") or 0,
        dmg_taken_fire = result.getNumber(resultId, "dmg_taken_fire") or 0,
        dmg_taken_ice = result.getNumber(resultId, "dmg_taken_ice") or 0,
        dmg_taken_earth = result.getNumber(resultId, "dmg_taken_earth") or 0,
        dmg_taken_energy = result.getNumber(resultId, "dmg_taken_energy") or 0,
        dmg_taken_holy = result.getNumber(resultId, "dmg_taken_holy") or 0,
        dmg_taken_death = result.getNumber(resultId, "dmg_taken_death") or 0,
    }
    result.free(resultId)
    return row
end

function ECHO_UTILS.phaseForExp(exp, cfg)
    cfg = cfg or ECHO_CONFIG
    if not exp or exp < 0 then
        exp = 0
    end
    local phase = 1
    local thresholds = cfg.basePhaseThresholds or {}
    for index, threshold in ipairs(thresholds) do
        if exp > threshold then
            phase = index + 1
        else
            break
        end
    end
    if phase > #thresholds + 1 then
        phase = #thresholds + 1
    end
    if exp > (thresholds[#thresholds] or 0) then
        local above = exp - (thresholds[#thresholds] or 0)
        if above > 0 then
            local extra = math.floor(above / (cfg.phaseIncrement or 1000))
            phase = (#thresholds + 1) + extra
        end
    end
    local maxPhase = cfg.maxPhases or 20
    if phase > maxPhase then
        phase = maxPhase
    elseif phase < 1 then
        phase = 1
    end
    return phase
end

function ECHO_UTILS.initBuckets()
    local buckets = {}
    for _, key in ipairs(DAMAGE_KEYS) do
        buckets[key] = { value = 0, last = ECHO_UTILS.millis() }
    end
    return buckets
end

function ECHO_UTILS.resetTotals()
    local totals = {}
    for _, key in ipairs(DAMAGE_KEYS) do
        totals[key] = 0
    end
    totals.total = 0
    return totals
end

function ECHO_UTILS.weightedChoice(options)
    local total = 0
    for _, entry in ipairs(options) do
        total = total + (entry.weight or 0)
    end
    if total <= 0 then
        return nil
    end
    local roll = math.random() * total
    local accum = 0
    for _, entry in ipairs(options) do
        accum = accum + (entry.weight or 0)
        if roll <= accum then
            return entry
        end
    end
    return options[#options]
end

function ECHO_UTILS.applyDecay(current, deltaMs, halfLifeMs)
    if halfLifeMs <= 0 or deltaMs <= 0 then
        return current
    end
    local decay = math.exp(-math.log(2) * (deltaMs / halfLifeMs))
    return current * decay
end

function ECHO_UTILS.accumulateBucket(buckets, bucket, amount, halfLifeMs)
    if not bucket or not buckets then
        return
    end
    local now = ECHO_UTILS.millis()
    local entry = buckets[bucket]
    if not entry then
        buckets[bucket] = { value = math.max(0, amount), last = now }
        return
    end
    local delta = now - (entry.last or now)
    entry.value = ECHO_UTILS.applyDecay(entry.value or 0, delta, halfLifeMs)
    entry.value = entry.value + math.max(0, amount)
    entry.last = now
end

return ECHO_UTILS
