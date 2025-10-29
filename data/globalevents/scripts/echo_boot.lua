if not ECHO_CONFIG then
    dofile('data/lib/echo_config.lua')
end
if not ECHO_UTILS then
    dofile('data/lib/echo_utils.lua')
end

local function ensureGlobals()
    ECHO_STATE = ECHO_STATE or {}
    ECHO_PERSIST_BUFFER = ECHO_PERSIST_BUFFER or {}
end

local function ensureMonsterRegistration()
    local monsterTypes = Game.getMonsterTypes and Game.getMonsterTypes() or nil
    if not monsterTypes then
        return
    end
    for name, mType in pairs(monsterTypes) do
        if mType then
            local events = mType:getCreatureEvents() or {}
            local has = false
            for _, eventName in ipairs(events) do
                if eventName == "ECHOThink" then
                    has = true
                    break
                end
            end
            if not has then
                mType:registerEvent("ECHOThink")
            end
        end
    end
end

local function flushPersistence()
    if not ECHO_PERSIST then
        return
    end
    ensureGlobals()
    local flushed = 0
    local limit = (ECHO_CONFIG.persistence and ECHO_CONFIG.persistence.maxBufferPerFlush) or 40
    for key, row in pairs(ECHO_PERSIST_BUFFER) do
        if row and row.dirty then
            local ok = ECHO_UTILS.safeDbUpsert(row)
            if ok then
                ECHO_PERSIST_BUFFER[key] = nil
            end
            flushed = flushed + 1
            if flushed >= limit then
                break
            end
        elseif row then
            ECHO_PERSIST_BUFFER[key] = nil
        end
    end
end

local function gcState()
    ensureGlobals()
    for cid, _ in pairs(ECHO_STATE) do
        if not Creature(cid) then
            ECHO_STATE[cid] = nil
        end
    end
end

function onStartup()
    ensureGlobals()
    ensureMonsterRegistration()
    return true
end

function onThink(interval)
    if not ECHO_ENABLED then
        return true
    end
    flushPersistence()
    gcState()
    return true
end
