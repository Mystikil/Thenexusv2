function onSay(player, words, param)
        if not player:getGroup():getAccess() then
                return false
        end

        local subCommand, arg = param:match("^(%S+)%s*(.*)$")
        if subCommand == "list" then
                local instances = getActiveInstances()
                for uid, info in pairs(instances) do
                        local endsIn = info.endsIn or 0
                        local count = info.playerCount or 0
                        player:sendTextMessage(MESSAGE_INFO_DESCR, string.format("#%d %s ends in %ds players=%d", uid, info.name or "", endsIn, count))
                end
        elseif subCommand == "close" then
                closeInstance(tonumber(arg) or 0)
        else
                player:sendTextMessage(MESSAGE_INFO_DESCR, "!inst list | !inst close <uid>")
        end
        return false
end
