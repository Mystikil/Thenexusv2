function onCastSpell(creature, variant)
    local spectators = Game.getSpectators(creature:getPosition(), false, false, 7, 7, 5, 5)
    for _, spec in ipairs(spectators) do
        if spec:isPlayer() then
            doTargetCombatHealth(creature, spec, COMBAT_FIREDAMAGE, -250, -350, CONST_ME_FIREAREA)
        end
    end
    return true
end
