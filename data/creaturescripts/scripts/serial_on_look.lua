-- [codex-fix] corrected event type/handler as per TFS 10.98
-- serial_on_look.lua
-- Emits item serial identifiers to staff members when inspecting items.

function onLook(player, thing, position, description)
    if type(description) ~= "string" then
        description = thing and thing.getDescription and thing:getDescription() or ""
    end
    if not player then
        return description
    end

    local groupId = 1
    local group = player.getGroup and player:getGroup() or nil
    if group and group.getId then
        groupId = group:getId()
    end

    if groupId >= 3 and thing and thing.isItem and thing:isItem() then
        local serialDesc = thing:getAttribute(ITEM_ATTRIBUTE_DESCRIPTION) or ""
        if serialDesc ~= "" then
            player:sendTextMessage(MESSAGE_INFO_DESCR, "Serial: " .. serialDesc)
        end
    end

    return description
end
