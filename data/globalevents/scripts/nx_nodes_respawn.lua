local NX = (dofile_once and dofile_once('data/lib/nx_professions.lua')) or dofile('data/lib/nx_professions.lua')

local rotationSets = {
        [0] = { meadow_flowers = 1.25, surface_ore = 1.15 },
        [1] = { woodland_trees = 1.25, deep_basalt = 1.25 }
}

local function applyRotation(toggle)
        NX_ResetRarity()
        local config = rotationSets[toggle] or {}
        local boosted = {}
        for nodeKey, bonus in pairs(config) do
                NX_SetNodeRarityBonus(nodeKey, bonus)
                boosted[#boosted + 1] = string.format("%s x%.2f", nodeKey, bonus)
        end
        if #boosted == 0 then
                print("[NX] Gathering rotation: no active boosts today.")
        else
                print(string.format("[NX] Gathering rotation: active boosts - %s", table.concat(boosted, ", ")))
        end
end

local function getToggle()
        local stored = Game.getStorageValue(NX_ROTATION_TOGGLE_KEY)
        if stored ~= 0 and stored ~= 1 then
                stored = 0
                Game.setStorageValue(NX_ROTATION_TOGGLE_KEY, stored)
        end
        return stored
end

local function rotate()
        local toggle = getToggle()
        toggle = toggle == 1 and 0 or 1
        Game.setStorageValue(NX_ROTATION_TOGGLE_KEY, toggle)
        applyRotation(toggle)
end

local function ensureRotation()
        applyRotation(getToggle())
end

local function shouldRotate()
        if NX and type(NX.shouldRotateNow) == "function" then
                return NX.shouldRotateNow(os.time())
        end
        if type(_G.NX_ShouldRotate) == "function" then
                return _G.NX_ShouldRotate()
        end
        return false
end

local function handleRotation(force)
        if force or shouldRotate() then
                rotate()
        else
                ensureRotation()
        end
end

function onTime(interval)
        handleRotation(false)
        return true
end

function onStartup()
        handleRotation(false)
        return true
end
