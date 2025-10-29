dofile('data/lib/serialization.lua')

local slotAliases = {
        right = CONST_SLOT_RIGHT,
        left = CONST_SLOT_LEFT,
        head = CONST_SLOT_HEAD,
        neck = CONST_SLOT_NECKLACE,
        necklace = CONST_SLOT_NECKLACE,
        backpack = CONST_SLOT_BACKPACK,
        armor = CONST_SLOT_ARMOR,
        chest = CONST_SLOT_ARMOR,
        legs = CONST_SLOT_LEGS,
        feet = CONST_SLOT_FEET,
        ring = CONST_SLOT_RING,
        ammo = CONST_SLOT_AMMO,
}

local defaultSlots = {
        CONST_SLOT_RIGHT,
        CONST_SLOT_LEFT,
}

local function resolveItem(player, param)
        param = param and param:trim() or ''
        if param ~= '' then
                local slot = slotAliases[param:lower()]
                if slot then
                        return player:getSlotItem(slot)
                end
        end

        for i = 1, #defaultSlots do
                local item = player:getSlotItem(defaultSlots[i])
                if item then
                        return item
                end
        end

        return nil
end

function onSay(player, words, param)
        if not player:getGroup():getAccess() then
                return true
        end

        if player:getAccountType() < ACCOUNT_TYPE_GOD then
                return false
        end

        local item = resolveItem(player, param)
        if not item then
                player:sendCancelMessage('Select an item or specify a slot.')
                return false
        end

        if Serialization.hasSerial(item) then
                player:sendCancelMessage('Item is already serialized.')
                return false
        end

        local serial = Serialization.assignSerial(item, {force = true})
        if serial then
                player:sendTextMessage(MESSAGE_STATUS_CONSOLE_BLUE, 'Assigned serial: ' .. serial)
        else
                player:sendCancelMessage('Unable to assign serial.')
        end
        return false
end
