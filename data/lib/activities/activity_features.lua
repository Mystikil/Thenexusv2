local existing = rawget(_G, 'ActivityFeatures')
if existing then
    return existing
end

local ActivityFeatures = {}

function ActivityFeatures.onStart(run)
    local activity = run.activity
    if not activity or not activity.features then
        return
    end
    if activity.features.npcs then
        print(string.format('[ACTIVITY:%d:%s] spawning NPCs for %s', activity.id, activity.kind, activity.name))
    end
    if activity.features.quests then
        print(string.format('[ACTIVITY:%d:%s] enabling quests for %s', activity.id, activity.kind, activity.name))
    end
    if activity.features.housing then
        print(string.format('[ACTIVITY:%d:%s] unlocking housing hooks for %s', activity.id, activity.kind, activity.name))
    end
end

function ActivityFeatures.onEnd(run)
    local activity = run.activity
    if not activity or not activity.features then
        return
    end
    if activity.features.housing then
        print(string.format('[ACTIVITY:%d:%s] clearing housing hooks for %s', activity.id, activity.kind, activity.name))
    end
    if activity.features.quests then
        print(string.format('[ACTIVITY:%d:%s] disabling quests for %s', activity.id, activity.kind, activity.name))
    end
    if activity.features.npcs then
        print(string.format('[ACTIVITY:%d:%s] despawning NPCs for %s', activity.id, activity.kind, activity.name))
    end
end

_G.ActivityFeatures = ActivityFeatures
return ActivityFeatures
