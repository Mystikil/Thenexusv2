#include "otpch.h"
#include "monster/Rank.hpp"
#include "monster/monster.h"
#include "condition.h"
#include "game.h"

#include <algorithm>
#include <cmath>
#include <fstream>
#include <random>

static RankSystem* g_rankSystem = nullptr;

RankSystem& RankSystem::get() {
    if (!g_rankSystem) g_rankSystem = new RankSystem();
    return *g_rankSystem;
}

const char* RankSystem::toString(RankTier t) const {
    switch (t) {
        case RankTier::F:   return "F";
        case RankTier::E:   return "E";
        case RankTier::D:   return "D";
        case RankTier::C:   return "C";
        case RankTier::B:   return "B";
        case RankTier::A:   return "A";
        case RankTier::S:   return "S";
        case RankTier::SS:  return "SS";
        case RankTier::SSS: return "SSS";
        default:            return "None";
    }
}

std::optional<RankTier> RankSystem::parseTier(const std::string& name) const {
    if (name == "F") return RankTier::F;
    if (name == "E") return RankTier::E;
    if (name == "D") return RankTier::D;
    if (name == "C") return RankTier::C;
    if (name == "B") return RankTier::B;
    if (name == "A") return RankTier::A;
    if (name == "S") return RankTier::S;
    if (name == "SS") return RankTier::SS;
    if (name == "SSS") return RankTier::SSS;
    return std::nullopt;
}

uint32_t RankSystem::totalWeight(const RankDistribution& d) const {
    uint32_t sum = 0;
    for (auto& kv : d.weights) {
        sum += kv.second;
    }
    return sum;
}

RankTier RankSystem::pickFrom(const RankDistribution& d) const {
    const uint32_t sum = totalWeight(d);
    if (sum == 0) return RankTier::F;
    static thread_local std::mt19937 rng{std::random_device{}()};
    uint32_t r = std::uniform_int_distribution<uint32_t>(1, sum)(rng);
    for (int i = 0; i <= 8; ++i) {
        RankTier t = static_cast<RankTier>(i);
        auto it = d.weights.find(t);
        if (it == d.weights.end()) continue;
        if (r <= it->second) return t;
        r -= it->second;
    }
    return RankTier::F;
}

RankTier RankSystem::pickBaseTier(const std::string&) const {
    // Default to global distribution
    return pickFrom(cfg.globalDist);
}

int RankSystem::biasToOffset(double bias) const {
    // bias (-1..+1) -> offset tiers (-2..+2)
    if (bias <= -0.66) return -2;
    if (bias <= -0.33) return -1;
    if (bias <  0.33)  return 0;
    if (bias <  0.66)  return +1;
    return +2;
}

RankTier RankSystem::clampedAdvance(RankTier base, int delta) const {
    if (base == RankTier::None) return base;
    int idx = static_cast<int>(base) + delta;
    if (idx < 0) idx = 0;
    if (idx > 8) idx = 8;
    return static_cast<RankTier>(idx);
}

RankTier RankSystem::pick(const std::string& zoneTag, const std::string& monsterKey) const {
    // 1) byMonsterName override
    auto itM = cfg.byMonsterName.find(monsterKey);
    if (itM != cfg.byMonsterName.end()) return pickFrom(itM->second);
    // 2) byZone override
    auto itZ = cfg.byZone.find(zoneTag);
    if (itZ != cfg.byZone.end()) return pickFrom(itZ->second);
    // 3) global
    return pickFrom(cfg.globalDist);
}

const RankDef* RankSystem::def(RankTier t) const {
    if (t == RankTier::None) {
        return nullptr;
    }
    const size_t idx = static_cast<size_t>(t);
    if (idx >= cfg.order.size()) {
        return nullptr;
    }
    return &cfg.order[idx];
}

bool RankSystem::loadFromJson(const std::string& path, std::string& err) {
    err.clear();
    cfg.enabled = true;
    cfg.order.clear();
    cfg.globalDist.weights.clear();
    cfg.byZone.clear();
    cfg.byMonsterName.clear();

    auto push = [&](const char* name, RankScalars s) { cfg.order.push_back(RankDef{name, s}); };
    push("F",   RankScalars{1.00,1.00,0.00, 0, 1.00,1.00,0,1.00,0,0});
    push("E",   RankScalars{1.05,1.03,0.01, 5, 1.05,1.05,0,0.98,0,1});
    push("D",   RankScalars{1.10,1.06,0.02, 8, 1.10,1.10,0,0.97,0,2});
    push("C",   RankScalars{1.20,1.12,0.03,12, 1.20,1.20,1,0.95,1,3});
    push("B",   RankScalars{1.35,1.18,0.05,16, 1.35,1.30,1,0.93,1,5});
    push("A",   RankScalars{1.55,1.26,0.08,20, 1.55,1.45,2,0.90,2,7});
    push("S",   RankScalars{1.80,1.35,0.12,24, 1.80,1.65,2,0.88,2,10});
    push("SS",  RankScalars{2.20,1.48,0.16,28, 2.20,2.00,3,0.85,3,14});
    push("SSS", RankScalars{2.80,1.65,0.22,32, 2.80,2.50,4,0.80,4,18});

    cfg.globalDist.weights.emplace(RankTier::F,   400);
    cfg.globalDist.weights.emplace(RankTier::E,   250);
    cfg.globalDist.weights.emplace(RankTier::D,   150);
    cfg.globalDist.weights.emplace(RankTier::C,    90);
    cfg.globalDist.weights.emplace(RankTier::B,    60);
    cfg.globalDist.weights.emplace(RankTier::A,    30);
    cfg.globalDist.weights.emplace(RankTier::S,    15);
    cfg.globalDist.weights.emplace(RankTier::SS,    4);
    cfg.globalDist.weights.emplace(RankTier::SSS,   1);

    std::ifstream f(path);
    if (!f) {
        return true;
    }

    return true;
}

// Apply only immediate scalars (HP & speed). Damage/xp/loot is handled in combat/death code paths.
void RankSystem::applyScalars(Monster& m, RankTier t) const {
    const RankDef* rd = def(t);
    if (!rd) return;
    // ---- Health scaling (engine-safe) ----
    const int32_t oldMax = m.getMaxHealth();
    const int32_t desired = std::max<int32_t>(1, static_cast<int32_t>(std::llround(oldMax * rd->s.hp)));
    const int32_t target  = std::min<int32_t>(desired, oldMax); // no runtime max-HP mutation
    const int32_t diff    = target - m.getHealth();

    if (diff != 0) {
        // Use the core combat pipeline so hooks/messages stay consistent.
        CombatDamage cd;
        cd.origin = ORIGIN_NONE;
        if (diff > 0) {
            cd.primary.type = COMBAT_HEALING;
            cd.primary.value = diff;
        } else {
            cd.primary.type = COMBAT_PHYSICALDAMAGE;
            cd.primary.value = diff; // negative triggers damage branch
        }
        g_game.combatChangeHealth(nullptr, &m, cd);
    }

    // ---- Speed scaling via Conditions ----
    if (rd->s.speedDelta != 0) {
        const int32_t delta = rd->s.speedDelta;
        const auto condType = (delta > 0) ? CONDITION_HASTE : CONDITION_PARALYZE;
        const int32_t durationMs = 24 * 60 * 60 * 1000; // 24h persistence
        auto cond = Condition::createCondition(CONDITIONID_COMBAT, condType, durationMs, 0);
        // CONDITION_PARAM_SPEED is the supported modifier in this fork.
        cond->setParam(CONDITION_PARAM_SPEED, std::abs(delta));
        m.addCondition(cond);
    }
}
