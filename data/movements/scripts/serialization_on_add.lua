dofile('data/lib/serialization.lua')

local function shouldSerialize(item)
        if not item then
                return false
        end

        if not Serialization.isEnabled() then
                return false
        end

        if Serialization.hasSerial(item) then
                return false
        end

        if Serialization.shouldSkip(item) then
                return false
        end

        return true
end

function onAddItem(moveItem, tileItem, position, cid)
        if shouldSerialize(moveItem) then
                Serialization.assignSerial(moveItem)
        end
        return true
end
