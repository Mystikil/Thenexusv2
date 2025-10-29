#pragma once
#include <cstdint>
#include <string>
#include <unordered_map>
#include <vector>
#include <optional>

// forward decl to avoid heavy includes here
class Monster;

enum class RankTier : uint8_t { F, E, D, C, B, A, S, SS, SSS, None };

struct RankScalars {
    double  hp         = 1.0;
    double  dmg        = 1.0;   // outgoing
    double  mit        = 0.0;   // incoming mitigation [0..0.80]
    int32_t speedDelta = 0;
    double  xp         = 1.0;
    double  lootMult   = 1.0;
    uint8_t extraRolls = 0;
    double  aiCdMult   = 1.0;
    uint8_t spellUnlock= 0;
    int32_t resist     = 0;     // treat as percent [0..100]
};

struct RankDef { std::string name; RankScalars s; };

struct RankDistribution {
    std::unordered_map<RankTier, uint32_t> weights;
};

struct RankConfig {
    bool enabled = false;
    std::vector<RankDef> order;
    RankDistribution globalDist;
    std::unordered_map<std::string, RankDistribution> byZone;
    std::unordered_map<std::string, RankDistribution> byMonsterName;
};

class RankSystem {
public:
    static RankSystem& get();

    bool loadFromJson(const std::string& path, std::string& err);
    const RankConfig& config() const { return cfg; }
    bool isEnabled() const { return cfg.enabled; }

    const RankDef* def(RankTier t) const;
    std::optional<RankTier> parseTier(const std::string& name) const;
    const char* toString(RankTier t) const;

    RankTier pick(const std::string& zoneTag, const std::string& monsterKey) const;

    // helpers referenced elsewhere
    RankTier clampedAdvance(RankTier base, int delta) const;
    RankTier pickBaseTier(const std::string& monsterKey) const;
    int biasToOffset(double bias) const;

    // apply immediate scalars (HP/speed)
    void applyScalars(Monster& m, RankTier t) const;

private:
    RankConfig cfg;

    uint32_t totalWeight(const RankDistribution& d) const;
    RankTier pickFrom(const RankDistribution& d) const;
};
