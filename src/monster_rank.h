#ifndef FS_MONSTER_RANK_H
#define FS_MONSTER_RANK_H

#include <array>
#include <cstdint>
#include <string_view>

inline constexpr std::size_t MONSTER_RANK_COUNT = 12;

enum class MonsterRank : uint8_t {
        F = 0,
        E,
        D,
        C,
        B,
        A,
        S,
        SS,
        SSS,
        SSSS,
        SSSSS,
        SSSSSS,
};

struct MonsterRankData {
        MonsterRank rank;
        std::string_view name;
        double healthMultiplier;
        double experienceMultiplier;
        double lootMultiplier;
        double speedMultiplier;
        double damageMultiplier;
        double defenseMultiplier;
};

inline constexpr std::array<MonsterRankData, MONSTER_RANK_COUNT> MONSTER_RANK_TABLE = {{
        {MonsterRank::F, "F", 1.0, 1.0, 1.0, 1.0, 1.0, 1.0},
        {MonsterRank::E, "E", 1.15, 1.1, 1.05, 1.05, 1.1, 1.1},
        {MonsterRank::D, "D", 1.3, 1.2, 1.1, 1.1, 1.2, 1.2},
        {MonsterRank::C, "C", 1.5, 1.35, 1.2, 1.15, 1.3, 1.3},
        {MonsterRank::B, "B", 1.8, 1.5, 1.3, 1.2, 1.45, 1.45},
        {MonsterRank::A, "A", 2.1, 1.75, 1.4, 1.25, 1.6, 1.6},
        {MonsterRank::S, "S", 2.5, 2.0, 1.6, 1.3, 1.8, 1.8},
        {MonsterRank::SS, "SS", 3.0, 2.35, 1.8, 1.35, 2.05, 2.0},
        {MonsterRank::SSS, "SSS", 3.6, 2.75, 2.0, 1.4, 2.3, 2.25},
        {MonsterRank::SSSS, "SSSS", 4.3, 3.2, 2.25, 1.45, 2.55, 2.5},
        {MonsterRank::SSSSS, "SSSSS", 5.1, 3.7, 2.5, 1.5, 2.8, 2.75},
        {MonsterRank::SSSSSS, "SSSSSS", 6.2, 4.4, 3.0, 1.6, 3.2, 3.1},
}};

struct MonsterRankUpgradeRule {
        MonsterRank from;
        MonsterRank to;
        uint32_t killsRequired;
        uint8_t chance; // 1-100
};

inline constexpr std::array<MonsterRankUpgradeRule, 12> MONSTER_RANK_UPGRADES = {{
        {MonsterRank::F, MonsterRank::E, 20, 30},
        {MonsterRank::E, MonsterRank::D, 25, 25},
        {MonsterRank::D, MonsterRank::C, 30, 20},
        {MonsterRank::C, MonsterRank::B, 35, 18},
        {MonsterRank::B, MonsterRank::A, 40, 15},
        {MonsterRank::A, MonsterRank::S, 50, 12},
        {MonsterRank::S, MonsterRank::SS, 60, 10},
        {MonsterRank::SS, MonsterRank::SSS, 70, 8},
        {MonsterRank::SSS, MonsterRank::SSSS, 80, 6},
        {MonsterRank::SSSS, MonsterRank::SSSSS, 90, 5},
        {MonsterRank::SSSSS, MonsterRank::SSSSSS, 110, 4},
        {MonsterRank::F, MonsterRank::S, 50, 6},
}};

inline constexpr std::string_view monsterRankToString(MonsterRank rank) {
        return MONSTER_RANK_TABLE[static_cast<std::size_t>(rank)].name;
}

inline constexpr const MonsterRankData& getMonsterRankData(MonsterRank rank) {
        return MONSTER_RANK_TABLE[static_cast<std::size_t>(rank)];
}

#endif // FS_MONSTER_RANK_H
