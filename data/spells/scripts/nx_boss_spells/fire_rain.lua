function onCastSpell(creature, variant)
    local area = { {1} }
    doAreaCombatHealth(creature, COMBAT_FIREDAMAGE, creature:getPosition(), area, -200, -300, CONST_ME_FIREAREA)
    return true
end
