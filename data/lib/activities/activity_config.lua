local existing = rawget(_G, 'ActivityConfig')
if existing then
    return existing
end

local ActivityConfig = {
    -- Example Instance
    [1] = {
        kind = "instance",
        id = 1,
        name = "Forgotten Catacombs",
        type = "solo",
        difficulty = "Normal",
        unlock = {
            storage = 51001,
            questStorages = { 61010 },
            minLevel = 30,
            minRep = { faction = "Adventurer's Union", tier = "Friendly" },
            keyItemId = 32501,
            consumeKey = false,
        },
        cooldown = { storage = 52001, seconds = 6 * 60 * 60 },
        bindSeconds = 180,
        expRate = 1.25,
        lootRate = 1.15,
        pressureBonus = 0.10,
        mapRange = { from = { x = 1000, y = 1000, z = 7 }, to = { x = 1060, y = 1060, z = 7 } },
        entryPos = { x = 1005, y = 1005, z = 7 },
        exitPos = { x = 1100, y = 1100, z = 7 },
        monsterSet = "undead_basic",
        bossName = "Gravekeeper",
        specialRules = { "NoSummon" },
        permadeath = {
            mode = "off",
            confirmOnce = true,
            dropPercent = 0.10,
            expPercent = 1.00,
            broadcast = true,
        },
        features = { housing = true, npcs = true, quests = true },
    },

    -- Example Dungeon
    [101] = {
        kind = "dungeon",
        id = 101,
        name = "The Ember Depths",
        type = "solo",
        difficulty = "Heroic",
        unlock = {
            storage = 51101,
            questStorages = { 61101, 61102 },
            minLevel = 50,
            keyItemId = 32521,
            consumeKey = true,
        },
        cooldown = { storage = 52101, seconds = 12 * 60 * 60 },
        bindSeconds = 240,
        expRate = 1.35,
        lootRate = 1.30,
        pressureBonus = 0.20,
        mapRange = { from = { x = 2000, y = 1000, z = 8 }, to = { x = 2080, y = 1065, z = 8 } },
        entryPos = { x = 2005, y = 1005, z = 8 },
        exitPos = { x = 2090, y = 1010, z = 7 },
        monsterSet = "ember_elementals",
        bossName = "Infernal Warden",
        dungeon = {
            mutateChance = 0.08,
            timerSeconds = 1800,
            scoreTiers = { S = 1800, A = 2100, B = 2400, C = 99999 },
            objectives = {
                "Extinguish 5 Ember Totems",
                "Defeat the Infernal Warden",
            },
            rewardTiers = { S = "legendary_box", A = "epic_box", B = "rare_box", C = "standard_box" },
            announcer = true,
        },
        permadeath = {
            mode = "inventory",
            confirmOnce = true,
            dropPercent = 0.15,
            broadcast = true,
        },
        specialRules = { "NoTeleport" },
        features = { housing = false, npcs = true, quests = true },
    },
}

_G.ActivityConfig = ActivityConfig
return ActivityConfig
