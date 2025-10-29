function onLogout(player)
	local playerId = player:getId()
        if nextUseStaminaTime[playerId] then
                nextUseStaminaTime[playerId] = nil
        end
        if ActivityManager and ActivityManager.onLogout then
                ActivityManager.onLogout(player)
        end
        return true
end
