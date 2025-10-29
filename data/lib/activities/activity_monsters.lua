local existing = rawget(_G, 'ActivityMonsters')
if existing then
    return existing
end

local ActivityMonsters = {
    undead_basic = {
        "Skeleton",
        "Ghoul",
        "Bonebeast",
        "Undead Gladiator",
    },
    ember_elementals = {
        "Lava Golem",
        "Blazing Elemental",
        "Fire Devil",
        "Hellspawn",
    },
    ember_elementals_mutated = {
        "Seething Elemental",
        "Scorching Golem",
        "Infernal Spark",
    },
}

_G.ActivityMonsters = ActivityMonsters
return ActivityMonsters
