-- E.C.H.O. (Emergent Cognitive Hostile Organism) configuration.
-- All tunables for the adaptive monster AI live in this file.

ECHO_ENABLED = true
ECHO_PERSIST = true

ECHO_CONFIG = {
    maxPhases = 20,
    basePhaseThresholds = {1500, 2500, 3500, 4500},
    phaseIncrement = 1000,
    experiencePerFight = 220,
    fallbackExperience = 1200,
    expOverrides = {},
    -- Optional fallback using monster level -> exp conversion.
    levelExpLookup = {
        [1] = 100,
        [2] = 250,
        [3] = 400,
        [4] = 600,
        [5] = 900,
        [6] = 1200,
        [7] = 1500,
        [8] = 1900,
        [9] = 2300,
        [10] = 2700,
    },
    thinker = {
        minIntervalMs = 400,
        heavyIntervalMs = 1200,
        damageMemoryHalfLifeMs = 15000,
        tiltDecayMs = 20000,
        tiltMax = 0.35,
        tiltMin = -0.20,
        meleeDistance = 2,
        kiteThreshold = 4,
        spacingSmoothing = 0.35,
    },
    adaptation = {
        damageTiltScalar = 0.4,
        tiltLearnRate = 0.35,
        tiltDecayRate = 0.15,
        spacingShiftRate = 0.2,
        spacingReversionRate = 0.05,
        crowdSwapBonus = 0.20,
        crowdAbilityBoost = 0.35,
        counterWeightScalar = 0.45,
        persistentTiltScalar = 0.2,
        persistentSpacingBias = 0.15,
        targetFocusScalar = 0.65,
        randomTargetVariance = 0.08,
    },
    persistence = {
        flushIntervalMs = 30000,
        maxBufferPerFlush = 40,
    },
    phases = {},
    abilities = {
        basic_melee = {
            type = "target",
            combatType = COMBAT_PHYSICALDAMAGE,
            minDamage = -120,
            maxDamage = -240,
            effect = CONST_ME_DRAWBLOOD,
            range = 1,
            tags = { melee = true },
            cooldown = { 1200, 1800 },
        },
        needle_bolt = {
            type = "target",
            combatType = COMBAT_ENERGYDAMAGE,
            minDamage = -90,
            maxDamage = -190,
            effect = CONST_ME_ENERGYAREA,
            range = 6,
            tags = { ranged = true },
            cooldown = { 2000, 2800 },
            counterTypes = { energy = true },
        },
        acid_splash = {
            type = "target",
            combatType = COMBAT_EARTHDAMAGE,
            minDamage = -110,
            maxDamage = -210,
            effect = CONST_ME_SMALLPLANTS,
            range = 5,
            tags = { ranged = true },
            cooldown = { 2200, 3200 },
            counterTypes = { physical = true },
        },
        chilling_wave = {
            type = "target",
            combatType = COMBAT_ICEDAMAGE,
            minDamage = -130,
            maxDamage = -260,
            effect = CONST_ME_ICEAREA,
            range = 5,
            tags = { ranged = true },
            cooldown = { 2400, 3400 },
            counterTypes = { fire = true },
        },
        crushing_slam = {
            type = "target",
            combatType = COMBAT_PHYSICALDAMAGE,
            minDamage = -180,
            maxDamage = -320,
            effect = CONST_ME_BLOCKHIT,
            range = 1,
            tags = { melee = true, crowd = true },
            cooldown = { 2600, 3800 },
        },
        siphon_shield = {
            type = "self",
            tags = { defensive = true },
            cooldown = { 8000, 10000 },
            resistBoost = { energy = 0.08, fire = 0.08 },
            shieldDurationMs = 7000,
        },
        retaliatory_shell = {
            type = "self",
            tags = { defensive = true },
            cooldown = { 9000, 12000 },
            resistBoost = { physical = 0.10, holy = 0.05 },
            shieldDurationMs = 6000,
        },
        pulse_nova = {
            type = "area",
            combatType = COMBAT_ENERGYDAMAGE,
            minDamage = -150,
            maxDamage = -320,
            effect = CONST_ME_ENERGYHIT,
            area = AREA_CIRCLE3X3,
            tags = { crowd = true, ranged = true },
            cooldown = { 4800, 6200 },
        },
        void_lash = {
            type = "target",
            combatType = COMBAT_DEATHDAMAGE,
            minDamage = -170,
            maxDamage = -300,
            effect = CONST_ME_MORTAREA,
            range = 4,
            tags = { ranged = true },
            cooldown = { 3000, 4200 },
            counterTypes = { holy = true },
        },
        burning_rebuke = {
            type = "target",
            combatType = COMBAT_FIREDAMAGE,
            minDamage = -140,
            maxDamage = -280,
            effect = CONST_ME_FIREAREA,
            range = 5,
            tags = { ranged = true },
            cooldown = { 2600, 3600 },
            counterTypes = { ice = true },
        },
    },
}

-- Phase specific behaviour definitions.
ECHO_CONFIG.phases[1] = {
    gcd = { 1800, 2600 },
    abilityPool = {
        { ability = "basic_melee", weight = 40 },
        { ability = "needle_bolt", weight = 25 },
        { ability = "acid_splash", weight = 20 },
        { ability = "siphon_shield", weight = 15 },
    },
    spacing = { preferred = 2, tolerance = 1, variance = 0.05 },
    crowd = { minAttackers = 2, swapBias = 0.05, abilityBonus = 0.10 },
}

ECHO_CONFIG.phases[2] = {
    gcd = { 1600, 2400 },
    abilityPool = {
        { ability = "basic_melee", weight = 30 },
        { ability = "needle_bolt", weight = 25 },
        { ability = "acid_splash", weight = 20 },
        { ability = "chilling_wave", weight = 15 },
        { ability = "crushing_slam", weight = 10 },
        { ability = "retaliatory_shell", weight = 12 },
    },
    spacing = { preferred = 3, tolerance = 1, variance = 0.07 },
    crowd = { minAttackers = 2, swapBias = 0.08, abilityBonus = 0.15 },
}

ECHO_CONFIG.phases[3] = {
    gcd = { 1400, 2200 },
    abilityPool = {
        { ability = "basic_melee", weight = 25 },
        { ability = "needle_bolt", weight = 20 },
        { ability = "chilling_wave", weight = 22 },
        { ability = "void_lash", weight = 18 },
        { ability = "crushing_slam", weight = 15 },
        { ability = "pulse_nova", weight = 12 },
        { ability = "retaliatory_shell", weight = 12 },
    },
    spacing = { preferred = 3, tolerance = 2, variance = 0.08 },
    crowd = { minAttackers = 2, swapBias = 0.12, abilityBonus = 0.20 },
}

ECHO_CONFIG.phases[4] = {
    gcd = { 1200, 2000 },
    abilityPool = {
        { ability = "basic_melee", weight = 20 },
        { ability = "chilling_wave", weight = 20 },
        { ability = "void_lash", weight = 22 },
        { ability = "pulse_nova", weight = 20 },
        { ability = "burning_rebuke", weight = 18 },
        { ability = "crushing_slam", weight = 15 },
        { ability = "retaliatory_shell", weight = 16 },
    },
    spacing = { preferred = 4, tolerance = 2, variance = 0.1 },
    crowd = { minAttackers = 3, swapBias = 0.15, abilityBonus = 0.25 },
}

-- Higher phases inherit behaviour but act faster and with broader ability access.
for phase = 5, ECHO_CONFIG.maxPhases do
    ECHO_CONFIG.phases[phase] = {
        gcd = { math.max(900, 1200 - ((phase - 4) * 40)), math.max(1300, 1800 - ((phase - 4) * 35)) },
        abilityPool = {
            { ability = "basic_melee", weight = 18 },
            { ability = "needle_bolt", weight = 18 },
            { ability = "chilling_wave", weight = 20 },
            { ability = "void_lash", weight = 20 },
            { ability = "pulse_nova", weight = 20 },
            { ability = "burning_rebuke", weight = 18 },
            { ability = "crushing_slam", weight = 16 },
            { ability = "retaliatory_shell", weight = 18 },
        },
        spacing = { preferred = 4, tolerance = 2, variance = 0.12 },
        crowd = { minAttackers = 3, swapBias = 0.18, abilityBonus = 0.30 },
    }
end
