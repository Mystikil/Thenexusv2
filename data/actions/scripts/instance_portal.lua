local ActivityManager = ActivityManager or dofile('data/scripts/lib/activity_manager.lua')

local function resolveActivityId(item)
        local description = item:getAttribute(ITEM_ATTRIBUTE_DESCRIPTION)
        if description and description ~= '' then
                local numeric = tonumber(description)
                if numeric then
                        return numeric
                end
        end
        local text = item:getAttribute(ITEM_ATTRIBUTE_TEXT)
        if text and text ~= '' then
                local numeric = tonumber(text)
                if numeric then
                        return numeric
                end
        end
        return nil
end

function onUse(player, item, fromPosition, target, toPosition, isHotkey)
        local activityId = resolveActivityId(item)
        if not activityId then
                player:sendTextMessage(MESSAGE_STATUS_SMALL, "Portal misconfigured (missing activity id).")
                return true
        end

        local activity = ActivityManager.getActivity(activityId)
        if not activity then
                player:sendTextMessage(MESSAGE_STATUS_SMALL, "Portal references unknown activity.")
                return true
        end

        local ok, reason = ActivityManager.enter(player, activityId)
        if not ok then
                player:sendTextMessage(MESSAGE_STATUS_SMALL, reason)
                return true
        end

        player:sendTextMessage(MESSAGE_EVENT_ADVANCE, string.format('You feel the air shift as reality folds around your party on the way to %s.', activity.name))
        return true
end
