-- nx_boss.lua
-- Boss registry used by the rank + phase framework. A minimal placeholder
-- implementation that demonstrates structure for future expansion.

if not NX_BOSS then
    NX_BOSS = {}
end

NX_BOSS.REGISTRY = {
    ["Inferna"] = {
        phases = {
            { threshold = 70, actions = { cast = {"fire_rain"}, hazard = "lava_ring" } },
            { threshold = 40, actions = { cast = {"meteor"}, hazard = "flame_gouts" } },
            { threshold = 10, actions = { cast = {"hellstorm"}, hazard = "eruption_all" } }
        },
        arena = { areaid = 5001, cleanup_tags = {"lava_ring", "flame_gouts", "eruption_all"} },
        use_exp_tier = true
    }
}

function NX_BOSS.getEntry(name)
    return NX_BOSS.REGISTRY[name]
end

return NX_BOSS
