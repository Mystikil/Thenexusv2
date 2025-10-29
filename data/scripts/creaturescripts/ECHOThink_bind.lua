local bind = CreatureEvent('ECHOAutoBind')
bind:type('think')

function bind.onThink(creature, interval)
    if not creature or not creature:isMonster() then
        return true
    end

    creature:registerEvent('ECHOThink')
    creature:registerEvent('ECHOThinkHealth')
    creature:registerEvent('ECHOThinkDeath')

    -- Stop running once the necessary handlers are attached.
    creature:unregisterEvent('ECHOAutoBind')
    return true
end

bind:register()
