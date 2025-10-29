function onLogin(player)
	local serverName = configManager.getString(configKeys.SERVER_NAME)
	local loginStr = "Welcome to " .. serverName .. "!"
	if player:getLastLoginSaved() <= 0 then
		loginStr = loginStr .. " Please choose your outfit."
		player:sendOutfitWindow()
	else
		loginStr = string.format("Your last visit in %s: %s.", serverName, os.date("%d %b %Y %X", player:getLastLoginSaved()))
	end
	player:sendTextMessage(MESSAGE_STATUS_DEFAULT, loginStr)

	-- Promotion
	local vocation = player:getVocation()
	local promotion = vocation:getPromotion()
	if player:isPremium() then
		local value = player:getStorageValue(PlayerStorageKeys.promotion)
		if value == 1 then
			player:setVocation(promotion)
		end
	elseif not promotion then
		player:setVocation(vocation:getDemotion())
	end

	-- Update client exp display
	player:updateClientExpDisplay()

	-- Events
        player:registerEvent("nx_rank_look")
        player:registerEvent("PlayerDeath")
        player:registerEvent("DropLoot")
        player:registerEvent("SerialOnLook")
        player:registerEvent("FactionReputationKill")

        if ActivityManager and ActivityManager.onLogin then
                ActivityManager.onLogin(player)
        end
        return true
end
