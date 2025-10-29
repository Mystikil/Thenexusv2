function onCastSpell(creature, variant)
    local target = creature:getTarget()
    if target then
        doAreaCombatHealth(creature, COMBAT_FIREDAMAGE, target:getPosition(), 0, -300, -400, CONST_ME_FIREAREA)
    end
    return true
end
