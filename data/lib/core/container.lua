function Container.isContainer(self)
	return true
end

function Container.createLootItem(self, item)
        if self:getEmptySlots() == 0 then
                return true
        end

        local itemType = ItemType(item.itemId)
        local economy = rawget(_G, 'NX_ECONOMY')
        local shouldAllow = economy and economy.shouldAllowLootItem

        if shouldAllow and not shouldAllow(item, { container = self, itemType = itemType }) then
                if itemType and itemType:isContainer() then
                        local childLoot = item.childLoot or {}
                        for i = 1, #childLoot do
                                if shouldAllow(childLoot[i], { container = self }) then
                                        self:createLootItem(childLoot[i])
                                end
                        end
                end
                return true
        end

        local itemCount = 0
        local randvalue = getLootRandom()

        if randvalue < item.chance then
                if itemType:isStackable() then
                        itemCount = randvalue % item.maxCount + 1
                else
                        itemCount = 1
                end
        end

        while itemCount > 0 do
                local count = math.min(ITEM_STACK_SIZE, itemCount)

                local subType = count
                if itemType:isFluidContainer() then
                        subType = math.max(0, item.subType)
                end

                local tmpItem = Game.createItem(item.itemId, subType)
                if not tmpItem then
                        return false
                end

                if tmpItem:isContainer() then
                        for i = 1, #item.childLoot do
                                if not tmpItem:createLootItem(item.childLoot[i]) then
                                        tmpItem:remove()
                                        return false
                                end
                        end

                        if #item.childLoot > 0 and tmpItem:getSize() == 0 then
                                tmpItem:remove()
                                return true
                        end
                end

                if item.subType ~= -1 then
                        tmpItem:setAttribute(ITEM_ATTRIBUTE_CHARGES, item.subType)
                end

                if item.actionId ~= -1 then
                        tmpItem:setActionId(item.actionId)
                end

                if item.text and item.text ~= "" then
                        tmpItem:setText(item.text)
                end

                local ret = self:addItemEx(tmpItem)
                if ret ~= RETURNVALUE_NOERROR then
                        tmpItem:remove()
                end

                itemCount = itemCount - count
        end
        return true
end
