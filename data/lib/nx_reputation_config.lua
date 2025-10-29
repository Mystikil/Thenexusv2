-- Unified Reputation & Trading Economy configuration
-- All constants, multipliers, and feature toggles live here.

NX_REPUTATION_CONFIG = {
    -- Cache behaviour
    cache = {
        -- seconds to cache tier lookups per player/faction
        ttl = 45,
        -- how many reputation deltas to queue before forcing a flush
        reputationBatch = 25
    },

    -- Default reputation tiers (sorted ascending by minimum points).
    -- Adjust modifiers to taste; buy modifier applies when player buys from NPC,
    -- sell modifier applies when player sells to NPC (affects payout).
    tiers = {
        { name = "Hated",    min = -math.huge, max = -1000, buyModifier = 1.20, sellModifier = 0.80, flags = { secret = false } },
        { name = "Disliked", min = -999,       max = -1,    buyModifier = 1.10, sellModifier = 0.90, flags = { secret = false } },
        { name = "Neutral",  min = 0,          max = 999,   buyModifier = 1.00, sellModifier = 1.00, flags = { secret = false } },
        { name = "Friendly", min = 1000,       max = 2499,  buyModifier = 0.98, sellModifier = 1.02, flags = { secret = true, quest = true } },
        { name = "Honored",  min = 2500,       max = 4999,  buyModifier = 0.96, sellModifier = 1.04, flags = { secret = true, quest = true, crafting = true } },
        { name = "Revered",  min = 5000,       max = math.huge, buyModifier = 0.95, sellModifier = 1.05, flags = { secret = true, quest = true, crafting = true } },
    },

    -- Per faction data used to seed SQL migration and runtime defaults.
    factions = {
        ["Traders Guild"] = {
            id = 1,
            description = "Merchants who control the central exchange and most caravans.",
            fees = {
                npcBuy = 0.02,    -- player buying from NPC
                npcSell = 0.03,   -- player selling to NPC
                market = 0.04     -- auction house fee captured per completed sale
            },
            reputation = {
                tradeBuyFactor = 0.0015,   -- reputation gained per gold spent at shops (net, after fees)
                tradeSellFactor = 0.0010,  -- reputation gained per gold earned selling to shops (net, after fees)
                donationMultiplier = 2,    -- multiplier applied to item donation value
                killPenalty = 150,         -- penalty for killing protected creatures
                decayPerWeek = 100,        -- optional decay towards neutral if inactive
                softCap = 6000,
                hardCap = 7500,
                softDiminishFactor = 0.50  -- once over softCap, gains are halved
            },
            economy = {
                seedPool = 50000,
                thresholds = {
                    { min = 0,       modifier = 1.03, label = "Strained",    secretChance = 0.0 },
                    { min = 40000,   modifier = 1.00, label = "Stable",      secretChance = 0.15 },
                    { min = 120000,  modifier = 0.97, label = "Booming",     secretChance = 0.35 },
                    { min = 200000,  modifier = 0.96, label = "Overflowing", secretChance = 0.50, capDiscount = true }
                },
                minModifier = 0.90,
                maxModifier = 1.10
            },
            towns = { 1, 2, 5 }, -- default towns whose market fees funnel here
            defaults = {
                donationItems = {
                    [2148] = 1,   -- gold coin
                    [2152] = 25,  -- platinum coin
                    [2160] = 250, -- crystal coin
                }
            }
        },
        ["Artisan Assembly"] = {
            id = 2,
            description = "Craftspeople seeking rare materials and bespoke commissions.",
            fees = {
                npcBuy = 0.015,
                npcSell = 0.02,
                market = 0.03
            },
            reputation = {
                tradeBuyFactor = 0.001,
                tradeSellFactor = 0.0012,
                donationMultiplier = 3,
                killPenalty = 200,
                decayPerWeek = 50,
                softCap = 6500,
                hardCap = 8000,
                softDiminishFactor = 0.45
            },
            economy = {
                seedPool = 30000,
                thresholds = {
                    { min = 0,      modifier = 1.04, label = "Shortage",   secretChance = 0.0 },
                    { min = 25000,  modifier = 1.00, label = "Balanced",   secretChance = 0.2 },
                    { min = 80000,  modifier = 0.96, label = "Thriving",   secretChance = 0.4 }
                },
                minModifier = 0.92,
                maxModifier = 1.12
            },
            towns = { 3, 4 },
            defaults = {
                donationItems = {
                    [2148] = 1,
                    [2152] = 30,
                    [9971] = 120 -- giant shimmering pearl
                }
            }
        },
        ["Central Exchange"] = {
            id = 3,
            description = "Neutral clearing house for remote settlements; redistributes nightly.",
            fees = {
                npcBuy = 0.0,
                npcSell = 0.0,
                market = 0.05
            },
            reputation = {
                tradeBuyFactor = 0,
                tradeSellFactor = 0,
                donationMultiplier = 1,
                killPenalty = 0,
                decayPerWeek = 0,
                softCap = 0,
                hardCap = 0,
                softDiminishFactor = 0
            },
            economy = {
                seedPool = 100000,
                thresholds = {
                    { min = 0, modifier = 1.00, label = "Reservoir", secretChance = 0.0 }
                },
                minModifier = 1.00,
                maxModifier = 1.00
            },
            towns = {},
            defaults = {}
        }
    },

    -- Optional faction specific overrides for NPCs.
    npcs = {
        -- Example mapping used by the sample quartermaster NPC.
        ["Faction Quartermaster"] = {
            faction = "Traders Guild",
            secretOffers = {
                buy = {
                    [24774] = { minTier = "Honored", economyMin = 100000, label = "Guild reserve" }
                }
            }
        }
    },

    -- Donation chests mapping unique action ids to faction targets.
    donationChests = {
        [47001] = {
            faction = "Traders Guild",
            reputationPerValue = 1.0, -- per donated gold equivalent after multipliers
            broadcast = "The Traders Guild thanks you for your contribution!"
        },
        [47002] = {
            faction = "Artisan Assembly",
            reputationPerValue = 1.1,
            broadcast = "The artisans rejoice at your generosity."
        }
    },

    -- Creatures that affect reputation when killed.
    creatures = {
        protected = {
            -- creature name -> { faction = "Faction Name", penalty = amount }
            ["Guild Merchant"] = { faction = "Traders Guild", penalty = 200 },
            ["Assembly Foreman"] = { faction = "Artisan Assembly", penalty = 250 }
        },
        allies = {
            -- creature name -> { faction = "Faction Name", reward = amount }
            ["Smuggler"] = { faction = "Traders Guild", reward = 40 }
        }
    },

    -- Quest storage identifiers for the sample Friendly+ quest.
    questExample = {
        storageBase = 55000,
        missionStorage = 55001,
        completionStorage = 55002,
        requiredFaction = "Traders Guild",
        requiredTier = "Friendly"
    },

    -- Global modifier hooks (placeholder for future events)
    globalModifiers = {
        buy = 1.00,
        sell = 1.00
    },

    -- Secret deal rotation settings
    secretDeals = {
        rotationHour = 9, -- daily rotation time (server save default)
        maxOffersPerFaction = 3
    }
}

-- Convenience lookup for tier order (populated at runtime).
NX_REPUTATION_CONFIG._tierOrder = {}
for index, tier in ipairs(NX_REPUTATION_CONFIG.tiers) do
    NX_REPUTATION_CONFIG._tierOrder[tier.name] = index
end
