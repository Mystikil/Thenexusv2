Serialization = Serialization or {}
local Serialization = Serialization

local ULID_LENGTH = 26
local TIME_PART_LENGTH = 10
local RANDOM_PART_LENGTH = ULID_LENGTH - TIME_PART_LENGTH
local BASE32 = {
        "0", "1", "2", "3", "4", "5", "6", "7", "8", "9",
        "A", "B", "C", "D", "E", "F", "G", "H", "J", "K",
        "M", "N", "P", "Q", "R", "S", "T", "V", "W", "X",
        "Y", "Z"
}

local BASE32_LOOKUP = {}
for index, char in ipairs(BASE32) do
        BASE32_LOOKUP[index - 1] = char
end

local function loadConfig()
        if not Serialization._config then
                Serialization._config = dofile('data/config/serialization.lua')
        end
        return Serialization._config
end

local function getViewConfig()
        local config = loadConfig()
        if not config then
                return nil
        end
        return config.view
end

function Serialization.reloadConfig()
        Serialization._config = nil
        return loadConfig()
end

function Serialization.getConfig()
        return loadConfig()
end

function Serialization.isEnabled()
        local config = loadConfig()
        return config.enabled ~= false
end

function Serialization.hasSerial(item)
        if not item then
                return false
        end

        local value = item:getCustomAttribute('serial')
        return value ~= nil and value ~= ''
end

local function getItemType(item)
        if not item then
                return nil
        end
        return item:getType()
end

function Serialization.shouldSkip(item)
        if not item then
                return true
        end

        local config = loadConfig()
        local itemType = getItemType(item)
        if not itemType then
                return true
        end

        local blacklist = config.blacklist_itemids
        if blacklist and blacklist[item:getId()] then
                return true
        end

        local exclude = config.exclude
        if exclude then
                if exclude.stackable and itemType:isStackable() then
                        return true
                end

                if exclude.fluid and itemType:isFluidContainer() then
                        return true
                end

                if exclude.corpse and itemType:isCorpse() then
                        return true
                end
        end

        return false
end

local lastTimestamp = 0
local lastRandom = {}

local function currentTimestamp()
        local seconds = os.time()
        local millis = math.floor((os.clock() % 1) * 1000)
        local value = seconds * 1000 + millis
        if value < lastTimestamp then
                value = lastTimestamp
        end
        return value
end

local function randomize()
        local values = {}
        for i = 1, RANDOM_PART_LENGTH do
                values[i] = math.random(0, 31)
        end
        return values
end

local function incrementRandom()
        for index = RANDOM_PART_LENGTH, 1, -1 do
                local value = lastRandom[index] or 0
                value = value + 1
                if value <= 31 then
                        lastRandom[index] = value
                        return
                end
                lastRandom[index] = 0
        end
end

local function encodeBase32(value, length)
        local buffer = {}
        for index = length, 1, -1 do
                local remainder = value % 32
                buffer[index] = BASE32_LOOKUP[remainder]
                value = (value - remainder) / 32
        end
        return table.concat(buffer)
end

local function encodeRandom(values)
        local buffer = {}
        for index = 1, RANDOM_PART_LENGTH do
                buffer[index] = BASE32_LOOKUP[values[index]] or '0'
        end
        return table.concat(buffer)
end

function Serialization.ulid()
        local timestamp = currentTimestamp()
        if timestamp == lastTimestamp then
                incrementRandom()
        else
                lastTimestamp = timestamp
                lastRandom = randomize()
        end

        return encodeBase32(timestamp, TIME_PART_LENGTH) .. encodeRandom(lastRandom)
end

function Serialization.assignSerial(item, options)
        if not item then
                return nil
        end

        if Serialization.hasSerial(item) then
                return item:getCustomAttribute('serial')
        end

        options = options or {}
        if not options.force then
                if not Serialization.isEnabled() then
                        return nil
                end

                if Serialization.shouldSkip(item) then
                        return nil
                end
        end

        local config = loadConfig()
        local serial = (config.serial_prefix or '') .. Serialization.ulid()
        item:setCustomAttribute('serial', serial)
        return serial
end

function Serialization.getSerial(item)
        if not item then
                return nil
        end
        local value = item:getCustomAttribute('serial')
        if value == '' then
                return nil
        end
        return value
end

function Serialization.canViewSerial(player)
        local view = getViewConfig()
        if not player or not view then
                return false
        end

        local group = player:getGroup()
        local gid = group and group:getId() or 0
        local minId = view.min_group_id or 3
        return gid >= minId
end

function Serialization.getSerialLine(item, viewer)
        local view = getViewConfig()
        if not (view and view.enable_in_tooltip) then
                return nil
        end

        if not Serialization.canViewSerial(viewer) then
                return nil
        end

        local serial = Serialization.getSerial(item)
        if not serial then
                return nil
        end

        local label = view.label or 'Serial'
        return string.format('%s: %s', label, serial)
end

function Serialization.injectSerial(description, item, viewer)
        local view = getViewConfig()
        if not (view and view.enable_in_look) then
                return description
        end

        if not Serialization.canViewSerial(viewer) then
                return description
        end

        local serial = Serialization.getSerial(item)
        if not serial then
                return description
        end

        local label = view.label or 'Serial'
        local base = description or ''
        local line = label .. ': ' .. serial
        if base == '' then
                return line
        end
        return base .. '\n' .. line
end

return Serialization
